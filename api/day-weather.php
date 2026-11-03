<?php
// Historical daily weather and terrain elevation for a representative day stop.
function day_weather(float $lat, float $lon, string $day, array $config = []): array {
  if (($config['weather_enabled'] ?? true) === false) throw new RuntimeException('Wetterdaten sind deaktiviert.');
  $recent=$day>=gmdate('Y-m-d',time()-5*86400);
  $base=$recent?($config['weather_forecast_url']??'https://api.open-meteo.com/v1/forecast'):($config['weather_archive_url']??'https://archive-api.open-meteo.com/v1/archive');
  if(parse_url($base,PHP_URL_SCHEME)!=='https')throw new RuntimeException('Ungültiger Wetterdienst.');
  $key=hash('sha256',json_encode([$lat,$lon,$day,2,$base]));
  $directory=__DIR__.'/cache/day-weather';
  if(!is_dir($directory))@mkdir($directory,0700,true);
  $file=$directory.'/'.$key.'.json';
  if(is_file($file)&&(!$recent||filemtime($file)>time()-3600)) {
    $cached=json_decode((string)file_get_contents($file),true);
    if(is_array($cached))return $cached;
  }
  $parameters=['latitude'=>$lat,'longitude'=>$lon,'start_date'=>$day,'end_date'=>$day,'daily'=>'temperature_2m_min,temperature_2m_max,weather_code','timezone'=>'auto'];
  if(!empty($config['weather_api_key']))$parameters['apikey']=$config['weather_api_key'];
  $url=$base.'?'.http_build_query($parameters);
  $curl=curl_init($url);
  curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_USERAGENT=>'ReiseDokumentation/1.0']);
  $raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
  $data=is_string($raw)?json_decode($raw,true):null;
  if($status!==200||!is_array($data)||($data['daily']['time'][0]??null)!==$day)throw new RuntimeException('Wetterdaten derzeit nicht verfügbar.');
  $result=['temperature_min'=>$data['daily']['temperature_2m_min'][0]??null,'temperature_max'=>$data['daily']['temperature_2m_max'][0]??null,'weather_code'=>$data['daily']['weather_code'][0]??null,'elevation'=>$data['elevation']??null];
  foreach($result as $value)if($value!==null&&!is_numeric($value))throw new RuntimeException('Ungültige Wetterdaten.');
  $temporary=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
  if(@file_put_contents($temporary,json_encode($result))!==false)@rename($temporary,$file);
  return $result;
}
