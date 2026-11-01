<?php
function gtLabels(): array {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/guest-trip-labels.json'),true,512,JSON_THROW_ON_ERROR);return $all[siteLanguage()]??$all['en'];}
function gtPlaces(array $ids,bool $skipMissing=false): array {
 if(count($ids)>100)throw new DomainException('invalid');$result=[];
 foreach($ids as $id){try{$p=sxCanonicalPlace(socialString($id,95,1));}catch(DomainException $e){if($skipMissing&&$e->getMessage()==='not_found')continue;throw $e;}$result[]=['id'=>$p['id'],'title'=>$p['title'],'city'=>$p['city']??'','city_name'=>isset(tripDestinations()[$p['city']??''])?activityLabel(tripDestinations()[$p['city']]['labels']):'','url'=>$p['url']??'','image'=>$p['image']??'','credit'=>$p['credit']??'','photo_credit'=>$p['image_disclosure']??[],'location'=>rxLocation($p)];}return $result;
}
function gtRoute(array $ref): array {
 $source=sxSource($ref);if(!$source||!in_array($source['kind'],['trip','share'],true))throw new DomainException('not_found');
 $doc=$source['document'];$places=$source['kind']==='share'?array_map(fn($i)=>['id'=>$i['id'],'title'=>$i['title'],'type'=>$i['type'],'private_coords'=>tsCoords($i['coords']??null),'private_address'=>$i['address']?:($i['coords']?implode(',',$i['coords']):''),'photo_override'=>isset($i['photo_override']['id'])?['id'=>$i['photo_override']['id'],'source'=>$source['target'],'credit'=>$i['credit']??'']:[],'note'=>$i['note'],'image'=>$i['image'],'location'=>['address'=>$i['address'],'coords'=>$i['coords'],'query'=>$i['coords']?implode(',',$i['coords']):$i['address']],'city'=>''],$source['snapshot']['items']):gtPlaces(array_column($doc['items']??[],'id'));
 return ['city'=>$doc['city']??'','country'=>$doc['country']??'','region'=>$doc['region']??'','document'=>tdPublicDocument($doc,$doc['items']??[]),'facts'=>tdKnownFacts($doc),'travel_mode'=>$doc['travel_mode']??null,'content_kind'=>$doc['content_kind']??'route','description'=>mb_substr($doc['description']??$doc['public_summary']??'',0,600),'source'=>array_intersect_key($source,array_flip(['kind','target','edition','name','title','url','revision'])),'places'=>$places,'items'=>array_map(fn($x)=>array_intersect_key($x,array_flip(['id','item_id','stay_minutes','active','alternative_to'])), $doc['items']??[]),'connections'=>rxConnections($doc['items']??[],$doc['connections']??[])];
}
function gtImport(array $u,array $input): array {
 sxSchema();$d=socialDb();$d->exec('CREATE TABLE IF NOT EXISTS guest_imports(owner TEXT NOT NULL,local_id TEXT NOT NULL,kind TEXT NOT NULL,digest TEXT NOT NULL,result TEXT NOT NULL,created INTEGER NOT NULL,PRIMARY KEY(owner,local_id,kind))');
 $local=socialString($input['localId']??'',64,16);if(!preg_match('/^[a-f0-9]{32}$/D',$local))throw new DomainException('invalid');$kind=$input['kind']??'';if(!in_array($kind,['trip','place'],true))throw new DomainException('invalid');
 $data=$input['data']??null;if(!is_array($data)||strlen(json_encode($data))>200000)throw new DomainException('invalid');$digest=hash('sha256',json_encode($data));$d->exec('BEGIN IMMEDIATE');
 try{
  $prior=socialQuery('SELECT * FROM guest_imports WHERE owner=? AND local_id=? AND kind=?',[$u['id'],$local,$kind])->fetch();
  if($prior){if(!hash_equals($prior['digest'],$digest))throw new DomainException('conflict');$d->exec('COMMIT');return json_decode($prior['result'],true);}
  if($kind==='place'){$place=gtPlaces([$data['id']??''])[0];socialQuery("INSERT OR IGNORE INTO network_edges(actor,kind,target,created) VALUES(?,'save',?,?)",[$u['id'],'catalog:'.$place['id'],time()]);$result=['id'=>$place['id']];}
  else{
   $plan=socialPlan($data['plan']??[]);foreach($plan['items'] as &$item){if($item['type']!=='custom'){$p=gtPlaces([$item['id']])[0];$item['title']=$p['title'];}$item['booked']=false;}unset($item);$plan['photos']=[];
   $result=socialSaveTrip($u,['title'=>socialString($data['title']??sxT('ideas'),120,1),'plan'=>$plan]);
   foreach($data['sources']??[] as $item=>$sources){if(!in_array($item,array_column($plan['items'],'item_id'),true)||!is_array($sources))throw new DomainException('invalid');foreach($sources as $ref){$source=sxSource($ref,$u['id']);if(!$source)throw new DomainException('not_found');$placeId=$plan['items'][array_search($item,array_column($plan['items'],'item_id'),true)]['id'];if(!in_array($placeId,$source['places'],true))throw new DomainException('invalid');socialQuery('INSERT OR IGNORE INTO social_trip_sources VALUES(?,?,?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$result['id'],$item,$source['kind'],$source['target'],$source['comment'],$source['edition'],$source['revision'],time()]);}}
  }
  socialQuery('INSERT INTO guest_imports VALUES(?,?,?,?,?,?)',[$u['id'],$local,$kind,$digest,json_encode($result),time()]);$d->exec('COMMIT');return $result+['imported'=>true];
 }catch(Throwable $e){$d->exec('ROLLBACK');throw $e;}
}
