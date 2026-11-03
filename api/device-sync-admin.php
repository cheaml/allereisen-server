<?php
declare(strict_types=1);

// Called only after device-sync.php has authenticated the bearer token and
// rechecked the Microsoft admin role specifically for administration. Import
// permission alone never permits these operations. Never accept cookies here.
function device_sync_admin_operation(string $operation,string $method): bool {
    $allowed=['admin-accounts'=>['GET','POST','DELETE'],'import-permissions'=>['GET','PATCH'],
        'trip-review'=>['GET','POST'],'all-trips-guests'=>['GET','POST','DELETE'],
        'trip-guests'=>['GET','DELETE'],'trip-invite'=>['POST']];
    return in_array($method,$allowed[$operation]??[],true);
}
