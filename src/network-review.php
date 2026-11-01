<?php
declare(strict_types=1);
// Moderation corrections retain the same place identity and all previous audit rows.
function networkReviewMigrate(PDO $db): void {
 $db->exec("CREATE TABLE IF NOT EXISTS moderation_versions(id INTEGER PRIMARY KEY,admin_id INTEGER NOT NULL,kind TEXT NOT NULL,target TEXT NOT NULL,action TEXT NOT NULL,version TEXT NOT NULL,note TEXT NOT NULL,created INTEGER NOT NULL);");
 $db->exec("CREATE TABLE IF NOT EXISTS network_place_aliases(source TEXT PRIMARY KEY,target TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS network_appeals(id INTEGER PRIMARY KEY,owner TEXT NOT NULL,kind TEXT NOT NULL,target TEXT NOT NULL,body TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',decision TEXT NOT NULL DEFAULT '',created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS network_place_merges(id INTEGER PRIMARY KEY,source TEXT NOT NULL,target TEXT NOT NULL,prior_state TEXT NOT NULL,prior_note TEXT NOT NULL,locale_states TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'active',admin_id INTEGER NOT NULL,created INTEGER NOT NULL,reversal_note TEXT NOT NULL DEFAULT '');");
}
function networkReviewVersion(string $kind,string $id): string {
 $table=['place'=>'member_places','post'=>'network_posts','comment'=>'network_comments','translation'=>'network_submissions','trip'=>'member_trips','report'=>'member_reports','member'=>'members','appeal'=>'network_appeals'][$kind]??null;
 if(!$table)return '';
 $row=socialQuery("SELECT * FROM $table WHERE id=?",[$id])->fetch();
 if(!$row)return '';
 unset($row['password'],$row['recovery']);
 if($kind==='post')$row['locales']=socialQuery("SELECT * FROM network_localizations WHERE kind='post' AND target=? ORDER BY language",[$id])->fetchAll();
 return hash('sha256',json_encode($row));
}
function networkReviewGuard(string $kind,string $id,string $action): void {
 // Check inside the same transaction as the state transition. Old browser forms fail closed.
 if(PHP_SAPI!=='cli'&&$kind!=='settings'){
  if($action==='reject')socialString($_POST['moderator_note']??'',500,10);
  $version=networkReviewVersion($kind,$id);
  if(!$version||!hash_equals($version,(string)($_POST['expected_version']??'')))throw new DomainException('conflict');
  socialQuery('INSERT INTO moderation_versions(admin_id,kind,target,action,version,note,created) VALUES(?,?,?,?,?,?,?)',[(int)$_SESSION['user'],$kind,$id,$action,$version,socialString($_POST['moderator_note']??'',500),time()]);
 }
}
function networkPlaceAlias(string $id): string {
 $seen=[];for($i=0;$i<10;$i++){if(isset($seen[$id]))return ''; $seen[$id]=true;$next=socialQuery('SELECT target FROM network_place_aliases WHERE source=?',[$id])->fetchColumn();if(!$next)return $id;$id=$next;}return '';
}
function networkCanonicalItem(string $id): string {return str_starts_with($id,'community-')?'community-'.networkPlaceAlias(substr($id,10)):$id;}
function networkResolvePlan(array $plan): array {foreach($plan['items']??[] as $index=>$item)$plan['items'][$index]['id']=networkCanonicalItem($item['id']);foreach($plan['connections']??[] as $i=>$leg){$plan['connections'][$i]['from']=networkCanonicalItem($leg['from']);$plan['connections'][$i]['to']=networkCanonicalItem($leg['to']);}return $plan;}
function networkDuplicates(array $p,string $exclude=''): array {
 $out=[];$name=mb_strtolower(trim($p['title']??''));$source=rtrim($p['source_url']??'','/');
 foreach(socialQuery("SELECT id,document,state FROM member_places WHERE id!=? AND json_extract(document,'$.city')=? AND state NOT IN ('blocked','merged')",[$exclude,$p['city']??'']) as $r){$d=json_decode($r['document'],true);$same=$name!==''&&mb_strtolower(trim($d['title']))===$name;$near=isset($p['latitude'],$p['longitude'],$d['latitude'],$d['longitude'])&&abs($p['latitude']-$d['latitude'])<0.001&&abs($p['longitude']-$d['longitude'])<0.001;$url=$source!==''&&rtrim($d['source_url']??'','/')===$source;if($same||$near||$url)$out[]=['id'=>$r['id'],'title'=>$d['title'],'state'=>$r['state']];if(count($out)>=10)break;}
 return $out;
}
function networkAppeal(array $u): void {
 socialLimit('appeal',5,86400);$kind=(string)($_POST['kind']??'');$id=(string)($_POST['target']??'');$table=['place'=>'member_places','post'=>'network_posts','trip'=>'member_trips'][$kind]??null;
 if(!$table||!socialQuery("SELECT 1 FROM $table WHERE id=? AND owner=?",[$id,$u['id']])->fetchColumn())throw new DomainException('not_found');
 if(socialQuery("SELECT 1 FROM network_appeals WHERE owner=? AND kind=? AND target=? AND state='pending'",[$u['id'],$kind,$id])->fetchColumn())throw new DomainException('conflict');
 socialQuery('INSERT INTO network_appeals(owner,kind,target,body,created) VALUES(?,?,?,?,?)',[$u['id'],$kind,$id,socialString($_POST['body']??'',2000,20),time()]);
}
function networkReviewAction(string $kind,string $id,string $action): void {
 requireAdmin();$admin=(int)$_SESSION['user'];socialDb()->beginTransaction();try{networkReviewGuard($kind,$id,$action);
 if($kind==='settings'){
  if(!in_array($action,['pause','resume'],true))throw new DomainException('invalid');socialQuery("INSERT INTO network_settings VALUES('publishing',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[$action==='pause'?'off':'on']);
 }elseif($kind==='appeal'){
  if(!in_array($action,['resolve','reject'],true))throw new DomainException('invalid');$note=socialString($_POST['moderator_note']??'',500,10);socialQuery("UPDATE network_appeals SET state=?,decision=? WHERE id=? AND state='pending'",[$action==='resolve'?'resolved':'rejected',$note,$id]);
 }elseif($kind==='place'&&$action==='needs_info'){
  $note=socialString($_POST['moderator_note']??'',500,10);socialQuery("UPDATE member_places SET state='needs_info',moderator_note=? WHERE id=? AND state IN ('pending','rejected','needs_info')",[$note,$id]);
 }elseif($kind==='place'&&$action==='merge'){
  $destination=networkPlaceAlias((string)($_POST['merge_target']??''));$source=socialQuery('SELECT * FROM member_places WHERE id=?',[$id])->fetch();$target=socialQuery("SELECT * FROM member_places WHERE id=? AND state='approved'",[$destination])->fetch();
  if(!$source||!$target||$destination===$id||$source['state']==='merged'||networkPlaceAlias($id)!==$id)throw new DomainException('invalid');$a=json_decode($source['document'],true);$b=json_decode($target['document'],true);if($a['city']!==$b['city'])throw new DomainException('invalid');
  $locales=socialQuery("SELECT language,state FROM network_localizations WHERE kind='place' AND target=?",[$id])->fetchAll();
  socialQuery('INSERT INTO network_place_merges(source,target,prior_state,prior_note,locale_states,admin_id,created) VALUES(?,?,?,?,?,?,?)',[$id,$destination,$source['state'],$source['moderator_note']??'',json_encode($locales,JSON_UNESCAPED_UNICODE),$admin,time()]);
  socialQuery('INSERT INTO network_place_aliases VALUES(?,?,?) ON CONFLICT(source) DO UPDATE SET target=excluded.target,created=excluded.created',[$id,$destination,time()]);socialQuery("UPDATE member_places SET state='merged',moderator_note=? WHERE id=?",[socialString($_POST['moderator_note']??'',500),$id]);socialQuery("UPDATE network_localizations SET state='hidden' WHERE kind='place' AND target=?",[$id]);
 }elseif($kind==='place'&&$action==='unmerge'){
  $merge=socialQuery("SELECT * FROM network_place_merges WHERE source=? AND state='active' ORDER BY id DESC LIMIT 1",[$id])->fetch();
  $source=socialQuery('SELECT p.*,m.state owner_state FROM member_places p JOIN members m ON m.id=p.owner WHERE p.id=?',[$id])->fetch();
  $alias=socialQuery('SELECT target FROM network_place_aliases WHERE source=?',[$id])->fetchColumn();
  if(!$merge||!$source||$source['state']!=='merged'||$alias!==$merge['target'])throw new DomainException('conflict');
  $note=socialString($_POST['moderator_note']??'',500,10);
  socialQuery('DELETE FROM network_place_aliases WHERE source=?',[$id]);
  $active=$source['owner_state']==='active';
  socialQuery('UPDATE member_places SET state=?,moderator_note=? WHERE id=?',[$active?$merge['prior_state']:'blocked',$merge['prior_note'],$id]);
  foreach(json_decode($merge['locale_states'],true) as $locale)socialQuery("UPDATE network_localizations SET state=? WHERE kind='place' AND target=? AND language=?",[$active?$locale['state']:'hidden',$id,$locale['language']]);
  socialQuery("UPDATE network_place_merges SET state='reverted',reversal_note=? WHERE id=?",[$note,$merge['id']]);
 }else throw new DomainException('invalid');
 socialQuery('INSERT INTO member_moderation(admin_id,kind,target,action,created) VALUES(?,?,?,?,?)',[$admin,$kind,$id,$action,time()]);socialDb()->commit();
 }catch(Throwable $e){socialDb()->rollBack();throw $e;}
}
function networkPlaceReviewControls(array $r,array $p): void {
 if(in_array($r['state'],['rejected','needs_info'],true)){
  echo '<details><summary>'.e(nt('resubmit')).'</summary><form method="post" action="'.e(languageUrl('/community/contribute/',siteLanguage())).'" class="network-form">'.csrf().'<input type="hidden" name="action" value="contribute"><input type="hidden" name="place_id" value="'.e($r['id']).'">';
  foreach(['city','category','language','cost'] as $key)echo '<input type="hidden" name="'.($key==='language'?'content_language':$key).'" value="'.e($p[$key]).'">';
  foreach(['title'=>180,'description'=>6000,'address'=>500,'hours'=>1000,'price'=>200,'source_url'=>1000,'latitude'=>25,'longitude'=>25] as $key=>$limit)echo '<label>'.e($key==='source_url'?nt('source'):socialT($key)).'<textarea name="'.e($key).'" maxlength="'.$limit.'" '.(in_array($key,['title','description','source_url','latitude','longitude'],true)?'required':'').'>'.e((string)($p[$key]??'')).'</textarea></label>';
  placeOfferForm($p);
  echo '<label class="social-check"><input type="checkbox" name="rights" required>'.e(socialT('rights')).'</label><button>'.e(nt('resubmit')).'</button></form></details>';
 }
 if(in_array($r['state'],['rejected','blocked','needs_info'],true))echo '<details><summary>'.e(nt('appeal')).'</summary><form method="post">'.csrf().'<input type="hidden" name="network_action" value="appeal"><input type="hidden" name="kind" value="place"><input type="hidden" name="target" value="'.e($r['id']).'"><label>'.e(nt('body')).'<textarea name="body" minlength="20" maxlength="2000" required></textarea></label><button>'.e(nt('appeal')).'</button></form></details>';
 foreach(socialQuery('SELECT state,decision FROM network_appeals WHERE owner=? AND kind=? AND target=? ORDER BY created DESC',[$r['owner'],'place',$r['id']]) as $a)echo '<p>'.e(nt('appeal').' · '.socialT($a['state']).' · '.$a['decision']).'</p>';
}
