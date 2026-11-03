<?php
declare(strict_types=1);
require_once __DIR__.'/travel-automation-scope.php';
require_once __DIR__.'/automation-pipeline.php';
require_once __DIR__.'/automation-rules.php';

function travel_timestamp(mixed $value):?int {
    if(!is_string($value)||!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,7})?(Z|[+-](?:0\d|1[0-3]):[0-5]\d|[+-]14:00)$/D',$value,$m))return null;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$m[1].$m[2]);$errors=DateTimeImmutable::getLastErrors();
    return $d&&($errors===false||!$errors['warning_count']&&!$errors['error_count'])?$d->getTimestamp():null;
}

function travel_settings(array $input):array {
    $defaults=['enabled'=>false,'cleanupEnabled'=>false,'storageMode'=>'copy','timezone'=>'Europe/Vienna','radiusKm'=>30,
        'sourceFolder'=>'Photos/Eigene Aufnahmen','targetFolder'=>'Reisen','startAt'=>null,'backfillFrom'=>null,
        'scanItemsPerRun'=>2000,'mediaPerRun'=>10,'maxRunSeconds'=>480];
    if(array_diff(array_keys($input),array_merge(array_keys($defaults),['ownerEmail','home','exiftool','perl'])))throw new InvalidArgumentException('unknown_setting');
    $s=array_replace($defaults,$input);
    foreach(['enabled','cleanupEnabled'] as $k)if(!is_bool($s[$k]))throw new InvalidArgumentException('invalid_switch');
    if(!filter_var($s['ownerEmail']??null,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('owner_email_required');
    if(!is_string($s['timezone'])||!in_array($s['timezone'],DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('invalid_timezone');
    foreach(['startAt','backfillFrom'] as $key)if($s[$key]!==null&&travel_timestamp($s[$key])===null)throw new InvalidArgumentException('invalid_start_time');
    $h=$s['home']??[];$finite=static fn($v)=>is_int($v)||is_float($v)&&is_finite($v);
    if(!is_array($h)||!$finite($h['latitude']??null)||!$finite($h['longitude']??null)||abs($h['latitude'])>90||abs($h['longitude'])>180)throw new InvalidArgumentException('home_coordinates_required');
    if(!$finite($s['radiusKm'])||$s['radiusKm']<=0)throw new InvalidArgumentException('invalid_radius');
    foreach(['sourceFolder','targetFolder'] as $key){if(!is_string($s[$key]))throw new InvalidArgumentException('invalid_folder_path');TravelOneDriveScope::assertPath($s[$key],$key==='sourceFolder');}
    if(TravelOneDriveScope::within($s['sourceFolder'],$s['targetFolder']))throw new InvalidArgumentException('source_inside_target');
    foreach(['scanItemsPerRun'=>[1,10000],'mediaPerRun'=>[1,50],'maxRunSeconds'=>[10,480]] as $key=>[$min,$max])if(!is_int($s[$key])||$s[$key]<$min||$s[$key]>$max)throw new InvalidArgumentException('invalid_budget');
    foreach(['exiftool','perl'] as $key)if(!is_string($s[$key]??null)||!is_file($s[$key]))throw new InvalidArgumentException('metadata_tool_required');
    if(!in_array($s['storageMode'],['copy','reference'],true))throw new InvalidArgumentException('invalid_storage_mode');
    if($s['storageMode']==='reference')$s['cleanupEnabled']=false;
    $s['ownerEmail']=strtolower($s['ownerEmail']);return $s;
}

function travel_classify(array $m,array $s):array {
    foreach(['itemId','driveId','accountId','version'] as $k)if(empty($m[$k]))return ['outcome'=>'review','reason'=>'missing-source-identity-or-version'];
    if(!empty($m['isScreenshot'])||!empty($m['isScreenRecording'])||!empty($m['isKnownOtherSource']))return ['outcome'=>'excluded','reason'=>'screen-or-other-source'];
    $kind=$m['kind']??'';if(!in_array($kind,['photo','video'],true))return ['outcome'=>'excluded','reason'=>'unsupported-media'];
    $w=$m['width']??null;$h=$m['height']??null;
    if(!is_int($w)||!is_int($h)||$w<=0||$h<=0)return ['outcome'=>'review','reason'=>'missing-dimensions'];
    if($kind==='photo'?min($w,$h)<=2000:min($w,$h)<1080||max($w,$h)<1920)return ['outcome'=>'excluded','reason'=>'below-resolution-rule'];
    $stamp=travel_timestamp($m['takenAt']??null);if($stamp===null)return ['outcome'=>'review','reason'=>'missing-capture-time'];
    foreach(['latitude'=>90,'longitude'=>180] as $k=>$limit){$v=$m[$k]??null;if(!(is_int($v)||is_float($v))||!is_finite((float)$v)||abs($v)>$limit)return ['outcome'=>'review','reason'=>'missing-or-invalid-gps'];}
    $home=$s['home'];$a=sin(deg2rad($m['latitude']-$home['latitude'])/2)**2+cos(deg2rad($m['latitude']))*cos(deg2rad($home['latitude']))*sin(deg2rad($m['longitude']-$home['longitude'])/2)**2;
    $distance=6371.0088*2*asin(sqrt(max(0,min(1,$a))));
    return ['outcome'=>$distance>$s['radiusKm']?'away':'home','reason'=>$distance>$s['radiusKm']?'outside-home-radius':'home-capture','capture'=>$stamp,'distanceKm'=>$distance];
}

function travel_place_name(string $place):string {
    $place=trim(preg_replace('~[\\\\/\x00-\x1f%:"<>|?*]+~u',' ',$place)??'');
    $place=trim($place," .\t\n\r\0\x0B");
    if($place==='')$place='Reise';
    // Leave room for year/month and a collision suffix in OneDrive's segment limit.
    if(function_exists('mb_strcut'))$place=mb_strcut($place,0,180,'UTF-8');
    else{preg_match('/^.{0,45}/us',$place,$letters);$place=$letters[0]??'Reise';}
    return trim($place)!==''?trim($place):'Reise';
}

/** Chronology is independent of Graph folder/page order and of midnight gaps. */
function travel_plan(array $events,array &$trips,Closure $folderExists):array {
    uksort($events,static fn($a,$b)=>strcmp((string)$a,(string)$b));
    uasort($events,static fn($a,$b)=>$a['capture']<=>$b['capture']);
    $segments=[[]];$index=0;$at=[];
    foreach($events as $id=>$event){$at[$event['capture']][$event['outcome']]=true;if($event['outcome']==='home'){$index++;$segments[$index]=[];}elseif($event['outcome']==='away')$segments[$index][$id]=$event;}
    $locations=[];
    foreach($segments as $i=>$rows)foreach($rows as $event)if(isset($event['tripId']))$locations[$event['tripId']][$i]=true;
    $blocked=[];foreach($locations as $tripId=>$positions)if(count($positions)>1)$blocked[$tripId]=true;
    $assignments=[];$review=[];
    foreach($segments as $rows){
        if(!$rows)continue;$ids=[];$reason=null;
        foreach($rows as $event){if(isset($event['tripId']))$ids[$event['tripId']]=true;if(count($at[$event['capture']]??[])>1)$reason='simultaneous-home-and-away';if(isset($blocked[$event['tripId']??'']))$reason='late-home-splits-existing-trip';}
        if(count($ids)>1)$reason='multiple-existing-trips-in-segment';
        if($reason!==null){foreach($rows as $id=>$event)$review[$id]=$reason;continue;}
        if($ids){$tripId=(string)array_key_first($ids);if(!isset($trips[$tripId]))throw new RuntimeException('trip_state_missing');}
        else{
            $first=reset($rows);$date=new DateTimeImmutable($first['media']['takenAt']);$place=travel_place_name($first['place']??'Reise');$base=$place.' '.$date->format('Y-m');$n=1;
            do{$name=$base.($n===1?'':' ('.$n.')');$path=$first['targetBase'].'/'.$name;$used=false;foreach($trips as $t)if(strcasecmp($t['targetPath'],$path)===0){$used=true;break;}$n++;if($n>10000)throw new RuntimeException('folder_collision_limit');}while($used||$folderExists($path));
            $tripId=automation_uuid();$trips[$tripId]=['id'=>$tripId,'title'=>$place.' '.$date->format('Y'),'folderName'=>$name,'targetBase'=>$first['targetBase'],'targetPath'=>$path,'first'=>$first['capture'],'last'=>$first['capture']];
        }
        foreach($rows as $id=>$event){$assignments[$id]=$tripId;$trips[$tripId]['first']=min($trips[$tripId]['first'],$event['capture']);$trips[$tripId]['last']=max($trips[$tripId]['last'],$event['capture']);}
    }
    return ['assignments'=>$assignments,'review'=>$review];
}
