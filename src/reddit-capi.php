<?php
// Private, environment-only credentials. Never exposed through measurementConfig().
function rcSetting(string $name): string {
 $v=getenv($name);if($v!==false)return trim($v);
 // FPM clears inherited environment; share the root-managed secret file with the worker.
 $path='/etc/tripovela/reddit-capi.env';
 $private=APP_ENV==='production'&&is_readable($path)?parse_ini_file($path,false,INI_SCANNER_RAW):[];
 return trim((string)($private[$name]??''));
}
function rcEnabled(): bool {if(qaActive())return false;
 return preg_match('/^a2_[a-z0-9]+$/D',rcSetting('REDDIT_PIXEL_ID'))===1 && rcSetting('REDDIT_CAPI_TOKEN')!=='';
}
function rcSchema(): void {
 socialDb()->exec("CREATE TABLE IF NOT EXISTS reddit_capi_outbox(member TEXT PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,payload TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,next_at INTEGER NOT NULL,created INTEGER NOT NULL,last_status INTEGER);");
}
function rcPayload(array $r): array {
 $e=['event_at'=>(int)$r['created']*1000,'action_source'=>'WEBSITE','event_source_url'=>APP_URL.'/measurement/reddit/','type'=>['tracking_type'=>'SIGN_UP'],'metadata'=>['conversion_id'=>hash('sha256',$r['event_id'])]];
 // Reddit's browser SDK hashes conversionId; match its wire representation.
 $s=json_decode($r['source'],true);if(!empty($s['rdt_cid']))$e['click_id']=$s['rdt_cid'];
 return ['data'=>['events'=>[$e]]];
}
function rcEnqueue(string $member): void {
 if(!rcEnabled()||!rmAllowed())return;
 rcSchema();$r=socialQuery('SELECT * FROM acquisition_registrations WHERE member=?',[$member])->fetch();
 if(!$r)return;
 socialQuery('INSERT OR IGNORE INTO reddit_capi_outbox(member,payload,next_at,created) VALUES(?,?,?,?)',[$member,json_encode(rcPayload($r)),time(),$r['created']]);
}
function rcRevoke(string $member): void {
 rcSchema();socialQuery("UPDATE reddit_capi_outbox SET state='cancelled',payload='{}' WHERE member=? AND state='pending'",[$member]);
}
function rcTransport(array $payload): int {
 $h=curl_init('https://ads-api.reddit.com/api/v3/pixels/'.rawurlencode(rcSetting('REDDIT_PIXEL_ID')).'/conversion_events');
 curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.rcSetting('REDDIT_CAPI_TOKEN'),'Content-Type: application/json'],CURLOPT_USERAGENT=>'Tripovela-CAPI/1.0',CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false]);
 curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);curl_close($h);return $status;
}
function rcRun(?callable $transport=null): array {
 if(!rcEnabled())return ['disabled'=>true];
 rcSchema();$lock=fopen(STORAGE_PATH.'/reddit-capi.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['busy'=>true];
 $counts=['sent'=>0,'retry'=>0,'failed'=>0];
 try {
  // Stay inside Reddit's two-day deduplication window; never replay old signups.
  socialQuery("UPDATE reddit_capi_outbox SET state='expired',payload='{}' WHERE state='pending' AND created<?",[time()-172000]);
  $rows=socialQuery("SELECT o.* FROM reddit_capi_outbox o JOIN members m ON m.id=o.member WHERE o.state='pending' AND o.next_at<=? AND m.state='active' LIMIT 20",[time()])->fetchAll();
  foreach($rows as $r){
   // Claim an attempt durably before network I/O; a crash retries the same payload.
   $a=(int)$r['attempts']+1;
   if($a>6){socialQuery("UPDATE reddit_capi_outbox SET state='failed' WHERE member=?",[$r['member']]);continue;}
   $q=socialQuery("UPDATE reddit_capi_outbox SET attempts=?,next_at=? WHERE member=? AND state='pending'",[$a,time()+min(21600,60*(2**($a-1))),$r['member']]);if(!$q->rowCount())continue;
   try{$status=($transport??'rcTransport')(json_decode($r['payload'],true));}catch(Throwable $e){$status=0;}
   $state=$status===200?'sent':(($status===0||$status===408||$status===429||$status>=500)&&$a<6?'pending':'failed');
   socialQuery("UPDATE reddit_capi_outbox SET state=?,last_status=? WHERE member=? AND state='pending'",[$state,$status,$r['member']]);
   $counts[$state==='pending'?'retry':$state]++;
  }
 }finally{flock($lock,LOCK_UN);fclose($lock);}
 return $counts;
}
