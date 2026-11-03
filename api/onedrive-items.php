<?php
declare(strict_types=1);

// Cross-drive identities remain usable for EXIF, thumbnails and originals.
function onedrive_item_url(string $id): string {
    if(str_starts_with($id,'drive:')){
        $parts=explode(':',$id,3);
        if(count($parts)!==3||$parts[1]===''||$parts[2]==='')throw new RuntimeException('Ungültige OneDrive-Datei.');
        return 'https://graph.microsoft.com/v1.0/drives/'.rawurlencode($parts[1]).'/items/'.rawurlencode($parts[2]);
    }
    return 'https://graph.microsoft.com/v1.0/me/drive/items/'.rawurlencode($id);
}
function onedrive_reference(array $item): string {
    $item=$item['remoteItem']??$item;
    $drive=(string)($item['parentReference']['driveId']??'');
    return $drive!==''?'drive:'.$drive.':'.$item['id']:(string)$item['id'];
}
function onedrive_collect_folder(string $folder,callable $read,?array &$presentIds=null): array {
    $presentIds=[];
    $base='https://graph.microsoft.com/v1.0/me/drive/root';
    $root=$read($base);$ownDrive=(string)($root['parentReference']['driveId']??'');
    $segments=explode('/',$folder);
    foreach($segments as $segment){
        if($segment===''||$segment==='.'||$segment==='..')throw new RuntimeException('Ungültiger OneDrive-Ordner.');
        $item=$read($base.':/'.rawurlencode($segment));
        $actual=$item['remoteItem']??$item;
        if(!isset($actual['folder'],$actual['id']))throw new RuntimeException('OneDrive-Ordner nicht gefunden.');
        $base=onedrive_item_url(onedrive_reference($item));
    }
    $fields='id,name,file,folder,remoteItem,parentReference,photo,video,image,location,size,lastModifiedDateTime,webUrl';
    $urls=[$base.'/children?$select='.$fields.'&$top=999'];$seen=[];$items=[];
    while($urls){
        $url=array_pop($urls);
        if(isset($seen[$url]))throw new RuntimeException('Wiederholte OneDrive-Seite');
        if(!str_starts_with($url,'https://graph.microsoft.com/v1.0/'))throw new RuntimeException('Ungültige OneDrive-Folgeseite');
        $seen[$url]=true;$page=$read($url);
        if(!is_array($page['value']??null))throw new RuntimeException('Unvollständige OneDrive-Seite');
        foreach($page['value'] as $entry){
            $item=$entry['remoteItem']??$entry;
            if(empty($item['id']))throw new RuntimeException('Unvollständige OneDrive-Datei.');
            // Keep ordinary own-drive IDs unchanged for existing archives.
            $drive=(string)($item['parentReference']['driveId']??'');
            $id=$drive!==''&&$drive!==$ownDrive?onedrive_reference($entry):(string)$item['id'];
            $presentIds[$id]=true;
            if(isset($item['folder']))$urls[]=onedrive_item_url($id).'/children?$select='.$fields.'&$top=999';
            elseif(isset($item['photo'])||isset($item['video'])||preg_match('~^(image|video)/~',(string)($item['file']['mimeType']??''))){
                $item['id']=$id;
                if(!isset($item['photo'])&&!isset($item['video']))$item[str_starts_with((string)($item['file']['mimeType']??''),'video/')?'video':'photo']??=[];
                $items[$id]=$item;
            }
        }
        if(isset($page['@odata.nextLink']))$urls[]=$page['@odata.nextLink'];
    }
    return array_values($items);
}
