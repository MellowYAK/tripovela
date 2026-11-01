<?php
function rsT(string $key,int $n=0): string {static $labels;$labels??=json_decode(file_get_contents(ROOT.'/content/route-social-labels.json'),true);return str_replace('{n}',(string)$n,$labels[siteLanguage()][$key]??$labels['en'][$key]??$key);}
function rsGallery(array $photos,string $title,bool $full=false): void {
 if(!$photos)return;$data=e(json_encode(array_values($photos),JSON_UNESCAPED_UNICODE));
 echo '<div class="rs-gallery '.($full?'rs-hero':('rs-gallery-'.min(3,count($photos)))).'">';
 foreach(array_slice($photos,0,$full?1:3) as $i=>$photo){echo '<button type="button" class="rs-gallery-item" data-rs-gallery="'.$data.'" data-rs-close="'.e(rxT('close')).'" data-rs-previous="'.e(rsT('gallery_previous')).'" data-rs-next="'.e(rsT('gallery_next')).'" data-rs-index="'.$i.'" aria-label="'.e(rsT('gallery',count($photos))).'"><img src="'.e($photo).'" alt="'.e($title).'" loading="lazy">';if($full)echo '<span>'.e(rsT('gallery',count($photos))).'</span>';elseif($i===2&&count($photos)>3)echo '<span>+'.(count($photos)-2).'</span>';echo '</button>';}
 echo '</div>';
}
function rsTime(array $p,bool $full=false): string {
 $t=rxTotals($p['items']??[],rxConnections($p['items']??[],$p['connections']??[],$p['travel_mode']??'walk'));
 $text=rxSummary($p);
 if($t['known_legs']||$t['known_visits_count'])$text.=' · '.rxT('author');
 if($full&&is_numeric($p['estimated_minutes']??null))$text=rsT('overall').': '.$p['estimated_minutes'].' '.rxT('minutes').($text?' — '.$text:'');
 return $text;
}
function rsStop(array $stop,array $catalog): array {return $catalog[$stop['id']]??['id'=>$stop['id'],'title'=>$stop['title']??'','image'=>'','url'=>'','cost'=>'unknown'];}
function rsPlaceContext(string $id): ?array {
 // The relationship is written once on submission, never inferred from another member's route.
 $row=socialQuery('SELECT route FROM community_place_origins WHERE place=?',[$id])->fetch();$origin=(bool)$row;
 if(!$row&&str_starts_with($id,'community-')){
  // Legacy records have no creation-origin field. A same-author public snapshot proves
  // membership only, so label this 'Stop in route', never claim it was created there.
  $row=socialQuery("SELECT t.id route FROM member_trips t JOIN member_places p ON p.owner=t.owner WHERE p.id=? AND t.public_plan IS NOT NULL AND t.state='published' AND EXISTS(SELECT 1 FROM json_each(t.public_plan,'$.items') WHERE json_extract(value,'$.id')=?) ORDER BY (SELECT MIN(created) FROM network_versions WHERE kind='trip' AND target=t.id),t.id LIMIT 1",[substr($id,10),$id])->fetch();
 }if(!$row)return null;
 $route=socialPublicTrip($row['route']);if(!$route)return null;$doc=json_decode($route['public_plan'],true);
 if(!in_array($id,array_column($doc['items']??[],'id'),true))return null;
 return ['origin'=>$origin,'url'=>networkUrl('trip',$row['route']),'title'=>$doc['public_title']??$route['title']];
}
function rsAddedPlace(array $r,array $p,array $places,string $url): void {
 $id=$r['target'];$place=$places[0]??['id'=>'community-'.$id,'title'=>$p['title']??'','url'=>$url,'image'=>'','summary'=>$p['description']??''];$context=rsPlaceContext($place['id']);
 echo '<article class="network-card rs-added" data-feed-item="place:'.e($id).'" data-content-language="'.e($r['language']).'">';$author=networkProfile($r['owner']);echo '<div class="rs-added-author">'.networkAvatar($author).'<div><a href="'.e(languageUrl('/community/members/'.$author['handle'].'/',siteLanguage())).'"><strong>'.e($author['name']?:$author['handle']).'</strong></a> · '.e(rsT('added_action')).'<small>'.gmdate('d.m.Y',(int)$r['updated']).'</small></div></div>';
 echo '<div class="rs-added-body">';
 if(!empty($place['image']))echo '<img class="rs-added-photo" src="'.e($place['image']).'" alt="" loading="lazy">';else echo '<span class="rs-place-icon">'.scIcon('place').'</span>';
 echo '<div class="rs-added-copy"><h2 dir="auto"><a href="'.e($place['url']).'">'.e($place['title']).'</a></h2><span class="rs-added-tag">'.e(rsT('added')).'</span>';
 if(!empty($place['summary']))echo '<p dir="auto">'.e(mb_strimwidth($place['summary'],0,150,'…','UTF-8')).'</p>';
 if($context)echo '<p>'.e(rsT($context['origin']?'context':'included')).' <a href="'.e($context['url']).'"><bdi>'.e($context['title']).'</bdi></a></p>';
 echo '</div><div class="rs-added-controls">';if($context)echo '<a class="button secondary" href="'.e($context['url']).'">'.e(rxT('view')).'</a>';networkButton('save','catalog',$place['id'],scText('Save place','שמירת מקום','Сохранить место'));echo '</div></div>';
 if(!empty($place['image'])){photoDisclosure($place['image_disclosure']??$p);if(!empty($p['photo_credit']))echo '<small class="sc-credit" dir="auto">'.e($p['photo_credit']).'</small>';}
 echo '<div class="network-actions">';
 networkButton('like','place',$id,'♡ '.nt('like'));echo '<a class="network-action" href="'.e($url).'#comments">'.scIcon('question').'<span class="network-action-label">'.e(nt('comment')).'</span>'.'</a><button class="network-action" data-network-share="'.e($url).'">'.scIcon('share').'<span class="network-action-label">'.e(nt('share')).'</span>'.'</button><button type="button" class="rs-suggest" data-cr-propose="'.e($place['id']).'">'.e(rsT('suggest')).'</button></div></article>';
}
