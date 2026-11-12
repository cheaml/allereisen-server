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

/** 0.4.1: trip recordings started in the iPhone app ("Reise aufzeichnen"). While one is active the automation
 * runs every ten minutes; every capture inside a recording joins that recording's trip, whatever the home
 * distance or quiet days say, and its stations are split from 300 m on. Stored next to the run state. */
const TRAVEL_RECORDING_STATION_KM=0.3;
function travel_recordings(string $privateDirectory):array {
    $file=$privateDirectory.'/recordings.json';if(!is_file($file))return [];
    try{$list=json_decode((string)file_get_contents($file),true,8,JSON_THROW_ON_ERROR);}catch(JsonException){return [];}
    if(!is_array($list))return [];
    return array_values(array_filter($list,static fn($r)=>is_array($r)&&is_string($r['id']??null)&&preg_match('/^[a-f0-9-]{8,64}$/D',$r['id'])&&is_int($r['from']??null)&&(($r['until']??null)===null||is_int($r['until']))&&is_string($r['title']??null)));
}
/** Capture time known from the OneDrive listing alone (photo.takenDateTime, or the UTC time in an iPhone file name such
 * as 20260129_070953206_iOS.jpg); only used to skip originals far before the scan window without downloading them. */
function travel_capture_hint(array $item):?int {
    $taken=$item['photo']['takenDateTime']??null;
    if(is_string($taken)&&($at=strtotime($taken))!==false)return $at;
    if(preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})\d*_iOS\./i',(string)($item['name']??''),$m)&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]))
        return gmmktime((int)$m[4],(int)$m[5],(int)$m[6],(int)$m[2],(int)$m[3],(int)$m[1]);
    // Otherwise the file's last change on the device (camera files such as P1010768.JPG): a photo cannot have
    // been taken after its file was last changed, so this is a safe upper bound for "far before the window".
    $modified=$item['fileSystemInfo']['lastModifiedDateTime']??null;
    if(is_string($modified)&&($at=strtotime($modified))!==false)return $at;
    return null;
}
function travel_recording_active(array $recordings):?array {foreach($recordings as $r)if($r['until']===null)return $r;return null;}
/** recording id => trip id of the trips the automation made from recordings (written by the runner). */
function travel_recording_trips(string $privateDirectory):array {
    $file=$privateDirectory.'/recording-trips.json';if(!is_file($file))return [];
    try{$map=json_decode((string)file_get_contents($file),true,4,JSON_THROW_ON_ERROR);}catch(JsonException){return [];}
    return is_array($map)?array_filter($map,static fn($v,$k)=>is_string($k)&&is_string($v)&&preg_match('/^[a-f0-9-]{36}$/D',$v),ARRAY_FILTER_USE_BOTH):[];
}
function travel_recording_for(array $recordings,int $capture):?array {
    foreach($recordings as $r)if($capture>=$r['from']&&($r['until']===null||$capture<=$r['until']))return $r;return null;
}
/** Participant name of the automation owner (as set in the app); new automatic trips start with it. */
function travel_owner_participant(string $privateDirectory):?string {
    $file=$privateDirectory.'/participant.json';if(!is_file($file))return null;
    try{$data=json_decode((string)file_get_contents($file),true,4,JSON_THROW_ON_ERROR);}catch(JsonException){return null;}
    return is_array($data)?travel_participant_name($data['name']??null):null;
}
function travel_participant_name(mixed $name):?string {
    if(!is_string($name))return null;$name=trim(preg_replace('/\s+/u',' ',$name)??'');
    return $name!==''&&mb_strlen($name)<=60&&!str_contains($name,',')&&!str_contains($name,'@')&&!preg_match('/[\x00-\x1F<>]/u',$name)?$name:null;
}
function travel_owner_participant_save(string $privateDirectory,mixed $name):void {
    $name=travel_participant_name($name);if($name===null)throw new InvalidArgumentException('invalid_participant');
    if(travel_owner_participant($privateDirectory)!==$name)travel_save($privateDirectory.'/participant.json',['name'=>$name]);
}
/** Start (only one at a time) or stop the recording; returns the updated list. */
function travel_recording_change(string $privateDirectory,string $mode,int $now,string $timezone):array {
    $recordings=travel_recordings($privateDirectory);
    if($mode==='start'&&travel_recording_active($recordings)===null){
        try{$day=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone($timezone))->format('d.m.Y');}catch(Exception){$day=gmdate('d.m.Y',$now);}
        $recordings[]=['id'=>bin2hex(random_bytes(16)),'from'=>$now,'until'=>null,'title'=>'Reise ab '.$day];
    }elseif($mode==='stop'){foreach($recordings as &$r)if($r['until']===null)$r['until']=$now;unset($r);}
    elseif($mode!=='start')throw new InvalidArgumentException('invalid_recording_mode');
    travel_save($privateDirectory.'/recordings.json',array_slice($recordings,-50));
    return $recordings;
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
        // An active trip recording from the app: run as with "every ten minutes", also when otherwise off or nightly.
        $recordings=travel_recordings($this->private);if(travel_recording_active($recordings)!==null){$s['enabled']=true;$s['frequentRuns']=true;}
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
        // A trip recording (running or ended in the last 7 days) is always covered, also when a rule change after its
        // start (e.g. another photo folder) moved the start of the scan window behind it.
        foreach($recordings as $recording)if($recording['until']===null||$recording['until']>=$now->getTimestamp()-7*86400)$window['from']=min($window['from'],$recording['from']);
        $result=['at'=>$now->format(DATE_ATOM),'status'=>'ready','through'=>$window['through'],'dryRun'=>$dryRun,'scanned'=>0,'candidates'=>0,'timed'=>0,'homeCaptures'=>0,'imported'=>0,'review'=>0];
        if(!$s['enabled']&&!$dryRun)return $result+['disabled'=>true];
        $scan=$state['scan']??[['chain'=>$source,'next'=>null,'items'=>[],'offset'=>0,'loaded'=>false,'pages'=>[]]];
        $persist=function()use(&$state,&$scan,$path){$state['scan']=$scan;travel_save($path,$state);};$persist();
        $started=microtime(true);$readCount=0;$blocked=false;
        // Large photo archives: the state (all seen files) is saved at most every 5 seconds instead of after every file,
        // and only files that need work count against scanItemsPerRun; maxRunSeconds still bounds every run.
        $processed=0;$saved=microtime(true);$persistSoon=function()use(&$saved,$persist){if(microtime(true)-$saved>=5){$persist();$saved=microtime(true);}};
        while($scan&&$processed<$s['scanItemsPerRun']&&$readCount<$s['mediaPerRun']&&microtime(true)-$started<$s['maxRunSeconds']){
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
                            if($cached){$processed++;$m=$seen['media'];$read=['chain'=>$chain,'place'=>$seen['place']??'Reise'];}
                            elseif(($hint=travel_capture_hint($item))!==null&&$hint<$window['from']-2*86400){
                                // Far before the scan window (e.g. a whole photo archive since 2008): noted without
                                // downloading the original; checked again tomorrow, again from the listing only.
                                $state['seen'][$id]=['name'=>$item['name'],'version'=>$version,'rules'=>$rules,'review'=>false,'reason'=>'before-window','retryAfter'=>$now->getTimestamp()+86400];
                                $scan[0]['offset']++;$persistSoon();continue;
                            }
                            else{
                            $readCount++;$processed++;
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
                            // Inside a trip recording a capture at home is a journey capture like any other.
                            $recording=$stamp!==null?travel_recording_for($recordings,$stamp):null;
                            if($recording!==null&&$decision['outcome']==='home')$decision['outcome']='away';
                            if(($decision['captureSource']??null)==='file-created'){$m['takenAt']=gmdate('Y-m-d\TH:i:s\Z',$stamp);$m['captureSource']='file-created';}
                            $state['seen'][$id]=['name'=>$item['name'],'version'=>$version,'rules'=>$rules,'review'=>$decision['outcome']==='review','reason'=>$decision['reason'],'retryAfter'=>$now->getTimestamp()+86400,'media'=>$captured,'place'=>$read['place']??'Reise'];
                            $skipped=$stamp!==null&&($s['backfillUntil']??null)!==null&&$stamp>=travel_timestamp($s['backfillUntil'])&&$stamp<(int)$state['activatedAt'];
                            if($stamp!==null&&!$skipped&&$stamp>=$window['from']&&$stamp<$window['until']&&in_array($decision['outcome'],['home','away','timed'],true)){
                                $extension=strtolower(pathinfo($item['name'],PATHINFO_EXTENSION));if(!preg_match('/^[a-z0-9]{1,10}$/D',$extension))throw new RuntimeException('invalid_media_extension');
                                $state['events'][$id]=['outcome'=>$decision['outcome'],'capture'=>$stamp,'media'=>$m,'chain'=>$read['chain'],'place'=>$read['place']??'Reise','extension'=>$extension,'sourceFolder'=>$s['sourceFolder'],'storageMode'=>'reference']+($recording!==null?['recording'=>$recording['id']]:[]);
                                $result[['home'=>'homeCaptures','timed'=>'timed'][$decision['outcome']]??'candidates']++;
                            }elseif($stamp!==null&&$stamp>=$window['until'])$state['seen'][$id]['retryAfter']=$window['next'];
                        }
                    }
                }
                $scan[0]['offset']++;$persistSoon();
            }catch(Throwable $e){$result['status']='discovery-blocked';$result['reason']=travel_reason($e);$blocked=true;$persist();break;}
        }
        $persist();
        if(!$scan&&!$blocked){
            // Captures of a trip recording form that recording's trip directly; the usual planning sees only the rest.
            $recorded=array_filter($state['events'],static fn($e)=>isset($e['recording']));
            $plan=travel_plan(array_diff_key($state['events'],$recorded),$state['trips'],['quietDays'=>$s['quietDays'],'homeBreakHours'=>$s['homeBreakHours']]);
            $titles=array_column($recordings,'title','id');
            foreach($recorded as $id=>$event){
                $rid=$event['recording'];$tripId=$state['recordingTrips'][$rid]??null;
                if($tripId===null||!isset($state['trips'][$tripId])){$tripId=automation_uuid();$state['recordingTrips'][$rid]=$tripId;
                    $state['trips'][$tripId]=['id'=>$tripId,'title'=>$titles[$rid]??'Aufgezeichnete Reise','targetPath'=>'reise://'.$tripId,'first'=>$event['capture'],'last'=>$event['capture'],'recording'=>$rid];}
                $state['trips'][$tripId]['first']=min($state['trips'][$tripId]['first'],$event['capture']);$state['trips'][$tripId]['last']=max($state['trips'][$tripId]['last'],$event['capture']);
                $plan['assignments'][$id]=$tripId;
            }
            // Small copy for the app (device-sync travel-recording), without reading the large state.
            if(!$dryRun&&$recorded)travel_save($this->private.'/recording-trips.json',$state['recordingTrips']??[]);
            foreach($plan['assignments'] as $id=>$tripId){
                if(!isset($state['jobs'][$id])){$state['events'][$id]['tripId']=$tripId;$state['jobs'][$id]=$state['events'][$id]+['tripId'=>$tripId,'status'=>'discovered','stationRadiusKm'=>isset($state['events'][$id]['recording'])?TRAVEL_RECORDING_STATION_KM:$s['stationRadiusKm']];}
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
        // 0.3.8: a catch-up period counts as done once a complete discovery cycle with these dates finished
        // and no discovered capture still waits for registration. The dates stay in force (no double trips);
        // the settings page only shows them as done and offers empty fields for a new period.
        $period=[$s['backfillFrom']??null,$s['backfillUntil']??null];
        if(!$dryRun&&$period[0]!==null&&$result['status']==='ready'&&$result['scanComplete']
            &&!array_filter($state['jobs'],static fn($j)=>$j['status']==='discovered'&&!isset($j['blocked']))){
            if([$state['backfillDone']['from']??null,$state['backfillDone']['until']??null]!==$period){$state['backfillDone']=['from'=>$period[0],'until'=>$period[1],'at'=>$now->format(DATE_ATOM)];$persist();}
        }
        if(isset($state['backfillDone'])&&[$state['backfillDone']['from'],$state['backfillDone']['until']]===$period)$result['backfillDone']=$state['backfillDone'];
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

