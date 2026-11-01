<?php
// Public discovery projection. Never read the owner's editable/private plan.
function exTripRows(array $filters): array {
 tsSchema();$catalog=scCatalogue();$rows=[];$seen=[];
 $accept=function(array $s)use($filters){
  if(!tdMatchesFacts($s,$filters))return false;
  foreach(['country','city'] as $key)if(!empty($filters[$key])&&($key==='country'?uxCountry($s[$key]??''):($s[$key]??''))!==$filters[$key])return false;
  // Do not label a trip with an unknown place cost or category.
  if(!empty($filters['cost'])||!empty($filters['category']))return false;
  $q=mb_strtolower(trim($filters['q']??''));return $q===''||str_contains(mb_strtolower(implode(' ',[$s['title']??'',$s['city']??'',$s['country']??'',$s['region']??'',...array_column($s['items']??[],'title')])),$q);
 };
 $project=function(array $s,string $url,string $key,int $updated)use(&$rows,$accept){
  if(!$accept($s))return;$coords=null;foreach($s['items']??[] as $i){$c=$i['coords']??null;if(is_array($c)&&count($c)===2&&is_numeric($c[0])&&is_numeric($c[1])&&abs((float)$c[0])<=90&&abs((float)$c[1])<=180){$coords=[(float)$c[0],(float)$c[1]];break;}}
  $facts=tdKnownFacts($s);$details=[];if(isset($facts['mode']))$details[]=rxT($facts['mode']);if(isset($facts['minutes']))$details[]=tdReadableTime((int)$facts['minutes']);elseif(isset($facts['duration']))$details[]=tdLabels()[$facts['duration']];
  $dest=tripDestinations()[$s['city']??'']??null;$city=$dest?(destinationCityNames($dest)[siteLanguage()]??destinationCityNames($dest)['en']):($s['city']??'');
  $rows[]=['id'=>$key,'entity'=>'trips','title'=>$s['title']??'','url'=>$url,'coords'=>$coords,'image'=>$s['cover']??'','destination'=>implode(' · ',array_filter([$city,$s['region']??'',uxCountryName($s['country']??'')])),'stops'=>count($s['items']??[]),'details'=>implode(' · ',$details),'updated'=>$updated];
 };
 foreach(socialQuery("SELECT t.id,t.owner,l.language,l.document,l.updated FROM member_trips t JOIN members m ON m.id=t.owner JOIN network_localizations l ON l.kind='trip' AND l.target=t.id AND l.state='published' WHERE t.state='published' AND t.public_plan IS NOT NULL AND m.state='active' ORDER BY (l.language=?) DESC,l.updated DESC",[siteLanguage()]) as $r){
  $key=$r['owner'].':'.$r['id'];if(isset($seen[$key])||!saCanView('trip',$r['id'],$r['owner'],'')||!saCanView('trip',$r['id'],$r['owner'])||crHidden('trip',$r['id']))continue;
  $seen[$key]=true;$s=json_decode($r['document'],true);if(!is_array($s)||!networkRouteVisible($s,$r['language'])||networkBlocked(socialUser()['id']??'',$r['owner']))continue;
  $s['title']=$s['public_title']??$s['title']??'';$s['cover']=!empty($s['photos'][0])?'/community/media/'.$s['photos'][0].'/':'';$s['items']=$s['items']??[];foreach($s['items'] as &$i){$p=$catalog[$i['id']]??['id'=>$i['id'],'title'=>$i['title']??''];$i['coords']=rxLocation($p)['coords'];$i['title']=$p['title'];if(!$s['cover'])$s['cover']=$p['image']??'';}unset($i);
  $project($s,languageUrl('/community/trips/'.$r['id'].'/?content_lang='.rawurlencode($r['language']),siteLanguage()),'trip:'.$r['id'],(int)$r['updated']);
 }
 foreach(socialQuery("SELECT s.* FROM trip_shares s JOIN members m ON m.id=s.owner WHERE s.visibility='public' AND s.revoked=0 AND m.state='active' ORDER BY s.updated DESC") as $r){
  $key=$r['owner'].':'.$r['trip'];if(isset($seen[$key])||!tsReadable($r)||crHidden('share',$r['token']))continue;$s=json_decode($r['snapshot'],true);if(!is_array($s))continue;$seen[$key]=true;$s['cover']=tsCoverUrl($r,$s);$project($s,tsUrl($r['token']),'share:'.$r['token'],(int)$r['updated']);
 }
 if(($filters['sort']??'')==='newest')usort($rows,fn($a,$b)=>$b['updated']<=>$a['updated']);return $rows;
}
