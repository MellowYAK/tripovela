<?php
declare(strict_types=1);
function experienceCatalog(): array {
 static $data;return $data??=json_decode(file_get_contents(ROOT.'/content/experiences/catalog.json'),true,512,JSON_THROW_ON_ERROR);
}
function experienceArticles(?string $language=null): array {
 $lang=$language??siteLanguage();$lang=$lang==='he'?'he':'en';$out=[];
 foreach(experienceCatalog() as $row){if(catalogProductInactive($row['product_code']))continue;$l=$row['languages'][$lang];$out[]=['id'=>$row['id'],'slug'=>$row['slug'],'title'=>$l['title'],'summary'=>$l['meta_description'],'seo_description'=>$l['meta_description'],'body'=>file_get_contents(ROOT.'/content/experiences/'.$row['slug'].'/'.$lang.'.md'),'category'=>'travel-essentials','status'=>'published','checked_at'=>'2026-09-30','_experience'=>true,'_lang'=>$lang,'_meta'=>$row];}return $out;
}
function experienceArticle(string $slug): ?array {foreach(experienceArticles() as $a)if($a['slug']===$slug)return $a;return null;}
function experienceLinks(): array {
 static $data;if($data===null){$file=ROOT.'/content/partners/viator-guide-links.json';$raw=is_file($file)?json_decode(file_get_contents($file),true):[];$data=($raw['environment']??'')==='production'?($raw['products']??[]):[];}return $data;
}
function experienceAffiliate(string $code): ?array {
 $row=experienceLinks()[$code]??null;if(!$row||($row['status']??'')!=='ok')return null;
 $u=parse_url($row['affiliate_url']??'');parse_str($u['query']??'',$q);
 if(($u['scheme']??'')!=='https'||($u['host']??'')!=='www.viator.com'||isset($u['user'])||isset($u['port'])||($q['campaign']??'')!==($row['campaign']??'')||!str_ends_with($u['path']??'','-'.$code))return null;
 foreach(experienceCatalog() as $guide)if($guide['product_code']===$code)return $row;return null;
}
function experienceHref(string $code,string $placement): string {
 $a=attribution();return '/go/viator/guide/'.$code.'/?'.http_build_query(['lang'=>siteLanguage(),'placement'=>$placement,'REF'=>$a['ref'],'PARAM'=>$a['param'],'utm_source'=>$a['source'],'utm_medium'=>$a['medium'],'utm_campaign'=>$a['ad_campaign'],'utm_content'=>$a['ad']]);
}
function experienceTokens(string $html): string {
 return preg_replace_callback('/\{\{VIATOR_LINK:([A-Za-z0-9]{1,40}):(top|middle|bottom|extra)\}\}/',function($m){
  if(!viatorEnabled()||!experienceAffiliate($m[1]))return '<span>'.e(languagePhrase('הקישור להזמנה אינו זמין כרגע.','Booking link currently unavailable.','Ссылка бронирования пока недоступна.')).'</span>';
  return '<span class="affiliate-price">'.viatorPriceHtml($m[1],siteLanguage()).'</span><br><a class="button" rel="sponsored" data-affiliate="viator" href="'.e(experienceHref($m[1],$m[2])).'">'.e(languagePhrase('בדיקת מחיר וזמינות ב־Viator','Check price & availability on Viator','Проверить цену и наличие на Viator')).' ↗</a>';
 },$html);
}
function experienceRedirect(string $code): void {
 $row=experienceAffiliate($code);if(!viatorEnabled()||!$row){http_response_code(410);echo 'Booking link unavailable';return;}
 if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return;}
 $resolved=viatorEnsureAffiliate($code,$row['campaign']);if(!$resolved){http_response_code(503);header('Retry-After: 3600');echo 'Booking link temporarily unavailable';return;}
 $page='';foreach(experienceCatalog() as $g)if($g['product_code']===$code){$page='/guides/'.$g['slug'].'/';break;}
 $placement=is_string($_GET['placement']??null)&&in_array($_GET['placement'],['top','middle','bottom','extra','offers','catalog','home','search'],true)?$_GET['placement']:'direct';
 if(in_array($placement,['offers','catalog','home','search'],true))$page=['offers'=>'/offers/','catalog'=>'/experiences/','home'=>'/','search'=>'/search/'][$placement];
 try{$a=attribution();if(countableOutbound($_SERVER,$a)&&empty($_SESSION['user'])){
 query('INSERT INTO outbound_events(created_at,provider,campaign,ref,param,source,page,placement,medium,ad_campaign,ad,entry_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[gmdate('c'),'viator',$code,$a['ref'],$a['param']?:$row['campaign'],$a['source'],$page,$placement,$a['medium'],$a['ad_campaign'],$a['ad'],($_SESSION['journey_expires']??0)>=time()?($_SESSION['journey_entry']??null):null]);analyticsOutbound('viator',$page,$placement,$code);
 }}catch(Throwable $e){error_log('Guide outbound measurement unavailable');}
 header('Referrer-Policy: no-referrer');header('Location: '.$resolved,true,302);
}
