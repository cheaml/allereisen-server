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

/** The browser may change preferences, never owner, tool paths or state location. */
function travel_preferences(array $base,array $input):array {
    $keys=['sourceFolder','latitude','longitude','radiusKm','timezone','enabled','smallPhotos','backfillFrom','stationRadiusKm','quietDays','homeBreakHours'];
    for($i=1;$i<=TRAVEL_EXTRA_HOMES;$i++)array_push($keys,'extraHomeName'.$i,'extraHomeLatitude'.$i,'extraHomeLongitude'.$i);
    if(array_diff(array_keys($input),$keys))throw new InvalidArgumentException('unknown_preference');
    foreach(['sourceFolder','timezone'] as $key)if(!is_string($input[$key]??null))throw new InvalidArgumentException('invalid_preference');
    foreach(['latitude','longitude','radiusKm'] as $key)if(!is_string($input[$key]??null)||!is_numeric($input[$key])||!is_finite((float)$input[$key]))throw new InvalidArgumentException('invalid_coordinates_or_radius');
    foreach(['enabled','smallPhotos'] as $key)if(isset($input[$key])&&$input[$key]!=='1')throw new InvalidArgumentException('invalid_switch');
    $base['sourceFolder']=trim($input['sourceFolder']);$base['timezone']=trim($input['timezone']);
    $base['home']=['latitude'=>(float)$input['latitude'],'longitude'=>(float)$input['longitude']];$base['radiusKm']=(float)$input['radiusKm'];
    $base['enabled']=isset($input['enabled']);$base['smallPhotos']=isset($input['smallPhotos']);
    foreach(['stationRadiusKm'=>TRAVEL_STATION_SIZES,'quietDays'=>TRAVEL_QUIET_DAYS,'homeBreakHours'=>TRAVEL_HOME_BREAKS] as $key=>$choices){
        $value=$input[$key]??null;if(!is_string($value)||!array_key_exists($value,$choices))throw new InvalidArgumentException('invalid_rule_choice');
        $base[$key]=$key==='stationRadiusKm'?(float)$value:(int)$value;
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
    $base['backfillFrom']=trim($input['backfillFrom'])!==''?trim($input['backfillFrom']):null;
    return travel_settings($base);
}
