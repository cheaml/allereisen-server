<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/device-sync-data.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$action=(string)($_GET['action']??'');
// Separate device credentials: never export Microsoft refresh/access tokens.
if($action==='return') {
    require_device_sync_user($db);
    $completed=$_SESSION['device_sync_completion']??null;
    if(!$completed||$completed['expires']<time())json(['error'=>'Der Rücksprung ist abgelaufen. Bitte das Anmeldefenster schließen und in der App erneut verbinden.'],410);
    $url=($completed['redirect']??'reiseapp://auth').'?'.http_build_query(['code'=>$completed['code'],'state'=>$completed['state']]);
    $safeUrl=htmlspecialchars($url,ENT_QUOTES,'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Zur App zurückkehren</title><body style="font:18px system-ui;max-width:32rem;margin:3rem auto;padding:1rem;background:#f4f0e8;color:#26473e"><h1>Freigabe angenommen</h1><p>Die Website hat deine Gerätefreigabe angenommen. Die App muss die Verbindung jetzt noch abschließen.</p><p id="status" role="status">Du wirst zur App zurückgeleitet …</p><p><a id="returnToApp" href="'.$safeUrl.'" style="display:inline-block;background:#26473e;color:white;padding:1rem 1.5rem;border-radius:1rem;text-decoration:none">Zur App zurückkehren</a></p><p>Wenn dieses Fenster geöffnet bleibt, tippe auf „Zur App zurückkehren“. Schließe es nicht vorher. Der Verbindungscode gilt zwei Minuten.</p><script src="./device-sync-ui.js?v=20261013-return"></script></body></html>';exit;
}
// Connect a device with a different account than the one signed in on this browser:
// end only the website session, keep the pending device request, show Microsoft's account picker.
if($action==='switch-account') {
    $pending=$_SESSION['device_sync_pending']??null;
    if(!$pending||$pending['expires']<time())json(['error'=>'Geräteanmeldung abgelaufen. Bitte in der App erneut starten.'],410);
    session_regenerate_id(true);
    // The person deliberately picks another account: drop the app's account suggestion.
    $pending['login_hint']='';
    $_SESSION=['device_sync_pending'=>$pending];
    header('Location: login.php?device_sync=1&select=1',true,303);exit;
}
// One-time link from the app (action web-link): signs this browser in as the app's account and
// opens one fixed server page. Single use, valid two minutes, never carries Microsoft tokens.
if($action==='web-login') {
    $code=(string)($_GET['code']??'');
    $fail=static function(string $message,int $status):never{http_response_code($status);header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="de"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AlleReisen</title><body style="font:18px system-ui;max-width:32rem;margin:3rem auto;padding:1rem;background:#f8eddc;color:#2b241f"><h1>Link nicht mehr gültig</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p></body></html>';exit;};
    if(!preg_match('/^[a-f0-9]{64}$/D',$code))$fail('Bitte die Seite in der App erneut öffnen.',400);
    $q=$db->prepare("SELECT user_id,challenge FROM device_sync_codes WHERE code_hash=? AND expires_at>NOW() AND challenge LIKE 'web:%'");$q->execute([hash('sha256',$code)]);$row=$q->fetch();
    // Single use: only the request that actually deletes the row may sign in.
    if($row){$q=$db->prepare('DELETE FROM device_sync_codes WHERE code_hash=?');$q->execute([hash('sha256',$code)]);if($q->rowCount()!==1)$row=false;}
    $target=$row?(DEVICE_SYNC_WEB_TARGETS[substr((string)$row['challenge'],4)]??null):null;
    if(!$row||$target===null)$fail('Der Link gilt nur einmal und zwei Minuten. Bitte die Seite in der App erneut öffnen.',410);
    if(!device_sync_user_allowed($db,(int)$row['user_id']))$fail('Dieses Konto benötigt eine Microsoft-Anmeldung.',403);
    complete_login((int)$row['user_id']);
    header('Location: '.rtrim((string)$config['app_url'],'/').'/'.$target,true,303);exit;
}
if($action==='authorize') {
    if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['challenge'])) {
        $challenge=(string)$_GET['challenge'];$state=(string)($_GET['state']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$challenge)||!preg_match('/^[A-Za-z0-9_-]{32,128}$/D',$state))json(['error'=>'Ungültige Geräteanmeldung.'],422);
        $redirect=(string)($_GET['redirect']??'reiseapp://auth');
        if(!device_sync_redirect_valid($redirect))json(['error'=>'Ungültige Rücksprungadresse.'],422);
        // The app passes the Microsoft account it uses for OneDrive, so both sign-ins use the same account.
        $hint=strtolower(trim((string)($_GET['login_hint']??'')));
        if($hint!==''&&(strlen($hint)>254||!filter_var($hint,FILTER_VALIDATE_EMAIL)))$hint='';
        $_SESSION['device_sync_pending']=['challenge'=>$challenge,'state'=>$state,'redirect'=>$redirect,'expires'=>time()+600,'csrf'=>bin2hex(random_bytes(32)),'login_hint'=>$hint];
    }
    $pending=$_SESSION['device_sync_pending']??null;
    if(!$pending||$pending['expires']<time())json(['error'=>'Geräteanmeldung abgelaufen. Bitte in der App erneut starten.'],410);
    if(empty($_SESSION['user_id'])) {header('Location: login.php?device_sync=1',true,303);exit;}
    // Browser still signed in with another account than the app's OneDrive account: sign in again
    // with the app's account (once; if Microsoft returns another account, the page below shows it).
    $hint=(string)($pending['login_hint']??'');
    if($hint!==''&&empty($pending['hint_checked'])){
        $q=$db->prepare('SELECT email FROM users WHERE id=?');$q->execute([(int)$_SESSION['user_id']]);
        $pending['hint_checked']=true;$_SESSION['device_sync_pending']=$pending;
        if(strcasecmp((string)$q->fetchColumn(),$hint)!==0){
            session_regenerate_id(true);$_SESSION=['device_sync_pending'=>$pending];
            header('Location: login.php?device_sync=1',true,303);exit;
        }
    }
    $owner=require_device_sync_user($db);
    device_sync_schema($db);
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!hash_equals($pending['csrf'],(string)($_POST['csrf']??'')))json(['error'=>'Bitte erneut verbinden.'],403);
        unset($_SESSION['device_sync_pending']);
        $code=bin2hex(random_bytes(32));
        $q=$db->prepare('INSERT INTO device_sync_codes(code_hash,user_id,challenge,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 2 MINUTE))');
        $q->execute([hash('sha256',$code),$owner,$pending['challenge']]);
        // Safari authentication sessions can leave a custom-scheme redirect
        // from a POST without visible feedback. Return through a normal GET
        // with a real user-activated link as a fallback.
        $_SESSION['device_sync_completion']=['code'=>$code,'state'=>$pending['state'],'redirect'=>$pending['redirect']??'reiseapp://auth','expires'=>time()+120];
        header('Location: device-sync.php?action=return',true,303);exit;
    }
    $q=$db->prepare('SELECT display_name,email FROM users WHERE id=?');$q->execute([$owner]);$who=$q->fetch(PDO::FETCH_ASSOC)?:[];
    $account=htmlspecialchars(implode(' · ',array_filter([(string)($who['display_name']??''),(string)($who['email']??'')])),ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Auf Reisen verbinden</title><body style="font:18px system-ui;max-width:32rem;margin:3rem auto;padding:1rem"><h1>Dieses Gerät mit deinem Reisearchiv verbinden</h1><p style="padding:.8rem 1rem;background:#eef3ee;border-radius:.8rem">Angemeldet als <strong>'.$account.'</strong><br><a href="device-sync.php?action=switch-account">Mit einem anderen Konto anmelden</a></p><p>Die App darf die für dich berechtigten Serverreisen ansehen. Mit Importrecht darf sie zusätzlich deine eigenen OneDrive-Reisen übernehmen, neue Reisen speichern und bestätigte Änderungen deiner eigenen Reisen übertragen. Vor Aktualisierungen wird die bisherige Webreise gesichert; neuere Webänderungen werden nicht ungefragt überschrieben. Lokale und iCloud-Reisen werden nicht übertragen. Die Originaldateien bleiben in OneDrive.</p><form method="post" id="deviceConnectForm"><input type="hidden" name="csrf" value="'.htmlspecialchars($pending['csrf'],ENT_QUOTES).'"><button style="font:inherit;padding:1rem;background:#26473e;color:white;border:0;border-radius:1rem">Dieses Gerät verbinden</button><p id="connectStatus" role="status"></p></form><p>Schließe das Fenster, um abzubrechen. Du kannst die Verbindung in der App wieder trennen.</p><script src="./device-sync-ui.js?v=20261013-return"></script></body></html>';exit;
}
$input=json_decode(file_get_contents('php://input'),true)??[];
if($action==='token'&&$_SERVER['REQUEST_METHOD']==='POST') {
    $code=(string)($input['code']??'');$verifier=(string)($input['verifier']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$code)||!preg_match('/^[A-Za-z0-9_-]{43,128}$/D',$verifier))json(['error'=>'Ungültiger Verbindungscode.'],401);
    $db->beginTransaction();
    $q=$db->prepare('SELECT * FROM device_sync_codes WHERE code_hash=? AND expires_at>NOW() FOR UPDATE');$q->execute([hash('sha256',$code)]);$row=$q->fetch();
    $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    if(!$row||!hash_equals($row['challenge'],$challenge)){$db->rollBack();json(['error'=>'Verbindungscode abgelaufen oder ungültig.'],401);}
    if(!device_sync_user_allowed($db,(int)$row['user_id'])){$db->rollBack();json(['error'=>'Microsoft-Konto nicht mehr berechtigt.'],403);}
    $q=$db->prepare('DELETE FROM device_sync_codes WHERE code_hash=?');$q->execute([hash('sha256',$code)]);
    $token=bin2hex(random_bytes(32));$q=$db->prepare('INSERT INTO device_sync_devices(token_hash,user_id,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))');$q->execute([hash('sha256',$token),$row['user_id']]);$db->commit();
    $q=$db->prepare('SELECT display_name AS displayName,email FROM users WHERE id=?');$q->execute([$row['user_id']]);
    json(['token'=>$token,'expiresAt'=>gmdate('c',time()+30*86400),'user'=>$q->fetch(PDO::FETCH_ASSOC)]);
}
$header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$match))json(['error'=>'Bitte Website in der App verbinden.'],401);
$q=$db->prepare("SELECT d.user_id FROM device_sync_devices d JOIN users u ON u.id=d.user_id WHERE d.token_hash=? AND d.expires_at>NOW() AND u.microsoft_id IS NOT NULL AND u.microsoft_id<>''");$q->execute([hash('sha256',$match[1])]);$owner=(int)$q->fetchColumn();
if(!$owner)json(['error'=>'Website-Verbindung abgelaufen. Bitte erneut verbinden.'],401);
session_write_close();
// Bearer authority is request-local and never upgrades a browser cookie.
$_SESSION=['user_id'=>$owner,'auth_method'=>'microsoft'];
// One-time browser link for a server page (see web-login above).
if($action==='web-link'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $target=(string)($input['target']??'');
    if(!isset(DEVICE_SYNC_WEB_TARGETS[$target])||!is_file(dirname(__DIR__).'/'.DEVICE_SYNC_WEB_TARGETS[$target]))json(['error'=>'Diese Seite gibt es auf deinem Server nicht.'],404);
    $code=bin2hex(random_bytes(32));
    $q=$db->prepare('INSERT INTO device_sync_codes(code_hash,user_id,challenge,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 2 MINUTE))');$q->execute([hash('sha256',$code),$owner,'web:'.$target]);
    json(['url'=>rtrim((string)$config['app_url'],'/').'/api/device-sync.php?action=web-login&code='.$code]);
}
// Deleting the own server account from an app (Apple requires in-app deletion).
if($action==='account-deletion'&&$_SERVER['REQUEST_METHOD']==='GET'){
    require_once __DIR__.'/account-delete.php';
    try{json(account_delete_summary($db,$config,(int)$owner));}catch(AccountDeleteError $e){json(['error'=>$e->getMessage()],$e->status);}
}
if($action==='account-delete'&&$_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/account-delete.php';
    try{json(account_delete($db,$config,(int)$owner,(string)($input['confirmEmail']??''),__DIR__.'/cache/thumbs'));}catch(AccountDeleteError $e){json(['error'=>$e->getMessage()],$e->status);}
}
if($action==='view-catalog'&&$_SERVER['REQUEST_METHOD']==='GET'){
    require_once __DIR__.'/trip-access.php';$views=[];
    foreach($db->query('SELECT id,user_id FROM trips ORDER BY updated_at DESC,id')->fetchAll() as $trip){
        if(!trip_can_read($db,$owner,(string)$trip['id']))continue;
        $view=device_sync_export($db,(int)$trip['user_id'],(string)$trip['id'],true);
        $view['trip']['source']=['provider'=>1,'accountId'=>'server-read','rootId'=>'server-view:'.$trip['id'],'label'=>'Serverreise'];
        $view['metadata'][$trip['id'].'/trip/'.$trip['id']]['read_only']=true;
        $views[]=$view;
    }
    $q=$db->prepare('SELECT display_name AS displayName,email FROM users WHERE id=?');$q->execute([$owner]);
    json(['protocol'=>1,'trips'=>$views,'user'=>$q->fetch(PDO::FETCH_ASSOC)]);
}
if($action==='view-media'&&in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){
    $_GET['action']=($_GET['preview']??'')==='1'?'preview':'media-stream';
    $_SERVER['REQUEST_METHOD']='GET';require __DIR__.'/api.php';exit;
}
if(in_array($action,['catalog','catalog-index','snapshot','preview','status','history','backups','publish','update','restore'],true)&&!device_sync_importer_allowed($db,$owner))json(['error'=>'Keine Importberechtigung. Berechtigte Reisen können weiterhin angesehen werden.'],403);
if($action==='family-circle') {
    json(['error'=>'Die zusätzliche OneDrive-Familienfreigabe wurde entfernt.'],410);
}
if($action==='capabilities'&&$_SERVER['REQUEST_METHOD']==='GET') {
    require_once __DIR__.'/device-sync-capabilities.php';json(device_sync_capabilities($db,$config,$owner));
}
// Deleting a trip on the server from the app (owners of the trip and admins, like on the website).
if($action==='trip-delete'&&$_SERVER['REQUEST_METHOD']==='DELETE') {
    $_SESSION=['user_id'=>$owner,'auth_method'=>'microsoft'];
    $_GET['action']='trip';
    require __DIR__.'/api.php';exit;
}
// Automation schedule from the app: only the owner of the automation (see travel-automation-setup.php).
if($action==='travel-automation'&&in_array($_SERVER['REQUEST_METHOD'],['GET','PATCH'],true)) {
    require_once __DIR__.'/travel-automation-settings.php';
    require_once __DIR__.'/travel-automation.php';
    try{$path=travel_settings_path(dirname(__DIR__),$config['travel_automation_settings_file']??null);$settings=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}
    catch(Throwable){json(['available'=>false]);}
    $q=$db->prepare('SELECT email,is_admin FROM users WHERE id=?');$q->execute([$owner]);$user=$q->fetch();
    if(!is_array($settings)||!$user||!(bool)$user['is_admin']||strcasecmp((string)$user['email'],(string)($settings['ownerEmail']??''))!==0)json(['available'=>false]);
    if($_SERVER['REQUEST_METHOD']==='PATCH'){
        // mode: off / night / frequent (0.3.4); frequentRuns (bool) from the first 0.3.4 test builds.
        try{
            if(isset($input['mode']))$changed=travel_apply_mode($settings,$input['mode']);
            elseif(is_bool($input['frequentRuns']??null))$changed=array_replace($settings,['frequentRuns'=>$input['frequentRuns']]);
            else json(['error'=>'Ungültige Einstellung.'],422);
        }catch(InvalidArgumentException){json(['error'=>'Ungültige Einstellung.'],422);}
        try{$settings=travel_settings($changed);travel_save($path,$settings);}
        catch(Throwable){json(['error'=>'Die Einstellung konnte nicht gespeichert werden.'],500);}
    }
    $last=travel_last_run(travel_private_directory($path,(string)$settings['ownerEmail']));
    [$reviewCount]=travel_review_cases(travel_private_directory($path,(string)$settings['ownerEmail']),0);
    json(['available'=>true,'mode'=>travel_mode($settings),'enabled'=>(bool)($settings['enabled']??false),'frequentRuns'=>(bool)($settings['frequentRuns']??false),
        'lastRun'=>$last===null?null:['at'=>gmdate(DATE_ATOM,$last['at']),'ok'=>$last['ok'],'connectionProblem'=>$last['connectionProblem'],'imported'=>$last['imported'],'review'=>$last['review']],'reviewCount'=>$reviewCount]);
}
// 0.4.1: trip recording from the iPhone app ("Reise aufzeichnen"): start/stop the recording period of the
// automation owner. Captures inside it form one trip (see travel_recordings in travel-automation.php).
if($action==='travel-recording'&&in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) {
    require_once __DIR__.'/travel-automation-settings.php';
    require_once __DIR__.'/travel-automation.php';
    try{$path=travel_settings_path(dirname(__DIR__),$config['travel_automation_settings_file']??null);$settings=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}
    catch(Throwable){json(['available'=>false]);}
    $q=$db->prepare('SELECT email,is_admin FROM users WHERE id=?');$q->execute([$owner]);$user=$q->fetch();
    if(!is_array($settings)||!$user||!(bool)$user['is_admin']||strcasecmp((string)$user['email'],(string)($settings['ownerEmail']??''))!==0)json(['available'=>false]);
    $private=travel_private_directory($path,(string)$settings['ownerEmail']);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        try{if(isset($input['participant']))travel_owner_participant_save($private,$input['participant']);
            $mode=(string)($input['mode']??'');$recordings=$mode==='participant'?travel_recordings($private):travel_recording_change($private,$mode,time(),(string)($settings['timezone']??'Europe/Vienna'));}
        catch(InvalidArgumentException){json(['error'=>'Ungültige Aufzeichnung.'],422);}
        catch(Throwable){json(['error'=>'Die Aufzeichnung konnte am Server nicht gespeichert werden.'],500);}
    }else $recordings=travel_recordings($private);
    $active=travel_recording_active($recordings);$latest=$active??(end($recordings)?:null);
    // 0.4.2: which trip the automation made from each recording (one recording, one trip), so the app links its own
    // recording to that trip instead of offering another one.
    $made=travel_recording_trips($private);
    $list=array_map(static fn($r)=>['id'=>$r['id'],'title'=>$r['title'],'from'=>$r['from'],'until'=>$r['until'],'tripId'=>$made[$r['id']]??null],array_slice($recordings,-20));
    json(['available'=>true,'active'=>$active!==null,'recording'=>$latest===null?null:['id'=>$latest['id'],'title'=>$latest['title'],'from'=>gmdate(DATE_ATOM,$latest['from']),'until'=>$latest['until']===null?null:gmdate(DATE_ATOM,$latest['until'])],'recordings'=>$list]);
}
if($action==='administration') {
    require_once __DIR__.'/device-sync-admin.php';
    $operation=(string)($_GET['operation']??'');
    // Guests of one trip are managed by its owner too; api.php checks require_trip_editor for these.
    $q=$db->prepare('SELECT is_admin FROM users WHERE id=?');$q->execute([$owner]);
    if(!(bool)$q->fetchColumn()&&!in_array($operation,['trip-guests','trip-invite','guest-emails'],true))json(['error'=>'Keine Administratorberechtigung.'],403);
    if(!device_sync_admin_operation($operation,$_SERVER['REQUEST_METHOD']))json(['error'=>'Verwaltungsaktion nicht erlaubt.'],405);
    // Request-local identity, not written into the browser session. The native
    // caller proves authority with an explicit Authorization header, not cookies.
    $_SESSION=['user_id'=>$owner,'auth_method'=>'microsoft'];
    foreach(['ADMIN'=>'admin-accounts','IMPORT'=>'import-permissions','REVIEW'=>'trip-review'] as $header=>$scope)
        $_SERVER['HTTP_X_REISE_'.$header.'_CSRF']=hash_hmac('sha256',$scope.'-v1:'.session_id().':'.$owner,(string)$config['encryption_key']);
    $_GET['action']=$operation;
    require __DIR__.'/api.php';exit;
}

if($action==='disconnect'&&$_SERVER['REQUEST_METHOD']==='POST'){$q=$db->prepare('DELETE FROM device_sync_devices WHERE token_hash=?');$q->execute([hash('sha256',$match[1])]);json(['ok'=>true]);}
if($action==='catalog-index'&&$_SERVER['REQUEST_METHOD']==='GET') {
    $db->beginTransaction();
    $filter=device_sync_has_column($db,'trips','source_provider')?" AND source_provider='onedrive'":'';
    $q=$db->prepare('SELECT id,title,UNIX_TIMESTAMP(updated_at) AS epoch FROM trips WHERE user_id=?'.$filter.' ORDER BY updated_at DESC,id');$q->execute([$owner]);
    $rows=[];foreach($q->fetchAll() as $row)$rows[]=['id'=>$row['id'],'title'=>$row['title'],'modifiedAt'=>$row['epoch']===null?null:gmdate('Y-m-d\TH:i:s\Z',(int)$row['epoch']),'revision'=>device_sync_export($db,$owner,$row['id'])['revision']];$db->commit();json(['trips'=>$rows]);
}
if($action==='catalog'&&$_SERVER['REQUEST_METHOD']==='GET') {
    $db->beginTransaction();
    $filter=device_sync_has_column($db,'trips','source_provider')?" AND source_provider='onedrive'":'';
    $q=$db->prepare('SELECT id FROM trips WHERE user_id=?'.$filter.' ORDER BY id');$q->execute([$owner]);
    $snapshots=array_map(static fn($id)=>device_sync_export($db,$owner,$id),$q->fetchAll(PDO::FETCH_COLUMN));$db->commit();
    json(['protocol'=>1,'trips'=>$snapshots]);
}
if($action==='preview'&&$_SERVER['REQUEST_METHOD']==='POST') {
    if(strlen(json_encode($input))>12*1024*1024)json(['error'=>'Reise zu groß.'],413);
    try{$input=device_sync_resolve_folder($db,$config,$owner,$input);json(device_sync_preview($db,$owner,$input));}
    catch(Throwable $error){$status=in_array($error->getCode(),[403,409,422],true)?$error->getCode():500;json(['error'=>$status===500?'Die Übertragung konnte nicht geprüft werden.':$error->getMessage()],$status);}
}
if($action==='snapshot'&&$_SERVER['REQUEST_METHOD']==='GET') {
    try {
        $id=device_sync_uuid((string)($_GET['trip']??''));
        $db->beginTransaction();$snapshot=device_sync_export($db,$owner,$id);$db->commit();json($snapshot);
    } catch(Throwable $error) {
        if($db->inTransaction())$db->rollBack();
        $status=in_array($error->getCode(),[403,404,422],true)?$error->getCode():500;
        json(['error'=>$status===500?'Die Webreise konnte nicht gelesen werden.':$error->getMessage()],$status);
    }
}
if($action==='trip-status'&&$_SERVER['REQUEST_METHOD']==='GET') {
    $id=device_sync_uuid((string)($_GET['trip']??''));$q=$db->prepare('SELECT user_id FROM trips WHERE id=?');$q->execute([$id]);$existing=$q->fetchColumn();
    if($existing===false)json(['exists'=>false,'revision'=>'']);
    if((int)$existing!==$owner)json(['error'=>'Reise nicht zugänglich.'],403);
    $snapshot=device_sync_export($db,$owner,$id);json(['exists'=>true,'revision'=>$snapshot['revision']]);
}
if($action==='history'&&$_SERVER['REQUEST_METHOD']==='GET') {
    require_once __DIR__.'/device-sync-history.php';
    try {json(device_sync_history($db,$owner,device_sync_uuid((string)($_GET['trip']??''))));}
    catch(Throwable $error){$status=in_array($error->getCode(),[403,404,422],true)?$error->getCode():500;json(['error'=>$status===500?'Der Änderungsverlauf konnte nicht gelesen werden.':$error->getMessage()],$status);}
}
if($action==='backups'&&$_SERVER['REQUEST_METHOD']==='GET') {
    device_sync_schema($db);$id=device_sync_uuid((string)($_GET['trip']??''));device_sync_export($db,$owner,$id);
    $q=$db->prepare('SELECT id,UNIX_TIMESTAMP(created_at) AS created_epoch,device_label,revision FROM device_sync_backups WHERE trip_id=? AND user_id=? ORDER BY created_at DESC,id DESC LIMIT 30');$q->execute([$id,$owner]);$backups=$q->fetchAll();foreach($backups as &$backup){$backup['created_at']=$backup['created_epoch']===null?null:gmdate('Y-m-d\TH:i:s\Z',(int)$backup['created_epoch']);unset($backup['created_epoch']);}unset($backup);json(['backups'=>$backups]);
}
if(in_array($action,['publish','update','restore'],true)&&$_SERVER['REQUEST_METHOD']==='POST') {
    if(strlen(json_encode($input))>12*1024*1024)json(['error'=>'Reise zu groß.'],413);
    try{
        device_sync_schema($db);
        if($action!=='restore')$input=device_sync_resolve_folder($db,$config,$owner,$input);
        json($action==='restore'?device_sync_restore($db,$owner,$input):device_sync_publish($db,$owner,$input,$action==='update'));
    }
    catch(Throwable $error){if($db->inTransaction())$db->rollBack();$status=in_array($error->getCode(),[403,409,422],true)?$error->getCode():500;json(['error'=>$status===500?'Die Reise konnte nicht übertragen werden.':$error->getMessage()],$status);}
}
json(['error'=>'Nicht gefunden.'],404);
