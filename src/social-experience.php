<?php
declare(strict_types=1);

// Adds provenance to the existing private member_trips; never a second trip store.
function sxSchema(): void {
 static $ready=false;if($ready)return;
 socialDb()->exec("CREATE TABLE IF NOT EXISTS social_trip_sources(id TEXT PRIMARY KEY,trip TEXT NOT NULL REFERENCES member_trips(id) ON DELETE CASCADE,item TEXT NOT NULL,kind TEXT NOT NULL,target TEXT NOT NULL,comment TEXT NOT NULL DEFAULT '',edition TEXT NOT NULL,revision INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL,UNIQUE(trip,item,kind,target,comment));
 CREATE INDEX IF NOT EXISTS social_source_trip ON social_trip_sources(trip,item);
 CREATE TABLE IF NOT EXISTS social_trip_actions(owner TEXT NOT NULL,token TEXT NOT NULL,trip TEXT NOT NULL,revision INTEGER NOT NULL,before_plan TEXT,before_title TEXT,before_sources TEXT NOT NULL,result TEXT NOT NULL,undone INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL,PRIMARY KEY(owner,token));
 CREATE TABLE IF NOT EXISTS social_continuations(post TEXT NOT NULL REFERENCES network_posts(id) ON DELETE CASCADE,source TEXT NOT NULL,kind TEXT NOT NULL,target TEXT NOT NULL,comment TEXT NOT NULL DEFAULT '',edition TEXT NOT NULL,author TEXT NOT NULL,created INTEGER NOT NULL,PRIMARY KEY(post,source));
 CREATE INDEX IF NOT EXISTS social_continuation_target ON social_continuations(kind,target);
 CREATE TABLE IF NOT EXISTS social_continuation_notices(post TEXT NOT NULL,recipient TEXT NOT NULL,PRIMARY KEY(post,recipient));
 CREATE TABLE IF NOT EXISTS social_flow_events(id TEXT PRIMARY KEY,event TEXT NOT NULL,actor TEXT NOT NULL,created INTEGER NOT NULL);
 CREATE TABLE IF NOT EXISTS social_comment_parents(comment TEXT PRIMARY KEY REFERENCES network_comments(id),parent TEXT NOT NULL REFERENCES network_comments(id));");$ready=true;
}
function sxLabels(): array {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/social-experience-labels.json'),true);return $all[siteLanguage()]??$all['en'];}
function sxT(string $key): string {return sxLabels()[$key]??nt($key);}
function sxObserve(array $u,array $input): array {
 sxSchema();$event=$input['event']??'';$id=(string)($input['id']??'');
 if($event==='source_reopened'){$ref=socialQuery('SELECT s.* FROM social_trip_sources s JOIN member_trips t ON t.id=s.trip WHERE s.id=? AND t.owner=?',[$id,$u['id']])->fetch();if(!$ref||!sxSource($ref,$u['id']))throw new DomainException('not_found');}
 elseif($event==='notification_opened'){$notice=socialQuery('SELECT * FROM network_notices WHERE id=? AND member=?',[$id,$u['id']])->fetch();if(!$notice||!saNoticeUrl($notice))throw new DomainException('not_found');}
 else throw new DomainException('invalid');
 sxEvent($event,$u['id'],$id.':'.gmdate('Y-m-d'));return ['ok'=>true];
}
function sxEvent(string $event,string $actor,string $token): void {
 sxSchema();if(!in_array($event,['publish_completed','reply_attachment_added','recommendation_adopted','route_forked','alternative_applied','source_reopened','continuation_published','notification_opened'],true))return;
 socialQuery('INSERT OR IGNORE INTO social_flow_events VALUES(?,?,?,?)',[hash('sha256',$actor.':'.$event.':'.$token),$event,$actor,time()]);
}
// Resolves afresh for a specified viewer. Nothing from a saved title/author snapshot is exposed.
function sxSource(array $ref,?string $viewer=null): ?array {
 $viewer??=socialUser()['id']??'';$kind=$ref['kind']??'';$id=$ref['target']??'';
 if($kind==='share')return tsSource($id);
 $table=['post'=>'network_posts','trip'=>'member_trips','place'=>'member_places'][$kind]??null;if(!$table)return null;
 $r=socialQuery("SELECT t.*,m.state member_state FROM $table t JOIN members m ON m.id=t.owner WHERE t.id=?",[$id])->fetch();
 if(!$r||$r['member_state']!=='active'||networkBlocked($viewer,$r['owner'])||!saCanView($kind,$id,$r['owner'],$viewer)||crHidden($kind,$id))return null;
 if(($kind==='post'&&$r['state']!=='published')||($kind==='place'&&$r['state']!=='approved')||($kind==='trip'&&(!$r['public_plan']||$r['state']==='blocked')))return null;
 $edition=$ref['edition']??'';
 $l=$edition?socialQuery("SELECT * FROM network_localizations WHERE kind=? AND target=? AND language=? AND state='published'",[$kind,$id,$edition])->fetch():socialQuery("SELECT * FROM network_localizations WHERE kind=? AND target=? AND state='published' ORDER BY updated,language LIMIT 1",[$kind,$id])->fetch();if(!$l)return null;
 $p=json_decode($l['document'],true);$author=$r['owner'];$comment=$ref['comment']??'';$places=$p['place_ids']??array_column($p['items']??[],'id');$routes=$p['linked_routes']??[];
 if($kind==='place')$places=['community-'.$id];
 if($comment){$c=socialQuery("SELECT c.* FROM network_comments c JOIN members m ON m.id=c.owner WHERE c.id=? AND c.kind=? AND c.target=? AND c.state='published' AND m.state='active'",[$comment,$kind,$id])->fetch();if(!$c||networkBlocked($viewer,$c['owner'])||crHidden('comment',$comment))return null;$author=$c['owner'];$links=json_decode(socialQuery('SELECT document FROM social_comment_links WHERE comment=?',[$comment])->fetchColumn()?:'{}',true);$places=$links['places']??[];$routes=$links['routes']??[];}
 $profile=networkProfile($author);if(!$profile)return null;
 return ['kind'=>$kind,'target'=>$id,'comment'=>$comment,'edition'=>$l['language'],'revision'=>(int)$l['source_version'],'author'=>$author,'name'=>$profile['name']?:$profile['handle'],'title'=>$p['public_title']??$p['title']??'','url'=>languageUrl('/community/'.['post'=>'posts','trip'=>'trips','place'=>'places'][$kind].'/'.$id.'/?content_lang='.rawurlencode($l['language']),siteLanguage()).($comment?'#comment-'.$comment:''),'places'=>$places,'routes'=>$routes,'document'=>$p];
}
function sxItems(array $items): array {
 $seen=[];foreach($items as $i=>&$item){$key=$item['item_id']??substr(hash('sha256','legacy:'.$i.':'.$item['id']),0,32);if(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key)||isset($seen[$key]))throw new DomainException('invalid');$seen[$key]=true;$item['item_id']=$key;$item['active']=($item['active']??true)!==false;$item['alternative_to']=socialString($item['alternative_to']??'',32);}unset($item);return $items;
}
function sxOwn(array $u,string $id): array {
 $r=socialQuery('SELECT * FROM member_trips WHERE id=? AND owner=?',[$id,$u['id']])->fetch();if(!$r)throw new DomainException('not_found');return $r;
}
function sxDeleteTrip(array $u,array $input): array {
 sxSchema();tsSchema();$db=socialDb();$db->exec('BEGIN IMMEDIATE');try{
  $id=(string)($input['id']??'');$row=sxOwn($u,$id);
  if((int)($input['revision']??0)!==(int)$row['revision'])throw new DomainException('conflict');
  socialQuery("UPDATE trip_shares SET revoked=1,revision=revision+1,updated=? WHERE trip=? AND owner=?",[time(),$id,$u['id']]);
  socialQuery("UPDATE network_localizations SET state='removed' WHERE kind='trip' AND target=?",[$id]);
  socialQuery('DELETE FROM member_trip_images WHERE trip_id=?',[$id]);
  socialQuery('DELETE FROM social_trip_sources WHERE trip=?',[$id]);
  socialQuery('DELETE FROM member_trips WHERE id=? AND owner=?',[$id,$u['id']]);
  $db->exec('COMMIT');return ['deleted'=>true];
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function sxTrip(array $u,string $id): array {
 sxSchema();$r=sxOwn($u,$id);$p=json_decode($r['plan'],true);$p['items']=sxItems($p['items']);$sources=[];
 foreach(socialQuery('SELECT * FROM social_trip_sources WHERE trip=? ORDER BY created,id',[$id]) as $s)if($v=sxSource($s,$u['id'])){$sources[$s['item']][]=['id'=>$s['id'],'kind'=>$v['kind'],'name'=>$v['name'],'url'=>$v['url'],'comment'=>(bool)$s['comment']];}
 // Parent ids are internal provenance only; source text/photos are never copied into this view.
 unset($p['public_summary'],$p['summary'],$p['photos']);
 $catalog=scCatalogue();foreach($p['items'] as &$item){$place=$catalog[$item['id']]??null;if($place){$item['title']=$place['title'];$item['location']=rxLocation($place);$item['image']=$place['image']??'';$item['credit']=$place['credit']??'';$item['photo_credit']=$place['image_disclosure']??[];}elseif(str_starts_with($item['id'],'community-')){$item['title']=rxT('location_missing');unset($item['location'],$item['image'],$item['credit']);}if(!empty($item['private_address']))$item['location']=['id'=>$item['id'],'title'=>$item['title'],'address'=>$item['private_address'],'query'=>$item['private_address'],'coords'=>tsCoords($item['private_coords']??null)??tsCoordinatePair($item['private_address'])];if(!empty($item['photo_override'])&&tdpAllowed($item['photo_override'],$u)){$item['image']=tdpUrl($item['photo_override']);$item['credit']=tdpRow($item['photo_override'])['credit'];$item['photo_credit']=[];}}unset($item);
 $p['connections']=rxConnections($p['items'],$p['connections']??[],$p['travel_mode']??'walk');
 $p['facts']=tdKnownFacts($p);
 return ['id'=>$id,'title'=>$r['title'],'revision'=>(int)$r['revision'],'plan'=>$p,'sources'=>$sources,'summary'=>rxSummary($p)];
}
function sxCanonicalPlace(string $id): array {
 $id=cpCanonical($id);$p=scCatalogue()[$id]??null;if(!$p)throw new DomainException('not_found');
 if(str_starts_with($id,'community-')&&!sxSource(['kind'=>'place','target'=>substr($id,10)]))throw new DomainException('not_found');return $p;
}
function sxWrite(array $u,array $input): array {
 sxSchema();$token=socialString($input['token']??'',64,16);$db=socialDb();$db->exec('BEGIN IMMEDIATE');
 try {
  $prior=socialQuery('SELECT * FROM social_trip_actions WHERE owner=? AND token=?',[$u['id'],$token])->fetch();if($prior){if($prior['undone'])throw new DomainException('conflict');$result=json_decode($prior['result'],true);$db->exec('COMMIT');return $result;}
  $op=$input['operation']??'add';if(in_array($op,['document','photo'],true)&&!tdEnabled())throw new DomainException('not_found');if(!in_array($op,['add','fork','alternative','replace','activate','edit','reorder','remove','repeat','leg','create','custom','meta','document','photo'],true))throw new DomainException('invalid');
  $id=socialString($input['id']??'',32);$row=$id?sxOwn($u,$id):null;if($row&&(int)($input['revision']??0)!==(int)$row['revision'])throw new DomainException('conflict');
  $p=$row?json_decode($row['plan'],true):['city'=>'','days'=>0,'travelers'=>1,'items'=>[]];$p['items']=sxItems($p['items']);$beforeSources=$row?socialQuery('SELECT * FROM social_trip_sources WHERE trip=?',[$id])->fetchAll():[];
  $source=!empty($input['source'])?sxSource($input['source'],$u['id']):null;if(!empty($input['source'])&&!$source)throw new DomainException('not_found');$attach=[];
  $referrer=!empty($input['referrer'])?sxSource($input['referrer'],$u['id']):null;
  if(!empty($input['referrer'])&&(!$referrer||$op!=='fork'||!$source||!in_array($source['target'],$referrer['routes'],true)))throw new DomainException('invalid');
  if($op==='fork'){
   if($row||!$source||!in_array($source['kind'],['trip','share'],true))throw new DomainException('invalid');$original=$source['document'];if(empty($input['additional_copy'])){$existing=socialQuery("SELECT t.id,t.revision FROM member_trips t WHERE t.owner=? AND ((?='share' AND json_extract(t.plan,'$.origin_share')=?) OR (?='trip' AND json_extract(t.plan,'$.origin_trip')=?) OR EXISTS(SELECT 1 FROM social_trip_sources s WHERE s.trip=t.id AND s.kind=? AND s.target=?)) ORDER BY t.updated DESC LIMIT 1",[$u['id'],$source['kind'],$source['target'],$source['kind'],$source['target'],$source['kind'],$source['target']])->fetch();if($existing){$db->exec('COMMIT');return ['id'=>$existing['id'],'revision'=>(int)$existing['revision'],'token'=>$token,'url'=>languageUrl('/my-trip/?trip='.$existing['id'],siteLanguage())];}}$p=['description'=>mb_substr($original['description']??$original['public_summary']??'',0,600),'city'=>$original['city']??'','days'=>0,'travelers'=>1,'items'=>[],'travel_mode'=>$original['travel_mode']??'walk'];
   foreach($original['items']??[] as $item){$place=($source['kind']==='share'&&($item['type']??'')==='custom')?$item:sxCanonicalPlace($item['id']);$p['items'][]=['id'=>$place['id'],'item_id'=>bin2hex(random_bytes(16)),'title'=>$place['title'],'day'=>0,'type'=>$item['type']??'experience','photo_override'=>tdpReference($item['photo_override']??null),'private_coords'=>$source['kind']==='share'?tsCoords($item['private_coords']??null):null,'private_address'=>$source['kind']==='share'?($item['private_address']??''):'','note'=>$source['kind']==='share'?($item['note']??''):'','booked'=>false,'stay_minutes'=>$item['stay_minutes']??null,'active'=>true];}
   $oldLegs=rxConnections($original['items']??[],$original['connections']??[],$p['travel_mode']);$p['connections']=[];foreach($oldLegs as $i=>$leg){$leg['from']=$p['items'][$i]['item_id'];$leg['to']=$p['items'][$i+1]['item_id'];$p['connections'][]=$leg;}$p['document']=tdDocument(tdPublicDocument($original,$original['items']??[]),$p['items'],tdRemap($original['items']??[],$p['items']));$p['country']=$original['country']??'';$p['region']=$original['region']??'';$p['origin_trip']=$source['kind']==='trip'?$source['target']:'';$p['origin_share']=$source['kind']==='share'?$source['target']:'';$p['facts']=tdKnownFacts($original);$p['content_kind']=$original['content_kind']??'route';$attach=array_column($p['items'],'item_id');
  }elseif($op==='photo'){$index=array_search($input['item']??'',array_column($p['items'],'item_id'),true);$ref=tdpReference($input['photo_override']??null);if($index===false||!tdpAllowed($ref,$u))throw new DomainException('invalid');$p['items'][$index]['photo_override']=$ref;
  }elseif($op==='document'){if(isset($input['destination']))$p=array_replace($p,tdDestination($input['destination']));$p['document']=tdDocument($input['document']??null,$p['items']);$p['facts']=tdFacts($input['facts']??[]);$p['content_kind']=in_array($input['content_kind']??'',['route','collection','story'],true)?$input['content_kind']:'collection';if($row)$row['title']=socialString($input['title']??$row['title'],120,1);
  }elseif($op==='create'||$op==='meta'){$p['description']=socialString($input['description']??'',600);if($op==='create'&&($input['content_kind']??'')==='collection')$p['content_kind']='collection';if($op==='meta'&&$row)$row['title']=socialString($input['title']??$row['title'],120,1);
  }elseif($op==='custom'){$p['items'][]=['id'=>'custom-'.bin2hex(random_bytes(12)),'item_id'=>bin2hex(random_bytes(16)),'title'=>socialString($input['title']??'',180,1),'type'=>'custom','note'=>'','private_address'=>socialString($input['address']??'',300),'day'=>0,'active'=>true];
  }elseif(in_array($op,['add','alternative','replace','repeat'],true)){
   $ids=$input['places']??[];if(!is_array($ids)||!count($ids)||count($ids)>30)throw new DomainException('invalid');$target=$input['item']??'';$index=array_search($target,array_column($p['items'],'item_id'),true);
   if(in_array($op,['alternative','replace'],true)&&(!$row||$index===false||count($ids)!==1||empty($p['items'][$index]['active'])))throw new DomainException('invalid');
   foreach($ids as $pid){if(!is_string($pid))throw new DomainException('invalid');$place=sxCanonicalPlace($pid);if($source&&!in_array($pid,$source['places'],true))throw new DomainException('invalid');
    $existing=array_search($place['id'],array_column($p['items'],'id'),true);
    if($existing!==false&&$op!=='repeat'){if(in_array($op,['replace','alternative'],true)&&$p['items'][$existing]['item_id']!==$target)throw new DomainException('conflict');$attach[]=$p['items'][$existing]['item_id'];continue;}
    $new=['id'=>$place['id'],'item_id'=>bin2hex(random_bytes(16)),'title'=>$place['title'],'day'=>0,'type'=>'experience','note'=>'','booked'=>false,'active'=>$op!=='alternative','alternative_to'=>in_array($op,['alternative','replace'],true)?$target:''];
    if($op==='replace'){$p['items'][$index]['active']=false;array_splice($p['items'],$index,0,[$new]);}else $p['items'][]=$new;$attach[]=$new['item_id'];if(!$p['city'])$p['city']=$place['city'];
   }
  }else{
   if(!$row)throw new DomainException('invalid');$index=array_search($input['item']??'',array_column($p['items'],'item_id'),true);
   if($op==='leg'){$leg=$input['leg']??[];$legs=rxConnections($p['items'],$p['connections']??[]);$found=false;foreach($legs as &$existing)if($existing['from']===($leg['from']??'')&&$existing['to']===($leg['to']??'')){$existing=$leg;$found=true;}unset($existing);if(!$found)throw new DomainException('invalid');$p['connections']=rxConnections($p['items'],$legs);}
   elseif($op==='reorder'){$order=$input['order']??[];$keys=array_column($p['items'],'item_id');if(!is_array($order)||count($order)!==count($keys)||count(array_unique($order))!==count($keys)||array_diff($order,$keys))throw new DomainException('invalid');$by=array_column($p['items'],null,'item_id');$p['items']=array_map(fn($key)=>$by[$key],$order);}
   else {if($index===false)throw new DomainException('not_found');if($op==='remove')array_splice($p['items'],$index,1);
    elseif($op==='activate'){$target=$p['items'][$index]['alternative_to']??'';$other=array_search($target,array_column($p['items'],'item_id'),true);if($other===false)throw new DomainException('conflict');$p['items'][$other]['active']=false;$p['items'][$other]['alternative_to']=$p['items'][$index]['item_id'];$new=$p['items'][$index];$new['active']=true;array_splice($p['items'],$index,1);$other=array_search($target,array_column($p['items'],'item_id'),true);array_splice($p['items'],$other,0,[$new]);}
    else {if(($p['items'][$index]['type']??'')==='custom'&&isset($input['title']))$p['items'][$index]['title']=socialString($input['title'],180,1);if(array_key_exists('private_address',$input)){if($p['items'][$index]['private_address']!==trim($input['private_address']))$p['items'][$index]['private_coords']=null;$p['items'][$index]['private_address']=socialString($input['private_address'],300);}$p['items'][$index]['note']=socialString($input['note']??'',500);$p['items'][$index]['stay_minutes']=networkMinutes($input['stay_minutes']??null);}
   }
  }
  // Explicit empty legs cannot fall back to stale per-place travel_minutes after mutation.
  $active=array_values(array_filter($p['items'],fn($x)=>$x['active']??true));$p['connections']=rxConnections($active,$p['connections']??[['from'=>'','to'=>'']]);
  $result=socialSaveTrip($u,['id'=>$id,'revision'=>$row['revision']??0,'title'=>$row['title']??socialString($input['title']??sxT('ideas'),120,1),'plan'=>$p]);$id=$result['id'];
  $attributions=array_filter([$source,$referrer]);foreach($attach as $item)foreach($attributions as $attribution)socialQuery('INSERT OR IGNORE INTO social_trip_sources VALUES(?,?,?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$id,$item,$attribution['kind'],$attribution['target'],$attribution['comment'],$attribution['edition'],$attribution['revision'],time()]);
  $result=['id'=>$id,'revision'=>$result['revision'],'token'=>$token,'url'=>languageUrl('/my-trip/?trip='.$id,siteLanguage())];
  socialQuery('INSERT INTO social_trip_actions(owner,token,trip,revision,before_plan,before_title,before_sources,result,created) VALUES(?,?,?,?,?,?,?,?,?)',[$u['id'],$token,$id,$result['revision'],$row['plan']??null,$row['title']??null,json_encode($beforeSources),json_encode($result),time()]);
  sxEvent($op==='fork'?'route_forked':(in_array($op,['alternative','replace','activate'],true)?'alternative_applied':'recommendation_adopted'),$u['id'],$token);$db->exec('COMMIT');if(in_array($op,['edit','reorder','remove','custom','leg','add'],true)&&socialQuery("SELECT 1 FROM social_trip_sources WHERE trip=? AND kind='share' LIMIT 1",[$id])->fetchColumn())tsEvent('first_edit',$id);if($op==='fork')rmAction($u['id'],'trip_copied',$token);elseif(in_array($op,['add','alternative','repeat'],true)&&count($p['items'])>count(json_decode($row['plan']??'{}',true)['items']??[]))rmAction($u['id'],'place_added_to_trip',$token);return $result;
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function sxUndo(array $u,array $input): array {
 sxSchema();$db=socialDb();$db->exec('BEGIN IMMEDIATE');try{$a=socialQuery('SELECT * FROM social_trip_actions WHERE owner=? AND token=?',[$u['id'],$input['token']??''])->fetch();if(!$a)throw new DomainException('not_found');if($a['undone']){$db->exec('COMMIT');return ['ok'=>true];}$r=sxOwn($u,$a['trip']);if((int)$r['revision']!==(int)$a['revision']||$r['public_plan']!==null||$r['shared_plan']!==null)throw new DomainException('conflict');
  // Keep the private trip identity, even for undoing its first addition.
  $plan=$a['before_plan']?:json_encode(['city'=>'','days'=>0,'travelers'=>1,'items'=>[]]);socialQuery('UPDATE member_trips SET plan=?,revision=revision+1,updated=? WHERE id=? AND owner=?',[$plan,time(),$r['id'],$u['id']]);socialQuery('DELETE FROM social_trip_sources WHERE trip=?',[$r['id']]);foreach(json_decode($a['before_sources'],true) as $s)socialQuery('INSERT INTO social_trip_sources VALUES(?,?,?,?,?,?,?,?,?)',array_values($s));socialQuery('UPDATE social_trip_actions SET undone=1 WHERE owner=? AND token=?',[$u['id'],$input['token']]);$db->exec('COMMIT');return ['ok'=>true];
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function sxContinuationSources(array $u,string $trip,array $ids): array {
 sxSchema();sxOwn($u,$trip);if(count($ids)>50)throw new DomainException('invalid');$out=[];
 foreach(array_unique($ids) as $id){$r=socialQuery('SELECT * FROM social_trip_sources WHERE id=? AND trip=?',[$id,$trip])->fetch();if(!$r)throw new DomainException('invalid');
  // Only sources visible to everyone are offered for public credit, with per-viewer checks at render time.
  if(($s=sxSource($r,$u['id']))&&sxSource($r,''))$out[$id]=$s;
 }return $out;
}
function sxPublishContinuation(array $u,string $post,array $p): void {
 if(empty($p['continuation_trip']))return;
 $sources=sxContinuationSources($u,$p['continuation_trip'],$p['continuation_sources']??[]);$recipients=[];
 foreach($sources as $sourceId=>$s){if($s['kind']==='post'&&$s['target']===$post)throw new DomainException('invalid');
  // A new post cannot be an ancestor of an existing source; edits never rewrite provenance.
  socialQuery('INSERT OR IGNORE INTO social_continuations VALUES(?,?,?,?,?,?,?,?)',[$post,$sourceId,$s['kind'],$s['target'],$s['comment'],$s['edition'],$s['author'],time()]);$recipients[$s['author']]=true;
 }
 foreach(array_keys($recipients) as $recipient){if($recipient===$u['id']||!sxSource(['kind'=>'post','target'=>$post,'edition'=>$p['language']],$recipient))continue;
  $q=socialQuery('INSERT OR IGNORE INTO social_continuation_notices VALUES(?,?)',[$post,$recipient]);if($q->rowCount())socialQuery("INSERT INTO network_notices(member,kind,target,language,created) VALUES(?,'continuation',?,?,?)",[$recipient,$post,$p['language'],time()]);
 }sxEvent('continuation_published',$u['id'],$post);
}
function sxCredits(string $post): void {
 sxSchema();if(!sxSource(['kind'=>'post','target'=>$post]))return;$links=[];
 foreach(socialQuery('SELECT * FROM social_continuations WHERE post=?',[$post]) as $r)if($s=sxSource($r))$links[$s['url']]='<a href="'.e($s['url']).'" data-sx-source="'.e($r['source']).'">'.e($s['name']).'</a>';
 if($links)echo '<aside class="sx-credits">'.e(sxT('inspired')).' · '.implode(' · ',$links).'</aside>';
}
function sxContinuations(string $kind,string $id,bool $profile=false): void {
 sxSchema();$links=[];$rows=$profile?socialQuery('SELECT DISTINCT post FROM social_continuations WHERE author=? ORDER BY created DESC LIMIT 30',[$id]):socialQuery('SELECT DISTINCT post FROM social_continuations WHERE kind=? AND target=? ORDER BY created DESC LIMIT 30',[$kind,$id]);
 foreach($rows as $r){$post=sxSource(['kind'=>'post','target'=>$r['post']]);if(!$post)continue;$allowed=false;foreach(socialQuery('SELECT * FROM social_continuations WHERE post=?',[$r['post']]) as $s)if(($profile?$s['author']===$id:($s['kind']===$kind&&$s['target']===$id))&&sxSource($s)){$allowed=true;break;}if($allowed)$links[]='<li><a href="'.e($post['url']).'">'.e($post['name'].' · '.($post['title']?:nt('experience'))).'</a></li>';}
 if($links)echo '<section class="sx-credits"><h2>'.e(sxT('inspired')).'</h2><ul>'.implode('',$links).'</ul></section>';
}
function sxComposerSources(array $u,array $d): void {
 $trip=$d['continuation_trip']??$_GET['from_trip']??'';if(!$trip)return;$r=sxTrip($u,$trip);$sources=[];$seen=[];foreach(socialQuery('SELECT * FROM social_trip_sources WHERE trip=?',[$trip]) as $ref)if(($s=sxSource($ref,''))&&sxSource($ref,$u['id'])&&!isset($seen[$s['url']])){$sources[$ref['id']]=$s;$seen[$s['url']]=true;}
 echo '<fieldset class="sx-continuation"><legend>'.e(sxT('share_did')).'</legend><input type="hidden" name="continuation_trip" value="'.e($trip).'"><p>'.e(sxT('preview_hint')).'</p><strong>'.e(sxT('inspired')).'</strong>';
 foreach($sources as $id=>$s)echo '<label><input type="checkbox" name="continuation_sources[]" value="'.e($id).'" '.(in_array($id,$d['continuation_sources']??[],true)?'checked':'').'> <a href="'.e($s['url']).'">'.e($s['name']).'</a></label>';
 echo '<strong>'.e(sxT('choose_places')).'</strong>';foreach($r['plan']['items'] as $item)if($item['active'])echo '<label><input type="checkbox" name="continuation_places[]" value="'.e($item['id']).'">'.e($item['title']).'</label>';
 echo '</fieldset>';
}
function sxPublishPlaces(array $u,array $places,array $audience): void {
 foreach($places as $place){if(!str_starts_with($place['id'],'community-'))continue;$id=substr($place['id'],10);$row=socialQuery("SELECT * FROM member_places WHERE id=? AND owner=? AND state='pending'",[$id,$u['id']])->fetch();if(!$row)continue;$p=json_decode($row['document'],true);if(empty($p['staged']))continue;
  saStore('place',$id,$u['id'],$audience);$p['staged']=false;networkApproveLocale('place',$id,$p['language'],$p,'community:'.$u['id']);socialQuery("UPDATE member_places SET state='approved',document=? WHERE id=?",[json_encode($p,JSON_UNESCAPED_UNICODE),$id]);
 }scCatalogue(true);
}
function sxLocated(array $place,array $u): bool {
 $loc=rxLocation($place);if($loc['coords']||$loc['address'])return true;
 if(str_starts_with($place['id'],'community-')){$raw=socialQuery('SELECT document FROM member_places WHERE id=? AND owner=?',[substr($place['id'],10),$u['id']])->fetchColumn();$p=json_decode($raw?:'{}',true);return isset($p['latitude'],$p['longitude'])||!empty($p['address']);}return false;
}
function sxSwitchDraft(array $u,array $input): array {
 scSchema();$kind=$input['kind']??'';if(!in_array($kind,['experience','place','trip','question'],true))throw new DomainException('invalid');
 $token=socialString($input['token']??'',64,16);$db=socialDb();$db->exec('BEGIN IMMEDIATE');
 try{if($url=socialQuery('SELECT url FROM social_publication_requests WHERE owner=? AND token=?',[$u['id'],$token])->fetchColumn()){$db->exec('COMMIT');return ['url'=>$url];}
  $old=scDraft($u,(string)($input['id']??''));if(!$old)throw new DomainException('not_found');if(seMap($old['id'],$u['id'])||(int)$old['revision']!==(int)($input['revision']??0))throw new DomainException('conflict');
  $doc=json_decode($old['document'],true);$doc['kind']=$kind;$id=bin2hex(random_bytes(16));
  if($kind!=='experience')unset($doc['continuation_trip'],$doc['continuation_sources']);
  socialQuery('INSERT INTO social_drafts(id,owner,kind,document,updated) VALUES(?,?,?,?,?)',[$id,$u['id'],$kind,json_encode($doc,JSON_UNESCAPED_UNICODE),time()]);
  $url=languageUrl('/community/create/?draft='.$id,siteLanguage());socialQuery('INSERT INTO social_publication_requests(owner,token,url) VALUES(?,?,?)',[$u['id'],$token,$url]);$db->exec('COMMIT');return ['url'=>$url];
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function sxTripCards(array $u): array {
 $rows=socialQuery('SELECT id,title,revision,plan FROM member_trips WHERE owner=? ORDER BY updated DESC',[$u['id']])->fetchAll();$catalog=scCatalogue();
 foreach($rows as &$r){$plan=json_decode($r['plan'],true);$r['stops']=count($plan['items']??[]);$r['image']='';$r['destination']='';
 foreach($plan['photos']??[] as $photo){if(preg_match('/^[a-f0-9]{32}$/D',(string)$photo)&&socialQuery('SELECT 1 FROM member_trip_images WHERE id=? AND trip_id=?',[$photo,$r['id']])->fetchColumn()){$r['image']='/community/media/'.$photo.'/';break;}}
 foreach($plan['items']??[] as $item){$p=$catalog[$item['id']]??[];if(!$r['image']&&!empty($item['photo_override'])&&tdpAllowed($item['photo_override'],$u))$r['image']=tdpUrl($item['photo_override']);if(!$r['image']&&!empty($p['image']))$r['image']=$p['image'];if(!$r['destination'])$r['destination']=isset(tripDestinations()[$p['city']??''])?activityLabel(tripDestinations()[$p['city']]['labels']):'';}unset($r['plan']);}unset($r);return $rows;
}
