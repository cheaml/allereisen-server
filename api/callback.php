<?php
require __DIR__ . '/bootstrap.php';
// OneDrive uses the same registered callback while retaining its own state checks.
if (str_starts_with((string)($_GET['state']??''),'alr-onedrive-')) {
    require __DIR__.'/onedrive.php';
    exit;
}
// Separate automation consent, using the already registered callback URL.
if (str_starts_with((string)($_GET['state']??''),'alr-auto-')) {
    if(!is_file(__DIR__.'/automation-oauth.php'))json(['error'=>'Reiseautomatik ist auf diesem Server nicht eingerichtet.'],404);
    require __DIR__.'/automation-oauth.php';
    automation_oauth_finish($db,$config);
}
if (empty($_GET['code']) || !hash_equals($_SESSION['oauth_state'] ?? '', $_GET['state'] ?? '')) { http_response_code(400); exit('Ungültige Anmeldung.'); }
$payload=['client_id'=>$config['microsoft_client_id'],'client_secret'=>$config['microsoft_client_secret'],'grant_type'=>'authorization_code','code'=>$_GET['code'],'redirect_uri'=>$config['app_url'].'/api/callback.php','code_verifier'=>$_SESSION['pkce_verifier']];
$ch=curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token'); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20]); $raw=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
$token=json_decode((string)$raw,true); if($status!==200 || empty($token['access_token'])) { http_response_code(401); exit('Microsoft-Anmeldung fehlgeschlagen.'); }
$ch=curl_init('https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName'); curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token['access_token']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20]); $me=json_decode((string)curl_exec($ch),true); curl_close($ch);
if(empty($me['id'])) { http_response_code(401); exit('Microsoft-Profil konnte nicht gelesen werden.'); }
$email=strtolower(trim((string)($me['mail']??$me['userPrincipalName']??''))); if(!filter_var($email,FILTER_VALIDATE_EMAIL)){http_response_code(401);exit('Microsoft hat keine verwendbare E-Mail-Adresse geliefert.');}
$q=$db->prepare('SELECT id FROM users WHERE microsoft_id=? OR LOWER(email)=? LIMIT 1'); $q->execute([$me['id'],$email]); $uid=(int)$q->fetchColumn();
if($uid){$q=$db->prepare('UPDATE users SET microsoft_id=?,email=?,display_name=? WHERE id=?');$q->execute([$me['id'],$email,$me['displayName']??null,$uid]);}
else{$q=$db->prepare('INSERT INTO users(microsoft_id,email,display_name) VALUES(?,?,?)');$q->execute([$me['id'],$email,$me['displayName']??null]);$uid=(int)$db->lastInsertId();}
$admins=array_map(static fn($address)=>strtolower(trim((string)$address)),(array)($config['admin_emails']??[]));
if(in_array($email,$admins,true)) { $q=$db->prepare('UPDATE users SET is_admin=1 WHERE id=?'); $q->execute([$uid]); }
$refreshToken=$token['refresh_token']??null; if(!is_string($refreshToken)||$refreshToken===''){http_response_code(401);exit('Microsoft hat keinen Refresh-Token geliefert. Bitte erneut anmelden.');}
$iv=random_bytes(12); $cipher=openssl_encrypt($refreshToken,'aes-256-gcm',hex2bin($config['encryption_key']),OPENSSL_RAW_DATA,$iv,$tag); if($cipher===false){http_response_code(500);exit('Token konnte nicht sicher gespeichert werden.');} $enc=base64_encode($iv.$tag.$cipher);
$q=$db->prepare('INSERT INTO oauth_tokens(user_id,refresh_token_enc,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE refresh_token_enc=VALUES(refresh_token_enc),expires_at=VALUES(expires_at)'); $q->execute([$uid,$enc,(int)($token['expires_in']??3600)]);
if(!empty($_SESSION['automation_setup_return'])) {
    $selectedAutomation=!empty($_SESSION['automation_setup_selected']);
    complete_login($uid);
    unset($_SESSION['automation_setup_return'],$_SESSION['automation_setup_selected'],$_SESSION['oauth_state'],$_SESSION['pkce_verifier'],$_SESSION['oauth_return_device_sync']);
    header('Location: '.$config['app_url'].'/api/automation-connect.php'.($selectedAutomation?'?scope=selected':''));exit;
}
$returnToDevice=!empty($_SESSION['oauth_return_device_sync'])&&($_SESSION['device_sync_pending']['expires']??0)>time();
complete_login($uid); unset($_SESSION['oauth_state'],$_SESSION['pkce_verifier'],$_SESSION['oauth_return_device_sync']); header('Location: '.$config['app_url'].($returnToDevice?'/api/device-sync.php?action=authorize':'/#home'));
