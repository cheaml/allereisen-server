<?php
require __DIR__ . '/bootstrap.php';
$_SESSION['oauth_return_device_sync']=($_GET['device_sync']??'')==='1'
    && !empty($_SESSION['device_sync_pending'])
    && ($_SESSION['device_sync_pending']['expires']??0)>time();
$state=bin2hex(random_bytes(24)); $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
$_SESSION['oauth_state']=$state; $_SESSION['pkce_verifier']=$verifier;
$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
$params=['client_id'=>$config['microsoft_client_id'],'response_type'=>'code','redirect_uri'=>$config['app_url'].'/api/callback.php','response_mode'=>'query','scope'=>'openid profile offline_access User.Read Files.Read Mail.Send','state'=>$state,'code_challenge'=>$challenge,'code_challenge_method'=>'S256'];
// "Mit einem anderen Konto anmelden": always show Microsoft's account picker instead of silently reusing the last account.
if(($_GET['select']??'')==='1')$params['prompt']='select_account';
// Device sign-in started right after the app's OneDrive sign-in: suggest that same account,
// so Microsoft can reuse the browser sign-in instead of asking for the password again.
elseif($_SESSION['oauth_return_device_sync']&&($hint=(string)($_SESSION['device_sync_pending']['login_hint']??''))!=='')$params['login_hint']=$hint;
header('Location: https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.http_build_query($params));
exit;
