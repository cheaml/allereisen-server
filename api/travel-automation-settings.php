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

/** The browser may change preferences, never owner, tool paths or state location. */
function travel_preferences(array $base,array $input):array {
    $keys=['sourceFolder','targetFolder','latitude','longitude','radiusKm','timezone','enabled','cleanupEnabled','backfillFrom'];
    if(array_diff(array_keys($input),$keys))throw new InvalidArgumentException('unknown_preference');
    foreach(['sourceFolder','targetFolder','timezone'] as $key)if(!is_string($input[$key]??null))throw new InvalidArgumentException('invalid_preference');
    foreach(['latitude','longitude','radiusKm'] as $key)if(!is_string($input[$key]??null)||!is_numeric($input[$key])||!is_finite((float)$input[$key]))throw new InvalidArgumentException('invalid_coordinates_or_radius');
    foreach(['enabled','cleanupEnabled'] as $key)if(isset($input[$key])&&$input[$key]!=='1')throw new InvalidArgumentException('invalid_switch');
    $base['sourceFolder']=trim($input['sourceFolder']);$base['targetFolder']=trim($input['targetFolder']);$base['timezone']=trim($input['timezone']);
    $base['home']=['latitude'=>(float)$input['latitude'],'longitude'=>(float)$input['longitude']];$base['radiusKm']=(float)$input['radiusKm'];
    $base['enabled']=isset($input['enabled']);$base['cleanupEnabled']=isset($input['cleanupEnabled']);
    if(!is_string($input['backfillFrom']??null))throw new InvalidArgumentException('invalid_start_time');
    $base['backfillFrom']=trim($input['backfillFrom'])!==''?trim($input['backfillFrom']):null;
    return travel_settings($base);
}
