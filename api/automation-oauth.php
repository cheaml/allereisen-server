<?php
declare(strict_types=1);
require_once __DIR__.'/automation-oauth-state.php';
require_once __DIR__.'/onedrive-scope.php';

/** Called by the existing registered callback, only for our separate state. */
function automation_oauth_finish(PDO $db,array $config):never
{
    $owner=require_microsoft_admin($db);$pending=$_SESSION['automation_oauth']??[];unset($_SESSION['automation_oauth']);
    try {
        automation_oauth_validate($pending,(string)($_GET['state']??''),$owner,time());
        if(isset($_GET['error']))throw new RuntimeException('Microsoft-Freigabe abgebrochen.');
        $code=(string)($_GET['code']??'');if($code==='')throw new RuntimeException('Microsoft hat keinen Freigabecode geliefert.');
        $ch=curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$config['microsoft_client_id'],'client_secret'=>$config['microsoft_client_secret'],'grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>rtrim($config['app_url'],'/').'/api/callback.php','code_verifier'=>$pending['verifier'],'scope'=>'openid profile offline_access User.Read Files.ReadWrite']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$token=json_decode((string)$body,true);
        if($status!==200||empty($token['access_token'])||empty($token['refresh_token'])||!in_array('Files.ReadWrite',explode(' ',(string)($token['scope']??'')),true))throw new RuntimeException('Microsoft hat die Schreibfreigabe nicht bestätigt.');
        $get=static function(string $path)use($token):array{$ch=curl_init('https://graph.microsoft.com/v1.0/'.$path);curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token['access_token']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($status!==200)throw new RuntimeException('OneDrive-Konto oder Photos nicht erreichbar.');return json_decode((string)$body,true,64,JSON_THROW_ON_ERROR);};
        $me=$get('me?$select=id');$q=$db->prepare('SELECT microsoft_id FROM users WHERE id=?');$q->execute([$owner]);$expected=$q->fetchColumn();
        try{$q=$db->prepare("SELECT account_id FROM media_source_connections WHERE user_id=? AND provider='onedrive'");$q->execute([$owner]);$expected=$q->fetchColumn()?:$expected;}catch(PDOException $e){if(($e->errorInfo[1]??null)!==1146)throw $e;}
        if(!is_string($expected)||$expected===''||!is_string($me['id']??null)||!hash_equals($expected,$me['id']))throw new RuntimeException('Bitte dasselbe OneDrive-Konto wie für die bestehenden Reisen verwenden.');
        $selected=($pending['selectedFolders']??false)===true;
        if($selected){
            require_once __DIR__.'/travel-automation-scope.php';$drive=$get('me/drive?$select=id');$photos=$get('me/drive/root?$select=id,name,folder,root,parentReference,remoteItem');
            if(empty($drive['id'])||isset($photos['parentReference']['driveId'])&&$photos['parentReference']['driveId']!==$drive['id'])throw new RuntimeException('Drive identity mismatch.');
            $photos['parentReference']['driveId']=$drive['id'];new TravelOneDriveScope($photos,'','Reisen');
        }else{$photos=$get('me/drive/root:/Photos?$select=id,name,folder,parentReference,remoteItem');$scope=new PhotosOneDriveScope($photos);$scope->assertAllowed([$photos],true);}
        $iv=random_bytes(12);$cipher=openssl_encrypt($token['refresh_token'],'aes-256-gcm',hex2bin($config['encryption_key']),OPENSSL_RAW_DATA,$iv,$tag);
        if($cipher===false)throw new RuntimeException('Die Verbindung konnte nicht sicher gespeichert werden.');
        $encoded=base64_encode($iv.$tag.$cipher);
        $q=$db->prepare($selected?'INSERT INTO travel_automation_connections(user_id,account_id,drive_id,root_item_id,refresh_token_enc) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),drive_id=VALUES(drive_id),root_item_id=VALUES(root_item_id),refresh_token_enc=VALUES(refresh_token_enc),updated_at=NOW()':'INSERT INTO automation_connections(user_id,account_id,drive_id,photos_item_id,refresh_token_enc) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),drive_id=VALUES(drive_id),photos_item_id=VALUES(photos_item_id),refresh_token_enc=VALUES(refresh_token_enc),updated_at=NOW()');
        $q->execute([$owner,$me['id'],$photos['parentReference']['driveId'],$photos['id'],$encoded]);
        header('Location: '.$config['app_url'].'/api/automation-connect.php'.($selected?'?scope=selected':''),true,303);exit;
    }catch(Throwable $e){
        http_response_code(400);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
        // No Graph/OAuth response bodies, codes, tokens or database diagnostics.
        echo '<p>Die OneDrive-Freigabe konnte nicht abgeschlossen werden. Bitte dasselbe Konto verwenden und die Freigabe erneut starten.</p><p><a href="automation-connect.php">Zur OneDrive-Verbindung</a></p>';exit;
    }
}
