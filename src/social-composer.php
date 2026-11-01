<?php
require_once __DIR__.'/route-experience.php';
require_once __DIR__.'/photo-disclosure.php';
require_once __DIR__.'/community-places.php';
require_once __DIR__.'/post-edits.php';
require_once __DIR__.'/place-collections.php';
require_once __DIR__.'/publication-types.php';
// Four publication types share the existing catalogue, moderation and private trip store.
function scText(string $en,string $he='',string $ru=''): string {return languagePhrase($he?:$en,$en,$ru?:$en);}
function scSchema(): void {
 static $ready=false;if($ready)return;$ready=true;
 socialDb()->exec("CREATE TABLE IF NOT EXISTS social_draft_trash(id TEXT PRIMARY KEY,owner TEXT NOT NULL,deleted INTEGER NOT NULL); CREATE TABLE IF NOT EXISTS social_drafts(id TEXT PRIMARY KEY,owner TEXT NOT NULL,kind TEXT NOT NULL,document TEXT NOT NULL,revision INTEGER NOT NULL DEFAULT 1,updated INTEGER NOT NULL); CREATE TABLE IF NOT EXISTS social_publication_requests(owner TEXT NOT NULL,token TEXT NOT NULL,url TEXT NOT NULL,PRIMARY KEY(owner,token)); CREATE TABLE IF NOT EXISTS social_comment_links(comment TEXT PRIMARY KEY,document TEXT NOT NULL)");
}
function scCatalogue(bool $refresh=false,?CatalogueReadBatch $batch=null): array {
 static $rows=[];if($refresh)$rows=[];$lang=networkContentLanguage();if(!$batch&&isset($rows[$lang]))return $rows[$lang];
 $catalog=array_column(tripCatalog(),null,'id')+tppCatalogue();
 if($batch)$batch->prepareFields(array_merge(array_keys($catalog),array_map(fn($id)=>'community-'.$id,array_keys($batch->places()))));
 // Linked member places follow the post's original language, independently of the interface.
 foreach($batch?$batch->places():socialQuery("SELECT p.* FROM member_places p JOIN members m ON m.id=p.owner WHERE p.state='approved' AND m.state='active'") as $r){
  $id='community-'.$r['id'];unset($catalog[$id]);$l=$batch?$batch->locale($r['id'],$lang):(networkLocale('place',$r['id'],$lang)?:socialQuery("SELECT * FROM network_localizations WHERE kind='place' AND target=? AND state='published' ORDER BY updated LIMIT 1",[$r['id']])->fetch());
  if(!$l||networkBlocked(socialUser()['id']??'',$r['owner'])||!saCanView('place',$r['id'],$r['owner'])||($batch?$batch->hidden('place',$r['id']):crHidden('place',$r['id']))||($batch?$batch->canonical($id)!==$id:networkPlaceAlias($r['id'])!==$r['id']))continue;$p=json_decode($l['document'],true);
  $catalog[$id]=['id'=>$id,'country'=>$p['country']??'','city'=>$p['city'],'title'=>$p['title'],'summary'=>$p['description'],'type'=>$p['category'],'duration'=>'','minutes'=>null,'image'=>!empty($p['photo'])&&crPhotoAllowed($p['photo'])?'/community/media/'.$p['photo'].'/':'','credit'=>socialT('member_content'),'url'=>languageUrl('/community/places/'.$r['id'].'/?content_lang='.rawurlencode($l['language']),siteLanguage()),'cost'=>$p['cost'],'community'=>true,'image_disclosure'=>$p,'photo_illustration'=>!empty($p['photo_illustration'])];
 }
 // Search aliases belong to the same stable identity; never merge records by name.
 $placeAliases=json_decode(file_get_contents(ROOT.'/content/place-search-aliases.json'),true,512,JSON_THROW_ON_ERROR);foreach(socialQuery("SELECT target,document FROM network_localizations WHERE kind='place' AND state='published'") as $aliasRow){$aliasDoc=json_decode($aliasRow['document'],true);if(!empty($aliasDoc['title']))$placeAliases['community-'.$aliasRow['target']][]=$aliasDoc['title'];}
 foreach(json_decode(file_get_contents(ROOT.'/content/places.json'),true) as $sourcePlace)$placeAliases[$sourcePlace['id']]=array_values($sourcePlace['titles']??[]);
 foreach(editorialDocuments() as $aliasId=>$aliasRecord){foreach($aliasRecord['editorial']['translations']??[] as $translation)if(!empty($translation['title']))$placeAliases[$aliasId][]=$translation['title'];}
 foreach($catalog as $id=>&$place){$place['aliases']=array_values(array_unique(array_merge($place['aliases']??[],$placeAliases[$id]??[])));if(empty($place['image'])&&str_starts_with($id,'community-')){foreach($batch?$batch->contributions($id):cpContributions($id) as $contribution){$images=crPhotos(json_decode($contribution['document'],true)['photos']??[]);if($images){$place['image']='/community/media/'.$images[0].'/';$place['image_disclosure']=json_decode($contribution['document'],true);$place['photo_illustration']=!empty(json_decode($contribution['document'],true)['photo_illustration']);break;}}}$place=$batch?$batch->fields($id,$lang,$place):crPlaceFields($id,$lang,$place);if(isset($place['description']))$place['summary']=$place['description'];}unset($place);if($batch)return $catalog;return $rows[$lang]=$catalog;
}
function scLinked(array $ids): array {
 if(count($ids)>30)throw new DomainException('invalid');$out=[];$catalog=scCatalogue();
 foreach($ids as $id){if(!is_string($id)||!isset($catalog[$id]))throw new DomainException('not_found');if(!isset($out[$id]))$out[$id]=$catalog[$id];}return array_values($out);
}
function scDraft(array $u,string $id): ?array {scSchema();return socialQuery('SELECT * FROM social_drafts WHERE id=? AND owner=? AND NOT EXISTS(SELECT 1 FROM social_draft_trash t WHERE t.id=social_drafts.id)',[$id,$u['id']])->fetch()?:null;}
function scSubmit(array $u): string {
 scSchema();networkPublicationAllowed();$token=socialString($_POST['request_token']??'',64,16);
 if($url=socialQuery('SELECT url FROM social_publication_requests WHERE owner=? AND token=?',[$u['id'],$token])->fetchColumn())return $url;
 $kind=$_POST['post_kind']??'';if(!isset(publicationTypes()[$kind]))throw new DomainException('invalid');
 $draftId=socialString($_POST['draft_id']??'',32);$old=$draftId?scDraft($u,$draftId):null;if($draftId&&!$old)throw new DomainException('not_found');
 if($old&&(int)($_POST['revision']??0)!==(int)$old['revision'])throw new DomainException('conflict');
 $edit=$old?seMap($draftId,$u['id']):null;if($edit){seGuard($u,$edit);$_POST['content_language']=$edit['language'];$GLOBALS['network_card_language']=$edit['language'];if($kind!==$old['kind'])throw new DomainException('invalid');}
 $ids=json_decode($_POST['place_ids']??'[]',true);if(!is_array($ids))throw new DomainException('invalid');if($kind==='experience'&&!empty($_POST['continuation_trip'])){$trip=sxTrip($u,(string)$_POST['continuation_trip']);$chosen=$_POST['continuation_places']??[];if(!is_array($chosen)||array_diff($chosen,array_column($trip['plan']['items'],'id')))throw new DomainException('invalid');$ids=array_values(array_unique(array_merge($ids,$chosen)));}$places=(($_POST['intent']??'')==='draft'||in_array($kind,['trip','place','experience'],true))?crDraftPlaces($u,$ids):scLinked($ids);
 $stopIds=json_decode($_POST['route_stop_ids']??'[]',true);if($kind==='trip'){if(!is_array($stopIds)||($stopIds&&count($stopIds)!==count($ids)))throw new DomainException('invalid');$byPlace=array_column($places,null,'id');$places=[];foreach($ids as $i=>$pid){$place=$byPlace[$pid];$place['item_id']=$stopIds[$i]??substr(hash('sha256','stop:'.$i.':'.$pid),0,32);$places[]=$place;}$places=sxItems($places);}
 $city=socialString($_POST['city']??'',100);$country=socialString($_POST['country']??'',64);if($city&&!isset(tripDestinations()[$city]))throw new DomainException('invalid');if($country&&!destinationCountryValid($country))throw new DomainException('invalid');
 $p=['kind'=>$kind,'title'=>socialString($_POST['title']??'',160),'body'=>socialString($_POST['body']??'',12000),'language'=>networkLanguage($_POST['content_language']??networkPublicationDefault()),'city'=>$city,'country'=>$country,'region'=>socialString($_POST['region']??'',100),'place_ids'=>array_column($places,'id'),'destinations'=>array_values(array_unique(array_filter(array_merge([$city],array_column($places,'city'))))),'visited_at'=>socialString($_POST['visited_at']??'',7),'photos'=>[],'route_status'=>($_POST['route_status']??'')==='tried'?'tried':'planned','travel_mode'=>in_array($_POST['travel_mode']??'',['walk','bike','transit','car'],true)?$_POST['travel_mode']:'walk','estimated_minutes'=>networkMinutes($_POST['estimated_minutes']??null)];
 if($p['visited_at']&&(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$p['visited_at'])||$p['visited_at']>gmdate('Y-m')))throw new DomainException('invalid');
 if($city&&$country&&!in_array($city,networkCountryCities($country),true))throw new DomainException('invalid');
 if($kind==='trip')$p['route_stop_ids']=array_column($places,'item_id');
 if($kind==='place'&&$places){$p['city']=$places[0]['city'];if($p['title']==='')$p['title']=$places[0]['title'];$p['country']=$places[0]['country']??'';}
 if($kind==='place')$p['place_tips']=plcTips($_POST['place_tips']??[], $p['place_ids']);
 $routeIds=$_POST['linked_routes']??[];if(!is_array($routeIds)||count($routeIds)>5)throw new DomainException('invalid');foreach($routeIds as $rid)if(!is_string($rid)||!socialPublicTrip($rid))throw new DomainException('not_found');$p['linked_routes']=array_values(array_unique($routeIds));
 $optional=json_decode($_POST['optional_ids']??'[]',true);$p['optional_ids']=is_array($optional)&&count(array_filter($optional,'is_string'))===count($optional)?array_values(array_intersect($p['route_stop_ids']??$p['place_ids'],$optional)):[];
 // Older clients identify optional stops by place ID. Preserve that explicit choice
 // while modern clients use occurrence IDs (so repeated visits remain independent).
 if($kind==='trip'&&!$stopIds&&is_array($optional)&&count(array_filter($optional,'is_string'))===count($optional))foreach($places as $place)if(in_array($place['id'],$optional,true))$p['optional_ids'][]=$place['item_id'];
 if($kind==='trip'){
  $legs=json_decode($_POST['connections']??'[]',true);$visits=json_decode($_POST['visits']??'{}',true);
  if(!is_array($legs)||count($legs)>30||!is_array($visits))throw new DomainException('invalid');
  $p['connections']=rxConnections($places,$legs,$p['travel_mode']);$p['visits']=[];
  foreach($places as $place)$p['visits'][$place['item_id']]=rxNumber($visits[$place['item_id']]??$visits[$place['id']]??null,10080);
 }
 $retained=json_decode($_POST['retained_photos']??'[]',true);$prior=$old?json_decode($old['document'],true):[];
 if(!is_array($retained)||count(array_filter($retained,'is_string'))!==count($retained)||array_diff($retained,array_merge($prior['photos']??[],scOwnedDraftPhotos($u['id'],$retained))))throw new DomainException('invalid');$p['photos']=$retained;
 $new=[];if(!empty($_FILES['photo']['name'][0])){if(empty($_POST['rights']))throw new DomainException('rights_required');$new=socialUploadImages(bin2hex(random_bytes(16)));$p['photos']=array_merge($p['photos'],$new);}
 if(count($p['photos'])>5){socialDiscardImages($new);throw new DomainException('invalid');}
 $draft=($_POST['intent']??'')==='draft';
 $p['continuation_trip']=socialString($_POST['continuation_trip']??'',32);$p['continuation_sources']=is_array($_POST['continuation_sources']??null)?$_POST['continuation_sources']:[];if($p['continuation_trip']){if($kind!=='experience')throw new DomainException('invalid');sxContinuationSources($u,$p['continuation_trip'],$p['continuation_sources']);}
 try {
  $p['route_facts']=cpRouteFacts(['length_km'=>isset($_POST['route_length'])&&$_POST['route_length']!==''?rxInputMeters($_POST['route_length'],(string)($_POST['route_length_unit']??'km'))/1000:($_POST['route_length_km']??''),'difficulty'=>$_POST['route_difficulty']??'','shape'=>$_POST['route_shape']??'','tags'=>array_map(fn($key)=>substr($key,10),array_keys(array_filter($_POST,fn($v,$k)=>str_starts_with($k,'route_tag_')&&$v==='1',ARRAY_FILTER_USE_BOTH)))]);
  $p+=photoMetadata($_POST);$audience=$edit?['audience'=>json_decode($old['document'],true)['audience']??'public']:saInput($_POST,$u['id'],$draft);if($draft)$p+=$audience;
  if(!$draft){
   foreach($places as $place)if(!empty($place['pending'])&&empty($place['staged_document']['staged']))throw new DomainException('not_found');
   if($kind==='experience'&&trim($p['body'])===''&&!$p['photos'])throw new DomainException('invalid');
   if($kind==='question'&&trim($p['title'])==='')throw new DomainException('invalid');
   if($kind==='place'&&(count($places)<1))throw new DomainException('invalid');
   if($kind==='trip'&&count($places)<2)throw new DomainException('invalid');
   // Known canonical places may have no navigation data; no coordinates are inferred.
  }
  if($kind==='trip'&&$p['title']===''&&$places)$p['title']=mb_substr($places[0]['title'].' → '.$places[count($places)-1]['title'],0,120);
  socialDb()->beginTransaction();
  // Recheck under the write transaction for double submissions.
  if($url=socialQuery('SELECT url FROM social_publication_requests WHERE owner=? AND token=?',[$u['id'],$token])->fetchColumn()){socialDb()->rollBack();socialDiscardImages($new);return $url;}
  $id=$draftId?:bin2hex(random_bytes(16));
  if(!$draft)sxPublishPlaces($u,$places,$audience);
  if($draft){
   if($old){$q=socialQuery('UPDATE social_drafts SET kind=?,document=?,revision=revision+1,updated=? WHERE id=? AND owner=? AND revision=?',[$kind,json_encode($p,JSON_UNESCAPED_UNICODE),time(),$id,$u['id'],$old['revision']]);if(!$q->rowCount())throw new DomainException('conflict');}
   else socialQuery('INSERT INTO social_drafts(id,owner,kind,document,updated) VALUES(?,?,?,?,?)',[$id,$u['id'],$kind,json_encode($p,JSON_UNESCAPED_UNICODE),time()]);
   $url=languageUrl('/community/create/?draft='.$id,siteLanguage());
  }elseif($edit){$url=seSave($u,$edit,$p,$places);$id=$edit['target'];
  }elseif($kind==='trip'){
   $items=[];foreach($places as $place)$items[]=['item_id'=>$place['item_id'],'id'=>$place['id'],'title'=>$place['title'],'day'=>0,'type'=>'experience','note'=>'','booked'=>false,'optional'=>in_array($place['item_id'],$p['optional_ids'],true),'mode'=>$p['travel_mode'],'stay_minutes'=>$p['visits'][$place['item_id']]??null];
   $plan=photoMetadata($p)+['route_facts'=>$p['route_facts'],'country'=>$p['country']?:($places[0]['country']??''),'region'=>$p['region']??'','connections'=>$p['connections'],'version'=>1,'city'=>$p['city']?:$places[0]['city'],'days'=>0,'travelers'=>1,'items'=>$items,'public_title'=>$p['title'],'public_summary'=>$p['body'],'language'=>$p['language'],'route_status'=>$p['route_status'],'travel_mode'=>$p['travel_mode'],'estimated_minutes'=>$p['estimated_minutes']];
   $saved=socialSaveTrip($u,['title'=>$p['title'],'plan'=>$plan]);$id=$saved['id'];foreach($p['photos'] as $im)socialQuery('INSERT OR IGNORE INTO member_trip_images VALUES(?,?,?)',[$im,$id,time()]);
   saStore('trip',$id,$u['id'],$audience);$publication=socialPublish($u,$id,$saved['revision']);$GLOBALS['sc_pending_submission']=!empty($publication['pending']);foreach($places as $place){$origin=socialQuery("SELECT document FROM member_places WHERE id=? AND owner=?",[substr($place['id'],10),$u['id']])->fetchColumn();if($origin&&!empty(json_decode($origin,true)['created_in_route']))socialQuery('INSERT OR IGNORE INTO community_place_origins VALUES(?,?,?)',[$place['id'],$id,$u['id']]);}$url=languageUrl('/community/trips/'.$id.'/?content_lang='.$p['language'],siteLanguage());
  }else{
   crQuota($u['id'],'post');socialQuery('INSERT INTO network_posts VALUES(?,?,?,?,?,?)',[$id,$u['id'],$kind,$p['city'],time(),'pending']);
   socialQuery('INSERT INTO network_localizations VALUES(?,?,?,?,?,?,?)',['post',$id,$p['language'],'pending',json_encode($p,JSON_UNESCAPED_UNICODE),1,time()]);foreach($p['photos'] as $im)socialQuery('INSERT OR IGNORE INTO network_images VALUES(?,?,?)',[$im,$id,$u['id']]);
   $url=languageUrl('/community/posts/'.$id.'/?content_lang='.$p['language'],siteLanguage());
  }
  if(!$draft&&!$edit){saStore($kind==='trip'?'trip':'post',$id,$u['id'],$audience);if($kind!=='trip'){$published=$p;unset($published['continuation_trip'],$published['continuation_sources']);networkApproveLocale('post',$id,$p['language'],$published,'community:'.$u['id']);socialQuery("UPDATE network_posts SET state='published' WHERE id=?",[$id]);}}
  if(!$draft&&!$edit){if($kind==='experience')sxPublishContinuation($u,$id,$p);sxEvent('publish_completed',$u['id'],$token);}
  if(!$draft&&$draftId)socialQuery('DELETE FROM social_drafts WHERE id=? AND owner=?',[$draftId,$u['id']]);
  socialQuery('INSERT INTO social_publication_requests VALUES(?,?,?)',[$u['id'],$token,$url]);socialDb()->commit();if(!$draft&&!$edit&&empty($GLOBALS['sc_pending_submission']))rmAction($u['id'],'post_published',$id);return $url;
 }catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();socialDiscardImages($new);throw $e;}
}
function scIcon(string $name): string {
 $paths=['walk'=>'M13 3a1 1 0 1 0 0 .1 M10 8l3-1 3 5 4 1 M7 13l3-5-1 7-4 6 M9 15l6 2 1 5','transit'=>'M5 3h14v15H5z M5 10h14 M8 3v7 M16 3v7 M8 14h1 M15 14h1 M7 18l-2 3 M17 18l2 3','car'=>'M3 11l3-7h12l3 7v8H3z M3 11h18 M6 15h2 M16 15h2 M5 19v2 M19 19v2','story'=>'M6 3h8l4 4v14H6z M14 3v5h4 M9 12h6 M9 16h6','place'=>'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0z M15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0','route'=>'M6 18 18 6 M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0 M21 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0','question'=>'M21 11a9 9 0 0 1-9 9H3l2-5a9 9 0 1 1 16-4','heart'=>'M12 21 3 12C-3 4 7-1 12 6 17-1 27 4 21 12z','save'=>'M6 3h12v18l-6-4-6 4z','share'=>'M8 10l8-5 M8 14l8 5 M8 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0 M22 4a3 3 0 1 1-6 0 3 3 0 0 1 6 0 M22 20a3 3 0 1 1-6 0 3 3 0 0 1 6 0','home'=>'M3 10 12 3l9 7 M5 9v12h5v-7h4v7h5V9','bell'=>'M5 17h14l-2-4V9a5 5 0 0 0-10 0v4z M10 21h4','search'=>'M17 17l5 5 M19 10a9 9 0 1 1-18 0 9 9 0 0 1 18 0','user'=>'M16 6a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M4 22v-3a8 8 0 0 1 16 0v3','map'=>'M3 5l6-2 6 2 6-2v17l-6 2-6-2-6 2z M9 3v17 M15 5v17'];
 return '<svg aria-hidden="true" class="sc-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="'.($paths[$name]??$paths['place']).'"/></svg>';
}
function scAddPlaces(array $u,array $data): array {
 scSchema();$token=socialString($data['token']??'',64,16);$places=scLinked($data['places']??[]);if(!$places)throw new DomainException('invalid');
 socialDb()->beginTransaction();try{
  if($id=socialQuery('SELECT url FROM social_publication_requests WHERE owner=? AND token=?',[$u['id'],$token])->fetchColumn()){socialDb()->rollBack();return ['id'=>$id,'unchanged'=>true];}
  $id=$data['id']??'';$row=$id?socialQuery('SELECT * FROM member_trips WHERE id=? AND owner=?',[$id,$u['id']])->fetch():null;if($id&&!$row)throw new DomainException('not_found');
  $plan=$row?json_decode($row['plan'],true):['city'=>$places[0]['city'],'days'=>0,'travelers'=>1,'items'=>[]];$existing=array_column($plan['items'],'id');
  foreach($places as $place)if(!in_array($place['id'],$existing,true))$plan['items'][]=['id'=>$place['id'],'title'=>$place['title'],'day'=>0,'type'=>'experience','note'=>'','booked'=>false];
  $result=socialSaveTrip($u,['id'=>$id,'revision'=>$row['revision']??0,'title'=>$row['title']??socialString($data['title']??socialT('my_trip'),120,1),'plan'=>$plan]);
  socialQuery('INSERT INTO social_publication_requests VALUES(?,?,?)',[$u['id'],$token,$result['id']]);socialDb()->commit();if(count($plan['items'])>count($existing))rmAction($u['id'],'place_added_to_trip',$token);return $result;
 }catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();throw $e;}
}
function scSaveOnce(array $u,array $data,string $token): array {
 scSchema();$token=socialString($token,64,16);if(!empty($data['id']))throw new DomainException('invalid');
 socialDb()->beginTransaction();try{
  $id=socialQuery('SELECT url FROM social_publication_requests WHERE owner=? AND token=?',[$u['id'],$token])->fetchColumn();
  if($id){$r=socialQuery('SELECT id,revision FROM member_trips WHERE id=? AND owner=?',[$id,$u['id']])->fetch();if(!$r)throw new DomainException('conflict');socialDb()->rollBack();return $r;}
  $result=socialSaveTrip($u,$data);socialQuery('INSERT INTO social_publication_requests VALUES(?,?,?)',[$u['id'],$token,$result['id']]);socialDb()->commit();return $result;
 }catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();throw $e;}
}
function scRelatedPlace(string $id): void {
 $rows=socialQuery("SELECT l.* FROM network_localizations l WHERE l.state='published' AND l.language=? AND (EXISTS(SELECT 1 FROM json_each(l.document,'$.place_ids') WHERE value=?) OR EXISTS(SELECT 1 FROM json_each(l.document,'$.items') WHERE json_extract(value,'$.id')=?)) ORDER BY l.updated DESC LIMIT 20",[siteLanguage(),$id,$id])->fetchAll();
 $visible=[];foreach($rows as $r)if(networkTarget($r['kind'],$r['target']))$visible[]=$r;if(!$visible)return;
 echo '<section class="practical-panel"><h2>'.e(scText('From the community','מהקהילה','Из сообщества')).'</h2><ul>';
 foreach($visible as $r){$p=json_decode($r['document'],true);echo '<li><a href="'.e(languageUrl('/community/'.($r['kind']==='trip'?'trips':'posts').'/'.$r['target'].'/?content_lang='.$r['language'],siteLanguage())).'">'.e($p['public_title']??$p['title']??mb_substr($p['body']??'',0,80)).'</a></li>';}echo '</ul></section>';
}

// A recovered local draft may retain uploads from another revision owned by the same member.
function scOwnedDraftPhotos(string $owner,array $wanted): array {
 if(!$wanted)return []; $found=[];
 foreach(socialQuery('SELECT document FROM social_drafts WHERE owner=?',[$owner]) as $row){$doc=json_decode($row['document'],true);foreach($doc['photos']??[] as $id)if(in_array($id,$wanted,true))$found[$id]=true;}
 return array_keys($found);
}

function scTrashDraft(array $u,string $id,bool $restore=false): void {
 scSchema();if(!socialQuery('SELECT 1 FROM social_drafts WHERE id=? AND owner=?',[$id,$u['id']])->fetchColumn())throw new DomainException('not_found');
 if($restore)socialQuery('DELETE FROM social_draft_trash WHERE id=? AND owner=?',[$id,$u['id']]);else socialQuery('INSERT OR REPLACE INTO social_draft_trash VALUES(?,?,?)',[$id,$u['id'],time()]);
}
function scDraftList(array $u): void {
 echo '<details class="network-card sc-drafts"><summary>'.e(scText('Your drafts','הטיוטות שלכם','Ваши черновики')).'</summary>';
 foreach(socialQuery('SELECT d.*,t.deleted FROM social_drafts d LEFT JOIN social_draft_trash t ON t.id=d.id WHERE d.owner=? ORDER BY d.updated DESC',[$u['id']]) as $row){$d=json_decode($row['document'],true);echo '<div data-saved-draft="'.e($row['id']).'" style="margin:12px 0"><a href="'.e(languageUrl('/community/create/?draft='.$row['id'],siteLanguage())).'" '.($row['deleted']?'hidden':'').'>'.e($d['title']?:mb_substr($d['body'],0,80)?:nt($row['kind'])).'</a> <button type="button" data-draft-trash="'.e($row['id']).'" data-restore="'.($row['deleted']?'1':'0').'">'.e(rxT($row['deleted']?'restore_draft':'delete_draft')).'</button><span role="status"></span></div>';}
 echo '</details>';
}
