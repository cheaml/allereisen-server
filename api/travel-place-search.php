<?php
declare(strict_types=1);
/* Place search for the automation settings page (home places): OpenStreetMap Nominatim,
 * called by the server so the browser needs no third-party connection. Results are cached and
 * requests share the one-per-second lock with the station names (api.php). */

/** Short place label from a Nominatim result: street and number or name, then the locality. */
function travel_place_label(array $result):string {
    $a=is_array($result['address']??null)?$result['address']:[];
    $locality='';foreach(['city','town','village','municipality','suburb','county','state','country'] as $key)if(is_string($a[$key]??null)&&$a[$key]!==''){$locality=$a[$key];break;}
    $street=trim((string)($a['road']??'').' '.(string)($a['house_number']??''));
    $name=is_string($result['name']??null)&&$result['name']!==''?$result['name']:($street!==''?$street:'');
    if($name===''){$parts=explode(',',(string)($result['display_name']??''));$name=trim($parts[0]);}
    $label=$name!==''&&strcasecmp($name,$locality)!==0&&$locality!==''?$name.', '.$locality:($name!==''?$name:$locality);
    return mb_substr(trim($label),0,60);
}

/** @return array decoded JSON or null when the service is unavailable. */
function travel_place_request(array $config,string $endpoint,array $query):?array {
    $directory=__DIR__.'/cache/geocode';
    if(!is_dir($directory)&&!@mkdir($directory,0750,true))return null;
    $guard=__DIR__.'/cache/.htaccess';
    if(!is_file($guard)&&@file_put_contents($guard,"Require all denied\nOptions -Indexes\n")===false)return null;
    $path=$directory.'/'.$endpoint.'-'.hash('sha256',json_encode($query)).'.json';
    $cached=@file_get_contents($path);if($cached!==false){$data=json_decode($cached,true);if(is_array($data))return $data;}
    $lock=@fopen($directory.'/.request-lock','c+');if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);return null;}
    try{
        rewind($lock);$last=(float)stream_get_contents($lock);
        $delay=1.1-(microtime(true)-$last);if($delay>0)usleep((int)ceil($delay*1000000));
        $base=$endpoint==='search'?($config['nominatim_search_url']??'https://nominatim.openstreetmap.org/search'):($config['nominatim_reverse_url']??'https://nominatim.openstreetmap.org/reverse');
        $ch=curl_init($base.'?'.http_build_query($query+['format'=>'jsonv2','addressdetails'=>1,'accept-language'=>'de,en'],'','&',PHP_QUERY_RFC3986));
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: AlleReisen/1.0'.(!empty($config['app_url'])?' ('.$config['app_url'].')':'')],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        rewind($lock);ftruncate($lock,0);fwrite($lock,(string)microtime(true));fflush($lock);
        $data=$status===200?json_decode((string)$raw,true):null;
        if(!is_array($data))return null;
        @file_put_contents($path,json_encode($data,JSON_UNESCAPED_UNICODE));return $data;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

/** Up to five places for a search text: [{label,detail,latitude,longitude}]. */
function travel_place_search(array $config,string $text):?array {
    $text=trim(preg_replace('/\s+/u',' ',$text)??'');
    if(mb_strlen($text)<2||mb_strlen($text)>120)return [];
    $data=travel_place_request($config,'search',['q'=>$text,'limit'=>5]);if($data===null)return null;
    $places=[];
    foreach(array_slice(array_is_list($data)?$data:[],0,5) as $result){
        $lat=$result['lat']??null;$lon=$result['lon']??null;if(!is_numeric($lat)||!is_numeric($lon))continue;
        $places[]=['label'=>travel_place_label($result),'detail'=>mb_substr((string)($result['display_name']??''),0,200),'latitude'=>round((float)$lat,6),'longitude'=>round((float)$lon,6)];
    }
    return $places;
}

/** Short label of the place at a coordinate (for a point picked on the map). */
function travel_place_reverse(array $config,float $latitude,float $longitude):?string {
    if(abs($latitude)>90||abs($longitude)>180)return null;
    $data=travel_place_request($config,'reverse',['lat'=>round($latitude,5),'lon'=>round($longitude,5),'zoom'=>16]);
    return $data===null||isset($data['error'])?null:travel_place_label($data);
}
