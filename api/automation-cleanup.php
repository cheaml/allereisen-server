<?php
declare(strict_types=1);
require_once __DIR__.'/automation-journal.php';
interface AutomationCleanupPort {
    public function source(string $id):array;
    public function destination(string $id):array;
    public function target(array $destination,string $name):?array;
    public function hash(array $item):string;
    public function recycleSource(array $source):void;
}
final class AutomationCleanupWorker {
    public function __construct(private AutomationJournal $journal,private AutomationCleanupPort $port,private Closure $catalogConfirmed){}
    public function run(string $account,string $drive,string $item,bool $dryRun=false):array {
        return $this->journal->locked($account,$drive,$item,function(array $r,callable $save)use($dryRun):array {
            if(($r['cleanupStatus']??null)==='recycled')return ['status'=>'recycled'];
            if(in_array($r['cleanupStatus']??null,['delete-intent','delete-uncertain'],true))return ['status'=>'review','reason'=>'cleanup-outcome-uncertain'];
            if(($r['status']??null)!=='imported')return ['status'=>'blocked','reason'=>'import-not-confirmed'];
            foreach(['version','destinationId','targetId','targetVersion','targetName','sourceHash','targetHash','tripId'] as $field)if(empty($r[$field]))return ['status'=>'blocked','reason'=>'cleanup-proof-incomplete'];
            if(!preg_match('/^[a-f0-9]{64}$/D',$r['sourceHash'])||!hash_equals($r['sourceHash'],$r['targetHash'])||($r['sourceBytes']??0)<=0||$r['item']===$r['targetId'])return ['status'=>'blocked','reason'=>'cleanup-proof-invalid'];
            if(!(($this->catalogConfirmed)($r)))return ['status'=>'blocked','reason'=>'catalog-reference-not-confirmed'];
            $source=$this->port->source($r['item']);$dest=$this->port->destination($r['destinationId']);$target=$this->port->target($dest,$r['targetName']);
            $matches=static fn(array $s,?array $t):bool=>isset($s['file'],$t['file'])&&!isset($s['folder'])&&!isset($s['remoteItem'])&&($s['id']??null)===$r['item']&&($s['eTag']??null)===$r['version']&&($s['parentReference']['driveId']??null)===$r['drive']&&($t['id']??null)===$r['targetId']&&($t['eTag']??null)===$r['targetVersion']&&($t['parentReference']['driveId']??null)===$r['drive']&&($t['parentReference']['id']??null)===$r['destinationId']&&(int)($s['size']??0)===$r['sourceBytes']&&(int)($t['size']??0)===$r['sourceBytes'];
            if(!$matches($source,$target))return ['status'=>'blocked','reason'=>'cleanup-version-or-identity-changed'];
            if(!hash_equals($r['sourceHash'],$this->port->hash($source))||!hash_equals($r['sourceHash'],$this->port->hash($target)))return ['status'=>'blocked','reason'=>'cleanup-content-mismatch'];
            $source=$this->port->source($r['item']);$target=$this->port->target($dest,$r['targetName']);
            if(!$matches($source,$target)||!(($this->catalogConfirmed)($r)))return ['status'=>'blocked','reason'=>'cleanup-proof-changed'];
            if($dryRun)return ['status'=>'eligible'];
            $r['cleanupStatus']='delete-intent';$r['cleanupRequestedAt']=gmdate(DATE_ATOM);$save($r);
            try{$this->port->recycleSource($source);}catch(Throwable $e){$r['cleanupStatus']='delete-uncertain';$save($r);return ['status'=>'review','reason'=>'cleanup-outcome-uncertain'];}
            $r['cleanupStatus']='recycled';$r['recycledAt']=gmdate(DATE_ATOM);$save($r);return ['status'=>'recycled'];
        });
    }
}

function automation_cleanup_catalog_confirmed(PDO $db,int $owner,array $r):bool {
    $q=$db->prepare('SELECT a.* FROM automation_media_map a JOIN trips t ON t.id=a.trip_id AND t.user_id=a.user_id WHERE a.source_key=? AND a.user_id=?');$q->execute([$r['key'],$owner]);$m=$q->fetch(PDO::FETCH_ASSOC);
    if(!$m)return false;
    foreach(['account_id'=>'account','drive_id'=>'drive','source_item_id'=>'item','source_version'=>'version','target_item_id'=>'targetId','target_version'=>'targetVersion','source_sha256'=>'sourceHash','target_sha256'=>'targetHash','trip_id'=>'tripId'] as $column=>$field)if(($m[$column]??null)!==$r[$field])return false;
    if((int)$m['byte_count']!==$r['sourceBytes'])return false;
    $q=$db->prepare('SELECT COUNT(*) FROM media_items WHERE trip_id=? AND onedrive_item_id=?');$q->execute([$r['tripId'],$r['targetId']]);if((int)$q->fetchColumn()<1)return false;
    $columns=array_fill_keys($db->query('SHOW COLUMNS FROM media_items')->fetchAll(PDO::FETCH_COLUMN),true);
    $q=$db->prepare('SELECT COUNT(*) FROM media_items m JOIN trips t ON t.id=m.trip_id WHERE t.user_id=? AND (m.onedrive_item_id=?'.(isset($columns['source_item_id'])?' OR m.source_item_id=?':'').')');$args=[$owner,$r['item']];if(isset($columns['source_item_id']))$args[]=$r['item'];$q->execute($args);return (int)$q->fetchColumn()===0;
}
