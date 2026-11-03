<?php
// Public geographic data, fetched server-side and cached. No trip IDs or names
// are sent to OpenStreetMap. Only authenticated archive users may request tiles.
require __DIR__.'/bootstrap.php';
require_user(); session_write_close();
$x=filter_input(INPUT_GET,'x',FILTER_VALIDATE_INT);
$y=filter_input(INPUT_GET,'y',FILTER_VALIDATE_INT);
if($x===false||$x===null||$y===false||$y===null||$x < -720||$x > 719||$y < -340||$y > 339) json(['error'=>'Ungültiger Kartenausschnitt'],400);
$directory=__DIR__.'/cache/water-coast';
if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)) json(['error'=>'Küstendaten nicht verfügbar'],503);
$file=$directory.'/'.$x.'-'.$y.'.json';
if(is_file($file)&&filemtime($file)>time()-90*86400){header('Content-Type: application/json');readfile($file);exit;}
$lock=fopen($file.'.lock','c');
if(!$lock||!flock($lock,LOCK_EX)) json(['error'=>'Küstendaten nicht verfügbar'],503);
if(is_file($file)&&filemtime($file)>time()-90*86400){header('Content-Type: application/json');readfile($file);exit;}
$west=$x/4-.25;$south=$y/4-.25;$east=$x/4+.5;$north=$y/4+.5;
$box=implode(',',[$south,$west,$north,$east]);
$query='[out:json][timeout:20];way["natural"="coastline"]('.$box.');out geom;';
$curl=curl_init('https://overpass-api.de/api/interpreter');
curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['data'=>$query]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_USERAGENT=>'ReiseDokumentation/1.0 (geographic coastline cache)',CURLOPT_SSL_VERIFYPEER=>true]);
$raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
$source=is_string($raw)?json_decode($raw,true):null;
if($status!==200||!is_array($source['elements']??null)||isset($source['remark'])) {
  // A known coastline remains useful if refreshing the external source fails.
  if(is_file($file)){header('Content-Type: application/json');readfile($file);exit;}
  json(['error'=>'Küstendaten vorübergehend nicht verfügbar'],503);
}
$coast=[];
foreach($source['elements'] as $element){if(!isset($element['geometry']))continue;$line=[];foreach($element['geometry'] as $point)$line[]=[(float)$point['lon'],(float)$point['lat']];if(count($line)>1)$coast[]=$line;}
$result=['bounds'=>[$west,$south,$east,$north],'coast'=>$coast,'source'=>'OpenStreetMap contributors, ODbL'];
$encoded=json_encode($result,JSON_UNESCAPED_SLASHES);
$temporary=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
if(file_put_contents($temporary,$encoded)!==false)rename($temporary,$file);
flock($lock,LOCK_UN);fclose($lock);
json($result);
