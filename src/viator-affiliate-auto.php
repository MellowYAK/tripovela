<?php
declare(strict_types=1);
// API requests only go to the fixed Viator API host, never to a URL from content.
function viatorConsumerUrl(string $url): ?array {
 if(str_starts_with($url,'//'))$url='https:'.$url;
 if(strlen($url)>4096||preg_match('/[\x00-\x20\x7f\\\\]/',$url))return null;
 $u=parse_url($url);if(!$u||!in_array(strtolower($u['scheme']??''),['https','http'],true)||!preg_match('/^(?:www\.|[a-z]{2}\.)?viator\.com$/D',strtolower($u['host']??''))||isset($u['user'])||isset($u['pass'])||isset($u['port']))return null;
 return $u;
}
function viatorUrlProduct(string $url): ?string {
 $u=viatorConsumerUrl($url);if(!$u)return null;
 return preg_match('~^/(?:[a-z]{2}(?:-[A-Za-z]{2})?/)?tours/[^/]+/[^/]+/d[0-9]+-([A-Za-z0-9]{1,40})/?$~D',$u['path']??'',$m)?$m[1]:null;
}
function viatorApiAffiliateValid(string $url,string $code,string $campaign): bool {
 $u=viatorConsumerUrl($url);if(!$u||($u['scheme']??'')!=='https'||($u['host']??'')!=='www.viator.com'||viatorUrlProduct($url)!==$code)return false;
 parse_str($u['query']??'',$q);
 foreach(['pid','mcid','campaign','medium'] as $k)if(preg_match_all('/(?:^|&)'.preg_quote($k,'/').'=/',$u['query']??'')!==1)return false;
 $identity=viatorIntegration();return $identity['partner_id']!==''&&$identity['mcid']!==''&&($q['pid']??'')===$identity['partner_id']&&($q['mcid']??'')===$identity['mcid']&&($q['medium']??'')==='api'&&($q['campaign']??'')===$campaign;
}
function viatorEnsureAffiliate(string $code,string $campaign,bool $force=false): ?string {
 viatorProductState($code,true);
 if(function_exists('catalogProductInactive')&&catalogProductInactive($code))return null;
 if(!preg_match('/^[A-Za-z0-9]{1,40}$/D',$code)||!preg_match('/^[A-Za-z0-9]{1,64}$/D',$campaign))return null;
 $identity=viatorIntegration();$name='affiliate-v2-'.hash('sha256',$identity['partner_id'].'|'.$identity['mcid'].'|'.$code.'|'.$campaign);$file=STORAGE_PATH.'/viator-live/'.$name.'.json';
 $read=static function()use($file,$code,$campaign){$r=is_file($file)?json_decode((string)@file_get_contents($file),true):null;return is_array($r)&&($r['status']??'')==='ok'&&($r['product_code']??'')===$code&&viatorApiAffiliateValid($r['url']??'',$code,$campaign)?$r:null;};
 if(!$force&&($cached=$read()))return $cached['url'];
 if(PHP_SAPI!=='cli'&&($GLOBALS['viator_affiliate_requests']??0)>=3)return null;
 $row=viatorPrivateCache($name,function()use($code,$campaign,$read,$force){
  if(!$force&&($cached=$read()))return $cached; // Double-check after taking the cache lock.
  $GLOBALS['viator_affiliate_requests']=($GLOBALS['viator_affiliate_requests']??0)+1;
  $p=viatorApiJson('/products/'.$code.'?'.http_build_query(['campaign-value'=>$campaign]));
  if(($p['status']??'')!=='ACTIVE'||($p['productCode']??'')!==$code||!viatorApiAffiliateValid($p['productUrl']??'',$code,$campaign))return null;
  // Keep the API-issued URL exactly as returned, including medium=api and api_version.
  return ['status'=>'ok','product_code'=>$code,'campaign'=>$campaign,'url'=>$p['productUrl'],'verified_at'=>time(),'expires_at'=>PHP_INT_MAX,'origin'=>'viator-production-api'];
 },$force);
 return ($row['status']??'')==='ok'&&viatorApiAffiliateValid($row['url']??'',$code,$campaign)?$row['url']:null;
}
function viatorAutoRegister(string $url,string $page,int $index): ?string {
 if(!viatorEnabled()||!($code=viatorUrlProduct($url))||!preg_match('~^/[a-zA-Z0-9/_-]{0,200}$~D',$page))return null;
 $campaign='tripovelaauto'.substr(hash('sha256',$page),0,16);$placement='inline:'.$index;
 if(!viatorEnsureAffiliate($code,$campaign))return null;
 $record=['product_code'=>$code,'campaign'=>$campaign,'page'=>$page,'placement'=>$placement];$id=hash('sha256',json_encode($record));
 $dir=STORAGE_PATH.'/viator-auto-routes';if(!is_dir($dir)&&!@mkdir($dir,0750,true))return null;
 $file=$dir.'/'.$id.'.json';if(!is_file($file)){$tmp=tempnam($dir,'.new-');if(!$tmp)return null;file_put_contents($tmp,json_encode($record));chmod($tmp,0640);if(!rename($tmp,$file)){@unlink($tmp);return null;}}
 $a=attribution();return '/go/viator/auto/'.$id.'/?'.http_build_query(['lang'=>siteLanguage(),'REF'=>$a['ref'],'PARAM'=>$a['param'],'utm_source'=>$a['source'],'utm_medium'=>$a['medium'],'utm_campaign'=>$a['ad_campaign'],'utm_content'=>$a['ad']]);
}
function viatorAutoAnchor(DOMElement $anchor,string $page,int $index): void {
 $href=$anchor->getAttribute('href');if(!viatorConsumerUrl($href))return;
 $route=viatorAutoRegister($href,$page,$index);
 if($route){$anchor->setAttribute('href',$route);$anchor->setAttribute('rel','sponsored nofollow');$anchor->setAttribute('data-affiliate','viator');}
 else{$span=$anchor->ownerDocument->createElement('span');$span->setAttribute('class','viator-link-unavailable');$span->setAttribute('title',languagePhrase('קישור ההזמנה אינו זמין כרגע','Booking link currently unavailable','Ссылка пока недоступна'));while($anchor->firstChild)$span->appendChild($anchor->firstChild);$anchor->parentNode->replaceChild($span,$anchor);}
}
function viatorAutoHtml(string $html): string {
 if(!preg_match('~<a\b[^>]*href=["\x27](?:https?:)?//(?:www\.|[a-z]{2}\.)?viator\.com(?:/|["\x27])~i',$html))return $html;
 $doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();libxml_use_internal_errors($old);
 $page=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
 foreach(iterator_to_array($doc->getElementsByTagName('a')) as $i=>$a)viatorAutoAnchor($a,$page,$i);
 foreach(iterator_to_array($doc->childNodes) as $node)if($node->nodeType===XML_PI_NODE)$doc->removeChild($node);
 return $doc->saveHTML();
}
function viatorAutoRedirect(string $id): void {
 if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return;}
 $file=STORAGE_PATH.'/viator-auto-routes/'.$id.'.json';$r=is_file($file)?json_decode((string)@file_get_contents($file),true):null;
 if(!viatorEnabled()||!is_array($r)||!hash_equals($id,hash('sha256',json_encode($r)))){http_response_code(410);echo 'Booking link unavailable';return;}
 $url=viatorEnsureAffiliate($r['product_code'],$r['campaign']);if(!$url){http_response_code(503);header('Retry-After: 3600');echo 'Booking link temporarily unavailable';return;}
 try{$a=attribution();if(countableOutbound($_SERVER,$a)&&empty($_SESSION['user'])){
 query('INSERT INTO outbound_events(created_at,provider,campaign,ref,param,source,page,placement,medium,ad_campaign,ad,entry_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[gmdate('c'),'viator',$r['product_code'],$a['ref'],$a['param']?:$r['campaign'],$a['source'],$r['page'],$r['placement'],$a['medium'],$a['ad_campaign'],$a['ad'],($_SESSION['journey_expires']??0)>=time()?($_SESSION['journey_entry']??null):null]);analyticsOutbound('viator',$r['page'],$r['placement'],$r['product_code']);
 }}catch(Throwable $ex){error_log('Viator automatic-link measurement unavailable');}
 header('Referrer-Policy: no-referrer');header('Location: '.$url,true,302);
}
