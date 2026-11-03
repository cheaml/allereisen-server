<?php
const WATER_ROUTE_VERSION = '20261002-4';
function route_cache_point(mixed $point): array {
  if(!is_array($point)||count($point)!==2||!is_numeric($point[0]??null)||!is_numeric($point[1]??null))throw new InvalidArgumentException('Ungültige Koordinaten');
  $lat=(float)$point[0];$lon=(float)$point[1];
  if(!is_finite($lat)||!is_finite($lon)||abs($lat)>90||abs($lon)>180)throw new InvalidArgumentException('Ungültige Koordinaten');
  return [round($lat,7),round($lon,7)];
}
function route_cache_key(string $trip, array $from, array $to): string {
  return hash('sha256',json_encode([WATER_ROUTE_VERSION,$trip,$from,$to],JSON_PRESERVE_ZERO_FRACTION));
}
function route_cache_read(string $file): ?array {
  if(!is_file($file))return null;
  $data=json_decode((string)file_get_contents($file),true);
  if(!is_array($data)||!array_key_exists('points',$data))return null;
  // Coordinates and algorithm version are part of the key. Both water routes
  // and straight land connections remain valid until those inputs change.
  return ($data['version']??null)===WATER_ROUTE_VERSION?$data:null;
}
function handle_route_cache(PDO $db, int $userId, array $input): never {
  $post=$_SERVER['REQUEST_METHOD']==='POST';$params=$post?$input:$_GET;
  $trip=(string)($params['trip']??'');require_trip_access($db,$userId,$trip);session_write_close();
  if(($params['version']??'')!==WATER_ROUTE_VERSION)json(['error'=>'Veraltete Routenberechnung'],409);
  try {
    $from=route_cache_point($post?($params['from']??null):json_decode((string)($params['from']??''),true));
    $to=route_cache_point($post?($params['to']??null):json_decode((string)($params['to']??''),true));
  }catch(InvalidArgumentException $e){json(['error'=>$e->getMessage()],422);}
  $q=$db->prepare('SELECT COUNT(*) FROM trip_stops WHERE trip_id=? AND ((latitude=? AND longitude=?) OR (latitude=? AND longitude=?))');
  $q->execute([$trip,$from[0],$from[1],$to[0],$to[1]]);
  if((int)$q->fetchColumn()<($from===$to?1:2))json(['error'=>'Stationspositionen haben sich geändert'],409);
  $directory=__DIR__.'/cache/water-routes';
  $file=$directory.'/'.route_cache_key($trip,$from,$to).'.json';
  if(!$post)json(($data=route_cache_read($file))?['hit'=>true,'points'=>$data['points']]:['hit'=>false]);
  if(!array_key_exists('points',$params))json(['error'=>'Route fehlt'],422);
  $points=$params['points'];
  if($points!==null){
    if(!is_array($points)||count($points)<2||count($points)>2000)json(['error'=>'Ungültige Route'],422);
    try{$points=array_map('route_cache_point',$points);}catch(InvalidArgumentException $e){json(['error'=>$e->getMessage()],422);}
    if($points[0]!==$from||$points[count($points)-1]!==$to)json(['error'=>'Route passt nicht zu den Stationen'],422);
  }
  if(!is_dir($directory)&&!@mkdir($directory,0700,true)&&!is_dir($directory))json(['error'=>'Route konnte nicht gespeichert werden'],503);
  $lock=fopen($file.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))json(['error'=>'Route konnte nicht gespeichert werden'],503);
  if(!route_cache_read($file)) {
    $temporary=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
    if(file_put_contents($temporary,json_encode(['points'=>$points,'version'=>WATER_ROUTE_VERSION]),LOCK_EX)===false||!rename($temporary,$file))json(['error'=>'Route konnte nicht gespeichert werden'],503);
    chmod($file,0600);
  }
  flock($lock,LOCK_UN);fclose($lock);json(['ok'=>true]);
}
