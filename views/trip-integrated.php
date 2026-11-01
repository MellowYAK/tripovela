<?php
// Called with an already permission-checked, public/localized publication only.
function tdPublication(array $p,array $catalog,array $photos,string $id,string $url,bool $public): void {
 $items=[];$points=[];
 foreach($p['items']??[] as $n=>$stop){$place=rsStop($stop,$catalog);$loc=rxLocation($place);
  $items[]=['item_id'=>$stop['item_id']??$stop['id'],'title'=>$place['title'],'image'=>$place['image']??'','credit'=>$place['credit']??'','note'=>'','address'=>$loc['address']??'','coords'=>$loc['coords']??null,'stay_minutes'=>$stop['stay_minutes']??null];
  if($loc['coords'])$points[]=$loc+['number'=>$n+1,'item_id'=>$stop['item_id']??$stop['id']];
 }
 $snapshot=['items'=>$items,'document'=>tdPublicDocument($p,$items),'facts'=>tdKnownFacts($p),'kind'=>$p['content_kind']??'route'];
 $intro=tdIntro($snapshot);if($intro!=='')echo '<p class="td-intro" dir="auto">'.e($intro).'</p>';
 echo '<div class="td-public-facts"><span>'.count($items).' '.e(tsT('stops')).'</span>';
 if(($p['content_kind']??'route')==='route'&&isset($p['travel_mode']))echo '<span>'.e(rxT($p['travel_mode'])).'</span>';
 $totals=rxTotals($p['items']??[],rxConnections($p['items']??[],$p['connections']??[]));if(isset($totals['total']))echo '<small>'.e(tdReadableTime($totals['total']).' · '.rxT('author')).'</small>';echo '</div>';
 if(isset($totals['travel'])&&$totals['modes']===['walk'])echo '<small>'.e(strtr(json_decode(file_get_contents(ROOT.'/content/directions-labels.json'),true)[siteLanguage()]['walking'],['{m}'=>(string)$totals['travel']])).'</small>';
 $displayFacts=$snapshot['facts'];unset($snapshot['facts']['minutes'],$snapshot['facts']['duration'],$snapshot['facts']['mode']);require ROOT.'/views/trip-document-facts.php';$snapshot['facts']=$displayFacts;
 if($public){echo '<div class="ts-actions"><button class="button" data-social-copy="'.e($id).'">'.e(tsT('mine')).'</button><button class="button secondary" data-network-share="'.e($url).'">'.e(nt('share')).'</button></div>';if(!socialUser())echo '<small>'.e(json_decode(file_get_contents(ROOT.'/content/directions-labels.json'),true)[siteLanguage()]['before_copy']).'</small>';}
 if($photos)rsGallery($photos,$p['public_title']??'',true);elseif($image=array_values(array_filter(array_column($items,'image')))[0]??'')echo '<img class="ts-cover" src="'.e($image).'" alt="">';
 echo '<div class="td-public-layout"><section id="route-story" class="td-reader"><span id="route-stops"></span>';require ROOT.'/views/trip-document-reader.php';echo '</section>';
 if($points){echo '<aside><details class="td-public-map"><summary>'.e(rxT('map')).'</summary><div class="rx-map" data-map-total="'.count($items).'" data-rx-map="'.e(json_encode($points,JSON_UNESCAPED_UNICODE)).'" data-rx-collection="'.(($p['content_kind']??'route')==='collection'?'1':'0').'" role="region" aria-label="'.e(rxT('map')).'"></div><small>'.e(tsT(($p['content_kind']??'route')==='collection'?'collection_hint':'schematic')).'</small></details></aside>';}
 echo '</div>';
 if($public)echo '<div class="ts-actions"><button type="button" class="button" data-social-copy="'.e($id).'">'.e(tsT('mine')).'</button></div>';
}
