<?php
declare(strict_types=1);
function trip_manual_dates(array $fields):?array {
    if(!array_key_exists('manual_starts_on',$fields)&&!array_key_exists('manual_ends_on',$fields))return null;
    if(!array_key_exists('manual_starts_on',$fields)||!array_key_exists('manual_ends_on',$fields))throw new RuntimeException('Bitte den Reisezeitraum vollständig angeben.',422);
    $start=$fields['manual_starts_on'];$end=$fields['manual_ends_on'];
    $valid=static function($value):bool{if(!is_string($value)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)||(int)substr($value,0,4)<1)return false;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $date!==false&&$date->format('Y-m-d')===$value;};
    if($start===null?$end!==null:!$valid($start)||$end!==null&&(!$valid($end)||strcmp($end,$start)<0))throw new RuntimeException('Bitte einen gültigen Reisezeitraum angeben; das Ende darf nicht vor dem Beginn liegen.',422);
    return [$start,$end];
}
function trip_manual_dates_supported(PDO $db):bool {
    static $cache=[];$key=spl_object_id($db);if(array_key_exists($key,$cache))return $cache[$key];
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$cols=array_column($db->query('PRAGMA table_info(trips)')->fetchAll(PDO::FETCH_ASSOC),'name');return $cache[$key]=in_array('manual_starts_on',$cols,true)&&in_array('manual_ends_on',$cols,true);}
    $q=$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='trips' AND COLUMN_NAME IN ('manual_starts_on','manual_ends_on')");return $cache[$key]=(int)$q->fetchColumn()===2;
}
