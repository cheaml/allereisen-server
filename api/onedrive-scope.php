<?php
declare(strict_types=1);

/** Preparation for the automation worker; no HTTP, tokens or file operations.
 * The caller must supply fresh Graph metadata, never a client-supplied path.
 * Unknown IDs, shortcuts, changed parent chains and other drives fail closed.
 */
interface AutomationOneDriveScope
{
    public function assertAllowed(array $chain, bool $allowRoot=false): void;
}

final class PhotosOneDriveScope implements AutomationOneDriveScope
{
    public const ROOT_NAME = 'Photos';
    private string $driveId;
    private string $rootId;

    public function __construct(array $photosRoot)
    {
        $parent=$photosRoot['parentReference']??[];
        if (($photosRoot['name']??null)!==self::ROOT_NAME || !isset($photosRoot['folder'])
            || isset($photosRoot['remoteItem']) || empty($photosRoot['id']) || empty($parent['driveId'])
            || !in_array($parent['path']??null, ['/drive/root','/drive/root:','/drives/'.($parent['driveId']??'').'/root','/drives/'.($parent['driveId']??'').'/root:'],true)) {
            throw new RuntimeException('Der bestätigte OneDrive-Hauptordner Photos fehlt. Zugriff gesperrt.');
        }
        $this->driveId=(string)$parent['driveId'];
        $this->rootId=(string)$photosRoot['id'];
    }

    /** $chain: item first, then its parents, ending with the freshly read Photos root. */
    public function assertAllowed(array $chain, bool $allowRoot=false): void
    {
        if (!$chain || count($chain)>128) throw new RuntimeException('OneDrive-Ordnerzugehörigkeit nicht bestätigt.');
        $seen=[];
        foreach ($chain as $index=>$item) {
            $id=$item['id']??null;$parent=$item['parentReference']??[];
            if (!is_string($id)||$id===''||isset($seen[$id])||isset($item['remoteItem'])
                ||($parent['driveId']??null)!==$this->driveId) throw new RuntimeException('OneDrive-Zugriff außerhalb Photos gesperrt.');
            $seen[$id]=true;
            if ($index>0 && !isset($item['folder'])) throw new RuntimeException('Ungültige OneDrive-Elternkette.');
            if ($id===$this->rootId) {
                if ($index!==count($chain)-1 || ($item['name']??null)!==self::ROOT_NAME || !isset($item['folder'])
                    || !in_array($parent['path']??null,['/drive/root','/drive/root:','/drives/'.$this->driveId.'/root','/drives/'.$this->driveId.'/root:'],true)
                    || ($index===0&&!$allowRoot)) throw new RuntimeException('Der Photos-Hauptordner darf nicht verändert werden.');
                return;
            }
            if (($parent['id']??null)!==($chain[$index+1]['id']??null)) throw new RuntimeException('OneDrive-Datei nicht innerhalb Photos.');
        }
        throw new RuntimeException('OneDrive-Datei nicht innerhalb Photos.');
    }

    public static function assertRelativeFolder(string $path): void
    {
        $parts=explode('/',$path);
        if (($parts[0]??'')!==self::ROOT_NAME) throw new RuntimeException('Nur Photos und seine Unterordner sind erlaubt.');
        foreach($parts as $part) if($part===''||$part==='.'||$part==='..'||preg_match('/[\\\\\x00-\x1f%]/',$part))
            throw new RuntimeException('Ungültiger Photos-Unterordner.');
    }
}
