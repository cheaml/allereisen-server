<?php
declare(strict_types=1);
require_once __DIR__.'/onedrive-scope.php';
require_once __DIR__.'/automation-copy.php';
require_once __DIR__.'/automation-cleanup.php';

/** One job's Graph capability: one source and one destination inside Photos.
 * No arbitrary endpoint, outside path or remote shortcut is exposed. Cleanup
 * only recycles the bound camera source with an exact If-Match version.
 */
final class PhotosAutomationCopyPort implements AutomationCopyPort, AutomationCleanupPort
{
    private AutomationOneDriveScope $scope;
    private bool $selectedScope=false;
    private array $targetChains=[];
    private const FIELDS='id,name,eTag,size,file,folder,root,parentReference,remoteItem,photo,video,image,location,webUrl';
    public function __construct(private string $token,private array $sourceChain,private array $destinationChain,private Closure $importer,private ?Closure $transport=null,?AutomationOneDriveScope $scope=null)
    {
        if($scope!==null){$this->scope=$scope;$this->selectedScope=true;$scope->assertAllowed($sourceChain);$scope->assertAllowed($destinationChain);$scope->assertTransfer($sourceChain,$destinationChain);return;}
        $root=$this->request('GET','me/drive/root:/Photos?$select='.self::FIELDS)['json'];
        $this->scope=new PhotosOneDriveScope($root);
        $this->scope->assertAllowed($sourceChain);$this->scope->assertAllowed($destinationChain);
        if(($sourceChain[array_key_last($sourceChain)]['id']??null)!==$root['id']||($destinationChain[array_key_last($destinationChain)]['id']??null)!==$root['id'])throw new RuntimeException('Photos root changed.');
    }
    private function request(string $method,string $relative,?array $body=null):array
    {
        if($this->transport!==null)return ($this->transport)($method,$relative,$body);
        $ch=curl_init('https://graph.microsoft.com/v1.0/'.$relative);
        $headers=['Authorization: Bearer '.$this->token];if($body!==null)$headers[]='Content-Type: application/json';
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>25]);
        if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($raw===false)throw new RuntimeException('Graph transport uncertain.');
        if(!in_array($status,[200,201,202,404],true))throw new RuntimeException('Graph request failed: '.$status);
        return ['status'=>$status,'json'=>$raw!==''?json_decode($raw,true,64,JSON_THROW_ON_ERROR):[]];
    }
    private function fresh(array $chain,bool $allowRoot=false):array
    {
        $root=$this->request('GET',($this->selectedScope?'me/drive/root':'me/drive/root:/Photos').'?'.'$select='.self::FIELDS)['json'];
        if($this->selectedScope&&empty($root['parentReference']['driveId']))$root['parentReference']['driveId']=$chain[array_key_last($chain)]['parentReference']['driveId'];
        if(($root['id']??null)!==($chain[array_key_last($chain)]['id']??null))throw new RuntimeException('Photos root changed.');
        $chain[array_key_last($chain)]=$root;
        foreach($chain as $i=>$item)if($i!==array_key_last($chain))$chain[$i]=$this->request('GET','me/drive/items/'.rawurlencode($item['id']).'?$select='.self::FIELDS)['json'];
        $this->scope->assertAllowed($chain,$allowRoot);return $chain;
    }
    public function source(string $id):array
    {
        if($id!==($this->sourceChain[0]['id']??null))throw new RuntimeException('Source capability mismatch.');
        $this->sourceChain=$this->fresh($this->sourceChain);if($this->selectedScope)$this->scope->assertTransfer($this->sourceChain,$this->destinationChain);return $this->sourceChain[0];
    }
    public function destination(string $id):array
    {
        if($id!==($this->destinationChain[0]['id']??null))throw new RuntimeException('Destination capability mismatch.');
        $this->destinationChain=$this->fresh($this->destinationChain);
        if($this->selectedScope)$this->scope->assertTransfer($this->sourceChain,$this->destinationChain);
        if(!isset($this->destinationChain[0]['folder']))throw new RuntimeException('Destination is not a folder.');
        return $this->destinationChain[0];
    }
    public function target(array $destination,string $name):?array
    {
        $this->assertName($name);
        $parent=$this->destination((string)$destination['id']);
        $reply=$this->request('GET','me/drive/items/'.rawurlencode($parent['id']).':/'.rawurlencode($name).'?$select='.self::FIELDS);
        if($reply['status']===404)return null;
        $item=$reply['json'];$chain=array_merge([$item],$this->destinationChain);$this->scope->assertAllowed($chain);
        if(($item['name']??null)!==$name||($item['parentReference']['id']??null)!==$parent['id'])throw new RuntimeException('Target parent mismatch.');
        $this->targetChains[$item['id']]=$chain;return $item;
    }
    public function hash(array $item):string
    {
        $id=$item['id']??'';
        $chain=$id===($this->sourceChain[0]['id']??null)?$this->sourceChain:($this->targetChains[$id]??null);
        if($chain===null)throw new RuntimeException('No Photos capability for content read.');
        $chain=$this->fresh($chain);$fresh=$chain[0];
        if(($fresh['eTag']??null)!==($item['eTag']??null)||!isset($fresh['file'])||($fresh['size']??0)<=0||$fresh['size']>256*1024*1024)throw new RuntimeException('Content version or size requires review.');
        $hash=hash_init('sha256');$bytes=0;
        $ch=curl_init('https://graph.microsoft.com/v1.0/me/drive/items/'.rawurlencode($id).'/content');
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->token],CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_TIMEOUT=>90,
            CURLOPT_WRITEFUNCTION=>static function($ch,string $data)use($hash,&$bytes):int{$bytes+=strlen($data);if($bytes>256*1024*1024)return 0;hash_update($hash,$data);return strlen($data);}]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if(!$ok||$status!==200||$bytes!==(int)$fresh['size'])throw new RuntimeException('Full content read incomplete.');
        $after=$this->fresh($chain)[0];
        if(($after['eTag']??null)!==$fresh['eTag'])throw new RuntimeException('Content changed during hash read.');
        return hash_final($hash);
    }
    public function copy(array $source,array $destination,string $name):void
    {
        $this->assertName($name);
        $fresh=$this->source((string)$source['id']);$parent=$this->destination((string)$destination['id']);
        if(($fresh['eTag']??null)!==($source['eTag']??null)||!isset($fresh['file']))throw new RuntimeException('Source changed before copy.');
        // Keep the original name. Durable name reservation and target lookup
        // prevent collisions; uncertain operations are never blindly retried.
        if($this->target($parent,$name)!==null)throw new RuntimeException('Target already exists; reconciliation required.');
        $reply=$this->request('POST','me/drive/items/'.rawurlencode($fresh['id']).'/copy',['name'=>$name,'parentReference'=>['driveId'=>$parent['parentReference']['driveId'],'id'=>$parent['id']]]);
        if($reply['status']!==202)throw new RuntimeException('Copy acceptance not confirmed.');
    }
    public function import(array $record,array $target):string
    {
        $fresh=$this->target($this->destinationChain[0],$record['targetName']);
        if(($fresh['id']??null)!==$target['id']||($fresh['eTag']??null)!==$record['targetVersion'])throw new RuntimeException('Verified target changed before import.');
        return ($this->importer)($record,$fresh);
    }
    private function assertName(string $name):void {
        if(!automation_valid_copy_name($name)||($name!==($this->sourceChain[0]['name']??null)&&!preg_match('/^alr-[a-f0-9]{64}\.[a-z0-9]{1,10}$/D',$name)))throw new RuntimeException('Unexpected target name.');
    }
    public function recycleSource(array $source):void {
        $fresh=$this->source((string)$source['id']);$this->destination((string)$this->destinationChain[0]['id']);
        $sourceNames=array_reverse(array_column(array_slice($this->sourceChain,1),'name'));
        $targetNames=array_reverse(array_column($this->destinationChain,'name'));
        if($this->selectedScope)$this->scope->assertTransfer($this->sourceChain,$this->destinationChain);
        if((!$this->selectedScope&&(array_slice($sourceNames,0,2)!==['Photos','Eigene Aufnahmen']||array_slice($targetNames,0,2)!==['Photos','Reisen']||count($targetNames)<3))||!isset($fresh['file'])||isset($fresh['folder'])||($fresh['eTag']??null)!==($source['eTag']??null)||empty($fresh['eTag'])||preg_match('/[\r\n]/',$fresh['eTag']))throw new RuntimeException('Cleanup scope or version changed.');
        $path='me/drive/items/'.rawurlencode($fresh['id']);
        if($this->transport!==null){$reply=($this->transport)('DELETE',$path,['ifMatch'=>$fresh['eTag']]);$status=$reply['status'];}
        else{
            $ch=curl_init('https://graph.microsoft.com/v1.0/'.$path);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->token,'If-Match: '.$fresh['eTag']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>25]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($raw===false)throw new RuntimeException('Cleanup transport uncertain.');
        }
        if($status!==204)throw new RuntimeException('Cleanup not confirmed.');
    }
}
