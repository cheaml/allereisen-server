<?php
declare(strict_types=1);

function trip_steps_validate(mixed $encoded, string $day): string {
    if(!is_string($encoded)||strlen($encoded)>1024)throw new RuntimeException('Ungültige Schrittzahlen.',422);
    try{$value=json_decode($encoded,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('Ungültige Schrittzahlen.',422);}
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
    if(!$date||$date->format('Y-m-d')!==$day||$day<'1900-01-01'||!is_array($value)||($value['day']??null)!==$day||!is_string($value['person']??null)||trim($value['person'])===''||strlen($value['person'])>480||preg_match('/[\x00-\x1f\x7f]/',$value['person'])||($value['source']??null)!=='Apple Health'||!is_int($value['count']??null)||$value['count']<0||$value['count']>500000||!is_string($value['timezone']??null)||strlen($value['timezone'])<1||strlen($value['timezone'])>100)throw new RuntimeException('Ungültige Schrittzahlen.',422);
    return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function trip_steps_read(PDO $db,string $trip,string $day): ?string {
    $q=$db->prepare('SELECT payload FROM trip_day_steps WHERE trip_id=? AND day_date=?');$q->execute([$trip,$day]);
    $value=$q->fetchColumn();return $value===false?null:(string)$value;
}
