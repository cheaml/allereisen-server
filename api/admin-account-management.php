<?php
declare(strict_types=1);
final class AdminAccountError extends RuntimeException {public function __construct(string $message,public readonly int $status){parent::__construct($message);}}
function admin_accounts_manage(PDO $db,array $config,int $actor,string $method,array $input,string $csrf): array {
    $q=$db->prepare('SELECT email,is_admin FROM users WHERE id=?');$q->execute([$actor]);$current=$q->fetch(PDO::FETCH_ASSOC);
    if(!$current||!$current['is_admin'])throw new AdminAccountError('Keine Administratorberechtigung.',403);
    $protected=array_map(static fn($s)=>strtolower(trim((string)$s)),(array)($config['admin_emails']??[]));
    $canManage=in_array(strtolower((string)$current['email']),$protected,true);
    $token=hash_hmac('sha256','admin-accounts-v1:'.session_id().':'.$actor,(string)$config['encryption_key']);
    if($method==='GET'){
        $accounts=$db->query('SELECT id,display_name,email,microsoft_id FROM users WHERE is_admin=1 ORDER BY COALESCE(email,display_name),id')->fetchAll(PDO::FETCH_ASSOC);
        foreach($accounts as &$row){$row['is_main']=in_array(strtolower((string)$row['email']),$protected,true);$row['protected']=in_array(strtolower((string)$row['email']),$protected,true)||(int)$row['id']===$actor;$row['microsoft_linked']=!empty($row['microsoft_id']);unset($row['microsoft_id']);}unset($row);
        return ['accounts'=>$accounts,'can_manage'=>$canManage,'csrf_token'=>$canManage?$token:null];
    }
    if(!in_array($method,['POST','DELETE'],true))throw new AdminAccountError('Methode nicht erlaubt.',405);
    if(!$canManage)throw new AdminAccountError('Nur die hinterlegten Hauptadmins dürfen Adminrechte verwalten.',403);
    if($csrf===''||!hash_equals($token,$csrf))throw new AdminAccountError('Sicherheitsprüfung fehlgeschlagen. Bitte die Verwaltung neu laden.',403);
    $email=strtolower(trim((string)($input['email']??'')));
    if(strlen($email)>320||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new AdminAccountError('Bitte eine gültige E-Mail-Adresse eingeben.',422);
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id,is_admin FROM users WHERE LOWER(email)=?');$q->execute([$email]);$target=$q->fetch(PDO::FETCH_ASSOC);
        if($method==='DELETE'){
            if(in_array($email,$protected,true)||($target&&(int)$target['id']===$actor))throw new AdminAccountError('Hauptadmins und das eigene Konto bleiben geschützt.',409);
            if($target){$q=$db->prepare('UPDATE users SET is_admin=0 WHERE id=?');$q->execute([$target['id']]);}
        }elseif($target){$q=$db->prepare('UPDATE users SET is_admin=1 WHERE id=?');$q->execute([$target['id']]);}
        else {$q=$db->prepare('INSERT INTO users(email,display_name,is_admin) VALUES(?,?,1)');$q->execute([$email,$email]);}
        $db->commit();return ['ok'=>true,'message'=>$method==='POST'?'Adminrechte gespeichert. Anmeldung über Microsoft erforderlich.':'Adminrechte entzogen. Das Konto und vorhandene Gastfreigaben bleiben erhalten.'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
