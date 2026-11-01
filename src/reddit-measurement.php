<?php
require_once __DIR__.'/reddit-capi.php';
// Measurement failures must never change the result of an application operation.
function rmSafe(callable $fn) {try{return $fn();}catch(Throwable $e){error_log('Conversion measurement unavailable');return null;}}
function rmSchema(): void {
 socialDb()->exec("CREATE TABLE IF NOT EXISTS acquisition_registrations(member TEXT PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,event_id TEXT UNIQUE NOT NULL,created INTEGER NOT NULL,method TEXT NOT NULL,source TEXT NOT NULL,pixel_claimed INTEGER,first_action INTEGER);
 CREATE TABLE IF NOT EXISTS acquisition_actions(member TEXT NOT NULL REFERENCES members(id) ON DELETE CASCADE,event TEXT NOT NULL,operation TEXT NOT NULL,created INTEGER NOT NULL,PRIMARY KEY(member,event,operation));");
}
function rmAllowed(): bool {if(qaActive())return false;
 $c=measurementConfig();$choice=json_decode($_COOKIE['tripovela_measurement_server']??'null',true);
 if(is_array($choice)&&($choice['version']??null)===$c['version']&&time()-(int)($choice['at']??0)<$c['consent_days']*86400)return ($choice['yes']??false)===true;
 return ($c['consent_mode']??'opt_in')==='testing_auto';
}
function rmCapture(array $input): void {
 if(!rmAllowed()){unset($_SESSION['acquisition']);return;}
 $keys=['utm_source','utm_medium','utm_campaign','utm_content','ref','rdt_cid'];
 if(!array_intersect($keys,array_keys($input)))return;
 $source=[];foreach($keys as $key){$v=$input[$key]??'';if(is_string($v)&&preg_match('/^[\pL\pN_.~ -]{1,200}$/uD',$v))$source[$key]=$v;}
 if($source)$_SESSION['acquisition']=['values'=>$source,'at'=>time()];
}
function rmRegistration(string $member,string $method='password'): ?string {
 return rmSafe(function()use($member,$method){rmSchema();$a=$_SESSION['acquisition']??[];$source=rmAllowed()&&time()-($a['at']??0)<30*86400?($a['values']??[]):[];
 socialQuery('INSERT OR IGNORE INTO acquisition_registrations(member,event_id,created,method,source) SELECT id,?,created,?,? FROM members WHERE id=? AND state=?',[bin2hex(random_bytes(16)),$method,json_encode($source),$member,'active']);
 $event=socialQuery('SELECT event_id FROM acquisition_registrations WHERE member=?',[$member])->fetchColumn()?:null;
 rmSafe(function()use($member){rcEnqueue($member);});return $event;});
}
function rmAction(string $member,string $event,string $operation): void {
 rmSafe(function()use($member,$event,$operation){if(!rmAllowed()||!in_array($event,['trip_copied','place_added_to_trip','post_published'],true))return;rmSchema();$now=time();
 socialQuery('INSERT OR IGNORE INTO acquisition_actions VALUES(?,?,?,?)',[$member,$event,hash('sha256',$operation),$now]);
 $q=socialQuery('UPDATE acquisition_registrations SET first_action=? WHERE member=? AND first_action IS NULL AND created<=?',[$now,$member,$now]);
 if($q->rowCount())socialQuery('INSERT OR IGNORE INTO acquisition_actions VALUES(?,?,?,?)',[$member,'first_meaningful_action','first',$now]);});
}
function rmClaim(array $u,string $event): ?string {
 if(!rmAllowed()||!preg_match('/^[a-f0-9]{32}$/D',$event)||!hash_equals($_SESSION['registration_event']??'',$event))return null;
 rmSchema();$q=socialQuery('UPDATE acquisition_registrations SET pixel_claimed=? WHERE member=? AND event_id=? AND pixel_claimed IS NULL AND created>?',[time(),$u['id'],$event,time()-1800]);
 unset($_SESSION['registration_event']);return $q->rowCount()?$event:null;
}
function rmEndpoint(): void {
 header('Content-Type: application/json');header('Cache-Control: private, no-store');
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo '{}';return;}
 $result=rmSafe(function(){if(($_POST['action']??'')==='decline'){$u=socialUser();if($u)rcRevoke($u['id']);unset($_SESSION['acquisition'],$_SESSION['registration_event']);return null;}
 $u=socialUser();return $u?rmClaim($u,(string)($_POST['event']??'')):null;});echo json_encode(['event'=>$result]);
}
function rmReport(int $start,int $end,bool $tests=false): array {
 requireAdmin();rmSchema();return socialQuery("SELECT json_extract(r.source,'$.utm_source') source,json_extract(r.source,'$.utm_campaign') campaign,json_extract(r.source,'$.utm_content') content,COUNT(*) registrations,SUM(r.first_action IS NOT NULL AND r.first_action<=r.created+86400) activated,SUM(r.created>?-86400) immature FROM acquisition_registrations r JOIN members m ON m.id=r.member LEFT JOIN network_member_flags f ON f.member=m.id WHERE r.created BETWEEN ? AND ? AND m.state!='deleted' ".($tests?'':"AND COALESCE(f.audience,'member') NOT IN ('qa','demo','admin') ").'GROUP BY source,campaign,content ORDER BY registrations DESC',[$end,$start,$end])->fetchAll();
}
