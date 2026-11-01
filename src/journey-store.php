<?php
declare(strict_types=1);
require_once __DIR__.'/journey-engine.php';
function journeyDb(): PDO {
 static $ready=false;$d=socialDb();if($ready)return $d;
 if($d->query("SELECT value FROM network_settings WHERE key='journey_schema'")->fetchColumn()==='1'){$ready=true;return $d;}
 $d->exec("CREATE TABLE IF NOT EXISTS journey_places(id TEXT PRIMARY KEY,legacy_id TEXT UNIQUE NOT NULL,timezone TEXT NOT NULL DEFAULT 'Asia/Tbilisi',geometry TEXT,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS journey_facts(version INTEGER PRIMARY KEY AUTOINCREMENT,subject TEXT NOT NULL,type TEXT NOT NULL,document TEXT NOT NULL,actor TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE INDEX IF NOT EXISTS journey_fact_subject ON journey_facts(subject,type,version);
 CREATE TABLE IF NOT EXISTS journey_trips(id TEXT PRIMARY KEY,principal TEXT NOT NULL,revision INTEGER NOT NULL,locale TEXT NOT NULL,title TEXT NOT NULL,source_version INTEGER,updated INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS journey_versions(trip TEXT NOT NULL,revision INTEGER NOT NULL,input TEXT NOT NULL,result TEXT NOT NULL,fingerprint TEXT NOT NULL,created INTEGER NOT NULL,PRIMARY KEY(trip,revision));
 CREATE TABLE IF NOT EXISTS journey_proposals(id TEXT PRIMARY KEY,trip TEXT NOT NULL,base_revision INTEGER NOT NULL,document TEXT NOT NULL,fingerprint TEXT NOT NULL,expires INTEGER NOT NULL,applied_revision INTEGER,idempotency TEXT,selection TEXT);
 CREATE TABLE IF NOT EXISTS journey_reports(id TEXT PRIMARY KEY,place TEXT NOT NULL,owner TEXT NOT NULL,type TEXT NOT NULL,observed INTEGER NOT NULL,evidence TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',reason TEXT NOT NULL DEFAULT '',created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS journey_impacts(id INTEGER PRIMARY KEY,subject TEXT NOT NULL,fact_version INTEGER NOT NULL,trip TEXT NOT NULL,revision INTEGER NOT NULL,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS journey_events(id TEXT PRIMARY KEY,event TEXT NOT NULL,locale TEXT NOT NULL,trip TEXT NOT NULL,revision INTEGER NOT NULL,status TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS journey_reviews(id TEXT PRIMARY KEY,owner TEXT NOT NULL,kind TEXT NOT NULL,target TEXT NOT NULL,revision INTEGER NOT NULL,rating INTEGER NOT NULL,visited TEXT NOT NULL,route_version INTEGER,state TEXT NOT NULL DEFAULT 'pending',UNIQUE(owner,kind,target));
 CREATE TABLE IF NOT EXISTS journey_review_versions(review TEXT NOT NULL,revision INTEGER NOT NULL,locale TEXT NOT NULL,body TEXT NOT NULL,actual_minutes INTEGER,actual_cost_minor INTEGER,created INTEGER NOT NULL,PRIMARY KEY(review,revision));
 CREATE TABLE IF NOT EXISTS journey_decisions(id INTEGER PRIMARY KEY,kind TEXT NOT NULL,target TEXT NOT NULL,actor INTEGER NOT NULL,reason TEXT NOT NULL,decision TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS journey_trip_history(trip TEXT NOT NULL,revision INTEGER NOT NULL,owner TEXT NOT NULL,document TEXT NOT NULL,created INTEGER NOT NULL,PRIMARY KEY(trip,revision));
 INSERT OR IGNORE INTO journey_trip_history SELECT id,revision,owner,plan,updated FROM member_trips;
 CREATE TRIGGER IF NOT EXISTS journey_legacy_insert AFTER INSERT ON member_trips BEGIN INSERT OR IGNORE INTO journey_trip_history VALUES(NEW.id,NEW.revision,NEW.owner,NEW.plan,NEW.updated); END;
 CREATE TRIGGER IF NOT EXISTS journey_legacy_update AFTER UPDATE ON member_trips BEGIN INSERT OR IGNORE INTO journey_trip_history VALUES(NEW.id,NEW.revision,NEW.owner,NEW.plan,NEW.updated); END;
 INSERT INTO network_settings(key,value) VALUES('journey_schema','1') ON CONFLICT(key) DO UPDATE SET value='1';");$ready=true;return $d;
}
function jq(string $sql,array $args=[]): PDOStatement {$q=journeyDb()->prepare($sql);$q->execute($args);return $q;}
function journeyPrincipal(): string {
 if(!empty($_SESSION['user'])&&isset(journeyPersonas()[$_SESSION['journey_demo']??'']))return 'demo:'.(int)$_SESSION['user'].':'.$_SESSION['journey_demo'];
 $u=socialUser();if($u){if(!empty($_SESSION['journey_guest']))jq('UPDATE journey_trips SET principal=? WHERE principal=?',['member:'.$u['id'],'guest:'.hash('sha256',$_SESSION['journey_guest'])]);return 'member:'.$u['id'];}$_SESSION['journey_guest']??=bin2hex(random_bytes(32));return 'guest:'.hash('sha256',$_SESSION['journey_guest']);
}
function journeyPersonas(): array {return [
 'noa'=>['name'=>'נועה','bio'=>'משפחה — שילוב של חינם ובתשלום','adults'=>2,'children'=>2,'finish_by'=>'16:00','budget_minor'=>15000],
 'daniel'=>['name'=>'דניאל','bio'=>'יום עם פחות הליכה','adults'=>1,'children'=>0,'max_walk_meters'=>2000],
 'maya'=>['name'=>'מאיה','bio'=>'אוכל ותרבות','adults'=>2,'children'=>0],
 'ori'=>['name'=>'אורי','bio'=>'חלון זמן קצר','adults'=>1,'children'=>0,'finish_by'=>'12:00'],
 'tamar'=>['name'=>'תמר','bio'=>'בירור מסלול ללא מדרגות','adults'=>1,'children'=>0,'required_step_free'=>true],
 'eitan'=>['name'=>'איתן','bio'=>'שמירת כמה גרסאות של היום','adults'=>2,'children'=>0],
 'yael'=>['name'=>'יעל','bio'=>'שינוי המשך היום לאחר איחור','adults'=>1,'children'=>0],
 'roni'=>['name'=>'רוני','bio'=>'בדיקת עלות לקבוצה','adults'=>6,'children'=>0,'budget_minor'=>30000]
 ];}
function journeyEnabled(string $feature='planning'): bool {
 $value=socialQuery('SELECT value FROM network_settings WHERE key=?',['journey_'.$feature])->fetchColumn();if($value==='off')return false;if(APP_ENV==='local'||!empty($_SESSION['user']))return true;
 $u=socialUser();return $value==='pilot'&&$u&&(bool)socialQuery("SELECT 1 FROM network_settings WHERE key=? AND value='on'",['journey_invite_'.$u['id']])->fetchColumn();
}
function journeyEvent(string $event,string $trip,int $rev,string $status,string $key): void {
 if(APP_ENV==='local'||!empty($_SESSION['user']))return;
 if(!in_array($event,['adapt','result','replan','apply','undo','start_self_report','visit_feedback','provider_error'],true))return;
 jq('INSERT OR IGNORE INTO journey_events VALUES(?,?,?,?,?,?,?)',[hash('sha256',journeyPrincipal().':'.$event.':'.$key),$event,siteLanguage(),$trip,$rev,$status,time()]);
}
function journeyPlace(string $legacy): string {
 $id=hash('sha256','place:'.networkCanonicalItem($legacy));jq('INSERT OR IGNORE INTO journey_places(id,legacy_id,created) VALUES(?,?,?)',[$id,networkCanonicalItem($legacy),time()]);return $id;
}
function journeyFact(string $subject,string $type): ?array {
 $r=jq('SELECT version,document FROM journey_facts WHERE subject=? AND type=? ORDER BY version DESC LIMIT 1',[$subject,$type])->fetch();if(!$r)return null;$v=json_decode($r['document'],true);$v['version']=(int)$r['version'];return $v;
}
function journeyFactWrite(string $subject,string $type,array $f,int $expected,string $reason): int {
 requireAdmin();if(!in_array($type,['price','hours','operating','accessibility','entrance','leg','duration'],true)||mb_strlen($reason)<10)throw new DomainException('INVALID_FACT');
 journeyValidateFactValue($type,$f['value']??[]);
 foreach(['observed_at','checked_at'] as $key)if(!is_int($f[$key]??null)||$f[$key]>time()||$f[$key]<0)throw new DomainException('INVALID_FACT_DATE');
 foreach(['valid_from','valid_until'] as $key)if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$f[$key]??''))throw new DomainException('INVALID_FACT_DATE');
 if($f['valid_from']>$f['valid_until']||!is_array($f['value']??null)||!in_array($f['review_status']??'',['reviewed','reported','unknown','expired'],true)||!in_array($f['conflict_state']??'',['clear','conflicting'],true))throw new DomainException('INVALID_FACT');
 $f['source_ref']=socialString($f['source_ref']??'',1000,1);$f['source_type']=socialString($f['source_type']??'',80,1);$f['ingested_at']=time();
 if(!in_array($f['source_type'],['official','provider','editor_observation'],true)||preg_match('/synthetic|fixture|test-only/i',$f['source_ref']))throw new DomainException('SYNTHETIC_NOT_PUBLISHABLE');
 if(!preg_match('/^(?:[a-f0-9]{64}|leg:[a-f0-9]{64}>[a-f0-9]{64})$/D',$subject))throw new DomainException('INVALID_SUBJECT');
 $d=journeyDb();$d->exec('BEGIN IMMEDIATE');try{
  if((int)(journeyFact($subject,$type)['version']??0)!==$expected)throw new DomainException('CONFLICT');
  jq('INSERT INTO journey_facts(subject,type,document,actor,created) VALUES(?,?,?,?,?)',[$subject,$type,json_encode($f), (string)$_SESSION['user'],time()]);$version=(int)$d->lastInsertId();
  jq('INSERT INTO journey_decisions(kind,target,actor,reason,decision,created) VALUES(?,?,?,?,?,?)',['fact',$subject,(int)$_SESSION['user'],$reason,'version:'.$version,time()]);
  // Conservative invalidation: every current planning revision is flagged, never rewritten.
  jq('INSERT INTO journey_impacts(subject,fact_version,trip,revision,created) SELECT ?,?,id,revision,? FROM journey_trips',[$subject,$version,time()]);
  $d->exec('COMMIT');return $version;
 }catch(Throwable $e){$d->exec('ROLLBACK');throw $e;}
}
function journeyValidateFactValue(string $type,array $v): void {
 $int=fn($n,$max)=>is_int($n)&&$n>=0&&$n<=$max;
 $clock=fn($s)=>is_string($s)&&preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$s);
 $date=function($s){if(!is_string($s))return false;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);return $d&&$d->format('Y-m-d')===$s;};
 $ok=false;
 switch($type){
  case 'price':$ok=$int($v['amount_minor']??null,100000000)&&preg_match('/^[A-Z]{3}$/D',$v['currency']??'')&&in_array($v['basis']??'',['person','adult','group'],true)&&(!isset($v['child_minor'])||$int($v['child_minor'],100000000));break;
  case 'duration':$ok=$int($v['minutes']??null,720)&&$v['minutes']>0&&(!isset($v['min_minutes'])||($int($v['min_minutes'],$v['minutes'])&&$v['min_minutes']>0));break;
  case 'operating':$ok=in_array($v['status']??'',['open','closed','unknown'],true);break;
  case 'accessibility':$ok=array_key_exists('step_free',$v)&&(is_bool($v['step_free'])||$v['step_free']===null);break;
  case 'entrance':$ok=is_numeric($v['latitude']??null)&&is_numeric($v['longitude']??null)&&abs((float)$v['latitude'])<=90&&abs((float)$v['longitude'])<=180;break;
  case 'leg':$ok=($v['mode']??'')==='walk'&&$int($v['minutes']??null,1440)&&$int($v['meters']??null,100000)&&(!isset($v['step_free'])||is_bool($v['step_free']));break;
  case 'hours':$ok=is_array($v['dates']??null)&&count($v['dates'])<=370;if($ok)foreach($v['dates'] as $day=>$windows){if(!$date($day)||!is_array($windows)||count($windows)>12){$ok=false;break;}foreach($windows as $w)if(!is_array($w)||!$clock($w['open']??null)||!$clock($w['close']??null)||(isset($w['last_admission'])&&!$clock($w['last_admission']))||(isset($w['close_date'])&&!$date($w['close_date']))){$ok=false;break 2;}}break;
 }
 if(!$ok)throw new DomainException('INVALID_FACT_VALUE');
}
function journeyInput(array $raw): array {
 $ids=$raw['stops']??[];if(!is_array($ids)||count($ids)<1||count($ids)>7||count(array_unique($ids))!==count($ids))throw new DomainException('INVALID_STOPS');
 $locale=networkLanguage($raw['content_locale']??siteLanguage());if($locale!==siteLanguage())throw new DomainException('CONTENT_UNAVAILABLE');
 $allowed=['content_locale','service_date','timezone','start_at','finish_by','finish_date','party','budget_minor','currency','max_walk_meters','required_step_free','anchors','locked','paid','optional','durations','buffers','completed','current_place','current_time','stops','start_id','end_id','route_version_id','private_notes','tags'];
 $i=array_intersect_key($raw,array_flip($allowed));$i['content_locale']=$locale;$i['timezone']=$i['timezone']??'Asia/Tbilisi';$i['currency']=$i['currency']??'GEL';
 if($i['timezone']!=='Asia/Tbilisi'||$i['currency']!=='GEL')throw new DomainException('PILOT_SCOPE');
 $cityItems=array_column(array_filter(tripCatalog(),fn($x)=>$x['city']==='Tbilisi'),null,'id');foreach(array_merge($ids,[$i['start_id']??'',$i['end_id']??'']) as $id)if(!is_string($id)||!isset($cityItems[$id]))throw new DomainException('CONTENT_UNAVAILABLE');
 foreach(array_merge($ids,[$i['start_id']??'',$i['end_id']??'']) as $id)if(!is_string($id)||!networkItemForLanguage($id,$locale))throw new DomainException('CONTENT_UNAVAILABLE');
 foreach(['locked','paid','optional','completed'] as $k){$i[$k]=$i[$k]??[];if(!is_array($i[$k])||array_diff($i[$k],$ids))throw new DomainException('INVALID_STOPS');}
 foreach(['anchors','durations','buffers'] as $k){$i[$k]=$i[$k]??[];if(!is_array($i[$k])||array_diff(array_keys($i[$k]),$ids))throw new DomainException('INVALID_STOPS');}
 $i['private_notes']=socialString($i['private_notes']??'',2000);$i['tags']=socialString($i['tags']??'',200);
 if(!empty($i['route_version_id'])){$source=jq("SELECT * FROM network_versions WHERE id=? AND kind='trip' AND language=?",[(int)$i['route_version_id'],$locale])->fetch();if(!$source||!networkTarget('trip',$source['target']))throw new DomainException('CONTENT_UNAVAILABLE');}
 // Every fixed reservation is an anchor; callers cannot silently drop its lock.
 $i['locked']=array_values(array_unique(array_merge($i['locked'],array_keys($i['anchors']))));
 $i['route']=array_merge(['@start'],$ids,['@end']);return $i;
}
function journeyBundle(array $i): array {
 $places=[];$versions=[];$canonical=[];$ids=array_values(array_unique(array_merge($i['stops'],[$i['start_id'],$i['end_id']])));$catalog=array_column(tripCatalog(),null,'id');
 foreach($ids as $id){$title=networkItemForLanguage($id,$i['content_locale']);if(!$title)throw new DomainException('CONTENT_UNAVAILABLE');$pid=journeyPlace($id);$canonical[$id]=$pid;$facts=[];
  foreach(['price','hours','operating','accessibility','duration','entrance'] as $type){$f=journeyFact($pid,$type);$facts[$type]=$f;$versions[$pid.':'.$type]=$f['version']??0;}
  $places[$id]=['locale'=>$i['content_locale'],'published'=>true,'title'=>$title,'visit_minutes'=>$facts['duration']['value']['minutes']??$catalog[$id]['minutes']??null,'min_visit_minutes'=>$facts['duration']['value']['min_minutes']??1,'facts'=>$facts];
  if(jq("SELECT 1 FROM journey_reports WHERE place=? AND state='pending' AND type IN ('closed','access') LIMIT 1",[$pid])->fetchColumn())$places[$id]['facts']['operating']['conflict_state']='conflicting';
 }
 $places['@start']=['locale'=>$i['content_locale'],'published'=>true,'endpoint'=>true,'title'=>$places[$i['start_id']]['title']];$places['@end']=['locale'=>$i['content_locale'],'published'=>true,'endpoint'=>true,'title'=>$places[$i['end_id']]['title']];
 $canonical['@start']=$canonical[$i['start_id']];$canonical['@end']=$canonical[$i['end_id']];$legs=[];
 foreach($i['route'] as $n=>$id){if(!$n)continue;$a=$canonical[$i['route'][$n-1]];$b=$canonical[$id];$subject='leg:'.$a.'>'.$b;$f=journeyFact($subject,'leg');$versions[$subject]=$f['version']??0;
  // Even a same-place walk is not invented; entrance routing must be reviewed.
  $legs[$i['route'][$n-1].'>'.$id]=$f;
 }
 $versions['reports']=hash('sha256',json_encode(jq('SELECT id,state FROM journey_reports ORDER BY id')->fetchAll()));
 ksort($versions);return ['places'=>$places,'legs'=>$legs,'fingerprint'=>hash('sha256',json_encode($versions)),'versions'=>$versions];
}
function journeyCalculate(array $i): array {$b=journeyBundle($i);$r=JourneyEngine::evaluate($i,$b['places'],$b['legs']);return ['input'=>$i,'result'=>$r,'fingerprint'=>$b['fingerprint']];}
function journeyOwn(string $id): array {$r=jq('SELECT * FROM journey_trips WHERE id=? AND principal=? AND locale=?',[$id,journeyPrincipal(),siteLanguage()])->fetch();if(!$r)throw new DomainException('NOT_FOUND');return $r;}
function journeyVersion(string $id,int $rev): array {$v=jq('SELECT * FROM journey_versions WHERE trip=? AND revision=?',[$id,$rev])->fetch();if(!$v)throw new DomainException('NOT_FOUND');return ['input'=>json_decode($v['input'],true),'result'=>json_decode($v['result'],true),'fingerprint'=>$v['fingerprint']];}
function journeySave(array $calc,string $title,?int $source=null): array {
 $id=bin2hex(random_bytes(16));$d=journeyDb();$d->exec('BEGIN IMMEDIATE');try{$calc=journeyCalculate($calc['input']);jq('INSERT INTO journey_trips VALUES(?,?,?,?,?,?,?)',[$id,journeyPrincipal(),1,siteLanguage(),socialString($title,120,1),$source??($calc['input']['route_version_id']??null),time()]);journeyAppend($id,1,$calc);$d->exec('COMMIT');}catch(Throwable $e){$d->exec('ROLLBACK');throw $e;}journeyEvent('result',$id,1,$calc['result']['status'],$id);return ['id'=>$id,'revision'=>1]+$calc;
}
function journeyAppend(string $id,int $rev,array $calc): void {jq('INSERT INTO journey_versions VALUES(?,?,?,?,?,?)',[$id,$rev,json_encode($calc['input']),json_encode($calc['result']),$calc['fingerprint'],time()]);}
function journeyProposal(string $id,int $rev,array $progress): array {
 if(!journeyEnabled('replanning'))throw new DomainException('FEATURE_DISABLED');$trip=journeyOwn($id);if((int)$trip['revision']!==$rev)throw new DomainException('CONFLICT');$base=journeyVersion($id,$rev);$i=$base['input'];
 if($progress){$done=$progress['completed']??[];if(!is_array($done)||array_slice($i['stops'],0,count($done))!==$done||!$done||end($done)!==($progress['current_place']??''))throw new DomainException('INVALID_PROGRESS');$i['completed']=$done;$i['current_place']=$progress['current_place'];$i['current_time']=$progress['current_time'];$i['route']=array_merge([$i['current_place']],array_slice($i['stops'],count($done)),['@end']);}
 $base=journeyCalculate($i);
 $candidates=[$i];foreach($i['route'] as $n=>$stop)if(in_array($stop,$i['optional'],true)&&!in_array($stop,array_merge($i['locked'],$i['completed']),true)){$c=$i;array_splice($c['route'],$n,1);$candidates[]=$c;}
 foreach($i['route'] as $stop)if($stop!=='@start'&&$stop!=='@end'&&!in_array($stop,array_merge($i['locked'],$i['completed']),true)){$f=journeyFact(journeyPlace($stop),'duration');$min=$f['value']['min_minutes']??null;if(is_int($min)&&$min>0&&JourneyEngine::current($f,time(),$i['service_date'],'duration')){$c=$i;$c['durations'][$stop]=$min;$candidates[]=$c;}}
 // Alternatives must be an explicit reviewed city list, never generated place facts.
 $cityIds=array_column(array_filter(tripCatalog(),fn($x)=>$x['city']==='Tbilisi'),'id');$curated=json_decode((string)socialQuery("SELECT value FROM network_settings WHERE key='journey_alternatives'")->fetchColumn(),true)??[];
 foreach($i['route'] as $n=>$stop)if($n&&$stop!=='@end'&&!in_array($stop,array_merge($i['locked'],$i['completed']),true))foreach(array_slice($curated[$stop]??[],0,3) as $alt){if(!in_array($alt,$cityIds,true)||!networkItemForLanguage($alt,siteLanguage())||in_array($alt,$i['stops'],true))continue;$c=$i;$c['route'][$n]=$alt;$c['stops']=array_values(array_unique(array_merge($c['stops'],[$alt])));$candidates[]=$c;}
 $options=[];$fingerprints=[];foreach(array_slice($candidates,0,25) as $candidate){$candidate['stops']=array_values(array_unique(array_merge($candidate['completed'],array_values(array_filter($candidate['route'],fn($id)=>$id!=='@start'&&$id!=='@end')))));foreach(['locked','paid','optional'] as $k)$candidate[$k]=array_values(array_intersect($candidate[$k],$candidate['stops']));foreach(['anchors','durations','buffers'] as $k)$candidate[$k]=array_intersect_key($candidate[$k],array_flip($candidate['stops']));$calc=journeyCalculate($candidate);$fingerprints[]=$calc['fingerprint'];if($calc['result']['status']==='READY')$options[]=['id'=>(string)count($options),'calculation'=>$calc,'diff'=>['cost_minor'=>$base['result']['cost_minor']!==null?$calc['result']['cost_minor']-$base['result']['cost_minor']:null,'walking_minutes'=>$calc['result']['walking_minutes']-$base['result']['walking_minutes'],'elapsed_minutes'=>($calc['result']['elapsed_minutes']??0)-($base['result']['elapsed_minutes']??0)]];if(count($options)===3)break;}
 $pid=bin2hex(random_bytes(16));$doc=['status'=>$options?'READY':($base['result']['status']==='NEEDS_DATA'?'NEEDS_DATA':'NO_OPTION_FOUND'),'options'=>$options,'base'=>$base,'progress'=>$progress,'warnings'=>['BOUNDED_SEARCH_ONLY']];jq('INSERT INTO journey_proposals(id,trip,base_revision,document,fingerprint,expires) VALUES(?,?,?,?,?,?)',[$pid,$id,$rev,json_encode($doc),hash('sha256',json_encode($fingerprints)),time()+600]);journeyEvent('replan',$id,$rev,$doc['status'],$pid);return ['proposal_id'=>$pid,'expires_at'=>time()+600]+$doc;
}
function journeyApply(string $id,string $proposal,string $selection,int $revision,string $key): array {
 if(!journeyEnabled('replanning'))throw new DomainException('FEATURE_DISABLED');if(!preg_match('/^[a-zA-Z0-9_-]{8,80}$/D',$key))throw new DomainException('INVALID_KEY');$d=journeyDb();$d->exec('BEGIN IMMEDIATE');
 try{$trip=journeyOwn($id);$p=jq('SELECT * FROM journey_proposals WHERE id=? AND trip=?',[$proposal,$id])->fetch();if(!$p)throw new DomainException('NOT_FOUND');
  if($p['applied_revision']!==null){if($p['idempotency']!==$key||$p['selection']!==$selection)throw new DomainException('CONFLICT');$r=['revision'=>(int)$p['applied_revision']]+journeyVersion($id,(int)$p['applied_revision']);$d->exec('COMMIT');return $r;}
  if((int)$trip['revision']!==$revision||(int)$p['base_revision']!==$revision)throw new DomainException('CONFLICT');if((int)$p['expires']<time())throw new DomainException('EXPIRED');$options=json_decode($p['document'],true)['options'];$chosen=null;foreach($options as $o)if($o['id']===$selection)$chosen=$o['calculation'];if(!$chosen)throw new DomainException('INVALID_OPTION');
  $current=journeyCalculate($chosen['input']);if($current['fingerprint']!==$chosen['fingerprint'])throw new DomainException('CONFLICT');if($current['result']['status']!=='READY')throw new DomainException('NO_LONGER_FEASIBLE');
  journeyAppend($id,$revision+1,$current);jq('UPDATE journey_trips SET revision=revision+1,updated=? WHERE id=? AND revision=?',[time(),$id,$revision]);jq('UPDATE journey_proposals SET applied_revision=?,idempotency=?,selection=? WHERE id=?',[$revision+1,$key,$selection,$proposal]);$d->exec('COMMIT');journeyEvent('apply',$id,$revision+1,'READY',$proposal);return ['revision'=>$revision+1]+$current;
 }catch(Throwable $e){$d->exec('ROLLBACK');throw $e;}
}
function journeyUndo(string $id,int $expected,int $restore): array {
 $d=journeyDb();$d->exec('BEGIN IMMEDIATE');try{$t=journeyOwn($id);if((int)$t['revision']!==$expected)throw new DomainException('CONFLICT');$old=journeyVersion($id,$restore);$calc=journeyCalculate($old['input']);journeyAppend($id,$expected+1,$calc);jq('UPDATE journey_trips SET revision=?,updated=? WHERE id=?',[$expected+1,time(),$id]);$d->exec('COMMIT');return ['revision'=>$expected+1]+$calc;}catch(Throwable $e){$d->exec('ROLLBACK');throw $e;}
}
function journeyRouteWarnings(array $plan): array {
 $codes=[];foreach($plan['items']??[] as $item){if(!empty($item['custom']))continue;$pid=hash('sha256','place:'.networkCanonicalItem($item['id']));$f=journeyFact($pid,'operating');
  if(($f['value']['status']??'')==='closed')$codes[]='PLACE_CLOSED';
  if(jq("SELECT 1 FROM journey_reports WHERE place=? AND state='pending' AND type IN ('closed','access') LIMIT 1",[$pid])->fetchColumn())$codes[]='UNVERIFIED_REPORT';
 }return array_values(array_unique($codes));
}
