<?php
declare(strict_types=1);

function user_can_import(PDO $db,int $userId): bool {
    if(user_is_admin($db,$userId))return true;
    if(!$userId||($_SESSION['auth_method']??'')!=='microsoft')return false;
    $q=$db->prepare('SELECT can_import FROM users WHERE id=? AND microsoft_id IS NOT NULL');
    $q->execute([$userId]);return (bool)$q->fetchColumn();
}
function require_importer(PDO $db): int {
    $id=require_user();if(!user_can_import($db,$id))json(['error'=>'Keine Importberechtigung'],403);return $id;
}

function import_permissions_manage(PDO $db,array $config,int $actor,string $method,array $input,string $csrf): array {
    if(!user_is_admin($db,$actor))json(['error'=>'Keine Administratorberechtigung.'],403);
    $q=$db->prepare('SELECT email FROM users WHERE id=?');$q->execute([$actor]);
    $email=strtolower((string)$q->fetchColumn());
    $main=array_map(static fn($s)=>strtolower(trim((string)$s)),(array)($config['admin_emails']??[]));
    $canManage=in_array($email,$main,true);
    $token=hash_hmac('sha256','import-permissions-v1:'.session_id().':'.$actor,(string)$config['encryption_key']);
    if($method==='GET'){
        $rows=$db->query("SELECT id,display_name,email,is_admin,can_import,(microsoft_id IS NOT NULL AND microsoft_id<>'') AS microsoft_connected FROM users WHERE (microsoft_id IS NOT NULL AND microsoft_id<>'') OR can_import=1 ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);
        return ['accounts'=>$rows,'can_manage'=>$canManage,'csrf_token'=>$canManage?$token:null];
    }
    if($method!=='PATCH')json(['error'=>'Methode nicht erlaubt.'],405);
    if(!$canManage)json(['error'=>'Nur Hauptadmins dürfen Importrechte verwalten.'],403);
    if($csrf===''||!hash_equals($token,$csrf))json(['error'=>'Bitte die Verwaltung neu laden.'],403);
    if(!isset($input['user_id'])||!is_bool($input['can_import']??null))json(['error'=>'Ungültige Importberechtigung.'],422);
    $pendingRevoke=$input['can_import']?'':' OR can_import=1';
    $q=$db->prepare("SELECT id,is_admin FROM users WHERE id=? AND ((microsoft_id IS NOT NULL AND microsoft_id<>'')".$pendingRevoke.")");$q->execute([(int)$input['user_id']]);$target=$q->fetch();
    if(!$target)json(['error'=>'Microsoft-Benutzer nicht gefunden.'],404);
    if($target['is_admin'])json(['error'=>'Administratoren besitzen das Importrecht über ihre Adminrolle.'],409);
    $q=$db->prepare('UPDATE users SET can_import=? WHERE id=? AND is_admin=0');$q->execute([$input['can_import']?1:0,(int)$target['id']]);
    return ['ok'=>true,'message'=>$input['can_import']?'Importrecht erteilt.':'Importrecht entzogen.'];
}
