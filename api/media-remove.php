<?php
declare(strict_types=1);
// Remove single photos/videos from a trip ("Aus Reise entfernen") or move them to another trip
// ("In andere Reise verschieben"). Only the trip's reference changes; the original file in OneDrive or
// on disk is never touched. Stations that become empty disappear unless they carry an own text. The
// photo automation does not bring an item back, because its state remembers every capture it has
// already processed.
function media_selected_ids(array $ids): array {
  $ids=array_values(array_unique(array_filter(array_map('strval',$ids),static fn($id)=>$id!==''&&strlen($id)<=191)));
  if(!$ids||count($ids)>500)throw new RuntimeException('Bitte eine bis 500 Aufnahmen auswählen.',422);
  return $ids;
}
function media_lock_trip(PDO $db,string $trip): void {
  $q=$db->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE');$q->execute([$trip]);if(!$q->fetchColumn())throw new RuntimeException('Reise nicht gefunden.',404);
}
/** After items left a trip: refresh or drop their stations, renumber, update the trip totals. */
function media_cleanup_trip(PDO $db,string $trip,array $stopIds): int {
  $removedStations=0;
  foreach(array_keys($stopIds) as $stopId){
    $q=$db->prepare('SELECT MIN(taken_at) AS first_at,MAX(taken_at) AS last_at,COUNT(*) AS media_count FROM media_items WHERE trip_id=? AND stop_id=?');$q->execute([$trip,$stopId]);$range=$q->fetch();
    if((int)$range['media_count']>0){$q=$db->prepare('UPDATE trip_stops SET starts_at=?,ends_at=? WHERE trip_id=? AND id=?');$q->execute([$range['first_at'],$range['last_at'],$trip,$stopId]);continue;}
    // An emptied station keeps its own text; without text it goes.
    $q=$db->prepare("DELETE FROM trip_stops WHERE trip_id=? AND id=? AND (summary IS NULL OR TRIM(summary)='')");$q->execute([$trip,$stopId]);$removedStations+=$q->rowCount();
  }
  media_renumber_stations($db,$trip);
  media_trip_totals($db,$trip);
  return $removedStations;
}
function media_renumber_stations(PDO $db,string $trip): void {
  $q=$db->prepare('SELECT id,position FROM trip_stops WHERE trip_id=? ORDER BY starts_at IS NULL,starts_at,position,id FOR UPDATE');$q->execute([$trip]);$all=$q->fetchAll();
  $changed=false;$max=0;foreach($all as $position=>$stop){$max=max($max,(int)$stop['position']);if((int)$stop['position']!==$position)$changed=true;}
  if(!$changed)return;
  // Move every index above the occupied range before assigning new numbers.
  $q=$db->prepare('UPDATE trip_stops SET position=position+? WHERE trip_id=?');$q->execute([$max+count($all)+1,$trip]);
  $q=$db->prepare('UPDATE trip_stops SET position=? WHERE trip_id=? AND id=?');foreach($all as $position=>$stop)$q->execute([$position,$trip,$stop['id']]);
}
function media_trip_totals(PDO $db,string $trip): void {
  $q=$db->prepare('UPDATE trips SET media_count=(SELECT COUNT(*) FROM media_items WHERE trip_id=?),starts_on=(SELECT DATE(MIN(taken_at)) FROM media_items WHERE trip_id=?),ends_on=(SELECT DATE(MAX(taken_at)) FROM media_items WHERE trip_id=?),updated_at=NOW() WHERE id=?');$q->execute([$trip,$trip,$trip,$trip]);
}
function media_remove(PDO $db,string $trip,array $ids): array {
  $ids=media_selected_ids($ids);
  $db->beginTransaction();
  try{
    media_lock_trip($db,$trip);
    $find=$db->prepare('SELECT id,stop_id FROM media_items WHERE trip_id=? AND id=? FOR UPDATE');
    $clear=$db->prepare('UPDATE trip_day_summaries SET photo_media_id=NULL WHERE trip_id=? AND photo_media_id=?');
    $delete=$db->prepare('DELETE FROM media_items WHERE trip_id=? AND id=?');
    $removed=[];$stops=[];
    foreach($ids as $id){
      $find->execute([$trip,$id]);$item=$find->fetch();if(!$item)continue;
      $clear->execute([$trip,$id]);$delete->execute([$trip,$id]);$removed[]=$id;if($item['stop_id']!==null)$stops[(string)$item['stop_id']]=true;
    }
    if(!$removed)throw new RuntimeException('Die Aufnahmen gehören nicht mehr zu dieser Reise. Bitte neu laden.',409);
    $removedStations=media_cleanup_trip($db,$trip,$stops);
    $db->commit();
  }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
  if(function_exists('remove_cached_thumbnails'))foreach($removed as $id)remove_cached_thumbnails($id);
  return ['ok'=>true,'removed'=>count($removed),'removed_stations'=>$removedStations];
}
/**
 * Move photos/videos to another trip. In the target they join a station of the same day within about
 * one kilometre (as an import would place them); otherwise a new station named after the day is created.
 * A capture the target already contains is only taken out of the source trip.
 */
function media_move(PDO $db,string $trip,string $target,array $ids): array {
  require_once __DIR__.'/capture-time.php';
  require_once __DIR__.'/sync-trip-media.php';
  $ids=media_selected_ids($ids);
  if($trip===$target)throw new RuntimeException('Bitte eine andere Reise als Ziel wählen.',422);
  $db->beginTransaction();
  try{
    // Lock both trips in a fixed order so two opposite moves cannot deadlock.
    $order=[$trip,$target];sort($order);foreach($order as $id)media_lock_trip($db,$id);
    $find=$db->prepare('SELECT * FROM media_items WHERE trip_id=? AND id=? FOR UPDATE');
    $items=[];foreach($ids as $id){$find->execute([$trip,$id]);$item=$find->fetch();if($item)$items[]=$item;}
    if(!$items)throw new RuntimeException('Die Aufnahmen gehören nicht mehr zu dieser Reise. Bitte neu laden.',409);
    $twin=$db->prepare('SELECT id FROM media_items WHERE trip_id=? AND (onedrive_item_id=? OR (source_provider<=>? AND source_item_id<=>? AND source_item_id IS NOT NULL)) LIMIT 1');
    $moving=[];$duplicates=[];
    foreach($items as $item){
      $twin->execute([$target,$item['onedrive_item_id'],$item['source_provider']??null,$item['source_item_id']??null]);
      if($twin->fetchColumn())$duplicates[]=$item;else $moving[]=$item;
    }
    $q=$db->prepare('SELECT * FROM trip_stops WHERE trip_id=? ORDER BY position FOR UPDATE');$q->execute([$target]);$stops=$q->fetchAll();
    $candidates=array_map(static fn($item)=>['id'=>(string)$item['id'],'name'=>$item['name'],'location'=>['latitude'=>$item['latitude'],'longitude'=>$item['longitude']],'_capture'=>['date'=>$item['taken_at'],'source'=>$item['capture_source']]],$moving);
    $plan=$candidates?sync_addition_plan($stops,$candidates,'uuid'):['stops'=>[],'new_stops'=>[],'media'=>[]];
    if($plan['new_stops']){
      $max=0;foreach($stops as $stop)$max=max($max,(int)$stop['position']);
      $q=$db->prepare('UPDATE trip_stops SET position=position+? WHERE trip_id=?');$q->execute([$max+count($plan['stops'])+1,$target]);
      $restore=$db->prepare('UPDATE trip_stops SET position=? WHERE trip_id=? AND id=?');foreach($stops as $stop)$restore->execute([$plan['stops'][$stop['id']]['position'],$target,$stop['id']]);
      $insert=$db->prepare('INSERT INTO trip_stops(id,trip_id,position,name,latitude,longitude,starts_at,ends_at) VALUES(?,?,?,?,?,?,?,?)');
      foreach(array_keys($plan['new_stops']) as $stopId){$new=$plan['stops'][$stopId];$insert->execute([$stopId,$target,$new['position'],$new['name'],$new['latitude'],$new['longitude'],$new['starts_at'],$new['ends_at']]);}
    }
    $sourceStops=[];$targetStops=[];
    $clear=$db->prepare('UPDATE trip_day_summaries SET photo_media_id=NULL WHERE trip_id=? AND photo_media_id=?');
    $move=$db->prepare('UPDATE media_items SET trip_id=?,stop_id=? WHERE trip_id=? AND id=?');
    foreach($plan['media'] as $planned){
      $item=null;foreach($moving as $candidate)if((string)$candidate['id']===$planned['id'])$item=$candidate;
      $clear->execute([$trip,$item['id']]);$move->execute([$target,$planned['_stop_id'],$trip,$item['id']]);
      if($item['stop_id']!==null)$sourceStops[(string)$item['stop_id']]=true;$targetStops[$planned['_stop_id']]=true;
    }
    $delete=$db->prepare('DELETE FROM media_items WHERE trip_id=? AND id=?');
    foreach($duplicates as $item){$clear->execute([$trip,$item['id']]);$delete->execute([$trip,$item['id']]);if($item['stop_id']!==null)$sourceStops[(string)$item['stop_id']]=true;}
    $removedStations=media_cleanup_trip($db,$trip,$sourceStops);
    media_cleanup_trip($db,$target,$targetStops);
    $db->commit();
  }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
  return ['ok'=>true,'moved'=>count($moving),'duplicates'=>count($duplicates),'removed_stations'=>$removedStations,'new_stations'=>count($plan['new_stops'])];
}
