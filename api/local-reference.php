<?php
declare(strict_types=1);
function import_local_references(PDO $db,array $config,int $owner,array $input): array {
    if(!defined('ATLAS_LOCAL_MODE')||!ATLAS_LOCAL_MODE)throw new RuntimeException('Lokale Originale benötigen die Anwendung auf diesem Gerät.',409);
    set_time_limit(0);
    $folder=realpath(trim((string)($input['source_folder']??'')));
    if(!$folder||!is_dir($folder))throw new InvalidArgumentException('Bitte einen erreichbaren lokalen Medienordner auswählen.',422);
    $python=(string)($config['local_python']??'');if(!is_file($python))throw new RuntimeException('Die lokale Importhilfe ist nicht eingerichtet.',409);
    $base=dirname(__DIR__).'/local-trip';$manifest=$base.'/data/reference-import-'.bin2hex(random_bytes(12)).'.json';
    $directory=__DIR__.'/cache/local-previews';if(!is_dir($directory))mkdir($directory,0750,true);
    file_put_contents(__DIR__.'/cache/.htaccess',"Require all denied\nOptions -Indexes\n");
    $command=[$python,$base.'/import-photos.py',$folder,'--manifest',$manifest,'--previews',$directory];
    if(!empty($config['local_heif_converter']))array_push($command,'--heif-converter',$config['local_heif_converter']);
    $log=fopen($base.'/data/reference-import.log','ab');
    $process=proc_open($command,[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
    if(!is_resource($process))throw new RuntimeException('Die lokale Importhilfe konnte nicht gestartet werden.');fclose($pipes[0]);
    $code=proc_close($process);fclose($log);
    try{
        if($code!==0||!is_file($manifest))throw new RuntimeException('Der Ordner konnte nicht gelesen werden. Details stehen im lokalen Importprotokoll.',422);
        $data=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);$items=[];
        foreach($data['photos'] as $photo){
            $path=realpath($folder.'/'.$photo['relative_path']);
            if(!$path||!str_starts_with(strtolower($path),strtolower($folder.DIRECTORY_SEPARATOR)))continue;
            $video=($photo['media_type']??'photo')==='video';$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
            $items[]=['id'=>$photo['id'],'name'=>$photo['name'],$video?'video':'photo'=>[],
              'location'=>['latitude'=>$photo['latitude'],'longitude'=>$photo['longitude']],
              '_capture'=>['date'=>$photo['taken_at']??capture_filename_time($photo['name']),'source'=>$photo['taken_at']?'exif':(capture_filename_time($photo['name'])?'filename':'unknown')],
              'local_original_path'=>$path,'local_original_root'=>$folder,'mime_type'=>$mime,
              'storage_path'=>$video?null:'cache/local-previews/'.$photo['id'].'.jpg'];
        }
        if(!$items)throw new RuntimeException('Keine unterstützten Medien gefunden.',422);
        $record=source_trip($db,$owner,(string)($input['title']??''),'local',$folder,(string)($input['trip']??''));
        $added=source_append($db,$record['id'],'local',$items);
        return ['trip'=>source_trip_result($db,$record['id']),'added_media'=>$added,'skipped'=>count($data['errors']??[]),'errors'=>$data['errors']??[]];
    }finally{if(is_file($manifest))unlink($manifest);}
}
