<?php
declare(strict_types=1);
require_once __DIR__.'/automation-journal.php';
function automation_valid_copy_name(mixed $name):bool {
    return is_string($name)&&$name!==''&&strlen($name)<=1024&&$name===trim($name)&&!in_array($name,['.','..'],true)&&!str_ends_with($name,'.')&&preg_match('~[\\\\/\x00-\x1f:"<>|?*]~u',$name)===0;
}

/** Each port method must enforce fresh Photos membership and same drive.
 * There is intentionally no delete operation in the copy/import test worker.
 */
interface AutomationCopyPort
{
    /** Fresh original metadata: id, eTag, size, parentReference. */
    public function source(string $id):array;
    /** Confirm destination folder is inside Photos. */
    public function destination(string $id):array;
    /** Full original content SHA-256, read conditionally on eTag. */
    public function hash(array $item):string;
    /** At most one copy submission. Async acceptance is not copy completion. */
    public function copy(array $source,array $destination,string $name):void;
    /** Fresh target lookup, scoped to the expected destination. */
    public function target(array $destination,string $name):?array;
    /** Transactional/idempotent catalog import and identity mapping; return trip ID. */
    public function import(array $record,array $target):string;
}

final class AutomationCopyWorker
{
    public function __construct(private AutomationJournal $journal,private AutomationCopyPort $port){}

    public function run(string $account,string $drive,string $item,string $version,string $destinationId,string $extension,array $originalMetadata=[],bool $deferImport=false):array
    {
        if($version===''||$destinationId===''||!preg_match('/^[a-z0-9]{1,10}$/D',$extension))throw new InvalidArgumentException('Incomplete copy request.');
        return $this->journal->locked($account,$drive,$item,function(array $record,callable $save)use($drive,$item,$version,$destinationId,$extension,$originalMetadata,$deferImport):array {
            if(($record['version']??$version)!==$version||($record['destinationId']??$destinationId)!==$destinationId)return ['status'=>'review','reason'=>'source-version-or-destination-conflict'];
            if(($record['status']??null)==='imported')return ['status'=>'imported','tripId'=>$record['tripId']];
            if(($record['status']??null)==='review')return ['status'=>'review','reason'=>$record['reason']];
            if($deferImport&&($record['status']??null)==='verified')return ['status'=>'verified'];
            $source=$this->port->source($item);$destination=$this->port->destination($destinationId);
            if(($source['id']??null)!==$item||($source['eTag']??null)!==$version||($source['parentReference']['driveId']??null)!==$drive||
                ($destination['id']??null)!==$destinationId||($destination['parentReference']['driveId']??null)!==$drive||!isset($source['file'])||!isset($destination['folder'])||($source['size']??0)<=0)
                return ['status'=>'review','reason'=>'source-or-destination-changed'];
            if($record['status']==='discovered') {
                $name=$source['name']??null;
                if(!automation_valid_copy_name($name))return $this->review($record,$save,'invalid-original-filename');
                if(!$this->journal->reserveName($record['account'],$drive,$destinationId,$name,$record['key']))return $this->review($record,$save,'original-filename-reserved-by-another-source');
                if($this->port->target($destination,$name)!==null)return $this->review($record,$save,'original-filename-already-exists');
                $hash=$this->port->hash($source);self::assertHash($hash);
                $record+=['version'=>$version,'destinationId'=>$destinationId,'targetName'=>$name,'sourceHash'=>$hash,'sourceBytes'=>(int)$source['size'],'originalMetadata'=>$originalMetadata];
                // Commit BEFORE the network side effect. A crash or timeout must
                // resume by target lookup, never submit another blind copy.
                $record['status']='copy-intent';$save($record);
                try {$this->port->copy($source,$destination,$record['targetName']);$record['status']='copy-pending';}
                catch(Throwable $e){$record['status']='copy-uncertain';}
                $save($record);
                return ['status'=>$record['status']];
            }
            $target=$this->port->target($destination,$record['targetName']);
            if($target===null)return ['status'=>$record['status'],'reason'=>'awaiting-copy-or-manual-reconciliation'];
            if(($target['id']??null)===$item||empty($target['id'])||empty($target['eTag'])||($target['name']??null)!==$record['targetName']||
                ($target['parentReference']['driveId']??null)!==$drive||($target['parentReference']['id']??null)!==$destinationId||!isset($target['file'])||
                (int)($target['size']??0)!==$record['sourceBytes'])return $this->review($record,$save,'target-identity-or-size-mismatch');
            $targetHash=$this->port->hash($target);self::assertHash($targetHash);
            $sourceHash=$this->port->hash($source);self::assertHash($sourceHash);
            if(!hash_equals($record['sourceHash'],$targetHash)||!hash_equals($record['sourceHash'],$sourceHash))return $this->review($record,$save,'content-mismatch');
            // Recheck versions/parents after content reads, before catalog work.
            $freshSource=$this->port->source($item);$freshTarget=$this->port->target($destination,$record['targetName']);
            if(($freshSource['eTag']??null)!==$version||($freshTarget['id']??null)!==$target['id']||($freshTarget['eTag']??null)!==$target['eTag']||
                ($freshTarget['parentReference']['id']??null)!==$destinationId)return $this->review($record,$save,'version-changed-during-verification');
            $record['status']='verified';$record['targetId']=$target['id'];$record['targetVersion']=$target['eTag'];$record['targetHash']=$targetHash;$save($record);
            if($deferImport)return ['status'=>'verified'];
            // If import commits but the process dies before journal save, the
            // import adapter must return the same mapping on the next run.
            $trip=$this->port->import($record,$freshTarget);
            if($trip==='')throw new RuntimeException('Catalog import did not confirm identity mapping.');
            $record['status']='imported';$record['tripId']=$trip;$save($record);
            return ['status'=>'imported','tripId'=>$trip];
        });
    }

    private static function assertHash(string $hash):void{if(!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new RuntimeException('Invalid full content hash.');}
    private function review(array $record,callable $save,string $reason):array{$record['status']='review';$record['reason']=$reason;$save($record);return ['status'=>'review','reason'=>$reason];}
}
