<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__.'/capture-time.php';
require_once __DIR__.'/trip-manual-dates.php';
$action=$_GET['action'] ?? ''; $userId=(int)($_SESSION['user_id']??0);
// Read-only requests retain authentication data but must not block login or
// other requests while waiting for OneDrive images on the same session.
if ($_SERVER['REQUEST_METHOD']==='GET' && $action!=='media-sources') session_write_close();
require_once __DIR__ . '/trip-access.php';
function is_latin_station_name(string $name): bool {
  return $name!=='' && preg_match('/^[\p{Latin}\p{M}\p{N}\p{P}\p{Z}]+$/u',$name)===1;
}
function latin_place_name(array $place, ?string $preferred, string $fallback): string {
  $details=is_array($place['namedetails']??null)?$place['namedetails']:[];
  foreach([$preferred,$details['name:de']??null,$details['name:en']??null,$details['int_name']??null] as $candidate){
    $candidate=trim((string)$candidate); if(is_latin_station_name($candidate))return substr($candidate,0,255);
  }
  return substr($fallback,0,255);
}
function station_place_parts(array $place): array {
  $address=is_array($place['address']??null)?$place['address']:[];
  $pick=static function(array $keys) use($address): string {
    foreach($keys as $key){$value=trim((string)($address[$key]??''));if($value!==''&&is_latin_station_name($value))return $value;}
    return '';
  };
  $island=$pick(['island']);
  if($island!=='')$island=trim((string)preg_replace('/^(?:otok|island|insel)\s+/iu','',$island));
  return ['island'=>$island,'locality'=>$pick(['hamlet','village','town','city','municipality'])];
}
function station_import_name(array $place, string $fallback): string {
  $parts=station_place_parts($place);$island=$parts['island'];$locality=$parts['locality'];
  if($locality!==''&&$island!==''&&mb_strtolower($locality)!==mb_strtolower($island))return mb_substr($locality.' · Insel '.$island,0,255);
  if($island!=='')return mb_substr('Insel '.$island,0,255);
  if($locality!=='')return mb_substr($locality,0,255);
  return latin_place_name($place,null,$fallback);
}
function station_reverse_lookup(float $latitude, float $longitude, array $config): ?array {
  static $remoteUnavailable=false;
  $directory=__DIR__.'/cache/geocode';
  if(!is_dir($directory)&&!@mkdir($directory,0750,true))return null;
  $guard=__DIR__.'/cache/.htaccess';
  if(!is_file($guard)&&@file_put_contents($guard,"Require all denied\nOptions -Indexes\n")===false)return null;
  $key=hash('sha256',number_format($latitude,5,'.','').','.number_format($longitude,5,'.',''));
  $path=$directory.'/'.$key.'.json';
  $cached=@file_get_contents($path);
  if($cached!==false){$data=json_decode($cached,true);if(is_array($data))return is_array($data['address']??null)?$data:null;}
  if($remoteUnavailable)return null;
  $lock=@fopen($directory.'/.request-lock','c+');if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);return null;}
  try {
    $cached=@file_get_contents($path);
    if($cached!==false){$data=json_decode($cached,true);if(is_array($data))return is_array($data['address']??null)?$data:null;}
    rewind($lock);$last=(float)stream_get_contents($lock);
    $delay=1.1-(microtime(true)-$last);if($delay>0)usleep((int)ceil($delay*1000000));
    $url=($config['nominatim_reverse_url']??'https://nominatim.openstreetmap.org/reverse').'?'.http_build_query(['format'=>'jsonv2','zoom'=>13,'lat'=>$latitude,'lon'=>$longitude,'addressdetails'=>1,'namedetails'=>1,'accept-language'=>'de,en'], '', '&', PHP_QUERY_RFC3986);
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: AlleReisen/1.0'.(!empty($config['app_url'])?' ('.$config['app_url'].')':'')],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    rewind($lock);ftruncate($lock,0);fwrite($lock,(string)microtime(true));fflush($lock);
    $data=$status===200?json_decode((string)$raw,true):null;
    if($status===404||($status===200&&(!is_array($data)||!is_array($data['address']??null)))){
      @file_put_contents($path,'{}',LOCK_EX);return null;
    }
    if(!is_array($data)||!is_array($data['address']??null)){$remoteUnavailable=true;return null;}
    if(@file_put_contents($path,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false){$remoteUnavailable=true;return null;}
    return $data;
  } finally { flock($lock,LOCK_UN);fclose($lock); }
}
if ($action==='session') { $familyAvailable=false; if(!$userId)json(['user'=>null,'logged_in'=>false,'is_admin'=>false,'is_microsoft'=>false,'is_guest'=>false]); $q=$db->prepare('SELECT display_name,email,is_admin,microsoft_id FROM users WHERE id=?');$q->execute([$userId]);$user=$q->fetch();if(!$user){$_SESSION=[];json(['user'=>null,'logged_in'=>false,'is_admin'=>false,'is_microsoft'=>false,'is_guest'=>false]);}$home=$config['home_location']??null;$homeLocation=is_array($home)&&is_numeric($home['latitude']??null)&&is_numeric($home['longitude']??null)&&abs((float)$home['latitude'])<=90&&abs((float)$home['longitude'])<=180?['latitude'=>(float)$home['latitude'],'longitude'=>(float)$home['longitude']]:null;json(['home_location'=>$homeLocation,'family_circle_available'=>$familyAvailable,'travel_automation_available'=>user_is_admin($db,$userId)&&!empty($user['microsoft_id'])&&($_SESSION['auth_method']??'microsoft')==='microsoft'&&!empty($config['travel_automation_settings_file']),'local_archive'=>defined('ATLAS_LOCAL_MODE')&&ATLAS_LOCAL_MODE,'online_archive_url'=>defined('ATLAS_LOCAL_MODE')&&ATLAS_LOCAL_MODE?($config['online_archive_url']??''):null,'user'=>$user,'logged_in'=>true,'is_admin'=>user_is_admin($db,$userId),'can_import'=>user_can_import($db,$userId),'can_approve_trips'=>user_can_approve_trips($db,$userId),'is_microsoft'=>(bool)($user['microsoft_id']??false)&&($_SESSION['auth_method']??'microsoft')==='microsoft','is_guest'=>($_SESSION['auth_method']??'microsoft')==='guest']); }
if ($action==='trips' && $_SERVER['REQUEST_METHOD']==='GET') {
  $userId=require_user();
  $firstPhoto='(SELECT DATE(MIN(m.taken_at)) FROM media_items m WHERE m.trip_id=t.id AND m.taken_at IS NOT NULL)';
  $lastPhoto='(SELECT DATE(MAX(m.taken_at)) FROM media_items m WHERE m.trip_id=t.id AND m.taken_at IS NOT NULL)';
  $manualDates=trip_manual_dates_supported($db);$manualStart=$manualDates?'t.manual_starts_on,':'';$manualEnd=$manualDates?'t.manual_ends_on,t.manual_starts_on,':'';
  $fields="t.id,(t.user_id=".(int)$userId.") AS is_owner,t.title,t.publication_status,t.source_provider,t.source_folder,t.intro,t.participants,t.main_location,t.main_latitude,t.main_longitude,COALESCE(t.starts_on,$firstPhoto) AS starts_on,COALESCE(t.ends_on,$lastPhoto) AS ends_on,t.media_count,t.cover_url,t.updated_at,t.media_synced_at,t.capture_dates_updated_at,EXISTS(SELECT 1 FROM trip_stops s WHERE s.trip_id=t.id AND s.latitude IS NOT NULL AND s.longitude IS NOT NULL) AS has_gps,(SELECT m.id FROM media_items m WHERE m.trip_id=t.id AND m.media_type='photo' ORDER BY m.taken_at,m.name LIMIT 1) AS cover_media_id";
  $fields.=',(t.user_id='.(int)$userId.') AS can_import';
  $fields=str_replace(['COALESCE(t.starts_on,','COALESCE(t.ends_on,'],['COALESCE('.$manualStart.'t.starts_on,','COALESCE('.$manualEnd.'t.ends_on,'],$fields);
  if($manualDates)$fields.=',t.manual_starts_on,t.manual_ends_on';
  $orderBy="COALESCE({$manualStart}t.starts_on,$firstPhoto,t.created_at) DESC";
  $visibility=trip_visibility_sql($db,$userId);
  if(user_is_admin($db,$userId)){$q=$db->query("SELECT $fields FROM trips t WHERE $visibility ORDER BY $orderBy");}
  else {$allTrips=($_SESSION['auth_method']??'microsoft')==='microsoft'?1:0;$q=$db->prepare("SELECT $fields FROM trips t JOIN users u ON u.id=? WHERE $visibility AND (($allTrips=1 AND t.user_id=u.id) OR (?=1 AND EXISTS(SELECT 1 FROM all_trips_guest_access a WHERE LOWER(a.email)=LOWER(u.email))) OR EXISTS(SELECT 1 FROM trip_guest_access a WHERE a.trip_id=t.id AND LOWER(a.email)=LOWER(u.email))) ORDER BY $orderBy");$q->execute([$userId,$allTrips]);}
  json(['trips'=>$q->fetchAll()]);
}
if ($action==='map-stops' && $_SERVER['REQUEST_METHOD']==='GET') {
  $tripDateField=trip_manual_dates_supported($db)?'t.manual_starts_on':'NULL';
  $userId=require_user();
  $allTripsGrant=($_SESSION['auth_method']??'microsoft')==='microsoft'?'EXISTS(SELECT 1 FROM all_trips_guest_access a JOIN users u ON LOWER(a.email)=LOWER(u.email) WHERE u.id='.(int)$userId.') OR ':'';
  $allTripsGrant.=($_SESSION['auth_method']??'')==='microsoft'?'t.user_id='.(int)$userId.' OR ':'';
  $guestFilter=user_is_admin($db,$userId)?'':' AND ('.$allTripsGrant.'EXISTS(SELECT 1 FROM trip_guest_access a JOIN users u ON LOWER(a.email)=LOWER(u.email) WHERE a.trip_id=t.id AND u.id='.(int)$userId.'))';
  $guestFilter.=' AND '.trip_visibility_sql($db,$userId);
  $stops=$db->query("SELECT s.id,s.trip_id,s.name,s.latitude,s.longitude,s.starts_at,t.title AS trip_title,$tripDateField AS trip_starts_on,(SELECT m.id FROM media_items m WHERE m.stop_id=s.id AND m.media_type='photo' ORDER BY m.taken_at LIMIT 1) AS preview_id,t.updated_at AS trip_updated,s.position AS map_position FROM trip_stops s JOIN trips t ON t.id=s.trip_id WHERE 1=1$guestFilter")->fetchAll();
  $mainLocations=$db->query("SELECT CONCAT('main-location-',t.id) AS id,t.id AS trip_id,t.main_location AS name,t.main_latitude AS latitude,t.main_longitude AS longitude,t.starts_on AS starts_at,t.title AS trip_title,$tripDateField AS trip_starts_on,NULL AS preview_id,t.updated_at AS trip_updated,0 AS map_position FROM trips t WHERE t.main_location IS NOT NULL AND t.main_location<>'' AND t.main_latitude IS NOT NULL AND t.main_longitude IS NOT NULL AND NOT EXISTS(SELECT 1 FROM trip_stops s WHERE s.trip_id=t.id AND s.latitude IS NOT NULL AND s.longitude IS NOT NULL)$guestFilter")->fetchAll();
  $items=array_merge($stops,$mainLocations);
  usort($items,static fn(array $a,array $b):int=>strcmp((string)$b['trip_updated'],(string)$a['trip_updated']) ?: ((int)$a['map_position']<=> (int)$b['map_position']));
  json(['stops'=>$items]);
}
if (in_array($action,['ai-day-summary','ai-proposals'],true)) json(['error'=>'KI-Texte werden auf dieser Website nicht erzeugt.'],410);
$input=json_decode(file_get_contents('php://input'),true) ?: [];
if ($action==='account-deletion' && $_SERVER['REQUEST_METHOD']==='GET') {
  $userId=require_user();require_once __DIR__.'/account-delete.php';
  try { json(account_delete_summary($db,$config,$userId)+['csrf_token'=>account_delete_csrf($config,$userId)]); }
  catch(AccountDeleteError $e){ json(['error'=>$e->getMessage()],$e->status); }
}
if ($action==='account-delete' && $_SERVER['REQUEST_METHOD']==='POST') {
  $userId=require_user();require_once __DIR__.'/account-delete.php';
  if(!hash_equals(account_delete_csrf($config,$userId),(string)($_SERVER['HTTP_X_REISE_ACCOUNT_CSRF']??'')))json(['error'=>'Sicherheitsprüfung fehlgeschlagen. Bitte die Seite neu laden.'],403);
  try { $result=account_delete($db,$config,$userId,(string)($input['confirm_email']??''),__DIR__.'/cache/thumbs'); }
  catch(AccountDeleteError $e){ json(['error'=>$e->getMessage()],$e->status); }
  // The account no longer exists: end this session everywhere it is used.
  $_SESSION=[];
  if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path']??'/',$p['domain']??'',(bool)($p['secure']??true),(bool)($p['httponly']??true));}
  session_destroy();
  json($result);
}
if ($action==='station-actions') {
  require_once __DIR__.'/station-actions.php';handle_station_actions($db,$input);
}
if ($action==='manual-routes') {
  require_once __DIR__.'/manual-routes.php';handle_manual_routes($db,$userId,$input);
}
require_once __DIR__.'/media-sources.php';
if($action==='onedrive-folders'){
  require_once __DIR__.'/onedrive-folders.php';handle_onedrive_folders($db,$config);
}
if(in_array($action,['media-sources','local-import','local-media','dropbox-import'],true))handle_media_sources($db,$config,$action,$input);
if ($action==='route-cache' && in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) {
  require_once __DIR__.'/route-cache.php';handle_route_cache($db,$userId,$input);
}
if ($action==='media-remove' && $_SERVER['REQUEST_METHOD']==='POST') {
  require_trip_editor($db,(string)($input['trip']??''));
  require_once __DIR__.'/media-remove.php';
  try{$result=media_remove($db,(string)($input['trip']??''),is_array($input['media']??null)?$input['media']:[]);}
  catch(Throwable $error){$status=in_array($error->getCode(),[404,409,422],true)?$error->getCode():500;json(['error'=>$status===500?'Die Aufnahmen konnten nicht entfernt werden.':$error->getMessage()],$status);}
  json($result);
}
if ($action==='media-move' && $_SERVER['REQUEST_METHOD']==='POST') {
  // Both trips must be editable by this person: the source loses, the target gains the captures.
  require_trip_editor($db,(string)($input['trip']??''));require_trip_editor($db,(string)($input['target']??''));
  require_once __DIR__.'/media-remove.php';
  try{$result=media_move($db,(string)($input['trip']??''),(string)($input['target']??''),is_array($input['media']??null)?$input['media']:[]);}
  catch(Throwable $error){$status=in_array($error->getCode(),[404,409,422],true)?$error->getCode():500;json(['error'=>$status===500?'Die Aufnahmen konnten nicht verschoben werden.':$error->getMessage()],$status);}
  json($result);
}
if ($action==='media-time' && $_SERVER['REQUEST_METHOD']==='PATCH') {
  $editor=require_trip_editor($db,(string)($input['trip']??''));
  if(!array_key_exists('expected_taken_at',$input)||!array_key_exists('expected_capture_source',$input))json(['error'=>'Bitte die Station neu laden.'],422);
  require_once __DIR__.'/manual-media-time.php';
  try{$result=manual_media_time($db,$editor,(string)($input['trip']??''),(string)($input['media']??''),(string)($input['taken_at']??''),$input['expected_taken_at'],$input['expected_capture_source']);}
  catch(Throwable $error){$status=in_array($error->getCode(),[404,409,422],true)?$error->getCode():500;json(['error'=>$status===500?'Die Aufnahmezeit konnte nicht gespeichert werden.':$error->getMessage()],$status);}
  json($result);
}
if ($action==='capture-dates' && $_SERVER['REQUEST_METHOD']==='POST') {
  require_source_trip_provider($db,(string)($input['trip']??''),'onedrive');
  require_once __DIR__.'/capture-dates.php';
  handle_capture_dates($db,$config,$input);
}
function cached_thumbnail_path(string $mediaId, string $size='medium'): string { $safeSize=in_array($size,['small','medium','large','fullscreen'],true)?$size:'medium'; return __DIR__.'/cache/thumbs/'.preg_replace('/[^a-zA-Z0-9-]/','',$mediaId).'-'.$safeSize.'.jpg'; }
function remove_cached_thumbnails(string $mediaId): int {
  $removed=0;
  foreach(['small','medium','large','fullscreen'] as $size) {
    $path=cached_thumbnail_path($mediaId,$size);
    if(is_file($path)&&@unlink($path))$removed++;
  }
  return $removed;
}
function remove_orphan_cached_thumbnails(PDO $db): int {
  $known=array_fill_keys($db->query('SELECT id FROM media_items')->fetchAll(PDO::FETCH_COLUMN),true);
  $removed=0;
  foreach(glob(__DIR__.'/cache/thumbs/*.jpg') ?: [] as $path){
    if(!preg_match('/^([0-9a-f-]{36})-(small|medium|large|fullscreen)\.jpg$/i',basename($path),$match)||isset($known[$match[1]]))continue;
    if(is_file($path)&&@unlink($path))$removed++;
  }
  return $removed;
}
if ($action==='sync-trip-media' && $_SERVER['REQUEST_METHOD']==='POST') {
  require_source_trip_provider($db,(string)($input['trip']??''),'onedrive');
  require_once __DIR__.'/sync-trip-media.php';
  handle_trip_media_sync($db,$config,$input);
}
if ($action==='trip-review') {
  $actor=require_admin($db);json(trip_review_manage($db,$actor,$_SERVER['REQUEST_METHOD'],$input,(string)($_SERVER['HTTP_X_REISE_REVIEW_CSRF']??'')));
}
if ($action==='import-permissions') {
  $actor=require_admin($db);
  json(import_permissions_manage($db,$config,$actor,$_SERVER['REQUEST_METHOD'],$input,(string)($_SERVER['HTTP_X_REISE_IMPORT_CSRF']??'')));
}
if ($action==='admin-accounts') {
  require_admin($db);
  require_once __DIR__.'/admin-account-management.php';
  try { json(admin_accounts_manage($db,$config,$userId,$_SERVER['REQUEST_METHOD'],is_array($input??null)?$input:[],(string)($_SERVER['HTTP_X_REISE_ADMIN_CSRF']??''))); }
  catch(AdminAccountError $e){json(['error'=>$e->getMessage()],$e->status);}
  catch(PDOException $e){json(['error'=>'Adminrechte konnten nicht gespeichert werden. Bitte neu laden und erneut versuchen.'],409);}
}

if ($action==='all-trips-guests') {
  require_admin($db);
  if($_SERVER['REQUEST_METHOD']==='GET') json(['guests'=>$db->query('SELECT email,created_at FROM all_trips_guest_access ORDER BY email')->fetchAll()]);
  $email=strtolower(trim((string)($input['email']??'')));
  if(!filter_var($email,FILTER_VALIDATE_EMAIL))json(['error'=>'Bitte gib eine gültige E-Mail-Adresse ein.'],422);
  if($_SERVER['REQUEST_METHOD']==='POST') {$add=$db->prepare('INSERT INTO all_trips_guest_access(email) VALUES(?) ON DUPLICATE KEY UPDATE email=VALUES(email)');$add->execute([$email]);json(['ok'=>true]);}
  if($_SERVER['REQUEST_METHOD']==='DELETE') {$remove=$db->prepare('DELETE FROM all_trips_guest_access WHERE email=?');$remove->execute([$email]);json(['ok'=>true]);}
  json(['error'=>'Methode nicht erlaubt'],405);
}
if ($action==='trip-guests') {
  require_trip_editor($db,trim((string)($_SERVER['REQUEST_METHOD']==='GET'?($_GET['trip']??''):($input['trip']??''))));
  $tripId=trim((string)($_SERVER['REQUEST_METHOD']==='GET'?($_GET['trip']??''):($input['trip']??'')));
  if($tripId==='')json(['error'=>'Reise fehlt.'],422);
  if($_SERVER['REQUEST_METHOD']==='GET') {
    $q=$db->prepare('SELECT a.email,EXISTS(SELECT 1 FROM all_trips_guest_access x WHERE LOWER(x.email)=LOWER(a.email)) AS has_archive_access FROM trip_guest_access a WHERE a.trip_id=? ORDER BY a.email');
    $q->execute([$tripId]);json(['guests'=>$q->fetchAll()]);
  }
  if($_SERVER['REQUEST_METHOD']==='DELETE') {
    $email=strtolower(trim((string)($input['email']??'')));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))json(['error'=>'Bitte gib eine gültige E-Mail-Adresse ein.'],422);
    $db->beginTransaction();
    try {
      $q=$db->prepare('DELETE FROM trip_guest_access WHERE trip_id=? AND LOWER(email)=?');$q->execute([$tripId,$email]);
      $q=$db->prepare('DELETE FROM trip_invitations WHERE trip_id=? AND LOWER(email)=? AND accepted_at IS NULL');$q->execute([$tripId,$email]);
      $db->commit();
    } catch(Throwable $error) { if($db->inTransaction())$db->rollBack();json(['error'=>'Der Gastzugang konnte nicht entfernt werden.'],500); }
    json(['ok'=>true]);
  }
  json(['error'=>'Methode nicht erlaubt.'],405);
}
if ($action==='guest-emails' && $_SERVER['REQUEST_METHOD']==='GET') {
  // Addresses already invited to the caller's trips (all trips for admins): suggestions when sharing the next trip.
  $userId=require_user();
  if(user_is_admin($db,$userId))$q=$db->query('SELECT DISTINCT LOWER(email) AS email FROM trip_guest_access ORDER BY email LIMIT 500');
  else{$q=$db->prepare('SELECT DISTINCT LOWER(a.email) AS email FROM trip_guest_access a JOIN trips t ON t.id=a.trip_id WHERE t.user_id=? ORDER BY email LIMIT 500');$q->execute([$userId]);}
  json(['emails'=>array_column($q->fetchAll(),'email')]);
}
if ($action==='trip-invite' && $_SERVER['REQUEST_METHOD']==='POST') {
  // Invitations by mail go through the sender's Microsoft account. deliver=link returns the personal link instead,
  // so the owner or an admin can send it via Signal, WhatsApp or another messenger.
  $byLink=($input['deliver']??'')==='link';
$senderId=require_trip_editor($db,trim((string)($input['trip']??'')));if(!$byLink){$mq=$db->prepare('SELECT microsoft_id FROM users WHERE id=?');$mq->execute([$senderId]);if(!(string)$mq->fetchColumn())json(['error'=>'Einladungen erfordern eine Microsoft-Anmeldung.'],403);}
  $tripId=trim((string)($input['trip']??''));$email=strtolower(trim((string)($input['email']??'')));
  if(!$tripId||!filter_var($email,FILTER_VALIDATE_EMAIL))json(['error'=>'Bitte gib eine gültige E-Mail-Adresse ein.'],422);
  $trip=$db->prepare('SELECT title FROM trips WHERE id=?');$trip->execute([$tripId]);$title=(string)$trip->fetchColumn();if($title==='')json(['error'=>'Reise nicht gefunden.'],404);
  $token=bin2hex(random_bytes(32));$id=uuid();
  try {$invite=$db->prepare('INSERT INTO trip_invitations(id,trip_id,email,token_hash,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))');$invite->execute([$id,$tripId,$email,hash('sha256',$token)]);}
  catch(Throwable $e){json(['error'=>'Einladung konnte nicht erstellt werden.'],500);}
  $url=rtrim((string)$config['app_url'],'/').'/api/invite.php?token='.rawurlencode($token);
  if($byLink){
    try {$grant=$db->prepare('INSERT INTO trip_guest_access(trip_id,email) VALUES(?,?) ON DUPLICATE KEY UPDATE email=VALUES(email)');$grant->execute([$tripId,$email]);}
    catch(Throwable $e){$db->prepare('DELETE FROM trip_invitations WHERE id=?')->execute([$id]);json(['error'=>'Der Reisezutritt konnte nicht eingerichtet werden.'],500);}
    json(['ok'=>true,'url'=>$url,'title'=>$title,'expiresDays'=>7]);
  }
  if(!send_invitation_mail($db,$config,$senderId,$email,$title,$url)){$db->prepare('DELETE FROM trip_invitations WHERE id=?')->execute([$id]);json(['error'=>'E-Mail konnte nicht gesendet werden. Bitte melde dich erneut mit Microsoft an und bestätige Mail.Send.'],502);}
  try {$grant=$db->prepare('INSERT INTO trip_guest_access(trip_id,email) VALUES(?,?) ON DUPLICATE KEY UPDATE email=VALUES(email)');$grant->execute([$tripId,$email]);}
  catch(Throwable $e){json(['error'=>'E-Mail wurde versendet, aber der Reisezutritt konnte nicht eingerichtet werden.'],500);}
  json(['ok'=>true]);
}
if ($action==='preview' && $_SERVER['REQUEST_METHOD']==='GET') {
  $trip=(string)($_GET['trip']??'');$media=(string)($_GET['media']??'');$size=in_array($_GET['size']??'', ['small','medium','large','fullscreen'],true)?$_GET['size']:'medium';
  require_trip_access($db,(int)($_SESSION['user_id']??0),$trip);
  $q=$db->prepare('SELECT m.id,m.onedrive_item_id,m.source_provider,m.source_item_id,m.storage_path,m.local_original_path,m.local_original_root,m.mime_type,m.thumbnail_url,t.user_id FROM media_items m JOIN trips t ON t.id=m.trip_id WHERE m.id=? AND m.trip_id=?');$q->execute([$media,$trip]);$item=$q->fetch();if(!$item){http_response_code(404);exit;}
  if($item['source_provider']!=='onedrive')source_preview($item,$config,$db,$size);
  $path=cached_thumbnail_path((string)$item['id'],$size);
  if(is_file($path)){header('Content-Type: image/jpeg');header('Cache-Control: private, max-age=31536000, immutable');readfile($path);exit;}
  $token=graph_access_token($db,$config,(int)$item['user_id']);if(!$token){http_response_code(409);exit;}
  $ch=curl_init(onedrive_item_url((string)$item['onedrive_item_id']).'/thumbnails/0/'.rawurlencode($size==='fullscreen'?'c2048x2048':$size).'/content');curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>30]);$image=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);if($status!==200||!is_string($image)||$image===''){http_response_code(404);exit;}
  $directory=dirname($path);if(!is_dir($directory)){@mkdir($directory,0750,true);@file_put_contents(dirname($directory).'/.htaccess',"Require all denied\nOptions -Indexes\n");}
  if(is_dir($directory)&&@file_put_contents($path,$image,LOCK_EX)!==false){$update=$db->prepare('UPDATE media_items SET thumbnail_url=? WHERE id=?');$update->execute(['cache/thumbs/'.basename($path),(string)$item['id']]);}
  header('Content-Type: '.(str_starts_with($type,'image/')?$type:'image/jpeg'));header('Cache-Control: private, max-age=31536000, immutable');echo $image;exit;
}
if ($action==='day-weather' && $_SERVER['REQUEST_METHOD']==='GET') {
  $trip=(string)($_GET['trip']??'');require_trip_access($db,$userId,$trip);
  $day=(string)($_GET['day']??'');
  $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
  if(!$date||$date->format('Y-m-d')!==$day)json(['error'=>'Ungültiger Reisetag'],422);
  $q=$db->prepare('SELECT name,latitude,longitude FROM trip_stops WHERE trip_id=? AND DATE(starts_at)=? AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY position LIMIT 1');
  $q->execute([$trip,$day]);$stop=$q->fetch();
  if(!$stop)json(['available'=>false,'reason'=>'Für diesen Tag fehlt ein Standort.']);
  require_once __DIR__.'/day-weather.php';
  try{json(['available'=>true,'location'=>$stop['name'],'day'=>$day]+day_weather((float)$stop['latitude'],(float)$stop['longitude'],$day,$config));}
  catch(Throwable $e){json(['available'=>false,'reason'=>'Wetterdaten derzeit nicht verfügbar.']);}
}
if ($action==='day-summary' && $_SERVER['REQUEST_METHOD']==='GET') {
  $userId=require_user(); $trip=(string)($_GET['trip']??'');require_trip_access($db,$userId,$trip);$day=(string)($_GET['day']??'');$q=$db->prepare('SELECT summary,photo_media_id FROM trip_day_summaries WHERE trip_id=? AND day_date=?');$q->execute([$trip,$day]);$record=$q->fetch()?:[];require_once __DIR__.'/trip-steps.php';json(['summary'=>$record['summary']??'','photo_media_id'=>$record['photo_media_id']??null,'steps'=>trip_steps_read($db,$trip,$day)]);
}
if ($action==='day-summary' && $_SERVER['REQUEST_METHOD']==='PATCH') {
  require_trip_editor($db,(string)($input['trip']??''));$trip=(string)($input['trip']??'');$day=(string)($input['day']??'');$summary=trim((string)($input['summary']??''));if(!$trip||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day)||!$summary)json(['error'=>'Ungültige Daten'],422);$q=$db->prepare('INSERT INTO trip_day_summaries(trip_id,day_date,summary) VALUES(?,?,?) ON DUPLICATE KEY UPDATE summary=VALUES(summary)');$q->execute([$trip,$day,$summary]);json(['ok'=>true]);
}
if ($action==='day-summary-photo' && $_SERVER['REQUEST_METHOD']==='PATCH') {
  require_trip_editor($db,(string)($input['trip']??''));$trip=(string)($input['trip']??'');$day=(string)($input['day']??'');$media=(string)($input['media']??'');if(!$trip||!$media||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day))json(['error'=>'Ungültige Daten'],422);
  $valid=$db->prepare("SELECT m.id FROM media_items m JOIN trip_stops s ON s.id=m.stop_id WHERE m.id=? AND m.trip_id=? AND m.media_type IN ('photo','video') AND DATE(s.starts_at)=?");$valid->execute([$media,$trip,$day]);if(!$valid->fetchColumn())json(['error'=>'Die Aufnahme gehört nicht zu diesem Reisetag.'],422);
  $q=$db->prepare("INSERT INTO trip_day_summaries(trip_id,day_date,summary,photo_media_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE photo_media_id=VALUES(photo_media_id)");$q->execute([$trip,$day,'',$media]);json(['ok'=>true]);
}
if ($action==='ai-day-summary' && $_SERVER['REQUEST_METHOD']==='POST') {
  require_admin($db);$trip=(string)($input['trip']??'');$day=(string)($input['day']??'');if(!$trip||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day))json(['error'=>'Ungültige Daten'],422);
  $q=$db->prepare('SELECT id,name,starts_at,ends_at,latitude,longitude FROM trip_stops WHERE trip_id=? AND DATE(starts_at)=? ORDER BY starts_at');$q->execute([$trip,$day]);$stops=$q->fetchAll();if(!$stops)json(['error'=>'Keine Stationen für diesen Tag vorhanden.'],404);
  $details=[];$visualStops=[];$firstPhoto=$db->prepare("SELECT onedrive_item_id FROM media_items WHERE stop_id=? AND media_type='photo' ORDER BY taken_at LIMIT 1");
  foreach($stops as $stop){$name=trim((string)($stop['name']??''));$unnamed=$name===''||preg_match('/^(Aufnahmeort|Aufnahmen)\s*·/u',$name);if($unnamed){$firstPhoto->execute([$stop['id']]);$photo=$firstPhoto->fetchColumn();if($photo)$visualStops[]=(string)$photo;continue;}$gps=$stop['latitude']!==null&&$stop['longitude']!==null?' (GPS '.number_format((float)$stop['latitude'],5,'.','').', '.number_format((float)$stop['longitude'],5,'.','').')':'';$details[]=$name.$gps.' von '.substr((string)$stop['starts_at'],11,5).' bis '.substr((string)$stop['ends_at'],11,5).' Uhr';}
  // Build the representative daily image set from named and unnamed stops.
  // This replaces the earlier unnamed-stops-only image selection.
  $visualStops=[];
  foreach($stops as $visualStop){$firstPhoto->execute([$visualStop['id']]);$photo=$firstPhoto->fetchColumn();if($photo)$visualStops[]=(string)$photo;}
  $key=trim((string)($config['gemini_api_key']??''));if($key==='')json(['error'=>'Gemini ist noch nicht konfiguriert.'],503);
  $prompt='Schreibe auf Deutsch genau einen vollständigen, zusammenhängenden Tagesabsatz für eine Reisedokumentation mit 80 bis 110 Wörtern. Keine Stichpunkte, Satzfragmente oder Überschriften. Beziehe die nachfolgend gelisteten Orte nachvollziehbar im Tagesverlauf ein und beschreibe vor allem ihren Charakter. Nenne zu einem Ort nur dann eine konkrete, überprüfbare Besonderheit, wenn du sie sicher kennst. Erfinde weder Orte noch Aktivitäten. Namenlose Zwischenstationen sollen nicht erwähnt werden. Einige Fotos solcher namenlosen Stationen können beigefügt sein: Greife sie nur auf, wenn sie eindeutig etwas Bemerkenswertes zeigen; beschreibe dabei ausschließlich klar Erkennbares und erfinde keinen Ort oder Anlass dazu. Nenne Personen auf den Stationsfotos, sofern sie über eine Personendefinition in OneDrive eindeutig zugeordnet sind; nutze dafür ausschließlich diese hinterlegten Namen und rate keine Identitäten. Besuchte Orte: '.($details?implode('; ',$details):'Keine Ortsnamen verfügbar.');
  $parts=[['text'=>$prompt],['text'=>'ANTWORTE AUSSCHLIESSLICH AUF DEUTSCH. Schreibe 80 bis 110 Wörter in genau einem vollständigen Absatz und beende ihn mit einem Satzzeichen. Gib niemals GPS-Werte, Rohdaten oder englischen Text aus. Die beigefügten Fotos stammen von Stationen dieses Tages. Nutze ausschließlich klar sichtbare Bildinhalte als Ergänzung, etwa Landschaft, Leuchttürme, Essen, Sonnenuntergänge oder Aktivitäten. Erfinde keine Details und ordne eine Beobachtung keinem Ort zu, wenn die Zuordnung nicht eindeutig ist.']];
  if($visualStops){$owner=$db->prepare('SELECT user_id FROM trips WHERE id=?');$owner->execute([$trip]);$q=$db->prepare('SELECT refresh_token_enc FROM oauth_tokens WHERE user_id=?');$q->execute([(int)$owner->fetchColumn()]);$raw=base64_decode((string)$q->fetchColumn(),true);$refresh=$raw!==false&&strlen($raw)>=29?openssl_decrypt(substr($raw,28),'aes-256-gcm',hex2bin($config['encryption_key']),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16)):false;if($refresh!==false){$tokenPayload=['client_id'=>$config['microsoft_client_id'],'client_secret'=>$config['microsoft_client_secret'],'grant_type'=>'refresh_token','refresh_token'=>$refresh,'scope'=>'openid profile offline_access User.Read Files.Read'];$ch=curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($tokenPayload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);$token=json_decode((string)curl_exec($ch),true);curl_close($ch);foreach(array_slice($visualStops,0,4) as $itemId){if(empty($token['access_token']))break;$ch=curl_init(onedrive_item_url((string)$itemId).'/thumbnails/0/medium/content');curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token['access_token']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>30]);$image=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);if($status===200&&is_string($image)&&strlen($image)>0&&strlen($image)<=3000000)$parts[]=['inlineData'=>['mimeType'=>str_starts_with($type,'image/')?$type:'image/jpeg','data'=>base64_encode($image)]];}}}
  $payload=['contents'=>[['role'=>'user','parts'=>$parts]],'generationConfig'=>['temperature'=>0.35,'maxOutputTokens'=>600]];
  $models=array_values(array_filter(array_unique([(string)($config['gemini_model']??''),(string)($config['gemini_fallback_model']??'')])));
  $isComplete=static function(string $text): bool {$words=preg_split('/\s+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY);return count($words)>=25&&strlen($text)>=140&&preg_match('/[.!?…]["\')\]]?$/u',trim($text))===1;};
  $summary='';$shortCandidate='';$status=0;
  foreach($models as $model){
    for($attempt=0;$attempt<2;$attempt++){
      $request=$payload;
      if($attempt===1)$request['contents'][0]['parts'][]=['text'=>'Erweitere den folgenden zu kurzen Entwurf auf 80 bis 110 Wörter. Behalte nur seine belegbaren Aussagen bei, ergänze keine erfundenen Fakten und gib ausschließlich den vollständigen erweiterten Absatz aus: '.$shortCandidate];
      $ch=curl_init('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent');
      curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($request,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60]);
      $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
      $result=json_decode((string)$raw,true);$candidate=trim((string)($result['candidates'][0]['content']['parts'][0]['text']??''));
      if($status===200&&$isComplete($candidate)){$summary=$candidate;break 2;}
      if($status===200&&$candidate!=='')$shortCandidate=$candidate;
    }
  }
  if($summary==='')json(['error'=>'Gemini lieferte wiederholt einen zu kurzen Tagestext. Bitte später erneut versuchen.'],502);
  $q=$db->prepare('INSERT INTO trip_day_summaries(trip_id,day_date,summary) VALUES(?,?,?) ON DUPLICATE KEY UPDATE summary=VALUES(summary)');$q->execute([$trip,$day,$summary]);json(['summary'=>$summary]);
}
if ($action==='ai-proposals' && $_SERVER['REQUEST_METHOD']==='POST') {
  $userId=require_admin($db);
  $key=trim((string)($config['gemini_api_key']??''));
  if($key==='') json(['error'=>'Gemini ist noch nicht konfiguriert.'],503);
  $tripId=(string)($input['trip']??''); $tripQuery=$db->prepare('SELECT id FROM trips WHERE id=?'); $tripQuery->execute([$tripId]);
  if(!$tripQuery->fetchColumn()) json(['error'=>'Reisedoku nicht gefunden.'],404);
  $stations=$input['stations']??[]; $images=$input['images']??[];
  if(!is_array($stations)||!is_array($images)||count($images)===0||count($images)>8) json(['error'=>'Ungültige Bildauswahl.'],422);
  $parts=[]; $labels=[]; $totalBytes=0;
  foreach($images as $index=>$image) {
    if(!is_array($image)||!is_string($image['data']??null)||!is_string($image['mime_type']??null)) json(['error'=>'Ungültiges Bildformat.'],422);
    $bytes=base64_decode($image['data'],true);
    if($bytes===false||strlen($bytes)>4*1024*1024) json(['error'=>'Ein Bild ist zu groß.'],422);
    $totalBytes+=strlen($bytes); if($totalBytes>16*1024*1024) json(['error'=>'Die Bildauswahl ist zu groß.'],422);
    $mime=$image['mime_type']; if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)) $mime='image/jpeg';
    $station=is_array($stations[$index]??null)?$stations[$index]:[];
    $coordinates=is_numeric($station['latitude']??null)&&is_numeric($station['longitude']??null) ? 'GPS '.number_format((float)$station['latitude'],5,'.','').', '.number_format((float)$station['longitude'],5,'.','') : 'ohne GPS';
    $labels[]=($index+1).'. '.substr((string)($station['name']??'Station'),0,255).', '.substr((string)($station['starts_at']??''),0,32).', '.$coordinates;
    $parts[]=['inlineData'=>['mimeType'=>$mime,'data'=>$image['data']]];
  }
  $prompt='Erstelle auf Deutsch ausschließlich valides JSON im Format {"reise":"...","stationen":[{"ort":"...","text":"..."}]}. Schreibe einen atmosphärischen Einstieg mit maximal 80 Wörtern und pro Bildgruppe einen sachlich-poetischen Stationsvorschlag mit maximal 60 Wörtern. Zu jeder Bildgruppe stehen gegebenenfalls GPS-Koordinaten bereit: Wenn du anhand dieser Koordinaten einen bekannten Ort im Umkreis von höchstens 5 km zuverlässig zuordnen kannst, integriere höchstens eine kurze, zutreffende Information über diesen Ort in den Stationstext. Wenn Zuordnung oder Fakt nicht sicher sind, erwähne keinen Ort und erfinde keine Fakten. Bildgruppen in Reihenfolge: '.implode('; ',$labels);
  array_unshift($parts,['text'=>$prompt]);
  $payload=['contents'=>[['role'=>'user','parts'=>$parts]],'generationConfig'=>['responseMimeType'=>'application/json','temperature'=>0.7,'maxOutputTokens'=>1400]];
  $models=array_values(array_filter(array_unique([(string)($config['gemini_model']??'gemini-3.8-flash'),(string)($config['gemini_fallback_model']??'gemini-3.7-flash')])));
  $raw=''; $curlError=''; $status=0;
  foreach($models as $model) {
    for($attempt=0;$attempt<3;$attempt++) {
      $ch=curl_init('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent');
      curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60]);
      $raw=curl_exec($ch); $curlError=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
      if($status===200) break 2;
      if(!in_array($status,[429,503],true)) break;
      if($attempt<2) usleep(500000*($attempt+1));
    }
    if(!in_array($status,[429,503,404],true)) break;
  }
  $result=json_decode((string)$raw,true); $generated=$result['candidates'][0]['content']['parts'][0]['text']??null;
  if($status!==200||!is_string($generated)) {
    $providerMessage=trim((string)($result['error']['message']??''));
    $blockedReason=trim((string)($result['promptFeedback']['blockReason']??''));
    if($status===401||$status===403) $message='Gemini-Zugriff wurde abgelehnt. Prüfe den API-Schlüssel und dessen Berechtigungen.';
    elseif($status===429) $message='Das Gemini-Kontingent ist aktuell ausgeschöpft. Bitte versuche es später erneut.';
    elseif($status===404) $message='Das konfigurierte Gemini-Modell ist für diesen API-Schlüssel nicht verfügbar.';
    elseif($blockedReason!=='') $message='Gemini hat diese Anfrage nicht verarbeitet ('.preg_replace('/[^A-Z_]/','',$blockedReason).').';
    elseif($curlError!=='') $message='Gemini ist momentan nicht erreichbar. Bitte versuche es erneut.';
    elseif($providerMessage!=='') $message='Gemini konnte keine Vorschläge erstellen: '.substr($providerMessage,0,240);
    else $message='Gemini konnte keine Vorschläge erstellen.';
    json(['error'=>$message],$status>=400&&$status<600?$status:502);
  }
  $proposals=json_decode($generated,true); if(!is_array($proposals)) json(['error'=>'Gemini lieferte kein gültiges Ergebnis.'],502);
  $suggestions=$proposals['stationen']??[];
  if(is_array($suggestions)) {
    $update=$db->prepare('UPDATE trip_stops SET summary=? WHERE id=? AND trip_id=?');
    foreach($suggestions as $index=>$suggestion) {
      $station=is_array($stations[$index]??null)?$stations[$index]:[];
      $stationId=(string)($station['id']??''); $summary=trim((string)(is_array($suggestion)?($suggestion['text']??''):''));
      if($stationId!=='' && $summary!=='') $update->execute([$summary,$stationId,$tripId]);
    }
  }
  json(['proposals'=>$proposals]);
}
if ($action==='import' && $_SERVER['REQUEST_METHOD']==='POST') {
  $userId=require_importer($db); // import right (admins have it implicitly); appending only to own trips (source_trip)
  $title=trim((string)($input['title']??'')); $folder=trim((string)($input['source_folder']??''));
  $existingTrip=(string)($input['trip']??'');
  if($existingTrip!=='')$title=(string)source_trip($db,$userId,'','onedrive','',$existingTrip)['title'];
  if($title==='') json(['error'=>'Titel fehlt'],422);
  if($folder==='' || strlen($folder)>1024) json(['error'=>'Ungültiger OneDrive-Ordnerpfad'],422);
  $accessToken=graph_access_token($db,$config,$userId);
  if(!$accessToken)json(['error'=>'OneDrive ist nicht verbunden oder die Verbindung ist abgelaufen. Bitte OneDrive verbinden.'],409);
  $token=['access_token'=>$accessToken];
  $segments=explode('/',$folder); if(in_array('', $segments,true) || in_array('.', $segments,true) || in_array('..', $segments,true)) json(['error'=>'Ungültiger OneDrive-Ordnerpfad'],422);
  $importNonce=(string)($input['import_token']??'');
  if ($importNonce!=='') {
    $pending=$_SESSION['capture_import']??null;
    if (!$pending||!hash_equals($pending['token'],$importNonce)||$pending['expires']<time()||$pending['title']!==$title||$pending['folder']!==$folder||($pending['trip']??'')!==$existingTrip) json(['error'=>'Importprüfung abgelaufen. Bitte neu starten.'],409);
    $items=$pending['items'];
  } else {
  try { $items=onedrive_collect_folder($folder,static function(string $url) use($accessToken): array {
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode((string)$raw,true);
    if($status!==200||!is_array($data))throw new RuntimeException('OneDrive-Ordner konnte nicht gelesen werden. Bei geteilten Ordnern bitte OneDrive erneut verbinden und die Verknüpfung unter Meine Dateien prüfen.');return $data;
  }); }catch(Throwable $error){json(['error'=>$error->getMessage()],502);}

    $queue=[];
    foreach ($items as $index=>&$item) {
      if (capture_graph_time($item)===null) $queue[]=$index;
      else $item['_capture']=capture_resolve($item);
    }
    unset($item);
    $pending=['token'=>bin2hex(random_bytes(24)),'title'=>$title,'folder'=>$folder,'trip'=>$existingTrip,'queue'=>$queue,'total'=>count($queue),'items'=>$items,'expires'=>time()+1800];
  }
  try {
    for ($n=0;$n<2&&$pending['queue'];$n++) {
      $index=$pending['queue'][0];
      $items[$index]['_capture']=capture_resolve_original($items[$index],$token['access_token'],$userId);array_shift($pending['queue']);
    }
  } catch (Throwable $error) {json(['error'=>'Aufnahmedaten konnten nicht vollständig geprüft werden. Bitte den Import erneut versuchen.'],502);}
  if ($pending['queue']) {
    $pending['items']=$items;$pending['expires']=time()+1800;$_SESSION['capture_import']=$pending;
    json(['pending'=>true,'import_token'=>$pending['token'],'remaining'=>count($pending['queue']),'total'=>$pending['total']]);
  }
  unset($_SESSION['capture_import']);
  usort($items,function(array $a,array $b): int { $qa=((int)($a['image']['width']??0)*(int)($a['image']['height']??0)) ?: (int)($a['size']??0); $qb=((int)($b['image']['width']??0)*(int)($b['image']['height']??0)) ?: (int)($b['size']??0); return $qb<=>$qa; });
  $selected=$items;
  usort($selected,function(array $a,array $b): int { return strcmp((string)(media_capture_date($a)??'9999-12-31'),(string)(media_capture_date($b)??'9999-12-31')); });
  // A folder can contain one or two files with an incorrect camera date.
  // Keep the largest continuous date cluster and ignore isolated dates outside
  // that trip period. Files without a usable date are retained.
  $dated=[];
  foreach($selected as $index=>$item){
    $rawDate=media_capture_date($item);
    $timestamp=is_string($rawDate)?strtotime($rawDate):false;
    if($timestamp!==false)$dated[]=['index'=>$index,'timestamp'=>$timestamp];
  }
  if(count($dated)>=3){
    usort($dated,static fn(array $a,array $b):int=>$a['timestamp']<=>$b['timestamp']);
    $clusters=[];$cluster=[];$previous=null;
    foreach($dated as $entry){
      if($previous!==null && $entry['timestamp']-$previous>3*86400){$clusters[]=$cluster;$cluster=[];}
      $cluster[]=$entry;$previous=$entry['timestamp'];
    }
    if($cluster)$clusters[]=$cluster;
    usort($clusters,static fn(array $a,array $b):int=>count($b)<=>count($a));
    $main=$clusters[0]??[];
    $outlierCount=count($dated)-count($main);
    if(count($main)>=4 && $outlierCount>0 && $outlierCount<=max(2,(int)floor(count($main)*0.1))){
      $first=$main[0]['timestamp'];$last=$main[count($main)-1]['timestamp'];
      $selected=array_values(array_filter($selected,static function(array $item) use($first,$last):bool {
        $rawDate=media_capture_date($item);
        $timestamp=is_string($rawDate)?strtotime($rawDate):false;
        return $timestamp===false || ($timestamp>=$first && $timestamp<=$last);
      }));
    }
  }
  $groups=[];
  $distanceKm=static function(float $latA,float $lonA,float $latB,float $lonB): float { $earth=6371.0;$dLat=deg2rad($latB-$latA);$dLon=deg2rad($lonB-$lonA);$a=sin($dLat/2)**2+cos(deg2rad($latA))*cos(deg2rad($latB))*sin($dLon/2)**2;return 2*$earth*asin(min(1,sqrt($a))); };
  // Eine Station steht für einen Kalendertag. Innerhalb eines Tages entsteht
  // nur bei einem Ortswechsel über 1 km nach mehr als einer Stunde eine weitere.
  $target=null;$previous=null;
  foreach($selected as $item) { $taken=media_capture_date($item); $taken=is_string($taken)?substr($taken,0,19):null; $lat=is_numeric($item['location']['latitude']??null)?(float)$item['location']['latitude']:null; $lon=is_numeric($item['location']['longitude']??null)?(float)$item['location']['longitude']:null;$day=$taken?substr($taken,0,10):'ohne-datum';$newDay=$previous!==null&&$day!==$previous['day'];$timeGap=$previous!==null&&$taken!==null&&$previous['taken']!==null&&abs(strtotime($taken)-strtotime($previous['taken']))>3600;$moved=$previous!==null&&$lat!==null&&$lon!==null&&$previous['lat']!==null&&$previous['lon']!==null&&$distanceKm($lat,$lon,$previous['lat'],$previous['lon'])>1.0;if($target===null||$newDay||($moved&&$timeGap)){$target=uuid();$groups[$target]=['id'=>$target,'name'=>$day,'latitude'=>$lat,'longitude'=>$lon,'starts_at'=>$taken,'ends_at'=>$taken,'items'=>[]];}$groups[$target]['ends_at']=$taken??$groups[$target]['ends_at'];$groups[$target]['items'][]=$item;$previous=['day'=>$day,'taken'=>$taken,'lat'=>$lat,'lon'=>$lon]; }
  $selected=[]; foreach($groups as $group) foreach($group['items'] as $item) $selected[]=$item;
  $cacheRead=$db->prepare('SELECT place_name FROM geocode_cache WHERE latitude_key=? AND longitude_key=?'); $cacheWrite=$db->prepare('INSERT INTO geocode_cache(latitude_key,longitude_key,place_name) VALUES(?,?,?) ON DUPLICATE KEY UPDATE place_name=VALUES(place_name)');
  foreach($groups as &$group) {
    if($group['latitude']===null||$group['longitude']===null)continue;
    $latKey=number_format($group['latitude'],2,'.','');$lonKey=number_format($group['longitude'],2,'.','');
    $cacheRead->execute([$latKey,$lonKey]);$oldName=$cacheRead->fetchColumn();
    $fallback=substr((string)$group['starts_at'],0,10);
    $place=station_reverse_lookup((float)$group['latitude'],(float)$group['longitude'],$config);
    $name=$place!==null?station_import_name($place,$fallback):(is_string($oldName)&&is_latin_station_name($oldName)?$oldName:$fallback);
    if($name!==''){$group['name']=$name;$cacheWrite->execute([$latKey,$lonKey,$name]);}
  }
  unset($group);
  $photoDates=array_values(array_filter(array_map(static function(array $item): ?string { $taken=media_capture_date($item); return is_string($taken)&&preg_match('/^\d{4}-\d{2}-\d{2}/',$taken)?substr($taken,0,10):null; },$selected)));
  sort($photoDates); $startsOn=$photoDates[0]??null; $endsOn=$photoDates ? $photoDates[count($photoDates)-1] : null;
  try {
    foreach($groups as $group)foreach($group['items'] as $item){$item['_station_name']=$group['name'];$normalized[]=$item;}
    $record=source_trip($db,$userId,$title,'onedrive',$folder,$existingTrip);
    $added=source_append($db,$record['id'],'onedrive',$normalized??[]);
    json(['added_media'=>$added,'trip'=>source_trip_result($db,$record['id'])],201);
  }catch(Throwable $error){json(['error'=>'Import konnte nicht gespeichert werden.'],500);}
}
if ($action==='trips' && $_SERVER['REQUEST_METHOD']==='POST') {
  $userId=require_admin($db);
  $title=trim((string)($input['title']??'')); if($title==='')json(['error'=>'Titel fehlt'],422);
  $id=uuid(); $q=$db->prepare('INSERT INTO trips(id,user_id,title,source_folder,media_count,cover_url) VALUES(?,?,?,?,?,?)');$q->execute([$id,$userId,$title,substr((string)($input['source_folder']??''),0,1024),(int)($input['media_count']??0),$input['cover_url']??null]);json(['trip'=>['id'=>$id]],201);
}
if ($action==='trip' && $_SERVER['REQUEST_METHOD']==='DELETE') {
  $userId=require_trip_editor($db,(string)($input['id']??''));
  $id=(string)($input['id']??''); if(!$id)json(['error'=>'Ungültige Daten'],422);
  $owned=$db->prepare('SELECT id FROM trips WHERE id=?');$owned->execute([$id]);
  if(!$owned->fetchColumn())json(['error'=>'Reisedoku nicht gefunden'],404);
  $cachedMedia=$db->prepare('SELECT id FROM media_items WHERE trip_id=?');$cachedMedia->execute([$id]);$cachedIds=$cachedMedia->fetchAll(PDO::FETCH_COLUMN);
  $storedMedia=$db->prepare('SELECT storage_path FROM media_items WHERE trip_id=? AND storage_path IS NOT NULL');$storedMedia->execute([$id]);$storedPaths=$storedMedia->fetchAll(PDO::FETCH_COLUMN);
  try {
    $db->beginTransaction();
    $q=$db->prepare('DELETE FROM media_items WHERE trip_id=?');$q->execute([$id]);
    $q=$db->prepare('DELETE FROM trip_stops WHERE trip_id=?');$q->execute([$id]);
    $q=$db->prepare('DELETE FROM trip_day_steps WHERE trip_id=?');$q->execute([$id]);
    $q=$db->prepare('DELETE FROM trips WHERE id=?');$q->execute([$id]);
    if($q->rowCount()!==1)throw new RuntimeException('Reisedoku konnte nicht gelöscht werden.');
    $db->commit();
  } catch(Throwable $e) {
    if($db->inTransaction())$db->rollBack(); json(['error'=>'Reisedoku konnte nicht gelöscht werden.'],500);
  }
  foreach($cachedIds as $mediaId)remove_cached_thumbnails((string)$mediaId);
  remove_source_files($db,$storedPaths);
  json(['ok'=>true]);
}
if ($action==='trip-intro' && $_SERVER['REQUEST_METHOD']==='PATCH') {
  require_trip_editor($db,(string)($input['id']??'')); $id=(string)($input['id']??'');$intro=trim((string)($input['intro']??''));if(!$id||!$intro)json(['error'=>'Ungültige Daten'],422);$q=$db->prepare('UPDATE trips SET intro=? WHERE id=?');$q->execute([$intro,$id]);json(['ok'=>true]);
}
if ($action==='trip-participants' && $_SERVER['REQUEST_METHOD']==='PATCH') {
  require_trip_editor($db,(string)($input['id']??'')); $id=(string)($input['id']??'');$participants=trim((string)($input['participants']??''));
  if(!$id)json(['error'=>'Ungültige Daten'],422);
  $q=$db->prepare('UPDATE trips SET participants=? WHERE id=?');$q->execute([$participants?:null,$id]);
  if($q->rowCount()===0){$exists=$db->prepare('SELECT id FROM trips WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())json(['error'=>'Reise nicht gefunden'],404);}
  json(['ok'=>true]);
}
if ($action==='trip-main-location' && $_SERVER['REQUEST_METHOD']==='PATCH') {
  require_trip_editor($db,(string)($input['id']??'')); $id=(string)($input['id']??'');$location=trim((string)($input['main_location']??''));
  if(!$id||mb_strlen($location)>255)json(['error'=>'Ungültige Daten'],422);
  $q=$db->prepare('UPDATE trips SET main_location=? WHERE id=?');$q->execute([$location?:null,$id]);
  if($q->rowCount()===0){$exists=$db->prepare('SELECT id FROM trips WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())json(['error'=>'Reise nicht gefunden'],404);}
  json(['ok'=>true]);
}
if ($action==='trip-main-location-geocode' && $_SERVER['REQUEST_METHOD']==='POST') {
  require_trip_editor($db,(string)($input['id']??'')); $id=(string)($input['id']??'');$location=trim((string)($input['main_location']??''));
  if(!$id||$location===''||mb_strlen($location)>255)json(['error'=>'Ungültige Ortsangabe'],422);
  $url='https://nominatim.openstreetmap.org/search?'.http_build_query(['q'=>$location,'format'=>'jsonv2','limit'=>1,'accept-language'=>'de,en','namedetails'=>1], '', '&', PHP_QUERY_RFC3986);
  $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: Family-on-the-road/1.0 ('.$config['app_url'].')']]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  $result=json_decode((string)$raw,true);$place=$result[0]??null;$latitude=isset($place['lat'])?(float)$place['lat']:null;$longitude=isset($place['lon'])?(float)$place['lon']:null;
  if($status!==200||!is_array($place)||$latitude===null||$longitude===null||$latitude < -90||$latitude > 90||$longitude < -180||$longitude > 180)json(['error'=>'Der Ort konnte nicht eindeutig gefunden werden.'],404);
  $location=latin_place_name($place,$location,'Hauptort');
  $q=$db->prepare('UPDATE trips SET main_location=?,main_latitude=?,main_longitude=? WHERE id=?');$q->execute([$location,$latitude,$longitude,$id]);
  if($q->rowCount()===0){$exists=$db->prepare('SELECT id FROM trips WHERE id=?');$exists->execute([$id]);if(!$exists->fetchColumn())json(['error'=>'Reise nicht gefunden'],404);}
  $mainName='Hauptort: '.$location;
  $existing=$db->prepare("SELECT id FROM trip_stops WHERE trip_id=? AND name LIKE 'Hauptort%' ORDER BY position LIMIT 1");$existing->execute([$id]);$stopId=(string)$existing->fetchColumn();
  if($stopId!==''){
    $q=$db->prepare('UPDATE trip_stops SET name=?,latitude=?,longitude=? WHERE id=? AND trip_id=?');$q->execute([$mainName,$latitude,$longitude,$stopId,$id]);
  }else{
    $position=$db->prepare('SELECT COALESCE(MAX(position)+1,0) FROM trip_stops WHERE trip_id=?');$position->execute([$id]);$next=(int)$position->fetchColumn();
    $firstMedia=$db->prepare('SELECT MIN(taken_at) FROM media_items WHERE trip_id=?');$firstMedia->execute([$id]);$firstAt=$firstMedia->fetchColumn() ?: null;
    $stopId=uuid();$q=$db->prepare('INSERT INTO trip_stops(id,trip_id,position,name,latitude,longitude,starts_at,ends_at) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$stopId,$id,$next,$mainName,$latitude,$longitude,$firstAt,$firstAt]);
  }
  json(['ok'=>true,'stop_id'=>$stopId,'latitude'=>$latitude,'longitude'=>$longitude,'display_name'=>$location]);
}
if ($action==='trip' && $_SERVER['REQUEST_METHOD']==='PATCH') {
  $userId=require_trip_editor($db,(string)($input['id']??''));
  $id=(string)($input['id']??'');$title=trim((string)($input['title']??''));if(!$id||!$title)json(['error'=>'Ungültige Daten'],422);$q=$db->prepare('UPDATE trips SET title=? WHERE id=?');$q->execute([$title,$id]);json(['ok'=>true]);
}
if ($action==='stop' && $_SERVER['REQUEST_METHOD']==='PATCH') {
  $userId=require_trip_editor($db,(string)($input['trip']??''));
  $id=(string)($input['id']??''); $trip=(string)($input['trip']??''); $summary=trim((string)($input['summary']??''));
  if(!$id||!$trip||$summary==='')json(['error'=>'Ungültige Daten'],422);
  $q=$db->prepare('UPDATE trip_stops SET summary=? WHERE id=? AND trip_id=?');$q->execute([$summary,$id,$trip]);
  if($q->rowCount()===0){$exists=$db->prepare('SELECT id FROM trip_stops WHERE id=? AND trip_id=?');$exists->execute([$id,$trip]);if(!$exists->fetchColumn())json(['error'=>'Station nicht gefunden'],404);}
  json(['ok'=>true]);
}
if ($action==='stop-name-suggestions' && $_SERVER['REQUEST_METHOD']==='GET') {
  require_trip_editor($db,(string)($_GET['trip']??''));
  $trip=(string)($_GET['trip']??'');$id=(string)($_GET['stop']??'');
  $q=$db->prepare('SELECT name,latitude,longitude FROM trip_stops WHERE id=? AND trip_id=?');$q->execute([$id,$trip]);$stop=$q->fetch();
  if(!$stop)json(['error'=>'Station nicht gefunden'],404);
  if(str_starts_with((string)$stop['name'],'Hauptort'))json(['error'=>'Der Hauptort wird in den Reisedaten verwaltet.'],422);
  if($stop['latitude']===null||$stop['longitude']===null)json(['suggestions'=>[],'island'=>'','note'=>'Für diese Station sind keine Koordinaten vorhanden.']);
  $place=station_reverse_lookup((float)$stop['latitude'],(float)$stop['longitude'],$config);
  if($place===null)json(['suggestions'=>[],'island'=>'','note'=>'Aus den Koordinaten ließ sich kein eindeutiger Ort ableiten.']);
  $parts=station_place_parts($place);$island=$parts['island'];$locality=$parts['locality'];$suggestions=[];
  $add=static function(string $label,string $name) use(&$suggestions): void {if($name!==''&&is_latin_station_name($name)&&!in_array($name,array_column($suggestions,'name'),true))$suggestions[]=['label'=>$label,'name'=>mb_substr($name,0,255)];};
  if($locality!==''&&$island!==''&&mb_strtolower($locality)!==mb_strtolower($island)){
    $add('Ort und Insel',$locality.' · Insel '.$island);
    $add('An Land, bitte prüfen',$locality.' auf '.$island);
  } elseif($island!=='')$add('Insel','Insel '.$island);
  elseif($locality!=='')$add('Ort',$locality);
  if($island!==''){
    $add('Auf See, bitte prüfen','Auf See nahe der Insel '.$island);
    $add('Segeln, bitte prüfen','Segeln nahe der Insel '.$island);
  }
  $feature=trim((string)($place['name']??''));
  if($feature!==''&&is_latin_station_name($feature)&&mb_strtolower($feature)!==mb_strtolower((string)$stop['name']))$add('Karteneintrag, bitte prüfen',$feature);
  json(['suggestions'=>$suggestions,'island'=>$island,'note'=>$suggestions?'Kartendaten sind ein Anhaltspunkt. Bitte Ort und Aktivität vor dem Speichern prüfen.':'Kein passender Ortsvorschlag gefunden. Trage einen Inselnamen ein, falls du ihn kennst.']);
}
if ($action==='stop-name' && $_SERVER['REQUEST_METHOD']==='PATCH') {
  require_trip_editor($db,(string)($input['trip']??''));
  $id=(string)($input['id']??'');$trip=(string)($input['trip']??'');$name=trim((string)($input['name']??''));
  if($id===''||$trip===''||$name===''||mb_strlen($name)>255||!is_latin_station_name($name)||str_starts_with($name,'Hauptort'))json(['error'=>'Bitte einen anderen Stationsnamen mit höchstens 255 lateinischen Zeichen eingeben.'],422);
  $q=$db->prepare('SELECT name FROM trip_stops WHERE id=? AND trip_id=?');$q->execute([$id,$trip]);$oldName=$q->fetchColumn();
  if($oldName===false)json(['error'=>'Station nicht gefunden'],404);
  if(str_starts_with((string)$oldName,'Hauptort'))json(['error'=>'Der Hauptort wird in den Reisedaten verwaltet.'],422);
  $q=$db->prepare('UPDATE trip_stops SET name=? WHERE id=? AND trip_id=?');$q->execute([$name,$id,$trip]);
  json(['ok'=>true,'name'=>$name]);
}
if ($action==='stop-location' && $_SERVER['REQUEST_METHOD']==='PATCH') {
  require_trip_editor($db,(string)($input['trip']??'')); $id=(string)($input['id']??'');$trip=(string)($input['trip']??'');$name=trim((string)($input['name']??''));$latitude=is_numeric($input['latitude']??null)?(float)$input['latitude']:null;$longitude=is_numeric($input['longitude']??null)?(float)$input['longitude']:null;
  if(!$id||!$trip||$name===''||$latitude===null||$longitude===null||!is_latin_station_name($name))json(['error'=>'Bitte einen Ortsnamen in lateinischer Schrift verwenden.'],422);
  if(($input['confirmed']??false)!==true)json(['error'=>'Position change requires explicit confirmation.'],409);
  if($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180)json(['error'=>'Coordinates are outside the valid map range.'],422);
  $q=$db->prepare('UPDATE trip_stops SET name=?,latitude=?,longitude=? WHERE id=? AND trip_id=?');$q->execute([substr($name,0,255),$latitude,$longitude,$id,$trip]);
  if($q->rowCount()===0){$exists=$db->prepare('SELECT id FROM trip_stops WHERE id=? AND trip_id=?');$exists->execute([$id,$trip]);if(!$exists->fetchColumn())json(['error'=>'Station nicht gefunden'],404);}
  json(['ok'=>true]);
}
if ($action==='restore-trip-locations-disabled' && $_SERVER['REQUEST_METHOD']==='POST') {
  require_admin($db); $trip=(string)($input['trip']??''); if($trip==='')json(['error'=>'Reise fehlt'],422);
  $stations=$db->prepare('SELECT id FROM trip_stops WHERE trip_id=?');$stations->execute([$trip]);$ids=$stations->fetchAll(PDO::FETCH_COLUMN);
  if(!$ids)json(['error'=>'Reise nicht gefunden'],404);
  $original=$db->prepare('SELECT latitude,longitude FROM media_items WHERE trip_id=? AND stop_id=? AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY taken_at,id LIMIT 1');
  $restore=$db->prepare('UPDATE trip_stops SET latitude=?,longitude=? WHERE id=? AND trip_id=?');$count=0;
  foreach($ids as $id){$original->execute([$trip,$id]);$point=$original->fetch();if(!$point)continue;$restore->execute([$point['latitude'],$point['longitude'],$id,$trip]);$count++;}
  json(['ok'=>true,'restored'=>$count]);
}
if ($action==='station' && $_SERVER['REQUEST_METHOD']==='POST') {
  $userId=require_trip_editor($db,(string)($input['trip']??''));
  $trip=(string)($input['trip']??''); $name=trim((string)($input['name']??'')); $starts=trim((string)($input['starts_at']??'')); $ends=trim((string)($input['ends_at']??''));
  $latitude=is_numeric($input['latitude']??null)?(float)$input['latitude']:null; $longitude=is_numeric($input['longitude']??null)?(float)$input['longitude']:null;
  if($trip===''||$name===''||$starts===''||$ends===''||$latitude===null||$longitude===null||!is_latin_station_name($name)) json(['error'=>'Bitte einen Stationsnamen in lateinischer Schrift verwenden.'],422);
  $owner=$db->prepare('SELECT id FROM trips WHERE id=?'); $owner->execute([$trip]); if(!$owner->fetchColumn()) json(['error'=>'Reisedoku nicht gefunden.'],404);
  $existing=$db->prepare('SELECT id FROM trip_stops WHERE trip_id=? AND name=? AND starts_at=?'); $existing->execute([$trip,$name,$starts]); if($existing->fetchColumn()) json(['error'=>'Diese Station ist bereits vorhanden.'],409);
  $id=uuid(); $db->beginTransaction();
  try {
    $position=$db->prepare('SELECT COALESCE(MAX(position)+1,0) FROM trip_stops WHERE trip_id=?'); $position->execute([$trip]); $next=(int)$position->fetchColumn();
    $insert=$db->prepare('INSERT INTO trip_stops(id,trip_id,position,name,latitude,longitude,starts_at,ends_at) VALUES(?,?,?,?,?,?,?,?)'); $insert->execute([$id,$trip,$next,substr($name,0,255),$latitude,$longitude,$starts,$ends]);
    $move=$db->prepare('UPDATE media_items SET stop_id=? WHERE trip_id=? AND taken_at>=? AND taken_at<?'); $move->execute([$id,$trip,$starts,$ends]);
    $db->commit(); json(['station'=>['id'=>$id,'name'=>$name,'media_count'=>$move->rowCount()]],201);
  } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); json(['error'=>'Station konnte nicht ergänzt werden.'],500); }
}
if ($action==='stops' && $_SERVER['REQUEST_METHOD']==='GET') {
  $trip=(string)($_GET['trip']??'');require_trip_access($db,(int)($_SESSION['user_id']??0),$trip);$q=$db->prepare("SELECT s.*,(SELECT m.id FROM media_items m WHERE m.stop_id=s.id AND m.media_type IN ('photo','video') ORDER BY (m.media_type='photo') DESC,m.taken_at,m.id LIMIT 1) AS preview_id FROM trip_stops s WHERE s.trip_id=? ORDER BY position");$q->execute([$trip]);json(['stops'=>$q->fetchAll()]);
}
if ($action==='media' && $_SERVER['REQUEST_METHOD']==='GET') {
  $userId=require_user(); $trip=(string)($_GET['trip']??'');require_trip_access($db,$userId,$trip);$stop=(string)($_GET['stop']??'');
  $main=$db->prepare("SELECT id FROM trip_stops WHERE id=? AND trip_id=? AND name LIKE 'Hauptort%'");$main->execute([$stop,$trip]);$isMain=(bool)$main->fetchColumn();
  $q=$isMain?$db->prepare('SELECT m.id,m.name,m.media_type,m.taken_at,m.capture_source,m.web_url FROM media_items m WHERE m.trip_id=? ORDER BY m.taken_at,m.name'):$db->prepare('SELECT m.id,m.name,m.media_type,m.taken_at,m.capture_source,m.web_url FROM media_items m WHERE m.trip_id=? AND m.stop_id=? ORDER BY m.taken_at,m.name');
  $q->execute($isMain?[$trip]:[$trip,$stop]);json(['media'=>$q->fetchAll()]);
}
function stream_graph_video(string $url, string $token, string $range): never {
  if($range!==''&&!preg_match('/^bytes=(?:\d+-\d*|-\d+)$/D',$range)){http_response_code(416);exit;}
  $response=['status'=>0,'type'=>'video/mp4','length'=>null,'range'=>null];
  $headers=$token!==''?['Authorization: Bearer '.$token]:[];
  if($range!=='')$headers[]='Range: '.$range;
  session_write_close();
  $sent=false;
  $ch=curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_HTTPHEADER=>$headers,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_TIMEOUT=>120,
    CURLOPT_BUFFERSIZE=>65536,
    CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$response): int {
      $trimmed=trim($line);
      if(preg_match('~^HTTP/\S+\s+(\d{3})~i',$trimmed,$match)){
        $response=['status'=>(int)$match[1],'type'=>'video/mp4','length'=>null,'range'=>null];
      }elseif(stripos($trimmed,'Content-Type:')===0){$response['type']=trim(substr($trimmed,13));}
      elseif(stripos($trimmed,'Content-Length:')===0){$response['length']=trim(substr($trimmed,15));}
      elseif(stripos($trimmed,'Content-Range:')===0){$response['range']=trim(substr($trimmed,14));}
      return strlen($line);
    },
    CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$response,&$sent): int {
      if(!in_array($response['status'],[200,206],true))return strlen($chunk);
      if($response['status']===206&&$response['range']===null)return 0;
      if(!$sent){
        http_response_code($response['status']);
        header('Content-Type: '.$response['type']);
        header('Cache-Control: private, no-store');
        header('Accept-Ranges: bytes');
        if($response['length']!==null)header('Content-Length: '.$response['length']);
        if($response['range']!==null)header('Content-Range: '.$response['range']);
        $sent=true;
      }
      echo $chunk;
      if(ob_get_level()>0)ob_flush();
      flush();
      return strlen($chunk);
    }
  ]);
  $ok=curl_exec($ch);
  curl_close($ch);
  if(!$sent){http_response_code($ok===false||!in_array($response['status'],[200,206],true)?502:$response['status']);}
  exit;
}
if ($action==='media-stream' && $_SERVER['REQUEST_METHOD']==='GET') {
  $trip=(string)($_GET['trip']??'');$media=(string)($_GET['media']??'');require_trip_access($db,(int)($_SESSION['user_id']??0),$trip);
  $q=$db->prepare('SELECT m.onedrive_item_id,m.source_provider,m.source_item_id,m.storage_path,m.local_original_path,m.local_original_root,m.mime_type,m.media_type,m.name,t.user_id FROM media_items m JOIN trips t ON t.id=m.trip_id WHERE m.id=? AND m.trip_id=?');$q->execute([$media,$trip]);$item=$q->fetch();if(!$item){http_response_code(404);exit;}
  if($item['source_provider']==='local'){
    try{
      if(!empty($item['local_original_path'])){
        if($item['media_type']==='photo'&&empty($_GET['original']))source_preview($item,$config,$db,'fullscreen');
        $path=source_local_file($item);
        if(!empty($_GET['original']))header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode((string)$item['name']));
        source_stream_file($path,(string)$item['mime_type']);
      }
      json(['error'=>'Bitte lokale Medien erneut als Originalreferenzen importieren.'],409);
    }catch(Throwable $error){json(['error'=>$error->getMessage()],in_array($error->getCode(),[404,409],true)?$error->getCode():500);}
  }
  if($item['source_provider']==='dropbox'){
    $link=dropbox_request('files/get_temporary_link',dropbox_token($db,$config,(int)$item['user_id']),['path'=>$item['source_item_id']]);
    $url=(string)($link['link']??'');$host=(string)parse_url($url,PHP_URL_HOST);
    if(!str_starts_with($url,'https://')||!str_ends_with($host,'.dropboxusercontent.com'))json(['error'=>'Ungültige Dropbox-Medienadresse.'],502);
    if(preg_match('/\.(heic|heif)$/i',(string)$item['name'])&&empty($_GET['original']))source_preview($item,$config,$db,'fullscreen');
    stream_graph_video($url,'',trim((string)($_SERVER['HTTP_RANGE']??'')));
  }
  $token=graph_access_token($db,$config,(int)$item['user_id']);if(!$token){http_response_code(409);exit;}$range=trim((string)($_SERVER['HTTP_RANGE']??''));
  if($item['media_type']==='video')stream_graph_video(onedrive_item_url((string)$item['onedrive_item_id']).'/content',$token,$range);
  $headers=['Authorization: Bearer '.$token];if($range!=='')$headers[]='Range: '.$range;
  $ch=curl_init(onedrive_item_url((string)$item['onedrive_item_id']).'/content');curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>120]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);if($body===false||!in_array($status,[200,206],true)){http_response_code(404);exit;}
  $heic=preg_match('/\.(heic|heif)$/i',(string)$item['name'])===1;if($heic){if(!class_exists('Imagick')){http_response_code(415);exit;}try{$image=new Imagick();$image->readImageBlob($body);$image->setImageFormat('jpeg');$image->setImageCompressionQuality(92);$image->stripImage();$body=$image->getImagesBlob();$image->clear();$image->destroy();$type='image/jpeg';$status=200;}catch(Throwable $e){http_response_code(415);exit;}}
  if($status===206)http_response_code(206);header('Content-Type: '.($type?:($item['media_type']==='video'?'video/mp4':'image/jpeg')));header('Cache-Control: private, no-store');header('Accept-Ranges: bytes');echo $body;exit;
}
if ($action==='media-capabilities' && $_SERVER['REQUEST_METHOD']==='GET') {
  require_admin($db);$imagick=class_exists('Imagick');$formats=[];
  if($imagick){try{$formats=array_map('strtoupper',Imagick::queryFormats());}catch(Throwable $e){$formats=[];}}
  json(['imagick'=>$imagick,'heic'=>$imagick&&in_array('HEIC',$formats,true),'heif'=>$imagick&&in_array('HEIF',$formats,true)]);
}
json(['error'=>'Nicht gefunden'],404);
