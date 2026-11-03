<?php
declare(strict_types=1);

function trip_owner_auto_approved(PDO $db,int $owner): bool {
    global $config;
    if(defined('ATLAS_LOCAL_MODE')&&ATLAS_LOCAL_MODE)return true;
    $q=$db->prepare('SELECT email,is_admin FROM users WHERE id=?');$q->execute([$owner]);$user=$q->fetch();
    $main=array_map(static fn($email)=>strtolower(trim((string)$email)),(array)($config['admin_emails']??[]));
    return $user&&(bool)$user['is_admin']&&in_array(strtolower((string)$user['email']),$main,true);
}
function user_can_approve_trips(PDO $db,int $user): bool {
    return user_is_admin($db,$user)&&trip_owner_auto_approved($db,$user);
}
function trip_visibility_sql(PDO $db,int $user,string $alias='t'): string {
    if(user_can_approve_trips($db,$user))return '1=1';
    $own=($_SESSION['auth_method']??'')==='microsoft'?' OR '.$alias.'.user_id='.(int)$user:'';
    return '('.$alias.".publication_status='approved'".$own.')';
}
function trip_review_manage(PDO $db,int $actor,string $method,array $input,string $csrf): array {
    global $config;
    if(!user_can_approve_trips($db,$actor))json(['error'=>'Nur Hauptadmins dürfen importierte Reisen freigeben.'],403);
    $token=hash_hmac('sha256','trip-review-v1:'.session_id().':'.$actor,(string)$config['encryption_key']);
    if($method==='GET'){
        $q=$db->query("SELECT t.id,t.title,t.starts_on,t.ends_on,t.media_count,t.created_at,u.display_name AS importer_name,u.email AS importer_email FROM trips t JOIN users u ON u.id=t.user_id WHERE t.publication_status='pending' ORDER BY t.created_at DESC,t.id");
        return ['trips'=>$q->fetchAll(PDO::FETCH_ASSOC),'csrf_token'=>$token];
    }
    if($method!=='POST')json(['error'=>'Methode nicht erlaubt.'],405);
    if($csrf===''||!hash_equals($token,$csrf))json(['error'=>'Bitte die Verwaltung neu laden.'],403);
    $q=$db->prepare("UPDATE trips SET publication_status='approved',approved_by=?,approved_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND publication_status='pending'");$q->execute([$actor,(string)($input['trip']??'')]);
    if($q->rowCount()!==1)json(['error'=>'Diese Reise wurde bereits freigegeben oder ist nicht mehr vorhanden.'],409);
    return ['ok'=>true,'message'=>'Reise freigegeben. Sie ist jetzt für die berechtigten Betrachter sichtbar.'];
}
