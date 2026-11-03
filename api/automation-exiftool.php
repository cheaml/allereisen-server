<?php
declare(strict_types=1);
/** Fixed read-only invocation; caller provides a private temporary original,
 * after checking Photos membership and source version. No user-supplied flags.
 */
function automation_read_exiftool(string $path,?string $tool=null,?string $perl=null):array
{
    // Default: <home>/reise-automation-tools/... next to public_html (api/ is <home>/public_html/<site>/api).
    $tool??=(getenv('REISE_EXIFTOOL')?:dirname(__DIR__,3).'/reise-automation-tools/exiftool-13.59/exiftool');
    $perl??='/usr/bin/perl';
    if(!is_file($path)||!is_file($tool))throw new RuntimeException('original_metadata_tool_unavailable');
    $command=[$perl,$tool,'-config','','-j','-n','-G1','-Make','-Model','-DateTimeOriginal','-OffsetTimeOriginal','-SubSecDateTimeOriginal',
        '-GPSLatitude','-GPSLongitude','-GPSLatitudeRef','-GPSLongitudeRef','-GPSDateTime','-ImageWidth','-ImageHeight','-Keys:CreationDate','-Keys:Make','-Keys:Model','-FileType','--',$path];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('original_metadata_tool_unavailable');
    fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    $output='';$start=microtime(true);$exit=-1;
    try {
        do {
            $output.=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);
            $status=proc_get_status($process);
            if(strlen($output)>65536||microtime(true)-$start>20){proc_terminate($process,9);throw new RuntimeException('original_metadata_limit');}
            if(!$status['running']){$exit=$status['exitcode'];break;}
            usleep(10000);
        }while(true);
        $output.=stream_get_contents($pipes[1]);
    } finally {fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
    if($exit!==0||strlen($output)>65536)throw new RuntimeException('original_metadata_failed');
    $rows=json_decode($output,true,64,JSON_THROW_ON_ERROR);
    return is_array($rows[0]??null)?$rows[0]:[];
}

/** Normalize selected original tags. Container modification times never qualify. */
function automation_exiftool_metadata(array $tags):array
{
    $pick=static function(array $names)use($tags){foreach($names as $name)if(isset($tags[$name])&&$tags[$name]!=='')return $tags[$name];return null;};
    $type=$pick(['File:FileType']);$video=in_array($type,['MOV','MP4','M4V'],true);
    $make=$pick($video?['Keys:Make']:['IFD0:Make']);$model=$pick($video?['Keys:Model']:['IFD0:Model']);
    $raw=$pick($video?['Keys:CreationDate']:['Composite:SubSecDateTimeOriginal','ExifIFD:DateTimeOriginal']);
    $offset=$pick(['ExifIFD:OffsetTimeOriginal']);
    $timeSource='original-offset';
    // Older cameras omit OffsetTimeOriginal. Derive it only from a consistent
    // original GPS UTC clock, never from upload time, filename or host timezone.
    if(!$video&&is_string($raw)&&$offset===null&&preg_match('/^(\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2})(?:\.\d{1,7})?$/D',$raw,$localParts)){
        $gps=$pick(['Composite:GPSDateTime']);
        if(is_string($gps)&&preg_match('/^(\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2})(?:\.\d{1,7})?Z$/D',$gps,$gpsParts)){
            $parse=static function(string $value):?DateTimeImmutable{$d=DateTimeImmutable::createFromFormat('!Y:m:d H:i:s',$value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();return $d&&($errors===false||!$errors['warning_count']&&!$errors['error_count'])?$d:null;};
            $local=$parse($localParts[1]);$utc=$parse($gpsParts[1]);
            if($local&&$utc){$difference=$local->getTimestamp()-$utc->getTimestamp();$rounded=(int)round($difference/900)*900;
                if(abs($rounded)<=14*3600&&abs($difference-$rounded)<=2){$minutes=(int)(abs($rounded)/60);$offset=sprintf('%s%02d:%02d',$rounded<0?'-':'+',intdiv($minutes,60),$minutes%60);$timeSource='original-gps-utc-offset';}
            }
        }
    }
    $taken=null;
    if(is_string($raw)) {
        if(!preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',$raw)&&is_string($offset))$raw.=$offset;
        if(preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d{1,7})?(Z|[+-](?:0[0-9]|1[0-3]):[0-5][0-9]|[+-]14:00)$/D',$raw,$m)) {
            $stamp="$m[1]-$m[2]-$m[3]T$m[4]$m[5]";
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$stamp);$errors=DateTimeImmutable::getLastErrors();
            if($date!==false&&($errors===false||!$errors['warning_count']&&!$errors['error_count']))$taken=$date->format(DATE_ATOM);
        }
    }
    $coordinate=static function($value,int $limit):?float{return (is_int($value)||is_float($value))&&is_finite((float)$value)&&abs($value)<=$limit?(float)$value:null;};
    $gps=static function(string $axis,int $limit)use($tags,$coordinate):?float {
        if(isset($tags['Composite:GPS'.$axis]))return $coordinate($tags['Composite:GPS'.$axis],$limit);
        $value=$coordinate($tags['GPS:GPS'.$axis]??null,$limit);$ref=$tags['GPS:GPS'.$axis.'Ref']??null;
        if($value===null||!in_array($ref,$axis==='Latitude'?['N','S']:['E','W'],true))return null;
        return in_array($ref,['S','W'],true)?-abs($value):abs($value);
    };
    $integer=static function($value):?int{return is_int($value)&&$value>0?$value:null;};
    return ['kind'=>$video?'video':'photo','cameraMake'=>is_string($make)?trim($make):null,'cameraModel'=>is_string($model)?trim($model):null,
        'width'=>$integer($pick(['File:ImageWidth','QuickTime:ImageWidth','Track1:ImageWidth','PNG:ImageWidth','ExifIFD:ExifImageWidth','IFD0:ImageWidth','System:ImageWidth'])),
        'height'=>$integer($pick(['File:ImageHeight','QuickTime:ImageHeight','Track1:ImageHeight','PNG:ImageHeight','ExifIFD:ExifImageHeight','IFD0:ImageHeight','System:ImageHeight'])),
        'takenAt'=>$taken,'captureTimeSource'=>$taken!==null?$timeSource:null,'latitude'=>$gps('Latitude',90),'longitude'=>$gps('Longitude',180)];
}
