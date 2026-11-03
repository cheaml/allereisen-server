<?php
declare(strict_types=1);

function manual_route_point(mixed $point): array {
    if (!is_array($point) || !array_is_list($point) || count($point)!==2) throw new InvalidArgumentException('Ungültige Routenposition.');
    foreach ($point as $n) if ((!is_int($n) && !is_float($n)) || !is_finite((float)$n)) throw new InvalidArgumentException('Ungültige Routenposition.');
    if (abs($point[0])>90 || abs($point[1])>180) throw new InvalidArgumentException('Ungültige Routenposition.');
    return [round((float)$point[0],7),round((float)$point[1],7)];
}
function manual_route_validate(array $input): array {
    $from=manual_route_point($input['from']??null);$to=manual_route_point($input['to']??null);
    if ($from===$to) throw new InvalidArgumentException('Start und Ziel müssen verschieden sein.');
    if (!array_key_exists('points',$input)) throw new InvalidArgumentException('Strecke fehlt.');
    $points=$input['points'];
    if ($points!==null) {
        if (!is_array($points)||!array_is_list($points)||count($points)<2||count($points)>200) throw new InvalidArgumentException('Ungültige Anzahl von Zwischenpunkten.');
        $points=array_map('manual_route_point',$points);
        if ($points[0]!==$from || $points[count($points)-1]!==$to) throw new InvalidArgumentException('Start und Ziel müssen an den Stationen bleiben.');
    }
    return [$from,$to,$points];
}
function handle_manual_routes(PDO $db, int $userId, array $input): never {
    $post=$_SERVER['REQUEST_METHOD']==='POST';
    if (!$post && $_SERVER['REQUEST_METHOD']!=='GET') json(['error'=>'Methode nicht erlaubt'],405);
    $trip=(string)($post?($input['trip']??''):($_GET['trip']??''));
    require_trip_access($db,$userId,$trip);
    if ($post) {
        require_trip_editor($db,$trip);
        // JSON writes require the application's own origin; never accept form posts.
        $expected=rtrim((string)($GLOBALS['config']['app_url']??''),'/');
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        if ($origin!=='' && $origin!==$expected) json(['error'=>'Ungültiger Ursprung'],403);
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) json(['error'=>'JSON-Anfrage erforderlich'],415);
        try { [$from,$to,$points]=manual_route_validate($input); }
        catch (InvalidArgumentException $e) { json(['error'=>$e->getMessage()],422); }
        $q=$db->prepare('SELECT COUNT(*) FROM trip_stops WHERE trip_id=? AND ((latitude=? AND longitude=?) OR (latitude=? AND longitude=?))');
        $q->execute([$trip,$from[0],$from[1],$to[0],$to[1]]);
        if ((int)$q->fetchColumn()<2) json(['error'=>'Stationspositionen haben sich geändert. Bitte die Karte neu laden.'],409);
    }
    if (!$post) {
        $q=$db->prepare('SELECT payload FROM trip_manual_routes WHERE trip_id=?');$q->execute([$trip]);
        json(['routes'=>array_map(static fn($row)=>json_decode($row['payload'],true),$q->fetchAll())]);
    }
    $key=hash('sha256',json_encode([$from,$to],JSON_PRESERVE_ZERO_FRACTION));
    if ($points===null) {
        $q=$db->prepare('DELETE FROM trip_manual_routes WHERE trip_id=? AND route_key=?');$q->execute([$trip,$key]);
    } else {
        $q=$db->prepare('INSERT INTO trip_manual_routes (trip_id,route_key,payload) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload)');
        $q->execute([$trip,$key,json_encode(['from'=>$from,'to'=>$to,'points'=>$points],JSON_THROW_ON_ERROR)]);
    }
    json(['ok'=>true]);
}
