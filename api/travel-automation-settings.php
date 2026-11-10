<?php
declare(strict_types=1);
require_once __DIR__.'/travel-automation-plan.php';

function travel_settings_path(string $root,mixed $path):string {
    $root=realpath($root);$resolved=is_string($path)?realpath($path):false;
    if(!$root||!$resolved||!is_file($resolved))throw new RuntimeException('private_settings_required');
    $normal=static fn($p)=>strtolower(str_replace('\\','/',$p));
    if(str_starts_with($normal($resolved),rtrim($normal($root),'/').'/')||preg_match('~[\\\\/](?:public_html|wwwroot|htdocs)(?:[\\\\/]|$)~i',$resolved))throw new RuntimeException('settings_must_be_outside_webroot');
    return $resolved;
}

/** Choices offered on the settings page (value => label). */
const TRAVEL_STATION_SIZES=['0.3'=>'klein (300 m) – Städtereise','1'=>'normal (1 km) – Standard','2'=>'groß (2 km) – Wandern, Natur','5'=>'sehr groß (5 km) – Roadtrip'];
const TRAVEL_QUIET_DAYS=['0'=>'nie – erst ein Heimatfoto beendet die Reise','2'=>'nach 2 Tagen ohne Reisefoto','3'=>'nach 3 Tagen ohne Reisefoto','5'=>'nach 5 Tagen ohne Reisefoto','7'=>'nach 7 Tagen ohne Reisefoto'];
const TRAVEL_HOME_BREAKS=['0'=>'nein – jedes Heimatfoto beendet die Reise','12'=>'bis 12 Stunden zu Hause','24'=>'bis 24 Stunden zu Hause','48'=>'bis 48 Stunden zu Hause'];
const TRAVEL_EXTRA_HOMES=3;
/** Shorter side in pixels => label. The page adds the size of the latest own camera captures. */
const TRAVEL_PHOTO_SIDES=['1080'=>'ab 1.080 px – auch ältere Handys','1500'=>'ab 1.500 px','2001'=>'mehr als 2.000 px – Standard','3000'=>'ab 3.000 px – aktuelle Handykameras (z. B. 4032 × 3024)'];
const TRAVEL_VIDEO_SIDES=['720'=>'ab HD (1280 × 720)','1080'=>'ab Full HD (1920 × 1080) – Standard','2160'=>'ab 4K (3840 × 2160)'];

/** One choice for the operation (stored as enabled + frequentRuns). */
const TRAVEL_MODES=['off'=>'Aus','night'=>'Nachts (0 bis 6 Uhr)','frequent'=>'Alle 10 Minuten, auch tagsüber'];
function travel_mode(array $s):string {return empty($s['enabled'])?'off':(!empty($s['frequentRuns'])?'frequent':'night');}
function travel_apply_mode(array $s,mixed $mode):array {
    if(!is_string($mode)||!isset(TRAVEL_MODES[$mode]))throw new InvalidArgumentException('invalid_mode');
    $s['enabled']=$mode!=='off';$s['frequentRuns']=$mode==='frequent';return $s;
}
/** Run results that mean the OneDrive permission of the automation must be granted again. */
const TRAVEL_CONNECTION_PROBLEMS=['onedrive_reconnect_required','automation_read_consent_required','automation_owner_connection_required','invalid_stored_connection','connection_decryption_failed'];
/** Last real run (time, result, imported and open cases) or null before the first run. */
function travel_last_run(string $privateDirectory):?array {
    $file=$privateDirectory.'/status.json';if(!is_file($file))return null;
    $status=json_decode((string)file_get_contents($file),true);if(!is_array($status))return null;
    $at=is_string($status['at']??null)?strtotime($status['at']):false;if($at===false)return null;
    $reason=is_string($status['reason']??null)?$status['reason']:null;
    return ['at'=>$at,'ok'=>($status['status']??'')==='ready','reason'=>$reason,'connectionProblem'=>in_array($reason,TRAVEL_CONNECTION_PROBLEMS,true),
        'imported'=>(int)($status['imported']??0),'review'=>(int)($status['review']??0),
        'backfillDone'=>is_array($status['backfillDone']??null)&&is_string($status['backfillDone']['from']??null)?$status['backfillDone']:null];
}

/** Plain reasons and what to do for captures the automation could not classify. */
const TRAVEL_REVIEW_REASONS=[
    'missing-capture-time'=>['keine Aufnahmezeit in der Datei','Meist ein geschicktes oder gespeichertes Bild. Gehört es zu einer Reise, dort von Hand hinzufügen und die Zeit setzen, sonst ignorieren.'],
    'missing-or-invalid-gps'=>['kein gültiger Aufnahmeort','Gehört es zu einer Reise, dort von Hand hinzufügen und die Station wählen. Mit „Auch kleinere Bilder und Bilder ohne Ort“ ordnet die Automatik es nach der Zeit zu.'],
    'original_size_review'=>['Original zu groß zum Lesen','Sehr große Dateien (oft lange Videos) liest die Automatik nicht. Bei Bedarf von Hand hinzufügen.'],
    'missing-dimensions'=>['Bildgröße unbekannt','OneDrive liefert keine Größe. Bei Bedarf von Hand hinzufügen.'],
    'missing-source-identity-or-version'=>['OneDrive-Angaben unvollständig','Wird beim nächsten Lauf erneut versucht.'],
    'event-version-changed'=>['Datei nach der Zuordnung geändert','Die Automatik ändert zugeordnete Fotos nicht selbst. In der Reise prüfen, ob das Foto noch passt.'],
    'late-home-splits-existing-trip'=>['späteres Heimatfoto würde eine bestehende Reise teilen','Bestehende Reisen ändert die Automatik nicht. In der Reise prüfen und bei Bedarf von Hand ändern.'],
    'multiple-existing-trips-in-segment'=>['passt zu mehreren bestehenden Reisen','In der passenden Reise von Hand hinzufügen.'],
];
/** Best known time of a review case: capture time, else file creation in OneDrive, else a date in the file name. */
function travel_review_time(array $seen):?int {
    foreach(['takenAt','fileCreatedAt'] as $key){$value=$seen['media'][$key]??null;if(is_string($value)&&($time=strtotime($value))!==false)return $time;}
    if(preg_match('/(?<!\d)(20\d{2})(\d{2})(\d{2})(?:[_ -]?(\d{2})(\d{2})(\d{2}))?/',(string)($seen['name']??''),$m)&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]))
        return gmmktime((int)($m[4]??12),(int)($m[5]??0),(int)($m[6]??0),(int)$m[2],(int)$m[3],(int)$m[1]);
    return null;
}
/** Place name from the GPS data of a capture: the name the automation stored, else looked up offline
 * in the map labels (no network). Null without GPS. */
function travel_review_place(array $entry,?string $labels):?string {
    $place=$entry['place']??null;if(is_string($place)&&$place!==''&&$place!=='Reise')return $place;
    $lat=$entry['media']['latitude']??null;$lon=$entry['media']['longitude']??null;
    if(!is_numeric($lat)||!is_numeric($lon)||abs((float)$lat)>90||abs((float)$lon)>180||$labels===null||!function_exists('pipeline_place'))return null;
    $name=pipeline_place((float)$lat,(float)$lon,$labels);return $name!=='Reise'?$name:null;
}
/** Captures waiting for a person: [count, newest items [id, name, reason, hint, can be added by hand, best known time]] from the run state. */
function travel_review_cases(string $privateDirectory,int $limit=50,?string $labels=null):array {
    $file=$privateDirectory.'/state.json';if(!is_file($file)||filesize($file)>64*1024*1024)return [0,[]];
    try{$state=json_decode((string)file_get_contents($file),true,256,JSON_THROW_ON_ERROR);}catch(JsonException){return [0,[]];}
    $cases=[];
    foreach((array)($state['seen']??[]) as $id=>$seen){if(empty($seen['review']))continue;$cases[$id]=['name'=>(string)($seen['name']??$id),'reason'=>(string)($seen['reason']??''),'manual'=>empty($seen['manual']),'at'=>travel_review_time($seen),'place'=>travel_review_place($seen,$labels)];}
    foreach((array)($state['jobs']??[]) as $id=>$job){$reason=$job['blocked']??(($job['status']??'')==='review'?($job['review']??'review'):null);if($reason===null)continue;$cases[$id]=['name'=>(string)($job['media']['name']??$id),'reason'=>(string)$reason,'manual'=>false,'at'=>is_int($job['capture']??null)?$job['capture']:null,'place'=>travel_review_place($job,$labels)];}
    uasort($cases,static fn($a,$b)=>strcmp($b['name'],$a['name']));
    $items=[];foreach(array_slice($cases,0,$limit,true) as $id=>$case){[$text,$hint]=TRAVEL_REVIEW_REASONS[$case['reason']]??[$case['reason'],'Wird beim nächsten Lauf erneut geprüft.'];$items[]=[(string)$id,$case['name'],$text,$hint,$case['manual'],$case['at'],$case['place']];}
    return [count($cases),$items];
}

/** Private run state of the automation (next to the private settings file, see tools/travel-automation.php). */
function travel_private_directory(string $settingsPath,string $ownerEmail):string {
    return dirname($settingsPath).'/travel-v2-'.substr(hash('sha256',strtolower($ownerEmail)),0,16);
}

/** Latest recorded photo and video (size, camera, capture time) from the automation's own run state. */
function travel_latest_captures(string $privateDirectory):array {
    $file=$privateDirectory.'/state.json';$latest=[];
    if(!is_file($file)||filesize($file)>64*1024*1024)return $latest;
    try{$state=json_decode((string)file_get_contents($file),true,256,JSON_THROW_ON_ERROR);}catch(JsonException){return $latest;}
    foreach((array)($state['events']??[]) as $event){
        $m=$event['media']??null;$at=$event['capture']??null;if(!is_array($m)||!is_int($at))continue;
        $kind=$m['kind']??'';$w=$m['width']??null;$h=$m['height']??null;
        if(!in_array($kind,['photo','video'],true)||!is_int($w)||!is_int($h)||$w<=0||$h<=0)continue;
        if(isset($latest[$kind])&&$latest[$kind]['capture']>=$at)continue;
        $latest[$kind]=['width'=>$w,'height'=>$h,'camera'=>trim(implode(' ',array_unique(array_filter([(string)($m['cameraMake']??''),(string)($m['cameraModel']??'')])))),'capture'=>$at];
    }
    return $latest;
}

/** The browser may change preferences, never owner, tool paths or state location. */
function travel_preferences(array $base,array $input):array {
    $keys=['sourceFolder','latitude','longitude','radiusKm','timezone','enabled','smallPhotos','backfillFrom','stationRadiusKm','quietDays','homeBreakHours','homeName','minPhotoShortSide','minVideoShortSide','frequentRuns','mode','backfillUntil','backfillKeep'];
    for($i=1;$i<=TRAVEL_EXTRA_HOMES;$i++)array_push($keys,'extraHomeName'.$i,'extraHomeLatitude'.$i,'extraHomeLongitude'.$i);
    if(array_diff(array_keys($input),$keys))throw new InvalidArgumentException('unknown_preference');
    foreach(['sourceFolder','timezone'] as $key)if(!is_string($input[$key]??null))throw new InvalidArgumentException('invalid_preference');
    foreach(['latitude','longitude','radiusKm'] as $key)if(!is_string($input[$key]??null)||!is_numeric($input[$key])||!is_finite((float)$input[$key]))throw new InvalidArgumentException('invalid_coordinates_or_radius');
    // smallPhotos: checkbox ("1") on older forms, choice "0"/"1" on the current page.
    if(isset($input['enabled'])&&$input['enabled']!=='1')throw new InvalidArgumentException('invalid_switch');
    if(isset($input['smallPhotos'])&&!in_array($input['smallPhotos'],['0','1'],true))throw new InvalidArgumentException('invalid_switch');
    $base['sourceFolder']=trim($input['sourceFolder']);$base['timezone']=trim($input['timezone']);
    $base['home']=['latitude'=>(float)$input['latitude'],'longitude'=>(float)$input['longitude']];$base['radiusKm']=(float)$input['radiusKm'];
    $base['enabled']=isset($input['enabled']);$base['smallPhotos']=($input['smallPhotos']??'')==='1';
    foreach(['stationRadiusKm'=>TRAVEL_STATION_SIZES,'quietDays'=>TRAVEL_QUIET_DAYS,'homeBreakHours'=>TRAVEL_HOME_BREAKS] as $key=>$choices){
        $value=$input[$key]??null;if(!is_string($value)||!array_key_exists($value,$choices))throw new InvalidArgumentException('invalid_rule_choice');
        $base[$key]=$key==='stationRadiusKm'?(float)$value:(int)$value;
    }
    // Optional fields (older forms do not send them): frequent runs, home place name and resolution rules.
    if(isset($input['frequentRuns'])){if(!in_array($input['frequentRuns'],['0','1'],true))throw new InvalidArgumentException('invalid_switch');$base['frequentRuns']=$input['frequentRuns']==='1';}
    // Current page: one choice Aus / Nachts / Alle 10 Minuten replaces both switches.
    if(isset($input['mode']))$base=travel_apply_mode($base,$input['mode']);
    if(isset($input['homeName'])){if(!is_string($input['homeName']))throw new InvalidArgumentException('invalid_home_name');$base['homeName']=trim($input['homeName']);}
    foreach(['minPhotoShortSide','minVideoShortSide'] as $key)if(isset($input[$key])){
        if(!is_string($input[$key])||!preg_match('/^[1-9][0-9]{2,4}$/D',$input[$key]))throw new InvalidArgumentException('invalid_resolution');
        $base[$key]=(int)$input[$key];
    }
    $base['extraHomes']=[];
    for($i=1;$i<=TRAVEL_EXTRA_HOMES;$i++){
        $name=$input['extraHomeName'.$i]??'';$lat=$input['extraHomeLatitude'.$i]??'';$lon=$input['extraHomeLongitude'.$i]??'';
        if(!is_string($name)||!is_string($lat)||!is_string($lon))throw new InvalidArgumentException('invalid_extra_homes');
        if(trim($lat)===''&&trim($lon)===''){if(trim($name)!=='')throw new InvalidArgumentException('invalid_extra_homes');continue;}
        if(!is_numeric($lat)||!is_numeric($lon))throw new InvalidArgumentException('invalid_extra_homes');
        $base['extraHomes'][]=['name'=>trim($name),'latitude'=>(float)$lat,'longitude'=>(float)$lon];
    }
    if(!is_string($input['backfillFrom']??null))throw new InvalidArgumentException('invalid_start_time');
    // A finished catch-up shows empty fields; saving them unchanged keeps the finished period in force.
    $keep=($input['backfillKeep']??'')==='1'&&trim($input['backfillFrom'])===''&&trim((string)($input['backfillUntil']??''))==='';
    if($keep){unset($input['backfillKeep']);return travel_settings($base);}
    // A plain date (date field) means the start of that day in the automation's time zone.
    $day=static function(string $value,string $zone):?string{$value=trim($value);if($value==='')return null;
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)){try{$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone($zone));}catch(Exception){$date=false;}if(!$date)throw new InvalidArgumentException('invalid_start_time');return $date->format('Y-m-d\TH:i:sP');}
        return $value;};
    $base['backfillFrom']=$day($input['backfillFrom'],$base['timezone']);
    if(isset($input['backfillUntil'])){if(!is_string($input['backfillUntil']))throw new InvalidArgumentException('invalid_start_time');$base['backfillUntil']=$day($input['backfillUntil'],$base['timezone']);}
    return travel_settings($base);
}
