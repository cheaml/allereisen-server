<?php
declare(strict_types=1);
function automation_oauth_begin(int $owner):array
{
    $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
    return ['owner'=>$owner,'state'=>'alr-auto-'.bin2hex(random_bytes(32)),'verifier'=>$verifier,'expires'=>time()+600];
}
function automation_oauth_validate(array $pending,string $state,int $owner,int $now):void
{
    if(($pending['owner']??null)!==$owner||($pending['expires']??0)<$now||!is_string($pending['state']??null)||
        !str_starts_with($state,'alr-auto-')||!hash_equals($pending['state'],$state)||strlen((string)($pending['verifier']??''))<43)
        throw new RuntimeException('Die OneDrive-Freigabe ist abgelaufen. Bitte erneut starten.');
}
