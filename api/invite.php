<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$token=(string)($_GET['token']??'');
if(!preg_match('/^[a-f0-9]{64}$/D',$token)){http_response_code(400);exit('Ungültiger Anmeldelink.');}
$q=$db->prepare('SELECT i.email,t.title FROM trip_invitations i JOIN trips t ON t.id=i.trip_id JOIN trip_guest_access a ON a.trip_id=i.trip_id AND LOWER(a.email)=LOWER(i.email) WHERE i.token_hash=? AND i.accepted_at IS NULL AND i.expires_at>NOW()');
$q->execute([hash('sha256',$token)]);$invite=$q->fetch();
if(!$invite){http_response_code(410);exit('Dieser Anmeldelink ist abgelaufen oder wurde bereits verwendet. <a href="../gastzugang.html">Neuen Link anfordern</a>.');}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
?>
<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reise ansehen · atlas</title><link rel="stylesheet" href="../style.css">
<main class="detail-page" style="max-width:620px;margin:auto">
<p class="eyebrow">GASTZUGANG</p><h1>Willkommen bei<br><em><?=htmlspecialchars((string)$invite['title'],ENT_QUOTES,'UTF-8')?></em></h1>
<p class="lede">Dieser Link meldet <?=htmlspecialchars((string)$invite['email'],ENT_QUOTES,'UTF-8')?> einmalig für die freigegebene Reise an.</p>
<form action="auth.php" method="post"><input type="hidden" name="action" value="redeem-link"><input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>"><button class="primary" type="submit">REISE ANSEHEN</button></form>
</main>
