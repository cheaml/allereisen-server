<?php
declare(strict_types=1);
require_once __DIR__.'/trip-steps.php';
require_once __DIR__.'/trip-manual-dates.php';
function device_sync_importer_allowed(PDO $db,int $user): bool {
    $q=$db->prepare("SELECT 1 FROM users WHERE id=? AND microsoft_id IS NOT NULL AND microsoft_id<>'' AND (is_admin=1 OR can_import=1)");
    $q->execute([$user]);return (bool)$q->fetchColumn();
}
function device_sync_user_allowed(PDO $db,int $user): bool {
    $q=$db->prepare("SELECT 1 FROM users WHERE id=? AND microsoft_id IS NOT NULL AND microsoft_id<>''");$q->execute([$user]);return (bool)$q->fetchColumn();
}
function require_device_sync_user(PDO $db): int {
    $user=require_user();
    if(($_SESSION['auth_method']??'')!=='microsoft'||!device_sync_user_allowed($db,$user))json(['error'=>'Microsoft-Anmeldung erforderlich.'],403);
    return $user;
}
function device_sync_has_column(PDO $db,string $table,string $column): bool {
    static $cache=[];$key=spl_object_id($db).'/'.$table.'/'.$column;if(isset($cache[$key]))return $cache[$key];
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$rows=$db->query('PRAGMA table_info('.$table.')')->fetchAll();return $cache[$key]=(bool)array_filter($rows,static fn($r)=>$r['name']===$column);}
    $q=$db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return $cache[$key]=(bool)$q->fetchColumn();
}
function device_sync_schema(PDO $db): void {
    // Migrations are installed by the operator, never during a user request.
    foreach(['trip_day_steps','device_sync_codes','device_sync_devices','device_sync_backups','device_sync_receipts'] as $table){
        try{$db->query('SELECT 1 FROM '.$table.' LIMIT 1');}
        catch(PDOException){throw new RuntimeException('Servermigration für den Geräteabgleich fehlt. Bitte die Installationsprüfung ausführen.',503);}
    }
}
function device_sync_redirect_valid(string $url): bool {
    return $url==='reiseapp://auth'||(preg_match('~^http://localhost:([0-9]{4,5})/$~D',$url,$m)===1&&(int)$m[1]>=1024&&(int)$m[1]<=65535);
}
function device_sync_resolve_folder(PDO $db,array $config,int $owner,array $input): array {
    $source=$input['trip']['source']??[];
    if(($source['provider']??null)!==1||($source['accountId']??'')!==device_sync_account($db,$owner))throw new RuntimeException('Bitte OneDrive und Website mit demselben Konto verbinden.',403);
    $marker=(string)($source['rootId']??'');
    if(str_starts_with($marker,'reise://')){
        $id=device_sync_uuid((string)($input['trip']['id']??''));if($marker!=='reise://'.$id)throw new RuntimeException('Ungültige Originalzuordnung.',422);
        $q=$db->prepare('SELECT source_folder FROM trips WHERE id=? AND user_id=?');$q->execute([$id,$owner]);if($q->fetchColumn()!==$marker)throw new RuntimeException('Originalreise nicht zugänglich.',403);
        $q=$db->prepare('SELECT m.onedrive_item_id FROM media_items m JOIN trips t ON t.id=m.trip_id WHERE t.user_id=?');$q->execute([$owner]);$known=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
        foreach($input['trip']['media']??[] as $media)if(!isset($known[$media['reference']['originalReference']??'']))throw new RuntimeException('Dieses Original ist noch keiner eigenen Reise zugeordnet.',422);
        $input['_verifiedOriginalRootId']=$marker;return $input;
    }
    // App sources contain Graph folder IDs, website sources contain folder paths.
    // Resolve the actual folder using the server owner's token before writing.
    require_once __DIR__.'/mail.php';
    $token=graph_access_token($db,$config,$owner);if(!$token)throw new RuntimeException('Bitte auf der Website erneut mit Microsoft anmelden, um OneDrive freizugeben.',409);
    $root=(string)($source['rootId']??'');
    $read=static function(string $path)use($token): ?array{$c=curl_init('https://graph.microsoft.com/v1.0/me/drive/'.$path.'?$select=id,name,folder,parentReference');curl_setopt_array($c,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);$raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);if($status===404||$status===400)return null;if($status!==200)throw new RuntimeException('OneDrive-Ordner konnte nicht geprüft werden. Bitte die Verbindung prüfen.',409);$item=json_decode((string)$raw,true);if(!is_array($item))throw new RuntimeException('Unvollständige OneDrive-Antwort.',409);return $item;};
    $item=$read('items/'.rawurlencode($root));
    if(!$item)$item=$read('root:/'.implode('/',array_map('rawurlencode',explode('/',$root))));
    if(!$item||!isset($item['folder']))throw new RuntimeException('Der OneDrive-Quellordner ist nicht mehr zugänglich.',422);
    $input['_verifiedOriginalRootId']=$root;$input['trip']['source']['rootId']=device_sync_folder_path($item);return $input;
}
function device_sync_folder_path(array $item): string {
    $parent=(string)($item['parentReference']['path']??'');$at=strpos($parent,'root:');
    if($at===false)throw new RuntimeException('Bitte einen Unterordner in OneDrive als Reisequelle auswählen.',422);
    $folder=trim(rawurldecode(substr($parent,$at+5)),'/');$folder=($folder!==''?$folder.'/':'').(string)$item['name'];
    return $folder;
}
function device_sync_account(PDO $db,int $owner): string {
    $sql=device_sync_has_column($db,'media_source_connections','account_id')?"SELECT COALESCE(NULLIF(c.account_id,''),u.microsoft_id) FROM users u LEFT JOIN media_source_connections c ON c.user_id=u.id AND c.provider='onedrive' WHERE u.id=?":'SELECT microsoft_id FROM users WHERE id=?';$q=$db->prepare($sql);$q->execute([$owner]);return (string)$q->fetchColumn();
}
function device_sync_export(PDO $db,int $owner,string $id,bool $readOnly=false): array {
    $filter=!$readOnly&&device_sync_has_column($db,'trips','source_provider')?" AND source_provider='onedrive'":'';
    $q=$db->prepare('SELECT * FROM trips WHERE id=? AND user_id=?'.$filter);$q->execute([$id,$owner]);$t=$q->fetch();if(!$t)throw new RuntimeException('Reise nicht zugänglich.',403);
    $q=$db->prepare('SELECT * FROM trip_stops WHERE trip_id=? ORDER BY position,id');$q->execute([$id]);$stops=$q->fetchAll();
    $q=$db->prepare('SELECT * FROM media_items WHERE trip_id=? ORDER BY id');$q->execute([$id]);$media=$q->fetchAll();
    if(!$readOnly&&array_filter($media,static fn($m)=>($m['source_provider']??'onedrive')!=='onedrive'))throw new RuntimeException('Gemischte Fotoquellen werden nicht übertragen.',422);
    $metadata=[];$metadata[$id.'/trip/'.$id]=['participants'=>$t['participants']??'','main_location'=>$t['main_location']??'','main_latitude'=>$t['main_latitude']===null?null:(float)$t['main_latitude'],'main_longitude'=>$t['main_longitude']===null?null:(float)$t['main_longitude']];
    if(!empty($t['manual_starts_on'])){$metadata[$id.'/trip/'.$id]['manual_starts_on']=$t['manual_starts_on'];$metadata[$id.'/trip/'.$id]['manual_ends_on']=$t['manual_ends_on']??null;}
    $stations=[];foreach($stops as $s){$stations[]=['id'=>$s['id'],'name'=>$s['name'],'day'=>empty($s['starts_at'])?null:substr($s['starts_at'],0,10),'summary'=>$s['summary']??''];$metadata[$id.'/station/'.$s['id']]=['latitude'=>$s['latitude']===null?null:(float)$s['latitude'],'longitude'=>$s['longitude']===null?null:(float)$s['longitude'],'location_confirmed'=>true];}
    $q=$db->prepare('SELECT * FROM trip_day_summaries WHERE trip_id=? ORDER BY day_date');$q->execute([$id]);$days=$q->fetchAll();foreach($days as $d)$metadata[$id.'/day/'.$d['day_date']]=['summary'=>$d['summary'],'photo_media_id'=>$d['photo_media_id']];
    $q=$db->prepare('SELECT day_date,payload FROM trip_day_steps WHERE trip_id=? ORDER BY day_date');$q->execute([$id]);foreach($q->fetchAll() as $steps)$metadata[$id.'/day/'.$steps['day_date']]['steps']=$steps['payload'];
    $q=$db->prepare('SELECT payload FROM trip_manual_routes WHERE trip_id=? ORDER BY route_key');$q->execute([$id]);$routes=$q->fetchAll();foreach($routes as $r){$route=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);$metadata[$id.'/route/manual:'.json_encode([$route['from'],$route['to']])]=['value'=>$route];}
    $unassigned=null;
    $items=[];foreach($media as $m){if(empty($m['stop_id'])){if($unassigned===null){$h=md5($id.'/unassigned');$unassigned=substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);$stations[]=['id'=>$unassigned,'name'=>'Aufnahmen ohne Station','day'=>null,'summary'=>''];}}$items[]=['stationId'=>$m['stop_id']?:$unassigned,'reference'=>['id'=>$m['id'],'name'=>$m['name'],'kind'=>$m['media_type'],'originalReference'=>$m['onedrive_item_id'],'previewPath'=>null,'takenAt'=>empty($m['taken_at'])?null:str_replace(' ','T',$m['taken_at']),'latitude'=>$m['latitude']===null?null:(float)$m['latitude'],'longitude'=>$m['longitude']===null?null:(float)$m['longitude']]];$metadata[$id.'/media/'.$m['id']]=['capture_source'=>$m['capture_source']??null];}
    $revisionTrip=$t;if(empty($t['manual_starts_on'])&&empty($t['manual_ends_on']))unset($revisionTrip['manual_starts_on'],$revisionTrip['manual_ends_on']);
    $time=$db->prepare($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT strftime('%s',updated_at) FROM trips WHERE id=?":'SELECT UNIX_TIMESTAMP(updated_at) FROM trips WHERE id=?');$time->execute([$id]);$epoch=$time->fetchColumn();
    return ['modifiedAt'=>$epoch===false||$epoch===null?null:gmdate('Y-m-d\TH:i:s\Z',(int)$epoch),'trip'=>['id'=>$id,'title'=>$t['title'],'source'=>['provider'=>1,'accountId'=>device_sync_account($db,$owner),'rootId'=>$t['source_folder'],'label'=>$t['title']],'summary'=>$t['intro']??'','createdAt'=>str_replace(' ','T',$t['created_at']),'stations'=>$stations,'media'=>$items],'metadata'=>$metadata,'revision'=>hash('sha256',json_encode((array_filter($metadata,static fn($v)=>isset($v['steps']))?[$revisionTrip,$stops,$media,$days,$routes,array_filter($metadata,static fn($v)=>isset($v['steps']))]:[$revisionTrip,$stops,$media,$days,$routes]),JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR))];
}
function device_sync_uuid(string $value): string {
    if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$value))throw new RuntimeException('Ungültige Reise- oder Stations-ID.',422);return strtolower($value);
}
// First release only publishes NEW app trips. Existing website trips stay
// authoritative; editing them is local until an explicit conflict protocol ships.
function device_sync_publish(PDO $db,int $owner,array $input,bool $update=false): array {
    $t=$input['trip']??[];$id=device_sync_uuid((string)($t['id']??''));$source=$t['source']??[];
    if(($source['provider']??null)!==1||($source['accountId']??'')!==device_sync_account($db,$owner))throw new RuntimeException('Nur OneDrive-Reisen aus dem verbundenen Website-Konto können veröffentlicht werden.',403);
    $title=trim((string)($t['title']??''));$root=(string)($source['rootId']??'');$stations=$t['stations']??[];$media=$t['media']??[];
    if($title===''||mb_strlen($title)>255||$root===''||strlen($root)>1024||count($stations)>5000||count($media)>5000||(!$media&&!$update))throw new RuntimeException('Ungültige Reise.',422);
    $requestHash=hash('sha256',json_encode([$owner,$update,$input],JSON_THROW_ON_ERROR));
    $db->beginTransaction();try{
    $q=$db->prepare('SELECT user_id FROM trips WHERE id=? FOR UPDATE');$q->execute([$id]);$existing=$q->fetchColumn();
    if($existing!==false&&(int)$existing!==$owner)throw new RuntimeException('Reise-ID bereits vergeben.',403);
    $q=$db->prepare('SELECT payload FROM device_sync_receipts WHERE request_hash=? AND user_id=? AND trip_id=?');$q->execute([$requestHash,$owner,$id]);$receipt=$q->fetchColumn();if($receipt!==false){$db->commit();return json_decode($receipt,true,512,JSON_THROW_ON_ERROR);}
    $backupId=null;$oldIds=[];
    if($update){
        if($existing===false)throw new RuntimeException('Die Webreise ist nicht mehr vorhanden. Bitte als neue Reise veröffentlichen.',409);
        foreach(['trip_stops','media_items','trip_day_summaries','trip_manual_routes'] as $table){$q=$db->prepare('SELECT trip_id FROM '.$table.' WHERE trip_id=? FOR UPDATE');$q->execute([$id]);$q->fetchAll();}
        $before=device_sync_export($db,$owner,$id);
        if(!hash_equals($before['revision'],(string)($input['revision']??'')))throw new RuntimeException('Die Webreise wurde inzwischen geändert. Es wurde nichts überschrieben. Bitte zuerst die Webversion prüfen.',409);
        if($before['trip']['source']['rootId']!==$root&&$before['trip']['source']['rootId']!==($input['_verifiedOriginalRootId']??null))throw new RuntimeException('Die feste OneDrive-Quelle darf beim Aktualisieren nicht geändert werden.',422);
        $backupId=uuid();$q=$db->prepare('INSERT INTO device_sync_backups(id,trip_id,user_id,revision,device_label,payload) VALUES(?,?,?,?,?,?)');$q->execute([$backupId,$id,$owner,$before['revision'],mb_substr((string)($input['deviceLabel']??'App'),0,100),json_encode($before,JSON_THROW_ON_ERROR)]);
        foreach($before['trip']['media'] as $m)$oldIds[$m['reference']['originalReference']]=$m['reference']['id'];
        // Keep the trip row, its owner, guests and invitations intact.
        foreach(['trip_day_summaries','media_items','trip_manual_routes','trip_stops'] as $table){$q=$db->prepare('DELETE FROM '.$table.' WHERE trip_id=?');$q->execute([$id]);}
        $q=$db->prepare('UPDATE trips SET title=?,intro=?,media_count=?,source_folder=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$q->execute([$title,(string)($t['summary']??''),count($media),$root,$id]);
    }else{
        if($existing!==false)throw new RuntimeException('Diese Reise ist bereits auf der Website. Bitte Änderungen an Website übertragen wählen.',409);
        $sql=device_sync_has_column($db,'trips','source_provider')?"INSERT INTO trips(id,user_id,title,intro,source_provider,source_folder,media_count) VALUES(?,?,?,?,'onedrive',?,?)":'INSERT INTO trips(id,user_id,title,intro,source_folder,media_count) VALUES(?,?,?,?,?,?)';$q=$db->prepare($sql);$q->execute([$id,$owner,$title,(string)($t['summary']??''),$root,count($media)]);
        if(function_exists('trip_owner_auto_approved')&&device_sync_has_column($db,'trips','publication_status')){$q=$db->prepare('UPDATE trips SET publication_status=? WHERE id=?');$q->execute([trip_owner_auto_approved($db,$owner)?'approved':'pending',$id]);}
    }
    $known=[];$metadata=$input['metadata']??[];
    foreach($stations as $index=>$s){$sid=device_sync_uuid((string)($s['id']??''));if(isset($known[$sid]))throw new RuntimeException('Doppelte Station.',422);$known[$sid]=true;$extra=$metadata[$id.'/station/'.$sid]??[];
        $located=array_values(array_filter($media,static fn($m)=>($m['stationId']??'')===$sid&&isset($m['reference']['latitude'],$m['reference']['longitude'])));
        $point=$located?$located[intdiv(count($located),2)]['reference']:[];
        if(!array_key_exists('latitude',$extra))$extra['latitude']=$point['latitude']??null;
        if(!array_key_exists('longitude',$extra))$extra['longitude']=$point['longitude']??null;
        if(($extra['latitude']!==null&&(!is_numeric($extra['latitude'])||abs((float)$extra['latitude'])>90))||($extra['longitude']!==null&&(!is_numeric($extra['longitude'])||abs((float)$extra['longitude'])>180)))throw new RuntimeException('Ungültige Stationsposition.',422);
        $times=array_values(array_filter(array_map(static fn($m)=>(($m['stationId']??'')===$sid)?($m['reference']['takenAt']??null):null,$media)));sort($times);
        $day=isset($s['day'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$s['day'])?(string)$s['day'].' 00:00:00':null;
        $q=$db->prepare('INSERT INTO trip_stops(id,trip_id,position,name,summary,latitude,longitude,starts_at,ends_at) VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$sid,$id,$index,mb_substr((string)($s['name']??''),0,255),(string)($s['summary']??''),$extra['latitude']??null,$extra['longitude']??null,$times?str_replace('T',' ',substr($times[0],0,19)):$day,$times?str_replace('T',' ',substr(end($times),0,19)):$day]);
    }
    $mediaIds=[];
    foreach($media as $m){$r=$m['reference']??[];$sid=(string)($m['stationId']??'');$original=(string)($r['originalReference']??'');if(!isset($known[$sid])||$original===''||strlen($original)>255||!in_array($r['kind']??'',['photo','video'],true))throw new RuntimeException('Ungültige Medienreferenz.',422);
        $hash=md5($id.'/'.$original);$mid=substr($hash,0,8).'-'.substr($hash,8,4).'-'.substr($hash,12,4).'-'.substr($hash,16,4).'-'.substr($hash,20);
        $mid=$oldIds[$original]??$mid;
        if(isset($mediaIds[(string)$r['id']])||in_array($mid,$mediaIds,true))throw new RuntimeException('Doppelte Medienreferenz.',422);
        $mediaIds[(string)$r['id']]=$mid;
        $values=[$mid,$id,$sid,$original];$sql='INSERT INTO media_items(id,trip_id,stop_id,onedrive_item_id,';
        if(device_sync_has_column($db,'media_items','source_provider')){$sql.="source_provider,source_item_id,";$values[]='onedrive';$values[]=$original;}
        $values=array_merge($values,[(string)$r['name'],$r['kind'],empty($r['takenAt'])?null:str_replace('T',' ',substr($r['takenAt'],0,19)),$r['latitude']??null,$r['longitude']??null]);$sql.='name,media_type,taken_at,latitude,longitude';
        if(device_sync_has_column($db,'media_items','capture_source')){$sql.=',capture_source';$values[]=$metadata[$id.'/media/'.(string)$r['id']]['capture_source']??null;}
        $sql.=') VALUES('.implode(',',array_fill(0,count($values),'?')).')';$q=$db->prepare($sql);$q->execute($values);
    }
    $extra=$metadata[$id.'/trip/'.$id]??[];$q=$db->prepare('UPDATE trips SET participants=?,main_location=?,main_latitude=?,main_longitude=? WHERE id=?');$q->execute([$extra['participants']??'',$extra['main_location']??'',$extra['main_latitude']??null,$extra['main_longitude']??null,$id]);
    $manualDates=trip_manual_dates($extra);if($manualDates!==null){if(!trip_manual_dates_supported($db))throw new RuntimeException('Bitte den Server für manuelle Reisedaten aktualisieren.',422);$q=$db->prepare('UPDATE trips SET manual_starts_on=?,manual_ends_on=? WHERE id=?');$q->execute([$manualDates[0],$manualDates[1],$id]);}
    require_once __DIR__.'/manual-routes.php';
    foreach($metadata as $key=>$fields){$prefix=$id.'/day/';if(str_starts_with($key,$prefix)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',substr($key,strlen($prefix)))){$q=$db->prepare('INSERT INTO trip_day_summaries(trip_id,day_date,summary,photo_media_id) VALUES(?,?,?,?)');$q->execute([$id,substr($key,strlen($prefix)),(string)($fields['summary']??''),$mediaIds[$fields['photo_media_id']??'']??null]);}
        if(str_starts_with($key,$id.'/route/manual:')&&!empty($fields['value'])){[$from,$to,$points]=manual_route_validate($fields['value']);if($points!==null){$q=$db->prepare('INSERT INTO trip_manual_routes(trip_id,route_key,payload) VALUES(?,?,?)');$q->execute([$id,hash('sha256',json_encode([$from,$to],JSON_PRESERVE_ZERO_FRACTION)),json_encode(['from'=>$from,'to'=>$to,'points'=>$points],JSON_THROW_ON_ERROR)]);}}
    }
    foreach($metadata as $key=>$fields){$prefix=$id.'/day/';if(str_starts_with($key,$prefix)&&array_key_exists('steps',$fields)){$day=substr($key,strlen($prefix));$steps=$fields['steps']===null?null:trip_steps_validate($fields['steps'],$day);$q=$db->prepare('DELETE FROM trip_day_steps WHERE trip_id=? AND day_date=?');$q->execute([$id,$day]);if($steps!==null){$q=$db->prepare('INSERT INTO trip_day_steps(trip_id,day_date,payload) VALUES(?,?,?)');$q->execute([$id,$day,$steps]);}}}
    $q=$db->prepare('UPDATE trips SET starts_on=(SELECT DATE(MIN(taken_at)) FROM media_items WHERE trip_id=?),ends_on=(SELECT DATE(MAX(taken_at)) FROM media_items WHERE trip_id=?) WHERE id=?');$q->execute([$id,$id,$id]);
    $reply=['created'=>!$update,'backupId'=>$backupId,'snapshot'=>device_sync_export($db,$owner,$id)];
    $q=$db->prepare('INSERT INTO device_sync_receipts(request_hash,trip_id,user_id,payload) VALUES(?,?,?,?)');$q->execute([$requestHash,$id,$owner,json_encode($reply,JSON_THROW_ON_ERROR)]);
    $db->commit();
    $removedIds=array_diff(array_values($oldIds),array_values($mediaIds));
    foreach($removedIds as $mid)if(preg_match('/^[a-f0-9-]{36}$/iD',$mid))foreach(['small','medium','large','fullscreen'] as $size){$path=__DIR__.'/cache/thumbs/'.$mid.'-'.$size.'.jpg';if(is_file($path))@unlink($path);}
    return $reply;
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function device_sync_restore(PDO $db,int $owner,array $input): array {
    $trip=device_sync_uuid((string)($input['tripId']??''));$backup=device_sync_uuid((string)($input['backupId']??''));
    $q=$db->prepare('SELECT payload FROM device_sync_backups WHERE id=? AND trip_id=? AND user_id=?');$q->execute([$backup,$trip,$owner]);$raw=$q->fetchColumn();if($raw===false)throw new RuntimeException('Sicherung nicht zugänglich.',403);
    $snapshot=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$snapshot['trip']['source']['rootId']=device_sync_export($db,$owner,$trip)['trip']['source']['rootId'];$snapshot['revision']=(string)($input['revision']??'');$snapshot['deviceLabel']='Wiederherstellung '.$backup;
    return device_sync_publish($db,$owner,$snapshot,true);
}
function device_sync_preview(PDO $db,int $owner,array $input): array {
    $t=$input['trip']??[];$id=device_sync_uuid((string)($t['id']??''));
    if(($t['source']['provider']??null)!==1||($t['source']['accountId']??'')!==device_sync_account($db,$owner))throw new RuntimeException('Nur OneDrive-Reisen aus dem verbundenen Konto sind möglich.',403);
    $q=$db->prepare('SELECT user_id FROM trips WHERE id=?');$q->execute([$id]);$existing=$q->fetchColumn();
    if($existing===false)return ['exists'=>false,'revision'=>'','changes'=>['Neue Reise: '.(string)($t['title']??''),count($t['stations']??[]).' Stationen und '.count($t['media']??[]).' Medien werden veröffentlicht.']];
    if((int)$existing!==$owner)throw new RuntimeException('Reise nicht zugänglich.',403);
    $before=device_sync_export($db,$owner,$id);$changes=[];
    if(($t['title']??'')!==$before['trip']['title'])$changes[]='Reisetitel geändert.';
    if(($t['summary']??'')!==$before['trip']['summary'])$changes[]='Einleitungstext geändert.';
    $oldStations=array_column($before['trip']['stations'],null,'id');$newStations=array_column($t['stations']??[],null,'id');$text=0;
    foreach($newStations as $sid=>$s)if(isset($oldStations[$sid])&&(($s['name']??'')!==$oldStations[$sid]['name']||($s['summary']??'')!==$oldStations[$sid]['summary']))$text++;
    if($text)$changes[]=$text.' Stationsnamen oder Stationstexte geändert.';
    $added=count(array_diff_key($newStations,$oldStations));$removed=count(array_diff_key($oldStations,$newStations));if($added||$removed)$changes[]=$added.' Stationen ergänzt, '.$removed.' Stationen entfernt oder zusammengeführt.';
    $index=static function(array $media): array{$result=[];foreach($media as $m)$result[$m['reference']['originalReference']]=$m;return $result;};$oldMedia=$index($before['trip']['media']);$newMedia=$index($t['media']??[]);$moved=0;
    foreach($newMedia as $ref=>$m)if(isset($oldMedia[$ref])&&$m['stationId']!==$oldMedia[$ref]['stationId'])$moved++;
    if($moved)$changes[]=$moved.' Medien einer anderen Station zugeordnet.';
    $added=count(array_diff_key($newMedia,$oldMedia));$removed=count(array_diff_key($oldMedia,$newMedia));if($added||$removed)$changes[]=$added.' Medien ergänzt, '.$removed.' Medien aus der Webreise entfernt.';
    $metadata=$input['metadata']??[];
    foreach(['trip'=>'Reiseangaben einschließlich Datum','day'=>'Tagesnotizen oder Tagesbilder','route'=>'Manuelle Verbindungsstrecken'] as $scope=>$label){$prefix=$id.'/'.$scope.'/';$filter=static fn($k)=>str_starts_with($k,$prefix);$old=array_filter($before['metadata'],$filter,ARRAY_FILTER_USE_KEY);$new=array_filter($metadata,$filter,ARRAY_FILTER_USE_KEY);if($old!=$new)$changes[]=$label.' werden übernommen.';}
    return ['exists'=>true,'modifiedAt'=>$before['modifiedAt'],'revision'=>$before['revision'],'changes'=>$changes?:['Keine inhaltlichen Änderungen erkannt.']];
}
