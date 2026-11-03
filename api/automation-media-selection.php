<?php
declare(strict_types=1);

/** Collection policy shared by manual and scheduled production runs. */
function automation_collection_extension(array $item):?string
{
    if(!isset($item['file'])||isset($item['remoteItem']))return null;
    $extension=strtolower(pathinfo((string)($item['name']??''),PATHINFO_EXTENSION));
    if(isset($item['video']))return in_array($extension,['mov','mp4','m4v'],true)?$extension:null;
    if(isset($item['image'])||isset($item['photo']))return in_array($extension,['jpg','jpeg','heic','heif'],true)?$extension:null;
    return null;
}
