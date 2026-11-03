<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail.php';

$isForm=$_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST);
$input=$isForm?$_POST:(json_decode(file_get_contents('php://input'),true) ?: []);
function auth_result(array $data, int $status=200): never { global $isForm; if($isForm){if($status>=200&&$status<300){header('Location: ../#home');exit;}http_response_code($status);header('Content-Type: text/html; charset=utf-8');echo '<p>'.htmlspecialchars((string)($data['error']??'Anmeldung nicht möglich.'),ENT_QUOTES,'UTF-8').'</p><p><a href="../gastzugang.html">Zurück zum Gastzugang</a></p>';exit;}json($data,$status); }
$action=(string)($input['action']??'');
$email=strtolower(trim((string)($input['email']??'')));
if($action==='redeem-link') {
  $token=(string)($input['token']??'');
  if(!preg_match('/^[a-f0-9]{64}$/D',$token))auth_result(['error'=>'Der Anmeldelink ist ungültig.'],400);
  try {
    $db->beginTransaction();
    $q=$db->prepare('SELECT i.id,i.trip_id,i.email FROM trip_invitations i JOIN trip_guest_access a ON a.trip_id=i.trip_id AND LOWER(a.email)=LOWER(i.email) WHERE i.token_hash=? AND i.accepted_at IS NULL AND i.expires_at>NOW() FOR UPDATE');
    $q->execute([hash('sha256',$token)]);$invite=$q->fetch();
    if(!$invite){$db->rollBack();auth_result(['error'=>'Der Anmeldelink ist abgelaufen oder wurde bereits verwendet.'],410);}
    $q=$db->prepare('SELECT id,is_admin FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');$q->execute([$invite['email']]);$user=$q->fetch();
    if($user && (bool)$user['is_admin']){$db->rollBack();auth_result(['error'=>'Für dieses Konto ist die Microsoft-Anmeldung erforderlich.'],403);}
    if($user)$userId=(int)$user['id'];
    else{$q=$db->prepare('INSERT INTO users(microsoft_id,email,display_name,is_admin) VALUES(NULL,?,?,0)');$q->execute([$invite['email'],$invite['email']]);$userId=(int)$db->lastInsertId();}
    $q=$db->prepare('UPDATE trip_invitations SET accepted_at=NOW() WHERE id=? AND accepted_at IS NULL');$q->execute([$invite['id']]);
    if($q->rowCount()!==1)throw new RuntimeException('Link bereits verwendet');
    $db->commit();
  } catch(Throwable $error) {
    if($db->inTransaction())$db->rollBack();
    auth_result(['error'=>'Die Anmeldung konnte nicht abgeschlossen werden.'],500);
  }
  complete_login($userId,'guest');
  if($isForm){header('Location: ../#trip/'.rawurlencode((string)$invite['trip_id']),true,303);exit;}
  json(['ok'=>true,'trip_id'=>$invite['trip_id']]);
}
if($action==='request-link') {
  if(!filter_var($email,FILTER_VALIDATE_EMAIL))auth_result(['error'=>'Bitte gib eine gültige E-Mail-Adresse ein.'],422);
  $q=$db->prepare('SELECT a.trip_id,t.title FROM trip_guest_access a JOIN trips t ON t.id=a.trip_id WHERE LOWER(a.email)=? ORDER BY t.starts_on DESC,t.id LIMIT 1');$q->execute([$email]);$trip=$q->fetch();
  $q=$db->prepare('SELECT is_admin FROM users WHERE LOWER(email)=? LIMIT 1');$q->execute([$email]);$isAdmin=(bool)$q->fetchColumn();
  if(!$trip || $isAdmin){
    if($isForm){header('Location: ../gastzugang.html?contact=1',true,303);exit;}
    json(['ok'=>false,'contact'=>true]);
  }
  $q=$db->prepare('SELECT COUNT(*) FROM trip_invitations WHERE LOWER(email)=? AND accepted_at IS NULL AND created_at>DATE_SUB(NOW(),INTERVAL 2 MINUTE)');$q->execute([$email]);
  if(!(int)$q->fetchColumn()){
      $q=$db->query('SELECT u.id FROM users u JOIN oauth_tokens o ON o.user_id=u.id WHERE u.is_admin=1 AND u.microsoft_id IS NOT NULL ORDER BY u.id LIMIT 1');$senderId=(int)$q->fetchColumn();
      if(!$senderId)auth_result(['error'=>'Der E-Mail-Versand ist derzeit nicht verfügbar.'],503);
      $token=bin2hex(random_bytes(32));$id=uuid();
      $q=$db->prepare('INSERT INTO trip_invitations(id,trip_id,email,token_hash,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE))');$q->execute([$id,$trip['trip_id'],$email,hash('sha256',$token)]);
      $url=rtrim((string)$config['app_url'],'/').'/api/invite.php?token='.rawurlencode($token);
      if(!send_invitation_mail($db,$config,$senderId,$email,(string)$trip['title'],$url)){
        $q=$db->prepare('DELETE FROM trip_invitations WHERE id=?');$q->execute([$id]);
        auth_result(['error'=>'Der E-Mail-Versand ist derzeit nicht verfügbar.'],503);
      }
  }
  if($isForm){header('Location: ../gastzugang.html?sent=1',true,303);exit;}
  json(['ok'=>true]);
}
if(in_array($action,['register','login','invite-register'],true)) auth_result(['error'=>'Bitte nutze die E-Mail-Einladung oder die Microsoft-Anmeldung.'],410);
auth_result(['error'=>'Nicht gefunden'],404);
