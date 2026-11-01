<?php
declare(strict_types=1);
require_once __DIR__.'/catalogue-read-batch.php';
// Explore is a read model: neither discovery nor a provider failure publishes content.
function exLabels(): array {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/explore-labels.json'),true);return $all[siteLanguage()]??$all['en'];}
function exT(string $key): string {return exLabels()[$key]??nt($key);}
function exOffer(array $item,?array $adapters=null): ?array {
 foreach($adapters??['exViatorOffer'] as $adapter){try{if($offer=$adapter($item))return $offer;}catch(Throwable $e){error_log('Explore booking adapter unavailable');}}
 return null;
}
function exViatorOffer(array $item): ?array {
 $code=$item['product']??'';if(!$code)return null;
 try {
  if(!viatorEnabled()||catalogProductInactive($code))return null;
  if(isset(providerCatalog()[$code]))$url=providerCatalogHref($code);
  elseif(isset(experienceCatalog()[$code]))$url=experienceHref($code,'explore');
  else return null;
  // Local cached metadata only. No availability request or guessed price during discovery.
  return ['provider'=>'Viator','product'=>$code,'url'=>$url,'affiliate'=>true];
 }catch(Throwable $e){error_log('Explore booking adapter unavailable');return null;}
}
function exProductKey(array $item,?array $offer): string {return !empty($item['product'])?strtolower((string)($item['provider']??$offer['provider']??'viator')).':'.$item['product']:'';}
function exCatalogue(bool $batchReads=true): array {
 $batch=$batchReads?new CatalogueReadBatch():null;
 $rows=[];$destinations=tripDestinations();$products=[];$ratings=[];foreach(communityRatingDb()->query('SELECT item_id,COUNT(*) count,AVG(score) average FROM ratings GROUP BY item_id') as $rating)$ratings[$rating['item_id']]=$rating;
 foreach(scCatalogue(false,$batch) as $id=>$item){
  $item=discoveryClassify($item);
  if(($batch?$batch->canonical($id):cpCanonical($id))!==$id||($batch?$batch->hidden('catalog',$id):crHidden('catalog',$id)))continue;
  $created=0;
  if(str_starts_with($id,'community-')){
   $pid=substr($id,10);$member=$batch?($batch->places()[$pid]??null):socialQuery("SELECT p.owner,p.created FROM member_places p JOIN members m ON m.id=p.owner WHERE p.id=? AND p.state='approved' AND m.state='active'",[$pid])->fetch();
   if(!$member||!saCanView('place',$pid,$member['owner'],'')||!saCanView('place',$pid,$member['owner'])||($batch?$batch->hidden('place',$pid):crHidden('place',$pid)))continue;
   $created=(int)$member['created'];
   $profile=$batch?$batch->profile($member['owner']):networkProfile($member['owner']);
   $item['contributor']=$profile?['id'=>$profile['id'],'avatar'=>$profile['avatar']??'','handle'=>$profile['handle'],'name'=>$profile['name']?:$profile['handle'],'url'=>languageUrl('/community/members/'.$profile['handle'].'/',siteLanguage())]:null;
  }
  $offer=exOffer($item);
  // Public library identity is independent of media, navigation and booking availability.
  $productKey=exProductKey($item,$offer);if($productKey!==''){if(isset($products[$productKey]))continue;$products[$productKey]=true;}
  $city=$item['city']??'';$destination=$destinations[$city]??null;
  $item['country']=uxCountry($destination['country']??($item['country']??''));
  $item['city_label']=$destination?(destinationCityNames($destination)[siteLanguage()]??destinationCityNames($destination)['en']):$city;
  $item['cost']=in_array($item['cost']??'',['free','paid'],true)?$item['cost']:'unknown';
  $item['rating']=$ratings[$id]??['count'=>0,'average'=>0];$item['offer']=$offer;$item['entity']=!empty($item['product'])?'activities':'places';$item['created']=$created;
  $aliases=[];if(str_starts_with($id,'community-'))foreach($batch?$batch->editions(substr($id,10)):socialQuery("SELECT document FROM network_localizations WHERE kind='place' AND target=? AND state='published'",[substr($id,10)]) as $edition)$aliases[]=json_decode($edition['document'],true)['title']??'';
  $countryNames=[];foreach(destinationLocales()['en'] as $iso=>$name)if(destinationCountryId($iso)===$item['country']){foreach(destinationLocales() as $locale)$countryNames[]=$locale[$iso]??'';break;}
  $item['search']=mb_strtolower(implode(' ',[$item['title'],$item['summary']??'',$city,$item['country'],...$countryNames,...$aliases,...($destination['labels']??[]),...array_values($destination?destinationCityNames($destination):[])]));
  $rows[$id]=$item;
 }
 return array_values($rows);
}
function exFilter(array $rows,array $f): array {
 $q=mb_strtolower(trim($f['q']??''));
 $rows=array_values(array_filter($rows,static function($r)use($f,$q){
  if(($f['city']??'')!==''&&($r['city']??'')!==$f['city'])return false;
  if(($f['country']??'')!==''&&($r['country']??'')!==$f['country'])return false;
  if(($f['category']??'')!==''&&!in_array($f['category'],$r['category_ids']??[],true))return false;
  if(($f['cost']??'')!==''&&$r['cost']!==$f['cost'])return false;
  if(($f['minutes']??0)>0&&(!isset($r['minutes'])||$r['minutes']>(int)$f['minutes']))return false;
  if(($f['tab']??'all')!=='all'&&$r['entity']!==$f['tab'])return false;
  return $q===''||mb_strpos($r['search'],$q)!==false;
 }));
 // Community ranking uses a 3.5-star prior with weight 5; unrated entries sort last.
 usort($rows,static function($a,$b)use($f,$q){
  if(($f['sort']??'')==='rating'){
   $rank=static fn($r)=>($r['rating']['count']??0)>0?((float)$r['rating']['average']*(int)$r['rating']['count']+17.5)/((int)$r['rating']['count']+5):0;
   $order=($rank($b)<=>$rank($a))?:((int)($b['rating']['count']??0)<=>(int)($a['rating']['count']??0));if($order)return $order;
  }
  if(($f['sort']??'')==='newest'&&$a['created']!==$b['created'])return $b['created']<=>$a['created'];
  $score=static fn($r)=>$q===''?0:(mb_strtolower($r['title'])===$q?3:(str_starts_with(mb_strtolower($r['title']),$q)?2:1));
  return ($score($b)<=>$score($a))?:strnatcasecmp($a['title'],$b['title'])?:strcmp($a['id'],$b['id']);
 });return $rows;
}
function exUrl(array $filters,array $changes=[]): string {return '/explore/?'.http_build_query(array_replace($filters,['lang'=>siteLanguage()],$changes));}
function exRelatedRoutes(string $place): void {
 $links=[];$seen=[];
 foreach(socialQuery("SELECT t.id,t.owner,l.language,l.document FROM member_trips t JOIN members m ON m.id=t.owner JOIN network_localizations l ON l.kind='trip' AND l.target=t.id AND l.state='published' WHERE t.state='published' AND t.public_plan IS NOT NULL AND m.state='active' ORDER BY l.updated DESC") as $row){
  if(isset($seen[$row['id']])||!saCanView('trip',$row['id'],$row['owner'],'')||!saCanView('trip',$row['id'],$row['owner'])||crHidden('trip',$row['id']))continue;
  $doc=json_decode($row['document'],true);$ids=array_map(fn($x)=>cpCanonical($x['id']??''),$doc['items']??[]);if(!in_array(cpCanonical($place),$ids,true))continue;
  $seen[$row['id']]=true;$links[]=['url'=>languageUrl('/community/trips/'.$row['id'].'/?content_lang='.rawurlencode($row['language']),siteLanguage()),'title'=>$doc['public_title']??$doc['title']??''];if(count($links)>=8)break;
 }
 if(!$links)return;echo '<section class="social-card"><h2>'.e(exT('related_routes')).'</h2><ul>';foreach($links as $link)echo '<li><a dir="auto" href="'.e($link['url']).'">'.e($link['title']).'</a></li>';echo '</ul></section>';
}

function exRating(array $item): void {
 $count=max(0,(int)($item['rating']['count']??0));
 $url=preg_replace('/#.*$/','',$item['url']).'#community-ratings';
 if(!$count){echo '<a class="ex-rating ex-rating-empty" href="'.e($url).'"><span aria-hidden="true">☆</span><span>'.e(exT('rating_empty')).'</span></a>';return;}
 $average=max(1,min(5,(float)$item['rating']['average']));$score=number_format($average,1);
 $label=str_replace(['{score}','{count}'],[$score,(string)$count],exT('rating_summary'));
 echo '<a class="ex-rating" href="'.e($url).'" aria-label="'.e($label).'"><span class="ex-stars" aria-hidden="true"><span>★★★★★</span><span class="ex-stars-fill ex-stars-'.(int)round($average*10).'">★★★★★</span></span><strong dir="ltr">'.$score.'<small> / 5</small></strong><span class="ex-rating-count">'.e(str_replace('{count}',(string)$count,exT('rating_count'))).'</span></a>';
}
