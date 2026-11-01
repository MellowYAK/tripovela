<?php
declare(strict_types=1);
function providerCatalog(): array {static $data;return $data??=json_decode((string)@file_get_contents(ROOT.'/content/provider-catalog.json'),true)?:[];}
function providerCatalogDestinations(array $existing): array {
 $file=ROOT.'/content/provider-destinations.json';$rows=json_decode((string)@file_get_contents($file),true)?:[];
 foreach($rows as $city=>$d){$found=false;foreach($existing as $key=>$old)if($key===$city||($old['labels'][1]??'')===$city||($city==='New York City'&&$key==='New York')){$found=true;break;}if(!$found)$existing['viator-'.$d['destination_id']]=$d;}
 return $existing;
}
function providerCatalogItems(array $destinations): array {
 $out=[];
 foreach(providerCatalog() as $code=>$row){$city=null;foreach($destinations as $key=>$d)if(($d['labels'][1]??'')===$row['city']||($key===$row['city'])||($row['city']==='New York City'&&$key==='New York')){$city=$key;break;}if($city===null)continue;
  $title=providerText($code,'title');$description=providerText($code,'description');if(!$title||!$description)continue;
  $duration=$row['duration'];$minutes=$duration['fixedDurationInMinutes']??$duration['variableDurationFromMinutes']??null;
  $out[]=['id'=>'provider-'.strtolower($code),'city'=>$city,'title'=>$title,'summary'=>mb_strimwidth($description,0,180,'…','UTF-8'),'type'=>$row['type'],'duration'=>$minutes?$minutes.' '.languagePhrase('דקות','minutes','минут'):'','minutes'=>$minutes,'image'=>$row['image'],'credit'=>'Viator · '.languagePhrase('תמונת הספק','Supplier image','Фото поставщика'),'url'=>languageUrl('/activities/'.$code.'/',siteLanguage()),'cost'=>'paid','product'=>$code,'provider_catalog'=>true];
 }return $out;
}
function providerCatalogHref(string $code): string {$a=attribution();return '/go/viator/catalog/'.$code.'/?'.http_build_query(['lang'=>siteLanguage(),'placement'=>'experience','REF'=>$a['ref'],'PARAM'=>$a['param'],'utm_source'=>$a['source'],'utm_medium'=>$a['medium'],'utm_campaign'=>$a['ad_campaign'],'utm_content'=>$a['ad']]);}
function providerCatalogRedirect(string $code): void {
 if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return;}
 if(!viatorEnabled()||!isset(providerCatalog()[$code])||catalogProductInactive($code)){http_response_code(410);echo 'Booking link unavailable';return;}
 $campaign='tripovelacatalog'.$code;$url=viatorEnsureAffiliate($code,$campaign);if(!$url){http_response_code(503);header('Retry-After: 3600');echo 'Booking link temporarily unavailable';return;}
 $page='/activities/'.$code.'/';try{$a=attribution();if(countableOutbound($_SERVER,$a)&&empty($_SESSION['user'])){
 query('INSERT INTO outbound_events(created_at,provider,campaign,ref,param,source,page,placement,medium,ad_campaign,ad,entry_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[gmdate('c'),'viator',$code,$a['ref'],$a['param']?:$campaign,$a['source'],$page,'experience',$a['medium'],$a['ad_campaign'],$a['ad'],($_SESSION['journey_expires']??0)>=time()?($_SESSION['journey_entry']??null):null]);analyticsOutbound('viator',$page,'experience',$code);
 }}catch(Throwable $e){error_log('Catalog outbound measurement unavailable');}
 header('Referrer-Policy: no-referrer');header('Location: '.$url,true,302);
}
