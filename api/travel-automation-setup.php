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
            $note='Gespeichert. Änderungen werden beim nächsten Lauf berücksichtigt. Vorhandene Reisen behalten ihre Zuordnung.';
        }catch(Throwable $e){http_response_code(400);$error='Nicht gespeichert. Bitte einen gültigen Fotoordner, Heimatkoordinaten (auch bei weiteren Heimatorten beide Werte), positiven Radius und Zeitzone angeben. Ein Nachholbeginn benötigt Datum, Uhrzeit und Zeitzone. Die technische Einrichtung muss vollständig sein.';}
    }
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fotoautomatik – AlleReisen</title>
<style>body{font:17px/1.6 system-ui,sans-serif;max-width:760px;margin:32px auto;padding:0 20px;background:#f8eddc;color:#2b241f}h1{line-height:1.2}form{display:grid;gap:18px}label{display:grid;gap:6px;font-weight:600}input,select,button{box-sizing:border-box;max-width:100%;width:100%;min-height:44px;padding:10px;font:inherit;border:1px solid #dfc39c;border-radius:8px;background:#fffaf0;color:#2b241f}button{background:#b8573f;border-color:#b8573f;color:#fffaf0;font-weight:600;cursor:pointer}h1{color:#26473e}label:has(input[type=checkbox]){display:flex;align-items:center}input[type=checkbox]{width:24px;flex:none}small{display:block;font-weight:400}.message{padding:14px;border:1px solid #d9823f;border-radius:8px;background:#fbf1e2}.error{border-color:#a22}a{color:#b8573f;overflow-wrap:anywhere}.coordinates{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:480px){.coordinates{grid-template-columns:1fr}}.extra-home{display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:12px;margin-top:12px}@media(max-width:600px){.extra-home{grid-template-columns:1fr}}details{margin-top:14px}summary{cursor:pointer;font-weight:600}select{background:#fffaf0}fieldset{min-width:0;border:1px solid #e2c59c;border-radius:10px;padding:16px;background:#fbf3e6}legend{font-weight:700;color:#26473e}</style></head><body>
<h1>Deine Fotoautomatik</h1>
<p>Reiseaufnahmen werden nachts anhand ihrer Aufnahmezeit und ihres Aufnahmeorts zu Reisen zusammengefasst. <strong>Deine Fotos bleiben, wo sie sind:</strong> Die Automatik kopiert, verschiebt und löscht nichts, sie merkt sich nur, welches Foto zu welcher Reise gehört.</p>
<?php if($error!==''): ?><p role="alert" class="message error"><?=$escape($error)?></p><?php endif; ?>
<?php if($note!==''): ?><p role="status" class="message"><?=$escape($note)?></p><?php endif; ?>
<?php if($settings!==null): ?>
<form method="post"><input type="hidden" name="csrf" value="<?=$escape($_SESSION['travel_preferences_csrf'])?>">
<label>Überwachter Fotoordner<input name="sourceFolder" maxlength="2000" value="<?=$escape($settings['sourceFolder']??'Photos/Eigene Aufnahmen')?>"><small>Pfad im eigenen OneDrive, zum Beispiel Bilder/Kamera. Alle Unterordner zählen mit. Leer bedeutet gesamtes eigenes OneDrive.</small></label>
<fieldset><legend>Heimatpunkt und Entfernung</legend><div class="coordinates">
<label>Breitengrad<input name="latitude" type="number" step="any" min="-90" max="90" required value="<?=$escape($settings['home']['latitude']??'')?>"></label>
<label>Längengrad<input name="longitude" type="number" step="any" min="-180" max="180" required value="<?=$escape($settings['home']['longitude']??'')?>"></label></div>
<label>Mindestentfernung in km<input name="radiusKm" type="number" step="any" min="0.001" required value="<?=$escape($settings['radiusKm']??30)?>"></label>
<small>Vorschlag: 30 km Luftlinie. Eine Kameraaufnahme innerhalb dieses Radius beendet die laufende Reise.</small>
<details<?=!empty($settings['extraHomes'])?' open':''?>><summary>Weitere Heimatorte (optional)</summary><small>Zum Beispiel Zweitwohnsitz, Arbeitsort oder Eltern. Aufnahmen im selben Radius um diese Orte zählen wie zu Hause und lösen keine Reise aus.</small>
<?php for($i=1;$i<=TRAVEL_EXTRA_HOMES;$i++):$extra=$settings['extraHomes'][$i-1]??[]; ?><div class="extra-home"><label>Bezeichnung<input name="extraHomeName<?=$i?>" maxlength="60" value="<?=$escape($extra['name']??'')?>"></label><label>Breitengrad<input name="extraHomeLatitude<?=$i?>" type="number" step="any" min="-90" max="90" value="<?=$escape($extra['latitude']??'')?>"></label><label>Längengrad<input name="extraHomeLongitude<?=$i?>" type="number" step="any" min="-180" max="180" value="<?=$escape($extra['longitude']??'')?>"></label></div><?php endfor; ?>
</details></fieldset>
<fieldset><legend>Reisen und Stationen</legend>
<?php $select=static function(string $name,array $choices,$current)use($escape):string{$html='<select name="'.$name.'">';foreach($choices as $value=>$label)$html.='<option value="'.$escape($value).'"'.((string)$value===(string)$current?' selected':'').'>'.$escape($label).'</option>';return $html.'</select>';}; ?>
<label>Stationsgröße<?=$select('stationRadiusKm',TRAVEL_STATION_SIZES,rtrim(rtrim(number_format((float)($settings['stationRadiusKm']??1),2,'.',''),'0'),'.'))?><small>Fotos desselben Tages innerhalb dieser Entfernung bilden eine Station. Klein trennt Sehenswürdigkeiten in einer Stadt, groß fasst Orte einer Wanderung oder Autofahrt zusammen.</small></label>
<label>Reise endet nach Funkstille<?=$select('quietDays',TRAVEL_QUIET_DAYS,(int)($settings['quietDays']??0))?><small>Hilfreich, wenn du zu Hause selten fotografierst: Nach so vielen Tagen ohne Reisefoto beginnt mit dem nächsten Reisefoto eine neue Reise.</small></label>
<label>Kurzer Heimataufenthalt unterbricht nicht<?=$select('homeBreakHours',TRAVEL_HOME_BREAKS,(int)($settings['homeBreakHours']??0))?><small>Zum Beispiel ein Zwischenstopp zu Hause zwischen zwei Reiseetappen: Geht es innerhalb dieser Zeit weiter, bleibt es eine Reise.</small></label>
<small>Tageswechsel und Aufnahmepausen allein beenden keine Reise. Geänderte Regeln gelten für neue Aufnahmen; bestehende Reisen bleiben, wie sie sind.</small></fieldset>
<fieldset><legend>Welche Aufnahmen zählen</legend>
<p><small>Standard: nur Kameraaufnahmen – Fotos mit mehr als 2.000 Pixeln an beiden Seiten, Videos ab 1.920 × 1.080 Pixeln, jeweils mit Aufnahmeort. Bildschirmfotos zählen nie.</small></p>
<label><input name="smallPhotos" type="checkbox" value="1" <?=!empty($settings['smallPhotos'])?'checked':''?>>Auch kleinere Fotos und Fotos ohne Ortsangabe einbeziehen (z. B. aus WhatsApp)</label>
<small>Kleinere Fotos mit Ortsangabe zählen wie Kameraaufnahmen. Fotos ohne Ortsangabe kommen zur Reise, die zu ihrer Aufnahmezeit läuft (bis 6 Stunden vor dem ersten oder nach dem letzten Reisefoto). Solche Bilder beginnen oder beenden nie selbst eine Reise.</small></fieldset>
<label>Zeitzone<input name="timezone" required value="<?=$escape($settings['timezone']??'Europe/Vienna')?>"><small>Bestimmt die nächtliche Grenze, einschließlich Sommer- und Winterzeit.</small></label>
<fieldset><legend>Betrieb</legend>
<label><input name="enabled" type="checkbox" value="1" <?=!empty($settings['enabled'])?'checked':''?>>Nächtliche Verarbeitung aktivieren</label>
<small>Beginne mit einem Probelauf. Der Websitebetreiber muss den nächtlichen Dienst einmalig einrichten. Diese Seite startet keinen Fotojob.</small></fieldset>
<details><summary>Ältere Aufnahmen bewusst nachholen</summary><label>Nachholen ab (optional)<input name="backfillFrom" placeholder="2026-10-01T00:00:00+02:00" value="<?=$escape($settings['backfillFrom']??'')?>"></label><small>Standardmäßig beginnt die Überwachung ab Aktivierung. Ein neuer Nachholbeginn erlaubt die Verarbeitung älterer Aufnahmen. Vorher einen Probelauf durchführen lassen.</small></details>
<button type="submit">Einstellungen speichern</button></form>
<p><a href="automation-connect.php?scope=selected">OneDrive für die Fotoautomatik verbinden</a></p>
<?php endif; ?>
<p><a href="../#connections">Zurück zu Anmeldung &amp; Verbindungen</a> · <a href="../docs/FOTOAUTOMATIK.md">Anleitung</a></p>
</body></html>
