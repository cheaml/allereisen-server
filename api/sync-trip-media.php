<?php
// Incremental OneDrive sync: existing media and station edits remain intact.
function sync_media_type(array $item): ?string {
  if(isset($item['video'])||str_starts_with((string)($item['file']['mimeType']??''),'video/'))return 'video';
  if(isset($item['photo'])||str_starts_with((string)($item['file']['mimeType']??''),'image/'))return 'photo';
  return null;
}
function sync_snapshot(PDO $db,string $trip): array {
  $q=$db->prepare('SELECT id,user_id,source_folder,updated_at FROM trips WHERE id=?');$q->execute([$trip]);$record=$q->fetch();
  $q=$db->prepare('SELECT * FROM trip_stops WHERE trip_id=? ORDER BY position,id');$q->execute([$trip]);$stops=$q->fetchAll();
  $q=$db->prepare('SELECT id,onedrive_item_id,stop_id,taken_at FROM media_items WHERE trip_id=? ORDER BY id');$q->execute([$trip]);
  return ['trip'=>$record,'stops'=>$stops,'media'=>$q->fetchAll()];
}
function sync_collect(string $folder,callable $read,?array &$presentIds=null): array {
  require_once __DIR__.'/onedrive-items.php';
  $items=onedrive_collect_folder($folder,$read,$presentIds);$indexed=[];
  foreach($items as $item){$presentIds[$item['id']]=true;$indexed[$item['id']]=$item;}
  return $indexed;
}

function sync_distance(array $a,array $b): ?float {
  if($a['latitude']===null||$a['longitude']===null||$b['latitude']===null||$b['longitude']===null)return null;
  $dLat=deg2rad((float)$b['latitude']-(float)$a['latitude']);$dLon=deg2rad((float)$b['longitude']-(float)$a['longitude']);
  $h=sin($dLat/2)**2+cos(deg2rad((float)$a['latitude']))*cos(deg2rad((float)$b['latitude']))*sin($dLon/2)**2;
  return 12742*asin(min(1,sqrt($h)));
}
/** Media join a station of the same day within $radiusKm (default 1 km). */
function sync_addition_plan(array $existing,array $items,callable $newId,float $radiusKm=1.0): array {
  if(!is_finite($radiusKm)||$radiusKm<0.05||$radiusKm>50)throw new InvalidArgumentException('invalid_station_radius');
  usort($items,static fn($a,$b)=>strcmp(media_capture_date($a)??'9999',media_capture_date($b)??'9999')?:strcmp($a['id'],$b['id']));
  $stops=array_column($existing,null,'id');$new=[];$media=[];
  foreach($items as $item){
    $taken=media_capture_date($item);$day=$taken?substr($taken,0,10):null;
    $location=['latitude'=>is_numeric($item['location']['latitude']??null)?(float)$item['location']['latitude']:null,'longitude'=>is_numeric($item['location']['longitude']??null)?(float)$item['location']['longitude']:null];
    $target=null;$best=INF;
    foreach($stops as $id=>$stop){
      if(($stop['starts_at']?substr($stop['starts_at'],0,10):null)!==$day)continue;
      $distance=sync_distance($location,$stop);if($distance!==null&&$distance>$radiusKm)continue;
      // Multiple visits to a place on the same day use the closest time range.
      $gap=$taken&&$stop['starts_at']?max(0,strtotime($stop['starts_at'])-strtotime($taken),strtotime($taken)-strtotime($stop['ends_at']??$stop['starts_at'])):0;
      $score=$gap+($distance??0)*60;
      if($score<$best){$best=$score;$target=(string)$id;}
    }
    if($target===null){
      $target=$newId();$new[$target]=true;
      $stops[$target]=['id'=>$target,'position'=>count($stops),'name'=>$day??'Aufnahmen ohne Datum','latitude'=>$location['latitude'],'longitude'=>$location['longitude'],'starts_at'=>$taken,'ends_at'=>$taken,'summary'=>null,'transport_to_next'=>null];
    }else{
      if($taken!==null){$stops[$target]['starts_at']=min($stops[$target]['starts_at']??$taken,$taken);$stops[$target]['ends_at']=max($stops[$target]['ends_at']??$taken,$taken);}
    }
    $item['_stop_id']=$target;$media[]=$item;
  }
  // Preserve the curated order of existing stations. Insert new stations in
  // their chronological place; unknown dates go at the end.
  $ordered=array_column($existing,'id');
  $newIds=array_keys($new);usort($newIds,static fn($a,$b)=>strcmp($stops[$a]['starts_at']??'9999',$stops[$b]['starts_at']??'9999'));
  foreach($newIds as $id){$at=count($ordered);foreach($ordered as $i=>$other){if(($stops[$id]['starts_at']??'9999')<($stops[$other]['starts_at']??'9999')){$at=$i;break;}}array_splice($ordered,$at,0,[$id]);}
  foreach($ordered as $position=>$id)$stops[$id]['position']=$position;
  return ['stops'=>$stops,'new_stops'=>$new,'media'=>$media];
}
function sync_apply(PDO $db,string $trip,array $snapshot,array $present,array $plan): array {
  $db->beginTransaction();
  try{
    $q=$db->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE');$q->execute([$trip]);
    if(hash('sha256',json_encode(sync_snapshot($db,$trip)))!==hash('sha256',json_encode($snapshot)))throw new RuntimeException('Die Reise wurde während des Abgleichs geändert. Bitte erneut abgleichen.',409);
    $missing=array_values(array_filter($snapshot['media'],static fn($m)=>!isset($present[$m['onedrive_item_id']])));
    $positions=array_map(static fn($s)=>(int)$s['position'],$snapshot['stops']);
    if(!$missing&&!$plan['media']&&$positions===array_keys($positions)){
      $q=$db->prepare('UPDATE trips SET media_synced_at=UTC_TIMESTAMP() WHERE id=?');$q->execute([$trip]);$db->commit();
      return ['ok'=>true,'added_media'=>0,'added_stations'=>0,'removed_media'=>0,'removed_stations'=>0,'remaining_media'=>count($snapshot['media']),'removed_ids'=>[]];
    }
    $clear=$db->prepare('UPDATE trip_day_summaries SET photo_media_id=NULL WHERE trip_id=? AND photo_media_id=?');
    $delete=$db->prepare('DELETE FROM media_items WHERE trip_id=? AND id=?');
    foreach($missing as $item){$clear->execute([$trip,$item['id']]);$delete->execute([$trip,$item['id']]);}
    if($plan['new_stops']){
      $max=0;foreach($snapshot['stops'] as $stop)$max=max($max,(int)$stop['position']);
      $q=$db->prepare('UPDATE trip_stops SET position=position+? WHERE trip_id=?');$q->execute([$max+count($plan['stops'])+1,$trip]);
    }
    $insertStop=$db->prepare('INSERT INTO trip_stops(id,trip_id,position,name,latitude,longitude,starts_at,ends_at) VALUES(?,?,?,?,?,?,?,?)');
    $updateStop=$db->prepare('UPDATE trip_stops SET position=?,starts_at=?,ends_at=? WHERE trip_id=? AND id=?');
    foreach($plan['stops'] as $id=>$stop){
      if(isset($plan['new_stops'][$id]))$insertStop->execute([$id,$trip,$stop['position'],$stop['name'],$stop['latitude'],$stop['longitude'],$stop['starts_at'],$stop['ends_at']]);
      else $updateStop->execute([$stop['position'],$stop['starts_at'],$stop['ends_at'],$trip,$id]);
    }
    $insert=$db->prepare('INSERT INTO media_items(id,trip_id,stop_id,onedrive_item_id,name,media_type,taken_at,latitude,longitude,web_url,capture_source,capture_details) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach($plan['media'] as $item)$insert->execute([uuid(),$trip,$item['_stop_id'],$item['id'],substr((string)($item['name']??''),0,1024),sync_media_type($item),media_capture_date($item),$item['location']['latitude']??null,$item['location']['longitude']??null,$item['webUrl']??null,$item['_capture']['source'],json_encode($item['_capture'],JSON_UNESCAPED_UNICODE)]);
    $q=$db->prepare("DELETE FROM trip_stops WHERE trip_id=? AND NOT EXISTS(SELECT 1 FROM media_items m WHERE m.stop_id=trip_stops.id) AND (name REGEXP '^(Aufnahmeort|Aufnahmen)' OR name REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$') AND (summary IS NULL OR summary='')");$q->execute([$trip]);$removedStops=$q->rowCount();
    // Close numbering gaps after removals without violating a unique position
    // constraint while moving rows. Preserve the existing curated order.
    $q=$db->prepare('SELECT id,position FROM trip_stops WHERE trip_id=? ORDER BY position,id');$q->execute([$trip]);$ordered=$q->fetchAll();
    if($ordered){
      $offset=max(array_map(static fn($s)=>(int)$s['position'],$ordered))+count($ordered)+1;
      $q=$db->prepare('UPDATE trip_stops SET position=position+? WHERE trip_id=?');$q->execute([$offset,$trip]);
      $q=$db->prepare('UPDATE trip_stops SET position=? WHERE trip_id=? AND id=?');foreach($ordered as $position=>$stop)$q->execute([$position,$trip,$stop['id']]);
    }
    $q=$db->prepare('UPDATE trips SET media_count=(SELECT COUNT(*) FROM media_items WHERE trip_id=?),starts_on=(SELECT DATE(MIN(taken_at)) FROM media_items WHERE trip_id=?),ends_on=(SELECT DATE(MAX(taken_at)) FROM media_items WHERE trip_id=?),media_synced_at=UTC_TIMESTAMP(),updated_at=NOW() WHERE id=?');$q->execute([$trip,$trip,$trip,$trip]);
    $q=$db->prepare('SELECT COUNT(*) FROM media_items WHERE trip_id=?');$q->execute([$trip]);$count=(int)$q->fetchColumn();
    $db->commit();
    return ['ok'=>true,'added_media'=>count($plan['media']),'added_stations'=>count($plan['new_stops']),'removed_media'=>count($missing),'removed_stations'=>$removedStops,'remaining_media'=>$count,'removed_ids'=>array_column($missing,'id')];
  }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
function handle_trip_media_sync(PDO $db,array $config,array $input): never {
  require_trip_editor($db,(string)($input['trip']??''));$trip=(string)($input['trip']??'');$nonce=(string)($input['sync_token']??'');
  $snapshot=sync_snapshot($db,$trip);$record=$snapshot['trip'];if(!$record)json(['error'=>'Reise nicht gefunden.'],404);
  $folder=trim((string)$record['source_folder']);$segments=explode('/',$folder);
  if(str_starts_with($folder,'reise://'))json(['error'=>'Diese Reise verwendet Originalzuordnungen. Neue Aufnahmen werden durch die Fotoautomatik zugeordnet; ein Ordnerabgleich würde andere Aufnahmen einschließen.'],422);
  if($folder===''||array_intersect(['','.','..'],$segments))json(['error'=>'Für diese Reise ist kein gültiger OneDrive-Ordner hinterlegt.'],422);
  $token=graph_access_token($db,$config,(int)$record['user_id']);if(!$token)json(['error'=>'OneDrive-Verbindung ist abgelaufen. Bitte erneut mit Microsoft anmelden.'],409);
  if($nonce!==''){
    $pending=$_SESSION['media_sync']??null;
    if(!$pending||!hash_equals($pending['token'],$nonce)||$pending['expires']<time()||$pending['trip']!==$trip)json(['error'=>'Abgleich abgelaufen. Bitte erneut starten.'],409);
    if(json_encode($pending['snapshot'])!==json_encode($snapshot))json(['error'=>'Die Reise wurde während des Abgleichs geändert. Bitte erneut starten.'],409);
  }else{
    try{$present=sync_collect($folder,static function($url)use($token){$c=curl_init($url);curl_setopt_array($c,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);if($status!==200)throw new RuntimeException('OneDrive '.$status);return json_decode((string)$raw,true);},$presentIds);}
    catch(Throwable $error){json(['error'=>'OneDrive-Ordner konnte nicht vollständig gelesen werden. Es wurde nichts geändert.'],502);}
    $known=array_fill_keys(array_column($snapshot['media'],'onedrive_item_id'),true);$new=array_values(array_diff_key($present,$known));$queue=[];
    foreach($new as $i=>&$item){if(capture_graph_time($item)===null)$queue[]=$i;else $item['_capture']=capture_resolve($item);}unset($item);
    $pending=['token'=>bin2hex(random_bytes(24)),'trip'=>$trip,'snapshot'=>$snapshot,'present'=>$presentIds,'items'=>$new,'queue'=>$queue,'total'=>count($queue),'expires'=>time()+1800];
  }
  try{for($i=0;$i<2&&$pending['queue'];$i++){$index=$pending['queue'][0];$pending['items'][$index]['_capture']=capture_resolve_original($pending['items'][$index],$token,(int)$record['user_id']);array_shift($pending['queue']);}}
  catch(Throwable $error){json(['error'=>'Aufnahmedaten neuer Bilder konnten nicht vollständig geprüft werden. Es wurde nichts geändert.'],502);}
  if($pending['queue']){$pending['expires']=time()+1800;$_SESSION['media_sync']=$pending;json(['pending'=>true,'sync_token'=>$pending['token'],'remaining'=>count($pending['queue']),'total'=>$pending['total'],'new_media'=>count($pending['items'])]);}
  $plan=sync_addition_plan($snapshot['stops'],$pending['items'],'uuid');
  foreach($plan['new_stops'] as $id=>$_){$stop=&$plan['stops'][$id];if($stop['latitude']!==null&&$stop['longitude']!==null){$place=station_reverse_lookup((float)$stop['latitude'],(float)$stop['longitude'],$config);if($place)$stop['name']=station_import_name($place,$stop['name']);}unset($stop);}
  try{$result=sync_apply($db,$trip,$snapshot,$pending['present'],$plan);}
  catch(Throwable $error){json(['error'=>$error->getCode()===409?$error->getMessage():'Abgleich konnte nicht gespeichert werden. Es wurde nichts geändert.'],$error->getCode()===409?409:500);}
  unset($_SESSION['media_sync']);$removedCache=0;foreach($result['removed_ids'] as $id)$removedCache+=remove_cached_thumbnails($id);
  unset($result['removed_ids']);$result['removed_cached_thumbnails']=$removedCache+remove_orphan_cached_thumbnails($db);json($result);
}
