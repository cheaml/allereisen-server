<?php
declare(strict_types=1);

/** Server rule adapter. Pure evaluation: no Graph, database or file operations. */
function automation_evaluate(array $media,array $home):array
{
    $finite=static fn($v):bool=>(is_int($v)||is_float($v))&&is_finite((float)$v);
    if(!$finite($home['latitude']??null)||!$finite($home['longitude']??null)||abs($home['latitude'])>90||abs($home['longitude'])>180)
        throw new InvalidArgumentException('Confirmed home coordinates required.');
    $radius=$home['radiusKm']??30;
    if(!$finite($radius)||$radius<=0||$radius>1000)throw new InvalidArgumentException('Valid home radius required.');
    $result=static fn(string $outcome,string $reason,?float $distance=null):array=>['outcome'=>$outcome,'reason'=>$reason,'distanceKm'=>$distance];
    if(empty($media['itemId'])||empty($media['driveId'])||empty($media['accountId'])||empty($media['version']))return $result('review','missing-source-identity-or-version');
    if(!empty($media['isScreenshot'])||!empty($media['isScreenRecording']))return $result('excluded','screen-capture');
    if(!empty($media['isKnownOtherSource']))return $result('excluded','known-other-source');
    $kind=$media['kind']??'';
    if(!in_array($kind,['photo','video'],true))return $result('excluded','unsupported-media');
    $make=trim((string)($media['cameraMake']??''));$model=trim((string)($media['cameraModel']??''));
    if($make===''||$model==='')return $result('review','missing-camera-metadata');
    if(strcasecmp($make,'Apple')!==0||stripos($model,'iPhone')!==0)return $result('excluded','camera-not-apple-iphone');
    $width=$media['width']??null;$height=$media['height']??null;
    if(!is_int($width)||!is_int($height)||$width<=0||$height<=0)return $result('review','missing-dimensions');
    if($kind==='photo'?min($width,$height)<=2000:(max($width,$height)<1920||min($width,$height)<1080))return $result('excluded','below-resolution-rule');
    $time=$media['takenAt']??null;
    if(!is_string($time)||!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,7})?(Z|[+-](?:0[0-9]|1[0-3]):[0-5][0-9]|[+-]14:00)$/D',$time,$stamp))return $result('review','missing-capture-time');
    $capture=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$stamp[1].$stamp[2]);
    $errors=DateTimeImmutable::getLastErrors();
    if($capture===false||($errors!==false&&($errors['warning_count']||$errors['error_count'])))return $result('review','missing-capture-time');
    $lat=$media['latitude']??null;$lon=$media['longitude']??null;
    if(!$finite($lat)||!$finite($lon)||abs($lat)>90||abs($lon)>180)return $result('review','missing-or-invalid-gps');
    $a=sin(deg2rad($lat-$home['latitude'])/2)**2+cos(deg2rad($lat))*cos(deg2rad($home['latitude']))*sin(deg2rad($lon-$home['longitude'])/2)**2;
    $distance=6371.0088*2*asin(sqrt(max(0,min(1,$a))));
    return $distance>$radius?$result('candidate','iphone-resolution-gps-heuristic',$distance):$result('excluded','inside-home-radius',$distance);
}

function automation_graph_photo(array $item,string $accountId):array
{
    $photo=$item['photo']??[];
    return ['itemId'=>$item['id']??'','name'=>$item['name']??'','version'=>$item['eTag']??'','driveId'=>$item['parentReference']['driveId']??'','accountId'=>$accountId,
        'kind'=>isset($item['video'])?'video':(isset($item['photo'])||isset($item['image'])?'photo':'other'),
        // Graph video resolution alone does not establish camera provenance.
        'cameraMake'=>isset($item['video'])?null:($photo['cameraMake']??null),'cameraModel'=>isset($item['video'])?null:($photo['cameraModel']??null),
        'width'=>$item['image']['width']??null,'height'=>$item['image']['height']??null,'takenAt'=>$photo['takenDateTime']??null,
        'latitude'=>$item['location']['latitude']??null,'longitude'=>$item['location']['longitude']??null,
        'isScreenshot'=>preg_match('/^(?:screenshot|screen shot|bildschirmfoto)[ _-]/i',(string)($item['name']??''))===1];
}
