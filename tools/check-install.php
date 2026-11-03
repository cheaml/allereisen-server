<?php
// CLI only: never expose diagnostics or customer configuration through HTTP.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$failed=0;
function result(string $name,bool $ok,string $detail=''): void {global $failed;echo ($ok?'OK   ':'FAIL ').$name.($detail!==''?' — '.$detail:'').PHP_EOL;if(!$ok)$failed++;}
result('PHP >= 8.3',PHP_VERSION_ID>=80300,PHP_VERSION);
foreach(['pdo_mysql','curl','openssl','mbstring','exif','fileinfo','session','json'] as $extension)result('Extension '.$extension,extension_loaded($extension));
result('AES-256-GCM',extension_loaded('openssl')&&in_array('aes-256-gcm',openssl_get_cipher_methods(),true));
$heic=class_exists('Imagick')&&count(Imagick::queryFormats('HEI*'))>0;
result('HEIC/HEIF-Anzeige', $heic,'Imagick mit ImageMagick/libheif erforderlich');
result('Cache beschreibbar',is_dir($root.'/api/cache')&&is_writable($root.'/api/cache'));
result('Temporärer Ordner beschreibbar',is_writable(sys_get_temp_dir()));
$schema=(string)file_get_contents($root.'/api/schema.sql');
$configFile=$argv[1]??$root.'/api/config.php';
if(!is_file($configFile)){result('Konfiguration',false,'api/config.php aus der Vorlage erstellen');exit(1);}
try{
 $config=require $configFile;
 result('HTTPS-App-URL',filter_var($config['app_url']??'',FILTER_VALIDATE_URL)!==false&&parse_url($config['app_url'],PHP_URL_SCHEME)==='https'&&!str_contains($config['app_url'],'example.invalid'));
 result('Verschlüsselungsschlüssel',preg_match('/^[a-f0-9]{64}$/i',$config['encryption_key']??'')===1);
 result('Admin-E-Mail',count(array_filter($config['admin_emails']??[],fn($e)=>filter_var($e,FILTER_VALIDATE_EMAIL)))>0);
 result('Microsoft OAuth',!empty($config['microsoft_client_id'])&&!empty($config['microsoft_client_secret'])&&$config['microsoft_client_id']!=='MICROSOFT_APPLICATION_ID');
 $db=new PDO($config['db_dsn'],$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 echo 'INFO Datenbankversion: '.$db->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
 preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?(\w+) \((.*?)\) ENGINE/s',$schema,$tables,PREG_SET_ORDER);
 foreach($tables as $table){
   preg_match_all('/^  ([a-z][a-z0-9_]*) /m',$table[2],$columns);
   $actual=$db->query('SHOW COLUMNS FROM `'.$table[1].'`')->fetchAll(PDO::FETCH_COLUMN);
   result('Schema '.$table[1],!array_diff($columns[1],$actual));
 }
}catch(Throwable $e){result('Konfiguration/Datenbank',false,'Prüfung fehlgeschlagen. Verbindung und Schema lokal prüfen; keine Zugangsdaten ausgegeben.');}
echo 'INFO OAuth-Anmeldung, HTTPS, HTTP-Zugriffsschutz und echtes HEIC müssen anschließend im Browser geprüft werden.'.PHP_EOL;
exit($failed?1:0);
