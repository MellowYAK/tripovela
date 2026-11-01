<?php
// Launch review: separate proposals, explicit capabilities, and immutable decisions.
function crMigrate(PDO $db): void {
 $db->exec("CREATE TABLE IF NOT EXISTS community_permissions(member TEXT PRIMARY KEY,reviewer INTEGER NOT NULL DEFAULT 0,photos INTEGER NOT NULL DEFAULT 0);
 CREATE TABLE IF NOT EXISTS community_photo_review(id TEXT PRIMARY KEY,owner TEXT NOT NULL,state TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS community_corrections(id TEXT PRIMARY KEY,place TEXT NOT NULL,owner TEXT NOT NULL,language TEXT NOT NULL,field TEXT NOT NULL,previous TEXT NOT NULL,value TEXT NOT NULL,explanation TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',created INTEGER NOT NULL,reviewer TEXT NOT NULL DEFAULT '',note TEXT NOT NULL DEFAULT '');
 CREATE TABLE IF NOT EXISTS community_place_fields(place TEXT NOT NULL,language TEXT NOT NULL,field TEXT NOT NULL,value TEXT NOT NULL,proposal TEXT NOT NULL,PRIMARY KEY(place,language,field));
 CREATE TABLE IF NOT EXISTS community_decisions(id INTEGER PRIMARY KEY,actor TEXT NOT NULL,kind TEXT NOT NULL,target TEXT NOT NULL,action TEXT NOT NULL,before_value TEXT NOT NULL,after_value TEXT NOT NULL,reason TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS community_hides(member TEXT NOT NULL,kind TEXT NOT NULL,target TEXT NOT NULL,PRIMARY KEY(member,kind,target));
 CREATE TABLE IF NOT EXISTS community_reports(id INTEGER PRIMARY KEY,owner TEXT NOT NULL,kind TEXT NOT NULL,target TEXT NOT NULL,reason TEXT NOT NULL,body TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',created INTEGER NOT NULL,UNIQUE(owner,kind,target));
 CREATE TABLE IF NOT EXISTS community_usage(owner TEXT NOT NULL,kind TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE INDEX IF NOT EXISTS community_usage_owner ON community_usage(owner,kind,created);
 CREATE INDEX IF NOT EXISTS community_correction_place ON community_corrections(place,created);");
 if(!in_array('base_version',array_column($db->query('PRAGMA table_info(community_corrections)')->fetchAll(),'name'),true))$db->exec("ALTER TABLE community_corrections ADD COLUMN base_version TEXT NOT NULL DEFAULT ''");
}
function crT(string $key): string {static $l;$l??=json_decode(file_get_contents(ROOT.'/content/community-review-labels.json'),true);return $l[siteLanguage()][$key]??$l['en'][$key]??$key;}
function crAdmin(): bool {if(empty($_SESSION['user']))return false;requireAdmin();return true;}
function crCapability(string $cap,?string $member=null): bool {if(!in_array($cap,['reviewer','photos'],true))return false;$member??=socialUser()['id']??'';return (bool)socialQuery("SELECT p.$cap FROM community_permissions p JOIN members m ON m.id=p.member WHERE p.member=? AND m.state='active'",[$member])->fetchColumn();}
function crActor(): string {if(crAdmin())return 'admin:'.(int)$_SESSION['user'];$u=socialRequire();if(!crCapability('reviewer',$u['id']))throw new DomainException('not_found');return $u['id'];}
function crAudit(string $actor,string $kind,string $id,string $action,$before,$after,string $reason): void {socialQuery('INSERT INTO community_decisions(actor,kind,target,action,before_value,after_value,reason,created) VALUES(?,?,?,?,?,?,?,?)',[$actor,$kind,$id,$action,json_encode($before,JSON_UNESCAPED_UNICODE),json_encode($after,JSON_UNESCAPED_UNICODE),$reason,time()]);}
function crQuota(string $owner,string $kind,int $amount=1): void {
 if(socialContentLimitsPaused())return;
 $defaults=['post'=>30,'correction'=>5,'photo'=>150,'comment'=>10,'place_contribution'=>60];$limit=(int)(socialQuery('SELECT value FROM network_settings WHERE key=?',['launch_limit_'.$kind])->fetchColumn()?:$defaults[$kind]);
 $own=!socialDb()->inTransaction();if($own)socialDb()->beginTransaction();try{$n=(int)socialQuery('SELECT COUNT(*) FROM community_usage WHERE owner=? AND kind=? AND created>=?',[$owner,$kind,strtotime('today UTC')])->fetchColumn();if($n+$amount>$limit)throw new DomainException('rate_limit');for($i=0;$i<$amount;$i++)socialQuery('INSERT INTO community_usage VALUES(?,?,?)',[$owner,$kind,time()]);if($own)socialDb()->commit();}catch(Throwable $e){if($own&&socialDb()->inTransaction())socialDb()->rollBack();throw $e;}
}
function crPhotoRegister(array $ids,string $owner): void {if(!$ids)return;$trusted=cmAutoEnabled()||crCapability('photos',$owner);if(!$trusted)crQuota($owner,'photo',count($ids));foreach($ids as $id)socialQuery('INSERT INTO community_photo_review VALUES(?,?,?,?)',[$id,$owner,$trusted?'approved':'pending',time()]);}
function crPhotoSubmitted(string $id): bool {
 // A private draft/upload is not a request for a community reviewer to inspect it.
 scSchema();return (bool)socialQuery("SELECT 1 FROM network_images WHERE id=? UNION SELECT 1 FROM member_images WHERE id=? UNION SELECT 1 FROM place_contribution_images WHERE id=? UNION SELECT 1 FROM member_profiles WHERE avatar=? UNION SELECT 1 FROM member_trip_images i JOIN member_trips t ON t.id=i.trip_id WHERE i.id=? AND (t.public_plan IS NOT NULL OR t.pending_plan IS NOT NULL OR t.shared_plan IS NOT NULL) UNION SELECT 1 FROM social_comment_links l JOIN network_comments c ON c.id=l.comment WHERE EXISTS(SELECT 1 FROM json_each(l.document,'$.photos') WHERE value=?) LIMIT 1",[$id,$id,$id,$id,$id,$id])->fetchColumn();
}
function crPhotoAllowed(string $id,bool $private=false): bool {
 $r=socialQuery('SELECT * FROM community_photo_review WHERE id=?',[$id])->fetch();if(!$r)return true;
 if($r['state']==='approved')return !crHidden('photo',$id);
 return $private&&(crAdmin()||(crCapability('reviewer')&&crPhotoSubmitted($id))||($r['owner']===(socialUser()['id']??'')));
}
function crPhotos(array $ids,bool $private=false): array {return array_values(array_filter($ids,fn($id)=>is_string($id)&&crPhotoAllowed($id,$private)));}
function crPhotoNotice(array $ids): void {foreach($ids as $id){$r=socialQuery("SELECT owner,state FROM community_photo_review WHERE id=?",[$id])->fetch();if($r&&$r['owner']===(socialUser()['id']??'')&&$r['state']!=='approved'){echo '<p role="status">'.e(crT($r['state']==='pending'?'photo_pending':$r['state'])).'</p>';break;}}}
function crDocument(array $doc,bool $private=false): array {$doc['photos']=crPhotos($doc['photos']??array_filter([$doc['photo']??'']),$private);if(isset($doc['photo'])&&!crPhotoAllowed($doc['photo']))$doc['photo']=$doc['photos'][0]??'';return $doc;}
function crHidden(string $kind,string $id): bool {return (bool)socialQuery('SELECT 1 FROM community_hides WHERE member=? AND kind=? AND target=?',[socialUser()['id']??'',$kind,$id])->fetchColumn();}
function crOwnStatus(array $u): void {
 if(crCapability('reviewer',$u['id']))echo '<p><a href="'.e(languageUrl('/community/review/',siteLanguage())).'">'.e(crT('review')).'</a></p>';
 echo '<details id="community-review-status" open class="social-card"><summary>'.e(crT('community')).'</summary>';
 foreach(socialQuery("SELECT d.* FROM network_notices n JOIN community_decisions d ON CAST(d.id AS TEXT)=n.target WHERE n.member=? AND n.kind='review_update' ORDER BY n.id DESC LIMIT 30",[$u['id']]) as $decision){$target=crReviewTarget($decision);echo '<p><a href="'.e($target['url']).'">'.e($target['title']).'</a> · '.e(crT(['approve'=>'approved','reject'=>'rejected','needs_info'=>'needs_info'][$decision['action']]??'pending')).' · '.e($decision['reason']).'</p>';}
 foreach(socialQuery('SELECT * FROM community_corrections WHERE owner=? ORDER BY created DESC LIMIT 50',[$u['id']]) as $r)echo '<p>'.e(cpT($r['field']).' · '.crT($r['state']).' · '.$r['note']).'</p>';
 foreach(socialQuery("SELECT * FROM community_photo_review WHERE owner=? AND state!='discarded' ORDER BY created DESC LIMIT 50",[$u['id']]) as $r){$note=socialQuery("SELECT reason FROM community_decisions WHERE kind='photo' AND target=? ORDER BY id DESC LIMIT 1",[$r['id']])->fetchColumn();echo '<figure><img src="/community/media/'.e($r['id']).'/" alt="" width="120" loading="lazy"><figcaption>'.e(crT($r['state']==='pending'?'photo_pending':$r['state']).($note?' · '.$note:'')).'</figcaption></figure>';}
 echo '</details>';
}
function crPlaceFields(string $place,string $language,array $doc): array {foreach(socialQuery('SELECT field,value FROM community_place_fields WHERE place=? AND language=?',[cpCanonical($place),$language]) as $f){$v=json_decode($f['value'],true);if($f['field']==='location'){[$doc['latitude'],$doc['longitude']]=$v;}else $doc[$f['field']]=$v;}return $doc;}
function crPendingPlaces(array $u): array {$out=[];foreach(socialQuery("SELECT id,document FROM member_places WHERE owner=? AND state IN ('pending','needs_info')",[$u['id']]) as $r){$p=json_decode($r['document'],true);$id='community-'.$r['id'];$out[$id]=['id'=>$id,'title'=>$p['title'],'city'=>$p['city']??'','country'=>$p['country']??'','image'=>'','cost'=>'unknown','pending'=>true,'staged'=>!empty($p['staged']),'staged_version'=>hash('sha256',$r['document']),'staged_document'=>!empty($p['staged'])?$p:null,'location'=>['id'=>$id,'title'=>$p['title'],'address'=>$p['address']??'','coords'=>isset($p['latitude'],$p['longitude'])?[$p['latitude'],$p['longitude']]:null],'url'=>''];}return $out;}
function crDraftPlaces(array $u,array $ids): array {if(count($ids)>30)throw new DomainException('invalid');$catalog=scCatalogue()+crPendingPlaces($u);$out=[];foreach($ids as $id){if(!is_string($id)||!isset($catalog[$id]))throw new DomainException('not_found');$out[$id]=$catalog[$id];}return array_values($out);}
function crPlaceBase(string $place,string $language): array {
 $place=cpCanonical($place);if(str_starts_with($place,'community-')){$r=socialQuery("SELECT l.document FROM network_localizations l JOIN member_places p ON p.id=l.target WHERE l.kind='place' AND l.target=? AND l.language=? AND l.state='published' AND p.state='approved'",[substr($place,10),$language])->fetchColumn();if(!$r)throw new DomainException('not_found');$doc=json_decode($r,true);}
 else{$doc=scCatalogue()[$place]??null;if(!$doc)throw new DomainException('not_found');$doc['description']=$doc['summary']??'';$loc=rxLocation($doc);$doc['address']=$loc['address'];$doc['latitude']=$loc['coords'][0]??null;$doc['longitude']=$loc['coords'][1]??null;}
 return crPlaceFields($place,$language,$doc);
}
function crValue(array $doc,string $field) {return $field==='location'?[$doc['latitude']??null,$doc['longitude']??null]:($doc[$field]??'');}
function crFieldVersion(string $place,string $lang,string $field,$value): string {return hash('sha256',json_encode([$value,socialQuery('SELECT proposal FROM community_place_fields WHERE place=? AND language=? AND field=?',[$place,$lang,$field])->fetchColumn()]));}
function crPropose(array $u,array $raw): string {
 networkPublicationAllowed();$place=cpCanonical(socialString($raw['place']??'',95,1));$lang=networkLanguage($raw['language']??networkContentLanguage());$field=$raw['field']??'';if(!in_array($field,['title','address','location','hours','price','description'],true))throw new DomainException('invalid');$doc=crPlaceBase($place,$lang);
 $value=$field==='location'?[networkCoordinate($raw['latitude']??'',90),networkCoordinate($raw['longitude']??'',180)]:socialString($raw['value']??'',3000,1);$why=socialString($raw['explanation']??'',2000,3);$previous=crValue($doc,$field);
 if(json_encode($previous)===json_encode($value))throw new DomainException('invalid');$id=bin2hex(random_bytes(16));socialDb()->beginTransaction();try{crQuota($u['id'],'correction');socialQuery('INSERT INTO community_corrections(id,place,owner,language,field,previous,value,explanation,created,base_version) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$place,$u['id'],$lang,$field,json_encode($previous,JSON_UNESCAPED_UNICODE),json_encode($value,JSON_UNESCAPED_UNICODE),$why,time(),crFieldVersion($place,$lang,$field,$previous)]);socialDb()->commit();cmAutoApprove();return $id;}catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();throw $e;}
}
function crCorrectionDecision(string $id,string $action,string $reason): void {
 $actor=crActor();socialDb()->beginTransaction();try{$r=socialQuery('SELECT * FROM community_corrections WHERE id=?',[$id])->fetch();if(!$r||(!crAdmin()&&($r['owner']===$actor||$r['owner']===(socialUser()['id']??''))))throw new DomainException('not_found');if(in_array($r['field'],['location','address','description'],true)&&!crAdmin())throw new DomainException('not_found');
 if(!in_array($action,['approve','reject','needs_info','restore','hide','unhide'],true))throw new DomainException('invalid');$state=['approve'=>'approved','reject'=>'rejected','needs_info'=>'needs_info','restore'=>'restored','hide'=>'hidden','unhide'=>'pending'][$action];
 if($action==='approve'||$action==='restore'){$doc=crPlaceBase($r['place'],$r['language']);$current=crValue($doc,$r['field']);$expected=json_decode($action==='restore'?$r['value']:$r['previous'],true);$versionOk=$action==='restore'?socialQuery('SELECT proposal FROM community_place_fields WHERE place=? AND language=? AND field=?',[$r['place'],$r['language'],$r['field']])->fetchColumn()===$id:hash_equals($r['base_version'],crFieldVersion($r['place'],$r['language'],$r['field'],$current));if(!in_array($r['state'],$action==='restore'?['approved']:['pending','needs_info'],true)||$current!==$expected||!$versionOk)throw new DomainException('conflict');$value=$action==='restore'?$r['previous']:$r['value'];socialQuery('INSERT INTO community_place_fields VALUES(?,?,?,?,?) ON CONFLICT(place,language,field) DO UPDATE SET value=excluded.value,proposal=excluded.proposal',[$r['place'],$r['language'],$r['field'],$value,$id]);}
 elseif($action==='unhide'){$old=socialQuery("SELECT before_value FROM community_decisions WHERE kind='correction' AND target=? AND action='hide' ORDER BY id DESC LIMIT 1",[$id])->fetchColumn();if($r['state']!=='hidden'||!$old)throw new DomainException('conflict');$state=json_decode($old,true);}elseif($action==='hide'){if($r['state']==='hidden')throw new DomainException('conflict');}elseif(!in_array($r['state'],['pending','needs_info'],true))throw new DomainException('conflict');
 socialQuery('UPDATE community_corrections SET state=?,reviewer=?,note=? WHERE id=?',[$state,$actor,$reason,$id]);crAudit($actor,'correction',$id,$action,$r['state'],$state,$reason);crNotifyReview($r['owner'],'correction',$id,$action);socialDb()->commit();scCatalogue(true);}catch(Throwable $e){socialDb()->rollBack();throw $e;}
}
function crPhotoDecision(string $id,string $action,string $reason): void {
 $actor=crActor();if(!crAdmin()&&!crPhotoSubmitted($id))throw new DomainException('not_found');if(!in_array($action,['approve','reject','needs_info','hide','restore'],true))throw new DomainException('invalid');socialDb()->beginTransaction();try{$r=socialQuery('SELECT * FROM community_photo_review WHERE id=?',[$id])->fetch();if(!$r||(!crAdmin()&&($r['owner']===$actor||$r['owner']===(socialUser()['id']??''))))throw new DomainException('not_found');$state=['approve'=>'approved','reject'=>'rejected','needs_info'=>'needs_info','hide'=>'hidden','restore'=>'approved'][$action];if($action==='approve'&&!in_array($r['state'],['pending','needs_info'],true)||$action==='restore'&&$r['state']!=='hidden')throw new DomainException('conflict');socialQuery('UPDATE community_photo_review SET state=? WHERE id=?',[$state,$id]);crAudit($actor,'photo',$id,$action,$r['state'],$state,$reason);crNotifyReview($r['owner'],'photo',$id,$action);socialDb()->commit();}catch(Throwable $e){socialDb()->rollBack();throw $e;}
}
function crReportPhotoOwner(string $id): ?string {
 if(!crPhotoAllowed($id)||!is_file(qaPhotoPath($id)))return null;
 foreach(socialQuery("SELECT 'post' kind,post_id target,owner FROM network_images WHERE id=? UNION SELECT 'trip',t.id,t.owner FROM member_trip_images i JOIN member_trips t ON t.id=i.trip_id WHERE i.id=? UNION SELECT 'place',p.id,p.owner FROM member_images i JOIN member_places p ON p.id=i.place_id WHERE i.id=?",[$id,$id,$id]) as $r)if(networkTarget($r['kind'],$r['target']))return $r['owner'];
 if(cpMediaAllowed($id))return socialQuery('SELECT c.owner FROM place_contribution_images i JOIN place_contributions c ON c.id=i.contribution WHERE i.id=?',[$id])->fetchColumn()?:null;
 $r=socialQuery('SELECT c.* FROM social_comment_links l JOIN network_comments c ON c.id=l.comment WHERE c.state=\'published\' AND EXISTS(SELECT 1 FROM json_each(l.document,\'$.photos\') WHERE value=?)',[$id])->fetch();if($r&&networkTarget($r['kind'],$r['target']))return $r['owner'];return null;
}
function crReport(array $u,array $raw): void {
 $kind=$raw['kind']??'';$id=socialString($raw['target']??'',95,1);$reason=$raw['reason']??'';if(!in_array($reason,['inappropriate','spam','incorrect','privacy','other'],true))throw new DomainException('invalid');
 $exists=false;$photoOwner=null;
 if(in_array($kind,['post','trip','place'],true))$exists=networkTarget($kind,$id);
 elseif($kind==='comment'){$c=socialQuery("SELECT * FROM network_comments WHERE id=? AND state='published'",[$id])->fetch();$exists=$c&&!networkBlocked($u['id'],$c['owner'])&&networkTarget($c['kind'],$c['target']);}
 elseif($kind==='contribution'){$c=socialQuery("SELECT * FROM place_contributions WHERE id=? AND state='published'",[$id])->fetch();$exists=$c&&!networkBlocked($u['id'],$c['owner'])&&isset(scCatalogue()[cpCanonical($c['place'])]);}
 elseif($kind==='correction'){$c=socialQuery("SELECT * FROM community_corrections WHERE id=? AND state!='hidden'",[$id])->fetch();$exists=$c&&!networkBlocked($u['id'],$c['owner'])&&isset(scCatalogue()[cpCanonical($c['place'])]);}
 elseif($kind==='photo')$exists=$photoOwner=crReportPhotoOwner($id);
 if(!$exists)throw new DomainException('not_found');
 if($photoOwner)socialQuery("INSERT OR IGNORE INTO community_photo_review VALUES(?,?,'approved',?)",[$id,$photoOwner,time()]);
 socialQuery('INSERT OR IGNORE INTO community_reports(owner,kind,target,reason,body,created) VALUES(?,?,?,?,?,?)',[$u['id'],$kind,$id,$reason,socialString($raw['body']??'',1000),time()]);if(!empty($raw['hide']))socialQuery('INSERT OR IGNORE INTO community_hides VALUES(?,?,?)',[$u['id'],$kind,$id]);
}
function crContentDecision(string $kind,string $id,string $action,string $reason): void {
 $actor=crActor();if(!in_array($action,['hide','restore'],true)||strlen($reason)<3)throw new DomainException('invalid');
 $table=['post'=>'network_posts','trip'=>'member_trips','place'=>'member_places','comment'=>'network_comments','contribution'=>'place_contributions'][$kind]??null;if(!$table)throw new DomainException('invalid');if($kind==='place'&&!crAdmin())throw new DomainException('not_found');
 socialDb()->beginTransaction();try{$row=socialQuery("SELECT state FROM $table WHERE id=?",[$id])->fetch();if(!$row)throw new DomainException('not_found');
 if($action==='hide'){$next='blocked';if($row['state']===$next)throw new DomainException('conflict');}
 else{$audit=socialQuery("SELECT * FROM community_decisions WHERE kind=? AND target=? AND action='hide' ORDER BY id DESC LIMIT 1",[$kind,$id])->fetch();if(!$audit||$row['state']!=='blocked')throw new DomainException('conflict');$next=json_decode($audit['before_value'],true);}
 socialQuery("UPDATE $table SET state=? WHERE id=?",[$next,$id]);crAudit($actor,$kind,$id,$action,$row['state'],$next,$reason);socialDb()->commit();}catch(Throwable $e){socialDb()->rollBack();throw $e;}
}

function crNotifyReview(string $owner,string $kind,string $target,string $action): void {
 if(!in_array($action,['approve','reject','needs_info'],true))return;
 $decision=socialQuery('SELECT id FROM community_decisions WHERE kind=? AND target=? ORDER BY id DESC LIMIT 1',[$kind,$target])->fetchColumn();
 if($decision)networkNotice($owner,'review_update',(string)$decision,networkProfile($owner)['language']??'en');
}
function crReviewTarget(array $decision): array {
 $place='';
 if($decision['kind']==='place_submission')$place=socialQuery('SELECT place FROM place_contributions WHERE id=?',[$decision['target']])->fetchColumn();
 elseif($decision['kind']==='correction')$place=socialQuery('SELECT place FROM community_corrections WHERE id=?',[$decision['target']])->fetchColumn();
 elseif($decision['kind']==='photo')$place=socialQuery('SELECT c.place FROM place_contribution_images i JOIN place_contributions c ON c.id=i.contribution WHERE i.id=?',[$decision['target']])->fetchColumn();
 $p=$place?(scCatalogue()[cpCanonical($place)]??null):null;
 return ['title'=>$p['title']??'', 'url'=>$p['url']??languageUrl('/community/profile/',siteLanguage()).'#community-review-status'];
}
