<?php
declare(strict_types=1);
require_once __DIR__.'/social-network.php';
require_once __DIR__.'/member-registration.php';
require_once __DIR__.'/member-remember.php';
require_once __DIR__.'/network-review.php';
require_once __DIR__.'/trip-document.php';
// Member accounts are separate from administration and anonymous activity scores.
function socialDb(): PDO {
 static $d;if($d)return $d;
 $d=new PDO('sqlite:'.STORAGE_PATH.'/community.sqlite');$d->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$d->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
 $d->sqliteCreateFunction('unicode_lower',fn($s)=>mb_strtolower((string)$s),1);
 $d->sqliteCreateFunction('social_hashtag',fn($s,$tag)=>in_array(mb_strtolower(ltrim((string)$tag,'#')),saTokens((string)$s,'#'),true)?1:0,2);
 $d->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
 $d->exec("CREATE TABLE IF NOT EXISTS members(id TEXT PRIMARY KEY,handle TEXT UNIQUE NOT NULL,password TEXT NOT NULL,recovery TEXT NOT NULL,session_version INTEGER NOT NULL DEFAULT 1,state TEXT NOT NULL DEFAULT 'active',created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS member_trips(id TEXT PRIMARY KEY,owner TEXT NOT NULL REFERENCES members(id),revision INTEGER NOT NULL DEFAULT 1,title TEXT NOT NULL,plan TEXT NOT NULL,public_plan TEXT,pending_plan TEXT,state TEXT NOT NULL DEFAULT 'private',updated INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS member_places(id TEXT PRIMARY KEY,owner TEXT NOT NULL REFERENCES members(id),document TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',moderator_note TEXT NOT NULL DEFAULT '',created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS member_ratings(kind TEXT NOT NULL,target TEXT NOT NULL,voter TEXT NOT NULL REFERENCES members(id),score INTEGER NOT NULL CHECK(score BETWEEN 1 AND 5),updated INTEGER NOT NULL,PRIMARY KEY(kind,target,voter));
 CREATE TABLE IF NOT EXISTS member_reports(id INTEGER PRIMARY KEY,reporter TEXT NOT NULL REFERENCES members(id),kind TEXT NOT NULL,target TEXT NOT NULL,reason TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'open',created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS member_moderation(id INTEGER PRIMARY KEY,admin_id INTEGER NOT NULL,kind TEXT NOT NULL,target TEXT NOT NULL,action TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS member_images(id TEXT PRIMARY KEY,place_id TEXT NOT NULL REFERENCES member_places(id));
 CREATE TABLE IF NOT EXISTS member_trip_images(id TEXT PRIMARY KEY,trip_id TEXT NOT NULL REFERENCES member_trips(id),created INTEGER NOT NULL);
 CREATE INDEX IF NOT EXISTS member_trips_owner ON member_trips(owner,updated);
 CREATE INDEX IF NOT EXISTS member_places_state ON member_places(state,created);");
 if(!in_array('shared_plan',array_column($d->query('PRAGMA table_info(member_trips)')->fetchAll(),'name'),true))$d->exec('ALTER TABLE member_trips ADD COLUMN shared_plan TEXT');
 networkMigrate($d);networkReviewMigrate($d);cpMigrate($d);return $d;
}
function socialQuery(string $sql,array $args=[]): PDOStatement {$q=socialDb()->prepare($sql);$q->execute($args);return $q;}
function socialT(string $key): string {if(in_array($key,['password_mismatch','email_invalid','registration_unavailable','captcha_required','captcha_unavailable','captcha_title'],true))return mrLabels($key);static $labels;$labels??=json_decode(file_get_contents(ROOT.'/content/community-labels.json'),true);return isset($labels[$key])?(siteLanguage()==='ka'?($labels[$key][3]??nt($key)):languagePhrase(...array_slice($labels[$key],0,3))):nt($key);}
function socialUser(): ?array {
 if(empty($_SESSION['member_id']))return memberRememberRestore();
 $u=socialQuery('SELECT id,handle,state,session_version FROM members WHERE id=?',[$_SESSION['member_id']])->fetch();
 if(!$u||$u['state']!=='active'||(int)$u['session_version']!==(int)($_SESSION['member_version']??0)||time()-(int)($_SESSION['member_seen']??0)>86400){unset($_SESSION['member_id'],$_SESSION['member_version'],$_SESSION['member_seen']);return memberRememberRestore();}
 $_SESSION['member_seen']=time();if(empty($_SESSION['member_remember_checked'])){memberRememberIssue($u);}else{memberRememberRenew();}return $u;
}
function socialRequire(): array {$u=socialUser();if(!$u)throw new DomainException('login_required');return $u;}
function socialLogin(array $u): void {session_regenerate_id(true);$_SESSION['member_id']=$u['id'];$_SESSION['member_version']=$u['session_version']??1;$_SESSION['member_seen']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));memberRememberIssue($u);}
// Owner requested a temporary pause of content quotas during site testing.
function socialContentLimits(): array {static $config;$config??=json_decode(file_get_contents(ROOT.'/content/community-limits.json'),true);return $config;}
function socialContentLimitsPaused(?string $action=null): bool {$config=socialContentLimits();return ($config['enabled']??true)===false&&($action===null||in_array($action,$config['testing_bypass_actions']??[],true));}
function socialLimit(string $name,int $limit,int $seconds): void {if(socialContentLimitsPaused($name))return;if(!throttle('social:'.$name.':'.hash('sha256',($_SERVER['REMOTE_ADDR']??'local').':'.(in_array($name,['auth','register'],true)?'':($_SESSION['member_id']??''))),$limit,$seconds))throw new DomainException('rate_limit');}
function socialString($value,int $max,int $min=0): string {if(!is_string($value)||mb_strlen(trim($value))<$min||mb_strlen($value)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))throw new DomainException('invalid');return trim($value);}
function socialAccountAction(string $action): void {
 socialLimit('auth',12,900);$handle=strtolower(socialString($_POST['handle']??'',30,3));$password=socialString($_POST['password']??'',128,8);if(strlen($password)>72)throw new DomainException('invalid');
 if(!preg_match('/^[a-z0-9][a-z0-9_-]{2,29}$/D',$handle))throw new DomainException('handle_hint');
 if($action==='register'){
  socialLimit('register',4,86400);$registered=mrRegister($_POST,siteLanguage());socialLogin(['id'=>$registered['member_id']]);$_SESSION['new_recovery']=$registered['recovery_code'];$_SESSION['measurement_success']='register';$_SESSION['registration_event']=$registered['registration_event']??'';
 }elseif($action==='login'){
  $u=socialQuery('SELECT * FROM members WHERE handle=?',[$handle])->fetch();$hash=$u['password']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
  if(!password_verify($password,$hash)||!$u||$u['state']!=='active')throw new DomainException('login_failed');unset($_SESSION['registration_event']);socialLogin($u);
 }elseif($action==='recover'){
  $code=socialString($_POST['recovery']??'',80,1);$u=socialQuery('SELECT * FROM members WHERE handle=?',[$handle])->fetch();
  if(!$u||$u['state']!=='active'||!hash_equals($u['recovery'],hash('sha256',$code)))throw new DomainException('login_failed');
  $recovery=bin2hex(random_bytes(20));socialQuery('UPDATE members SET password=?,recovery=?,session_version=session_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),hash('sha256',$recovery),$u['id']]);$u['session_version']++;socialLogin($u);$_SESSION['new_recovery']=$recovery;
 }
}
function socialPlan(array $raw): array {
 $city=socialString($raw['city']??'',100);
 $days=$raw['days']??null;$travelers=$raw['travelers']??null;if(!is_int($days)||$days<0||$days>30||!is_int($travelers)||$travelers<1||$travelers>30||!is_array($raw['items']??null)||count($raw['items'])>100)throw new DomainException('invalid');
 $items=[];$seen=[];foreach(sxItems($raw['items']) as $x){if(!is_array($x))throw new DomainException('invalid');$id=socialString($x['id']??'',95,1);$day=$x['day']??null;
  if(!preg_match('/^[A-Za-z0-9_-]+$/D',$id)||isset($seen[$x['item_id']])||!is_int($day)||$day<0||$day>$days)throw new DomainException('invalid');$seen[$x['item_id']]=true;
  $items[]=['photo_override'=>tdpReference($x['photo_override']??null),'item_id'=>$x['item_id'],'active'=>$x['active'],'alternative_to'=>$x['alternative_to'],'optional'=>!empty($x['optional']),'stay_minutes'=>networkMinutes($x['stay_minutes']??null),'travel_minutes'=>networkMinutes($x['travel_minutes']??null),'mode'=>in_array($x['mode']??'',['walk','transit','car','bike'],true)?$x['mode']:'walk','id'=>$id,'day'=>$day,'title'=>socialString($x['title']??'',180),'private_coords'=>tsCoords($x['private_coords']??null),'private_address'=>socialString($x['private_address']??'',300),'note'=>socialString($x['note']??'',500),'type'=>($x['type']??'')==='custom'?'custom':'experience','booked'=>($x['booked']??false)===true];
 }
 return photoMetadata($raw)+['origin_trip'=>preg_match('/^[a-f0-9]{32}$/D',(string)($raw['origin_trip']??''))?$raw['origin_trip']:'','origin_share'=>preg_match('/^[a-f0-9]{32}$/D',(string)($raw['origin_share']??''))?$raw['origin_share']:'','document'=>tdDocument($raw['document']??null,$items),'content_kind'=>in_array($raw['content_kind']??'',['route','collection','story'],true)?$raw['content_kind']:'route','facts'=>tdFacts($raw['facts']??[]),'description'=>socialString($raw['description']??'',600),'route_facts'=>cpRouteFacts(is_array($raw['route_facts']??null)?$raw['route_facts']:[]),'region'=>socialString($raw['region']??'',100),'country'=>socialString($raw['country']??'',64),'connections'=>rxConnections($items,is_array($raw['connections']??null)?$raw['connections']:[],$raw['travel_mode']??'walk'),'route_status'=>($raw['route_status']??'')==='tried'?'tried':'planned','travel_mode'=>in_array($raw['travel_mode']??'',['walk','car','transit','bike'],true)?$raw['travel_mode']:'walk','estimated_minutes'=>networkMinutes($raw['estimated_minutes']??null),'public_title'=>socialString($raw['public_title']??'',120),'public_summary'=>socialString($raw['public_summary']??'',12000),'language'=>networkLanguage($raw['language']??networkPublicationDefault()),'catalog_language'=>validSiteLanguage($raw['catalog_language']??null)?$raw['catalog_language']:siteLanguage(),'parent_route_id'=>networkParent($raw['parent_route_id']??''),'parent_version_id'=>(int)($raw['parent_version_id']??0),'version'=>1,'city'=>$city,'days'=>$days,'travelers'=>$travelers,'start'=>preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)($raw['start']??''))?$raw['start']:'','end'=>preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)($raw['end']??''))?$raw['end']:'','activeDay'=>max(0,min($days,(int)($raw['activeDay']??0))),'items'=>$items];
}
function socialSaveTrip(array $u,array $input): array {
 if(!is_array($input['plan']??null))throw new DomainException('invalid');$p=socialPlan($input['plan']);foreach($p['items'] as $item)if(!empty($item['photo_override'])&&!tdpAllowed($item['photo_override'],$u))throw new DomainException('not_found');$title=socialString($input['title']??socialT('my_trip'),120,1);$id=$input['id']??'';if($p['parent_route_id']){ $source=socialQuery('SELECT owner FROM member_trips WHERE id=?',[$p['parent_route_id']])->fetch();$snapshot=socialQuery("SELECT 1 FROM network_versions WHERE id=? AND kind='trip' AND target=?",[$p['parent_version_id'],$p['parent_route_id']])->fetchColumn();if(!$source||(!$snapshot&&$p['parent_version_id']!==0)){$p['parent_route_id']='';$p['parent_version_id']=0;}}
 if($id){$old=socialQuery('SELECT * FROM member_trips WHERE id=? AND owner=?',[$id,$u['id']])->fetch();if(!$old)throw new DomainException('not_found');$previousPlan=json_decode($old['plan'],true);$previousItems=array_column($previousPlan['items']??[],null,'item_id');$inputItems=array_column($input['plan']['items']??[],null,'item_id');foreach($p['items'] as &$savedItem)if(!array_key_exists('photo_override',$inputItems[$savedItem['item_id']]??[])&&!empty($previousItems[$savedItem['item_id']]['photo_override']))$savedItem['photo_override']=$previousItems[$savedItem['item_id']]['photo_override'];unset($savedItem);foreach(['route_facts','country','region'] as $key)if(!array_key_exists($key,$input['plan']))$p[$key]=$previousPlan[$key]??($key==='route_facts'?[]:'');foreach(['document','facts','content_kind','origin_share','origin_trip'] as $key)if(!array_key_exists($key,$input['plan'])&&array_key_exists($key,$previousPlan))$p[$key]=$previousPlan[$key];$p['document']=tdDocument($p['document']??null,$p['items']);foreach(photoMetadata($previousPlan) as $key=>$value)if(!array_key_exists($key,$input['plan']))$p[$key]=$value;$rev=(int)($input['revision']??0);if((int)$old['revision']!==$rev)throw new DomainException('conflict');
  $q=socialQuery('UPDATE member_trips SET plan=?,title=?,revision=revision+1,updated=? WHERE id=? AND owner=? AND revision=?',[json_encode($p,JSON_UNESCAPED_UNICODE),$title,time(),$id,$u['id'],$rev]);if(!$q->rowCount())throw new DomainException('conflict');$revision=$rev+1;
 }else{
  if((int)socialQuery('SELECT COUNT(*) FROM member_trips WHERE owner=?',[$u['id']])->fetchColumn()>=100)throw new DomainException('trip_limit');
  $id=bin2hex(random_bytes(16));socialQuery('INSERT INTO member_trips(id,owner,title,plan,updated) VALUES(?,?,?,?,?)',[$id,$u['id'],$title,json_encode($p,JSON_UNESCAPED_UNICODE),time()]);$revision=1;if($p['parent_route_id'])networkEvent('route_clone','trip',$p['parent_route_id']);
 }
 tdpClaim($p,$u);return ['id'=>$id,'revision'=>$revision,'url'=>languageUrl('/community/trips/'.$id.'/',siteLanguage()),'message'=>socialT('saved_private')];
}
function socialPublicPlan(array $plan): array {
 $catalog=array_column(tripCatalog(),null,'id');$out=photoMetadata($plan)+['route_facts'=>$plan['route_facts']??[],'country'=>$plan['country']??'','region'=>$plan['region']??'','language'=>$plan['language']??networkPublicationDefault(),'catalog_language'=>siteLanguage(),'parent_route_id'=>$plan['parent_route_id']??'','parent_version_id'=>$plan['parent_version_id']??0,'version'=>1,'city'=>$plan['city'],'days'=>$plan['days'],'items'=>[]];$pending=false;
 foreach($plan['items'] as $x){if(($x['active']??true)===false)continue;$known=$catalog[$x['id']]??null;if(!$known&&($title=networkItemForLanguage($x['id'],$plan['language']??networkPublicationDefault())))$known=['title'=>$title];if(!$known||$x['type']==='custom')$pending=true;
  // Never publish personal notes, booking status, travel dates or party size.
  $out['items'][]=['item_id'=>$x['item_id']??$x['id'],'optional'=>!empty($x['optional']),'stay_minutes'=>$x['stay_minutes']??null,'travel_minutes'=>$x['travel_minutes']??null,'mode'=>$x['mode']??'walk','id'=>$x['id'],'day'=>$x['day'],'title'=>$known?$known['title']:$x['title'],'custom'=>!$known||$x['type']==='custom'];
 }$total=0;$unknownTime=false;$free=true;$paid=false;foreach($plan['items'] as $x){if(($x['active']??true)===false)continue;$c=$catalog[$x['id']]??[];$stay=$x['stay_minutes']??$c['minutes']??null;$travel=$x['travel_minutes']??null;if($stay===null||$travel===null)$unknownTime=true;$total+=(int)$stay+(int)$travel;if(($c['cost']??'')!=='free')$free=false;if(($c['cost']??'')==='paid')$paid=true;}$out['connections']=rxConnections($out['items'],$plan['connections']??[],$plan['travel_mode']??'walk');$out['total_minutes']=rxTotals($out['items'],$out['connections'])['total'];$out['cost']=$free?'free':($paid?'paid':'unknown');$out['route_status']=$plan['route_status']??'planned';$out['travel_mode']=$plan['travel_mode']??'walk';$out['estimated_minutes']=$plan['estimated_minutes']??null;$out['destinations']=array_values(array_unique(array_filter(array_map(fn($x)=>$catalog[$x['id']]['city']??'', $plan['items']))));if(!$out['items'])throw new DomainException('empty_trip');return ['plan'=>$out,'pending'=>$pending];
}
function socialPublish(array $u,string $id,int $revision,bool $shareOnly=false): array {
 $own=!socialDb()->inTransaction();if($own)socialDb()->beginTransaction();try{$result=socialPublishWrite($u,$id,$revision,$shareOnly);if($own){socialDb()->commit();if(!$shareOnly&&empty($result['pending']))rmAction($u['id'],'post_published',$id);}return $result;}catch(Throwable $e){if($own&&socialDb()->inTransaction())socialDb()->rollBack();throw $e;}
}
function socialPublishWrite(array $u,string $id,int $revision,bool $shareOnly=false): array {
 networkPublicationAllowed();if(!$shareOnly&&seFirst('trip',$id)&&!seAllowed($u,'trip',$id))throw new DomainException('edit_expired');$row=socialQuery('SELECT * FROM member_trips WHERE id=? AND owner=?',[$id,$u['id']])->fetch();if(!$row)throw new DomainException('not_found');if((int)$row['revision']!==$revision)throw new DomainException('conflict');if($row['state']==='blocked')throw new DomainException('blocked');
 $private=json_decode($row['plan'],true);if(!$shareOnly&&count($private['items']??[])<2)throw new DomainException('empty_trip');$r=socialPublicPlan($private);$r['plan']['public_title']=socialString($_POST['public_title']??$private['public_title']??'',120);$r['plan']['summary']=socialString($_POST['summary']??$private['public_summary']??'',12000);$r['plan']['photos']=socialQuery('SELECT id FROM member_trip_images WHERE trip_id=? ORDER BY created,id',[$id])->fetchAll(PDO::FETCH_COLUMN);$json=json_encode($r['plan'],JSON_UNESCAPED_UNICODE);
 // Direct links remain unlisted. Member publications go live immediately after validation.
 if($shareOnly)$q=socialQuery('UPDATE member_trips SET shared_plan=?,revision=revision+1 WHERE id=? AND revision=?',[$json,$id,$revision]);
 elseif($r['pending']){if(!seFirst('trip',$id))crQuota($u['id'],'post');$q=socialQuery("UPDATE member_trips SET pending_plan=?,state='pending',revision=revision+1,updated=? WHERE id=? AND revision=?",[$json,time(),$id,$revision]);}
 else {if(!seFirst('trip',$id))crQuota($u['id'],'post');networkValidateRoute($r['plan']);if(!socialQuery('SELECT 1 FROM social_audiences WHERE kind=? AND target=?',['trip',$id])->fetchColumn())saStore('trip',$id,$u['id'],saInput($_POST,$u['id']));networkApproveLocale('trip',$id,$r['plan']['language'],$r['plan'],'community:'.$u['id']);$q=socialQuery("UPDATE member_trips SET public_plan=?,pending_plan=NULL,state='published',revision=revision+1,updated=? WHERE id=? AND revision=?",[$json,time(),$id,$revision]);}
 if(!$q->rowCount())throw new DomainException('conflict');return ['id'=>$id,'revision'=>$revision+1,'pending'=>!$shareOnly&&$r['pending'],'url'=>languageUrl('/community/'.($shareOnly?'shared':'trips').'/'.$id.'/?content_lang='.rawurlencode($r['plan']['language']),siteLanguage()),'message'=>($shareOnly?socialT('shared_link_ready'):rxT('publication_published'))];
}
function socialPublicTrip(string $id,?int $version=null): ?array {$r=socialQuery("SELECT t.*,m.handle FROM member_trips t JOIN members m ON m.id=t.owner WHERE t.id=? AND t.public_plan IS NOT NULL AND t.state!='blocked' AND m.state='active'",[$id])->fetch();if(!$r||!saCanView('trip',$id,$r['owner'])||networkBlocked(socialUser()['id']??'',$r['owner']))return null;$l=networkLocale('trip',$id);if($l&&$version!==null){$v=socialQuery("SELECT document,id AS source_version FROM network_versions WHERE id=? AND kind='trip' AND target=? AND language=?",[$version,$id,networkContentLanguage()])->fetch();$l=$v?:null;}if(!$l||!networkRouteVisible(json_decode($l['document'],true),networkContentLanguage()))return null;$r['public_plan']=$l['document'];$r['public_version']=(int)$l['source_version'];return $r;}
function socialSharedTrip(string $id): ?array {$r=socialQuery("SELECT t.*,m.handle FROM member_trips t JOIN members m ON m.id=t.owner WHERE t.id=? AND t.shared_plan IS NOT NULL AND t.state!='blocked' AND m.state='active'",[$id])->fetch();if($r){if(!saCanView('trip',$id,$r['owner']))return null;$p=json_decode($r['shared_plan'],true);if(($p['language']??'he')!==networkContentLanguage()||networkBlocked(socialUser()['id']??'',$r['owner']))return null;$r['public_plan']=$r['shared_plan'];}return $r?:null;}
function socialRating(string $kind,string $target): array {return socialQuery("SELECT COUNT(*) count,AVG(r.score) average FROM member_ratings r JOIN members m ON m.id=r.voter WHERE r.kind=? AND r.target=? AND m.state='active'",[$kind,$target])->fetch();}
function socialRate(array $u,string $kind,string $target,int $score): void {
 if($kind!=='trip'||$score<1||$score>5)throw new DomainException('invalid');
 $owner=$kind==='trip'?(socialPublicTrip($target)['owner']??null):socialQuery("SELECT id FROM members WHERE id=? AND state='active'",[$target])->fetchColumn();
 if(!$owner)throw new DomainException('not_found');if($owner===$u['id'])throw new DomainException('self_rating');
 socialLimit('rate',60,3600);socialQuery('INSERT INTO member_ratings VALUES(?,?,?,?,?) ON CONFLICT(kind,target,voter) DO UPDATE SET score=excluded.score,updated=excluded.updated',[$kind,$target,$u['id'],$score,time()]);
}
function socialPlaceUpload(array $u): string {
 networkPublicationAllowed();socialLimit('place',10,86400);$city=socialString($_POST['city']??'',100,1);$type=socialString($_POST['category']??'',30,1);
 if(!isset(tripDestinations()[$city])||!in_array($type,['city','nature','museums','food','shows','water','markets','culture'],true))throw new DomainException('invalid');
 $p=['title'=>socialString($_POST['title']??'',180,3),'description'=>socialString($_POST['description']??'',6000,20),'city'=>$city,'category'=>$type,'address'=>socialString($_POST['address']??'',500),'hours'=>socialString($_POST['hours']??'',1000),'price'=>socialString($_POST['price']??'',200),'cost'=>in_array($_POST['cost']??'',['free','paid','unknown'],true)?$_POST['cost']:'unknown','source_url'=>networkSource($_POST['source_url']??''),'latitude'=>($_POST['latitude']??'')!==''?networkCoordinate($_POST['latitude'],90):null,'longitude'=>($_POST['longitude']??'')!==''?networkCoordinate($_POST['longitude'],180):null,'language'=>networkLanguage($_POST['content_language']??networkPublicationDefault()),'photo'=>''];
 $p['offer']=placeOfferInput($_POST,$p['cost']);$p['publication_origin']='community';
 if(empty($_POST['rights']))throw new DomainException('rights_required');
 $existing=(string)($_POST['place_id']??'');$old=$existing?socialQuery("SELECT * FROM member_places WHERE id=? AND owner=? AND state IN ('rejected','needs_info')",[$existing,$u['id']])->fetch():null;if($existing&&!$old)throw new DomainException('not_found');$id=$existing?:bin2hex(random_bytes(16));$photos=$old?[]:socialUploadImages($id);if($old)$photos=json_decode($old['document'],true)['photos']??[];$p['photos']=$photos;$p['photo']=$photos[0]??'';
 try{socialDb()->beginTransaction();if($old)socialQuery("UPDATE member_places SET document=?,state='pending' WHERE id=? AND owner=?",[json_encode($p,JSON_UNESCAPED_UNICODE),$id,$u['id']]);else socialQuery("INSERT INTO member_places(id,owner,document,created,state) VALUES(?,?,?,?,'pending')",[$id,$u['id'],json_encode($p,JSON_UNESCAPED_UNICODE),time()]);foreach($old?[]:$photos as $imageId)socialQuery('INSERT INTO member_images VALUES(?,?)',[$imageId,$id]);socialDb()->commit();cmAutoApprove();}
 catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();if(!$old)socialDiscardImages($photos);throw $e;}return $id;
}
function socialDiscardImages(array $ids): void {foreach($ids as $id)if(preg_match('/^[a-f0-9]{32}$/D',$id)){ $file=STORAGE_PATH.'/community-images/'.$id.'.jpg';if(is_file($file))unlink($file);socialQuery("UPDATE community_photo_review SET state='discarded' WHERE id=?",[$id]);}}
function socialUploadImages(string $firstId,int $maximum=5): array {
 if(disk_free_space(STORAGE_PATH)<500000000)throw new DomainException('image_invalid');
 $upload=$_FILES['photo']??null;$files=[];$photos=[];
 if($upload){if(is_array($upload['name'])){if(count($upload['name'])>$maximum)throw new DomainException('image_invalid');foreach($upload['name'] as $i=>$unused)$files[]=['error'=>$upload['error'][$i],'size'=>$upload['size'][$i],'tmp_name'=>$upload['tmp_name'][$i]];}else $files[]=$upload;}
 $dir=STORAGE_PATH.'/community-images';if(!is_dir($dir))mkdir($dir,0700,true);$written=[];
 try{foreach($files as $file){if($file['error']===UPLOAD_ERR_NO_FILE)continue;
  if(count($photos)>=$maximum||$file['error']!==UPLOAD_ERR_OK||$file['size']>5000000||!is_uploaded_file($file['tmp_name']))throw new DomainException('image_invalid');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new DomainException('image_invalid');
  $imageId=$photos?bin2hex(random_bytes(16)):$firstId;$input=tempnam($dir,'upload-');$output=$dir.'/'.$imageId.'.jpg';$written[]=$imageId;
  try{if(!move_uploaded_file($file['tmp_name'],$input))throw new DomainException('image_invalid');$python=getenv('TRIPOVELA_PYTHON')?:(PHP_OS_FAMILY==='Windows'?'python':'python3');$proc=proc_open([$python,ROOT.'/bin/community_image.py',$input,$output],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new DomainException('image_invalid');fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new DomainException('image_invalid');$photos[]=$imageId;chmod($output,0600);}finally{if(is_file($input))unlink($input);}
 }if($photos){$member=socialRequire();crPhotoRegister($photos,$member['id']);}return $photos;}catch(Throwable $e){socialDiscardImages($written);throw $e;}
}
function socialTripPhotos(array $u,string $id,int $revision): void {
 socialLimit('trip_photos',20,86400);$row=socialQuery('SELECT * FROM member_trips WHERE id=? AND owner=?',[$id,$u['id']])->fetch();if(!$row)throw new DomainException('not_found');if((int)$row['revision']!==$revision)throw new DomainException('conflict');if($row['state']==='blocked')throw new DomainException('blocked');if(empty($_POST['rights']))throw new DomainException('rights_required');
 $count=(int)socialQuery('SELECT COUNT(*) FROM member_trip_images WHERE trip_id=?',[$id])->fetchColumn();$images=socialUploadImages(bin2hex(random_bytes(16)),5-$count);if(!$images)throw new DomainException('image_invalid');
 try{socialDb()->beginTransaction();$q=socialQuery('UPDATE member_trips SET revision=revision+1 WHERE id=? AND owner=? AND revision=?',[$id,$u['id'],$revision]);if(!$q->rowCount())throw new DomainException('conflict');foreach($images as $photo)socialQuery('INSERT INTO member_trip_images VALUES(?,?,?)',[$photo,$id,time()]);socialDb()->commit();}
 catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();socialDiscardImages($images);throw $e;}
}

function socialCatalog(): array {
 $out=[];foreach(socialQuery("SELECT p.* FROM member_places p JOIN members m ON m.id=p.owner WHERE p.state='approved' AND m.state='active'") as $row){$l=networkLocale('place',$row['id'],siteLanguage())?:socialQuery("SELECT * FROM network_localizations WHERE kind='place' AND target=? AND state='published' ORDER BY updated,language LIMIT 1",[$row['id']])->fetch();if(!$l||!saCanView('place',$row['id'],$row['owner'],'')||!saCanView('place',$row['id'],$row['owner'])||crHidden('place',$row['id'])||networkPlaceAlias($row['id'])!==$row['id'])continue;$p=json_decode($l['document'],true);$out[]=['id'=>'community-'.$row['id'],'city'=>$p['city'],'title'=>$p['title'],'summary'=>$p['description'],'type'=>$p['category'],'duration'=>'','minutes'=>null,'image'=>!empty($p['photo'])&&crPhotoAllowed($p['photo'])?'/community/media/'.$p['photo'].'/':'','credit'=>socialT('member_content'),'url'=>languageUrl('/community/places/'.$row['id'].'/?content_lang='.rawurlencode($l['language']),siteLanguage()),'cost'=>$p['cost'],'product'=>null,'price'=>null,'price_label'=>placeOfferPrice(array_replace($p,['offer'=>json_decode($row['document'],true)['offer']??[]])),'community'=>true];}return $out;
}
function socialModerate(string $kind,string $id,string $action,int $revision=0): void {
 requireAdmin();$admin=(int)$_SESSION['user'];if(!in_array($action,['approve','reject','block','restore','resolve'],true))throw new DomainException('invalid');
 socialDb()->beginTransaction();try{networkReviewGuard($kind,$id,$action);
 if($kind==='place'){
  if(!in_array($action,['approve','reject','block'],true))throw new DomainException('invalid');
  $placeState=socialQuery('SELECT state FROM member_places WHERE id=?',[$id])->fetchColumn();if(!$placeState)throw new DomainException('not_found');if(in_array($action,['approve','reject'],true)&&$placeState!=='pending')throw new DomainException('conflict');
  if(socialQuery('SELECT 1 FROM network_place_aliases WHERE source=?',[$id])->fetchColumn())throw new DomainException('conflict');
  socialQuery('UPDATE member_places SET state=?,moderator_note=? WHERE id=?',[$action==='approve'?'approved':($action==='block'?'blocked':'rejected'),socialString($_POST['moderator_note']??'',500),$id]);
  if($action==='approve'){$pr=socialQuery('SELECT document FROM member_places WHERE id=?',[$id])->fetchColumn();$pd=json_decode($pr,true);networkApproveLocale('place',$id,$pd['language']??'he',$pd,(string)$admin);}else socialQuery("UPDATE network_localizations SET state='hidden' WHERE kind='place' AND target=?",[$id]);
 }elseif($kind==='trip'){
  $r=socialQuery('SELECT * FROM member_trips WHERE id=?',[$id])->fetch();if(!$r||(int)$r['revision']!==$revision)throw new DomainException('conflict');
  if($action==='approve'){if(!$r['pending_plan'])throw new DomainException('invalid');$np=json_decode($r['pending_plan'],true);foreach($np['items'] as &$stop){if(networkItemForLanguage($stop['id'],$np['language']??'he'))$stop['custom']=false;}unset($stop);networkValidateRoute($np);socialQuery('UPDATE member_trips SET pending_plan=? WHERE id=?',[json_encode($np,JSON_UNESCAPED_UNICODE),$id]);networkApproveLocale('trip',$id,$np['language']??'he',$np,(string)$admin);socialQuery("UPDATE member_trips SET public_plan=pending_plan,pending_plan=NULL,state='published',revision=revision+1 WHERE id=?",[$id]);}
  elseif($action==='reject')socialQuery("UPDATE member_trips SET pending_plan=NULL,state=CASE WHEN public_plan IS NULL THEN 'private' ELSE 'published' END,revision=revision+1 WHERE id=?",[$id]);
  elseif($action==='block')socialQuery("UPDATE member_trips SET state='blocked',public_plan=NULL,pending_plan=NULL,shared_plan=NULL,revision=revision+1 WHERE id=?",[$id]);else throw new DomainException('invalid');
 }elseif($kind==='member'){
  if(!in_array($action,['block','restore'],true))throw new DomainException('invalid');socialQuery('UPDATE members SET state=?,session_version=session_version+1 WHERE id=? AND state!="deleted"',[$action==='block'?'blocked':'active',$id]);
 }elseif($kind==='report'&&$action==='resolve')socialQuery("UPDATE member_reports SET state='resolved' WHERE id=?",[$id]);else throw new DomainException('invalid');
 if($kind==='trip'&&$action==='block')socialQuery("UPDATE network_localizations SET state='hidden' WHERE kind='trip' AND target=?",[$id]);
 socialQuery('INSERT INTO member_moderation(admin_id,kind,target,action,created) VALUES(?,?,?,?,?)',[$admin,$kind,$id,$action,time()]);socialDb()->commit();
 }catch(Throwable $e){socialDb()->rollBack();throw $e;}
}
