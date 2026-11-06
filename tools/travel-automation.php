<?php
declare(strict_types=1);
// Installed under tools/. No HTTP worker or private configuration in the webroot.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);ini_set('display_errors','0');set_time_limit(600);
require_once __DIR__.'/../api/travel-automation.php';
require_once __DIR__.'/../api/travel-automation-graph.php';
require_once __DIR__.'/../api/travel-automation-settings.php';
require_once __DIR__.'/../api/automation-original-reference.php';
try{
    $args=$argv;array_shift($args);$mode=array_shift($args);if(!in_array($mode,['validate','check','dry-run','run','night','status','install-schema'],true)||count($args)!==2||$args[0]!=='--config')throw new RuntimeException('usage_mode_--config_private_settings_json');
    $root=realpath(__DIR__.'/..');$settingsPath=travel_settings_path($root,$args[1]);
    $inside=static fn(string $path,string $base):bool=>str_starts_with(strtolower(str_replace('\\','/',$path)).'/',rtrim(strtolower(str_replace('\\','/',$base)),'/').'/');
    if($inside($settingsPath,$root)||preg_match('~[\\\\/](?:public_html|wwwroot|htdocs)(?:[\\\\/]|$)~i',$settingsPath))throw new RuntimeException('settings_must_be_outside_webroot');
    $s=travel_settings(json_decode((string)file_get_contents($settingsPath),true,32,JSON_THROW_ON_ERROR));
    if($mode==='night'){if(!travel_is_night($s,new DateTimeImmutable('now'))){echo '{"status":"outside_night_window","jobsStarted":false}'.PHP_EOL;exit(0);}$mode='run';}
    if($mode==='validate'){echo '{"status":"configuration_valid","jobsStarted":false}'.PHP_EOL;exit(0);}
    $private=dirname($settingsPath).'/travel-v2-'.substr(hash('sha256',strtolower($s['ownerEmail'])),0,16);
    if(!is_dir($private)&&!mkdir($private,0700))throw new RuntimeException('private_directory_unavailable');
    if($inside(realpath($private),$root)||preg_match('~[\\\\/](?:public_html|wwwroot|htdocs)(?:[\\\\/]|$)~i',realpath($private)))throw new RuntimeException('state_must_be_outside_webroot');
    $lock=fopen($private.'/worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){echo '{"status":"busy"}'.PHP_EOL;exit(0);}
    if($mode==='status'){$path=$private.'/status.json';echo is_file($path)?file_get_contents($path).PHP_EOL:'{"status":"not_run"}'.PHP_EOL;exit(0);}
    if($mode==='run'&&!$s['enabled']){echo '{"status":"disabled"}'.PHP_EOL;exit(0);}
    $config=require $root.'/api/config.php';$dbFactory=static fn():PDO=>new PDO($config['db_dsn'],$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db=$dbFactory();
    if($mode==='install-schema'){
        foreach(['migration-automation.sql','migration-travel-automation.sql','migration-original-references.sql'] as $file){
            $sql=preg_replace('/^--[^\r\n]*(?:\r?\n|$)/m','',(string)file_get_contents($root.'/api/'.$file));
            foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement){if(!preg_match('/^CREATE TABLE IF NOT EXISTS (automation_(connections|media_map)|travel_automation_connections|original_media_memberships)\s*\(/',$statement))throw new RuntimeException('unexpected_schema_statement');$db->exec($statement);}
        }
        echo '{"status":"schema_ready","jobsEnabled":false}'.PHP_EOL;exit(0);
    }
    if($mode==='dry-run')[$owner,$token]=travel_read_connection($db,$config,$s['ownerEmail']);
    else {foreach(['travel_automation_connections','automation_media_map'] as $table)$db->query('SELECT 1 FROM '.$table.' LIMIT 1');[$owner,$token]=travel_connection($db,$config,$s['ownerEmail']);}
    $graph=new TravelGraph($token);
    if(($graph->get('me?$select=id')['id']??null)!==$owner['account_id'])throw new RuntimeException('account_mismatch');
    $drive=$graph->get('me/drive?$select=id');if($mode==='dry-run')$owner['drive_id']=$drive['id']??null;if(empty($owner['drive_id'])||($drive['id']??null)!==$owner['drive_id'])throw new RuntimeException('drive_identity_changed');
    $rootItem=$graph->get('me/drive/root?$select='.TravelGraph::FIELDS);
    if(isset($rootItem['parentReference']['driveId'])&&$rootItem['parentReference']['driveId']!==$owner['drive_id'])throw new RuntimeException('drive_identity_changed');
    $rootItem['parentReference']['driveId']=$owner['drive_id'];$scope=new TravelOneDriveScope($rootItem,$s['sourceFolder']);
    if($mode!=='dry-run'&&$rootItem['id']!==$owner['root_item_id'])throw new RuntimeException('drive_root_changed');
    $source=$graph->resolve($rootItem,$s['sourceFolder'],$scope,false);if($source===null)throw new RuntimeException('camera_folder_missing');
    if($mode==='check'){echo json_encode(['status'=>'ready','enabled'=>$s['enabled'],'smallPhotos'=>$s['smallPhotos'],'timezone'=>$s['timezone'],'sourceFolder'=>$s['sourceFolder'],'radiusKm'=>$s['radiusKm']],JSON_UNESCAPED_SLASHES).PHP_EOL;exit(0);}
    if($mode==='run'){
        $stateId=hash('sha256',realpath($private));$q=$db->prepare('UPDATE travel_automation_connections SET worker_state_id=? WHERE user_id=? AND worker_state_id IS NULL');$q->execute([$stateId,$owner['id']]);
        $q=$db->prepare('SELECT worker_state_id FROM travel_automation_connections WHERE user_id=?');$q->execute([$owner['id']]);if(!hash_equals($stateId,(string)$q->fetchColumn()))throw new RuntimeException('worker_state_directory_changed');
    }
    $metadata=static function(array $chain)use($graph,$token,$scope,$private,$s,$root):array{$read=pipeline_metadata(fn($path)=>$graph->get($path),$token,$chain,$scope,TravelGraph::FIELDS,$private,$s['exiftool'],$s['perl']);$m=$read['metadata'];$read['place']=isset($m['latitude'],$m['longitude'])?pipeline_place((float)$m['latitude'],(float)$m['longitude'],$root.'/map-labels'):'Reise';return $read;};
    // Originals stay in place: verify the current source version/hash and bind it to the journey.
    $register=static function(array $job,array $trip)use($graph,$rootItem,$owner,$dbFactory,$token,$private,$s):array{
        $jobScope=new TravelOneDriveScope($rootItem,$job['sourceFolder']);
        return automation_register_original($dbFactory(),(int)$owner['id'],$token,$job,$trip,$jobScope,fn($path)=>$graph->get($path),TravelGraph::FIELDS,$private,$s['exiftool'],$s['perl']);
    };
    $backup=static function(string $day)use($private,$dbFactory,$owner):void{$path=$private.'/catalog-before-'.$day.'.json';if(is_file($path))return;$db=$dbFactory();$db->beginTransaction();try{$q=$db->prepare('SELECT * FROM trips WHERE user_id=?');$q->execute([$owner['id']]);$data=['trips'=>$q->fetchAll(PDO::FETCH_ASSOC)];foreach(['trip_stops','media_items','trip_day_summaries'] as $table){$q=$db->prepare('SELECT x.* FROM '.$table.' x JOIN trips t ON t.id=x.trip_id WHERE t.user_id=?');$q->execute([$owner['id']]);$data[$table]=$q->fetchAll(PDO::FETCH_ASSOC);}$db->commit();travel_save($path,$data);}catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}};
    $runner=new TravelAutomationRunner($s,$private,['account'=>$owner['account_id'],'drive'=>$owner['drive_id'],'root'=>$rootItem['id']],fn($chain,$next)=>$graph->children($chain,$next,$scope),$metadata,$register,$backup);
    echo json_encode($runner->run($source,new DateTimeImmutable('now'),$mode==='dry-run'),JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $error){$reason=travel_reason($error);if(isset($private)&&is_dir($private)&&in_array($mode??'', ['run','dry-run'],true))travel_save($private.($mode==='dry-run'?'/preview-status.json':'/status.json'),['at'=>gmdate(DATE_ATOM),'status'=>'error','reason'=>$reason]);echo json_encode(['status'=>'error','reason'=>$reason]).PHP_EOL;exit(1);}
