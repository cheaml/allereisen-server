<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/travel-automation.php';
require_once __DIR__.'/travel-automation-settings.php';
if(empty($_SESSION['user_id'])){header('Location: '.rtrim($config['app_url'],'/').'/api/login.php',true,303);exit;}
$owner=require_microsoft_admin($db);
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$settings=null;$note='';$error='';$path=null;
try{
    $path=travel_settings_path(dirname(__DIR__),$config['travel_automation_settings_file']??null);
    $settings=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($settings))throw new RuntimeException('invalid_configuration');
    $q=$db->prepare('SELECT email FROM users WHERE id=?');$q->execute([$owner]);$email=$q->fetchColumn();
    if(!is_string($email)||strcasecmp($email,(string)($settings['ownerEmail']??''))!==0){http_response_code(403);$settings=null;throw new RuntimeException('different_owner');}
}catch(Throwable $e){$error='Die Fotoautomatik ist für dieses Konto noch nicht eingerichtet. Bitte den Websitebetreiber die einmalige Einrichtung abschließen lassen.';}
$_SESSION['travel_preferences_csrf']??=bin2hex(random_bytes(32));
if($settings!==null&&$_SERVER['REQUEST_METHOD']==='POST'){
    if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['travel_preferences_csrf'],$_POST['csrf'])){http_response_code(403);$error='Bitte die Seite erneut öffnen und nochmals speichern.';}
    else{
        try{
            $input=$_POST;unset($input['csrf']);$changed=travel_preferences($settings,$input);travel_save($path,$changed);$settings=$changed;
            $note='Gespeichert. Änderungen werden beim nächsten Lauf berücksichtigt. Vorhandene Reisen behalten ihre Zuordnung und ihren Ordner.';
        }catch(Throwable $e){http_response_code(400);$error='Nicht gespeichert. Bitte gültige Ordner, Heimatkoordinaten, positiven Radius und Zeitzone angeben. Ein Nachholbeginn benötigt Datum, Uhrzeit und Zeitzone. Die technische Einrichtung muss vollständig sein.';}
    }
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fotoautomatik – AlleReisen</title>
<style>body{font:17px/1.6 system-ui,sans-serif;max-width:760px;margin:32px auto;padding:0 20px;background:#ededed;color:#242424}h1{line-height:1.2}form{display:grid;gap:18px}label{display:grid;gap:6px;font-weight:600}input,select,button{box-sizing:border-box;max-width:100%;width:100%;min-height:44px;padding:10px;font:inherit;border:1px solid #888;border-radius:8px}button{background:#686868;color:white;cursor:pointer}label:has(input[type=checkbox]){display:flex;align-items:center}input[type=checkbox]{width:24px;flex:none}small{display:block;font-weight:400}.message{padding:14px;border:1px solid #777;border-radius:8px}.error{border-color:#a22}a{color:#333;overflow-wrap:anywhere}.coordinates{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:480px){.coordinates{grid-template-columns:1fr}}fieldset{min-width:0;border:1px solid #aaa;border-radius:10px;padding:16px}</style></head><body>
<h1>Deine Fotoautomatik</h1>
<p>Reiseaufnahmen werden nachts anhand ihrer Aufnahmezeit und ihres Aufnahmeorts zugeordnet. Fotos brauchen mehr als 2.000 Pixel an beiden Seiten, Videos mindestens 1.920 × 1.080 Pixel.</p>
<?php if($error!==''): ?><p role="alert" class="message error"><?=$escape($error)?></p><?php endif; ?>
<?php if($note!==''): ?><p role="status" class="message"><?=$escape($note)?></p><?php endif; ?>
<?php if($settings!==null): ?>
<form method="post"><input type="hidden" name="csrf" value="<?=$escape($_SESSION['travel_preferences_csrf'])?>">
<label>Überwachter Fotoordner<input name="sourceFolder" maxlength="2000" value="<?=$escape($settings['sourceFolder']??'Photos/Eigene Aufnahmen')?>"><small>Pfad im eigenen OneDrive, zum Beispiel Bilder/Kamera. Alle Unterordner zählen mit. Leer bedeutet gesamtes eigenes OneDrive.</small></label>
<label>Zielordner für Reisen<input name="targetFolder" required maxlength="2000" value="<?=$escape($settings['targetFolder']??'Reisen')?>"><small>Vorschlag: Reisen. Dieser Ordner wird nicht überwacht. Pro Reise entsteht Ort YYYY-MM, bei Namensgleichheit mit (2), (3) usw.</small></label>
<fieldset><legend>Heimatpunkt und Entfernung</legend><div class="coordinates">
<label>Breitengrad<input name="latitude" type="number" step="any" min="-90" max="90" required value="<?=$escape($settings['home']['latitude']??'')?>"></label>
<label>Längengrad<input name="longitude" type="number" step="any" min="-180" max="180" required value="<?=$escape($settings['home']['longitude']??'')?>"></label></div>
<label>Mindestentfernung in km<input name="radiusKm" type="number" step="any" min="0.001" required value="<?=$escape($settings['radiusKm']??30)?>"></label>
<small>Vorschlag: 30 km Luftlinie. Eine passende Aufnahme innerhalb dieses Radius beendet die laufende Reise und bleibt im Quellordner. Tageswechsel und Aufnahmeunterbrechungen allein beenden keine Reise.</small></fieldset>
<label>Zeitzone<input name="timezone" required value="<?=$escape($settings['timezone']??'Europe/Vienna')?>"><small>Bestimmt die nächtliche Grenze, einschließlich Sommer- und Winterzeit.</small></label>
<fieldset><legend>Betrieb</legend>
<label><input name="enabled" type="checkbox" value="1" <?=!empty($settings['enabled'])?'checked':''?>>Nächtliche Verarbeitung aktivieren</label>
<label><input name="cleanupEnabled" type="checkbox" value="1" <?=!empty($settings['cleanupEnabled'])?'checked':''?>>Quellen nach bestätigter Kopie und Import in den OneDrive-Papierkorb verschieben</label>
<small>Beginne mit einem Probelauf und teste Kopie und Import zuerst ohne Quellenbereinigung. Der Websitebetreiber muss den nächtlichen Dienst einmalig einrichten. Diese Seite startet keinen Fotojob.</small></fieldset>
<details><summary>Ältere Aufnahmen bewusst nachholen</summary><label>Nachholen ab (optional)<input name="backfillFrom" placeholder="2026-10-01T00:00:00+02:00" value="<?=$escape($settings['backfillFrom']??'')?>"></label><small>Standardmäßig beginnt die Überwachung ab Aktivierung. Ein neuer Nachholbeginn erlaubt die Verarbeitung älterer Aufnahmen. Vorher einen Probelauf durchführen lassen.</small></details>
<button type="submit">Einstellungen speichern</button></form>
<p><a href="automation-connect.php?scope=selected">OneDrive für die Fotoautomatik verbinden</a></p>
<?php endif; ?>
<p><a href="../#connections">Zurück zu Anmeldung &amp; Verbindungen</a> · <a href="../docs/FOTOAUTOMATIK.md">Anleitung</a></p>
</body></html>
