<?php
declare(strict_types=1);

function device_sync_capabilities(PDO $db,array $config,int $owner): array {
    $tables=['device_sync_codes','device_sync_devices','device_sync_backups','device_sync_receipts','trip_day_steps','trip_day_summaries','trip_manual_routes','all_trips_guest_access','trip_invitations'];
    $issues=[];
    foreach($tables as $table){try{$db->query('SELECT 1 FROM '.$table.' LIMIT 1');}catch(PDOException){$issues[]='Datenbankmigration fehlt: '.$table;}}
    foreach([['users','can_import'],['trips','publication_status'],['trips','manual_starts_on']] as [$table,$column])
        if(!device_sync_has_column($db,$table,$column))$issues[]='Datenbankmigration fehlt: '.$table.'.'.$column;
    $q=$db->prepare('SELECT email,is_admin,can_import FROM users WHERE id=?');$q->execute([$owner]);$user=$q->fetch();$email=strtolower((string)($user['email']??''));$admin=(bool)($user['is_admin']??false);
    $main=$admin&&in_array($email,array_map(static fn($s)=>strtolower(trim((string)$s)),(array)($config['admin_emails']??[])),true);
    $q=$db->prepare('SELECT COUNT(*) FROM oauth_tokens WHERE user_id=?');$q->execute([$owner]);$oneDrive=(int)$q->fetchColumn()>0;
    if(!$oneDrive&&($admin||!empty($user['can_import'])))$issues[]='Am Server fehlt die Microsoft-/OneDrive-Anmeldung für dieses Konto.';
    return ['protocol'=>1,'capabilities'=>['administration'=>$admin,'serverRead'=>true,'bulkTransfer'=>true,'revisionSync'=>true,'steps'=>true,'familyCircle'=>false,'familySharing'=>false],
        'ready'=>count($issues)===0,'issues'=>$issues,'canImport'=>$admin||(bool)($user['can_import']??false),'canManageAccounts'=>$main,'canApproveTrips'=>$main,'oneDriveConfigured'=>$oneDrive,
        'note'=>'Die gespeicherte Microsoft-Verbindung muss gültig sein und auf dieselben OneDrive-Originale wie die App zugreifen können.'];
}
