<?php
declare(strict_types=1);

function graph_access_token(PDO $db, array $config, int $ownerId, bool $mailSend=false): ?string {
  $encoded=null;$sourceToken=false;
  if(!$mailSend){$q=$db->prepare("SELECT refresh_token_enc FROM media_source_connections WHERE user_id=? AND provider='onedrive'");$q->execute([$ownerId]);$encoded=$q->fetchColumn();$sourceToken=(bool)$encoded;}
  if(!$encoded){$q=$db->prepare('SELECT refresh_token_enc FROM oauth_tokens WHERE user_id=?');$q->execute([$ownerId]);$encoded=$q->fetchColumn();}
  $raw=base64_decode((string)$encoded,true);
  if($raw===false||strlen($raw)<29)return null;
  $refresh=openssl_decrypt(substr($raw,28),'aes-256-gcm',hex2bin($config['encryption_key']),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));if($refresh===false)return null;
  $payload=['client_id'=>$config['microsoft_client_id'],'client_secret'=>$config['microsoft_client_secret'],'grant_type'=>'refresh_token','refresh_token'=>$refresh,'scope'=>$mailSend?'openid profile offline_access User.Read Files.Read Mail.Send':($sourceToken?'openid profile offline_access User.Read Files.Read.All':'openid profile offline_access User.Read Files.Read')];
  $ch=curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);$token=json_decode((string)curl_exec($ch),true);curl_close($ch);return is_string($token['access_token']??null)?$token['access_token']:null;
}

function send_invitation_mail(PDO $db, array $config, int $senderId, string $recipient, string $tripTitle, string $url): bool {
  $token=graph_access_token($db,$config,$senderId,true);if(!$token)return false;
  $safeTitle=htmlspecialchars($tripTitle,ENT_QUOTES,'UTF-8');$safeUrl=htmlspecialchars($url,ENT_QUOTES,'UTF-8');
  $guestUrl=htmlspecialchars(rtrim((string)$config['app_url'],'/').'/gastzugang.html',ENT_QUOTES,'UTF-8');
  $body='<p>Du hast Zugang zur Reise <strong>'.$safeTitle.'</strong>.</p><p><a href="'.$safeUrl.'">Reise ansehen</a></p><p>Der Link ist einmalig gültig. Bestätige die Anmeldung auf der geöffneten Seite.</p><p>Für einen späteren Besuch kannst du <a href="'.$guestUrl.'">einen neuen Anmeldelink anfordern</a>.</p>';
  $payload=['message'=>['subject'=>'Dein Zugang zur Reise: '.$tripTitle,'body'=>['contentType'=>'HTML','content'=>$body],'toRecipients'=>[['emailAddress'=>['address'=>$recipient]]]],'saveToSentItems'=>true];
  $ch=curl_init('https://graph.microsoft.com/v1.0/me/sendMail');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);$result=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return $result!==false&&$status===202;
}
