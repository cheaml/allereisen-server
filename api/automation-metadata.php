<?php
declare(strict_types=1);

/** Pure normalization of original JPEG EXIF. Missing offsets stay missing;
 * neither the server timezone nor file modification time supplies capture time.
 */
function automation_jpeg_metadata(array $exif): array
{
    $ifd=$exif['IFD0']??[];$details=$exif['EXIF']??[];$gps=$exif['GPS']??[];
    $text=static function($value):?string {return is_string($value)&&trim($value)!==''?trim($value):null;};
    $rational=static function($value):?float {
        if(is_int($value)||is_float($value))return is_finite((float)$value)?(float)$value:null;
        if(!is_string($value)||!preg_match('/^([0-9]+(?:\.[0-9]+)?)(?:\/([0-9]+(?:\.[0-9]+)?))?$/D',$value,$m))return null;
        $den=isset($m[2])?(float)$m[2]:1.0;
        return $den>0?(float)$m[1]/$den:null;
    };
    $coordinate=static function($parts,$ref,bool $latitude)use($rational):?float {
        if(!is_array($parts)||count($parts)!==3||!in_array($ref,$latitude?['N','S']:['E','W'],true))return null;
        $values=array_map($rational,array_values($parts));
        if(in_array(null,$values,true)||$values[0]<0||$values[1]<0||$values[1]>=60||$values[2]<0||$values[2]>=60)return null;
        $degrees=$values[0]+$values[1]/60+$values[2]/3600;
        if($degrees>($latitude?90:180))return null;
        return in_array($ref,['S','W'],true)?-$degrees:$degrees;
    };
    $local=$text($details['DateTimeOriginal']??null);$offset=$text($details['OffsetTimeOriginal']??null);$taken=null;
    if($local!==null&&$offset!==null&&preg_match('/^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/D',$local)&&preg_match('/^[+-](?:0[0-9]|1[0-4]):[0-5][0-9]$/D',$offset)&&(!str_starts_with(substr($offset,1),'14:')||substr($offset,4)==='00')) {
        $parsed=DateTimeImmutable::createFromFormat('!Y:m:d H:i:sP',$local.$offset);
        if($parsed!==false&&$parsed->format('Y:m:d H:i:sP')===$local.$offset)$taken=$parsed->format(DATE_ATOM);
    }
    $width=$details['ExifImageWidth']??$exif['COMPUTED']['Width']??null;
    $height=$details['ExifImageLength']??$exif['COMPUTED']['Height']??null;
    $positive=static function($v):?int{return filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false?(int)$v:null;};
    return ['kind'=>'photo','cameraMake'=>$text($ifd['Make']??null),'cameraModel'=>$text($ifd['Model']??null),
        'width'=>$positive($width),'height'=>$positive($height),'takenAt'=>$taken,
        'latitude'=>$coordinate($gps['GPSLatitude']??null,$gps['GPSLatitudeRef']??null,true),
        'longitude'=>$coordinate($gps['GPSLongitude']??null,$gps['GPSLongitudeRef']??null,false)];
}
