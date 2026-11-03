<?php
declare(strict_types=1);

function device_history_changes(?array $before,array $after): array {
    if($before===null)return ['Reise erstmals auf dem Server veröffentlicht'];
    $changes=[];$a=$before['trip'];$b=$after['trip'];
    foreach(['title'=>'Reisetitel','summary'=>'Einleitung'] as $key=>$label)if(($a[$key]??'')!==($b[$key]??''))$changes[]=$label.' geändert';
    $index=static function(array $items,string $key): array {$out=[];foreach($items as $item)$out[(string)($item[$key]??'')]=$item;return $out;};
    $stationsA=$index($a['stations']??[],'id');$stationsB=$index($b['stations']??[],'id');
    if(array_diff_key($stationsA,$stationsB)||array_diff_key($stationsB,$stationsA))$changes[]='Stationen ergänzt oder entfernt';
    foreach($stationsB as $id=>$station)if(isset($stationsA[$id])&&$stationsA[$id]!=$station){$changes[]='Stationsangaben geändert';break;}
    $media=static function(array $items): array {$out=[];foreach($items as $item){$r=$item['reference']??[];unset($r['id'],$r['previewPath']);$out[(string)($r['originalReference']??'')]=[$item['stationId']??null,$r];}return $out;};
    $mediaA=$media($a['media']??[]);$mediaB=$media($b['media']??[]);$added=count(array_diff_key($mediaB,$mediaA));$removed=count(array_diff_key($mediaA,$mediaB));
    if($added)$changes[]=$added.' Aufnahmen ergänzt';if($removed)$changes[]=$removed.' Aufnahmen entfernt';
    foreach($mediaB as $id=>$item)if(isset($mediaA[$id])&&$mediaA[$id]!=$item){$changes[]='Aufnahmedaten oder Stationszuordnung geändert';break;}
    $labels=['trip'=>'Reiseeinstellungen','route'=>'Verbindungsstrecken','station'=>'Stationsangaben','day'=>'Tagesangaben','media'=>'Aufnahmeangaben'];
    foreach(array_unique(array_merge(array_keys($before['metadata']??[]),array_keys($after['metadata']??[]))) as $key){if(($before['metadata'][$key]??null)==($after['metadata'][$key]??null))continue;$parts=explode('/',$key);$changes[]=($labels[$parts[1]??'']??'Weitere Reiseangaben').' geändert';}
    return array_values(array_unique($changes))?:['Abgleich bestätigt; keine inhaltlichen Änderungen nachweisbar'];
}

function device_sync_history(PDO $db,int $owner,string $id): array {
    $current=device_sync_export($db,$owner,$id); // Includes ownership validation.
    $q=$db->prepare('SELECT r.request_hash,r.payload,UNIX_TIMESTAMP(r.created_at) AS epoch,b.payload AS before_payload,b.device_label FROM device_sync_receipts r LEFT JOIN device_sync_backups b ON b.id=JSON_UNQUOTE(JSON_EXTRACT(r.payload,\'$.backupId\')) AND b.user_id=r.user_id AND b.trip_id=r.trip_id WHERE r.trip_id=? AND r.user_id=? ORDER BY r.created_at DESC,r.request_hash DESC LIMIT 100');
    $q->execute([$id,$owner]);$entries=[];$latest=null;
    foreach($q->fetchAll() as $row){$reply=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);$after=$reply['snapshot']??null;if(!is_array($after))continue;$before=$row['before_payload']?json_decode($row['before_payload'],true,512,JSON_THROW_ON_ERROR):null;
        $origin=match($row['device_label']??''){'iOS'=>'iPhone/iPad','WinUI'=>'Windows','Android'=>'Android',default=>'Herkunft nicht protokolliert'};
        if(str_starts_with($row['device_label']??'','Wiederherstellung '))$origin='Server-Wiederherstellung';
        $entries[]=['id'=>'server-'.$row['request_hash'],'at'=>$row['epoch']===null?null:gmdate('Y-m-d\TH:i:s\Z',(int)$row['epoch']),'origin'=>$origin,'status'=>'Auf dem Server gespeichert','changes'=>device_history_changes($before,$after),'historical'=>true];
        if($latest===null)$latest=$after;
    }
    if($latest===null||($latest['revision']??'')!==$current['revision']){
        // No receipt proves who made intervening website/import changes or when.
        array_unshift($entries,['id'=>'server-current-'.$current['revision'],'at'=>null,'origin'=>'Server; genaue Herkunft unbekannt','status'=>'Aktueller Stand; Zeitpunkt nicht vollständig protokolliert','changes'=>$latest?device_history_changes($latest,$current):['Für diesen Reisestand liegt kein früherer Übertragungsbeleg vor'],'historical'=>true]);
    }
    return ['entries'=>$entries];
}
