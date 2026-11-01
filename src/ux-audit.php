<?php
require_once ROOT.'/src/review-followup.php';
require_once ROOT.'/src/measurement.php';
function networkLoginUrl(): string {return languageUrl('/community/account/?return_to='.rawurlencode($_SERVER['REQUEST_URI']??'/community/'),siteLanguage());}
function uxLabels(): array {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/ux-labels.json'),true);return array_replace($all['en'],$all[siteLanguage()]??[]);}
function uxT(string $key): string {return uxLabels()[$key]??nt('unavailable');}
function uxCountry(string $country): string {return preg_match('/^[a-z]{2}$/iD',$country)?destinationCountryId(strtoupper($country)):$country;}
function uxCountryName(string $country): string {foreach(destinationLocales()['en'] as $iso=>$name)if(destinationCountryId($iso)===uxCountry($country))return destinationLocales()[siteLanguage()][$iso]??$name;return $country;}
function uxPlaceSearchText(array $p): string {
 $values=[$p['title']??'',$p['city']??'',$p['country']??''];$country=uxCountry($p['country']??'');
 foreach(destinationLocales()['en'] as $iso=>$name)if(destinationCountryId($iso)===$country){foreach(destinationLocales() as $locale)$values[]=$locale[$iso]??'';break;}
 if($d=tripDestinations()[$p['city']??'']??null)$values=array_merge($values,array_values(destinationCityNames($d)));
 return mb_strtolower(implode(' ',$values));
}
function uxNotice(array $n): array {
 $out=['title'=>'','actor'=>'','reason'=>'','url'=>saNoticeUrl($n)];
 if(($n['member']??'')!==(socialUser()['id']??''))return [];
 if($n['kind']==='review_update'){
  $d=socialQuery('SELECT * FROM community_decisions WHERE id=?',[$n['target']])->fetch();if(!$d)return $out;
  $p=crReviewTarget($d);$out['title']=$p['title']?:uxT('unavailable');$out['reason']=$d['reason'];$out['url']=$p['url'];
 }elseif($n['kind']==='friend_request'){$p=networkProfile($n['target']);if($out['url']&&$p)$out['actor']=$p['name']?:$p['handle'];}
 elseif($n['kind']==='reply'){
  $parts=explode(':',$n['target']);$source=sxSource(['kind'=>$parts[0]??'','target'=>$parts[1]??'','comment'=>$parts[2]??'','edition'=>$n['language']]);
  if($source){$out['title']=$source['title'];if(!empty($parts[2])){$owner=socialQuery("SELECT owner FROM network_comments WHERE id=? AND state='published'",[$parts[2]])->fetchColumn();$actor=$owner?networkProfile($owner):null;if($actor)$out['actor']=$actor['name']?:$actor['handle'];}}
 }
 elseif(in_array($n['kind'],['approve','reject','block'],true)){
  foreach(['post'=>'network_posts','trip'=>'member_trips','place'=>'member_places'] as $kind=>$table)if(socialQuery("SELECT 1 FROM $table WHERE id=? AND owner=?",[$n['target'],$n['member']])->fetchColumn()){$doc=json_decode(socialQuery('SELECT document FROM network_localizations WHERE kind=? AND target=? ORDER BY updated DESC LIMIT 1',[$kind,$n['target']])->fetchColumn()?:'{}',true);$out['title']=$doc['title']??$doc['public_title']??uxT('unavailable');break;}
 }
 elseif($n['kind']==='mention'||$n['kind']==='continuation'){
  $parts=explode(':',$n['target']);$ref=$n['kind']==='mention'?['kind'=>$parts[0]??'','target'=>$parts[1]??'','edition'=>$parts[2]??'']:['kind'=>'post','target'=>$n['target'],'edition'=>$n['language']];
  if($out['url']&&($source=sxSource($ref))){$out['title']=$source['title'];$out['url']=$source['url'];}
 }
 if(!$out['url'])$out['title']=uxT('unavailable');return $out;
}
