<?php
declare(strict_types=1);
require_once __DIR__.'/network-languages.php';
require_once __DIR__.'/social-composer.php';
require_once __DIR__.'/network-destinations.php';
require_once __DIR__.'/social-audience.php';
// Additive community schema. Public localizations are independent from private plans.
function networkMigrate(PDO $db): void {
 saMigrate($db);
 $db->exec("CREATE TABLE IF NOT EXISTS network_language_preferences(member TEXT PRIMARY KEY REFERENCES members(id),publication_language TEXT NOT NULL,feed_language TEXT NOT NULL)");
 $languageColumns=array_column($db->query('PRAGMA table_info(network_language_preferences)')->fetchAll(PDO::FETCH_ASSOC),'name');
 if(!in_array('interface_language',$languageColumns,true)){
  $db->exec("ALTER TABLE network_language_preferences ADD COLUMN interface_language TEXT NOT NULL DEFAULT ''");
  $db->exec("UPDATE network_language_preferences SET interface_language=publication_language WHERE publication_language IN ('he','en','ru','ka')");
 }
 if(!in_array('feed_custom',$languageColumns,true)){
  $db->exec("ALTER TABLE network_language_preferences ADD COLUMN feed_custom INTEGER NOT NULL DEFAULT 0");
  $db->exec("UPDATE network_language_preferences SET feed_custom=1 WHERE feed_language<>publication_language");
 }
 $db->exec("CREATE TABLE IF NOT EXISTS network_member_flags(member TEXT PRIMARY KEY,audience TEXT NOT NULL); CREATE TABLE IF NOT EXISTS network_event_audience(event_id TEXT PRIMARY KEY,audience TEXT NOT NULL);");
 $db->exec("CREATE TABLE IF NOT EXISTS community_schema(version INTEGER PRIMARY KEY);
 CREATE TABLE IF NOT EXISTS member_profiles(member TEXT PRIMARY KEY REFERENCES members(id),name TEXT NOT NULL DEFAULT '',bio TEXT NOT NULL DEFAULT '',avatar TEXT NOT NULL DEFAULT '',language TEXT NOT NULL DEFAULT 'he',city TEXT NOT NULL DEFAULT '',business INTEGER NOT NULL DEFAULT 0,updated INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS network_posts(id TEXT PRIMARY KEY,owner TEXT NOT NULL REFERENCES members(id),kind TEXT NOT NULL,city TEXT NOT NULL,created INTEGER NOT NULL,state TEXT NOT NULL DEFAULT 'pending');
 CREATE TABLE IF NOT EXISTS network_localizations(kind TEXT NOT NULL,target TEXT NOT NULL,language TEXT NOT NULL,state TEXT NOT NULL,document TEXT NOT NULL,source_version INTEGER NOT NULL DEFAULT 1,updated INTEGER NOT NULL,PRIMARY KEY(kind,target,language));
 CREATE TABLE IF NOT EXISTS network_versions(id INTEGER PRIMARY KEY,kind TEXT NOT NULL,target TEXT NOT NULL,language TEXT NOT NULL,document TEXT NOT NULL,actor TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS network_images(id TEXT PRIMARY KEY,post_id TEXT NOT NULL REFERENCES network_posts(id),owner TEXT NOT NULL REFERENCES members(id));
 CREATE TABLE IF NOT EXISTS network_edges(actor TEXT NOT NULL REFERENCES members(id),kind TEXT NOT NULL,target TEXT NOT NULL,created INTEGER NOT NULL,PRIMARY KEY(actor,kind,target));
 CREATE TABLE IF NOT EXISTS network_comments(id TEXT PRIMARY KEY,owner TEXT NOT NULL REFERENCES members(id),kind TEXT NOT NULL,target TEXT NOT NULL,language TEXT NOT NULL,body TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS network_notices(id INTEGER PRIMARY KEY,member TEXT NOT NULL REFERENCES members(id),kind TEXT NOT NULL,target TEXT NOT NULL,language TEXT NOT NULL,seen INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS network_events(id TEXT PRIMARY KEY,event TEXT NOT NULL,member TEXT,kind TEXT NOT NULL,target TEXT NOT NULL,language TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS network_settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS network_submissions(id TEXT PRIMARY KEY,kind TEXT NOT NULL,target TEXT NOT NULL,language TEXT NOT NULL,owner TEXT NOT NULL REFERENCES members(id),document TEXT NOT NULL,base_version INTEGER NOT NULL,state TEXT NOT NULL DEFAULT 'pending',note TEXT NOT NULL DEFAULT '',created INTEGER NOT NULL);
 CREATE INDEX IF NOT EXISTS network_version_origin ON network_versions(kind,target,id);
 CREATE INDEX IF NOT EXISTS network_locale_feed ON network_localizations(language,state,updated,target);
 CREATE INDEX IF NOT EXISTS network_comment_target ON network_comments(kind,target,language,state,created);
 CREATE INDEX IF NOT EXISTS network_edges_target ON network_edges(kind,target,actor);");
 // Account classification is administered explicitly; biography text is not an account type.
 if(!$db->query('SELECT 1 FROM community_schema WHERE version=2')->fetchColumn()){
  $db->beginTransaction();try{
   $insert=$db->prepare('INSERT OR IGNORE INTO network_localizations VALUES(?,?,?,?,?,?,?)');
   foreach($db->query("SELECT id,public_plan,updated FROM member_trips WHERE public_plan IS NOT NULL AND state!='blocked'") as $r){$p=json_decode($r['public_plan'],true);$insert->execute(['trip',$r['id'],$p['language']??'he','published',$r['public_plan'],1,$r['updated']]);}
   foreach($db->query("SELECT id,document,created FROM member_places WHERE state='approved'") as $r){$p=json_decode($r['document'],true);$insert->execute(['place',$r['id'],$p['language']??'he','published',$r['document'],1,$r['created']]);}
   $db->exec('INSERT INTO community_schema VALUES(2)');$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
 }
 if(!$db->query('SELECT 1 FROM community_schema WHERE version=3')->fetchColumn()){
  $db->beginTransaction();try{
   $rows=$db->query("SELECT l.* FROM network_localizations l WHERE l.state='published' AND NOT EXISTS(SELECT 1 FROM network_versions v WHERE v.id=l.source_version AND v.kind=l.kind AND v.target=l.target AND v.language=l.language)")->fetchAll(PDO::FETCH_ASSOC);
   foreach($rows as $row){$q=$db->prepare('INSERT INTO network_versions(kind,target,language,document,actor,created) VALUES(?,?,?,?,?,?)');$q->execute([$row['kind'],$row['target'],$row['language'],$row['document'],'migration',$row['updated']]);$q=$db->prepare('UPDATE network_localizations SET source_version=? WHERE kind=? AND target=? AND language=?');$q->execute([(int)$db->lastInsertId(),$row['kind'],$row['target'],$row['language']]);}
   $db->exec('INSERT INTO community_schema VALUES(3)');$db->commit();
  }catch(Throwable $e){$db->rollBack();throw $e;}
 }
}
function nt(string $key): string {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/network-labels.json'),true);return $all[$key][siteLanguage()]??uiText($all[$key]['en']??$key);}
function networkLanguage($value): string {if(!networkValidContentLanguage($value))throw new DomainException('invalid');return $value;}
function networkMinutes($v): ?int {if($v===null||$v==='')return null;if(!is_numeric($v)||(int)$v<0||(int)$v>1440)throw new DomainException('invalid');return (int)$v;}
function networkParent($v): string {return is_string($v)&&preg_match('/^[a-f0-9]{32}$/D',$v)?$v:'';}
function networkSource($v): string {$s=socialString($v,1000,1);if(!filter_var($s,FILTER_VALIDATE_URL)||!in_array(parse_url($s,PHP_URL_SCHEME),['http','https'],true)||parse_url($s,PHP_URL_USER))throw new DomainException('invalid');return $s;}
function networkCoordinate($v,int $limit): float {if(!is_numeric($v)||abs((float)$v)>$limit)throw new DomainException('invalid');return (float)$v;}
function networkEnabled(): bool {return socialQuery("SELECT value FROM network_settings WHERE key='publishing'")->fetchColumn()!=='off';}
function networkPublicationAllowed(): void {if(!networkEnabled())throw new DomainException('publication_paused');}
function networkProfile(string $id): array {$p=socialQuery("SELECT m.id,m.handle,p.name,p.bio,p.avatar,p.language,p.city,p.business,COALESCE(d.mode,'name') display_mode FROM members m LEFT JOIN member_profiles p ON p.member=m.id LEFT JOIN social_display_preferences d ON d.member=m.id WHERE m.id=? AND m.state='active'",[$id])->fetch()?:[];if($p){if((socialUser()['id']??'')===$id)$p['editable_name']=$p['name'];if($p['display_mode']==='nickname')$p['name']=$p['handle'];}return $p;}
function networkBlocked(string $a,string $b): bool {return $a!==''&&$b!==''&&(bool)socialQuery("SELECT 1 FROM network_edges WHERE kind='block' AND ((actor=? AND target=?) OR (actor=? AND target=?))",[$a,$b,$b,$a])->fetchColumn();}
function networkLocale(string $kind,string $id,?string $lang=null): ?array {
 $r=socialQuery("SELECT * FROM network_localizations WHERE kind=? AND target=? AND language=? AND state='published'",[$kind,$id,$lang??networkContentLanguage()])->fetch();if($r&&$kind==='place')$r['document']=json_encode(crPlaceFields('community-'.$id,$r['language'],json_decode($r['document'],true)),JSON_UNESCAPED_UNICODE);return $r?:null;
}
function networkTarget(string $kind,string $id): ?array {
 if($kind==='trip')$r=socialQuery("SELECT id,owner FROM member_trips WHERE id=? AND public_plan IS NOT NULL AND state!='blocked'",[$id])->fetch();
 elseif($kind==='place')$r=socialQuery("SELECT id,owner FROM member_places WHERE id=? AND state='approved'",[$id])->fetch();
 elseif($kind==='post')$r=socialQuery("SELECT id,owner FROM network_posts WHERE id=? AND state='published'",[$id])->fetch();
 else return null;
 if($r&&$kind==='trip'){$edition=networkLocale('trip',$id);if(!$edition||!networkRouteVisible(json_decode($edition['document'],true),networkContentLanguage()))return null;}
 if(!$r||!saCanView($kind,$id,$r['owner'])||!networkProfile($r['owner'])||networkBlocked(socialUser()['id']??'',$r['owner'])||!networkLocale($kind,$id))return null;return $r;
}
function networkEvent(string $event,string $kind,string $id,?string $dedupe=null): void {
 if(!in_array($event,['route_view','route_save','route_clone','route_publish','share_intent','feed_view','reaction','follow','comment','empty_feed','visit_report'],true))return;
 if(preg_match('/bot|crawl|spider|preview/i',$_SERVER['HTTP_USER_AGENT']??''))return;
 $key=$dedupe?hash('sha256',$event.':'.session_id().':'.$dedupe):bin2hex(random_bytes(16));
 socialQuery('INSERT OR IGNORE INTO network_events VALUES(?,?,?,?,?,?,?)',[$key,$event,socialUser()['id']??null,$kind,$id,siteLanguage(),time()]);
 $member=socialUser()['id']??'';
 $audience=!empty($_SESSION['user'])?'admin':(APP_ENV==='local'?'qa':($member?'member':'unclassified'));
 socialQuery('INSERT OR IGNORE INTO network_event_audience VALUES(?,?)',[$key,$audience]);
}
function networkNotice(string $member,string $kind,string $target,string $language): void {socialQuery('INSERT INTO network_notices(member,kind,target,language,created) VALUES(?,?,?,?,?)',[$member,$kind,$target,$language,time()]);}
function networkProfileSave(array $u): void {
 socialLimit('profile',20,3600);$name=socialString($_POST['name']??'',60,1);$bio=socialString($_POST['bio']??'',400);$lang=networkLanguage($_POST['publication_language']??$_POST['content_language']??networkPublicationDefault());$city=socialString($_POST['city']??'',100);if($city&&!isset(tripDestinations()[$city]))throw new DomainException('invalid');
 $old=networkProfile($u['id']);$avatar=$old['avatar']??'';$images=[];
 if(isset($_FILES['photo'])&&($_FILES['photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$images=socialUploadImages(bin2hex(random_bytes(16)),1);$avatar=$images[0]??$avatar;}
 if(!empty($_POST['remove_avatar']))$avatar='';
 try{socialDb()->beginTransaction();if(isset($_POST['publication_language'])){rxSaveUnit($u,$_POST);networkSaveLanguagePreferences($u,$_POST);}socialQuery('INSERT INTO member_profiles VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(member) DO UPDATE SET name=excluded.name,bio=excluded.bio,avatar=excluded.avatar,language=excluded.language,city=excluded.city,business=excluded.business,updated=excluded.updated',[$u['id'],$name,$bio,$avatar,$lang,$city,empty($_POST['business'])?0:1,time()]);
 socialQuery('INSERT INTO social_display_preferences VALUES(?,?) ON CONFLICT(member) DO UPDATE SET mode=excluded.mode',[$u['id'],($_POST['display_mode']??'name')==='nickname'?'nickname':'name']);
 socialDb()->commit();}
 catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();socialDiscardImages($images);throw $e;}
 if(($old['avatar']??'')&&$old['avatar']!==$avatar)socialDiscardImages([$old['avatar']]);
}
function networkPostSave(array $u): string {
 // Legacy endpoint supports text/story shapes only. Collections and routes use scSubmit.
 // Existing guide/update records remain readable, but are not extra authoring types.
 networkPublicationAllowed();socialLimit('post',15,86400);$kind=socialString($_POST['post_kind']??'',20);if(!in_array($kind,['experience','question'],true))throw new DomainException('invalid');
 $lang=networkLanguage($_POST['content_language']??'');$city=socialString($_POST['city']??'',100);if(!($kind==='question'&&$city==='')&&!isset(tripDestinations()[$city]))throw new DomainException('invalid');
 $p=['title'=>socialString($_POST['title']??'',160,3),'body'=>socialString($_POST['body']??'',12000,10),'language'=>$lang,'city'=>$city,'kind'=>$kind,'source'=>socialString($_POST['source']??'',1000),'visited_at'=>socialString($_POST['visited_at']??'',10),'commercial'=>!empty($_POST['commercial'])];
 if($p['visited_at']&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$p['visited_at'])||$p['visited_at']>gmdate('Y-m-d')))throw new DomainException('invalid');
 if(empty($_POST['rights']))throw new DomainException('rights_required');$id=bin2hex(random_bytes(16));$images=socialUploadImages(bin2hex(random_bytes(16)));$p['photos']=$images;
 try{socialDb()->beginTransaction();crQuota($u['id'],'post');socialQuery('INSERT INTO network_posts VALUES(?,?,?,?,?,?)',[$id,$u['id'],$kind,$city,time(),'pending']);socialQuery('INSERT INTO network_localizations VALUES(?,?,?,?,?,?,?)',['post',$id,$lang,'pending',json_encode($p,JSON_UNESCAPED_UNICODE),1,time()]);foreach($images as $im)socialQuery('INSERT INTO network_images VALUES(?,?,?)',[$im,$id,$u['id']]);saStore('post',$id,$u['id'],saInput($_POST,$u['id']));networkApproveLocale('post',$id,$lang,$p,'community:'.$u['id']);socialQuery("UPDATE network_posts SET state='published' WHERE id=?",[$id]);socialDb()->commit();}
 catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();socialDiscardImages($images);throw $e;}return $id;
}
function networkEdge(array $u,string $edge,string $kind,string $target,bool $on): void {
 socialLimit('edge',180,3600);$key=$kind.':'.$target;
 if(in_array($edge,['follow','block'],true)){
  if($kind==='member'){if($target===$u['id']||!networkProfile($target))throw new DomainException('invalid');if($edge==='follow'&&networkBlocked($u['id'],$target))throw new DomainException('blocked');$key=$target;}
  elseif($edge==='follow'&&$kind==='city'&&isset(tripDestinations()[$target])){$edge='follow_city';$key=$target;}
  else throw new DomainException('invalid');
 }elseif($edge==='save'&&$kind==='catalog'){if(!isset(scCatalogue()[$target]))throw new DomainException('not_found');}elseif(!in_array($edge,['like','save','hide','less'],true)||!networkTarget($kind,$target))throw new DomainException('not_found');
 if($on)socialQuery('INSERT OR IGNORE INTO network_edges VALUES(?,?,?,?)',[$u['id'],$edge,$key,time()]);else socialQuery('DELETE FROM network_edges WHERE actor=? AND kind=? AND target=?',[$u['id'],$edge,$key]);
 if($edge==='block'&&$on)socialQuery("DELETE FROM network_edges WHERE kind='follow' AND ((actor=? AND target=?) OR (actor=? AND target=?))",[$u['id'],$target,$target,$u['id']]);
 if($edge==='block'&&$on)socialQuery('DELETE FROM social_friendships WHERE (a=? AND b=?) OR (a=? AND b=?)',[$u['id'],$target,$target,$u['id']]);
 if($on&&$edge==='save'&&$kind==='trip')networkEvent('route_save',$kind,$target);
}
function networkComment(array $u): void {
 networkPublicationAllowed();socialLimit('comment',30,3600);scSchema();sxSchema();$kind=(string)($_POST['kind']??'');$target=(string)($_POST['target']??'');$r=networkTarget($kind,$target);if(!$r)throw new DomainException('not_found');
 $placeIds=is_array($_POST['linked_places']??null)?$_POST['linked_places']:[];if(count($placeIds)>revAttachmentLimits()['place'])throw new DomainException('too_many_linked_places');$places=scLinked($placeIds);$routes=is_array($_POST['linked_routes']??null)?$_POST['linked_routes']:[];if(count($routes)>revAttachmentLimits()['trip'])throw new DomainException('too_many_linked_routes');
 foreach($routes as $route)if(!is_string($route)||!revAttachableRoute($route))throw new DomainException('route_unavailable');
 $token=socialString($_POST['comment_token']??'',64,16);if(socialQuery('SELECT 1 FROM social_publication_requests WHERE owner=? AND token=?',[$u['id'],$token])->fetchColumn())return;
 $parent=socialString($_POST['parent_comment']??'',32);$parentRow=$parent?revReplyParent($kind,$target,$parent,$u):null;if($parent&&!$parentRow)throw new DomainException('reply_unavailable');
 $photos=[];if(!empty($_FILES['photo']['name'][0])){if(empty($_POST['rights']))throw new DomainException('rights_required');$photos=socialUploadImages(bin2hex(random_bytes(16)));}
 // Comments belong to the original publication, independently of their text or UI language.
 // Keep the existing column as thread metadata; never label it as the text's detected language.
 $body=socialString($_POST['body']??'',2000,3);$language=(string)(socialQuery('SELECT language FROM network_versions WHERE kind=? AND target=? ORDER BY id LIMIT 1',[$kind,$target])->fetchColumn()?:networkLocale($kind,$target)['language']);$id=bin2hex(random_bytes(16));
 socialDb()->beginTransaction();try{if(socialQuery('SELECT 1 FROM social_publication_requests WHERE owner=? AND token=?',[$u['id'],$token])->fetchColumn()){socialDb()->rollBack();socialDiscardImages($photos);return;}crQuota($u['id'],'comment');socialQuery('INSERT INTO network_comments VALUES(?,?,?,?,?,?,?,?)',[$id,$u['id'],$kind,$target,$language,$body,'published',time()]);socialQuery('INSERT INTO social_comment_links VALUES(?,?)',[$id,json_encode(['places'=>array_column($places,'id'),'routes'=>$routes,'photos'=>$photos],JSON_UNESCAPED_UNICODE)]);socialQuery('INSERT INTO social_publication_requests VALUES(?,?,?)',[$u['id'],$token,$id]);if($parent)socialQuery('INSERT INTO social_comment_parents VALUES(?,?)',[$id,$parent]);if($places||$routes)sxEvent('reply_attachment_added',$u['id'],$token);$recipients=array_unique(array_filter([$r['owner'],$parentRow['owner']??'']));foreach($recipients as $recipient)if($recipient!==$u['id'])networkNotice($recipient,'reply',$kind.':'.$target.':'.$id,$language);socialDb()->commit();}catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();socialDiscardImages($photos);throw $e;}
}
function networkFeed(array $filter=[]): array {
 tsSchema();
 $actor=socialUser()['id']??'';$lang=$filter['language']??networkFeedLanguage();if($lang!=='all')networkLanguage($lang);$city=$filter['city']??'';$tab=$filter['tab']??'for_you';$owner=$filter['owner']??'';$type=$filter['type']??'';$q=mb_substr((string)($filter['q']??''),0,100);$budget=in_array($filter['budget']??'',['free','paid'],true)?$filter['budget']:'';$minutes=max(0,min(1440,(int)($filter['minutes']??0)));
 $country=is_string($filter['country']??null)?$filter['country']:'';$countryCities=$country!==''?networkCountryCities($country):[];
 $countryClause=$country!==''?($countryCities?" AND (json_extract(l.document,'$.country')=? OR COALESCE(json_extract(l.document,'$.city'),p.city,'') IN (".implode(',',array_fill(0,count($countryCities),'?')).") OR EXISTS(SELECT 1 FROM json_each(l.document,'$.destinations') WHERE value IN (".implode(',',array_fill(0,count($countryCities),'?')).")))":" AND json_extract(l.document,'$.country')=?") :'';
 $fingerprint=hash('sha256',json_encode([$lang,$city,$country,$tab,$owner,$type,$q,$budget,$minutes,$actor]));$cut=(string)($filter['cursor']??'');$before=PHP_INT_MAX;$last='';
 if($cut){$c=json_decode(base64_decode($cut,true)?:'',true);if(is_array($c)&&($c['f']??'')===$fingerprint){$before=(int)$c['t'];$last=(string)$c['id'];}}
 $audienceSql=saSql('l.kind','l.target','m.id',$actor);
 // Catalogue additions are owner-only feed activity; authored Place posts use kind=post.
 // Apply before pagination. Catalogue access and publication audience rules are unchanged.
 $viewer=socialDb()->quote($actor);
 $placeActivitySql="(l.kind!='place' OR ($viewer!='' AND m.id=$viewer))";
 $sql="SELECT l.*,COALESCE(t.owner,p.owner,pl.owner) owner,m.handle,COALESCE(json_extract(l.document,'$.city'),p.city,'') city FROM network_localizations l
 LEFT JOIN member_trips t ON l.kind='trip' AND t.id=l.target LEFT JOIN network_posts p ON l.kind='post' AND p.id=l.target LEFT JOIN member_places pl ON l.kind='place' AND pl.id=l.target
 JOIN members m ON m.id=COALESCE(t.owner,p.owner,pl.owner)
 WHERE $audienceSql AND $placeActivitySql AND (l.kind!='trip' OR NOT EXISTS(SELECT 1 FROM trip_shares dup WHERE dup.trip=t.id AND dup.owner=t.owner AND dup.visibility='public' AND dup.revoked=0)) AND (?='all' OR l.language=?) AND l.state='published'
 AND l.language=COALESCE((SELECT v.language FROM network_versions v WHERE v.kind=l.kind AND v.target=l.target ORDER BY v.id LIMIT 1),(SELECT o.language FROM network_localizations o WHERE o.kind=l.kind AND o.target=l.target ORDER BY o.updated,o.language LIMIT 1)) AND m.state='active'
 AND ((l.kind='trip' AND t.public_plan IS NOT NULL AND t.state!='blocked') OR (l.kind='post' AND p.state='published') OR (l.kind='place' AND pl.state='approved'))
 AND (?='' OR COALESCE(json_extract(l.document,'$.city'),p.city,'')=? OR EXISTS(SELECT 1 FROM json_each(l.document,'$.destinations') WHERE value=?)) AND (?='' OR m.id=?)
 AND (?='' OR l.kind=? OR json_extract(l.document,'$.kind')=?)
 AND (?='' OR instr(unicode_lower(COALESCE(json_extract(l.document,'$.title'),json_extract(l.document,'$.public_title'),'')||' '||COALESCE(json_extract(l.document,'$.body'),json_extract(l.document,'$.summary'),json_extract(l.document,'$.description'),'')),unicode_lower(?))>0)
 AND (?='' OR json_extract(l.document,'$.cost')=?) AND (CAST(? AS INTEGER)=0 OR (json_extract(l.document,'$.total_minutes') IS NOT NULL AND json_extract(l.document,'$.total_minutes')<=CAST(? AS INTEGER)))
 AND NOT EXISTS(SELECT 1 FROM network_edges e WHERE e.kind='block' AND ((e.actor=? AND e.target=m.id) OR (e.actor=m.id AND e.target=?)))
 AND NOT EXISTS(SELECT 1 FROM network_edges e WHERE e.actor=? AND e.kind IN ('hide','less') AND e.target=l.kind||':'||l.target)
 AND (?!='following' OR EXISTS(SELECT 1 FROM network_edges e WHERE e.actor=? AND ((e.kind='follow' AND e.target=m.id) OR(e.kind='follow_city' AND (e.target=COALESCE(json_extract(l.document,'$.city'),p.city,'') OR EXISTS(SELECT 1 FROM json_each(l.document,'$.destinations') WHERE value=e.target))))))
 AND (?!='saved' OR EXISTS(SELECT 1 FROM network_edges e WHERE e.actor=? AND e.kind='save' AND e.target=l.kind||':'||l.target))
 AND (l.updated<? OR(l.updated=? AND l.kind||':'||l.target<?)) $countryClause ORDER BY l.updated DESC,l.kind||':'||l.target DESC LIMIT 7";
 if(str_starts_with($q,'#')){$sql=str_replace("instr(unicode_lower(COALESCE(json_extract(l.document,'$.title'),json_extract(l.document,'$.public_title'),'')||' '||COALESCE(json_extract(l.document,'$.body'),json_extract(l.document,'$.summary'),json_extract(l.document,'$.description'),'')),unicode_lower(?))>0","social_hashtag(COALESCE(json_extract(l.document,'$.title'),json_extract(l.document,'$.public_title'),'')||' '||COALESCE(json_extract(l.document,'$.body'),json_extract(l.document,'$.summary'),json_extract(l.document,'$.description'),''),?)=1",$sql);}
 $rows=socialQuery($sql,[$lang,$lang,$city,$city,$city,$owner,$owner,$type,$type,$type,$q,$q,$budget,$budget,$minutes,$minutes,$actor,$actor,$actor,$tab,$actor,$tab,$actor,$before,$before,$last?:'~',...($country!==''?[$country,...$countryCities,...$countryCities]:[])])->fetchAll();$rows=array_merge($rows,tsFeedRows($filter,$before,$last,$lang));usort($rows,fn($a,$b)=>((int)$b['updated']<=>(int)$a['updated'])?:strcmp($b['kind'].':'.$b['target'],$a['kind'].':'.$a['target']));$more=count($rows)>6;$rows=array_slice($rows,0,6);$end=end($rows);$rows=array_values(array_filter($rows,fn($r)=>$r['kind']!=='trip'||networkRouteVisible(json_decode($r['document'],true),$r['language'])));
 return ['rows'=>$rows,'next'=>$more&&$end?base64_encode(json_encode(['f'=>$fingerprint,'t'=>$end['updated'],'id'=>$end['kind'].':'.$end['target']])):''];
}
function networkApproveLocale(string $kind,string $id,string $lang,array $doc,string $actor): void {
 // Called inside the moderation transaction. Immutable archive precedes the live pointer.
 $json=json_encode($doc,JSON_UNESCAPED_UNICODE);socialQuery('INSERT INTO network_versions(kind,target,language,document,actor,created) VALUES(?,?,?,?,?,?)',[$kind,$id,$lang,$json,$actor,time()]);$version=(int)socialDb()->lastInsertId();
 socialQuery("INSERT INTO network_localizations VALUES(?,?,?,'published',?,?,?) ON CONFLICT(kind,target,language) DO UPDATE SET state='published',document=excluded.document,source_version=excluded.source_version,updated=excluded.updated",[$kind,$id,$lang,$json,$version,time()]);
 $table=['post'=>'network_posts','trip'=>'member_trips','place'=>'member_places'][$kind]??'';if($table){$owner=socialQuery("SELECT owner FROM $table WHERE id=?",[$id])->fetchColumn();if($owner)saMentionNotices($kind,$id,$owner,($doc['title']??$doc['public_title']??'').' '.($doc['body']??$doc['summary']??$doc['description']??''),$lang);}
}
function networkModerate(string $kind,string $id,string $action): void {
 requireAdmin();if(!in_array($action,['approve','reject','block','resolve'],true))throw new DomainException('invalid');socialDb()->beginTransaction();try{networkReviewGuard($kind,$id,$action);
 if($kind==='post'){$p=socialQuery('SELECT * FROM network_posts WHERE id=?',[$id])->fetch();if(!$p||$p['state']==='removed'||!networkProfile($p['owner']))throw new DomainException('not_found');if(in_array($action,['approve','reject'],true)&&$p['state']!=='pending')throw new DomainException('conflict');$l=socialQuery('SELECT * FROM network_localizations WHERE kind=? AND target=?',['post',$id])->fetch();if(!$l)throw new DomainException('not_found');if($action==='approve')networkApproveLocale('post',$id,$l['language'],json_decode($l['document'],true),(string)$_SESSION['user']);else socialQuery('UPDATE network_localizations SET state=? WHERE kind=? AND target=?',[$action==='block'?'blocked':'rejected','post',$id]);socialQuery('UPDATE network_posts SET state=? WHERE id=?',[$action==='approve'?'published':($action==='block'?'blocked':'rejected'),$id]);networkNotice($p['owner'],$action,$id,$l['language']);}
 elseif($kind==='comment'){$r=socialQuery('SELECT * FROM network_comments WHERE id=?',[$id])->fetch();if(!$r)throw new DomainException('not_found');if(in_array($action,['approve','reject'],true)&&$r['state']!=='pending')throw new DomainException('conflict');socialQuery('UPDATE network_comments SET state=? WHERE id=?',[$action==='approve'?'published':'removed',$id]);if($action==='approve'){$table=['post'=>'network_posts','trip'=>'member_trips','place'=>'member_places'][$r['kind']]??'';$target=$table?socialQuery("SELECT owner FROM $table WHERE id=?",[$r['target']])->fetch():null;if($target)saMentionNotices($r['kind'],$r['target'],$target['owner'],$r['body'],$r['language'],'comment:'.$r['id'],$r['owner']);if($target&&$target['owner']!==$r['owner'])networkNotice($target['owner'],'reply',$r['kind'].':'.$r['target'],$r['language']);}}
 elseif($kind==='translation'){
  $s=socialQuery("SELECT * FROM network_submissions WHERE id=? AND state='pending'",[$id])->fetch();if(!$s)throw new DomainException('conflict');$version=(int)(networkLocale($s['kind'],$s['target'],$s['language'])['source_version']??0);if($version!==(int)$s['base_version'])throw new DomainException('conflict');
  if($action==='approve'){if(!networkProfile($s['owner']))throw new DomainException('not_found');$targetRow=networkOwnTarget(['id'=>$s['owner']],$s['kind'],$s['target']);if($s['kind']==='place'&&$targetRow['state']!=='approved')throw new DomainException('unapproved_stop');if($s['kind']==='trip'&&empty($targetRow['public_plan']))throw new DomainException('unapproved_stop');$translated=json_decode($s['document'],true);if($s['kind']==='trip')networkValidateRoute($translated);networkApproveLocale($s['kind'],$s['target'],$s['language'],$translated,(string)$_SESSION['user']);if($s['kind']==='post')socialQuery("UPDATE network_posts SET state='published' WHERE id=?",[$s['target']]);}
  socialQuery('UPDATE network_submissions SET state=?,note=? WHERE id=?',[$action==='approve'?'approved':'rejected',socialString($_POST['moderator_note']??'',500),$id]);networkNotice($s['owner'],$action,$s['target'],$s['language']);
 }else throw new DomainException('invalid');
 socialQuery('INSERT INTO member_moderation(admin_id,kind,target,action,created) VALUES(?,?,?,?,?)',[(int)$_SESSION['user'],$kind,$id,$action,time()]);socialDb()->commit();
 }catch(Throwable $e){socialDb()->rollBack();throw $e;}
}
function networkAvatar(array $p,string $class=''): string {$label=$p['name']?:($p['handle']??'Tripovela');return '<span class="network-avatar '.e($class).'">'.(!empty($p['avatar'])&&crPhotoAllowed($p['avatar'],($p['id']??'')===(socialUser()['id']??''))&&is_file(qaPhotoPath($p['avatar']))?'<img src="/community/avatar/'.e($p['id']).'/?v='.e($p['avatar']).'" alt="'.e($label).'" width="48" height="48">':'<span aria-hidden="true">'.e(mb_strtoupper(mb_substr($label,0,1))).'</span>').'</span>';}
function networkUrl(string $kind,string $id): string {return languageUrl('/community/'.(['trip'=>'trips','place'=>'places','post'=>'posts'][$kind]??'posts').'/'.$id.'/?content_lang='.rawurlencode(networkContentLanguage()),siteLanguage());}
function networkPublicLanguages(string $kind,string $id): array {
 $table=['trip'=>'member_trips','place'=>'member_places','post'=>'network_posts'][$kind]??null;if(!$table)return [];
 $row=socialQuery("SELECT t.* FROM $table t JOIN members m ON m.id=t.owner WHERE t.id=? AND m.state='active'",[$id])->fetch();
 if(!$row||!saCanView($kind,$id,$row['owner'])||networkBlocked(socialUser()['id']??'',$row['owner'])||($kind==='trip'?empty($row['public_plan'])||$row['state']==='blocked':!in_array($row['state'],['approved','published'],true)))return [];
 $languages=[];foreach(socialQuery("SELECT language,document FROM network_localizations WHERE kind=? AND target=? AND state='published'",[$kind,$id]) as $locale)if($kind!=='trip'||networkRouteVisible(json_decode($locale['document'],true),$locale['language']))$languages[]=$locale['language'];return $languages;
}
function networkUnavailable(array $languages=[]): void {http_response_code(404);echo '<section class="network-empty"><h1>'.e(nt('unavailable')).'</h1><p>'.e(nt($languages?'missing_edition':'no_fallback')).'</p>';foreach($languages as $lang)if(isset(networkContentLanguages()[$lang])){$parts=parse_url($_SERVER['REQUEST_URI']);parse_str($parts['query']??'',$query);$query['content_lang']=$lang;echo '<a class="button secondary" hreflang="'.e($lang).'" href="'.e(languageUrl($parts['path'].'?'.http_build_query($query),siteLanguage())).'">'.e(networkContentLanguages()[$lang]).'</a>';}echo '<a href="'.e(languageUrl('/community/',siteLanguage())).'">'.e(nt('home')).'</a></section>';}
function networkDeleteAccount(array $u): void {
 $id=$u['id'];$images=socialQuery('SELECT i.id FROM member_images i JOIN member_places p ON p.id=i.place_id WHERE p.owner=?',[$id])->fetchAll(PDO::FETCH_COLUMN);
 $images=array_merge($images,socialQuery('SELECT i.id FROM member_trip_images i JOIN member_trips t ON t.id=i.trip_id WHERE t.owner=?',[$id])->fetchAll(PDO::FETCH_COLUMN),socialQuery('SELECT id FROM network_images WHERE owner=?',[$id])->fetchAll(PDO::FETCH_COLUMN));$images=array_merge($images,socialQuery('SELECT i.id FROM place_contribution_images i JOIN place_contributions c ON c.id=i.contribution WHERE c.owner=?',[$id])->fetchAll(PDO::FETCH_COLUMN));$avatar=networkProfile($id)['avatar']??'';if($avatar)$images[]=$avatar;
 socialDb()->beginTransaction();try{
  journeyEraseMember($id);socialQuery('DELETE FROM route_unit_preferences WHERE member=?',[$id]);socialQuery('DELETE FROM community_place_origins WHERE owner=?',[$id]);
  sxSchema();socialQuery('DELETE FROM social_trip_actions WHERE owner=?',[$id]);socialQuery('DELETE FROM social_flow_events WHERE actor=?',[$id]);socialQuery('DELETE FROM social_continuations WHERE author=? OR post IN(SELECT id FROM network_posts WHERE owner=?)',[$id,$id]);socialQuery('DELETE FROM social_continuation_notices WHERE recipient=? OR post IN(SELECT id FROM network_posts WHERE owner=?)',[$id,$id]);
  socialQuery('DELETE FROM place_contribution_votes WHERE voter=? OR contribution IN(SELECT id FROM place_contributions WHERE owner=?)',[$id,$id]);socialQuery('DELETE FROM place_contribution_images WHERE contribution IN(SELECT id FROM place_contributions WHERE owner=?)',[$id]);socialQuery('DELETE FROM place_contributions WHERE owner=?',[$id]);socialQuery('DELETE FROM place_write_requests WHERE owner=?',[$id]);
  socialQuery('DELETE FROM social_recipients WHERE member=? OR EXISTS(SELECT 1 FROM social_audiences a WHERE a.kind=social_recipients.kind AND a.target=social_recipients.target AND a.owner=?)',[$id,$id]);
  socialQuery('DELETE FROM social_mentions WHERE member=? OR EXISTS(SELECT 1 FROM social_audiences a WHERE a.kind=social_mentions.kind AND a.target=social_mentions.target AND a.owner=?)',[$id,$id]);
  mrSchema();socialQuery('DELETE FROM member_email_identity WHERE member=?',[$id]);socialQuery('DELETE FROM social_audiences WHERE owner=?',[$id]);socialQuery('DELETE FROM social_friendships WHERE a=? OR b=?',[$id,$id]);socialQuery('DELETE FROM social_display_preferences WHERE member=?',[$id]);
  foreach(['trip'=>['member_trips','owner'],'place'=>['member_places','owner'],'post'=>['network_posts','owner']] as $kind=>[$table,$column]){
   foreach(['network_localizations','network_versions'] as $related)socialQuery("DELETE FROM $related WHERE kind=? AND target IN(SELECT id FROM $table WHERE $column=?)",[$kind,$id]);
   socialQuery("UPDATE network_comments SET state='removed',body='' WHERE kind=? AND target IN(SELECT id FROM $table WHERE $column=?)",[$kind,$id]);
  }
  socialQuery('DELETE FROM member_images WHERE place_id IN(SELECT id FROM member_places WHERE owner=?)',[$id]);socialQuery('DELETE FROM member_trip_images WHERE trip_id IN(SELECT id FROM member_trips WHERE owner=?)',[$id]);
  socialQuery("UPDATE network_place_merges SET prior_note='',reversal_note='',locale_states='[]' WHERE source IN(SELECT id FROM member_places WHERE owner=?)",[$id]);
  seSchema();socialQuery('DELETE FROM social_edit_drafts WHERE owner=?',[$id]);
  foreach(['network_appeals'=>'owner','network_submissions'=>'owner','network_images'=>'owner','network_posts'=>'owner','member_places'=>'owner','member_trips'=>'owner','network_language_preferences'=>'member','member_profiles'=>'member','network_edges'=>'actor','network_notices'=>'member','member_ratings'=>'voter'] as $table=>$col)socialQuery("DELETE FROM $table WHERE $col=?",[$id]);
  socialQuery("UPDATE network_comments SET body='',state='removed' WHERE owner=?",[$id]);socialQuery("UPDATE member_reports SET reason='[removed by account owner]' WHERE reporter=?",[$id]);socialQuery('UPDATE network_events SET member=NULL WHERE member=?',[$id]);
  socialQuery("UPDATE members SET state='deleted',handle=?,password='',recovery='',session_version=session_version+1 WHERE id=?",['deleted_'.substr($id,0,20),$id]);socialDb()->commit();
 }catch(Throwable $e){socialDb()->rollBack();throw $e;}socialDiscardImages($images);
}
function networkOwnTarget(array $u,string $kind,string $id): array {
 $table=['post'=>'network_posts','trip'=>'member_trips','place'=>'member_places'][$kind]??null;if(!$table)throw new DomainException('invalid');$row=socialQuery("SELECT * FROM $table WHERE id=? AND owner=?",[$id,$u['id']])->fetch();if(!$row||in_array($row['state'],['blocked','removed'],true))throw new DomainException('not_found');return $row;
}
function networkSubmitTranslation(array $u): void {
 networkPublicationAllowed();socialLimit('translation',20,86400);$kind=(string)($_POST['kind']??'');$id=(string)($_POST['target']??'');$row=networkOwnTarget($u,$kind,$id);$lang=networkLanguage($_POST['content_language']??'');
 $source=socialQuery("SELECT * FROM network_localizations WHERE kind=? AND target=? ORDER BY updated DESC LIMIT 1",[$kind,$id])->fetch();if(!$source)throw new DomainException('not_found');$doc=json_decode($source['document'],true);
 $doc['language']=$lang;$doc[$kind==='trip'?'public_title':'title']=socialString($_POST['title']??'',160,3);$doc[$kind==='post'?'body':($kind==='trip'?'summary':'description')]=socialString($_POST['body']??'',12000,20);
 if($kind==='trip'){
  // A route localization must resolve every stop in that language before submission.
  $old=$_GET['lang']??null;$_GET['lang']=$lang;
  try{foreach($doc['items'] as &$stop){if(!empty($stop['custom']))throw new DomainException('unapproved_stop');$locale=networkItemForLanguage($stop['id'],$lang);if(!$locale)throw new DomainException('unapproved_stop');$stop['title']=$locale;}unset($stop);}finally{if($old===null)unset($_GET['lang']);else $_GET['lang']=$old;}
 }elseif($kind==='place'){foreach(['address','hours','price'] as $key)$doc[$key]=socialString($_POST[$key]??'',1000);}
 $version=(int)(networkLocale($kind,$id,$lang)['source_version']??0);if((int)($_POST['base_version']??0)!==$version)throw new DomainException('conflict');
 socialQuery('INSERT INTO network_submissions(id,kind,target,language,owner,document,base_version,created) VALUES(?,?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$kind,$id,$lang,$u['id'],json_encode($doc,JSON_UNESCAPED_UNICODE),$version,time()]);cmAutoApprove();
}
function networkItemForLanguage(string $id,string $lang): ?string {
 $old=$_GET['lang']??null;$_GET['lang']=$lang;try{return networkItemForLanguageResolved($id,$lang);}finally{if($old===null)unset($_GET['lang']);else $_GET['lang']=$old;}
}
function networkRouteVisible(array $doc,string $lang): bool {
 if(($doc['language']??'he')!==$lang)return false;$catalogLanguage=$doc['catalog_language']??$lang;foreach($doc['items']??[] as $stop)if(!empty($stop['custom'])||!networkItemForLanguage((string)$stop['id'],$catalogLanguage))return false;return !empty($doc['items']);
}
function networkItemForLanguageResolved(string $id,string $lang): ?string {
 if(str_starts_with($id,'community-')){$place=networkPlaceAlias(substr($id,10));if(!socialQuery("SELECT 1 FROM member_places p JOIN members m ON m.id=p.owner WHERE p.id=? AND p.state='approved' AND m.state='active'",[$place])->fetchColumn())return null;$l=networkLocale('place',$place,$lang)?:socialQuery("SELECT * FROM network_localizations WHERE kind='place' AND target=? AND state='published' ORDER BY updated LIMIT 1",[$place])->fetch();return $l?(json_decode($l['document'],true)['title']??null):null;}
 $doc=editorialDocuments()[$id]??editorialDocuments()['item-'.$id]??null;if($doc&&!array_filter($doc['context']['provider_bindings']??[],fn($b)=>($b['provider']??'')==='viator')){$tr=$doc['editorial']['translations'][$lang]??null;return catalogItemTranslationAvailable($id,$tr)?$tr['title']:null;}
 $code=null;foreach(array_keys(array_replace(providerCatalog(),activityMetadata(),json_decode(file_get_contents(ROOT.'/content/activity-details.json'),true))) as $candidate)if(in_array($id,['provider-'.strtolower($candidate),'offer-'.strtolower($candidate)],true)){$code=(string)$candidate;break;}
 if(!$code)foreach(experienceCatalog() as $entry)if(($entry['id']??'')===$id)$code=$entry['product_code'];
 if($code)return providerText($code,'title')&&providerText($code,'description')?providerText($code,'title'):null;
 foreach(json_decode(file_get_contents(ROOT.'/content/places.json'),true) as $place)if($place['id']===$id&&in_array($lang,['he','en','ru'],true))return $place['titles'][['he'=>0,'en'=>1,'ru'=>2][$lang]]??null;
 return null;
}
function networkValidateRoute(array $doc): void {
 $old=$_GET['lang']??null;$_GET['lang']=networkLanguage($doc['catalog_language']??$doc['language']??'he');
 try{if(empty($doc['items']))throw new DomainException('unapproved_stop');foreach($doc['items'] as $stop)if(!empty($stop['custom'])||!networkItemForLanguage((string)$stop['id'],$_GET['lang']))throw new DomainException('unapproved_stop');}
 finally{if($old===null)unset($_GET['lang']);else $_GET['lang']=$old;}
}
