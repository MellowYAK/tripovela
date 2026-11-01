<?php
// Collection notes belong to the publication, never to canonical place records.
function plcTips($raw,array $ids): array {
 if(is_string($raw))$raw=json_decode($raw,true);
 if(!is_array($raw)||count($raw)>30)throw new DomainException('invalid');
 $out=[];foreach($raw as $id=>$text){if(!is_string($text))throw new DomainException('invalid');if(in_array($id,$ids,true)&&trim($text)!=='')$out[$id]=socialString($text,1000);}
 return $out;
}
function plcLabels(): array {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/place-collection-labels.json'),true);return $all[siteLanguage()]??$all['en'];}
function plcT(string $key,int $n=0): string {return str_replace('{n}',(string)$n,plcLabels()[$key]??$key);}
function plcRender(array $p,array $places,string $url,bool $full): void {
 echo '<section class="plc-collection"><h2 dir="auto"><a href="'.e($url).'">'.e($p['title']??'').'</a></h2>';
 if(!empty($p['photos']))rsGallery(['/community/media/'.$p['photos'][0].'/'],$p['title']??'',false);
 if(!empty($p['body'])){echo '<p dir="auto" data-user-content>'.saRichText(mb_strimwidth($p['body'],0,240,'…','UTF-8')).'</p>';if(mb_strwidth($p['body'],'UTF-8')>240)echo '<details class="plc-description"><summary>'.e(sxT('read_more')).'</summary><div dir="auto" data-user-content>'.saRichText($p['body']).'</div></details>';}
 echo '<button class="button plc-choose" data-sc-add="'.e(json_encode(array_map(fn($x)=>array_intersect_key($x,array_flip(['id','title','city','image'])),$places),JSON_UNESCAPED_UNICODE)).'">'.e(plcT('choose')).'</button>';
 echo '<div id="collection-places" class="plc-places">';
 foreach($full?$places:array_slice($places,0,3) as $place){echo '<article class="plc-place">';
  echo '<div class="plc-picture">';if(!empty($place['image'])){
   echo '<a href="'.e($place['url']).'"><img src="'.e($place['image']).'" alt="'.e($place['title']).'" loading="lazy"></a>';
   $disclosure=$place['image_disclosure']??[];$inherited=$disclosure['inherited_photo_credits']??[];
   $ai=!empty($place['photo_illustration'])||!empty($disclosure['photo_illustration']);foreach($inherited as $credit)$ai=$ai||!empty($credit['photo_illustration']);if($ai)echo photoAiBadge();
   echo '<details class="plc-image-info"><summary>'.e(plcT('image_details')).'</summary><small>'.e(plcT('library')).'</small>';
   if($disclosure)photoDisclosure(array_replace($disclosure,['photo_illustration'=>false]));foreach($inherited as $credit)photoDisclosure(array_replace($credit,['photo'=>'inherited','photo_illustration'=>false]));
   if(!empty($place['credit']))echo '<small dir="auto">'.e($place['credit']).'</small>';echo '</details>';
  }else echo '<span class="plc-placeholder">'.scIcon('place').'</span>';echo '</div>';
  echo '<div class="plc-details"><h3 dir="auto"><a href="'.e($place['url']).'">'.e($place['title']).'</a></h3><small>'.e(activityLabel(tripDestinations()[$place['city']]['labels']??[$place['city'],$place['city'],$place['city']]).(!empty($place['country'])?' · '.uxCountryName($place['country']):'')).'</small>';
  if(!empty($p['place_tips'][$place['id']]))echo '<div class="plc-tip"><strong>'.e(plcT('author_tip')).'</strong><p dir="auto">'.saRichText($p['place_tips'][$place['id']]).'</p></div>';
  echo '<a class="plc-view" href="'.e($place['url']).'">'.e(plcT('view')).' →</a></div>';networkButton('save','catalog',$place['id'],plcT('save_place'));echo '</article>';
 }echo '</div>';if(!$full&&count($places)>3)echo '<a class="plc-all" href="'.e($url).'#collection-places">'.e(plcT('all',count($places))).' →</a>';
 if(!empty($p['photos']))rsGallery(array_map(fn($id)=>'/community/media/'.$id.'/',array_slice($p['photos'],1)),$p['title']??'',false);
 echo '</section>';
}
