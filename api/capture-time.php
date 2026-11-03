<?php
require_once __DIR__.'/onedrive-items.php';
function capture_valid_time(string $value): ?string {
  if (!preg_match('/^(\d{4})[-:](\d{2})[-:](\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D',$value,$m)) return null;
  if (!checkdate((int)$m[2],(int)$m[3],(int)$m[1])||(int)$m[1]<1900||(int)$m[4]>23||(int)$m[5]>59||(int)$m[6]>59) return null;
  // Preserve recorded wall time. A filename has no timezone; never infer one.
  return "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
}
function capture_filename_time(string $name): ?string {
  $stem=pathinfo($name,PATHINFO_FILENAME);
  $patterns=[
    '/^(?:(?:IMG|VID|PXL)[_-])?(\d{4})(\d{2})(\d{2})[_-](\d{2})(\d{2})(\d{2})(?:\d{3})?(?=$|[_. -])/i',
    '/^(?:(?:IMG|VID)[_-])?(\d{4})-(\d{2})-(\d{2})[ _T](\d{2})[.-](\d{2})[.-](\d{2})(?=$|[_. -])/i'
  ];
  foreach ($patterns as $pattern) if (preg_match($pattern,$stem,$m)) return capture_valid_time("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]");
  return null;
}
function capture_graph_time(array $item): ?string {
  foreach ([$item['photo']['takenDateTime']??null,$item['video']['takenDateTime']??null] as $date) if (is_string($date)&&($valid=capture_valid_time($date))!==null) return $valid;
  return null;
}
function capture_resolve(array $item, ?string $exif=null, ?string $note=null): array {
  $graph=capture_graph_time($item);$filename=capture_filename_time((string)($item['name']??''));
  $exif=is_string($exif)?capture_valid_time($exif):null;
  $date=$graph??$exif??$filename;$source=$graph!==null?'onedrive':($exif!==null?'exif':($filename!==null?'filename':'unknown'));
  return ['date'=>$date,'source'=>$source,'filename_date'=>$filename,'conflict'=>$date!==null&&$filename!==null&&$date!==$filename,'note'=>$note];
}
function media_capture_date(array $item): ?string {
  return array_key_exists('_capture',$item)?$item['_capture']['date']:capture_graph_time($item);
}
function capture_exif_time(array $item,string $token,int $owner): array {
  $extension=strtolower(pathinfo((string)($item['name']??''),PATHINFO_EXTENSION));
  if (!in_array($extension,['jpg','jpeg','tif','tiff'],true)) return [null,'EXIF für dieses Dateiformat nicht auslesbar'];
  if (!function_exists('exif_read_data')) throw new RuntimeException('EXIF-Unterstützung auf dem Server fehlt.');
  if ((int)($item['size']??0)>32*1024*1024) return [null,'Original größer als 32 MB; EXIF nicht geprüft'];
  $directory=__DIR__.'/cache/capture-times';
  if (!is_dir($directory)&&!mkdir($directory,0700,true)) throw new RuntimeException('EXIF-Zwischenspeicher konnte nicht angelegt werden.');
  if (file_put_contents($directory.'/.htaccess',"Require all denied\n")===false) throw new RuntimeException('EXIF-Zwischenspeicher konnte nicht geschützt werden.');
  $key=hash('sha256',json_encode([$owner,$item['id'],$item['lastModifiedDateTime']??null,$item['size']??null]));
  $cache=$directory.'/'.$key.'.json';
  if (is_file($cache)) {$saved=json_decode((string)file_get_contents($cache),true);if (is_array($saved)&&array_key_exists('date',$saved)) return [$saved['date'],null];}
  $path=tempnam(sys_get_temp_dir(),'reise-exif-');if ($path===false) throw new RuntimeException('EXIF-Prüfung konnte nicht gestartet werden.');
  $file=fopen($path,'wb');$bytes=0;
  try {
    $ch=curl_init(onedrive_item_url((string)$item['id']).'/content');
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_MAXFILESIZE=>32*1024*1024,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use($file,&$bytes):int {$bytes+=strlen($chunk);return $bytes>32*1024*1024?0:fwrite($file,$chunk);}]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);fclose($file);$file=null;
    if (!$ok||$status!==200) throw new RuntimeException('Ein Originalfoto konnte für die EXIF-Prüfung nicht geladen werden. Bitte erneut versuchen.');
    $data=@exif_read_data($path,'EXIF',true);
    $raw=$data['EXIF']['DateTimeOriginal']??null;
    $date=is_string($raw)?capture_valid_time($raw):null;
    file_put_contents($cache,json_encode(['date'=>$date]),LOCK_EX);chmod($cache,0600);
    return [$date,null];
  } finally {if (is_resource($file)) fclose($file);unlink($path);}
}
function capture_resolve_original(array $item,string $token,int $owner): array {
  if (capture_graph_time($item)!==null) return capture_resolve($item);
  [$exif,$note]=isset($item['video'])?[null,'Kein OneDrive-Aufnahmedatum für dieses Video']:capture_exif_time($item,$token,$owner);
  return capture_resolve($item,$exif,$note);
}
