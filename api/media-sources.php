<?php
declare(strict_types=1);

// Provider-neutral archive contract. Graph-shaped data is confined to the existing
// station planner; all persisted identities also contain their source provider.
require_once __DIR__.'/sync-trip-media.php';
require_once __DIR__.'/trip-publication.php';

function source_provider(string $value): string {
    if (!in_array($value, ['onedrive','local','dropbox'], true)) throw new InvalidArgumentException('Ungültige Medienquelle.',422);
    return $value;
}
function source_csrf(): string {
    return $_SESSION['media_source_csrf'] ??= bin2hex(random_bytes(32));
}
function require_source_write(): void {
    if (!hash_equals((string)($_SESSION['media_source_csrf']??''), (string)($_SERVER['HTTP_X_ATLAS_CSRF']??'')) || empty($_SESSION['media_source_csrf'])) json(['error'=>'Bitte den Import neu öffnen.'],403);
}
function source_encrypt(string $token, array $config): string {
    $key=hex2bin((string)$config['encryption_key']); $iv=random_bytes(12);
    $cipher=openssl_encrypt($token,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false) throw new RuntimeException('Verbindung konnte nicht gesichert werden.');
    return base64_encode($iv.$tag.$cipher);
}
function source_decrypt(string $encoded, array $config): string {
    $raw=base64_decode($encoded,true);
    if($raw===false||strlen($raw)<29) throw new RuntimeException('Dropbox bitte erneut verbinden.',409);
    $token=openssl_decrypt(substr($raw,28),'aes-256-gcm',hex2bin((string)$config['encryption_key']),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
    if($token===false) throw new RuntimeException('Dropbox bitte erneut verbinden.',409);
    return $token;
}
function source_save_connection(PDO $db,array $config,int $owner,string $provider,string $refresh,string $account): void {
    if($account==='')throw new RuntimeException('Das Cloudkonto konnte nicht erkannt werden.',502);
    $q=$db->prepare('SELECT account_id FROM media_source_connections WHERE user_id=? AND provider=?');$q->execute([$owner,$provider]);$previous=$q->fetchColumn();
    if(!$previous&&$provider==='onedrive'){$q=$db->prepare('SELECT microsoft_id FROM users WHERE id=?');$q->execute([$owner]);$previous=$q->fetchColumn();}
    if($previous&&$previous!==$account){
        $q=$db->prepare('SELECT 1 FROM media_items m JOIN trips t ON t.id=m.trip_id WHERE t.user_id=? AND m.source_provider=? LIMIT 1');$q->execute([$owner,$provider]);
        if($q->fetchColumn())throw new RuntimeException('Für vorhandene Reisen bitte dasselbe Cloudkonto erneut verbinden.',409);
    }
    $q=$db->prepare('INSERT INTO media_source_connections(user_id,provider,account_id,refresh_token_enc) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),refresh_token_enc=VALUES(refresh_token_enc)');$q->execute([$owner,$provider,$account,source_encrypt($refresh,$config)]);
}
function dropbox_request(string $endpoint, string $token, array $payload, bool $content=false): array {
    $ch=curl_init(($content?'https://content.dropboxapi.com/2/':'https://api.dropboxapi.com/2/').$endpoint);
    $encoded=json_encode($payload,JSON_THROW_ON_ERROR);
    $headers=['Authorization: Bearer '.$token];
    if($content) $headers[]='Dropbox-API-Arg: '.$encoded;
    else $headers[]='Content-Type: application/json';
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$content?'':$encoded,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
    if($status!==200||!is_string($body))throw new RuntimeException('Dropbox konnte die Anfrage nicht ausführen. Bitte Verbindung und Ordner prüfen.',502);
    return $content?['body'=>$body,'type'=>$type]:json_decode($body,true,512,JSON_THROW_ON_ERROR);
}
function dropbox_token(PDO $db, array $config, int $owner): string {
    $q=$db->prepare("SELECT refresh_token_enc FROM media_source_connections WHERE user_id=? AND provider='dropbox'");$q->execute([$owner]);$encoded=$q->fetchColumn();
    if(!$encoded)throw new RuntimeException('Dropbox ist noch nicht verbunden.',409);
    $ch=curl_init('https://api.dropboxapi.com/oauth2/token');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'refresh_token','refresh_token'=>source_decrypt((string)$encoded,$config),'client_id'=>$config['dropbox_client_id']??'','client_secret'=>$config['dropbox_client_secret']??'']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode((string)$raw,true);
    if($status!==200||empty($data['access_token']))throw new RuntimeException('Dropbox bitte erneut verbinden.',409);
    return (string)$data['access_token'];
}
function dropbox_normalize(array $entry): ?array {
    if(($entry['.tag']??'')!=='file')return null;
    $name=(string)($entry['name']??'');$extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    $video=in_array($extension,['mp4','mov','m4v','webm'],true);
    if(!$video&&!in_array($extension,['jpg','jpeg','png','webp','gif','heic','heif','tif','tiff'],true))return null;
    $metadata=$entry['media_info']['metadata']??[];
    $taken=capture_valid_time((string)($metadata['time_taken']??''));
    if($taken===null)$taken=capture_filename_time($name);
    $location=$metadata['location']??[];
    $lat=$location['latitude']??null;$lon=$location['longitude']??null;
    if(!is_numeric($lat)||!is_numeric($lon)||abs((float)$lat)>90||abs((float)$lon)>180)$lat=$lon=null;
    return ['id'=>(string)$entry['id'],'name'=>$name,$video?'video':'photo'=>[],
      'location'=>['latitude'=>$lat,'longitude'=>$lon],
      '_capture'=>['date'=>$taken,'source'=>isset($metadata['time_taken'])&&capture_valid_time((string)$metadata['time_taken'])?'dropbox':'filename','note'=>$taken?null:'Kein Aufnahmedatum vorhanden']];
}
function source_trip(PDO $db,int $owner,string $title,string $provider,string $folder,string $trip=''): array {
    if($trip!==''){
        $q=$db->prepare('SELECT * FROM trips WHERE id=? AND user_id=?');$q->execute([$trip,$owner]);$record=$q->fetch();
        if(!$record)throw new RuntimeException('Die Reise gehört nicht zu diesem Konto.',403);
        return $record;
    }
    $title=trim($title);if($title===''||strlen($title)>255||strlen($folder)>1024)throw new InvalidArgumentException('Bitte einen gültigen Titel und Ordner eingeben.',422);
    $publication=trip_owner_auto_approved($db,$owner)?'approved':'pending';
    $id=uuid();$q=$db->prepare('INSERT INTO trips(id,user_id,title,source_provider,source_folder,publication_status) VALUES(?,?,?,?,?,?)');$q->execute([$id,$owner,$title,$provider,$folder,$publication]);
    return ['id'=>$id,'user_id'=>$owner,'title'=>$title,'source_provider'=>$provider,'source_folder'=>$folder,'media_count'=>0,'publication_status'=>$publication];
}
function source_append(PDO $db,string $trip,string $provider,array $items): int {
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE');$q->execute([$trip]);if(!$q->fetchColumn())throw new RuntimeException('Reise nicht gefunden.',404);
        $q=$db->prepare('SELECT COALESCE(source_item_id,onedrive_item_id) FROM media_items WHERE trip_id=? AND source_provider=?');$q->execute([$trip,$provider]);$known=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
        $items=array_values(array_filter($items,static fn($item)=>!isset($known[$item['id']])));
        $q=$db->prepare('SELECT * FROM trip_stops WHERE trip_id=? ORDER BY position');$q->execute([$trip]);$stops=$q->fetchAll();
        $plan=sync_addition_plan($stops,$items,static fn()=>uuid());
        foreach($plan['media'] as $item){if(isset($item['_station_name'])&&isset($plan['new_stops'][$item['_stop_id']]))$plan['stops'][$item['_stop_id']]['name']=$item['_station_name'];}
        if($plan['new_stops']){
            $max=0;foreach($stops as $s)$max=max($max,(int)$s['position']);
            $q=$db->prepare('UPDATE trip_stops SET position=position+? WHERE trip_id=?');$q->execute([$max+count($plan['stops'])+1,$trip]);
        }
        $insert=$db->prepare('INSERT INTO trip_stops(id,trip_id,position,name,latitude,longitude,starts_at,ends_at) VALUES(?,?,?,?,?,?,?,?)');
        $update=$db->prepare('UPDATE trip_stops SET position=?,starts_at=?,ends_at=? WHERE id=? AND trip_id=?');
        foreach($plan['stops'] as $id=>$stop){
            if(isset($plan['new_stops'][$id]))$insert->execute([$id,$trip,$stop['position'],$stop['name'],$stop['latitude'],$stop['longitude'],$stop['starts_at'],$stop['ends_at']]);
            else $update->execute([$stop['position'],$stop['starts_at'],$stop['ends_at'],$id,$trip]);
        }
        $insert=$db->prepare('INSERT INTO media_items(id,trip_id,stop_id,onedrive_item_id,source_provider,source_item_id,name,media_type,taken_at,capture_source,capture_details,latitude,longitude,storage_path,mime_type,local_original_path,local_original_root) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($plan['media'] as $item)$insert->execute([uuid(),$trip,$item['_stop_id'],($provider==='onedrive'?$item['id']:$provider.':'.hash('sha256',$item['id'])),$provider,$item['id'],$item['name'],isset($item['video'])?'video':'photo',media_capture_date($item),$item['_capture']['source'],json_encode($item['_capture'],JSON_UNESCAPED_UNICODE),$item['location']['latitude']??null,$item['location']['longitude']??null,$item['storage_path']??null,$item['mime_type']??null,$item['local_original_path']??null,$item['local_original_root']??null]);
        $q=$db->prepare("UPDATE trips SET source_provider=CASE WHEN EXISTS(SELECT 1 FROM media_items WHERE trip_id=? AND source_provider<>?) THEN 'mixed' ELSE ? END,media_count=(SELECT COUNT(*) FROM media_items WHERE trip_id=?),starts_on=(SELECT DATE(MIN(taken_at)) FROM media_items WHERE trip_id=?),ends_on=(SELECT DATE(MAX(taken_at)) FROM media_items WHERE trip_id=?),updated_at=NOW() WHERE id=?");$q->execute([$trip,$provider,$provider,$trip,$trip,$trip,$trip]);
        $db->commit();return count($items);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function source_trip_result(PDO $db,string $id): array {
    $q=$db->prepare('SELECT id,title,source_provider,source_folder,starts_on,ends_on,media_count,publication_status FROM trips WHERE id=?');$q->execute([$id]);return $q->fetch();
}
function local_coordinates(array $gps,string $axis): ?float {
    $parts=$gps['GPS'.$axis]??null;if(!is_array($parts)||count($parts)!==3)return null;
    $values=[];foreach($parts as $part){$ratio=explode('/',(string)$part);if(count($ratio)>1&&(float)$ratio[1]==0)return null;$values[]=(float)$ratio[0]/(count($ratio)>1?(float)$ratio[1]:1);}
    $number=$values[0]+$values[1]/60+$values[2]/3600;
    if(in_array($gps['GPS'.$axis.'Ref']??'',['S','W'],true))$number=-$number;
    return is_finite($number)&&abs($number)<=($axis==='Latitude'?90:180)?$number:null;
}
function source_file(string $relative): string {
    if(!preg_match('~^cache/(?:originals/[a-f0-9]{64}\.[a-z0-9]+|local-previews/[a-f0-9]{64}\.jpg)$~D',$relative))throw new RuntimeException('Ungültiger Medienpfad.',404);
    $path=__DIR__.'/'.$relative;if(!is_file($path))throw new RuntimeException('Mediendatei nicht gefunden.',404);return $path;
}
function source_local_file(array $item): string {
    if(!defined('ATLAS_LOCAL_MODE')||!ATLAS_LOCAL_MODE)throw new RuntimeException('Dieses Original ist nur auf dem zugehörigen Gerät verfügbar.',409);
    $root=realpath((string)($item['local_original_root']??''));$path=realpath((string)($item['local_original_path']??''));
    if(!$root||!$path||!is_file($path)||!str_starts_with(strtolower($path),strtolower($root.DIRECTORY_SEPARATOR)))throw new RuntimeException('Das lokale Original ist nicht erreichbar. Bitte den Quellordner wieder bereitstellen.',404);
    return $path;
}
function remove_source_files(PDO $db,array $paths): void {
    $query=$db->prepare('SELECT 1 FROM media_items WHERE storage_path=? LIMIT 1');
    foreach(array_unique(array_filter($paths)) as $relative){
        $query->execute([$relative]);if($query->fetchColumn())continue;
        try{$path=source_file($relative);}catch(Throwable){continue;}
        $preview=dirname($path).'/'.pathinfo($path,PATHINFO_FILENAME).'.preview.jpg';
        if(is_file($preview))unlink($preview);unlink($path);
    }
}
function source_coordinate(mixed $value,int $limit): ?float {
    return is_numeric($value)&&is_finite((float)$value)&&abs((float)$value)<=$limit?(float)$value:null;
}
function require_source_trip_provider(PDO $db,string $trip,string $provider): void {
    require_admin($db);
    $q=$db->prepare('SELECT source_provider FROM trips WHERE id=?');$q->execute([$trip]);$actual=$q->fetchColumn();
    if($actual===false)json(['error'=>'Reise nicht gefunden.'],404);
    if($actual!==$provider)json(['error'=>'Diese Aktion ist für diese Medienquelle nicht verfügbar.'],409);
}
function source_stream_file(string $path,string $mime): never {
    $length=filesize($path);$start=0;$end=$length-1;$range=trim((string)($_SERVER['HTTP_RANGE']??''));
    if($range!==''){
        if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)||($m[1]===''&&$m[2]==='')){http_response_code(416);header('Content-Range: bytes */'.$length);exit;}
        if($m[1]==='')$start=max(0,$length-(int)$m[2]);else{$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}
        if($start>$end||$start>=$length){http_response_code(416);header('Content-Range: bytes */'.$length);exit;}
        http_response_code(206);header("Content-Range: bytes $start-$end/$length");
    }
    header('Content-Type: '.$mime);header('Cache-Control: private, no-store');header('Accept-Ranges: bytes');header('Content-Length: '.($end-$start+1));
    $stream=fopen($path,'rb');fseek($stream,$start);$remaining=$end-$start+1;
    while($remaining>0&&!feof($stream)&&!connection_aborted()){$buffer=fread($stream,min(65536,$remaining));echo $buffer;$remaining-=strlen($buffer);}fclose($stream);exit;
}
function source_preview(array $item,array $config,PDO $db,string $size): never {
    if($item['source_provider']==='local'){
        $path=source_file((string)$item['storage_path']);
        if(!empty($item['local_original_path']))source_stream_file($path,'image/jpeg');
        $preview=dirname($path).'/'.pathinfo($path,PATHINFO_FILENAME).'.preview.jpg';
        if(is_file($preview))source_stream_file($preview,'image/jpeg');
        if(!str_starts_with((string)$item['mime_type'],'image/')){http_response_code(404);exit;}
        if(class_exists('Imagick')){
            $image=new Imagick();$image->readImage($path);$image->setIteratorIndex(0);$image->autoOrientImage();$image->thumbnailImage($size==='small'?240:2048,$size==='small'?240:2048,true);$image->setImageFormat('jpeg');$body=$image->getImageBlob();$image->clear();header('Content-Type: image/jpeg');header('Cache-Control: private, max-age=3600');echo $body;exit;
        }
        source_stream_file($path,(string)$item['mime_type']);
    }
    $response=dropbox_request('files/get_thumbnail',dropbox_token($db,$config,(int)$item['user_id']),['path'=>$item['source_item_id'],'format'=>'jpeg','size'=>$size==='small'?'w256h256':'w1024h768','mode'=>'fitone_bestfit'],true);
    header('Content-Type: image/jpeg');header('Cache-Control: private, max-age=3600');echo $response['body'];exit;
}
function handle_media_sources(PDO $db,array $config,string $action,array $input): never {
    $owner=$action==='media-sources'?require_importer($db):require_admin($db);
    try{
        if($action==='media-sources'&&$_SERVER['REQUEST_METHOD']==='GET'){
            $q=$db->prepare("SELECT 1 FROM media_source_connections WHERE user_id=? AND provider='dropbox'");$q->execute([$owner]);$dropbox=(bool)$q->fetchColumn();
            $q=$db->prepare("SELECT 1 FROM media_source_connections WHERE user_id=? AND provider='onedrive' UNION SELECT 1 FROM oauth_tokens WHERE user_id=?");$q->execute([$owner,$owner]);$onedrive=(bool)$q->fetchColumn();
            json(['csrf_token'=>source_csrf(),'sources'=>[
              ['id'=>'onedrive','label'=>'OneDrive','configured'=>!empty($config['microsoft_client_id']),'connected'=>$onedrive],
              ['id'=>'local','label'=>'Lokale Originale','configured'=>defined('ATLAS_LOCAL_MODE')&&ATLAS_LOCAL_MODE,'connected'=>defined('ATLAS_LOCAL_MODE')&&ATLAS_LOCAL_MODE],
              ['id'=>'dropbox','label'=>'Dropbox','configured'=>!empty($config['dropbox_client_id'])&&!empty($config['dropbox_client_secret']),'connected'=>$dropbox]]]);
        }
        if($_SERVER['REQUEST_METHOD']!=='POST')json(['error'=>'Methode nicht erlaubt.'],405);
        require_source_write();
        if($action==='local-import'){
            require_once __DIR__.'/local-reference.php';
            $result=import_local_references($db,$config,$owner,$input);json($result,201);
        }
        if($action==='local-media'){
            json(['error'=>'Lokale Originale werden referenziert. Datei-Uploads sind nicht vorgesehen.'],410);
        }
        if($action==='dropbox-import'){
            $folder=trim((string)($input['source_folder']??''));if($folder==='/'||$folder==='')$folder='';elseif(!str_starts_with($folder,'/')||strlen($folder)>1024)throw new InvalidArgumentException('Dropbox-Ordner beginnt mit /.',422);
            $token=dropbox_token($db,$config,$owner);$page=dropbox_request('files/list_folder',$token,['path'=>$folder,'recursive'=>true,'include_media_info'=>true]);$items=[];
            do{foreach($page['entries']??[] as $entry){$item=dropbox_normalize($entry);if($item)$items[$item['id']]=$item;}
                if(count($items)>5000)throw new RuntimeException('Bitte einen Ordner mit höchstens 5000 Medien auswählen.',422);
                if(empty($page['has_more']))break;$page=dropbox_request('files/list_folder/continue',$token,['cursor'=>$page['cursor']]);
            }while(true);
            if(!$items)throw new InvalidArgumentException('Keine unterstützten Fotos oder Videos gefunden.',422);
            $record=source_trip($db,$owner,(string)($input['title']??''),'dropbox',$folder,(string)($input['trip']??''));
            $added=source_append($db,$record['id'],'dropbox',array_values($items));json(['added_media'=>$added,'trip'=>source_trip_result($db,$record['id'])],201);
        }
        json(['error'=>'Nicht gefunden.'],404);
    }catch(Throwable $error){$status=in_array($error->getCode(),[403,404,409,413,415,422,502],true)?$error->getCode():500;if($status===500)error_log('Media source import: '.$error->getMessage());json(['error'=>$status===500?'Der Medienimport konnte nicht abgeschlossen werden.':$error->getMessage()],$status);}
}