<?php
require_once __DIR__.'/capture-time.php';
// Pure planning functions are also used by the regression checks.
function capture_snapshot(PDO $db, string $trip, bool $lock=false): array {
  $result=[];
  foreach (['trips'=>'id','trip_stops'=>'trip_id','media_items'=>'trip_id','trip_day_summaries'=>'trip_id'] as $table=>$key) {
    $order=$table==='trip_day_summaries'?'day_date':'id';
    $q=$db->prepare("SELECT * FROM $table WHERE $key=? ORDER BY $order".($lock?' FOR UPDATE':''));
    $q->execute([$trip]);$result[$table]=$q->fetchAll();
  }
  return $result;
}
function capture_fingerprint(array $snapshot): string { return hash('sha256',json_encode($snapshot)); }
function capture_plan(array $snapshot, array $dates, array $sources=[]): array {
  $stops=array_column($snapshot['trip_stops'],null,'id');$buckets=[];$changes=[];$media=[];$dayMap=[];$missing=[];$unknown=[];
  foreach ($snapshot['media_items'] as $item) {
    $oldStop=$stops[$item['stop_id']]??null;
    $oldDay=substr((string)($oldStop['starts_at']??$item['taken_at']),0,10);
    if (!array_key_exists($item['onedrive_item_id'],$dates)) $missing[]=$item['name'];
    $manual=($item['capture_source']??null)==='manual';
    $taken=$manual?$item['taken_at']:(array_key_exists($item['onedrive_item_id'],$dates)?$dates[$item['onedrive_item_id']]:$item['taken_at']);
    if ($taken===null) $unknown[]=$item['name'];
    $day=substr((string)$taken,0,10);
    if ($oldDay!=='') $dayMap[$oldDay][$day]=true;
    // A user's final correction takes precedence over every automatic source.
    $source=$manual?null:($sources[$item['onedrive_item_id']]??null);
    if ($taken!==$item['taken_at']) $changes[]=['name'=>$item['name'],'station'=>$oldStop['name']??'Ohne Station','before'=>$item['taken_at'],'after'=>$taken,'source'=>$source['source']??'unknown'];
    if ($source!==null) {$item['capture_source']=$source['source'];$item['capture_details']=json_encode($source,JSON_UNESCAPED_UNICODE);}
    $item['taken_at']=$taken;$media[$item['id']]=$item;
    $key=$item['stop_id']??'unassigned';$buckets[$key][$day][]=$item['id'];
  }
  $planned=[];
  foreach ($buckets as $key=>$days) {
    $original=$stops[$key]??['id'=>null,'trip_id'=>$snapshot['trips'][0]['id'],'position'=>0,'name'=>'Aufnahmen','latitude'=>null,'longitude'=>null,'starts_at'=>null,'ends_at'=>null,'summary'=>null,'transport_to_next'=>null];
    ksort($days);$oldDay=substr((string)$original['starts_at'],0,10);
    $keep=array_key_exists($oldDay,$days)?$oldDay:array_key_first($days);
    foreach ($days as $day=>$ids) {
      $stop=$original;$stop['id']=($day===$keep&&$original['id'])?$original['id']:'new-'.count($planned);
      $times=array_values(array_filter(array_map(fn($id)=>$media[$id]['taken_at'],$ids),fn($v)=>$v!==null));sort($times);
      $stop['starts_at']=$times[0]??null;$stop['ends_at']=$times?end($times):null;
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$stop['name'])) $stop['name']=$day?:'Ohne Aufnahmedatum';
      // Preserve the original text and location on every fragment of a split station.
      if ($day!==$keep) $stop['transport_to_next']=null;
      foreach ($ids as $id) $media[$id]['stop_id']=$stop['id'];
      $planned[]=$stop;
    }
    unset($stops[$key]);
  }
  // Manually created stops without media remain intact.
  $planned=array_merge($planned,array_values($stops));
  usort($planned,fn($a,$b)=>strcmp($a['starts_at']??'9999',$b['starts_at']??'9999') ?: ($a['position']<=>$b['position']) ?: strcmp($a['id'],$b['id']));
  foreach ($planned as $i=>&$stop) $stop['position']=$i;
  unset($stop);
  $annotations=[];$allDays=[];
  foreach ($planned as $stop) if ($stop['starts_at']) $allDays[substr($stop['starts_at'],0,10)]=true;
  foreach ($snapshot['trip_day_summaries'] as $row) {
    $targets=array_keys($dayMap[$row['day_date']]??[$row['day_date']=>true]);
    $target=count($targets)===1&&$targets[0]!==''?$targets[0]:null;
    $annotations[]=['before'=>$row['day_date'],'suggested'=>$target,'summary'=>$row['summary'],'photo_media_id'=>$row['photo_media_id'],'photo_day'=>isset($media[$row['photo_media_id']??''])?substr((string)$media[$row['photo_media_id']]['taken_at'],0,10):null];
  }
  $times=array_values(array_filter(array_column($media,'taken_at'),fn($v)=>$v!==null));sort($times);
  return ['media'=>array_values($media),'stops'=>$planned,'changes'=>$changes,'missing'=>$missing,'unknown'=>$unknown,'annotations'=>$annotations,'days'=>array_keys($allDays),'starts_on'=>$times?substr($times[0],0,10):null,'ends_on'=>$times?substr(end($times),0,10):null];
}
function capture_annotations(array $snapshot, array $plan, array $choices): array {
  $result=[];$used=[];
  foreach ($snapshot['trip_day_summaries'] as $row) {
    $day=$choices[$row['day_date']]??null;
    if (!is_string($day)||!in_array($day,array_merge($plan['days'],[$row['day_date']]),true)) throw new RuntimeException('Bitte jedem vorhandenen Tagestext bzw. Tagesbild einen Tag zuordnen.');
    if (isset($used[$day])) throw new RuntimeException('Zwei Tagesinhalte dürfen nicht auf denselben Tag verschoben werden. Bitte die Zuordnung prüfen.');
    $used[$day]=true;$row['day_date']=$day;$result[]=$row;
  }
  return $result;
}
function capture_insert(PDO $db, string $table, array $row): void {
  $fields=array_keys($row);$q=$db->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$fields).'`) VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');$q->execute(array_values($row));
}
function handle_capture_dates(PDO $db, array $config, array $input): never {
  require_trip_editor($db,(string)($input['trip']??''));
  $trip=(string)($input['trip']??'');$mode=$input['mode']??'preview';
  if ($mode==='resolve') {
    $pending=$_SESSION['capture_dates'][$trip]??null;
    if (!$pending||$pending['expires']<time()||!hash_equals($pending['token'],(string)($input['token']??''))) json(['error'=>'Die Prüfung ist abgelaufen. Bitte erneut starten.'],409);
    $token=graph_access_token($db,$config,(int)$pending['owner']);
    if (!$token) json(['error'=>'Bitte erneut mit Microsoft anmelden.'],409);
    try {
      // Small requests keep large travel folders below the web-server timeout.
      for ($n=0;$n<2&&$pending['queue'];$n++) {
        $item=$pending['queue'][0];
        $source=capture_resolve_original($item,$token,(int)$pending['owner']);
        $pending['sources'][$item['id']]=$source;$pending['dates'][$item['id']]=$source['date'];array_shift($pending['queue']);
      }
    } catch (Throwable $error) {json(['error'=>$error->getMessage()],502);}
    $pending['expires']=time()+1800;$_SESSION['capture_dates'][$trip]=$pending;
    if ($pending['queue']) json(['pending'=>true,'token'=>$pending['token'],'remaining'=>count($pending['queue']),'total'=>$pending['total']]);
    capture_preview_response($db,$trip,$pending);
  }
  if ($mode==='apply') {
    $pending=$_SESSION['capture_dates'][$trip]??null;
    if (!$pending||$pending['expires']<time()||!hash_equals($pending['token'],(string)($input['token']??''))) json(['error'=>'Die Vorschau ist abgelaufen. Bitte erneut prüfen.'],409);
    try {
      $db->beginTransaction();$snapshot=capture_snapshot($db,$trip,true);
      if (!hash_equals($pending['fingerprint'],capture_fingerprint($snapshot))) throw new RuntimeException('Die Reise wurde inzwischen geändert. Bitte eine neue Vorschau laden.');
      if (!empty($pending['queue'])) throw new RuntimeException('Die EXIF-Prüfung ist noch nicht abgeschlossen.');
      foreach ($pending['sources']??[] as $source) if ($source['conflict']&&($input['acknowledge_conflicts']??false)!==true) throw new RuntimeException('Bitte die Abweichungen zwischen Metadaten und Dateinamen bestätigen.');
      $plan=capture_plan($snapshot,$pending['dates'],$pending['sources']??[]);
      $annotations=capture_annotations($snapshot,$plan,is_array($input['day_choices']??null)?$input['day_choices']:[]);
      // Store the complete original trip outside the public website before any mutation.
      $directory=$config['capture_backup_directory']??dirname(__DIR__,3).'/reisen-date-backups';
      if (!is_dir($directory)&&!mkdir($directory,0700,true)) throw new RuntimeException('Die Sicherung konnte nicht angelegt werden.');
      $backup='capture-'.bin2hex(random_bytes(16)).'.json';
      $payload=json_encode(['created_at'=>gmdate('c'),'snapshot'=>$snapshot],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
      if (file_put_contents($directory.'/'.$backup,$payload,LOCK_EX)!==strlen($payload)) throw new RuntimeException('Die Sicherung konnte nicht vollständig gespeichert werden.');
      chmod($directory.'/'.$backup,0600);
      $offset=1+max(array_merge([count($plan['stops'])],array_column($snapshot['trip_stops'],'position')));
      $q=$db->prepare('UPDATE trip_stops SET position=position+? WHERE trip_id=?');$q->execute([$offset,$trip]);
      $ids=[];$update=$db->prepare('UPDATE trip_stops SET position=?,name=?,starts_at=?,ends_at=? WHERE id=? AND trip_id=?');
      foreach ($plan['stops'] as $stop) {
        $key=$stop['id'];
        if (str_starts_with($key,'new-')) {$stop['id']=uuid();capture_insert($db,'trip_stops',$stop);}
        else $update->execute([$stop['position'],$stop['name'],$stop['starts_at'],$stop['ends_at'],$stop['id'],$trip]);
        $ids[$key]=$stop['id'];
      }
      $q=$db->prepare('UPDATE media_items SET taken_at=?,stop_id=?,capture_source=?,capture_details=? WHERE id=? AND trip_id=?');
      foreach ($plan['media'] as $item) $q->execute([$item['taken_at'],$ids[$item['stop_id']],$item['capture_source']??null,$item['capture_details']??null,$item['id'],$trip]);
      $q=$db->prepare('DELETE FROM trip_day_summaries WHERE trip_id=?');$q->execute([$trip]);
      foreach ($annotations as $row) capture_insert($db,'trip_day_summaries',$row);
      $q=$db->prepare('UPDATE trips SET starts_on=?,ends_on=?,capture_dates_updated_at=UTC_TIMESTAMP(),updated_at=NOW() WHERE id=?');$q->execute([$plan['starts_on'],$plan['ends_on'],$trip]);
      $db->commit();unset($_SESSION['capture_dates'][$trip]);
      json(['ok'=>true,'changed'=>count($plan['changes']),'backup'=>$backup]);
    } catch (Throwable $error) {
      if ($db->inTransaction()) $db->rollBack();
      json(['error'=>$error instanceof RuntimeException&&!($error instanceof PDOException)?$error->getMessage():'Die Korrektur konnte nicht gespeichert werden. Es wurden keine Reisedaten geändert.'],409);
    }
  }
  if ($mode!=='preview') json(['error'=>'Ungültige Aktion.'],422);
  $snapshot=capture_snapshot($db,$trip);
  if (!$snapshot['trips']) json(['error'=>'Reise nicht gefunden.'],404);
  $record=$snapshot['trips'][0];$segments=explode('/',trim((string)$record['source_folder']));
  if (array_intersect(['','.','..'],$segments)) json(['error'=>'Ungültiger OneDrive-Ordner.'],422);
  $token=graph_access_token($db,$config,(int)$record['user_id']);
  if (!$token) json(['error'=>'Bitte erneut mit Microsoft anmelden.'],409);
  $dates=[];$sources=[];$queue=[];$stored=array_column($snapshot['media_items'],null,'onedrive_item_id');
  try{$items=onedrive_collect_folder((string)$record['source_folder'],static function(string $url)use($token):array{
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$page=json_decode((string)$raw,true);
    if($status!==200||!is_array($page))throw new RuntimeException('OneDrive konnte nicht vollständig gelesen werden.');return $page;
  });}catch(Throwable $error){json(['error'=>'OneDrive konnte nicht vollständig gelesen werden. Es wurde nichts geändert.'],502);}
  foreach($items as $item)if(isset($stored[$item['id']])){
    if(($stored[$item['id']]['capture_source']??null)==='manual'){$dates[$item['id']]=$stored[$item['id']]['taken_at'];$sources[$item['id']]=['date'=>$dates[$item['id']],'source'=>'manual','conflict'=>false,'note'=>'Manuell korrigiert; bleibt erhalten'];}
    elseif(capture_graph_time($item)===null)$queue[]=$item;
    else{$sources[$item['id']]=capture_resolve($item);$dates[$item['id']]=$sources[$item['id']]['date'];}
  }
  $nonce=bin2hex(random_bytes(24));
  $pending=['token'=>$nonce,'expires'=>time()+1800,'fingerprint'=>capture_fingerprint($snapshot),'dates'=>$dates,'sources'=>$sources,'queue'=>$queue,'total'=>count($queue),'owner'=>(int)$record['user_id']];
  $_SESSION['capture_dates']=[$trip=>$pending];
  if ($queue) json(['pending'=>true,'token'=>$nonce,'remaining'=>count($queue),'total'=>count($queue)]);
  capture_preview_response($db,$trip,$pending);
}
function capture_preview_response(PDO $db,string $trip,array $pending): never {
  $snapshot=capture_snapshot($db,$trip);
  if (!hash_equals($pending['fingerprint'],capture_fingerprint($snapshot))) json(['error'=>'Die Reise wurde inzwischen geändert. Bitte erneut prüfen.'],409);
  $plan=capture_plan($snapshot,$pending['dates'],$pending['sources']);$evidence=[];
  foreach ($snapshot['media_items'] as $item) if (isset($pending['sources'][$item['onedrive_item_id']])) $evidence[]=array_merge(['name'=>$item['name']],$pending['sources'][$item['onedrive_item_id']]);
  json(['token'=>$pending['token'],'changes'=>$plan['changes'],'evidence'=>$evidence,'unknown'=>$plan['unknown'],'missing'=>$plan['missing'],'annotations'=>$plan['annotations'],'days'=>$plan['days'],'starts_on'=>$plan['starts_on'],'ends_on'=>$plan['ends_on'],'stations'=>array_map(fn($s)=>['name'=>$s['name'],'starts_at'=>$s['starts_at'],'ends_at'=>$s['ends_at']],$plan['stops'])]);
}
