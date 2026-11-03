<?php
require_once __DIR__.'/capture-time.php';
require_once __DIR__.'/sync-trip-media.php';
function manual_reorder_stations(PDO $db,string $trip): void {
  $q=$db->prepare('SELECT id,position FROM trip_stops WHERE trip_id=? ORDER BY starts_at IS NULL,starts_at,position,id FOR UPDATE');$q->execute([$trip]);$stops=$q->fetchAll();
  $changed=false;$max=0;foreach($stops as $position=>$stop){$max=max($max,(int)$stop['position']);if((int)$stop['position']!==$position)$changed=true;}
  if(!$changed)return;
  // Move every index above the occupied range before assigning new numbers.
  $q=$db->prepare('UPDATE trip_stops SET position=position+? WHERE trip_id=?');$q->execute([$max+count($stops)+1,$trip]);
  $q=$db->prepare('UPDATE trip_stops SET position=? WHERE trip_id=? AND id=?');foreach($stops as $position=>$stop)$q->execute([$position,$trip,$stop['id']]);
}
function manual_media_time(PDO $db,int $editor,string $trip,string $media,string $value,?string $expected,?string $expectedSource): array {
  $date=capture_valid_time($value);
  if($date===null)throw new RuntimeException('Bitte ein gültiges Aufnahmedatum mit Uhrzeit eingeben.',422);
  $db->beginTransaction();
  try{
    $q=$db->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE');$q->execute([$trip]);if(!$q->fetchColumn())throw new RuntimeException('Reise nicht gefunden.',404);
    $q=$db->prepare('SELECT * FROM media_items WHERE trip_id=? AND id=? FOR UPDATE');$q->execute([$trip,$media]);$item=$q->fetch();if(!$item)throw new RuntimeException('Aufnahme nicht gefunden.',404);
    if($item['taken_at']!==$expected||($item['capture_source']??null)!==$expectedSource)throw new RuntimeException('Die Aufnahmezeit wurde inzwischen geändert. Bitte die Station neu laden.',409);
    $target=$item['stop_id'];
    if($target===null||substr((string)$item['taken_at'],0,10)!==substr($date,0,10)){
      $q=$db->prepare('SELECT * FROM trip_stops WHERE trip_id=? ORDER BY position FOR UPDATE');$q->execute([$trip]);$stops=$q->fetchAll();$source=null;foreach($stops as $stop)if($stop['id']===$item['stop_id'])$source=$stop;
      $candidate=['id'=>$item['onedrive_item_id'],'name'=>$item['name'],'location'=>['latitude'=>$source['latitude']??$item['latitude'],'longitude'=>$source['longitude']??$item['longitude']],'_capture'=>['date'=>$date,'source'=>'manual']];
      $plan=sync_addition_plan($stops,[$candidate],'uuid');$target=$plan['media'][0]['_stop_id'];
      if($plan['new_stops']){
        $max=0;foreach($stops as $stop)$max=max($max,(int)$stop['position']);
        $q=$db->prepare('UPDATE trip_stops SET position=position+? WHERE trip_id=?');$q->execute([$max+count($plan['stops'])+1,$trip]);
        $restore=$db->prepare('UPDATE trip_stops SET position=? WHERE trip_id=? AND id=?');foreach($stops as $stop)$restore->execute([$plan['stops'][$stop['id']]['position'],$trip,$stop['id']]);
        $new=$plan['stops'][$target];
        if($source&&!preg_match('/^(?:[0-9]{4}-[0-9]{2}-[0-9]{2}|Hauptort|Aufnahmen)/u',$source['name']))$new['name']=$source['name'];
        $q=$db->prepare('INSERT INTO trip_stops(id,trip_id,position,name,latitude,longitude,starts_at,ends_at) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$target,$trip,$new['position'],$new['name'],$new['latitude'],$new['longitude'],$date,$date]);
      }
    }
    $prior=json_decode((string)($item['capture_details']??''),true);
    $original=($item['capture_source']??null)==='manual'&&is_array($prior['original']??null)?$prior['original']:['taken_at'=>$item['taken_at'],'source'=>$item['capture_source'],'details'=>$prior];
    $details=['date'=>$date,'source'=>'manual','conflict'=>false,'original'=>$original,'edited_at'=>gmdate('c'),'edited_by'=>$editor,'previous_date'=>$item['taken_at']];
    $q=$db->prepare('UPDATE media_items SET taken_at=?,stop_id=?,capture_source=?,capture_details=? WHERE id=? AND trip_id=?');$q->execute([$date,$target,'manual',json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$media,$trip]);
    $q=$db->prepare('UPDATE trip_day_summaries SET photo_media_id=NULL WHERE trip_id=? AND photo_media_id=? AND day_date<>?');$q->execute([$trip,$media,substr($date,0,10)]);
    foreach(array_unique(array_filter([$item['stop_id'],$target])) as $stopId){
      $q=$db->prepare('SELECT MIN(taken_at) AS first_at,MAX(taken_at) AS last_at,COUNT(*) AS media_count FROM media_items WHERE trip_id=? AND stop_id=?');$q->execute([$trip,$stopId]);$range=$q->fetch();
      // Empty source stations retain their name, notes and previous day.
      if(!(int)$range['media_count'])continue;
      $q=$db->prepare('UPDATE trip_stops SET starts_at=?,ends_at=? WHERE trip_id=? AND id=?');$q->execute([$range['first_at'],$range['last_at'],$trip,$stopId]);
    }
    manual_reorder_stations($db,$trip);
    $q=$db->prepare('UPDATE trips SET starts_on=(SELECT DATE(MIN(taken_at)) FROM media_items WHERE trip_id=?),ends_on=(SELECT DATE(MAX(taken_at)) FROM media_items WHERE trip_id=?),updated_at=NOW() WHERE id=?');$q->execute([$trip,$trip,$trip]);
    $db->commit();return ['ok'=>true,'taken_at'=>$date,'capture_source'=>'manual','stop_id'=>$target,'moved'=>$target!==$item['stop_id']];
  }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
