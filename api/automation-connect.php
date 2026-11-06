<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/automation-oauth-state.php';
$selected=($_GET['scope']??'')==='selected';
if(empty($_SESSION['user_id'])){$_SESSION['automation_setup_return']=true;$_SESSION['automation_setup_selected']=$selected;header('Location: '.$config['app_url'].'/api/login.php',true,303);exit;}
$owner=require_microsoft_admin($db);
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$_SESSION['automation_csrf']??=bin2hex(random_bytes(32));
$authorizationUrl=null;
if($_SERVER['REQUEST_METHOD']==='POST') {
    if(!hash_equals($_SESSION['automation_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Bitte die Seite erneut öffnen.');}
    $pending=automation_oauth_begin($owner);$pending['selectedFolders']=$selected;$_SESSION['automation_oauth']=$pending;
    $challenge=rtrim(strtr(base64_encode(hash('sha256',$pending['verifier'],true)),'+/','-_'),'=');
    $params=['client_id'=>$config['microsoft_client_id'],'response_type'=>'code','redirect_uri'=>rtrim($config['app_url'],'/').'/api/callback.php','response_mode'=>'query',
        'scope'=>automation_oauth_scope($selected),'state'=>$pending['state'],'code_challenge'=>$challenge,'code_challenge_method'=>'S256','prompt'=>'consent'];
    // The site's form-action policy intentionally allows only same-origin
    // forms. Return a normal navigation link instead of a cross-origin POST
    // redirect; keep the existing site policy unchanged.
    $authorizationUrl='https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.http_build_query($params);
}
$connected=false;
try{$q=$db->prepare($selected?'SELECT root_item_id FROM travel_automation_connections WHERE user_id=?':'SELECT photos_item_id FROM automation_connections WHERE user_id=?');$q->execute([$owner]);$connected=(bool)$q->fetchColumn();}catch(PDOException $e){if(($e->errorInfo[1]??null)!==1146)throw $e;}
$csrf=htmlspecialchars($_SESSION['automation_csrf'],ENT_QUOTES,'UTF-8');
?>
<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>OneDrive-Reiseautomatik</title>
<style>body{font:18px/1.55 system-ui,sans-serif;max-width:680px;margin:48px auto;padding:0 24px;color:#2b241f;background:#f8eddc}h1{line-height:1.2;color:#26473e}button{font:inherit;font-weight:600;background:#b8573f;color:#fffaf0;border:0;border-radius:12px;padding:12px 20px;cursor:pointer}a{color:#b8573f}.status{padding:16px;background:#fbf1e2;border:1px solid #e2c59c;border-radius:12px}</style>
<h1>OneDrive für die Reiseautomatik verbinden</h1>
<?php if($selected): ?>
<?php if($connected): ?><p class="status">Die separate Verbindung für die allgemeine Fotoautomatik ist gespeichert. Den Laufstatus zeigt das private Automatikjournal.</p><?php endif; ?>
<p>Verbinde dein persönliches OneDrive-Konto. Fotoordner, Heimatpunkt und Radius legst du unter „Fotoautomatik einstellen“ fest. Unterordner werden mit überwacht.</p>
<p>Microsoft erteilt nur <strong>Lesezugriff</strong>. Die Fotoautomatik kopiert, verschiebt und löscht nichts; deine Originale bleiben, wo sie sind. Der Worker begrenzt das Lesen zusätzlich auf den gewählten Fotoordner. Diese Verbindung aktiviert keine Fotojobs. Beginne mit einem Probelauf.</p>
<?php else: ?>
<?php if($connected): ?><p class="status">Die Verbindung und der Ordner Photos sind bestätigt. Der aktuelle Laufstatus wird im privaten Automatikjournal geführt.</p><?php endif; ?>
<p>Verbinde dasselbe persönliche OneDrive-Konto, in dem deine Fotos im Ordner <strong>Photos</strong> liegen.</p>
<p>Microsoft erteilt Lesen und Schreiben für das Konto. Der Server begrenzt die Reiseautomatik zusätzlich auf <strong>Photos und seine Unterordner</strong>.</p>
<p>Diese Verbindung aktiviert noch keinen Fotojob. Im ersten Kopiertest bleiben die Originale erhalten.</p>
<?php endif; ?>
<?php if($authorizationUrl!==null): ?>
<p><a href="<?=htmlspecialchars($authorizationUrl,ENT_QUOTES,'UTF-8')?>">Weiter zur Microsoft-Freigabe</a></p>
<?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><button type="submit">Bei Microsoft freigeben</button></form>
<?php endif; ?>
<p><a href="../">Zur Reiseübersicht</a></p></html>
