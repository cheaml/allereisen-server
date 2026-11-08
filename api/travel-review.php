<?php
declare(strict_types=1);
/* Review cases of the trip automation, settled by hand on the settings page: show a thumbnail, add the
 * original to an existing trip (as a reference, at the time of the chosen station) or ignore it.
 * Only captures that are on the review list can be shown or added. */
require_once __DIR__.'/travel-automation-settings.php';

final class TravelReviewError extends RuntimeException {}

/** The open review entry for a OneDrive item id, or null. */
function travel_review_entry(string $privateDirectory,string $itemId):?array {
    if(!preg_match('/^[A-Za-z0-9!._-]{1,128}$/D',$itemId))return null;
    $file=$privateDirectory.'/state.json';if(!is_file($file))return null;
    $state=json_decode((string)file_get_contents($file),true);
    $seen=$state['seen'][$itemId]??null;
    return is_array($seen)&&!empty($seen['review'])&&empty($seen['manual'])?$seen:null;
}

/** Marks a review entry as settled by hand, under the worker lock so a running job cannot overwrite it. */
function travel_review_settle(string $privateDirectory,string $itemId,array $how):void {
    $lock=fopen($privateDirectory.'/worker.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new TravelReviewError('Die Automatik läuft gerade. Bitte in einer Minute erneut versuchen.');
    try{
        $path=$privateDirectory.'/state.json';$state=json_decode((string)file_get_contents($path),true,256,JSON_THROW_ON_ERROR);
        if(!isset($state['seen'][$itemId]))throw new TravelReviewError('Diese Aufnahme steht nicht mehr zur Prüfung.');
        $state['seen'][$itemId]['review']=false;$state['seen'][$itemId]['manual']=$how+['at'=>gmdate(DATE_ATOM)];
        travel_save($path,$state);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

/** Ignores every open review case whose best known time lies within the days from..to (inclusive, in the
 * automation's time zone). Cases without any known time are left alone. Returns the number ignored. */
function travel_review_ignore_range(string $privateDirectory,string $from,string $to,string $timezone):int {
    try{$zone=new DateTimeZone($timezone);}catch(Exception){$zone=new DateTimeZone('Europe/Vienna');}
    $start=DateTimeImmutable::createFromFormat('!Y-m-d',$from,$zone);$end=DateTimeImmutable::createFromFormat('!Y-m-d',$to,$zone);
    if(!$start||!$end||$start>$end)throw new TravelReviewError('Bitte einen gültigen Zeitraum wählen (von bis bis).');
    $first=$start->getTimestamp();$last=$end->modify('+1 day')->getTimestamp();
    $lock=fopen($privateDirectory.'/worker.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new TravelReviewError('Die Automatik läuft gerade. Bitte in einer Minute erneut versuchen.');
    try{
        $path=$privateDirectory.'/state.json';$state=json_decode((string)file_get_contents($path),true,256,JSON_THROW_ON_ERROR);$count=0;
        foreach($state['seen']??[] as $id=>$seen){
            if(empty($seen['review'])||!empty($seen['manual']))continue;
            $time=travel_review_time($seen);if($time===null||$time<$first||$time>=$last)continue;
            $state['seen'][$id]['review']=false;$state['seen'][$id]['manual']=['action'=>'ignored','range'=>$from.'..'.$to,'at'=>gmdate(DATE_ATOM)];$count++;
        }
        if($count)travel_save($path,$state);return $count;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

/** Ignores every open review case (empties the list). Returns the number ignored. */
function travel_review_ignore_all(string $privateDirectory):int {
    $lock=fopen($privateDirectory.'/worker.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new TravelReviewError('Die Automatik läuft gerade. Bitte in einer Minute erneut versuchen.');
    try{
        $path=$privateDirectory.'/state.json';$state=json_decode((string)file_get_contents($path),true,256,JSON_THROW_ON_ERROR);$count=0;
        foreach($state['seen']??[] as $id=>$seen){
            if(empty($seen['review'])||!empty($seen['manual']))continue;
            $state['seen'][$id]['review']=false;$state['seen'][$id]['manual']=['action'=>'ignored','all'=>true,'at'=>gmdate(DATE_ATOM)];$count++;
        }
        if($count)travel_save($path,$state);return $count;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

/** GET on Microsoft Graph with the owner's token (read only). */
function travel_review_graph(string $token,string $path,bool $binary=false):array {
    $ch=curl_init('https://graph.microsoft.com/v1.0/'.$path);
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>$binary,CURLOPT_MAXREDIRS=>3,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
    if($body===false||$status!==200)throw new TravelReviewError('OneDrive hat die Aufnahme nicht geliefert.');
    return [$body,$type];
}

/** Trips the person may add photos to, newest first: [id => title]. */
function travel_review_trips(PDO $db,int $user):array {
    $all=user_is_admin($db,$user);
    $q=$db->prepare('SELECT id,title FROM trips'.($all?'':' WHERE user_id=?').' ORDER BY COALESCE(starts_on,DATE(created_at)) DESC,title');
    $q->execute($all?[]:[$user]);$trips=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$trips[(string)$row['id']]=(string)$row['title'];return $trips;
}

/** Stations of a trip for the choice: [[id,label]]. */
function travel_review_stops(PDO $db,int $user,string $trip):array {
    if(!user_can_edit_trip($db,$user,$trip))throw new TravelReviewError('Keine Berechtigung für diese Reise.');
    $q=$db->prepare('SELECT id,position,name,starts_at FROM trip_stops WHERE trip_id=? ORDER BY position');$q->execute([$trip]);
    return array_map(static fn($s)=>[(string)$s['id'],($s['position']+1).' · '.$s['name'].($s['starts_at']?' ('.date('d.m.Y',strtotime((string)$s['starts_at'])).')':'')],$q->fetchAll(PDO::FETCH_ASSOC));
}

/** Adds the original as a reference to the trip at the chosen station (time of the station).
 * Returns false when the trip already has it (e.g. a second click), so the case can still be settled. */
function travel_review_add(PDO $db,int $user,string $token,string $itemId,string $trip,string $stop):bool {
    if(!user_can_edit_trip($db,$user,$trip))throw new TravelReviewError('Keine Berechtigung für diese Reise.');
    $q=$db->prepare($stop===''?'SELECT id,starts_at,latitude,longitude FROM trip_stops WHERE trip_id=? ORDER BY position LIMIT 1':'SELECT id,starts_at,latitude,longitude FROM trip_stops WHERE trip_id=? AND id=?');
    $q->execute($stop===''?[$trip]:[$trip,$stop]);$station=$q->fetch(PDO::FETCH_ASSOC);
    if(!$station)throw new TravelReviewError('Bitte eine Station der Reise wählen.');
    [$raw]=travel_review_graph($token,'me/drive/items/'.rawurlencode($itemId).'?$select=id,name,file,image,photo,video,webUrl');
    $item=json_decode($raw,true);if(!is_array($item)||($item['id']??null)!==$itemId||!isset($item['file']))throw new TravelReviewError('OneDrive hat die Aufnahme nicht geliefert.');
    $type=isset($item['video'])?'video':'photo';
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT 1 FROM media_items WHERE trip_id=? AND onedrive_item_id=?');$q->execute([$trip,$itemId]);
        if($q->fetchColumn()){$db->rollBack();return false;}
        $q=$db->prepare('INSERT INTO media_items(id,trip_id,stop_id,onedrive_item_id,name,media_type,taken_at,latitude,longitude,web_url,capture_source,capture_details) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        // Same columns as the OneDrive sync (sync-trip-media.php), present on every server version.
        $q->execute([uuid(),$trip,$station['id'],$itemId,mb_substr((string)($item['name']??''),0,1024),$type,$station['starts_at'],null,null,$item['webUrl']??null,'manual',
            json_encode(['reason'=>'automation-review','note'=>'Zeit und Station von Hand gewählt'],JSON_UNESCAPED_UNICODE)]);
        $q=$db->prepare('UPDATE trips SET media_count=(SELECT COUNT(*) FROM media_items WHERE trip_id=?) WHERE id=?');$q->execute([$trip,$trip]);
        $db->commit();
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    return true;
}
