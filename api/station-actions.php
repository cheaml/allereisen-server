<?php
declare(strict_types=1);
function handle_station_actions(PDO $db,array $input):never {
 require_trip_editor($db,(string)(($_SERVER['REQUEST_METHOD']==='POST'?$input:$_GET)['trip']??''));
 $post=$_SERVER['REQUEST_METHOD']==='POST';
 if (!$post&&$_SERVER['REQUEST_METHOD']!=='GET') json(['error'=>'Methode nicht erlaubt'],405);
 $params=$post?$input:$_GET;$trip=(string)($params['trip']??'');$source=(string)($params['source']??'');
 if ($post&&($input['confirmed']??false)!==true) json(['error'=>'Bitte die Änderung bestätigen.'],422);
 if ($post&&!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) json(['error'=>'JSON-Anfrage erforderlich'],415);
 if ($post&&isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==rtrim((string)$GLOBALS['config']['app_url'],'/')) json(['error'=>'Ungültiger Ursprung'],403);
 try {
  $db->beginTransaction();
  $q=$db->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE');$q->execute([$trip]);if(!$q->fetchColumn())throw new RuntimeException('Reise nicht gefunden.',404);
  $q=$db->prepare('SELECT * FROM trip_stops WHERE trip_id=? ORDER BY position FOR UPDATE');$q->execute([$trip]);$stops=$q->fetchAll();
  $byId=array_column($stops,null,'id');$stop=$byId[$source]??null;if(!$stop)throw new RuntimeException('Station nicht gefunden.',404);
  $day=substr((string)($stop['starts_at']??''),0,10);
  $q=$db->prepare('SELECT stop_id,COUNT(*) AS n FROM media_items WHERE trip_id=? GROUP BY stop_id');$q->execute([$trip]);$counts=array_column($q->fetchAll(),'n','stop_id');
  $sameDay=array_values(array_filter($stops,static fn($s)=>$day!==''&&substr((string)($s['starts_at']??''),0,10)===$day));
  $dayEmpty=$day!==''&&array_sum(array_map(static fn($s)=>(int)($counts[$s['id']]??0),$sameDay))===0;
  if($dayEmpty){$q=$db->prepare('SELECT COUNT(*) FROM media_items WHERE trip_id=? AND DATE(taken_at)=?');$q->execute([$trip,$day]);$dayEmpty=(int)$q->fetchColumn()===0;}
  if(!$post){$db->commit();json(['empty'=>(int)($counts[$source]??0)===0,'day'=>$day,'empty_day'=>$dayEmpty,'station_count'=>$dayEmpty?count($sameDay):1]);}
  $operation=$input['operation']??'';$target=(string)($input['target']??'');
  if($operation==='date'){
   $date=(string)($input['day']??'');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
   if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new RuntimeException('Bitte ein gültiges Reisedatum angeben.',422);
   if($day!=='')throw new RuntimeException('Diese Station ist bereits datiert. Bitte neu laden.',409);
   $q=$db->prepare('SELECT COUNT(*) FROM media_items WHERE trip_id=? AND stop_id=? AND taken_at IS NOT NULL');$q->execute([$trip,$source]);
   if((int)$q->fetchColumn()>0)throw new RuntimeException('Die Station enthält bereits datierte Aufnahmen. Bitte neu laden.',409);
   $q=$db->prepare("UPDATE trip_stops SET starts_at=?,ends_at=?,name=CASE WHEN name='Aufnahmen ohne Datum' THEN ? ELSE name END WHERE trip_id=? AND id=?");$q->execute([$date.' 00:00:00',$date.' 00:00:00',$date,$trip,$source]);
   $q=$db->prepare('UPDATE trips SET updated_at=CURRENT_TIMESTAMP WHERE id=?');$q->execute([$trip]);
   $db->commit();json(['ok'=>true,'station_id'=>$source,'removed'=>[]]);
  }elseif($operation==='merge'){
   $destination=$byId[$target]??null;
   if(!$destination||$target===$source||$day===''||substr((string)($destination['starts_at']??''),0,10)!==$day)throw new RuntimeException('Bitte eine andere Zielstation desselben Tages auswählen.',422);
   $text=trim((string)($destination['summary']??''));$extra=trim((string)($stop['summary']??''));
   if($extra!==''&&$extra!==$text)$text=trim($text."\n\n".$extra);
   if(mb_strlen($text)>100000)throw new RuntimeException('Die gemeinsamen Stationstexte sind zu lang.',422);
   $q=$db->prepare('UPDATE media_items SET stop_id=? WHERE trip_id=? AND stop_id=?');$q->execute([$target,$trip,$source]);
   $times=array_values(array_filter([$destination['starts_at'],$stop['starts_at']]));sort($times);$start=$times[0]??null;
   $times=array_values(array_filter([$destination['ends_at'],$stop['ends_at']]));sort($times);$end=$times?end($times):null;
   $q=$db->prepare('UPDATE trip_stops SET summary=?,starts_at=?,ends_at=? WHERE trip_id=? AND id=?');$q->execute([$text,$start,$end,$trip,$target]);
   $ids=[$source];
  }elseif($operation==='delete'){
   if((int)($counts[$source]??0)!==0)throw new RuntimeException('Nur Stationen ohne Medien können gelöscht werden.',409);
   if($dayEmpty&&($input['confirm_empty_day']??'')!==$day)throw new RuntimeException('Bitte auch das Entfernen des leeren Tages bestätigen.',409);
   $ids=$dayEmpty?array_column($sameDay,'id'):[$source];
   if($dayEmpty){$q=$db->prepare('DELETE FROM trip_day_summaries WHERE trip_id=? AND day_date=?');$q->execute([$trip,$day]);}
  }else throw new RuntimeException('Unbekannte Änderung.',422);
  $q=$db->prepare('DELETE FROM trip_stops WHERE trip_id=? AND id=? AND NOT EXISTS(SELECT 1 FROM media_items WHERE stop_id=trip_stops.id)');
  foreach($ids as $id){$q->execute([$trip,$id]);if($q->rowCount()!==1)throw new RuntimeException('Die Station hat sich geändert. Bitte neu laden.',409);}
  $q=$db->prepare('UPDATE trips SET updated_at=CURRENT_TIMESTAMP WHERE id=?');$q->execute([$trip]);
  $db->commit();json(['ok'=>true,'station_id'=>$operation==='merge'?$target:null,'removed'=>$ids,'removed_day'=>$operation==='delete'&&$dayEmpty?$day:null]);
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$code=in_array($e->getCode(),[404,409,422],true)?$e->getCode():500;json(['error'=>$code===500?'Stationen konnten nicht geändert werden.':$e->getMessage()],$code);}
}
