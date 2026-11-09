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
<?php
// Link preview for messengers (WhatsApp, Signal …): trip title, short line and the app icon.
// Fetching this page for the preview does not use up the invitation; only the button below does.
$previewTitle='Einladung: '.(string)$invite['title'];
$previewText='Reise mit Karte, Fotos und Diashow auf AlleReisen. Der Link gilt 7 Tage.';
$base=rtrim((string)($config['app_url']??''),'/');
$e=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES,'UTF-8');
?>
<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=$e($previewTitle)?> · AlleReisen</title>
<meta name="description" content="<?=$e($previewText)?>"><meta property="og:type" content="website"><meta property="og:site_name" content="AlleReisen">
<meta property="og:title" content="<?=$e($previewTitle)?>"><meta property="og:description" content="<?=$e($previewText)?>">
<?php if($base!==''):?><meta property="og:image" content="<?=$e($base.'/app-icon-512.png')?>"><meta property="og:image:width" content="512"><meta property="og:image:height" content="512"><?php endif;?>
<meta name="robots" content="noindex"><link rel="icon" href="../app-icon-192.png"><link rel="stylesheet" href="../style.css">
<main class="detail-page" style="max-width:620px;margin:auto">
<p class="eyebrow">GASTZUGANG</p><h1>Willkommen bei<br><em><?=htmlspecialchars((string)$invite['title'],ENT_QUOTES,'UTF-8')?></em></h1>
<p class="lede">Dieser Link meldet <?=htmlspecialchars((string)$invite['email'],ENT_QUOTES,'UTF-8')?> einmalig für die freigegebene Reise an.</p>
<form action="auth.php" method="post"><input type="hidden" name="action" value="redeem-link"><input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>"><button class="primary" type="submit">REISE ANSEHEN</button></form>
</main>
