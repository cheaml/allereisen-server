<?php
declare(strict_types=1);

$configFile = defined('ATLAS_LOCAL_MODE') && ATLAS_LOCAL_MODE
    ? dirname(__DIR__).'/local-trip/data/archive-config.php'
    : __DIR__ . '/config.php';
if (!is_file($configFile)) { http_response_code(503); exit('Server ist noch nicht konfiguriert.'); }
$config = require $configFile;
require_once __DIR__ . '/session.php';
atlas_start_session();
try { $db = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
catch (PDOException $e) { http_response_code(503); exit('Datenbank nicht erreichbar.'); }
if (defined('ATLAS_LOCAL_MODE') && ATLAS_LOCAL_MODE && ((int)($_SESSION['user_id']??0)!==(int)$config['local_admin_id'] || ($_SESSION['auth_method']??'')!=='local')) {
    complete_login((int)$config['local_admin_id'],'local');
}
function json(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function require_user(): int { if (empty($_SESSION['user_id'])) json(['error'=>'Nicht angemeldet'],401); return (int)$_SESSION['user_id']; }
function user_is_admin(PDO $db,int $userId): bool {
    $method=$_SESSION['auth_method']??'microsoft';
    $authorized=$method==='microsoft'||($method==='local'&&defined('ATLAS_LOCAL_MODE')&&ATLAS_LOCAL_MODE);
    if(!$userId||!$authorized)return false;
    $q=$db->prepare('SELECT is_admin FROM users WHERE id=?');$q->execute([$userId]);return (bool)$q->fetchColumn();
}
/** Trip administration: administrators may edit every trip, signed-in owners their own trips. */
function user_can_edit_trip(PDO $db,int $userId,string $tripId): bool {
    if(user_is_admin($db,$userId))return true;
    $method=$_SESSION['auth_method']??'microsoft';
    if(!$userId||$tripId===''||!($method==='microsoft'||($method==='local'&&defined('ATLAS_LOCAL_MODE')&&ATLAS_LOCAL_MODE)))return false;
    $q=$db->prepare('SELECT 1 FROM trips WHERE id=? AND user_id=?');$q->execute([$tripId,$userId]);return (bool)$q->fetchColumn();
}
function require_trip_editor(PDO $db,string $tripId): int { $userId=require_user(); if(!user_can_edit_trip($db,$userId,$tripId))json(['error'=>'Keine Berechtigung, diese Reise zu bearbeiten.'],403); return $userId; }
function require_admin(PDO $db): int { $userId=require_user(); if(!user_is_admin($db,$userId))json(['error'=>'Keine Administratorberechtigung'],403); return $userId; }
function require_microsoft_admin(PDO $db): int { $userId=require_admin($db); $q=$db->prepare('SELECT microsoft_id FROM users WHERE id=?'); $q->execute([$userId]); if(!(string)$q->fetchColumn()) json(['error'=>'Diese Funktion erfordert eine Microsoft-Anmeldung.'],403); return $userId; }
function complete_login(int $userId, string $method='microsoft'): void { session_regenerate_id(true); $_SESSION['user_id']=$userId; $_SESSION['auth_method']=$method; $_SESSION['login_expires_at']=time()+ATLAS_SESSION_LIFETIME; }
function uuid(): string { $b=random_bytes(16); $b[6]=chr((ord($b[6])&0x0f)|0x40); $b[8]=chr((ord($b[8])&0x3f)|0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4)); }

require_once __DIR__.'/import-permissions.php';

require_once __DIR__.'/trip-publication.php';
