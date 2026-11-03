<?php
declare(strict_types=1);
// Connect a media source to the current account without replacing its login.
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/media-sources.php';
$owner=require_importer($db);
if(empty($config['microsoft_client_id'])||empty($config['microsoft_client_secret']))json(['error'=>'OneDrive muss zuerst in der Serverkonfiguration eingerichtet werden.'],503);
$redirect=rtrim((string)$config['app_url'],'/').'/api/callback.php';
$scope='openid profile offline_access User.Read Files.Read.All';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_source_write();$_SESSION['onedrive_oauth']=['state'=>'alr-onedrive-'.bin2hex(random_bytes(32)),'expires'=>time()+600,'owner'=>$owner];
    json(['url'=>'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.http_build_query(['client_id'=>$config['microsoft_client_id'],'redirect_uri'=>$redirect,'response_type'=>'code','response_mode'=>'query','scope'=>$scope,'state'=>$_SESSION['onedrive_oauth']['state']])]);
}
$pending=$_SESSION['onedrive_oauth']??null;unset($_SESSION['onedrive_oauth']);
if(!$pending||$pending['expires']<time()||$pending['owner']!==$owner||!hash_equals($pending['state'],(string)($_GET['state']??'')))json(['error'=>'Die OneDrive-Verbindung ist abgelaufen. Bitte erneut starten.'],400);
if(isset($_GET['error'])){header('Location: ../?source=onedrive&connection=cancelled#import',true,303);exit;}
$code=(string)($_GET['code']??'');if($code==='')json(['error'=>'OneDrive hat keinen Verbindungscode geliefert.'],400);
$ch=curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirect,'client_id'=>$config['microsoft_client_id'],'client_secret'=>$config['microsoft_client_secret'],'scope'=>$scope]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode((string)$body,true);
if($status!==200||empty($data['refresh_token']))json(['error'=>'OneDrive konnte nicht verbunden werden. Bitte erneut versuchen.'],502);
$ch=curl_init('https://graph.microsoft.com/v1.0/me?$select=id');curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$data['access_token']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);$account=json_decode((string)curl_exec($ch),true);curl_close($ch);
try{source_save_connection($db,$config,$owner,'onedrive',(string)$data['refresh_token'],(string)($account['id']??''));}catch(Throwable $error){json(['error'=>$error->getMessage()],$error->getCode()===409?409:502);}
header('Location: ../?source=onedrive&connection=connected#import',true,303);exit;
