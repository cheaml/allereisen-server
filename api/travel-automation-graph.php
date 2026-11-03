<?php
declare(strict_types=1);
require_once __DIR__.'/automation-graph.php';
require_once __DIR__.'/travel-automation-scope.php';

final class TravelGraph {
    public const FIELDS='id,name,eTag,size,file,folder,root,parentReference,remoteItem,photo,video,image,location,webUrl,createdDateTime';
    public function __construct(public readonly string $token,private ?Closure $transport=null){}
    public function request(string $method,string $path,?array $body=null):array {
        if(str_contains($path,'://')||str_starts_with($path,'/')||str_contains($path,"\r")||str_contains($path,"\n"))throw new RuntimeException('unsafe_graph_path');
        if($this->transport!==null)return ($this->transport)($method,$path,$body);
        $ch=curl_init('https://graph.microsoft.com/v1.0/'.$path);$headers=['Authorization: Bearer '.$this->token];
        $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>30];
        if($body!==null){$headers[]='Content-Type: application/json';$options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR);}
        $options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($ch,$options);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($raw===false)throw new RuntimeException('graph_transport_failed');
        if($status===404)return ['status'=>404,'json'=>[]];
        if(!in_array($status,[200,201],true))throw new RuntimeException($status===401?'onedrive_reconnect_required':($status===429?'onedrive_throttled_retry':'graph_request_failed'));
        return ['status'=>$status,'json'=>json_decode($raw,true,128,JSON_THROW_ON_ERROR)];
    }
    public function get(string $path):array {$reply=$this->request('GET',$path);if($reply['status']!==200)throw new RuntimeException('graph_item_missing');return $reply['json'];}
    public function fresh(array $chain,AutomationOneDriveScope $scope,bool $root=false):array {
        foreach($chain as &$item){$before=$item;$item=$this->get('me/drive/items/'.rawurlencode($item['id']).'?$select='.self::FIELDS);if(isset($before['root'],$item['root'])&&empty($item['parentReference']['driveId']))$item['parentReference']['driveId']=$before['parentReference']['driveId'];}unset($item);$scope->assertAllowed($chain,$root);return $chain;
    }
    public function folder(array $parent,string $name,AutomationOneDriveScope $scope,bool $create):?array {
        if(!automation_valid_copy_name($name))throw new RuntimeException('invalid_folder_name');
        $parent=$this->fresh($parent,$scope,true);$reply=$this->request('GET','me/drive/items/'.rawurlencode($parent[0]['id']).':/'.rawurlencode($name).'?$select='.self::FIELDS);
        if($reply['status']===404){if(!$create)return null;$reply=$this->request('POST','me/drive/items/'.rawurlencode($parent[0]['id']).'/children',['name'=>$name,'folder'=>(object)[],'@microsoft.graph.conflictBehavior'=>'fail']);}
        if(($reply['json']['name']??null)!==$name||!isset($reply['json']['folder']))throw new RuntimeException('folder_conflict');
        $chain=array_merge([$reply['json']],$parent);$scope->assertAllowed($chain,true);return $chain;
    }
    public function resolve(array $root,string $path,AutomationOneDriveScope $scope,bool $create):?array {
        if($scope instanceof TravelOneDriveScope){TravelOneDriveScope::assertPath($path,true);$parts=$path===''?[]:explode('/',$path);}else{PhotosOneDriveScope::assertRelativeFolder($path);$parts=array_slice(explode('/',$path),1);}
        $chain=[$root];foreach($parts as $name){$chain=$this->folder($chain,$name,$scope,$create);if($chain===null)return null;}return $chain;
    }
    public function children(array $chain,?string $next,AutomationOneDriveScope $scope):array {
        $chain=$this->fresh($chain,$scope,true);$parent=$chain[0];$path='me/drive/items/'.rawurlencode($parent['id']).'/children?$top=100&$select='.self::FIELDS;
        if($next!==null){
            $url=parse_url($next);$expected='/v1.0/me/drive/items/'.rawurlencode($parent['id']).'/children';$drive='/v1.0/drives/'.rawurlencode($parent['parentReference']['driveId']).'/items/'.rawurlencode($parent['id']).'/children';
            if(($url['scheme']??null)!=='https'||($url['host']??null)!=='graph.microsoft.com'||isset($url['user'])||isset($url['pass'])||isset($url['fragment'])||isset($url['port'])||!in_array($url['path']??' ',[$expected,$drive],true))throw new RuntimeException('unsafe_pagination');
            $path=substr($url['path'],6).'?'.($url['query']??'');
        }
        $reply=$this->get($path);$items=$reply['value']??null;if(!is_array($items))throw new RuntimeException('invalid_graph_page');
        foreach($items as $item){if(isset($item['remoteItem']))continue;$scope->assertAllowed(array_merge([$item],$chain));}
        $following=$reply['@odata.nextLink']??null;if($following!==null&&(!is_string($following)||$following===$next))throw new RuntimeException('cyclic_pagination');
        return ['items'=>$items,'next'=>$following];
    }
}

function travel_read_connection(PDO $db,array $config,string $email):array {
    require_once __DIR__.'/mail.php';
    $q=$db->prepare('SELECT id,microsoft_id FROM users WHERE LOWER(email)=? AND is_admin=1');$q->execute([strtolower($email)]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1||empty($rows[0]['microsoft_id']))throw new RuntimeException('automation_owner_connection_required');
    $owner=$rows[0];$owner['account_id']=$owner['microsoft_id'];
    $token=graph_access_token($db,$config,(int)$owner['id']);if(!$token)throw new RuntimeException('onedrive_reconnect_required');
    return [$owner,$token];
}

function travel_connection(PDO $db,array $config,string $email):array {
    $q=$db->prepare('SELECT u.id,u.microsoft_id,a.account_id,a.drive_id,a.root_item_id,a.refresh_token_enc FROM users u JOIN travel_automation_connections a ON a.user_id=u.id WHERE LOWER(u.email)=? AND u.is_admin=1');$q->execute([strtolower($email)]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)throw new RuntimeException('automation_owner_connection_required');$owner=$rows[0];
    $raw=base64_decode($owner['refresh_token_enc'],true);if($raw===false||strlen($raw)<29)throw new RuntimeException('invalid_stored_connection');
    $refresh=openssl_decrypt(substr($raw,28),'aes-256-gcm',hex2bin($config['encryption_key']),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));if($refresh===false)throw new RuntimeException('connection_decryption_failed');
    $ch=curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$config['microsoft_client_id'],'client_secret'=>$config['microsoft_client_secret'],'grant_type'=>'refresh_token','refresh_token'=>$refresh,'scope'=>'openid profile offline_access User.Read Files.ReadWrite']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25]);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $token=is_string($body)?json_decode($body,true):null;
    if($code!==200||empty($token['access_token'])||!in_array('Files.ReadWrite',explode(' ',(string)($token['scope']??'')),true))throw new RuntimeException('automation_write_consent_required');
    if(!empty($token['refresh_token'])){$iv=random_bytes(12);$cipher=openssl_encrypt($token['refresh_token'],'aes-256-gcm',hex2bin($config['encryption_key']),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('connection_encryption_failed');$q=$db->prepare('UPDATE travel_automation_connections SET refresh_token_enc=? WHERE user_id=? AND refresh_token_enc=?');$q->execute([base64_encode($iv.$tag.$cipher),$owner['id'],$owner['refresh_token_enc']]);}
    return [$owner,(string)$token['access_token']];
}
