<?php
declare(strict_types=1);
require_once __DIR__.'/travel-automation-plan.php';
function travel_save(string $path,array $data):void {
    $temp=tempnam(dirname($path),'commit-');if($temp===false)throw new RuntimeException('state_write_failed');
    try{$f=fopen($temp,'wb');if(!$f)throw new RuntimeException('state_write_failed');try{$body=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(fwrite($f,$body)!==strlen($body)||!fflush($f))throw new RuntimeException('state_write_failed');if(function_exists('fsync')&&!fsync($f))throw new RuntimeException('state_write_failed');}finally{fclose($f);}chmod($temp,0600);if(!rename($temp,$path))throw new RuntimeException('state_commit_failed');}finally{if(is_file($temp))unlink($temp);}
}

function travel_window(array $s,DateTimeImmutable $now,?int $from=null):array {
    $end=$now->setTimezone(new DateTimeZone($s['timezone']))->setTime(0,0);
    return ['from'=>$from??travel_timestamp($s['backfillFrom']??$s['startAt']??null)??$now->getTimestamp(),'until'=>$end->getTimestamp(),'next'=>$end->modify('+1 day')->getTimestamp(),'through'=>$end->modify('-1 day')->format('Y-m-d')];
}
function travel_reason(Throwable $e):string {return preg_match('/^[a-z][a-z0-9_-]{0,100}$/D',$e->getMessage())?$e->getMessage():'verification_failed_retry_or_review';}
function travel_is_night(array $settings,DateTimeImmutable $now):bool{return (int)$now->setTimezone(new DateTimeZone($settings['timezone']))->format('G')<6;}
function travel_job_priority(array $job):int{return in_array($job['status'],['copy-intent','copy-pending','copy-uncertain','verified'],true)?0:($job['status']==='discovered'?1:2);}

/** Only a completed discovery cycle can authorize transfers. The copy and cleanup
 * ports retain independent durable content/version/catalog proofs. */
final class TravelAutomationRunner {
    public function __construct(private array $settings,private string $private,private array $identity,
        private Closure $children,private Closure $metadata,private Closure $transfer,
        private Closure $cleanup,private Closure $backup,private Closure $folderExists){}

    public function run(array $source,DateTimeImmutable $now,bool $dryRun=false):array {
        $s=$this->settings;$path=$this->private.($dryRun?'/preview-state.json':'/state.json');
        $identity=hash('sha256',json_encode($this->identity,JSON_THROW_ON_ERROR));
        $state=is_file($path)?json_decode((string)file_get_contents($path),true,256,JSON_THROW_ON_ERROR):[
            'schema'=>2,'identity'=>$identity,'activatedAt'=>$now->getTimestamp(),'events'=>[],'jobs'=>[],'trips'=>[],'seen'=>[],'excludedTargets'=>[]];
        if(($state['schema']??null)!==2||($state['identity']??null)!==$identity)throw new RuntimeException('state_identity_or_schema_changed');
        $rules=hash('sha256',json_encode([$s['sourceFolder'],$s['targetFolder'],$s['home'],$s['radiusKm'],$s['timezone'],$s['startAt'],$s['backfillFrom']],JSON_THROW_ON_ERROR));
        if(($state['rules']??null)!==$rules){
            $first=!isset($state['rules']);$newBackfill=$s['backfillFrom']!==null&&($state['backfillFrom']??null)!==$s['backfillFrom'];
            $state['rulesFrom']=$newBackfill?travel_timestamp($s['backfillFrom']):($first?(travel_timestamp($s['startAt'])??$state['activatedAt']):$now->getTimestamp());
            $state['rules']=$rules;$state['backfillFrom']=$s['backfillFrom'];$state['scan']=null;
            // A configuration change affects future captures, never silently
            // reclassifies previously recorded home evidence or imported trips.
        }
        $state['excludedTargets'][$s['targetFolder']]=true;
        $window=travel_window($s,$now,$state['rulesFrom']);
        $result=['at'=>$now->format(DATE_ATOM),'status'=>'ready','through'=>$window['through'],'dryRun'=>$dryRun,'scanned'=>0,'candidates'=>0,'homeCaptures'=>0,'imported'=>0,'recycled'=>0,'review'=>0];
        if(!$s['enabled']&&!$dryRun)return $result+['disabled'=>true];
        $scan=$state['scan']??[['chain'=>$source,'next'=>null,'items'=>[],'offset'=>0,'loaded'=>false,'pages'=>[]]];
        $persist=function()use(&$state,&$scan,$path){$state['scan']=$scan;travel_save($path,$state);};$persist();
        $started=microtime(true);$readCount=0;$blocked=false;
        while($scan&&$result['scanned']<$s['scanItemsPerRun']&&$readCount<$s['mediaPerRun']&&microtime(true)-$started<$s['maxRunSeconds']){
            try{
                if(!$scan[0]['loaded']){
                    $page=($this->children)($scan[0]['chain'],$scan[0]['next']);
                    if($page['next']!==null&&isset($scan[0]['pages'][$page['next']]))throw new RuntimeException('cyclic_pagination');
                    if($page['next']!==null)$scan[0]['pages'][$page['next']]=true;
                    $scan[0]['items']=$page['items'];$scan[0]['next']=$page['next'];$scan[0]['offset']=0;$scan[0]['loaded']=true;
                }
                if($scan[0]['offset']>=count($scan[0]['items'])){
                    if($scan[0]['next']!==null){$scan[0]['loaded']=false;$scan[0]['items']=[];}else array_shift($scan);
                    $persist();continue;
                }
                $item=$scan[0]['items'][$scan[0]['offset']];$chain=array_merge([$item],$scan[0]['chain']);$result['scanned']++;
                $itemPath=implode('/',array_reverse(array_column(array_slice($chain,0,-1),'name')));
                $excluded=false;foreach($state['excludedTargets'] as $target=>$_)if(TravelOneDriveScope::within($itemPath,$target)){$excluded=true;break;}
                if(!$excluded&&!isset($item['remoteItem'])){
                    if(isset($item['folder']))$scan[]=['chain'=>$chain,'next'=>null,'items'=>[],'offset'=>0,'loaded'=>false,'pages'=>[]];
                    if(isset($item['file'])&&(isset($item['image'])||isset($item['photo'])||isset($item['video'])||preg_match('/\.(jpe?g|heic|heif|png|webp|tiff?|mov|mp4|m4v)$/i',$item['name']??''))){
                        $id=(string)$item['id'];$version=(string)($item['eTag']??'');$seen=$state['seen'][$id]??null;$known=$state['events'][$id]??null;
                        if($known&&$known['media']['version']!==$version){$state['seen'][$id]=['version'=>$version,'name'=>$item['name'],'review'=>true,'reason'=>'event-version-changed'];if(isset($state['jobs'][$id]))$state['jobs'][$id]['blocked']='event-version-changed';throw new RuntimeException('event-version-changed');}
                        elseif(!$known&&($seen===null||$seen['version']!==$version||($seen['retryAfter']??0)<=$now->getTimestamp()||($seen['rules']??null)!==$rules)){
                            $readCount++;$read=($this->metadata)($chain);$current=$read['chain'][0];$version=(string)($current['eTag']??'');$m=array_replace(automation_graph_photo($current,$this->identity['account']),$read['metadata']);
                            $decision=travel_classify($m,$s);$stamp=$decision['capture']??null;
                            $state['seen'][$id]=['name'=>$item['name'],'version'=>$version,'rules'=>$rules,'review'=>$decision['outcome']==='review','reason'=>$decision['reason'],'retryAfter'=>$now->getTimestamp()+86400];
                            if($stamp!==null&&$stamp>=$window['from']&&$stamp<$window['until']&&in_array($decision['outcome'],['home','away'],true)){
                                $extension=strtolower(pathinfo($item['name'],PATHINFO_EXTENSION));if(!preg_match('/^[a-z0-9]{1,10}$/D',$extension))throw new RuntimeException('invalid_media_extension');
                                $state['events'][$id]=['outcome'=>$decision['outcome'],'capture'=>$stamp,'media'=>$m,'chain'=>$read['chain'],'place'=>$read['place']??'Reise','extension'=>$extension,'sourceFolder'=>$s['sourceFolder'],'targetBase'=>$s['targetFolder'],'storageMode'=>$s['storageMode']??'copy'];
                                $result[$decision['outcome']==='home'?'homeCaptures':'candidates']++;
                            }elseif($stamp!==null&&$stamp>=$window['until'])$state['seen'][$id]['retryAfter']=$window['next'];
                        }
                    }
                }
                $scan[0]['offset']++;$persist();
            }catch(Throwable $e){$result['status']='discovery-blocked';$result['reason']=travel_reason($e);$blocked=true;$persist();break;}
        }
        if(!$scan&&!$blocked){
            $plan=travel_plan($state['events'],$state['trips'],$this->folderExists);
            foreach($plan['assignments'] as $id=>$tripId){
                if(!isset($state['jobs'][$id])){$state['events'][$id]['tripId']=$tripId;$state['jobs'][$id]=$state['events'][$id]+['tripId'=>$tripId,'status'=>'discovered'];}
                if(($state['jobs'][$id]['blocked']??null)==='late-home-splits-existing-trip'||($state['jobs'][$id]['blocked']??null)==='multiple-existing-trips-in-segment')unset($state['jobs'][$id]['blocked']);
            }
            foreach($plan['review'] as $id=>$reason){$state['seen'][$id]['review']=true;$state['seen'][$id]['reason']=$reason;if(isset($state['jobs'][$id]))$state['jobs'][$id]['blocked']=$reason;}
            $persist();
            uasort($state['jobs'],static fn($a,$b)=>travel_job_priority($a)<=>travel_job_priority($b)?:strcmp($a['lastAttempt']??'',$b['lastAttempt']??'')?:$a['capture']<=>$b['capture']);
            $worked=0;$backedUp=false;
            foreach($state['jobs'] as $id=>&$job){
                if($dryRun||$worked>=$s['mediaPerRun']||microtime(true)-$started>=$s['maxRunSeconds'])break;
                if(isset($job['blocked'])||$job['status']==='imported'&&(!$s['cleanupEnabled']||($job['cleanupStatus']??null)==='recycled'))continue;
                $worked++;try{
                    if($job['status']!=='imported'){
                        if(!$backedUp){($this->backup)($window['through']);$backedUp=true;}
                        $reply=($this->transfer)($job,$state['trips'][$job['tripId']]);$job['status']=$reply['status'];$job['reason']=$reply['reason']??null;if($job['status']==='imported')$result['imported']++;
                    }
                    if($job['status']==='imported'&&$s['cleanupEnabled']&&($job['storageMode']??'copy')!=='reference'){$reply=($this->cleanup)($job,$state['trips'][$job['tripId']]);$job['cleanupStatus']=$reply['status'];$job['cleanupReason']=$reply['reason']??null;if($reply['status']==='recycled')$result['recycled']++;}
                }catch(Throwable $e){$job['reason']=travel_reason($e);}
                $job['lastAttempt']=$now->format(DATE_ATOM);$persist();
            }unset($job);
            // Null means a NEW complete scan starts next run, not an empty page.
            $scan=null;$persist();
        }
        $result['scanComplete']=$scan===null||$scan===[];
        $result['pending']=count(array_filter($state['jobs'],static fn($j)=>$j['status']!=='imported'));
        $issues=[];foreach($state['seen'] as $id=>$e)if($e['review']??false)$issues[$id]=true;
        foreach($state['jobs'] as $id=>$j)if(isset($j['blocked'])||$j['status']==='review'||in_array($j['cleanupStatus']??null,['review','blocked'],true))$issues[$id]=true;
        $result['review']=count($issues);
        $result['pendingCleanup']=$s['cleanupEnabled']?count(array_filter($state['jobs'],static fn($j)=>$j['status']==='imported'&&($j['cleanupStatus']??null)!=='recycled')):0;
        $result['planned']=[];foreach($state['jobs'] as $id=>$job)$result['planned'][]=['source'=>$job['media']['name'],'trip'=>$state['trips'][$job['tripId']]['title'],'folder'=>$state['trips'][$job['tripId']]['targetPath'],'status'=>$job['status'],'cleanupStatus'=>$job['cleanupStatus']??null,'review'=>$job['blocked']??$job['reason']??$job['cleanupReason']??null];
        $result['homeEvidence']=count(array_filter($state['events'],static fn($e)=>$e['outcome']==='home'));
        travel_save($this->private.($dryRun?'/preview-status.json':'/status.json'),$result);
        $lines=['# Reiseautomatik – '.($dryRun?'Probelauf':'Journal'),'','Stand: '.$now->format(DATE_ATOM),'Verarbeitet bis einschließlich '.$window['through'].' ('.$s['timezone'].').',''];
        foreach($state['seen'] as $entry)if($entry['review']??false)$lines[]=str_replace(["\r","\n"],' ',($entry['name']??'Datei').' | '.$entry['reason']);
        foreach($result['planned'] as $entry)$lines[]=str_replace(["\r","\n"],' ',$entry['source'].' | '.$entry['folder'].' | '.$entry['status'].' | '.($entry['cleanupStatus']??'').' | '.($entry['review']??''));
        if(file_put_contents($this->private.($dryRun?'/preview.md':'/journal.md'),implode("\n",$lines)."\n",LOCK_EX)===false)throw new RuntimeException('report_write_failed');
        return $result;
    }
}

