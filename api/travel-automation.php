<?php
declare(strict_types=1);
require_once __DIR__.'/travel-automation-plan.php';
function travel_save(string $path,array $data):void {
    $temp=tempnam(dirname($path),'commit-');if($temp===false)throw new RuntimeException('state_write_failed');
    try{$f=fopen($temp,'wb');if(!$f)throw new RuntimeException('state_write_failed');try{$body=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(fwrite($f,$body)!==strlen($body)||!fflush($f))throw new RuntimeException('state_write_failed');if(function_exists('fsync')&&!fsync($f))throw new RuntimeException('state_write_failed');}finally{fclose($f);}chmod($temp,0600);
        // A scanner or indexer can hold the target for a moment (seen on Windows): retry briefly.
        $moved=false;for($try=0;$try<5&&!$moved;$try++){$moved=@rename($temp,$path);if(!$moved)usleep(100000);}
        if(!$moved)throw new RuntimeException('state_commit_failed');}finally{if(is_file($temp))unlink($temp);}
}

/** Captures up to the start of today (nightly) or, with frequent runs (every ten minutes during the day),
 * up to five minutes ago (gives the phone time to finish uploading a series to OneDrive). */
const TRAVEL_FREQUENT_GRACE_SECONDS=300;
const TRAVEL_FREQUENT_INTERVAL_SECONDS=600;
function travel_window(array $s,DateTimeImmutable $now,?int $from=null):array {
    if(!empty($s['frequentRuns'])){
        $until=$now->getTimestamp()-TRAVEL_FREQUENT_GRACE_SECONDS;
        return ['from'=>$from??travel_timestamp($s['backfillFrom']??$s['startAt']??null)??$now->getTimestamp(),'until'=>$until,'next'=>$now->getTimestamp()+TRAVEL_FREQUENT_INTERVAL_SECONDS,'through'=>$now->setTimezone(new DateTimeZone($s['timezone']))->format('Y-m-d')];
    }
    $end=$now->setTimezone(new DateTimeZone($s['timezone']))->setTime(0,0);
    return ['from'=>$from??travel_timestamp($s['backfillFrom']??$s['startAt']??null)??$now->getTimestamp(),'until'=>$end->getTimestamp(),'next'=>$end->modify('+1 day')->getTimestamp(),'through'=>$end->modify('-1 day')->format('Y-m-d')];
}
function travel_reason(Throwable $e):string {return preg_match('/^[a-z][a-z0-9_-]{0,100}$/D',$e->getMessage())?$e->getMessage():'verification_failed_retry_or_review';}
function travel_is_night(array $settings,DateTimeImmutable $now):bool{return (int)$now->setTimezone(new DateTimeZone($settings['timezone']))->format('G')<6;}
/** Failures that concern a single original and become its review case. */
const TRAVEL_ITEM_REVIEW_REASONS=['original_size_review','original_read_incomplete'];
function travel_job_priority(array $job):int{return $job['status']==='discovered'?1:2;}

/** Only a completed discovery cycle can authorize catalog registration. Originals
 * stay in place: the register port verifies identity, version and content hash
 * and binds the source item to its journey; nothing is copied or deleted. */
final class TravelAutomationRunner {
    public function __construct(private array $settings,private string $private,private array $identity,
        private Closure $children,private Closure $metadata,private Closure $register,
        private Closure $backup){}

    public function run(array $source,DateTimeImmutable $now,bool $dryRun=false):array {
        $s=$this->settings;$path=$this->private.($dryRun?'/preview-state.json':'/state.json');
        $identity=hash('sha256',json_encode($this->identity,JSON_THROW_ON_ERROR));
        $state=is_file($path)?json_decode((string)file_get_contents($path),true,256,JSON_THROW_ON_ERROR):[
            'schema'=>2,'identity'=>$identity,'activatedAt'=>$now->getTimestamp(),'events'=>[],'jobs'=>[],'trips'=>[],'seen'=>[],'excludedTargets'=>[]];
        if(($state['schema']??null)!==2||($state['identity']??null)!==$identity)throw new RuntimeException('state_identity_or_schema_changed');
        // Resolution rules join the fingerprint only when changed, so existing setups keep their state.
        $resolution=($s['minPhotoShortSide']??TRAVEL_DEFAULT_PHOTO_SIDE)!==TRAVEL_DEFAULT_PHOTO_SIDE||($s['minVideoShortSide']??TRAVEL_DEFAULT_VIDEO_SIDE)!==TRAVEL_DEFAULT_VIDEO_SIDE?[[$s['minPhotoShortSide'],$s['minVideoShortSide']]]:[];
        $period=($s['backfillUntil']??null)!==null?[['until',$s['backfillUntil']]]:[];
        $rules=hash('sha256',json_encode(array_merge([$s['sourceFolder'],$s['smallPhotos'],$s['home'],$s['extraHomes'],$s['radiusKm'],$s['timezone'],$s['startAt'],$s['backfillFrom']],$resolution,$period),JSON_THROW_ON_ERROR));
        if(($state['rules']??null)!==$rules){
            $first=!isset($state['rules']);$newBackfill=$s['backfillFrom']!==null&&($state['backfillFrom']??null)!==$s['backfillFrom'];
            $state['rulesFrom']=$newBackfill?travel_timestamp($s['backfillFrom']):($first?(travel_timestamp($s['startAt'])??$state['activatedAt']):$now->getTimestamp());
            $state['rules']=$rules;$state['backfillFrom']=$s['backfillFrom'];$state['scan']=null;
            // A configuration change affects future captures, never silently
            // reclassifies previously recorded home evidence or imported trips.
        }
        $window=travel_window($s,$now,$state['rulesFrom']);
        $result=['at'=>$now->format(DATE_ATOM),'status'=>'ready','through'=>$window['through'],'dryRun'=>$dryRun,'scanned'=>0,'candidates'=>0,'timed'=>0,'homeCaptures'=>0,'imported'=>0,'review'=>0];
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
                        // Settled by hand on the settings page (added to a trip or ignored): never offered for review again.
                        elseif(!$known&&empty($seen['manual'])&&($seen===null||$seen['version']!==$version||($seen['retryAfter']??0)<=$now->getTimestamp()||($seen['rules']??null)!==$rules)){
                            // Unchanged file already read once (rule change, or a capture newer than the window
                            // checked again): classify from the stored capture data, without loading the original.
                            $cached=is_array($seen['media']??null)&&($seen['version']??null)===$version;
                            if($cached){$m=$seen['media'];$read=['chain'=>$chain,'place'=>$seen['place']??'Reise'];}
                            else{
                            $readCount++;
                            try{$read=($this->metadata)($chain);}catch(Throwable $itemError){
                                // One unreadable or oversized original is a review case for that file only;
                                // it must not stop the scan of all other captures.
                                if(!in_array(travel_reason($itemError),TRAVEL_ITEM_REVIEW_REASONS,true))throw $itemError;
                                $state['seen'][$id]=['name'=>$item['name'],'version'=>$version,'rules'=>$rules,'review'=>true,'reason'=>travel_reason($itemError),'retryAfter'=>$now->getTimestamp()+7*86400];
                                $scan[0]['offset']++;$persist();continue;
                            }
                            // Only downloads of originals count against the per-run budget; listing data is free.
                            if(($read['downloaded']??true)===false)$readCount--;
                            $current=$read['chain'][0];$version=(string)($current['eTag']??'');$m=array_replace(automation_graph_photo($current,$this->identity['account']),$read['metadata']);$m['fileCreatedAt']=$current['fileSystemInfo']['createdDateTime']??null;
                            }
                            $captured=$m;
                            $decision=travel_classify($m,$s);$stamp=$decision['capture']??null;
                            if(($decision['captureSource']??null)==='file-created'){$m['takenAt']=gmdate('Y-m-d\TH:i:s\Z',$stamp);$m['captureSource']='file-created';}
                            $state['seen'][$id]=['name'=>$item['name'],'version'=>$version,'rules'=>$rules,'review'=>$decision['outcome']==='review','reason'=>$decision['reason'],'retryAfter'=>$now->getTimestamp()+86400,'media'=>$captured,'place'=>$read['place']??'Reise'];
                            $skipped=$stamp!==null&&($s['backfillUntil']??null)!==null&&$stamp>=travel_timestamp($s['backfillUntil'])&&$stamp<(int)$state['activatedAt'];
                            if($stamp!==null&&!$skipped&&$stamp>=$window['from']&&$stamp<$window['until']&&in_array($decision['outcome'],['home','away','timed'],true)){
                                $extension=strtolower(pathinfo($item['name'],PATHINFO_EXTENSION));if(!preg_match('/^[a-z0-9]{1,10}$/D',$extension))throw new RuntimeException('invalid_media_extension');
                                $state['events'][$id]=['outcome'=>$decision['outcome'],'capture'=>$stamp,'media'=>$m,'chain'=>$read['chain'],'place'=>$read['place']??'Reise','extension'=>$extension,'sourceFolder'=>$s['sourceFolder'],'storageMode'=>'reference'];
                                $result[['home'=>'homeCaptures','timed'=>'timed'][$decision['outcome']]??'candidates']++;
                            }elseif($stamp!==null&&$stamp>=$window['until'])$state['seen'][$id]['retryAfter']=$window['next'];
                        }
                    }
                }
                $scan[0]['offset']++;$persist();
            }catch(Throwable $e){$result['status']='discovery-blocked';$result['reason']=travel_reason($e);$blocked=true;$persist();break;}
        }
        if(!$scan&&!$blocked){
            $plan=travel_plan($state['events'],$state['trips'],['quietDays'=>$s['quietDays'],'homeBreakHours'=>$s['homeBreakHours']]);
            foreach($plan['assignments'] as $id=>$tripId){
                if(!isset($state['jobs'][$id])){$state['events'][$id]['tripId']=$tripId;$state['jobs'][$id]=$state['events'][$id]+['tripId'=>$tripId,'status'=>'discovered','stationRadiusKm'=>$s['stationRadiusKm']];}
                if(($state['jobs'][$id]['blocked']??null)==='late-home-splits-existing-trip'||($state['jobs'][$id]['blocked']??null)==='multiple-existing-trips-in-segment')unset($state['jobs'][$id]['blocked']);
            }
            foreach($plan['review'] as $id=>$reason){$state['seen'][$id]['review']=true;$state['seen'][$id]['reason']=$reason;if(isset($state['jobs'][$id]))$state['jobs'][$id]['blocked']=$reason;}
            $persist();
            uasort($state['jobs'],static fn($a,$b)=>travel_job_priority($a)<=>travel_job_priority($b)?:strcmp($a['lastAttempt']??'',$b['lastAttempt']??'')?:$a['capture']<=>$b['capture']);
            $worked=0;$backedUp=false;
            foreach($state['jobs'] as $id=>&$job){
                if($dryRun||$worked>=$s['mediaPerRun']||microtime(true)-$started>=$s['maxRunSeconds'])break;
                if(isset($job['blocked'])||$job['status']==='imported')continue;
                $worked++;try{
                    if(!$backedUp){($this->backup)($window['through']);$backedUp=true;}
                    $reply=($this->register)($job,$state['trips'][$job['tripId']]);$job['status']=$reply['status'];$job['reason']=$reply['reason']??null;if($job['status']==='imported')$result['imported']++;
                }catch(Throwable $e){$job['reason']=travel_reason($e);}
                $job['lastAttempt']=$now->format(DATE_ATOM);$persist();
            }unset($job);
            // Null means a NEW complete scan starts next run, not an empty page.
            $scan=null;$persist();
        }
        $result['scanComplete']=$scan===null||$scan===[];
        $result['pending']=count(array_filter($state['jobs'],static fn($j)=>$j['status']!=='imported'));
        $issues=[];foreach($state['seen'] as $id=>$e)if($e['review']??false)$issues[$id]=true;
        foreach($state['jobs'] as $id=>$j)if(isset($j['blocked'])||$j['status']==='review')$issues[$id]=true;
        $result['review']=count($issues);
        $result['planned']=[];foreach($state['jobs'] as $id=>$job)$result['planned'][]=['source'=>$job['media']['name'],'trip'=>$state['trips'][$job['tripId']]['title'],'tripId'=>$job['tripId'],'status'=>$job['status'],'review'=>$job['blocked']??$job['reason']??null];
        $result['homeEvidence']=count(array_filter($state['events'],static fn($e)=>$e['outcome']==='home'));
        travel_save($this->private.($dryRun?'/preview-status.json':'/status.json'),$result);
        $lines=['# Reiseautomatik – '.($dryRun?'Probelauf':'Journal'),'','Stand: '.$now->format(DATE_ATOM),'Verarbeitet bis einschließlich '.$window['through'].' ('.$s['timezone'].').',''];
        foreach($state['seen'] as $entry)if($entry['review']??false)$lines[]=str_replace(["\r","\n"],' ',($entry['name']??'Datei').' | '.$entry['reason']);
        foreach($result['planned'] as $entry)$lines[]=str_replace(["\r","\n"],' ',$entry['source'].' | '.$entry['trip'].' | '.$entry['status'].' | '.($entry['review']??''));
        if(file_put_contents($this->private.($dryRun?'/preview.md':'/journal.md'),implode("\n",$lines)."\n",LOCK_EX)===false)throw new RuntimeException('report_write_failed');
        return $result;
    }
}

