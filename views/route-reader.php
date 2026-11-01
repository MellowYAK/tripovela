<?php
function rxReader(array $p,array $catalog,bool $full,string $id,string $url,bool $public): void {
 $items=$p['items']??[];$legs=rxConnections($items,$p['connections']??[],$p['travel_mode']??'walk');$totals=rxTotals($items,$legs);$points=[];$missing=[];
 foreach($items as $i=>$stop){$place=rsStop($stop,$catalog);$loc=rxLocation($place);if($loc['coords'])$points[]=$loc+['number'=>$i+1];else $missing[]=$place;}
 echo '<section class="rx-reader rs-reader" data-rx-labels="'.e(json_encode(rxLabels(),JSON_UNESCAPED_UNICODE)).'"><div class="rx-meta"><span>'.count($items).' '.e(rxT('stops')).'</span><span>'.e(count($totals['modes'])>1?rxT('mixed'):rxT($totals['modes'][0]??$p['travel_mode']??'walk')).'</span><span>'.e(($p['route_status']??'planned')==='tried'?scText('Tried by the author','דיווח הכותב: נוסה','Автор прошёл маршрут'):rxT('planned')).'</span></div>';
 if($time=rsTime($p,true))echo '<div class="rx-summary">'.e($time).'</div>';
 if($public){echo '<div class="rx-route-actions"><button class="button rx-primary" data-social-copy="'.e($id).'">'.e(rxT('use')).'</button>';networkButton('save','trip',$id,nt('save'));echo '</div>';}
 echo '<h3 id="route-stops">'.e(rsT('places')).'</h3>';
 if($missing){echo '<div class="rs-location-note">'.e(rsT($points?'missing_some':'missing_all'));foreach($missing as $place)if(isset($catalog[$place['id']])){echo ' <button data-cr-propose="'.e($place['id']).'">'.e(rsT('suggest')).'</button>';break;}echo '</div>';}
 echo '<div class="rx-reader-layout"><div>';
 foreach($items as $i=>$stop){$place=rsStop($stop,$catalog);$loc=rxLocation($place);
  if($i>0){$l=$legs[$i-1];echo '<div class="rx-leg" data-route-connection="'.e($l['from'].'|'.$l['to']).'">'.scIcon($l['mode']).e(rxT($l['mode']).($l['minutes']!==null?' · '.$l['minutes'].' '.rxT('minutes'):' · '.rxT('unavailable')).($l['meters']!==null?' · '.rxDistance($l['meters']):'').($l['mode']==='transit'&&$l['transit_line']?' · '.rxT('transit_line').': '.$l['transit_line']:'').($l['mode']==='transit'&&$l['station_number']?' · '.rxT('station_number').': '.$l['station_number']:'').($l['source']!=='unavailable'?' · '.rxT($l['source']):'')).'</div>';}
  echo '<div class="rx-itinerary-stop" id="stop-'.($i+1).'"><div class="rx-stop"><span class="rx-number">'.($i+1).'</span>';
  if(!empty($place['image']))echo '<img src="'.e($place['image']).'" alt="" loading="lazy">';else echo '<span class="rs-stop-icon">'.scIcon('place').'</span>';
  echo '<div class="rx-stop-info"><strong><bdi>';if(!empty($place['url']))echo '<a href="'.e($place['url']).'">'.e($place['title']).'</a>';elseif(isset($catalog[$stop['id']]))echo '<button type="button" class="cp-open" data-cp-place="'.e($stop['id']).'">'.e($place['title']).'</button>';else echo e($place['title']);echo '</bdi></strong>';
  if($loc['address'])echo '<small>'.e($loc['address']).'</small>';
  if(in_array($place['cost']??'',['free','paid'],true))echo '<small>'.e(nt($place['cost'])).'</small>';if(!empty($stop['optional']))echo '<small>'.e(rxT('optional')).'</small>';
  if(!empty($place['photo_illustration']))echo photoAiBadge();echo '</div>';
  if(($stop['stay_minutes']??null)!==null)echo '<small class="rx-stay">'.e(rxT('visit').' · '.$stop['stay_minutes'].' '.rxT('minutes')).'</small>';
  echo rxDirections($place,$i?rsStop($items[$i-1],$catalog):null,$i?$legs[$i-1]['mode']:($p['travel_mode']??'walk')).'</div></div>';
 }
 echo '</div>';if($full&&$points){echo '<aside class="rx-map-panel"><h3>'.e(rxT('map')).'</h3><div class="rx-map" data-rx-map="'.e(json_encode($points,JSON_UNESCAPED_UNICODE)).'" role="region" aria-label="'.e(rxT('map')).'"></div>';if($missing)echo '<small>'.e(rxT('map_partial')).'</small>';echo '<small>'.e(rxT('map_note')).'</small></aside>';}
 echo '</div>';cpFactsDisplay($p);echo '</section>';
}
