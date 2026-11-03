<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/media-sources.php';
$owner=require_admin($db);
if(empty($config['dropbox_client_id'])||empty($config['dropbox_client_secret']))json(['error'=>'Dropbox muss zuerst in der Serverkonfiguration eingerichtet werden.'],503);
$redirect=rtrim((string)$config['app_url'],'/').'/api/dropbox.php';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_source_write();
    $_SESSION['dropbox_oauth']=['state'=>bin2hex(random_bytes(32)),'expires'=>time()+600,'owner'=>$owner];
    json(['url'=>'https://www.dropbox.com/oauth2/authorize?'.http_build_query(['client_id'=>$config['dropbox_client_id'],'redirect_uri'=>$redirect,'response_type'=>'code','token_access_type'=>'offline','scope'=>'files.metadata.read files.content.read','state'=>$_SESSION['dropbox_oauth']['state']])]);
}
$pending=$_SESSION['dropbox_oauth']??null;unset($_SESSION['dropbox_oauth']);
if(!$pending||$pending['expires']<time()||$pending['owner']!==$owner||!hash_equals($pending['state'],(string)($_GET['state']??'')))json(['error'=>'Die Dropbox-Verbindung ist abgelaufen. Bitte erneut starten.'],400);
if(isset($_GET['error'])){header('Location: ../?source=dropbox&connection=cancelled#import',true,303);exit;}
$code=(string)($_GET['code']??'');if($code==='')json(['error'=>'Dropbox hat keinen Verbindungscode geliefert.'],400);
$ch=curl_init('https://api.dropboxapi.com/oauth2/token');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirect,'client_id'=>$config['dropbox_client_id'],'client_secret'=>$config['dropbox_client_secret']]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode((string)$body,true);
if($status!==200||empty($data['refresh_token']))json(['error'=>'Dropbox konnte nicht verbunden werden. Bitte erneut versuchen.'],502);
try{source_save_connection($db,$config,$owner,'dropbox',(string)$data['refresh_token'],(string)($data['account_id']??''));}catch(Throwable $error){json(['error'=>$error->getMessage()],$error->getCode()===409?409:502);}
header('Location: ../?source=dropbox&connection=connected#import',true,303);exit;
