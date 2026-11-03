<?php
declare(strict_types=1);
require_once __DIR__.'/onedrive-items.php';

// List only immediate folders, using the same shortcut resolution as imports.
function onedrive_list_folders(string $path, callable $read): array {
    if (strlen($path)>1024 || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
        throw new InvalidArgumentException('Ungültiger OneDrive-Ordnerpfad.', 422);
    }
    $segments=$path===''?[]:explode('/', $path);
    $base='https://graph.microsoft.com/v1.0/me/drive/root';
    foreach ($segments as $segment) {
        if ($segment==='' || $segment==='.' || $segment==='..') throw new InvalidArgumentException('Ungültiger OneDrive-Ordnerpfad.', 422);
        $entry=$read($base.':/'.rawurlencode($segment));
        $item=$entry['remoteItem']??$entry;
        if (!isset($item['folder'], $item['id'])) throw new RuntimeException('OneDrive-Ordner nicht gefunden.', 404);
        $base=onedrive_item_url(onedrive_reference($entry));
    }
    $url=$base.'/children?$select=id,name,folder,remoteItem,parentReference&$top=200';
    $seen=[]; $folders=[];
    while ($url!==null) {
        if (!is_string($url) || !str_starts_with($url,'https://graph.microsoft.com/v1.0/') || isset($seen[$url])) {
            throw new RuntimeException('Ungültige oder wiederholte OneDrive-Folgeseite.', 502);
        }
        if (count($seen)>=100) throw new RuntimeException('Zu viele OneDrive-Ordner. Bitte einen kleineren Bereich wählen.', 422);
        $seen[$url]=true; $page=$read($url);
        if (!is_array($page['value']??null)) throw new RuntimeException('Unvollständige OneDrive-Ordnerliste.', 502);
        foreach ($page['value'] as $entry) {
            $item=$entry['remoteItem']??$entry;
            $name=(string)($entry['name']??$item['name']??'');
            if (!isset($item['folder']) || $name==='' || $name==='.' || $name==='..' || preg_match('~[/\\\\\x00-\x1f\x7f]~',$name)) continue;
            $folderPath=$path===''?$name:$path.'/'.$name;
            if (strlen($folderPath)>1024) continue;
            $folders[$name]=['name'=>$name,'path'=>$folderPath];
            if (count($folders)>5000) throw new RuntimeException('Zu viele OneDrive-Ordner. Bitte einen kleineren Bereich wählen.', 422);
        }
        $url=$page['@odata.nextLink']??null;
    }
    $folders=array_values($folders);
    usort($folders,static fn(array $a,array $b):int=>strnatcasecmp($a['name'],$b['name']));
    return ['path'=>$path,'folders'=>$folders];
}

function handle_onedrive_folders(PDO $db,array $config): never {
    if ($_SERVER['REQUEST_METHOD']!=='GET') json(['error'=>'Methode nicht erlaubt.'],405);
    $owner=require_importer($db);
    $path=$_GET['folder']??'';
    if (!is_string($path)) json(['error'=>'Ungültiger OneDrive-Ordnerpfad.'],422);
    try {
        $token=graph_access_token($db,$config,$owner);
        if (!$token) json(['error'=>'Bitte OneDrive zuerst verbinden.'],409);
        $result=onedrive_list_folders($path,static function(string $url) use($token):array {
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30]);
            $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            $data=is_string($raw)?json_decode($raw,true):null;
            if ($status===401 || $status===403) throw new RuntimeException('Bitte OneDrive erneut verbinden und den Lesezugriff bestätigen.',409);
            if ($status===404) throw new RuntimeException('OneDrive-Ordner nicht gefunden.',404);
            if ($status!==200 || !is_array($data)) throw new RuntimeException('OneDrive-Ordner konnten nicht geladen werden. Bitte erneut versuchen.',502);
            return $data;
        });
        json($result);
    } catch(Throwable $error) {
        $status=in_array($error->getCode(),[404,409,422,502],true)?$error->getCode():502;
        json(['error'=>$status===502?'OneDrive-Ordner konnten nicht geladen werden. Bitte erneut versuchen.':$error->getMessage()],$status);
    }
}
