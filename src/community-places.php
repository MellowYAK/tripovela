<?php
require_once __DIR__.'/community-review.php';
function cpMigrate(PDO $db): void {
 crMigrate($db);
 $db->exec("CREATE TABLE IF NOT EXISTS community_place_origins(place TEXT PRIMARY KEY,route TEXT NOT NULL,owner TEXT NOT NULL); CREATE TABLE IF NOT EXISTS route_unit_preferences(member TEXT PRIMARY KEY,unit TEXT NOT NULL); CREATE TABLE IF NOT EXISTS place_contributions(id TEXT PRIMARY KEY,place TEXT NOT NULL,owner TEXT NOT NULL,language TEXT NOT NULL,document TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'published',created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS place_contribution_votes(contribution TEXT NOT NULL,voter TEXT NOT NULL,aspect TEXT NOT NULL,PRIMARY KEY(contribution,voter,aspect));
 CREATE TABLE IF NOT EXISTS place_contribution_images(id TEXT PRIMARY KEY,contribution TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS place_write_requests(owner TEXT NOT NULL,token TEXT NOT NULL,place TEXT NOT NULL,PRIMARY KEY(owner,token));
 CREATE INDEX IF NOT EXISTS place_contributions_place ON place_contributions(place,state,created);");
}
function cpLabels(): array {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/community-place-labels.json'),true);return array_replace($all['en'],$all[siteLanguage()]??[],['auto_review'=>cmAutoEnabled(),'photo_source'=>photoLabels()[2],'photo_license'=>photoLabels()[3]]);}
function cpT(string $key): string {return cpLabels()[$key]??$key;}
function cpCanonical(string $id): string {return str_starts_with($id,'community-')?'community-'.networkPlaceAlias(substr($id,10)):$id;}
function cpContributions(string $id): array {
 $id=cpCanonical($id);$actor=socialUser()['id']??'';
 $rows=socialQuery("SELECT c.*,m.handle,(SELECT COUNT(*) FROM place_contribution_votes v JOIN members vm ON vm.id=v.voter WHERE v.contribution=c.id AND v.aspect='details' AND vm.state='active') detail_votes,(SELECT COUNT(*) FROM place_contribution_votes v JOIN members vm ON vm.id=v.voter WHERE v.contribution=c.id AND v.aspect='photo' AND vm.state='active') photo_votes,(SELECT COUNT(*) FROM place_contribution_votes v JOIN members vm ON vm.id=v.voter WHERE v.contribution=c.id AND v.aspect='current' AND vm.state='active') current_votes FROM place_contributions c JOIN members m ON m.id=c.owner WHERE c.state='published' AND m.state='active' AND NOT EXISTS(SELECT 1 FROM network_edges e WHERE e.kind='block' AND ((e.actor=? AND e.target=c.owner) OR(e.target=? AND e.actor=c.owner))) ORDER BY c.created DESC,c.id DESC",[$actor,$actor])->fetchAll();
 return array_values(array_filter($rows,fn($r)=>cpCanonical($r['place'])===$id&&!crHidden('contribution',$r['id'])));
}
function cpEnrich(array $p): array {
 $rows=cpContributions($p['id']);$owners=[];foreach($rows as $r)$owners[$r['owner']]=true;
 if(str_starts_with($p['id'],'community-')){$owner=socialQuery("SELECT owner FROM member_places WHERE id=? AND state='approved'",[substr(cpCanonical($p['id']),10)])->fetchColumn();if($owner)$owners[$owner]=true;}
 $p['contributors']=count($owners);$p['community_details']=[];return $p;
}
function cpSearch(string $q): array {
 $q=mb_strtolower(trim(mb_substr($q,0,100)));if(mb_strlen($q)<2)return [];$tokens=preg_split('/[\s,،]+/u',$q,-1,PREG_SPLIT_NO_EMPTY);$out=[];
 foreach(scCatalogue() as $p){if(!empty(discoveryClassify($p)['product'])||str_starts_with($p['url']??'','/activities/'))continue;$hay=uxPlaceSearchText($p);$ok=true;foreach($tokens as $token)if(mb_strpos($hay,$token)===false){$ok=false;break;}if(!$ok)continue;
  $p=cpEnrich($p);$p['location']=rxLocation($p);$p['city_label']=activityLabel(tripDestinations()[$p['city']]['labels']??[$p['city'],$p['city'],$p['city']]).(!empty($p['country'])?' · '.uxCountryName($p['country']):'');$out[]=array_intersect_key($p,array_flip(['id','title','city','country','city_label','image','cost','url','location','contributors','community_details']));if(count($out)>=12)break;
 }return $out;
}
function cpSave(array $u,array $raw): string {
 networkPublicationAllowed();socialLimit('place_contribution',120,3600);$token=socialString($raw['token']??'',64,16);
 if($done=socialQuery('SELECT place FROM place_write_requests WHERE owner=? AND token=?',[$u['id'],$token])->fetchColumn())return $done;
 $id=cpCanonical(socialString($raw['place']??'',95));$staged=$id?(crPendingPlaces($u)[$id]??null):null;if(empty($staged['staged']))$staged=null;$existing=$id?(scCatalogue()[$id]??$staged):null;if($id&&!$existing)throw new DomainException('not_found');
 $baseId=socialString($raw['base_version']??'',32);$base=null;if($baseId){foreach(cpContributions($id) as $candidate)if($candidate['id']===$baseId)$base=$candidate;if(!$base)throw new DomainException('not_found');}
 $doc=[];foreach(['title'=>180,'city'=>100,'country'=>2,'address'=>500,'hours'=>1000,'directions'=>1500,'price'=>200,'description'=>3000,'photo_caption'=>1000,'checked_on'=>10] as $key=>$max)$doc[$key]=socialString($raw[$key]??'', $max,$key==='title'&&!$id?1:0);
 $doc['latitude']=trim((string)($raw['latitude']??''))===''?null:networkCoordinate($raw['latitude'],90);$doc['longitude']=trim((string)($raw['longitude']??''))===''?null:networkCoordinate($raw['longitude'],180);if(($doc['latitude']===null)!==($doc['longitude']===null))throw new DomainException('invalid');
 $doc['base_version']=$baseId;if($doc['checked_on']!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$doc['checked_on'])||!strtotime($doc['checked_on'])||$doc['checked_on']>gmdate('Y-m-d')))throw new DomainException('invalid');
 $doc['country']=strtoupper($doc['country']);if($doc['country']!==''&&!preg_match('/^[A-Z]{2}$/D',$doc['country']))throw new DomainException('invalid');
 $doc['place_type']=in_array($raw['place_type']??'',['parking','trailhead','viewpoint','park','water','museum','food','camp','other'],true)?$raw['place_type']:'other';
 $doc['cost']=in_array($raw['cost']??'',['free','paid','unknown'],true)?$raw['cost']:'unknown';$doc['language']=networkLanguage($raw['content_language']??networkPublicationDefault());$doc+=photoMetadata($raw);
 $photos=[];if(!empty($_FILES['photo']['name'][0])){if(empty($raw['rights']))throw new DomainException('rights_required');$photos=socialUploadImages(bin2hex(random_bytes(16)));}$retained=json_decode($raw['retained_photos']??'[]',true);$baseDoc=$staged?$staged['staged_document']:($base?json_decode($base['document'],true):[]);if(!is_array($retained)||count(array_filter($retained,'is_string'))!==count($retained)||array_diff($retained,$baseDoc['photos']??[])||count($retained)+count($photos)>10){socialDiscardImages($photos);throw new DomainException('invalid');}$doc['photos']=array_values(array_unique(array_merge($retained,$photos)));$doc['photo']=$doc['photos'][0]??'';if($retained){$credits=$baseDoc['inherited_photo_credits']??[];$credits[]=array_intersect_key($baseDoc,array_flip(['photo_author','photo_source','photo_license','photo_illustration']));$doc['inherited_photo_credits']=array_values(array_map(fn($x)=>json_decode($x,true),array_unique(array_map('json_encode',$credits))));}
 socialDb()->beginTransaction();try{
  if($done=socialQuery('SELECT place FROM place_write_requests WHERE owner=? AND token=?',[$u['id'],$token])->fetchColumn()){socialDb()->rollBack();socialDiscardImages($photos);return $done;}
  if($staged){
   $fresh=socialQuery("SELECT document FROM member_places WHERE id=? AND owner=? AND state='pending'",[substr($id,10),$u['id']])->fetchColumn();
   if(!$fresh||!hash_equals(hash('sha256',$fresh),(string)($raw['staged_version']??'')))throw new DomainException('conflict');
   $updated=array_replace(json_decode($fresh,true),$doc);$updated['staged']=true;
   socialQuery('UPDATE member_places SET document=? WHERE id=? AND owner=?',[json_encode($updated,JSON_UNESCAPED_UNICODE),substr($id,10),$u['id']]);
  }
  crQuota($u['id'],'place_contribution');if(!$id){$pid=bin2hex(random_bytes(16));$id='community-'.$pid;$p=$doc+['staged'=>($raw['staged']??'')==='1','created_in_route'=>($raw['created_in_route']??'')==='1','category'=>'city','latitude'=>null,'longitude'=>null,'source_url'=>'','publication_origin'=>'community'];
   socialQuery("INSERT INTO member_places(id,owner,document,state,created) VALUES(?,?,?,'pending',?)",[$pid,$u['id'],json_encode($p,JSON_UNESCAPED_UNICODE),time()]);
   // New submissions publish immediately; existing pending places are untouched.
   if(empty($p['staged'])){networkApproveLocale('place',$pid,$doc['language'],$p,'community:'.$u['id']);socialQuery("UPDATE member_places SET state='approved' WHERE id=?",[$pid]);}
  }
  $cid=bin2hex(random_bytes(16));socialQuery('INSERT INTO place_contributions(id,place,owner,language,document,created) VALUES(?,?,?,?,?,?)',[$cid,$id,$u['id'],$doc['language'],json_encode($doc,JSON_UNESCAPED_UNICODE),time()]);foreach($photos as $photo)socialQuery('INSERT INTO place_contribution_images VALUES(?,?)',[$photo,$cid]);socialQuery('INSERT INTO place_write_requests VALUES(?,?,?)',[$u['id'],$token,$id]);socialDb()->commit();cmAutoApprove();scCatalogue(true);return $id;
 }catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();socialDiscardImages($photos);throw $e;}
}
function cpVote(array $u,string $id,string $aspect): void {
 if(!in_array($aspect,['details','photo','current'],true))throw new DomainException('invalid');socialLimit('place_vote',120,3600);
 $r=socialQuery("SELECT * FROM place_contributions WHERE id=? AND state='published'",[$id])->fetch();if(!$r||$r['owner']===$u['id']||networkBlocked($u['id'],$r['owner'])||!isset(scCatalogue()[cpCanonical($r['place'])]))throw new DomainException('not_found');
 if($aspect==='photo'&&!json_decode($r['document'],true)['photos'])throw new DomainException('invalid');
 socialDb()->beginTransaction();try{foreach(cpContributions($r['place']) as $other)if($other['id']!==$id)socialQuery('DELETE FROM place_contribution_votes WHERE contribution=? AND voter=? AND aspect=?',[$other['id'],$u['id'],$aspect]);socialQuery('INSERT OR IGNORE INTO place_contribution_votes VALUES(?,?,?)',[$id,$u['id'],$aspect]);socialDb()->commit();}catch(Throwable $e){socialDb()->rollBack();throw $e;}
}
function cpMediaAllowed(string $image): bool {
 $r=socialQuery("SELECT c.* FROM place_contribution_images i JOIN place_contributions c ON c.id=i.contribution JOIN members m ON m.id=c.owner WHERE i.id=? AND c.state='published' AND m.state='active'",[$image])->fetch();return $r&&!networkBlocked(socialUser()['id']??'',$r['owner'])&&isset(scCatalogue()[cpCanonical($r['place'])]);
}
function cpRouteFacts(array $raw): array {
 $facts=[];foreach(['difficulty'=>['easy','moderate','hard'],'shape'=>['loop','one_way','out_back']] as $key=>$allowed)if(in_array($raw[$key]??'', $allowed,true))$facts[$key]=$raw[$key];
 if(isset($raw['length_km'])&&$raw['length_km']!==''){if(!is_numeric($raw['length_km'])||$raw['length_km']<0||$raw['length_km']>5000)throw new DomainException('invalid');$facts['length_km']=(float)$raw['length_km'];}
 $allowed=['water','flowers','shade','viewpoints','nature','history','family','dogs','wheelchair','stroller','transit','parking','steep','seasonal'];$tags=$raw['tags']??[];if(!is_array($tags))throw new DomainException('invalid');$facts['tags']=array_values(array_intersect($allowed,$tags));return $facts;
}
function cpFactsFields(array $p): void {
 $f=$p['route_facts']??[];echo '<details class="cp-details"><summary>'.e(cpT('route_features')).'</summary><p>'.e(cpT('author_facts')).'</p><label>'.e(rxT('distance')).'<input type="number" min="0" max="5000000" step="any" name="route_length" value="'.e(isset($f['length_km'])?round($f['length_km']*1000/rxUnitConfig()['units'][rxUnit()]['meters'],6):'').'"><select name="route_length_unit">'.rxUnitOptions(rxUnit()).'</select></label>';
 foreach(['difficulty'=>['easy','moderate','hard'],'shape'=>['loop','one_way','out_back']] as $key=>$values){echo '<label>'.e(cpT($key)).'<select name="route_'.$key.'"><option value="">'.e(nt('unknown')).'</option>';foreach($values as $v)echo '<option value="'.$v.'" '.(($f[$key]??'')===$v?'selected':'').'>'.e(cpT($v)).'</option>';echo '</select></label>';}
 echo '<div class="cp-tags">';foreach(['water','flowers','shade','viewpoints','nature','history','family','dogs','wheelchair','stroller','transit','parking','steep','seasonal'] as $tag)echo '<label><input type="checkbox" name="route_tag_'.$tag.'" value="1" '.(in_array($tag,$f['tags']??[],true)?'checked':'').'> '.e(cpT($tag)).'</label>';echo '</div></details>';
}
function cpFactsDisplay(array $p): void {
 $f=$p['route_facts']??[];if(!$f||(!isset($f['length_km'])&&empty($f['difficulty'])&&empty($f['shape'])&&empty($f['tags'])))return;
 echo '<details class="cp-details"><summary>'.e(cpT('route_features')).'</summary><div class="cp-tags">';foreach(['difficulty','shape'] as $key)if(!empty($f[$key]))echo '<span>'.e(cpT($f[$key])).'</span>';if(isset($f['length_km']))echo '<span>'.e(rxDistance($f['length_km']*1000)).'</span>';foreach($f['tags']??[] as $tag)echo '<span>'.e(cpT($tag)).'</span>';echo '</div><small>'.e(cpT('author_facts')).'</small></details>';
}

function cpCommunityPanel(string $id,array $rows): void {
 echo '<section class="social-card cp-wiki"><h2>'.e(crT('community')).'</h2><p>'.e(crT('separate')).'</p><div class="cp-wiki-actions"><button type="button" class="button" data-cr-propose="'.e($id).'">'.e(crT('propose')).'</button><button type="button" class="button secondary" data-cp-contribute="'.e($id).'">'.e(crT('personal')).'</button><button type="button" class="button secondary" data-cp-place="'.e($id).'">'.e(crT('community')).'</button></div></section>';
}
