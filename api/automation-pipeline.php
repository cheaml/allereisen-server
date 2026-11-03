<?php
declare(strict_types=1);
require_once __DIR__.'/automation-catalog.php';
require_once __DIR__.'/automation-exiftool.php';

function pipeline_folder_date(string $name):?string {
    if(!preg_match('/^(\d{4})(?:[-_.]?)(\d{2})(?:[-_.]?)(\d{2})(?=$|[^0-9])/u',$name,$m))return null;
    if(!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return null;
    return $m[1].'-'.$m[2].'-'.$m[3];
}
function pipeline_assignment(int $capture,array $trips):array {
    $matches=[];
    foreach($trips as $id=>$trip)if(isset($trip['first'],$trip['last'])&&$capture>=$trip['first']-86400&&$capture<=$trip['last']+86400)$matches[$id]=$trip;
    if(count($matches)>1)return ['status'=>'review','reason'=>'bridges-existing-trips'];
    if(!$matches)return ['status'=>'new'];
    $id=array_key_first($matches);$trip=$matches[$id];
    if($capture<$trip['first'])return ['status'=>'review','reason'=>'precedes-fixed-start'];
    if($capture>$trip['last']&&$capture-$trip['last']>=86400)return ['status'=>'new'];
    return ['status'=>'existing','id'=>$id];
}
function pipeline_folder_name(string $taken,string $place):string {
    $day=(new DateTimeImmutable($taken))->format('Ymd'); // recorded local capture date, not server/upload date
    $place=trim(preg_replace('~[\\\\/\x00-\x1f%]+~u',' ',$place)??'');
    return $day.' '.($place!==''?$place:'Reise');
}
function pipeline_place(float $lat,float $lon,string $directory):string {
    $best=INF;$name='Reise';$y=(int)floor($lat/5);$x=(int)floor($lon/5);
    for($dy=-1;$dy<=1;$dy++)for($dx=-1;$dx<=1;$dx++){
        $path=$directory.'/'.($y+$dy).'_'.($x+$dx).'.json';if(!is_file($path))continue;
        $data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
        foreach($data['features']??[] as $row){if(($row[4]??null)!=='city')continue;
            $distance=hypot(($lat-$row[2])*111.32,($lon-$row[3])*111.32*cos(deg2rad($lat)));
            if($distance<$best&&$distance<=75){$best=$distance;$name=trim((string)($row[5]?:$row[1]));}
        }
    }
    return $name;
}
function pipeline_due(DateTimeImmutable $now,?string $lastDay):bool {
    $local=$now->setTimezone(new DateTimeZone('Europe/Vienna'));
    return (int)$local->format('H')>=22&&$lastDay!==$local->format('Y-m-d');
}
function pipeline_metadata(Closure $get,string $token,array $chain,AutomationOneDriveScope $scope,string $fields,string $private,?string $exiftool=null,?string $perl=null):array {
    foreach($chain as &$part){$before=$part;$part=$get('me/drive/items/'.rawurlencode($part['id']).'?$select='.$fields);if(isset($before['root'],$part['root'])&&empty($part['parentReference']['driveId']))$part['parentReference']['driveId']=$before['parentReference']['driveId'];}unset($part);$scope->assertAllowed($chain);
    $item=$chain[0];if(!isset($item['file'])||($item['size']??0)<=0||$item['size']>256*1024*1024)throw new RuntimeException('original_size_review');
    $tmp=tempnam($private,'metadata-');$stream=fopen($tmp,'wb');$bytes=0;
    try{
        $ch=curl_init('https://graph.microsoft.com/v1.0/me/drive/items/'.rawurlencode($item['id']).'/content');
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'If-Match: '.$item['eTag']],CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_TIMEOUT=>90,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use($stream,&$bytes):int{$bytes+=strlen($chunk);return $bytes>256*1024*1024?0:fwrite($stream,$chunk);}]);
        $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);fclose($stream);$stream=null;
        if(!$ok||$code!==200||$bytes!==(int)$item['size'])throw new RuntimeException('original_read_incomplete');
        $metadata=automation_exiftool_metadata(automation_read_exiftool($tmp,$exiftool,$perl));$hash=hash_file('sha256',$tmp);
    }finally{if(is_resource($stream))fclose($stream);if(is_file($tmp))unlink($tmp);}
    foreach($chain as &$part){$before=$part;$part=$get('me/drive/items/'.rawurlencode($part['id']).'?$select='.$fields);if(isset($before['root'],$part['root'])&&empty($part['parentReference']['driveId']))$part['parentReference']['driveId']=$before['parentReference']['driveId'];}unset($part);$scope->assertAllowed($chain);
    if($chain[0]['eTag']!==$item['eTag'])throw new RuntimeException('source_changed_retry');
    return ['chain'=>$chain,'metadata'=>$metadata,'hash'=>$hash];
}
