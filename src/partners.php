<?php
declare(strict_types=1);
function partnerCatalog(): array { return []; }
function trackingLabel($value): string { return is_string($value)&&preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}$/D',$value)?$value:''; }
function captureAttribution(array $input): void {
 if(($_SESSION['attribution']['expires']??0)<time())unset($_SESSION['attribution']);
 $newContext=false;foreach(['REF','ref','PARAM','param','utm_source','utm_medium','utm_campaign','utm_content','src'] as $k)if(isset($input[$k]))$newContext=true;
 if(($newContext&&strpos($_SERVER['REQUEST_URI']??'','/go/')!==0)||($_SESSION['journey_expires']??0)<time())unset($_SESSION['journey_entry']);
 if(array_intersect(['REF','ref','PARAM','param','utm_source','utm_medium','utm_campaign','utm_content','src'],array_keys($input))) {
  $_SESSION['attribution']=['ref'=>trackingLabel($input['REF']??$input['ref']??''),'param'=>trackingLabel($input['PARAM']??$input['param']??''),'source'=>trackingLabel($input['utm_source']??$input['src']??''),'medium'=>trackingLabel($input['utm_medium']??''),'ad_campaign'=>trackingLabel($input['utm_campaign']??''),'ad'=>trackingLabel($input['utm_content']??''),'expires'=>time()+1800];
 } elseif(!isset($_SESSION['attribution'])||($_SESSION['attribution']['expires']??0)<time()) {
  $host=strtolower((string)parse_url($_SERVER['HTTP_REFERER']??'',PHP_URL_HOST));$source='';
  foreach(['google.co.il'=>'google','google.com'=>'google','facebook.com'=>'facebook','fb.com'=>'facebook','instagram.com'=>'instagram','chatgpt.com'=>'chatgpt','bing.com'=>'bing'] as $domain=>$label)if($host===$domain||substr($host,-strlen('.'.$domain))==='.'. $domain)$source=$label;
  if($source!=='')$_SESSION['attribution']=['source'=>$source,'expires'=>time()+1800];
 }
 if(isset($_SESSION['attribution']))$_SESSION['attribution']['expires']=time()+1800;
}
function attribution(): array { $empty=['ref'=>'','param'=>'','source'=>'','medium'=>'','ad_campaign'=>'','ad'=>''];$a=$_SESSION['attribution']??[];return ($a['expires']??0)>=time()?array_merge($empty,$a):$empty; }
// Owner requested all affiliate navigation paused on 2026-09-25. Preserve provider settings and reports.
function affiliateNavigationEnabled(): bool {return false;}
function partnerEnabled(string $provider): bool {return affiliateNavigationEnabled() && setting('partner_'.$provider,'0')==='1';}
function partnerUrl(string $provider,string $campaign,string $param): string {
 $catalog=partnerCatalog();if(!isset($catalog[$provider]['campaigns'][$campaign]))throw new InvalidArgumentException('Unknown campaign');
 if($param===''||trackingLabel($param)!==$param)throw new InvalidArgumentException('Invalid PARAM');
 // Preserve WeSell's UI-generated path suffix, not a conventional query string.
 return '';
}
function partnerParam(string $provider,string $campaign,array $a): string {return $a['param']?:($a['ad_campaign']?:'tripovela-'.$provider.'-'.$campaign);}
function validOfferPage($page): bool {return is_string($page)&&preg_match('~^/offers/(?:(eshet|walla)/(?:([a-z-]+)/)?)?$~D',$page)&&strlen($page)<100;}
function partnerHref(string $provider,string $campaign): string {
 $a=attribution();global $path;return '/go/'.$provider.'/'.$campaign.'/?'.http_build_query(['REF'=>$a['ref'],'PARAM'=>partnerParam($provider,$campaign,$a),'utm_source'=>$a['source'],'utm_medium'=>$a['medium'],'utm_campaign'=>$a['ad_campaign'],'utm_content'=>$a['ad'],'placement'=>'offers','page'=>validOfferPage($path)?$path:'/offers/']);
}
function countableOutbound(array $server,array $a): bool {
 if(($server['REQUEST_METHOD']??'GET')!=='GET')return false;
 if(preg_match('/prefetch|prerender/i',($server['HTTP_PURPOSE']??'').' '.($server['HTTP_SEC_PURPOSE']??'')))return false;
 foreach(['ref','param'] as $key)if(preg_match('/^(qa_|measurement_check)/i',$a[$key]??''))return false;
 return true;
}
function recordOutbound(string $provider,string $campaign,array $a,string $placement): void {
 if(!countableOutbound($_SERVER,$a))return;
 $page=$_GET['page']??'';$page=validOfferPage($page)?$page:'unknown';
 query('INSERT INTO outbound_events(created_at,provider,campaign,ref,param,source,page,placement,medium,ad_campaign,ad,entry_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[gmdate('c'),$provider,$campaign,$a['ref'],$a['param'],$a['source'],$page,$placement,$a['medium'],$a['ad_campaign'],$a['ad'],($_SESSION['journey_expires']??0)>=time()?($_SESSION['journey_entry']??null):null]);
 analyticsOutbound($provider,$page,$placement);
}

function recordPageView(string $page): void {
 if(!empty($GLOBALS['terms_pending']))return;
 $a=attribution();if(!countableOutbound($_SERVER,$a)||!empty($_SESSION['user'])||http_response_code()>=400)return;
 $host=strtolower((string)parse_url($_SERVER['HTTP_REFERER']??'',PHP_URL_HOST));
 if(!preg_match('/^[a-z0-9.-]{1,253}$/D',$host))$host='';
 query('INSERT INTO page_events(created_at,page,ref,param,source,medium,ad_campaign,ad,referrer_domain) VALUES(?,?,?,?,?,?,?,?,?)',[gmdate('c'),$page,$a['ref'],$a['param'],$a['source'],$a['medium'],$a['ad_campaign'],$a['ad'],$host]);
 $entryId=(int)db()->lastInsertId();
 analyticsPage($page);
 if(empty($_SESSION['journey_entry'])){$_SESSION['journey_entry']=$entryId;$_SESSION['journey_expires']=time()+1800;}
}
