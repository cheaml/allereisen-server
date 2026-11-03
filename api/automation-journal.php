<?php
declare(strict_types=1);

/** Per-source durable journal. Callers hold the file lock across each operation.
 * Configure the directory outside the website document root.
 */
final class AutomationJournal
{
    public function __construct(private string $directory)
    {
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Journal directory unavailable.');
        $resolved=realpath($directory);
        if($resolved===false||preg_match('~[\\\\/]public_html(?:[\\\\/]|$)~i',$resolved))throw new RuntimeException('Journal must be private.');
        $this->directory=$resolved;
    }

    public static function key(string $account,string $drive,string $item):string
    {
        foreach([$account,$drive,$item] as $part)if($part==='')throw new InvalidArgumentException('Missing source identity.');
        return hash('sha256',json_encode([$account,$drive,$item],JSON_THROW_ON_ERROR));
    }
    public function reserveName(string $account,string $drive,string $destination,string $name,string $sourceKey):bool
    {
        $folded=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
        $key=hash('sha256',json_encode([$account,$drive,$destination,$folded],JSON_THROW_ON_ERROR));
        $file=fopen($this->directory.'/name-'.$key.'.reservation','c+');if($file===false)throw new RuntimeException('Name reservation unavailable.');
        try{
            if(!flock($file,LOCK_EX))throw new RuntimeException('Name reservation lock unavailable.');
            $saved=stream_get_contents($file);
            if($saved!=='')return hash_equals(trim($saved),$sourceKey);
            if(fwrite($file,$sourceKey."\n")!==strlen($sourceKey)+1||!fflush($file))throw new RuntimeException('Name reservation write incomplete.');
            if(function_exists('fsync')&&!fsync($file))throw new RuntimeException('Name reservation sync failed.');
            return true;
        }finally{flock($file,LOCK_UN);fclose($file);}
    }

    public function locked(string $account,string $drive,string $item,callable $work):array
    {
        $key=self::key($account,$drive,$item);$path=$this->directory.'/'.$key.'.json';
        $lock=fopen($this->directory.'/'.$key.'.lock','c');
        if($lock===false)throw new RuntimeException('Journal lock unavailable.');
        @chmod($this->directory.'/'.$key.'.lock',0600);
        try {
            if(!flock($lock,LOCK_EX|LOCK_NB))return ['status'=>'busy'];
            $record=is_file($path)?json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR):
                ['schema'=>1,'key'=>$key,'account'=>$account,'drive'=>$drive,'item'=>$item,'status'=>'discovered'];
            if(($record['schema']??null)!==1||($record['key']??null)!==$key||($record['account']??null)!==$account||($record['drive']??null)!==$drive||($record['item']??null)!==$item)
                throw new RuntimeException('Journal identity mismatch.');
            $save=function(array $next)use($path,$key,$account,$drive,$item):void {
                if(($next['schema']??null)!==1||($next['key']??null)!==$key||($next['account']??null)!==$account||($next['drive']??null)!==$drive||($next['item']??null)!==$item)
                    throw new RuntimeException('Journal identity cannot change.');
                $next['updatedAt']=gmdate(DATE_ATOM);
                $json=json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                $tmp=tempnam($this->directory,'pending-');if($tmp===false)throw new RuntimeException('Journal write unavailable.');
                try {
                    @chmod($tmp,0600);$file=fopen($tmp,'wb');if($file===false)throw new RuntimeException('Journal write unavailable.');
                    try {if(fwrite($file,$json)!==strlen($json)||!fflush($file))throw new RuntimeException('Journal write incomplete.');if(function_exists('fsync')&&!fsync($file))throw new RuntimeException('Journal sync failed.');}
                    finally {fclose($file);}
                    if(!rename($tmp,$path))throw new RuntimeException('Journal commit failed.');
                }finally {if(is_file($tmp))unlink($tmp);}
            };
            return $work($record,$save);
        }finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
