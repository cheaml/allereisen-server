<?php
declare(strict_types=1);
require_once __DIR__.'/onedrive-scope.php';
require_once __DIR__.'/automation-copy.php';

/** Selected folders in the authenticated user's drive; never remote shortcuts.
 * The general travel automation reads only (target null); a target is kept for
 * the copy capability that the shared copy port still offers. */
final class TravelOneDriveScope implements AutomationOneDriveScope {
    public function __construct(private array $root,private string $source,private ?string $target=null) {
        if(empty($root['id'])||!isset($root['root'],$root['folder'])||isset($root['remoteItem'])||empty($root['parentReference']['driveId']))throw new RuntimeException('drive_root_not_confirmed');
        self::assertPath($source,true);
        if($target!==null){self::assertPath($target);if(self::within($source,$target))throw new RuntimeException('source_inside_target');}
    }
    public static function assertPath(string $path,bool $allowRoot=false):void {
        if($allowRoot&&$path==='')return;
        if(strlen($path)>2000)throw new InvalidArgumentException('invalid_folder_path');
        foreach(explode('/',$path) as $part)if(!automation_valid_copy_name($part)||str_contains($part,'%'))throw new InvalidArgumentException('invalid_folder_path');
    }
    public static function within(string $path,string $parent):bool {
        return $parent===''||strcasecmp($path,$parent)===0||str_starts_with(strtolower($path),strtolower($parent).'/');
    }
    public function path(array $chain):string {
        if(!$chain||count($chain)>128)throw new RuntimeException('invalid_parent_chain');
        $seen=[];$names=[];$last=count($chain)-1;
        foreach($chain as $i=>$item){
            $id=$item['id']??null;
            if(!is_string($id)||$id===''||isset($seen[$id])||isset($item['remoteItem'])||($item['parentReference']['driveId']??null)!==$this->root['parentReference']['driveId'])throw new RuntimeException('drive_membership_changed');
            $seen[$id]=true;
            if($i>0&&!isset($item['folder']))throw new RuntimeException('invalid_parent_chain');
            if($i===$last){if($id!==$this->root['id']||!isset($item['root'],$item['folder']))throw new RuntimeException('drive_root_changed');}
            else{if(($item['parentReference']['id']??null)!==$chain[$i+1]['id'])throw new RuntimeException('parent_changed');self::assertPath((string)($item['name']??''));$names[]=$item['name'];}
        }
        return implode('/',array_reverse($names));
    }
    public function assertAllowed(array $chain,bool $allowRoot=false):void {
        $path=$this->path($chain);
        if($path===''&&!$allowRoot)throw new RuntimeException('drive_root_is_not_media');
        if(self::within($path,$this->source)||$this->target!==null&&self::within($path,$this->target))return;
        if($allowRoot&&isset($chain[0]['folder'])&&(self::within($this->source,$path)||$this->target!==null&&self::within($this->target,$path)))return;
        throw new RuntimeException('outside_selected_folders');
    }
    public function assertTransfer(array $source,array $destination):void {
        if($this->target===null)throw new RuntimeException('transfer_disabled_read_only_scope');
        $from=$this->path($source);$to=$this->path($destination);
        if(!self::within($from,$this->source)||self::within($from,$this->target)||!self::within($to,$this->target)||strcasecmp($to,$this->target)===0||!isset($source[0]['file'],$destination[0]['folder']))throw new RuntimeException('transfer_outside_selected_folders');
    }
}
