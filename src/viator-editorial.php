<?php
declare(strict_types=1);
// Curated editorial placements. No arbitrary URLs, destinations or product IDs accepted.
function editorialViatorMap(): array {
 static $map;return $map??=json_decode(file_get_contents(ROOT.'/content/partners/viator-editorial-map.json'),true,512,JSON_THROW_ON_ERROR);
}
function editorialViatorLinks(): array {
 static $links;if($links===null){$data=json_decode(file_get_contents(ROOT.'/content/partners/viator-editorial-links.json'),true);$links=($data['environment']??'')==='production'?($data['products']??[]):[];}return $links;
}
function editorialViatorProduct(string $code): ?array {
 $row=editorialViatorLinks()[$code]??null;if(!$row||($row['status']??'')!=='ok'||!isset(editorialViatorMap()['products'][$code]))return null;
 $u=parse_url($row['affiliate_url']??'');parse_str($u['query']??'',$q);
 if(($u['scheme']??'')!=='https'||($u['host']??'')!=='www.viator.com'||isset($u['user'])||isset($u['port'])||($q['campaign']??'')!==($row['campaign']??'')||!str_ends_with($u['path']??'','-'.$code))return null;
 return $row;
}
function editorialViatorHref(string $id,string $code,string $placement): string {
 $a=attribution();return '/go/viator/article/'.$id.'/'.$code.'/?'.http_build_query(['lang'=>siteLanguage(),'placement'=>$placement,'REF'=>$a['ref'],'PARAM'=>$a['param'],'utm_source'=>$a['source'],'utm_medium'=>$a['medium'],'utm_campaign'=>$a['ad_campaign'],'utm_content'=>$a['ad']]);
}
function editorialViatorBlock(array $article,string $placement='middle'): string {
 $id=(string)$article['id'];$entry=editorialViatorMap()['articles'][$id]??null;
 if(!viatorEnabled()||!$entry||$entry['slug']!==$article['slug'])return '';
 $lang=siteLanguage();$he=$lang==='he';$offers=[];
 foreach($entry['products'] as $code)if(editorialViatorProduct($code))$offers[]=$code;
 if(!$offers)return '';
 $html='<section class="editorial-viator '.($placement==='middle'?'editorial-viator-full':'editorial-viator-compact').'" lang="'.$lang.'" dir="'.($he?'rtl':'ltr').'" translate="no" data-editorial-viator="'.e($id).'" id="viator-'.$placement.'">';
 $html.='<span class="eyebrow">TRIPOVELA · VIATOR</span><h2>'.e(languagePhrase('פעילויות שהוזכרו במדריך','Activities discussed in this guide','Занятия, упомянутые в путеводителе')).'</h2>';
 if($placement==='middle')$html.='<p>'.e($entry[$lang]??($lang==='ka'?'დაჯავშნამდე შეამოწმეთ აქტივობის დეტალები, თარიღები და საბოლოო ფასი.':'')).'</p>';
 $html.='<div class="editorial-viator-options">';
 foreach($offers as $code){$label=$lang==='ka'?providerText($code,'title'):editorialViatorMap()['products'][$code][$lang];$html.='<div><h3>'.e($label).'</h3>';
 $html.='<p class="editorial-viator-price">'.viatorPriceHtml($code,$lang).'</p>';
 $html.='<a class="button" rel="sponsored nofollow" data-affiliate="viator" href="'.e(editorialViatorHref($id,$code,$placement)).'">'.e(languagePhrase('בדיקת מחיר וזמינות ב־Viator','Check price & availability on Viator','Проверить цену и наличие на Viator')).' ↗</a></div>';}
 $html.='</div><p class="offers-disclosure">'.e(languagePhrase('קישורי שותפים: ייתכן שנקבל עמלה מהזמנה, ללא תוספת עלות עבורכם. ההזמנה והתשלום ב־Viator. בדקו תאריכים, תנאים ומחיר סופי.','Affiliate links: we may earn a commission at no extra cost to you. Booking and payment take place on Viator. Check dates, terms and the final total.','Партнёрские ссылки: мы можем получить комиссию без дополнительных расходов для вас. Бронирование и оплата проходят на Viator. Проверьте даты, условия и итоговую стоимость.')).'</p></section>';
 return $html;
}
function editorialViatorBody(array $article,string $html): string {
 $block=editorialViatorBlock($article);if($block==='')return $html;
 // Keep the original guide intact, placing contextual advice between complete sections.
 $parts=preg_split('/(?=<h2>)/',$html,3);
 if(count($parts)===3)return $parts[0].$parts[1].$block.$parts[2];
 return $html.$block;
}
function editorialViatorRedirect(string $id,string $code): void {
 $entry=editorialViatorMap()['articles'][$id]??null;$row=editorialViatorProduct($code);
 if(!viatorEnabled()||!$entry||!in_array($code,$entry['products'],true)||!$row){http_response_code(410);echo 'Booking link unavailable';return;}
 if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return;}
 $placement=is_string($_GET['placement']??null)&&in_array($_GET['placement'],['top','middle','bottom'],true)?$_GET['placement']:'direct';$page='/guides/'.$entry['slug'].'/';
 $campaign='tripovelaarticle'.$id;$url=viatorEnsureAffiliate($code,$campaign);if(!$url){http_response_code(503);header('Retry-After: 3600');echo 'Booking link temporarily unavailable';return;}
 try{$a=attribution();if(countableOutbound($_SERVER,$a)&&empty($_SESSION['user'])){
 query('INSERT INTO outbound_events(created_at,provider,campaign,ref,param,source,page,placement,medium,ad_campaign,ad,entry_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[gmdate('c'),'viator',$code,$a['ref'],$a['param']?:$campaign,$a['source'],$page,$placement,$a['medium'],$a['ad_campaign'],$a['ad'],($_SESSION['journey_expires']??0)>=time()?($_SESSION['journey_entry']??null):null]);analyticsOutbound('viator',$page,$placement,$code);
 }}catch(Throwable $e){error_log('Editorial outbound measurement unavailable');}
 header('Referrer-Policy: no-referrer');header('Location: '.$url,true,302);
}
