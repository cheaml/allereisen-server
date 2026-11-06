<?php
declare(strict_types=1);
require_once __DIR__.'/capture-time.php';
require_once __DIR__.'/sync-trip-media.php';
require_once __DIR__.'/onedrive-scope.php';
require_once __DIR__.'/trip-manual-dates.php';

function automation_uuid():string
{
    $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);
    $hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}

/** One transaction binds verified copy, preserves existing media IDs, appends
 * missing media/stations and commits the source-target mapping for restart.
 * Must be called only after current Graph target/content verification.
 */
function automation_catalog_import(PDO $db,int $owner,array $trip,array $record,array $media,array $target,?string $targetBase=null,bool $originalReference=false):string
{
    if(($record['status']??null)!=='verified'||!preg_match('/^[a-f0-9]{64}$/D',(string)($record['key']??''))||!preg_match('/^[a-f0-9]{64}$/D',(string)($record['sourceHash']??''))||
        !hash_equals((string)$record['sourceHash'],(string)($record['targetHash']??''))||($record['sourceBytes']??0)<=0||
        ($target['id']??null)!==($record['targetId']??null)||($target['eTag']??null)!==($record['targetVersion']??null)||
        ($media['itemId']??null)!==($record['item']??null)||($media['version']??null)!==($record['version']??null))throw new RuntimeException('Verified original metadata/mapping required.');
    if($originalReference){$validFolder=($record['mode']??null)==='original-reference'&&$record['item']===$target['id']&&$record['version']===$target['eTag']&&($target['parentReference']['driveId']??null)===$record['drive']&&($trip['source_folder']??null)==='reise://'.$trip['id'];}
    elseif($targetBase===null){PhotosOneDriveScope::assertRelativeFolder((string)($trip['source_folder']??''));$validFolder=str_starts_with($trip['source_folder'],'Photos/Reisen/');}
    else{require_once __DIR__.'/travel-automation-scope.php';TravelOneDriveScope::assertPath($targetBase);TravelOneDriveScope::assertPath((string)($trip['source_folder']??''));$validFolder=TravelOneDriveScope::within($trip['source_folder'],$targetBase)&&strcasecmp($trip['source_folder'],$targetBase)!==0;}
    if(!$validFolder||empty($trip['id'])||empty($trip['title'])||(empty($media['takenAt'])&&empty($trip['manual_starts_on'])))throw new RuntimeException('Trip identity and capture time required.');
    $mappingTable=$originalReference?'original_media_memberships':'automation_media_map';
    $taken=empty($media['takenAt'])?null:(new DateTimeImmutable($media['takenAt']))->format('Y-m-d H:i:s');
    $manual=$trip['manual_starts_on']??null;if($manual!==null)trip_manual_dates(['manual_starts_on'=>$manual,'manual_ends_on'=>null]);
    $columns=array_fill_keys($db->query('SHOW COLUMNS FROM media_items')->fetchAll(PDO::FETCH_COLUMN),true);
    $tripColumns=array_fill_keys($db->query('SHOW COLUMNS FROM trips')->fetchAll(PDO::FETCH_COLUMN),true);
    $provider=isset($columns['source_provider'])?" AND m.source_provider='onedrive'":'';
    $sourceMatch=isset($columns['source_item_id'])?'(m.onedrive_item_id=? OR m.source_item_id=?)':'m.onedrive_item_id=?';
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id,microsoft_id FROM users WHERE id=? FOR UPDATE');$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);
        if(!$user)throw new RuntimeException('Automation owner missing.');
        $account=$user['microsoft_id'];
        try{$q=$db->prepare("SELECT account_id FROM media_source_connections WHERE user_id=? AND provider='onedrive'");$q->execute([$owner]);$account=$q->fetchColumn()?:$account;}
        catch(PDOException $e){if(($e->errorInfo[1]??null)!==1146)throw $e;}
        if($account!==$record['account'])throw new RuntimeException('OneDrive account changed.');
        $q=$db->prepare('SELECT * FROM '.$mappingTable.' WHERE source_key=?');$q->execute([$record['key']]);$mapped=$q->fetch(PDO::FETCH_ASSOC);
        if($mapped){
            if((int)$mapped['user_id']!==$owner||$mapped['target_item_id']!==$record['targetId']||$mapped['source_version']!==$record['version']||$mapped['trip_id']!==$trip['id']||$mapped['source_sha256']!==$record['sourceHash'])throw new RuntimeException('Existing copy mapping conflicts.');
            if($originalReference){$q=$db->prepare('SELECT COUNT(*) FROM media_items m JOIN trips t ON t.id=m.trip_id WHERE t.id=? AND t.user_id=? AND m.onedrive_item_id=?');$q->execute([$trip['id'],$owner,$target['id']]);if(!(int)$q->fetchColumn())throw new RuntimeException('original_membership_requires_review');}
            $db->commit();return $mapped['trip_id'];
        }
        $q=$db->prepare('SELECT user_id FROM trips WHERE id=? FOR UPDATE');$q->execute([$trip['id']]);$tripOwner=$q->fetchColumn();
        if($tripOwner===false){$q=$db->prepare(isset($tripColumns['source_provider'])?"INSERT INTO trips(id,user_id,title,source_provider,source_folder) VALUES(?,?,?,'onedrive',?)":"INSERT INTO trips(id,user_id,title,source_folder) VALUES(?,?,?,?)");$q->execute([$trip['id'],$owner,$trip['title'],$trip['source_folder']]);}
        elseif((int)$tripOwner!==$owner)throw new RuntimeException('Trip owner mismatch.');
        if($originalReference){$q=$db->prepare('UPDATE trips SET source_folder=? WHERE id=? AND user_id=?');$q->execute([$trip['source_folder'],$trip['id'],$owner]);}
        if($manual!==null){if(!isset($tripColumns['manual_starts_on'],$tripColumns['manual_ends_on']))throw new RuntimeException('manual_trip_date_schema_missing');$q=$db->prepare('SELECT COUNT(*) FROM media_items WHERE trip_id=? AND taken_at IS NOT NULL');$q->execute([$trip['id']]);if(!(int)$q->fetchColumn()){$q=$db->prepare('UPDATE trips SET manual_starts_on=?,manual_ends_on=NULL WHERE id=? AND manual_starts_on IS NULL');$q->execute([$manual,$trip['id']]);}}

        // Rebind every own existing reference without changing media/station IDs,
        // captions, selected day pictures or curated coordinates.
        $args=[$owner,$record['item']];if(isset($columns['source_item_id']))$args[]=$record['item'];
        $q=$db->prepare('SELECT m.id,m.trip_id FROM media_items m JOIN trips t ON t.id=m.trip_id WHERE t.user_id=?'.$provider.' AND '.$sourceMatch.' FOR UPDATE');$q->execute($args);$references=$q->fetchAll(PDO::FETCH_ASSOC);
        $conflict=$db->prepare('SELECT m.id FROM media_items m WHERE m.trip_id=?'.$provider.' AND '.$sourceMatch.' AND m.id<>?');
        $rebind=$db->prepare('UPDATE media_items SET onedrive_item_id=?,'.(isset($columns['source_item_id'])?'source_item_id=?,':'').'web_url=?,thumbnail_url=NULL WHERE id=?');
        $touch=$db->prepare('UPDATE trips SET updated_at=NOW() WHERE id=?');
        foreach($references as $reference){
            $args=[$reference['trip_id'],$target['id']];if(isset($columns['source_item_id']))$args[]=$target['id'];$args[]=$reference['id'];$conflict->execute($args);
            if($conflict->fetchColumn())throw new RuntimeException('Existing target media needs manual reconciliation.');
            $args=[$target['id']];if(isset($columns['source_item_id']))$args[]=$target['id'];$args[]=$target['webUrl']??null;$args[]=$reference['id'];$rebind->execute($args);$touch->execute([$reference['trip_id']]);
        }
        $q=$db->prepare('SELECT m.id FROM media_items m WHERE m.trip_id=?'.$provider.' AND '.$sourceMatch);$args=[$trip['id'],$target['id']];if(isset($columns['source_item_id']))$args[]=$target['id'];$q->execute($args);
        if(!$q->fetchColumn()) {
            $q=$db->prepare('SELECT * FROM trip_stops WHERE trip_id=? ORDER BY position');$q->execute([$trip['id']]);$stops=$q->fetchAll(PDO::FETCH_ASSOC);
            $item=['id'=>$target['id'],'name'=>$media['name']??$target['name'],'location'=>['latitude'=>$media['latitude']??null,'longitude'=>$media['longitude']??null],
                ($media['kind']??'photo')==='video'?'video':'photo'=>[],
                '_capture'=>['date'=>$taken,'source'=>$taken===null?'unknown':(($media['captureSource']??null)==='file-created'?'file-created':'original-metadata'),'original_taken_at'=>$media['takenAt']??null]];
            $plan=sync_addition_plan($stops,[$item],static fn()=>automation_uuid(),(float)($trip['station_radius_km']??1.0));
            if($plan['new_stops']){$max=0;foreach($stops as $s)$max=max($max,(int)$s['position']);$q=$db->prepare('UPDATE trip_stops SET position=position+? WHERE trip_id=?');$q->execute([$max+count($plan['stops'])+1,$trip['id']]);}
            $insert=$db->prepare('INSERT INTO trip_stops(id,trip_id,position,name,latitude,longitude,starts_at,ends_at) VALUES(?,?,?,?,?,?,?,?)');
            $update=$db->prepare('UPDATE trip_stops SET position=?,starts_at=?,ends_at=? WHERE id=? AND trip_id=?');
            foreach($plan['stops'] as $id=>$stop){if(isset($plan['new_stops'][$id]))$insert->execute([$id,$trip['id'],$stop['position'],$stop['name'],$stop['latitude'],$stop['longitude'],$stop['starts_at'],$stop['ends_at']]);else $update->execute([$stop['position'],$stop['starts_at'],$stop['ends_at'],$id,$trip['id']]);}
            $item=$plan['media'][0];
            $values=['id'=>automation_uuid(),'trip_id'=>$trip['id'],'stop_id'=>$item['_stop_id'],'onedrive_item_id'=>$target['id'],'name'=>$item['name'],'media_type'=>isset($item['video'])?'video':'photo','taken_at'=>$taken,'latitude'=>$media['latitude']??null,'longitude'=>$media['longitude']??null,'web_url'=>$target['webUrl']??null,'capture_source'=>$item['_capture']['source'],'capture_details'=>json_encode($item['_capture'],JSON_THROW_ON_ERROR)];
            if(isset($columns['source_provider']))$values['source_provider']='onedrive';if(isset($columns['source_item_id']))$values['source_item_id']=$target['id'];
            $q=$db->prepare('INSERT INTO media_items('.implode(',',array_keys($values)).') VALUES('.implode(',',array_fill(0,count($values),'?')).')');$q->execute(array_values($values));
        }
        $q=$db->prepare('SELECT COUNT(*) AS media_count,DATE(MIN(taken_at)) AS starts_on,DATE(MAX(taken_at)) AS ends_on FROM media_items WHERE trip_id=?');$q->execute([$trip['id']]);$totals=$q->fetch(PDO::FETCH_ASSOC);
        $q=$db->prepare('UPDATE trips SET media_count=?,starts_on=?,ends_on=?,updated_at=NOW() WHERE id=?');$q->execute([$totals['media_count'],$totals['starts_on'],$totals['ends_on'],$trip['id']]);
        $q=$db->prepare('INSERT INTO '.$mappingTable.'(source_key,user_id,account_id,drive_id,source_item_id,source_version,target_item_id,target_version,source_sha256,target_sha256,byte_count,trip_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$record['key'],$owner,$record['account'],$record['drive'],$record['item'],$record['version'],$record['targetId'],$record['targetVersion'],$record['sourceHash'],$record['targetHash'],$record['sourceBytes'],$trip['id']]);
        $db->commit();return $trip['id'];
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
