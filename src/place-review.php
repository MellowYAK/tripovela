<?php
function prT(string $key): string {static $labels;$labels??=json_decode(file_get_contents(ROOT.'/content/place-review-labels.json'),true);return $labels[siteLanguage()][$key]??$labels['en'][$key];}
function prSubmission(string $id): array {
 $r=socialQuery("SELECT c.*,m.handle FROM place_contributions c JOIN members m ON m.id=c.owner WHERE c.id=? AND c.state='published' AND m.state='active'",[$id])->fetch();
 if(!$r)throw new DomainException('not_found');$place=cpCanonical($r['place']);
 if(str_starts_with($place,'community-')){$p=socialQuery("SELECT document FROM member_places WHERE id=? AND state='approved'",[substr($place,10)])->fetchColumn();if(!$p)throw new DomainException('not_found');$base=json_decode($p,true);$edition=networkLocale('place',substr($place,10),$r['language']);if($edition)$base=json_decode($edition['document'],true);$base=crPlaceFields($place,$r['language'],$base);}
 else $base=crPlaceBase($place,$r['language']);
 $doc=json_decode($r['document'],true);$changes=[];
 foreach(['title','address','hours','price','description','directions','photo_caption','place_type','cost'] as $key){$value=$doc[$key]??'';if(!is_string($value)||trim($value)===''||($key==='cost'&&$value==='unknown')||($key==='place_type'&&$value==='other'))continue;if($value!==($base[$key]??''))$changes[$key]=['before'=>$base[$key]??'','after'=>$value];}
 if(isset($doc['latitude'],$doc['longitude'])){$value=[networkCoordinate($doc['latitude'],90),networkCoordinate($doc['longitude'],180)];if($value!==crValue($base,'location'))$changes['location']=['before'=>crValue($base,'location'),'after'=>$value];}
 $photos=[];foreach(socialQuery('SELECT p.* FROM place_contribution_images i JOIN community_photo_review p ON p.id=i.id WHERE i.contribution=?',[$id]) as $photo)$photos[]=$photo;
 $decision=socialQuery("SELECT action FROM community_decisions WHERE kind='place_submission' AND target=? ORDER BY id DESC LIMIT 1",[$id])->fetchColumn()?:'';
 $version=hash('sha256',json_encode([$r['document'],$base,$photos,$decision]));
 return compact('r','place','base','doc','changes','photos','decision','version');
}
function prDecide(string $id,string $action,string $version,string $note): void {
 if(!crAdmin())throw new DomainException('not_found');prApply($id,$action,$version,$note,crActor());
}
function prApply(string $id,string $action,string $version,string $note,string $actor): void {
 if(!in_array($action,['approve','reject','needs_info'],true))throw new DomainException('invalid');
 socialDb()->beginTransaction();try{$s=prSubmission($id);if(!hash_equals($s['version'],$version)||in_array($s['decision'],['approve','reject'],true))throw new DomainException('conflict');
 // Authenticated administrators may review their own community submissions; actor is audited.
 if($action==='approve')foreach($s['changes'] as $field=>$change){
  $pid=bin2hex(random_bytes(16));$before=json_encode($change['before'],JSON_UNESCAPED_UNICODE);$after=json_encode($change['after'],JSON_UNESCAPED_UNICODE);
  socialQuery("INSERT INTO community_corrections(id,place,owner,language,field,previous,value,explanation,state,created,reviewer,note,base_version) VALUES(?,?,?,?,?,?,?,?,'approved',?,?,?,?)",[$pid,$s['place'],$s['r']['owner'],$s['r']['language'],$field,$before,$after,'Contribution '.$id,time(),$actor,$note,crFieldVersion($s['place'],$s['r']['language'],$field,$change['before'])]);
  socialQuery('INSERT INTO community_place_fields VALUES(?,?,?,?,?) ON CONFLICT(place,language,field) DO UPDATE SET value=excluded.value,proposal=excluded.proposal',[$s['place'],$s['r']['language'],$field,$after,$pid]);
  crAudit($actor,'correction',$pid,'approve','pending','approved',$note);
 }
 foreach($s['photos'] as $photo)if(in_array($photo['state'],['pending','needs_info'],true)){$next=['approve'=>'approved','reject'=>'rejected','needs_info'=>'needs_info'][$action];socialQuery('UPDATE community_photo_review SET state=? WHERE id=?',[$next,$photo['id']]);crAudit($actor,'photo',$photo['id'],$action,$photo['state'],$next,$note);}
 crAudit($actor,'place_submission',$id,$action,$s['base'],['changes'=>$s['changes'],'photos'=>array_column($s['photos'],'id')],$note);if($actor!=='system:testing-auto-review')crNotifyReview($s['r']['owner'],'place_submission',$id,$action);socialDb()->commit();scCatalogue(true);
 }catch(Throwable $e){if(socialDb()->inTransaction())socialDb()->rollBack();throw $e;}
}
function prRender(): void {
 if(!crAdmin())return;$status=in_array($_GET['status']??'',['approve','reject','needs_info'],true)?$_GET['status']:'';echo '<nav class="pr-tabs">';foreach([''=>'pending','approve'=>'approved','reject'=>'rejected','needs_info'=>'needs_info'] as $value=>$label)echo '<a href="?filter=places&amp;status='.e($value).'">'.e(crT($label)).'</a>';echo '</nav>';
 echo '<link rel="stylesheet" href="/assets/place-review.css?v='.filemtime(ROOT.'/public/assets/place-review.css').'"><p>'.e(prT('intro')).'</p>';$count=0;
 foreach(socialQuery("SELECT c.id FROM place_contributions c WHERE c.state='published' AND COALESCE((SELECT d.action FROM community_decisions d WHERE d.kind='place_submission' AND d.target=c.id ORDER BY d.id DESC LIMIT 1),'')=? ORDER BY c.created DESC LIMIT 100",[$status]) as $row){try{$s=prSubmission($row['id']);}catch(DomainException $e){continue;}
  $pending=array_filter($s['photos'],fn($p)=>in_array($p['state'],['pending','needs_info'],true));if(!$status&&!$s['changes']&&!$pending)continue;$count++;
  echo '<article class="social-card pr-card"><header><h2 dir="auto">'.e($s['base']['title']??$s['doc']['title']??$s['place']).'</h2><p><a href="'.e(languageUrl('/community/members/'.$s['r']['handle'].'/',siteLanguage())).'">@'.e($s['r']['handle']).'</a> · '.e(gmdate('Y-m-d H:i',$s['r']['created'])).' UTC · '.e($s['r']['language']).'</p></header>';
  echo '<div class="pr-photos">';foreach($s['photos'] as $p)echo '<figure><a href="/community/media/'.e($p['id']).'/" target="_blank" rel="noopener"><img src="/community/media/'.e($p['id']).'/" alt="'.e(cpT('photos')).'" loading="lazy"></a><figcaption>'.e(crT($p['state'])).'</figcaption></figure>';echo '</div>';
  foreach(['photo_author','photo_source','photo_license'] as $key)if(!empty($s['doc'][$key]))echo '<p class="pr-credit">'.e(cpT($key)).': <span dir="auto">'.e($s['doc'][$key]).'</span></p>';
  echo '<div class="pr-changes">';foreach($s['changes'] as $field=>$v){$display=fn($x)=>is_array($x)?implode(', ',array_map(fn($z)=>$z===null?'—':(string)$z,$x)):(string)$x;echo '<section><h3>'.e($field==='location'?crT('location'):cpT($field)).'</h3><div class="pr-compare"><div><small>'.e(prT('before')).'</small><p dir="auto">'.e($display($v['before'])?:'—').'</p></div><div><small>'.e(prT('after')).'</small><p dir="auto">'.e($display($v['after'])).'</p></div></div></section>';}echo '</div>';
  if(in_array($status,['approve','reject'],true)){echo '<p>'.e(crT($status==='approve'?'approved':'rejected')).'</p></article>';continue;}echo '<form method="post">'.csrf().'<input type="hidden" name="kind" value="place_submission"><input type="hidden" name="id" value="'.e($row['id']).'"><input type="hidden" name="version" value="'.e($s['version']).'"><details><summary>'.e(prT('note')).'</summary><textarea name="note" maxlength="1000" aria-label="'.e(prT('note')).'"></textarea></details><div class="pr-actions"><button name="decision" value="approve" class="button">'.e(prT('approve')).'</button><button name="decision" value="needs_info" class="button secondary">'.e(prT('needs_info')).'</button><button name="decision" value="reject" class="button secondary">'.e(prT('reject')).'</button></div></form></article>';
 }if(!$count)echo '<p class="social-card">'.e(prT('empty')).'</p>';
}
