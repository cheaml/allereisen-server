<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/travel-automation.php';
require_once __DIR__.'/travel-automation-settings.php';
if(empty($_SESSION['user_id'])){header('Location: '.rtrim($config['app_url'],'/').'/api/login.php',true,303);exit;}
$owner=require_microsoft_admin($db);
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
require_once __DIR__.'/travel-place-search.php';
// Map (Leaflet from unpkg with integrity check, OpenStreetMap tiles) and the place search through this page.
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline' https://unpkg.com; script-src 'self' https://unpkg.com; img-src 'self' data: https://*.tile.openstreetmap.org; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$settings=null;$note='';$error='';$path=null;
try{
    $path=travel_settings_path(dirname(__DIR__),$config['travel_automation_settings_file']??null);
    $settings=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($settings))throw new RuntimeException('invalid_configuration');
    $q=$db->prepare('SELECT email FROM users WHERE id=?');$q->execute([$owner]);$email=$q->fetchColumn();
    if(!is_string($email)||strcasecmp($email,(string)($settings['ownerEmail']??''))!==0){http_response_code(403);$settings=null;throw new RuntimeException('different_owner');}
}catch(Throwable $e){$error='Die Fotoautomatik ist für dieses Konto noch nicht eingerichtet. Bitte den Websitebetreiber die einmalige Einrichtung abschließen lassen.';}
// Place search for the home places (JSON, same sign-in as this page).
if($settings!==null&&$_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['place'])){
    session_write_close();
    if($_GET['place']==='search'){$places=travel_place_search($config,(string)($_GET['q']??''));json($places===null?['error'=>'Die Ortssuche ist gerade nicht erreichbar.']:['places'=>$places],$places===null?503:200);}
    if($_GET['place']==='reverse'&&is_numeric($_GET['lat']??null)&&is_numeric($_GET['lon']??null)){$label=travel_place_reverse($config,(float)$_GET['lat'],(float)$_GET['lon']);json(['label'=>$label??'']);}
    json(['error'=>'Ungültige Ortsabfrage.'],400);
}
require_once __DIR__.'/travel-review.php';
$privateDir=$settings!==null&&$path!==null?travel_private_directory($path,(string)($settings['ownerEmail']??'')):null;
// Review cases: thumbnail of a listed capture (only those) and the stations of a trip, same sign-in as this page.
if($privateDir!==null&&$_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['thumb'])){
    session_write_close();$id=(string)$_GET['thumb'];
    if(travel_review_entry($privateDir,$id)===null){http_response_code(404);exit;}
    require_once __DIR__.'/mail.php';$token=graph_access_token($db,$config,$owner);
    $size=($_GET['size']??'')==='large'?'large':'small';
    try{if(!$token)throw new TravelReviewError('no_token');[$image,$type]=travel_review_graph($token,'me/drive/items/'.rawurlencode($id).'/thumbnails/0/'.$size.'/content',true);}
    catch(TravelReviewError){http_response_code(404);exit;}
    header('Content-Type: '.(preg_match('~^image/[a-z0-9.+-]+$~',$type)?$type:'image/jpeg'));header('Cache-Control: private, max-age=3600');header('X-Content-Type-Options: nosniff');echo $image;exit;
}
// The original of a listed capture in OneDrive (opens there; Microsoft asks for the sign-in if needed).
if($privateDir!==null&&$_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['original'])){
    session_write_close();$id=(string)$_GET['original'];
    if(travel_review_entry($privateDir,$id)===null){http_response_code(404);exit('Diese Aufnahme steht nicht mehr zur Prüfung.');}
    require_once __DIR__.'/mail.php';$token=graph_access_token($db,$config,$owner);
    try{if(!$token)throw new TravelReviewError('no_token');[$raw]=travel_review_graph($token,'me/drive/items/'.rawurlencode($id).'?$select=id,webUrl');$web=json_decode($raw,true)['webUrl']??'';}
    catch(TravelReviewError){$web='';}
    $host=strtolower((string)parse_url((string)$web,PHP_URL_HOST));
    if(!str_starts_with((string)$web,'https://')||!preg_match('/(^|\.)(onedrive\.live\.com|1drv\.ms|sharepoint\.com)$/D',$host)){http_response_code(404);exit('OneDrive hat keinen Link zur Aufnahme geliefert.');}
    header('Location: '.$web,true,302);exit;
}
if($settings!==null&&$_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['stops'])){
    session_write_close();
    try{json(['stops'=>travel_review_stops($db,$owner,(string)$_GET['stops'])]);}catch(TravelReviewError $e){json(['error'=>$e->getMessage()],403);}
}
$_SESSION['travel_preferences_csrf']??=bin2hex(random_bytes(32));
if($settings!==null&&$_SERVER['REQUEST_METHOD']==='POST'){
    if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['travel_preferences_csrf'],$_POST['csrf'])){http_response_code(403);$error='Bitte die Seite erneut öffnen und nochmals speichern.';}
    // A review case settled by hand: add it to a trip (reference, time of the station) or ignore it.
    // Many review cases at once: ignore everything from a period (e.g. a stay with many sent pictures).
    elseif(($_POST['op']??null)==='dismiss-all'){
        try{
            if($privateDir===null)throw new TravelReviewError('Die Automatik ist nicht eingerichtet.');
            $count=travel_review_ignore_all($privateDir);
            $note=$count?$count.($count===1?' Aufnahme':' Aufnahmen').' ausgeblendet. Die Prüfliste ist leer; die Automatik legt sie nicht mehr vor.':'Es steht keine Aufnahme zur Prüfung, die sich ausblenden lässt.';
        }catch(TravelReviewError $e){http_response_code(409);$error=$e->getMessage();}
    }
    elseif(($_POST['op']??null)==='dismiss-range'){
        try{
            if($privateDir===null)throw new TravelReviewError('Die Automatik ist nicht eingerichtet.');
            $count=travel_review_ignore_range($privateDir,(string)($_POST['from']??''),(string)($_POST['to']??''),(string)($settings['timezone']??'Europe/Vienna'));
            $note=$count?$count.($count===1?' Aufnahme':' Aufnahmen').' aus diesem Zeitraum ausgeblendet. Die Automatik legt sie nicht mehr vor.':'Im gewählten Zeitraum steht keine Aufnahme mit bekanntem Datum zur Prüfung.';
        }catch(TravelReviewError $e){http_response_code(409);$error=$e->getMessage();}
    }
    elseif(in_array($_POST['op']??null,['assign','dismiss'],true)){
        $id=(string)($_POST['item']??'');
        try{
            if($privateDir===null||travel_review_entry($privateDir,$id)===null)throw new TravelReviewError('Diese Aufnahme steht nicht mehr zur Prüfung.');
            if($_POST['op']==='assign'){
                $trip=(string)($_POST['trip']??'');if($trip==='')throw new TravelReviewError('Bitte zuerst eine Reise wählen.');
                require_once __DIR__.'/mail.php';$token=graph_access_token($db,$config,$owner);
                if(!$token)throw new TravelReviewError('Bitte auf der Website neu mit Microsoft anmelden und erneut versuchen.');
                travel_review_add($db,$owner,$token,$id,$trip,(string)($_POST['stop']??''));
                travel_review_settle($privateDir,$id,['action'=>'added','trip'=>$trip]);
                $note='Hinzugefügt. Die Aufnahme ist jetzt in der Reise; Zeit und Station kannst du dort ändern.';
            }else{travel_review_settle($privateDir,$id,['action'=>'ignored']);$note='Ausgeblendet. Die Automatik legt diese Aufnahme nicht mehr vor.';}
        }catch(TravelReviewError $e){http_response_code(409);$error=$e->getMessage();}
        catch(PDOException){http_response_code(500);$error='Die Aufnahme konnte nicht hinzugefügt werden. Bitte später erneut versuchen.';}
    }
    else{
        try{
            $input=$_POST;unset($input['csrf']);$changed=travel_preferences($settings,$input);travel_save($path,$changed);$settings=$changed;
            $note='Gespeichert. Änderungen werden beim nächsten Lauf berücksichtigt. Vorhandene Reisen behalten ihre Zuordnung.';
        }catch(Throwable $e){http_response_code(400);$error='Nicht gespeichert. Bitte einen gültigen Fotoordner, Heimatkoordinaten (auch bei weiteren Heimatorten beide Werte), positiven Radius und Zeitzone angeben. Ein Nachholbeginn benötigt Datum, Uhrzeit und Zeitzone. Die technische Einrichtung muss vollständig sein.';}
    }
}
// Size of the latest own captures (from the automation's run state) as a choice for the resolution rule.
$latest=$settings!==null&&$path!==null?travel_latest_captures(travel_private_directory($path,(string)($settings['ownerEmail']??''))):[];
$pixels=static fn(int $n):string=>number_format($n,0,',','.');
$captureDay=static function(int $at)use($settings):string{try{$zone=new DateTimeZone((string)($settings['timezone']??'Europe/Vienna'));}catch(Exception){$zone=new DateTimeZone('UTC');}return (new DateTimeImmutable('@'.$at))->setTimezone($zone)->format('d.m.Y');};
$sideChoices=static function(array $presets,?array $capture,int $current,string $own)use($pixels):array{
    $choices=$presets;
    if($capture){$side=(string)min($capture['width'],$capture['height']);$choices[$side]=isset($choices[$side])?$choices[$side].' · '.$own:'ab '.$pixels((int)$side).' px – '.$own;}
    if(!isset($choices[(string)$current]))$choices[(string)$current]='ab '.$pixels($current).' px – bisher gewählt';
    ksort($choices,SORT_NUMERIC);return $choices;
};
// Status line: last run of the automation and whether its OneDrive permission exists.
$lastRun=$settings!==null&&$path!==null?travel_last_run(travel_private_directory($path,(string)($settings['ownerEmail']??''))):null;
require_once __DIR__.'/automation-pipeline.php';
[$reviewCount,$reviewItems]=$privateDir!==null?travel_review_cases($privateDir,50,dirname(__DIR__).'/map-labels'):[0,[]];
$reviewTrips=[];if($reviewCount>0){try{$reviewTrips=travel_review_trips($db,$owner);}catch(PDOException){}}
$granted=false;if($settings!==null){try{$q=$db->prepare('SELECT 1 FROM travel_automation_connections WHERE user_id=?');$q->execute([$owner]);$granted=(bool)$q->fetchColumn();}catch(PDOException){}}
$runTime=static function(int $at)use($settings):string{try{$zone=new DateTimeZone((string)($settings['timezone']??'Europe/Vienna'));}catch(Exception){$zone=new DateTimeZone('UTC');}$time=(new DateTimeImmutable('@'.$at))->setTimezone($zone);$today=(new DateTimeImmutable('now',$zone))->format('Y-m-d');return ($time->format('Y-m-d')===$today?'heute':$time->format('d.m.Y')).' '.$time->format('H:i');};
$photoSide=(int)($settings['minPhotoShortSide']??TRAVEL_DEFAULT_PHOTO_SIDE);$videoSide=(int)($settings['minVideoShortSide']??TRAVEL_DEFAULT_VIDEO_SIDE);
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fotoautomatik – AlleReisen</title>
<style>body{font:17px/1.6 system-ui,sans-serif;max-width:760px;margin:32px auto;padding:0 20px;background:#f8eddc;color:#2b241f}h1{line-height:1.2}form{display:grid;gap:18px}label{display:grid;gap:6px;font-weight:600}input,select,button{box-sizing:border-box;max-width:100%;width:100%;min-height:44px;padding:10px;font:inherit;border:1px solid #dfc39c;border-radius:8px;background:#fffaf0;color:#2b241f}button{background:#b8573f;border-color:#b8573f;color:#fffaf0;font-weight:600;cursor:pointer}h1{color:#26473e}label:has(input[type=checkbox]){display:flex;align-items:center}input[type=checkbox]{width:24px;flex:none}small{display:block;font-weight:400}.message{padding:14px;border:1px solid #d9823f;border-radius:8px;background:#fbf1e2}.error{border-color:#a22}a{color:#b8573f;overflow-wrap:anywhere}.coordinates{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:480px){.coordinates{grid-template-columns:1fr}}.extra-home{display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:12px;margin-top:12px}@media(max-width:600px){.extra-home{grid-template-columns:1fr}}details{margin-top:14px}summary{cursor:pointer;font-weight:600}select{background:#fffaf0}fieldset{min-width:0;border:1px solid #e2c59c;border-radius:10px;padding:16px;background:#fbf3e6}legend{font-weight:700;color:#26473e}.place-picker{display:grid;gap:10px;margin:6px 0 14px}.place-picker[hidden]{display:none}.place-search{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}.place-search button{width:auto;padding:10px 18px}.place-status{margin:0;min-height:1.2em;font-size:15px}.place-status:empty{display:none}.place-results{list-style:none;padding:0;margin:0;display:grid;gap:6px}.place-results button{display:grid;gap:2px;text-align:left;background:#fffaf0;color:#2b241f;border-color:#dfc39c;font-weight:400}.place-pin{border-radius:50%;background:#26473e;border:3px solid #fffaf0;box-shadow:0 2px 6px #0006}.place-pin.active{background:#b8573f}.place-map{height:320px;border-radius:10px;border:1px solid #dfc39c;z-index:0}.run-status{display:grid;gap:4px;padding:14px;margin:0 0 20px;border:1px solid #dfc39c;border-radius:10px;background:#fffaf0}.review-cases{margin-top:6px}.review-cases ul{margin:8px 0 0;padding-left:18px;display:grid;gap:6px}.review-cases li{display:flex;gap:10px;align-items:flex-start}.review-cases li small{display:block;color:#6b5a4a}.review-thumb{width:72px;height:72px;object-fit:cover;border-radius:8px;background:#e9dcc8;flex:none}.review-range{display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;margin:10px 0;padding:10px;border:1px dashed #dfc39c;border-radius:8px}.review-range strong,.review-range small{grid-column:1/-1}.review-range button{min-height:38px;padding:6px 8px;font-size:15px;background:#fffaf0;color:#b8573f}.review-original{display:inline-block;margin-top:4px;font-size:15px}.review-assign{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px}.review-assign select,.review-assign button{min-height:38px;padding:6px 8px;font-size:15px}.review-assign .quiet{background:#fffaf0;color:#b8573f}.run-status .warn{color:#a22;font-weight:700}.choice{display:grid!important;grid-template-columns:24px 1fr;gap:2px 10px;align-items:start}.choice input{width:20px;min-height:0;margin:5px 0 0}.choice small{grid-column:2}details.resolution,details.grant{margin-top:10px}.camera-now{display:grid;gap:2px;padding:12px;margin-bottom:14px;border:1px solid #dfc39c;border-radius:8px;background:#fffaf0}</style></head><body>
<h1>Deine Fotoautomatik</h1>
<p>Reiseaufnahmen werden nachts anhand ihrer Aufnahmezeit und ihres Aufnahmeorts zu Reisen zusammengefasst. <strong>Deine Fotos bleiben, wo sie sind:</strong> Die Automatik kopiert, verschiebt und löscht nichts, sie merkt sich nur, welches Foto zu welcher Reise gehört.</p>
<?php if($error!==''): ?><p role="alert" class="message error"><?=$escape($error)?></p><?php endif; ?>
<?php if($note!==''): ?><p role="status" class="message"><?=$escape($note)?></p><?php endif; ?>
<?php if($settings!==null): ?>
<div class="run-status" role="status">
<span><strong>Automatik:</strong> <?=$escape(TRAVEL_MODES[travel_mode($settings)])?></span>
<span><strong>Letzter Lauf:</strong> <?php if($lastRun===null): ?>noch keiner<?php else: ?><?=$escape($runTime($lastRun['at']))?> · <?=$lastRun['ok']?'erfolgreich'.($lastRun['imported']?', '.$lastRun['imported'].' neu eingeordnet':'').($lastRun['review']?', '.$lastRun['review'].' zur Prüfung':''):'mit Fehler'.($lastRun['reason']&&!$lastRun['connectionProblem']?' ('.$escape($lastRun['reason']).')':'')?><?php endif; ?></span>
<span><strong>OneDrive-Freigabe:</strong> <?php if(!$granted): ?><span class="warn">fehlt</span> · <a href="automation-connect.php?scope=selected">Jetzt bei Microsoft freigeben</a><?php elseif($lastRun['connectionProblem']??false): ?><span class="warn">muss erneuert werden</span> · <a href="automation-connect.php?scope=selected">Jetzt bei Microsoft freigeben</a><?php else: ?>vorhanden<?php endif; ?></span>
<?php if($reviewCount>0): ?><details class="review-cases"><summary>Zur Prüfung (<?=$reviewCount?>)</summary><small>Diese Aufnahmen hat die Automatik nicht eingeordnet. Sie blockieren nichts; die übrigen Fotos werden normal verarbeitet.<?=$reviewCount>count($reviewItems)?' Angezeigt werden die '.count($reviewItems).' neuesten.':''?></small><form method="post" class="review-range"><input type="hidden" name="csrf" value="<?=$escape($_SESSION['travel_preferences_csrf'])?>"><strong>Alle aus einem Zeitraum ignorieren</strong><label>von<input type="date" name="from" required></label><label>bis<input type="date" name="to" required></label><button name="op" value="dismiss-range" class="quiet">Zeitraum ignorieren</button><button name="op" value="dismiss-all" class="quiet" formnovalidate data-confirm="Alle Aufnahmen der Prüfliste ignorieren? Die Automatik legt sie danach nicht mehr vor. Die Fotos selbst bleiben in OneDrive.">Alle ignorieren</button><small>Zählt das Aufnahmedatum, sonst das Datum der Datei in OneDrive oder im Dateinamen. Aufnahmen ganz ohne Datum bleiben in der Liste.</small></form>
<ul><?php foreach($reviewItems as [$id,$name,$text,$hint,$canAdd,$reviewAt,$reviewPlace]): ?><li><?php if($canAdd): ?><a href="travel-automation-setup.php?thumb=<?=rawurlencode($id)?>&amp;size=large" target="_blank" rel="noopener" title="Groß ansehen"><img class="review-thumb" src="travel-automation-setup.php?thumb=<?=rawurlencode($id)?>" alt="Vorschau von <?=$escape($name)?>" loading="lazy" width="72" height="72"></a><?php endif; ?><div><strong><?=$escape($name)?></strong><?=$reviewAt!==null?' · '.$escape($captureDay($reviewAt)):''?><?=$reviewPlace!==null?' · '.$escape($reviewPlace):''?> – <?=$escape($text)?><small><?=$escape($hint)?></small>
<?php if($canAdd): ?><a class="review-original" href="travel-automation-setup.php?original=<?=rawurlencode($id)?>" target="_blank" rel="noopener">In OneDrive öffnen ↗</a><form method="post" class="review-assign"><input type="hidden" name="csrf" value="<?=$escape($_SESSION['travel_preferences_csrf'])?>"><input type="hidden" name="item" value="<?=$escape($id)?>"><select name="trip" class="review-trip" aria-label="Reise"><option value="">Reise wählen …</option><?php foreach($reviewTrips as $tripId=>$title): ?><option value="<?=$escape($tripId)?>"><?=$escape($title)?></option><?php endforeach; ?></select><select name="stop" class="review-stop" aria-label="Station"><option value="">erste Station</option></select><button name="op" value="assign">Zu Reise hinzufügen</button><button name="op" value="dismiss" class="quiet">Ignorieren</button></form><?php endif; ?></div></li><?php endforeach; ?></ul></details><?php endif; ?>
</div>
<form method="post" class="settings-form"><input type="hidden" name="csrf" value="<?=$escape($_SESSION['travel_preferences_csrf'])?>">
<label>Überwachter Fotoordner<input name="sourceFolder" maxlength="2000" value="<?=$escape($settings['sourceFolder']??'Photos/Eigene Aufnahmen')?>"><small>Pfad im eigenen OneDrive, zum Beispiel Bilder/Kamera. Alle Unterordner zählen mit. Leer bedeutet gesamtes eigenes OneDrive.</small></label>
<fieldset><legend>Heimatort und Entfernung</legend>
<label>Ortsname<input name="homeName" maxlength="80" placeholder="zum Beispiel Wien, Mariahilf" value="<?=$escape($settings['homeName']??'')?>"><small>Nur zur Anzeige. Entscheidend sind die Koordinaten.</small></label>
<div class="place-picker" hidden>
<div class="place-search"><label>Ort oder Adresse suchen<input type="search" class="place-query" placeholder="zum Beispiel Salzburg oder Hauptstraße 1, Graz" autocomplete="off"></label><button type="button" class="place-find">Suchen</button></div>
<p class="place-status" role="status"></p><ul class="place-results"></ul>
<label>Karte und Suche setzen<select class="place-target"><option value="home">den Heimatort</option><?php for($i=1;$i<=TRAVEL_EXTRA_HOMES;$i++): ?><option value="<?=$i?>">weiteren Heimatort <?=$i?></option><?php endfor; ?></select></label>
<div class="place-map" aria-label="Karte zur Wahl des Heimatorts"></div>
<small>Tippe auf die Karte oder ziehe die Markierung. Der Kreis zeigt die Mindestentfernung, innerhalb der du als zu Hause giltst.</small>
</div>
<div class="coordinates">
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
<?php $videoName=[720=>'HD',1080=>'Full HD',2160=>'4K'][$videoSide]??'ab '.$pixels($videoSide).' px'; $photoName=$photoSide===TRAVEL_DEFAULT_PHOTO_SIDE?'über 2.000 px':'ab '.$pixels($photoSide).' px'; ?>
<label class="choice"><input type="radio" name="smallPhotos" value="0" <?=empty($settings['smallPhotos'])?'checked':''?>>Nur Kameraaufnahmen mit Aufnahmeort – empfohlen<small>Fotos <?=$photoName?> (kürzere Seite), Videos ab <?=$escape($videoName)?>. Die Größe stellst du unten unter „Größe genauer einstellen“ ein.</small></label>
<label class="choice"><input type="radio" name="smallPhotos" value="1" <?=!empty($settings['smallPhotos'])?'checked':''?>>Auch kleinere Bilder und Bilder ohne Ort (z. B. WhatsApp)<small>Bilder ohne Ort kommen zur Reise, die zu ihrer Aufnahmezeit läuft; sie beginnen oder beenden nie selbst eine Reise. Geschickte Bilder stammen aber oft von anderen Orten, passende fügst du besser einzeln hinzu.</small></label>
<details class="resolution"><summary>Größe genauer einstellen</summary>
<?php if($latest): ?><div class="camera-now"><strong>Deine letzten Aufnahmen</strong><?php foreach(['photo'=>'Foto','video'=>'Video'] as $kind=>$word): if(isset($latest[$kind])):$c=$latest[$kind]; ?><span><?=$word?>: <?=$pixels(max($c['width'],$c['height']))?> × <?=$pixels(min($c['width'],$c['height']))?> px<?=$c['camera']!==''?' · '.$escape($c['camera']):''?> · <?=$captureDay($c['capture'])?></span><?php endif; endforeach; ?><small>So groß speichert deine Kamera zurzeit. Du kannst diese Größe als Untergrenze wählen („wie dein letztes Foto“).</small></div>
<?php else: ?><p><small>Sobald die Automatik Aufnahmen verarbeitet hat, siehst du hier die Auflösung deiner letzten Fotos und Videos.</small></p><?php endif; ?>
<label>Fotos zählen ab (kürzere Seite)<?=$select('minPhotoShortSide',$sideChoices(TRAVEL_PHOTO_SIDES,$latest['photo']??null,$photoSide,'wie dein letztes Foto'),$photoSide)?><small>Ein Foto mit 4.032 × 3.024 px hat als kürzere Seite 3.024 px.</small></label>
<label>Videos zählen ab<?=$select('minVideoShortSide',$sideChoices(TRAVEL_VIDEO_SIDES,$latest['video']??null,$videoSide,'wie dein letztes Video'),$videoSide)?></label>
</details>
<small>Bildschirmfotos zählen nie.</small></fieldset>
<label>Zeitzone<input name="timezone" required value="<?=$escape($settings['timezone']??'Europe/Vienna')?>"><small>Bestimmt die nächtliche Grenze, einschließlich Sommer- und Winterzeit.</small></label>
<fieldset><legend>Betrieb</legend>
<label>Automatik<?=$select('mode',TRAVEL_MODES,travel_mode($settings))?><small><strong>Nachts:</strong> Aufnahmen bis gestern werden zwischen 0 und 6 Uhr eingeordnet. <strong>Alle 10 Minuten:</strong> Neue Aufnahmen erscheinen schon während der Reise, etwa 5 bis 15 Minuten nach dem Hochladen in OneDrive; bei sehr großen Fotoordnern fragt der Server OneDrive dann oft ab.</small></label>
<small>Voraussetzung ist ein Cronjob am Server (siehe Anleitung). Beginne mit einem Probelauf. Diese Seite startet keinen Fotojob.</small></fieldset>
<details<?=!empty($settings['backfillFrom'])?' open':''?>><summary>Ältere Aufnahmen bewusst nachholen</summary><div class="coordinates"><label>Nachholen ab<input type="date" name="backfillFrom" value="<?=$escape(substr((string)($settings['backfillFrom']??''),0,10))?>"></label><label>bis (optional)<input type="date" name="backfillUntil" value="<?=$escape(substr((string)($settings['backfillUntil']??''),0,10))?>"></label></div><small>Normalerweise ordnet die Automatik nur Aufnahmen ab ihrer Aktivierung ein. Mit „Nachholen ab“ kommen ältere dazu. Mit „bis“ nur genau dieser Zeitraum, z. B. eine einzelne Reise: Aufnahmen danach bis zur Aktivierung bleiben außen vor, damit bestehende Reisen nicht doppelt entstehen. Beide Tage zählen ganz mit („bis“ = bis zum Beginn dieses Tages). Vorher einen Probelauf durchführen lassen.</small></details>
<button type="submit">Einstellungen speichern</button></form>
<?php if($granted&&!($lastRun['connectionProblem']??false)): ?><details class="grant"><summary>OneDrive-Freigabe erneuern oder anderes Konto</summary><small>Nur nötig, wenn du die Freigabe bei Microsoft widerrufen hast oder ein anderes OneDrive-Konto verwenden willst. Die Freigabe erneuert sich bei jedem Lauf selbst.</small><p><a href="automation-connect.php?scope=selected">Bei Microsoft neu freigeben</a></p></details><?php endif; ?>
<?php endif; ?>
<p><a href="../#connections">Zurück zu Anmeldung &amp; Verbindungen</a> · <a href="../docs/FOTOAUTOMATIK.md">Anleitung</a></p>
<script src="travel-automation-setup.js?v=20261108-csp" defer></script>
</body></html>
