<?php
declare(strict_types=1);
require_once __DIR__.'/viator-prices.php';
function viatorLandingPath(): string {return '/en/las-vegas/grand-canyon-helicopter-tours/';}
function viatorProducts(): array {
 $links=json_decode(file_get_contents(ROOT.'/content/partners/viator-links.json'),true,512,JSON_THROW_ON_ERROR);
 return [
 'landing'=>['id'=>'5847LASWIN','campaign'=>'tripovelagclanding','title'=>'Canyon landing','fit'=>'For time inside the canyon','description'=>'A helicopter tour with a landing on a private bluff below the rim. Start here if getting out of the helicopter is an essential part of the experience you want.','planning'=>'Check how to reach the departure point; the itinerary reviewed specifies making your own way to the heliport.','button'=>'Check canyon-landing tour','url'=>$links['landing']],
 'air'=>['id'=>'5516P6','campaign'=>'tripovelagcair','title'=>'Air-only flight','fit'=>'For a flight-focused experience','description'=>'See the Grand Canyon from the air without a sightseeing stop on the ground in the canyon. Compare this option when the flight itself is your priority.','planning'=>'The itinerary reviewed lists hotel transfers. Check your pickup arrangements and allow for the full outing, not just flight time.','button'=>'Check air-only tour','url'=>$links['air']],
 'ranch'=>['id'=>'5516P9','campaign'=>'tripovelagcranch','title'=>'Flight + Western ranch','fit'=>'For a different ground experience','description'=>'Pair a Grand Canyon flight with a Western ranch stop. Choose this format because the ranch interests you; it is different from landing inside the canyon.','planning'=>'The itinerary reviewed lists lunch and hotel transfers. Confirm the arrangements for your selected departure.','button'=>'Check flight-and-ranch tour','url'=>$links['ranch']]
 ];
}
function viatorEnabled(): bool {return setting('viator_grand_canyon_enabled','0')==='1';}
function viatorHref(string $key,string $placement): string {
 $a=attribution();return '/go/viator/'.$key.'/?'.http_build_query(['lang'=>'en','placement'=>$placement,'REF'=>$a['ref'],'PARAM'=>$a['param'],'utm_source'=>$a['source'],'utm_medium'=>$a['medium'],'utm_campaign'=>$a['ad_campaign'],'utm_content'=>$a['ad']]);
}
function viatorButton(string $key,string $placement,?string $label=null): string {
 $product=viatorProducts()[$key];if(!viatorEnabled())return '<span class="gc-unavailable">Booking links are temporarily unavailable.</span>';
 return '<span class="affiliate-price">'.viatorPriceHtml($product['id'],'en').'</span><a class="gc-book" rel="sponsored" data-affiliate="viator" href="'.e(viatorHref($key,$placement)).'">'.e($label??$product['button']).' <span aria-hidden="true">↗</span></a>';
}
function viatorArticle(): string {
 $body=file_get_contents(ROOT.'/content/grand-canyon-article.md');$body=str_replace('{{DETAILS_CHECKED_DATE}}','September 30, 2026',$body);
 foreach(['LANDING'=>'landing','AIR_ONLY'=>'air','RANCH'=>'ranch'] as $token=>$key)$body=str_replace('{{AFFILIATE_'.$token.'}}','/viator-placeholder/'.$key.'/',$body);
 $html=md($body);$doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8"><div id="gc-article">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);libxml_clear_errors();libxml_use_internal_errors($old);
 $seen=[];foreach(iterator_to_array($doc->getElementsByTagName('a')) as $a){if(preg_match('~^/viator-placeholder/(landing|air|ranch)/$~',$a->getAttribute('href'),$m)){
  $key=$m[1];$placement=isset($seen[$key])?'final_choice':'product_detail';$seen[$key]=true;
  if(!viatorEnabled()){$a->parentNode->replaceChild($doc->createTextNode('Booking links are temporarily unavailable.'),$a);continue;}
  $a->setAttribute('href',viatorHref($key,$placement));$a->setAttribute('rel','sponsored');$a->setAttribute('class','gc-book');$a->setAttribute('data-affiliate','viator');
  $priceDoc=new DOMDocument();$priceDoc->loadHTML('<?xml encoding="UTF-8"><div class="affiliate-price">'.viatorPriceHtml(viatorProducts()[$key]['id'],'en').'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);$a->parentNode->insertBefore($doc->importNode($priceDoc->getElementsByTagName('div')->item(0),true),$a);
 }}
 foreach($doc->getElementsByTagName('h2') as $h){if($h->textContent==='A closer look at your three options')$h->setAttribute('id','tour-details');if($h->textContent==='Questions before you book')$h->setAttribute('id','questions');}
 foreach(iterator_to_array($doc->getElementsByTagName('h3')) as $heading){
  if(!preg_match('/^([123])\. /',$heading->textContent,$match))continue;
  $key=['1'=>'landing','2'=>'air','3'=>'ranch'][$match[1]];
  $figure=$doc->createElement('figure');$figure->setAttribute('class','gc-story-image');
  $img=$doc->createElement('img');$img->setAttribute('src','/assets/gc-'.$key.'-illustration.png');$img->setAttribute('alt',['landing'=>'Illustrated canyon cliffs and rocky foreground','air'=>'Illustrated aerial perspective of canyon layers and river','ranch'=>'Illustrated Southwest ranch and desert landscape'][$key]);$img->setAttribute('width','1536');$img->setAttribute('height','1024');$img->setAttribute('loading','lazy');$figure->appendChild($img);
  $heading->parentNode->insertBefore($figure,$heading);
 }
 $out='';foreach($doc->getElementById('gc-article')->childNodes as $node)$out.=$doc->saveHTML($node);return $out;
}
function viatorRedirect(string $key): void {
 $products=viatorProducts();if(!viatorEnabled()||!isset($products[$key])){http_response_code(410);echo 'This booking link is unavailable.';return;}
 if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');return;}
 $p=$products[$key];$attr=attribution();
 if($attr['source']==='google'&&$attr['ad_campaign']==='gc_heli_us_test01'){
  $group=strpos($attr['ad'],'rsa_b_landing_')===0?'landing':(strpos($attr['ad'],'rsa_a_compare_')===0?'compare':'');
  if($group!==''){$networkLinks=json_decode(file_get_contents(ROOT.'/content/partners/viator-google-us-links.json'),true,512,JSON_THROW_ON_ERROR);$p['url']=$networkLinks[$group][$key];$p['campaign']='tripovelagcus01'.$group.$key;}
 }
 $u=parse_url($p['url']);parse_str($u['query']??'',$q);
 if(($u['scheme']??'')!=='https'||($u['host']??'')!=='www.viator.com'||($q['campaign']??'')!==$p['campaign']||strpos($u['path']??'',$p['id'])===false){http_response_code(503);echo 'Booking link configuration is unavailable.';return;}
 $resolved=viatorEnsureAffiliate($p['id'],$p['campaign']);if(!$resolved){http_response_code(503);header('Retry-After: 3600');echo 'Booking link temporarily unavailable';return;}
 $placement=is_string($_GET['placement']??null)&&in_array($_GET['placement'],['quick_choice','product_detail','final_choice'],true)?$_GET['placement']:'direct';
 try{
  $a=attribution();if(countableOutbound($_SERVER,$a)&&empty($_SESSION['user'])){
   query('INSERT INTO outbound_events(created_at,provider,campaign,ref,param,source,page,placement,medium,ad_campaign,ad,entry_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[gmdate('c'),'viator',$p['id'],$a['ref'],$a['param']?:$p['campaign'],$a['source'],viatorLandingPath(),$placement,$a['medium'],$a['ad_campaign'],$a['ad'],($_SESSION['journey_expires']??0)>=time()?($_SESSION['journey_entry']??null):null]);
   analyticsOutbound('viator',viatorLandingPath(),$placement,$p['id']);
  }
 }catch(Throwable $ex){error_log('Viator outbound measurement unavailable');}
 header('Referrer-Policy: no-referrer');header('Location: '.$resolved,true,302);
}
