<?php
declare(strict_types=1);
// Deleting one's own server account (website and apps).
// Removes the account, its own trips with all stations/texts/media references/routes,
// its connections and device links, access grants to its e-mail address and the
// server-side preview images of its trips. Originals in OneDrive/Dropbox are untouched.

final class AccountDeleteError extends RuntimeException {public function __construct(string $message,public readonly int $status){parent::__construct($message);}}

function account_delete_user(PDO $db,int $userId): array {
    $q=$db->prepare('SELECT id,email,display_name,is_admin FROM users WHERE id=?');$q->execute([$userId]);
    $user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user)throw new AccountDeleteError('Konto nicht gefunden.',404);
    return $user;
}

/** Reason why the account must not be deleted, or null. */
function account_delete_blocker(PDO $db,array $config,array $user): ?string {
    $email=strtolower(trim((string)$user['email']));
    $main=array_map(static fn($s)=>strtolower(trim((string)$s)),(array)($config['admin_emails']??[]));
    if($email!==''&&in_array($email,$main,true))
        return 'Dieses Konto ist in der Serverkonfiguration als Hauptadministrator eingetragen (admin_emails). Es kann erst gelöscht werden, wenn die Adresse dort entfernt wurde.';
    if(!empty($user['is_admin'])&&(int)$db->query('SELECT COUNT(*) FROM users WHERE is_admin=1')->fetchColumn()<=1)
        return 'Dies ist das einzige Administratorkonto. Bitte zuerst einem anderen Konto Administratorrechte geben.';
    return null;
}

function account_delete_summary(PDO $db,array $config,int $userId): array {
    $user=account_delete_user($db,$userId);
    $q=$db->prepare('SELECT COUNT(*) FROM trips WHERE user_id=?');$q->execute([$userId]);$trips=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT COUNT(*) FROM media_items m JOIN trips t ON t.id=m.trip_id WHERE t.user_id=?');$q->execute([$userId]);$media=(int)$q->fetchColumn();
    return ['email'=>(string)$user['email'],'display_name'=>(string)($user['display_name']??''),'trips'=>$trips,'media'=>$media,'blocked'=>account_delete_blocker($db,$config,$user)];
}

/** Runs a statement and ignores a missing optional table (older installations). */
function account_delete_exec(PDO $db,string $sql,array $params): int {
    try {$q=$db->prepare($sql);$q->execute($params);return $q->rowCount();}
    catch(PDOException $e){
        if($e->getCode()==='42S02'||str_contains($e->getMessage(),'no such table'))return 0;
        throw $e;
    }
}

/**
 * Deletes the account after the caller has authenticated the user and checked the
 * typed confirmation. $thumbnailDirectory: server preview cache (api/cache/thumbs).
 */
function account_delete(PDO $db,array $config,int $userId,string $confirmEmail,string $thumbnailDirectory): array {
    $user=account_delete_user($db,$userId);
    $email=strtolower(trim((string)$user['email']));
    if($email===''||strtolower(trim($confirmEmail))!==$email)throw new AccountDeleteError('Zur Bestätigung bitte die eigene E-Mail-Adresse genau eingeben.',422);
    if($blocker=account_delete_blocker($db,$config,$user))throw new AccountDeleteError($blocker,409);
    $q=$db->prepare('SELECT m.id FROM media_items m JOIN trips t ON t.id=m.trip_id WHERE t.user_id=?');$q->execute([$userId]);
    $mediaIds=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
    $counts=['trips'=>0,'media'=>count($mediaIds),'previews'=>0];
    $db->beginTransaction();
    try {
        // Device links and sync records have no foreign key to users.
        foreach(['device_sync_receipts','device_sync_backups','device_sync_devices','device_sync_codes'] as $table)
            account_delete_exec($db,"DELETE FROM $table WHERE user_id=?",[$userId]);
        // Access granted to this person's e-mail address by others (personal data of the deleted person).
        foreach(['trip_guest_access','all_trips_guest_access','trip_invitations','family_circle_contacts'] as $table)
            account_delete_exec($db,"DELETE FROM $table WHERE LOWER(email)=?",[$email]);
        account_delete_exec($db,'UPDATE trips SET approved_by=NULL WHERE approved_by=?',[$userId]);
        // Own trips first (cascades to stations, day texts, media references, routes, invitations).
        $counts['trips']=account_delete_exec($db,'DELETE FROM trips WHERE user_id=?',[$userId]);
        // The account (cascades to OAuth tokens, media/automation connections, family circle).
        account_delete_exec($db,'DELETE FROM users WHERE id=?',[$userId]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    // Preview files only after the database change succeeded.
    foreach($mediaIds as $id){
        $safe=preg_replace('/[^a-zA-Z0-9-]/','',$id);if($safe==='')continue;
        foreach(['small','medium','large','fullscreen'] as $size){$path=$thumbnailDirectory.'/'.$safe.'-'.$size.'.jpg';if(is_file($path)&&@unlink($path))$counts['previews']++;}
    }
    return ['ok'=>true]+$counts;
}

/** CSRF token for the website (bound to session and user). */
function account_delete_csrf(array $config,int $userId): string {
    return hash_hmac('sha256','account-delete-v1:'.session_id().':'.$userId,(string)($config['encryption_key']??''));
}
