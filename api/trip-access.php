<?php
declare(strict_types=1);

function trip_can_read(PDO $db,int $userId,string $tripId): bool {
    if(!$userId)return false;
    $q=$db->prepare('SELECT 1 FROM trips t WHERE t.id=? AND '.trip_visibility_sql($db,$userId));$q->execute([$tripId]);if(!$q->fetchColumn())return false;
    if(user_is_admin($db,$userId))return true;
    $microsoft=($_SESSION['auth_method']??'')==='microsoft'?'1':'0';
    $q=$db->prepare('SELECT 1 FROM users u WHERE u.id=? AND (('.$microsoft.'=1 AND EXISTS(SELECT 1 FROM trips owned WHERE owned.id=? AND owned.user_id=u.id)) OR ('.$microsoft.'=1 AND EXISTS(SELECT 1 FROM all_trips_guest_access a WHERE LOWER(a.email)=LOWER(u.email))) OR EXISTS(SELECT 1 FROM trip_guest_access a WHERE a.trip_id=? AND LOWER(a.email)=LOWER(u.email)))');
    $q->execute([$userId,$tripId,$tripId]);return (bool)$q->fetchColumn();
}
function require_trip_access(PDO $db,int $userId,string $tripId): void {
    if(!$userId)json(['error'=>'Nicht angemeldet'],401);
    if(!trip_can_read($db,$userId,$tripId))json(['error'=>'Kein Zugriff auf diese Reise'],403);
}
