<?php
require_once ROOT.'/views/route-reader.php';
require_once ROOT.'/views/trip-integrated.php';
require_once ROOT.'/views/route-social.php';
function scCommentFields(): string {
 $html='<details><summary>＋ '.e(scText('Attach places or routes','צירוף מקומות או מסלולים','Прикрепить места или маршруты')).'</summary>';
 foreach(['place','trip'] as $type){$selected=[];if(($_POST['network_action']??'')==='comment')foreach((array)($_POST[$type==='place'?'linked_places':'linked_routes']??[]) as $id){if(!is_string($id))continue;if($type==='place'){$p=scCatalogue()[$id]??null;if($p)$selected[]=['id'=>$id,'title'=>$p['title']];}elseif($source=revAttachableRoute($id)){$doc=json_decode($source['public_plan'],true);$selected[]=['id'=>$id,'title'=>$doc['public_title']??$doc['title']??''];}}
 $html.='<div data-comment-attachments="'.$type.'" data-limit="'.revAttachmentLimits()[$type].'" data-selected="'.e(json_encode($selected,JSON_UNESCAPED_UNICODE)).'"><label>'.e(publicationLabel($type)).'<input type="search" autocomplete="off" maxlength="100" placeholder="'.e(nt('search')).'"></label><small>'.e(str_replace('{count}',(string)revAttachmentLimits()[$type],revT($type==='trip'?'routes_limit':'places_limit'))).'</small><div data-attachment-selected></div><p role="status"></p><div data-attachment-results></div></div>';}
 return $html.'<label>'.e(scText('Add photos','הוספת תמונות','Добавить фото')).'<input type="file" name="photo[]" multiple accept="image/jpeg,image/png,image/webp"></label><label><input type="checkbox" name="rights">'.e(nt('rights')).'</label></details>';
}
function scPlaceTile(array $place,bool $actions=false): void {
 echo '<div class="sc-place-tile"><button type="button" class="cp-open" data-cp-place="'.e($place['id']).'">'.e(cpT('place_details')).'</button>';if(!empty($place['image']))echo '<img src="'.e($place['image']).'" alt="" loading="lazy">';echo '<div><a href="'.e($place['url']).'"><strong>'.e($place['title']).'</strong></a>'.(!empty($place['photo_illustration'])?photoAiBadge():'').'<small>'.e(activityLabel(tripDestinations()[$place['city']]['labels']??[$place['city'],$place['city'],$place['city']])).' · '.e(nt($place['cost']??'unknown')).'</small></div>';
 networkButton('save','catalog',$place['id'],scText('Save place','שמירת מקום','Сохранить место'));if($actions)echo '<button data-sc-add="'.e(json_encode([array_intersect_key($place,array_flip(['id','title','city']))],JSON_UNESCAPED_UNICODE)).'">＋ '.e(scText('Add to my trip','הוספה לטיול','Добавить в поездку')).'</button>';echo '</div>';
}
function scDirections(array $place): string {
 return rxDirections($place)?:'<span class="rx-help">'.e(rxT('location_missing')).'</span>';
}
function scCard(array $r,bool $full=false): void {
 if($r['kind']==='share'){tsFeedCard($r);return;}
 if(crHidden($r['kind'],$r['target']))return;$history=!empty($r['_history']);$public=!$history&&($r['state']??'published')==='published';$previous=$GLOBALS['network_card_language']??null;$GLOBALS['network_card_language']=$r['language'];
 $p=networkResolvePlan(json_decode($r['document'],true));crPhotoNotice($p['photos']??[]);$p=crDocument($p,($r['owner']??'')===(socialUser()['id']??''));$kind=$r['kind'];$id=$r['target'];$type=$kind==='trip'?'trip':($p['kind']??$kind);$url=networkUrl($kind,$id);$catalog=scCatalogue();if($full&&($r['owner']??'')===(socialUser()['id']??''))$catalog+=crPendingPlaces(socialUser());$places=[];
 // Explicitly linked library places may have a different source language from the post.
 // Resolve only those missing references; this does not add other-language posts to the feed.
 if(!empty($p['catalog_language'])&&$p['catalog_language']!==$r['language']){
  $cardLanguage=$GLOBALS['network_card_language'];$uiLanguage=$_GET['lang']??null;
  try{$GLOBALS['network_card_language']=$p['catalog_language'];$_GET['lang']=$p['catalog_language'];$source=scCatalogue();}
  finally{$GLOBALS['network_card_language']=$cardLanguage;if($uiLanguage===null)unset($_GET['lang']);else $_GET['lang']=$uiLanguage;}
  foreach(array_unique(array_merge($p['place_ids']??[],array_column($p['items']??[],'id'))) as $pid)if(!isset($catalog[$pid])&&isset($source[$pid]))$catalog[$pid]=$source[$pid];
 }
 // Publication-specific illustrations reuse canonical places, without duplicating the catalogue.
 foreach($p['place_media']??[] as $pid=>$media){
  if(!isset($catalog[$pid])||!is_array($media))continue;$photo=$media['photo']??'';
  if(!is_string($photo)||!preg_match('/^[a-f0-9]{32}$/D',$photo)||!in_array($photo,$p['photos']??[],true))continue;
  $catalog[$pid]['image']='/community/media/'.$photo.'/';$catalog[$pid]['photo_illustration']=!empty($media['illustration']);
  if(is_string($media['title']??null)&&$media['title']!=='')$catalog[$pid]['title']=$media['title'];
 }
 foreach($p['place_ids']??[] as $pid)if(isset($catalog[$pid]))$places[]=$catalog[$pid];
 if($kind==='place'&&!$places&&isset($catalog['community-'.$id]))$places[]=$catalog['community-'.$id];
 if($history)foreach($p['items']??[] as $stop)if(isset($catalog[$stop['id']]))$catalog[$stop['id']]['title']=$stop['title']??$catalog[$stop['id']]['title'];
 if($kind==='place'&&!$full){rsAddedPlace($r,$p,$places,$url);if($previous===null)unset($GLOBALS['network_card_language']);else $GLOBALS['network_card_language']=$previous;return;}
 $title=$p['public_title']??$p['title']??'';$body=$p['body']??$p['summary']??$p['description']??'';$city=tdDestinationText($p);
 $label=publicationLabel($type);
 if($type==='place'&&$kind==='post')$label=plcT('count',count($places));
 if($full&&$type==='trip')echo '<a class="rs-back" href="'.e(languageUrl('/community/',siteLanguage())).'">'.e(rsT('back')).'</a>';
 echo '<article class="network-card sc-card sc-card-'.e($type).($full?' sc-full':'').'" data-feed-item="'.e($kind.':'.$id).'" data-content-language="'.e($r['language']).'">';networkAuthor($r['owner'],(int)$r['updated']);if($full&&$type==='trip')echo '<h1 dir="auto">'.e($title).'</h1>';if($kind==='post')sxCredits($id);
 $audience=socialQuery('SELECT audience FROM social_audiences WHERE kind=? AND target=?',[$kind,$id])->fetchColumn()?:'public';
 echo '<div class="sa-audience-label">'.e(saText($audience)).'</div>';
 echo '<div class="sc-card-meta"><span class="sc-type">'.scIcon(publicationTypes()[$type]['icon']??'story').e($label).'</span>'.($city?'<span>'.e($city).'</span>':'').'<span class="sc-original" title="'.e(nt('original_language')).'">'.e(networkContentLanguages()[$r['language']]??$r['language']).'</span></div>';
 echo '<div class="network-card-text" lang="'.e($r['language']).'" dir="auto">';
 if($title&&$type!=='place'&&$type!=='trip')echo '<h2><a href="'.e($url).'">'.e($title).'</a></h2>';
 if($type!=='place'&&$type!=='trip'&&$body)echo '<p data-user-content>'.saRichText($full?$body:mb_strimwidth($body,0,240,'…','UTF-8')).'</p>';echo '</div>';
 $photos=array_map(fn($x)=>'/community/media/'.$x.'/', $p['photos']??[]);
 if($type==='place'&&$places){plcRender($p,$places,$url,$full);}
 elseif($type==='trip'){
  if($full)tdPublication($p,$catalog,$photos,$id,$url,$public);else scRoutePreview($p,$catalog,$photos,$id,$url,$public,$title);
 }else{
  if($photos){echo '<div class="sc-photo-grid sc-photo-count-'.min(3,count($photos)).'">';foreach(array_slice($photos,0,$full?5:2) as $photo)echo '<a href="'.e($url).'"><img src="'.e($photo).'" loading="lazy" alt="'.e($title?:$label).'"></a>';echo '</div>';}
  if($places&&($full||$type!=='question')){echo '<section class="sc-linked-places"><h3>'.e(scText('Places in this story','מקומות בחוויה','Места в рассказе')).'</h3><div>';foreach(($full?$places:array_slice($places,0,2)) as $place)scPlaceTile($place);echo '<button class="button secondary" data-sc-add="'.e(json_encode(array_map(fn($x)=>array_intersect_key($x,array_flip(['id','title','city'])),$places),JSON_UNESCAPED_UNICODE)).'">＋ '.e(scText('Add places to my trip','הוספת מקומות לטיול','Добавить места в поездку')).'</button></div></section>';}
 }
 foreach($p['linked_routes']??[] as $rid){$linked=socialPublicTrip($rid);if($linked)echo '<p><a href="'.e(networkUrl('trip',$rid)).'">'.scIcon('route').e($linked['title']??nt('trip')).'</a></p>';}
 photoDisclosure($p);
 if(!empty($p['photo_credit']))echo '<small class="sc-credit">'.e($p['photo_credit']).'</small>';
 if($type==='question')echo '<div class="sc-question-buttons"><a class="button" href="'.e($url).'#answer-editor">'.e(scText('Answer question','מענה לשאלה','Ответить на вопрос')).'</a><a class="button secondary" href="'.e($url).'#comments">'.e(scText('View answers','צפייה בתשובות','Посмотреть ответы')).'</a></div>';
 if($public){seControls($kind,$id);echo '<div class="network-actions">';networkButton('like',$kind,$id,'♡ '.nt('like'));echo '<a class="network-action" href="'.e($url).'#comments">'.scIcon('question').'<span class="network-action-label">'.e(nt('comment')).'</span>'.'</a>';networkButton('save',$kind,$id,'♧ '.scText('Save post','שמירת פרסום','Сохранить'));echo '<button class="network-action" data-network-share="'.e($url).'">'.scIcon('share').'<span class="network-action-label">'.e(nt('share')).'</span>'.'</button></div>';
 if(socialUser()){echo '<details class="network-more"><summary aria-label="'.e(nt('more')).'">⋯</summary>';networkButton('hide',$kind,$id,nt('hide'),true);if($r['owner']!==socialUser()['id'])networkButton('block','member',$r['owner'],nt('block'),true);if($r['owner']!==socialUser()['id'])echo '<form method="post">'.csrf().networkContentLanguageField().'<input type="hidden" name="network_action" value="report"><input type="hidden" name="kind" value="'.e($kind).'"><input type="hidden" name="target" value="'.e($id).'"><label>'.e(nt('report')).'<textarea name="reason" required minlength="10" maxlength="1000"></textarea></label><button>'.e(nt('report')).'</button></form>';echo '</details>';}
 }echo '</article>';if($previous===null)unset($GLOBALS['network_card_language']);else $GLOBALS['network_card_language']=$previous;
}

function scRoutePreview(array $p,array $catalog,array $photos,string $id,string $url,bool $public,string $title): void {
 $items=$p['items']??[];$totals=rxTotals($items,rxConnections($items,$p['connections']??[],$p['travel_mode']??'walk'));
 echo '<div class="rs-route-preview"><h2 dir="auto"><a href="'.e($url).'">'.e($title).'</a></h2>';
 $body=$p['body']??$p['summary']??'';if($body)echo '<p class="rs-excerpt" dir="auto">'.saRichText(mb_strimwidth($body,0,260,'…','UTF-8')).' <a href="'.e($url).'">'.e(nt('read')).'</a></p>';
 rsGallery($photos,$title);echo '<div class="rx-meta"><span>'.count($items).' '.e(rxT('stops')).'</span><span>'.e(count($totals['modes'])>1?rxT('mixed'):rxT($totals['modes'][0]??$p['travel_mode']??'walk')).'</span>';
 if($time=rsTime($p))echo '<small>'.e($time).'</small>';echo '</div><h3>'.e(rsT('places')).'</h3><ol class="rs-preview-stops">';
 foreach(array_slice($items,0,3) as $i=>$stop){$place=rsStop($stop,$catalog);echo '<li><span class="rx-number">'.($i+1).'</span><a href="'.e($place['url']?:($url.'#stop-'.($i+1))).'">';if(!empty($place['image']))echo '<img src="'.e($place['image']).'" alt="" loading="lazy">';else echo scIcon('place');echo '<bdi>'.e($place['title']).'</bdi><span aria-hidden="true">›</span></a></li>';}
 echo '</ol>';if(count($items)>3)echo '<a class="rs-all" href="'.e($url).'">'.e(rsT('all',count($items))).'</a>';
 echo '<div class="rx-route-actions"><a class="button rx-primary" href="'.e($url).'">'.e(rxT('view')).'</a>';if($public)echo '<button class="button secondary" data-social-copy="'.e($id).'">'.e(rxT('use')).'</button>';echo '</div></div>';
}
