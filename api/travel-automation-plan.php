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

/** Originals always stay where they are: journeys reference the source items,
 * there is no target folder, copy or source cleanup. Older private settings
 * files may still carry targetFolder/cleanupEnabled/storageMode; they are
 * accepted and dropped so an update never breaks a configured server. */
// Camera-sized media: photos need at least this shorter side, videos this shorter side and a 16:9
// long side. Defaults match the original fixed rule (photos above 2000 px, videos Full HD).
const TRAVEL_DEFAULT_PHOTO_SIDE=2001;
const TRAVEL_DEFAULT_VIDEO_SIDE=1080;
function travel_settings(array $input):array {
    $defaults=['enabled'=>false,'smallPhotos'=>false,'frequentRuns'=>false,'timezone'=>'Europe/Vienna','radiusKm'=>30,'extraHomes'=>[],
        'quietDays'=>0,'homeBreakHours'=>0,'stationRadiusKm'=>1.0,'homeName'=>'',
        'minPhotoShortSide'=>TRAVEL_DEFAULT_PHOTO_SIDE,'minVideoShortSide'=>TRAVEL_DEFAULT_VIDEO_SIDE,
        'sourceFolder'=>'Photos/Eigene Aufnahmen','startAt'=>null,'backfillFrom'=>null,'backfillUntil'=>null,
        'scanItemsPerRun'=>2000,'mediaPerRun'=>10,'maxRunSeconds'=>480];
    $legacy=['targetFolder','cleanupEnabled','storageMode'];
    if(array_diff(array_keys($input),array_merge(array_keys($defaults),['ownerEmail','home','exiftool','perl'],$legacy)))throw new InvalidArgumentException('unknown_setting');
    $s=array_diff_key(array_replace($defaults,$input),array_flip($legacy));
    // frequentRuns: also run during the day (every cron run, usually five minutes), including today's captures.
    foreach(['enabled','smallPhotos','frequentRuns'] as $k)if(!is_bool($s[$k]))throw new InvalidArgumentException('invalid_switch');
    if(!filter_var($s['ownerEmail']??null,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('owner_email_required');
    if(!is_string($s['timezone'])||!in_array($s['timezone'],DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('invalid_timezone');
    foreach(['startAt','backfillFrom','backfillUntil'] as $key)if($s[$key]!==null&&travel_timestamp($s[$key])===null)throw new InvalidArgumentException('invalid_start_time');
    // Catch up only a period (e.g. one journey): captures between its end and the activation stay out.
    if($s['backfillUntil']!==null&&($s['backfillFrom']===null||travel_timestamp($s['backfillUntil'])<=travel_timestamp($s['backfillFrom'])))throw new InvalidArgumentException('invalid_backfill_period');
    $h=$s['home']??[];$finite=static fn($v)=>is_int($v)||is_float($v)&&is_finite($v);
    if(!is_array($h)||!$finite($h['latitude']??null)||!$finite($h['longitude']??null)||abs($h['latitude'])>90||abs($h['longitude'])>180)throw new InvalidArgumentException('home_coordinates_required');
    if(!$finite($s['radiusKm'])||$s['radiusKm']<=0)throw new InvalidArgumentException('invalid_radius');
    // Further home places (second home, work, parents): captures there count as home.
    if(!is_array($s['extraHomes'])||!array_is_list($s['extraHomes'])||count($s['extraHomes'])>5)throw new InvalidArgumentException('invalid_extra_homes');
    foreach($s['extraHomes'] as $i=>$extra){
        if(!is_array($extra)||array_diff(array_keys($extra),['name','latitude','longitude'])||!$finite($extra['latitude']??null)||!$finite($extra['longitude']??null)||abs($extra['latitude'])>90||abs($extra['longitude'])>180)throw new InvalidArgumentException('invalid_extra_homes');
        $name=$extra['name']??'';if(!is_string($name)||mb_strlen($name)>60)throw new InvalidArgumentException('invalid_extra_homes');
        $s['extraHomes'][$i]=['name'=>trim($name),'latitude'=>$extra['latitude'],'longitude'=>$extra['longitude']];
    }
    // A journey also ends after this many days without a journey capture (0 = off).
    if(!is_int($s['quietDays'])||$s['quietDays']<0||$s['quietDays']>60)throw new InvalidArgumentException('invalid_quiet_days');
    // A home stay shorter than this many hours does not end the journey (0 = off).
    if(!is_int($s['homeBreakHours'])||$s['homeBreakHours']<0||$s['homeBreakHours']>72)throw new InvalidArgumentException('invalid_home_break');
    if(is_int($s['stationRadiusKm']))$s['stationRadiusKm']=(float)$s['stationRadiusKm'];
    if(!$finite($s['stationRadiusKm'])||$s['stationRadiusKm']<0.05||$s['stationRadiusKm']>50)throw new InvalidArgumentException('invalid_station_radius');
    foreach(['minPhotoShortSide'=>[300,12000],'minVideoShortSide'=>[240,4320]] as $key=>[$min,$max])if(!is_int($s[$key])||$s[$key]<$min||$s[$key]>$max)throw new InvalidArgumentException('invalid_resolution');
    // Name of the main home place (shown on the settings page only).
    if(!is_string($s['homeName'])||mb_strlen($s['homeName'])>80)throw new InvalidArgumentException('invalid_home_name');$s['homeName']=trim($s['homeName']);
    if(!is_string($s['sourceFolder']))throw new InvalidArgumentException('invalid_folder_path');TravelOneDriveScope::assertPath($s['sourceFolder'],true);
    foreach(['scanItemsPerRun'=>[1,10000],'mediaPerRun'=>[1,50],'maxRunSeconds'=>[10,480]] as $key=>[$min,$max])if(!is_int($s[$key])||$s[$key]<$min||$s[$key]>$max)throw new InvalidArgumentException('invalid_budget');
    foreach(['exiftool','perl'] as $key)if(!is_string($s[$key]??null)||!is_file($s[$key]))throw new InvalidArgumentException('metadata_tool_required');
    $s['ownerEmail']=strtolower($s['ownerEmail']);return $s;
}

function travel_classify(array $m,array $s):array {
    foreach(['itemId','driveId','accountId','version'] as $k)if(empty($m[$k]))return ['outcome'=>'review','reason'=>'missing-source-identity-or-version'];
    if(!empty($m['isScreenshot'])||!empty($m['isScreenRecording'])||!empty($m['isKnownOtherSource']))return ['outcome'=>'excluded','reason'=>'screen-or-other-source'];
    $kind=$m['kind']??'';if(!in_array($kind,['photo','video'],true))return ['outcome'=>'excluded','reason'=>'unsupported-media'];
    $w=$m['width']??null;$h=$m['height']??null;$open=!empty($s['smallPhotos']);
    if(!is_int($w)||!is_int($h)||$w<=0||$h<=0)return ['outcome'=>'review','reason'=>'missing-dimensions'];
    // Camera-sized media (see TRAVEL_DEFAULT_PHOTO_SIDE): the shorter side decides; videos also need a 16:9 long side.
    $photoSide=$s['minPhotoShortSide']??TRAVEL_DEFAULT_PHOTO_SIDE;$videoSide=$s['minVideoShortSide']??TRAVEL_DEFAULT_VIDEO_SIDE;
    $small=$kind==='photo'?min($w,$h)<$photoSide:min($w,$h)<$videoSide||max($w,$h)<intdiv($videoSide*16,9);
    if($small&&!$open)return ['outcome'=>'excluded','reason'=>'below-resolution-rule'];
    $stamp=travel_timestamp($m['takenAt']??null);
    if($stamp===null){
        // With the open option, media without a recorded capture time (WhatsApp and other
        // received or saved pictures) use the time the file was created on the device and
        // join the running journey; they never start or end one.
        $created=$open?travel_timestamp($m['fileCreatedAt']??null):null;
        return $created!==null?['outcome'=>'timed','reason'=>'file-time-assigned-by-time','capture'=>$created,'captureSource'=>'file-created']:['outcome'=>'review','reason'=>'missing-capture-time'];
    }
    $located=true;foreach(['latitude'=>90,'longitude'=>180] as $k=>$limit){$v=$m[$k]??null;if(!(is_int($v)||is_float($v))||!is_finite((float)$v)||abs($v)>$limit)$located=false;}
    // With the open option (WhatsApp and similar), media without location join the
    // journey running at their capture time; they never start or end a journey.
    if(!$located)return $open?['outcome'=>'timed','reason'=>'no-location-assigned-by-time','capture'=>$stamp]:['outcome'=>'review','reason'=>'missing-or-invalid-gps'];
    $distance=INF;
    foreach(array_merge([$s['home']],$s['extraHomes']??[]) as $home){
        $a=sin(deg2rad($m['latitude']-$home['latitude'])/2)**2+cos(deg2rad($m['latitude']))*cos(deg2rad($home['latitude']))*sin(deg2rad($m['longitude']-$home['longitude'])/2)**2;
        $distance=min($distance,6371.0088*2*asin(sqrt(max(0,min(1,$a)))));
    }
    // Only camera-sized media are home evidence: a small or forwarded picture at home never ends a journey.
    if($distance<=$s['radiusKm']&&$small)return ['outcome'=>'excluded','reason'=>'small-media-not-home-evidence','capture'=>$stamp,'distanceKm'=>$distance];
    return ['outcome'=>$distance>$s['radiusKm']?'away':'home','reason'=>$distance>$s['radiusKm']?'outside-home-radius':'home-capture','capture'=>$stamp,'distanceKm'=>$distance];
}

/**
 * Capture data straight from the OneDrive listing, so most camera photos need no download of the original.
 * OneDrive reports takenDateTime as the local wall time marked "Z" (08:09 in Vienna arrives as 08:09Z).
 * iPhone camera uploads are named after the true UTC time (20260129_070953206_iOS.jpg = 07:09:53 UTC), so
 * both together give the exact moment and offset (2026-01-29T08:09:53+01:00), as exiftool reads it.
 * Anything incomplete or inconsistent (videos, received pictures, other names, no GPS) returns null:
 * then the original is read as before.
 */
function travel_listing_metadata(array $item):?array {
    if(isset($item['video'])||isset($item['remoteItem'])||!isset($item['file'],$item['photo']))return null;
    $photo=$item['photo'];$make=trim((string)($photo['cameraMake']??''));$wall=$photo['takenDateTime']??null;
    if($make===''||!is_string($wall)||!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,7})?Z$/D',$wall,$w))return null;
    if(!preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})\d{0,3}_iOS(?: \d+)?\.(?:jpe?g|heic|heif)$/iD',(string)($item['name']??''),$n))return null;
    $utc=gmmktime((int)$n[4],(int)$n[5],(int)$n[6],(int)$n[2],(int)$n[3],(int)$n[1]);
    $local=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$w[1],new DateTimeZone('UTC'));if(!$local)return null;
    $offset=$local->getTimestamp()-$utc;
    // Both clocks must agree up to a whole time-zone offset (quarter hours, at most 14 h).
    if($offset%900!==0||abs($offset)>14*3600)return null;
    $lat=$item['location']['latitude']??null;$lon=$item['location']['longitude']??null;
    if(!(is_int($lat)||is_float($lat))||!(is_int($lon)||is_float($lon))||!is_finite((float)$lat)||!is_finite((float)$lon)||abs($lat)>90||abs($lon)>180||((float)$lat===0.0&&(float)$lon===0.0))return null;
    $width=$item['image']['width']??null;$height=$item['image']['height']??null;
    if(!is_int($width)||!is_int($height)||$width<=0||$height<=0)return null;
    $sign=$offset<0?'-':'+';$abs=abs($offset);
    return ['kind'=>'photo','cameraMake'=>$make,'cameraModel'=>trim((string)($photo['cameraModel']??''))?:null,'width'=>$width,'height'=>$height,
        'takenAt'=>$local->format('Y-m-d\TH:i:s').sprintf('%s%02d:%02d',$sign,intdiv($abs,3600),intdiv($abs%3600,60)),'captureTimeSource'=>'onedrive-listing',
        'latitude'=>(float)$lat,'longitude'=>(float)$lon];
}

/**
 * Saved or received pictures (no camera, no GPS in the listing) when pictures without GPS do not count
 * anyway (smallPhotos off): they only end up on the review list, so their original need not be loaded.
 * Files younger than two days are still read: OneDrive may fill in camera and place a little later.
 */
function travel_listing_without_gps(array $item,int $now):?array {
    if(isset($item['video'])||isset($item['remoteItem'])||!isset($item['file']))return null;
    if(!preg_match('/\.(?:jpe?g|heic|heif|png)$/iD',(string)($item['name']??'')))return null;
    if(isset($item['location']['latitude'])||isset($item['location']['longitude'])||trim((string)($item['photo']['cameraMake']??''))!=='')return null;
    $created=strtotime((string)($item['createdDateTime']??''));if($created===false||$now-$created<2*86400)return null;
    $width=$item['image']['width']??null;$height=$item['image']['height']??null;
    if(!is_int($width)||!is_int($height)||$width<=0||$height<=0)return null;
    // Capture time only when the iPhone file name confirms it (see travel_listing_metadata).
    $timed=travel_listing_metadata(array_replace_recursive($item,['photo'=>['cameraMake'=>'listing'],'location'=>['latitude'=>1.0,'longitude'=>1.0]]));
    return ['kind'=>'photo','cameraMake'=>null,'cameraModel'=>null,'width'=>$width,'height'=>$height,'takenAt'=>$timed['takenAt']??null,
        'captureTimeSource'=>'onedrive-listing-no-gps','latitude'=>null,'longitude'=>null];
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

/** Hours a media item without location may lie before the first or after the
 * last located capture of a journey and still belong to it. */
const TRAVEL_TIMED_MARGIN=6*3600;

/** Chronology is independent of Graph folder/page order and of midnight gaps.
 * Journeys are catalog entries only (no OneDrive folder); media without location
 * ("timed") join the journey around their capture time or wait for one.
 * Rules: quietDays ends a journey after that many days without a journey capture;
 * homeBreakHours lets a shorter home stay continue the journey. Both apply only to
 * captures not yet assigned, so existing journeys never change afterwards. */
function travel_plan(array $events,array &$trips,array $rules=[]):array {
    $quiet=max(0,(int)($rules['quietDays']??0))*86400;$break=max(0,(int)($rules['homeBreakHours']??0))*3600;
    uksort($events,static fn($a,$b)=>strcmp((string)$a,(string)$b));
    uasort($events,static fn($a,$b)=>$a['capture']<=>$b['capture']);
    $segments=[[]];$index=0;$at=[];$timed=[];$homes=[];$pendingHomes=[];$previous=null;
    foreach($events as $id=>$event){
        if($event['outcome']==='timed'){$timed[$id]=$event;continue;}
        $at[$event['capture']][$event['outcome']]=true;
        if($event['outcome']==='home'){$pendingHomes[]=$event['capture'];continue;}
        if($event['outcome']!=='away')continue;
        $split=false;
        if($previous!==null&&isset($previous['tripId'],$event['tripId'])){
            // Assigned journeys keep their boundaries; a late home photo inside one still splits (review).
            $split=$previous['tripId']!==$event['tripId']||$pendingHomes;
        }elseif($pendingHomes){
            $split=$previous===null||!$break||$event['capture']-$pendingHomes[0]>=$break;
        }elseif($previous!==null&&$quiet&&$event['capture']-$previous['capture']>$quiet)$split=true;
        if($split&&$pendingHomes)array_push($homes,...$pendingHomes);
        if($split&&$segments[$index]){$index++;$segments[$index]=[];}
        $segments[$index][$id]=$event;$previous=$event;$pendingHomes=[];
    }
    // Home captures after the last journey capture still close the journey.
    array_push($homes,...$pendingHomes);
    foreach($timed as $id=>$event){
        foreach($segments as $i=>$rows){
            if(!$rows)continue;$captures=array_column($rows,'capture');$first=min($captures);$last=max($captures);
            if($event['capture']<$first-TRAVEL_TIMED_MARGIN||$event['capture']>$last+TRAVEL_TIMED_MARGIN)continue;
            $from=min($event['capture'],$first);$until=max($event['capture'],$last);$crossesHome=false;
            foreach($homes as $home)if($home>=$from&&$home<=$until){$crossesHome=true;break;}
            if(!$crossesHome){$segments[$i][$id]=$event;break;}
        }
    }
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
            $located=array_filter($rows,static fn($e)=>$e['outcome']==='away');$first=reset($located);
            // Name the journey after the place with the most located captures (ties: the earlier one),
            // not after the first photo, which is often taken on the way.
            $counts=[];foreach($located as $event){$name=travel_place_name($event['place']??'Reise');$counts[$name]=($counts[$name]??0)+1;}
            $place=travel_place_name($first['place']??'Reise');foreach($counts as $name=>$count)if($count>$counts[$place])$place=$name;
            $date=new DateTimeImmutable($first['media']['takenAt']);
            $tripId=automation_uuid();$trips[$tripId]=['id'=>$tripId,'title'=>$place.' '.$date->format('Y'),'targetPath'=>'reise://'.$tripId,'first'=>$first['capture'],'last'=>$first['capture']];
        }
        foreach($rows as $id=>$event){$assignments[$id]=$tripId;$trips[$tripId]['first']=min($trips[$tripId]['first'],$event['capture']);$trips[$tripId]['last']=max($trips[$tripId]['last'],$event['capture']);}
    }
    return ['assignments'=>$assignments,'review'=>$review];
}
