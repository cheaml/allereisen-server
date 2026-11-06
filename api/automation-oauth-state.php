<?php
declare(strict_types=1);
/** The general travel automation (selected folders) only reads originals and asks
 * Microsoft for Files.Read. The personal Photos automation copies and keeps
 * Files.ReadWrite. A read-write grant also satisfies reading, so connections made
 * before the switch keep working until they are renewed. */
function automation_oauth_scope(bool $selected):string
{
    return 'openid profile offline_access User.Read '.($selected?'Files.Read':'Files.ReadWrite');
}
function automation_oauth_granted(string $granted,bool $selected):bool
{
    $scopes=explode(' ',$granted);
    return in_array('Files.ReadWrite',$scopes,true)||$selected&&in_array('Files.Read',$scopes,true);
}
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
