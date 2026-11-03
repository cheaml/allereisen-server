<?php
declare(strict_types=1);
require_once __DIR__.'/automation-catalog.php';
require_once __DIR__.'/automation-pipeline.php';

/** Source-only port: no destination, copy, delete or recycle capability. */
interface OriginalReferencePort {
    public function source(string $id):array;
    public function hash(array $item):string;
    public function register(array $proof,array $source):string;
}
final class CallbackOriginalReferencePort implements OriginalReferencePort {
    public function __construct(private Closure $read,private Closure $digest,private Closure $save){}
    public function source(string $id):array{return ($this->read)($id);}
    public function hash(array $item):string{return ($this->digest)($item);}
    public function register(array $proof,array $source):string{return ($this->save)($proof,$source);}
}
function automation_original_reference(OriginalReferencePort $port,string $account,string $drive,string $trip,array $media):array
{
    if(($media['accountId']??null)!==$account||($media['driveId']??null)!==$drive)throw new RuntimeException('original_account_mismatch');
    $item=$port->source((string)$media['itemId']);
    if(($item['id']??null)!==$media['itemId']||($item['eTag']??null)!==$media['version']||($item['parentReference']['driveId']??null)!==$drive||!isset($item['file'])||isset($item['remoteItem'])||($item['size']??0)<=0)throw new RuntimeException('original_identity_or_version_changed');
    $hash=$port->hash($item);if(!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new RuntimeException('original_hash_invalid');
    $fresh=$port->source($item['id']);if(($fresh['eTag']??null)!==$item['eTag']||($fresh['parentReference']['id']??null)!==($item['parentReference']['id']??null))throw new RuntimeException('original_changed_during_verification');
    $proof=['mode'=>'original-reference','status'=>'verified','key'=>hash('sha256',json_encode([$account,$drive,$trip,$item['id']],JSON_THROW_ON_ERROR)),
        'account'=>$account,'drive'=>$drive,'item'=>$item['id'],'version'=>$item['eTag'],'sourceHash'=>$hash,'targetHash'=>$hash,'sourceBytes'=>(int)$item['size'],'targetId'=>$item['id'],'targetVersion'=>$item['eTag']];
    $registered=$port->register($proof,$fresh);if($registered!==$trip)throw new RuntimeException('original_membership_not_confirmed');
    return ['status'=>'imported','tripId'=>$trip,'mode'=>'original-reference'];
}

function automation_register_original(PDO $db,int $owner,string $token,array $job,array $trip,AutomationOneDriveScope $scope,Closure $get,string $fields,string $private,?string $tool=null,?string $perl=null):array
{
    $read=null;
    $source=static function(string $id)use(&$read,$job,$get,$token,$scope,$fields,$private,$tool,$perl):array{
        if($id!==$job['media']['itemId'])throw new RuntimeException('original_capability_mismatch');
        if($read===null)$read=pipeline_metadata($get,$token,$job['chain'],$scope,$fields,$private,$tool,$perl);
        $chain=$read['chain'];foreach($chain as &$part){$before=$part;$part=$get('me/drive/items/'.rawurlencode($part['id']).'?$select='.$fields);if(isset($before['root'],$part['root'])&&empty($part['parentReference']['driveId']))$part['parentReference']['driveId']=$before['parentReference']['driveId'];}unset($part);$scope->assertAllowed($chain);return $chain[0];
    };
    $port=new CallbackOriginalReferencePort($source,
        static function(array $item)use(&$read):string{if(($read['chain'][0]['eTag']??null)!==$item['eTag'])throw new RuntimeException('original_changed_during_verification');return $read['hash'];},
        static function(array $proof,array $item)use(&$read,$db,$owner,$trip,$job):string{return automation_catalog_import($db,$owner,['id'=>$trip['id'],'title'=>$trip['title'],'source_folder'=>'reise://'.$trip['id']],$proof,array_replace($job['media'],$read['metadata']),$item,null,true);});
    return automation_original_reference($port,$job['media']['accountId'],$job['media']['driveId'],$trip['id'],$job['media']);
}
