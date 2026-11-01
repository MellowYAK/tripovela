<?php
// Published edits use immutable network_versions and the existing private composer.
function seSchema(): void {
 static $ready=false;if($ready)return;$ready=true;
 socialDb()->exec('CREATE TABLE IF NOT EXISTS social_edit_drafts(draft TEXT PRIMARY KEY,owner TEXT NOT NULL,kind TEXT NOT NULL,target TEXT NOT NULL,language TEXT NOT NULL,base_version INTEGER NOT NULL)');
}
function seT(string $key): string {static $labels; $labels??=json_decode(file_get_contents(ROOT.'/content/post-edit-labels.json'),true);return $labels[siteLanguage()][$key]??$labels['en'][$key]??$key;}
function seFirst(string $kind,string $id): int {return (int)socialQuery('SELECT MIN(created) FROM network_versions WHERE kind=? AND target=?',[$kind,$id])->fetchColumn();}
function seAllowed(array $u,string $kind,string $id): bool {
 if(!in_array($kind,['post','trip'],true))return false;
 $table=$kind==='trip'?'member_trips':'network_posts';$r=socialQuery("SELECT owner,state FROM $table WHERE id=?",[$id])->fetch();$first=seFirst($kind,$id);
 return $r&&$r['owner']===$u['id']&&$r['state']==='published'&&$first>0&&time()<$first+5400;
}
function seMap(string $draft,string $owner): ?array {seSchema();return socialQuery('SELECT * FROM social_edit_drafts WHERE draft=? AND owner=?',[$draft,$owner])->fetch()?:null;}
function seGuard(array $u,array $edit): void {
 if(!seAllowed($u,$edit['kind'],$edit['target']))throw new DomainException('edit_expired');
 $current=networkLocale($edit['kind'],$edit['target'],$edit['language']);
 if(!$current||(int)$current['source_version']!==(int)$edit['base_version'])throw new DomainException('conflict');
}
function seStart(array $u,string $kind,string $id): string {
 scSchema();seSchema();if(!seAllowed($u,$kind,$id))throw new DomainException('edit_expired');
 $first=socialQuery('SELECT language FROM network_versions WHERE kind=? AND target=? ORDER BY id LIMIT 1',[$kind,$id])->fetchColumn();$l=networkLocale($kind,$id,$first);if(!$l)throw new DomainException('not_found');
 $p=json_decode($l['document'],true);$d=$p;
 if($kind==='trip'){$d['kind']='trip';$d['title']=$p['public_title']??'';$d['body']=$p['summary']??'';$d['place_ids']=array_column($p['items'],'id');$p['items']=sxItems($p['items']);$d['route_stop_ids']=array_column($p['items'],'item_id');$d['connections']=rxConnections($p['items'],$p['connections']??[],$p['travel_mode']??'walk');$d['visits']=[];$d['optional_ids']=[];foreach($p['items'] as $item){$d['visits'][$item['item_id']]=$item['stay_minutes']??null;if(!empty($item['optional']))$d['optional_ids'][]=$item['item_id'];}}
 $d['language']=$first;$d['audience']=socialQuery('SELECT audience FROM social_audiences WHERE kind=? AND target=?',[$kind,$id])->fetchColumn()?:'public';
 $draft=bin2hex(random_bytes(16));socialDb()->beginTransaction();try{
 socialQuery('INSERT INTO social_drafts(id,owner,kind,document,updated) VALUES(?,?,?,?,?)',[$draft,$u['id'],$d['kind'],json_encode($d,JSON_UNESCAPED_UNICODE),time()]);
 socialQuery('INSERT INTO social_edit_drafts VALUES(?,?,?,?,?,?)',[$draft,$u['id'],$kind,$id,$first,$l['source_version']]);socialDb()->commit();
 }catch(Throwable $e){socialDb()->rollBack();throw $e;}
 return languageUrl('/community/create/?draft='.$draft.'&content_lang='.rawurlencode($first),siteLanguage());
}
function seSave(array $u,array $edit,array $p,array $places): string {
 seGuard($u,$edit);$id=$edit['target'];$kind=$edit['kind'];$previous=json_decode(networkLocale($kind,$id,$edit['language'])['document'],true);
 $p['language']=$edit['language'];
 if($kind==='trip'){
 $items=[];foreach($places as $place)$items[]=['item_id'=>$place['item_id'],'id'=>$place['id'],'title'=>$place['title'],'day'=>0,'type'=>'experience','note'=>'','booked'=>false,'optional'=>in_array($place['item_id'],$p['optional_ids'],true),'stay_minutes'=>$p['visits'][$place['item_id']]??null,'mode'=>$p['travel_mode']];
 $plan=photoMetadata($p)+['items'=>$items,'city'=>$places[0]['city'],'country'=>$places[0]['country']??'','days'=>0,'language'=>$p['language'],'connections'=>$p['connections'],'route_facts'=>$p['route_facts'],'route_status'=>$p['route_status'],'travel_mode'=>$p['travel_mode'],'estimated_minutes'=>$p['estimated_minutes']];
 $out=socialPublicPlan($plan);if($out['pending'])throw new DomainException('unapproved_stop');$doc=$out['plan'];$doc['public_title']=$p['title'];$doc['summary']=$p['body'];$doc['photos']=$p['photos'];
 foreach(['parent_route_id','parent_version_id','place_media'] as $key)if(isset($previous[$key]))$doc[$key]=$previous[$key];networkValidateRoute($doc);
 socialQuery('UPDATE member_trips SET public_plan=?,revision=revision+1,updated=? WHERE id=? AND owner=?',[json_encode($doc,JSON_UNESCAPED_UNICODE),time(),$id,$u['id']]);
 foreach($p['photos'] as $im)socialQuery('INSERT OR IGNORE INTO member_trip_images VALUES(?,?,?)',[$im,$id,time()]);
 }else{$doc=$p;foreach(['place_media','catalog_language'] as $key)if(isset($previous[$key]))$doc[$key]=$previous[$key];socialQuery('UPDATE network_posts SET city=? WHERE id=?',[$p['city'],$id]);foreach($p['photos'] as $im)socialQuery('INSERT OR IGNORE INTO network_images VALUES(?,?,?)',[$im,$id,$u['id']]);}
 networkApproveLocale($kind,$id,$edit['language'],$doc,'edit:'.$u['id']);
 return languageUrl('/community/'.($kind==='trip'?'trips':'posts').'/'.$id.'/?content_lang='.rawurlencode($edit['language']),siteLanguage());
}
function seControls(string $kind,string $id): void {
 if(!in_array($kind,['post','trip'],true))return;$u=socialUser();$count=(int)socialQuery('SELECT COUNT(*) FROM network_versions WHERE kind=? AND target=? AND language=?',[$kind,$id,networkContentLanguage()])->fetchColumn();
 echo '<div class="se-controls">';if(qaAdminId()&&!qaActive()&&!amViewing())echo '<a class="button secondary" href="'.e(languageUrl('/KNISA/content/?kind='.$kind.'&id='.$id.'&content_lang='.networkContentLanguage(),siteLanguage())).'">'.e(qaT('manage_post')).'</a>';if($u&&seAllowed($u,$kind,$id))echo '<form method="post">'.csrf().'<input type="hidden" name="network_action" value="edit_start"><input type="hidden" name="kind" value="'.e($kind).'"><input type="hidden" name="target" value="'.e($id).'"><button class="button secondary">'.e(seT('edit')).'</button></form>';
 if($count>1)echo '<a href="'.e(networkUrl($kind,$id).'&history=1').'">'.e(seT('history')).' ('.$count.')</a>';echo '</div>';
}
function seHistory(string $kind,string $id): void {
 $target=networkTarget($kind,$id);if(!$target){networkUnavailable();return;}
 $rows=socialQuery('SELECT * FROM network_versions WHERE kind=? AND target=? AND language=? ORDER BY id',[$kind,$id,networkContentLanguage()])->fetchAll();
 echo '<h1>'.e(seT('history')).'</h1><p>'.e(seT('history_note')).'</p><a href="'.e(networkUrl($kind,$id)).'">'.e(seT('current')).'</a><nav class="se-versions">';$chosen=(int)($_GET['version']??0);$selected=null;
 foreach($rows as $i=>$row){$number=$i+1;echo '<a href="'.e(networkUrl($kind,$id).'&history=1&version='.$row['id']).'">'.e(seT('version')).' '.$number.' · '.e(gmdate('Y-m-d H:i:s',$row['created'])).' UTC</a>';if((int)$row['id']===$chosen||(!$chosen&&$i===count($rows)-1))$selected=$row;}
 echo '</nav>';if(!$selected){networkUnavailable();return;}
 scCard($selected+['owner'=>$target['owner'],'updated'=>$selected['created'],'state'=>'published','_history'=>true],true);
}
