<?php
declare(strict_types=1);
// Practical information is separate from editorial revisions and translation imports.
function practicalDefaults(string $id): array {
 $free=freeAttractionData($id);$doc=editorialDocuments()[$id]??[];$query='';
 if(!empty($free['location']['map_search_url'])){parse_str(parse_url($free['location']['map_search_url'],PHP_URL_QUERY)??'',$q);$query=(string)($q['query']??'');}
 return ['official_url'=>$free['official_url']??$doc['editorial']['official_url']??'','map_query'=>$query,'latitude'=>null,'longitude'=>null,'checked_at'=>$free['admission']['checked_at']??'', 'visibility'=>['address'=>true,'map'=>true,'official'=>true,'hours'=>true,'directions'=>true], 'translations'=>array_fill_keys(['he','en','ru'],['address'=>'','hours'=>'','directions'=>''])];
}
function practicalData(string $id): array {
 $base=practicalDefaults($id);static $reviewed;$reviewed=$reviewed??json_decode((string)file_get_contents(ROOT.'/content/practical-reviewed-locations.json'),true);$base=array_replace_recursive($base,$reviewed[$id]??[]);$d=tripCatalogDb();if(!$d)return $base;
 try{$q=$d->prepare('SELECT document FROM place_practical WHERE id=?');$q->execute([$id]);$raw=$q->fetchColumn();if($raw)$base=array_replace_recursive($base,json_decode($raw,true));}catch(Throwable $e){}
 return $base;
}
function practicalText(string $key): string {
 static $labels;$labels=$labels??json_decode(file_get_contents(ROOT.'/content/practical-labels.json'),true);
 return $labels[siteLanguage()][$key]??uiText($labels['en'][$key]??$key);
}
function practicalVisible(array $p,string $field): bool {return ($p['visibility'][$field]??false)===true;}
function practicalExtraLabel(string $key): string {
 $v=['special_notes'=>['הערות מיוחדות','Special notes','Особые примечания','სპეციალური შენიშვნები'],'accessibility'=>['מידע נגישות','Accessibility information','Доступность','ხელმისაწვდომობა'],'booking_notes'=>['הזמנה מראש','Advance booking','Предварительная запись','წინასწარი დაჯავშნა'],'seasonal_notes'=>['עונתיות וחריגים','Seasonal exceptions','Сезонные изменения','სეზონური ცვლილებები'],'contact'=>['פרטי קשר','Contact details','Контакты','საკონტაქტო ინფორმაცია'],'waze'=>['ניווט ב־Waze','Navigate with Waze','Маршрут в Waze','ნავიგაცია Waze-ით']];
 return isset($v[$key])?(siteLanguage()==='ka'?$v[$key][3]:languagePhrase(...array_slice($v[$key],0,3))):$key;
}
function practicalMapQuery(array $p): string {
 if(is_numeric($p['latitude']??null)&&is_numeric($p['longitude']??null))return $p['latitude'].','.$p['longitude'];
 return (string)($p['map_query']??'');
}
function practicalOfficialUrl(array $p): string {
 $url=(string)($p['official_url']??'');
 return filter_var($url,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['https','http'],true)?$url:'';
}
function practicalMap(array $p): void {
 if(!practicalVisible($p,'map')||practicalMapQuery($p)==='')return;
 ?><div class="practical-map-section"><h3><?=e(practicalText('map'))?></h3><iframe class="practical-map" src="<?=e('https://www.google.com/maps?q='.rawurlencode(practicalMapQuery($p)).'&output=embed')?>" title="<?=e(practicalText('map'))?>" loading="lazy" referrerpolicy="no-referrer" allowfullscreen></iframe><p class="small"><?=e(practicalText('map_note'))?></p></div><?php
}
function practicalPanel(string $id): void {
 scRelatedPlace($id);
 $p=practicalData($id);$tr=$p['translations'][siteLanguage()]??[];$url=practicalVisible($p,'official')?practicalOfficialUrl($p):'';$has=false;
 foreach(['address','hours','directions'] as $field)if(practicalVisible($p,$field)&&!empty($tr[$field]))$has=true;
 foreach(['special_notes','accessibility','booking_notes','seasonal_notes','contact'] as $field)if(!empty($tr[$field]))$has=true;
 $has=$has||(practicalVisible($p,'map')&&practicalMapQuery($p)!=='')||$url!=='';if(!$has)return;
 ?>
 <section class="practical-panel" translate="no"><h2><?=e(practicalText('title'))?></h2>
 <dl class="practical-facts">
 <?php foreach(['address','hours','directions'] as $field)if(practicalVisible($p,$field)&&!empty($tr[$field])):?><div><dt><?=e(practicalText($field))?></dt><dd><?=nl2br(e($tr[$field]))?></dd></div><?php endif?>
 </dl>
 <?php foreach(['special_notes','accessibility','booking_notes','seasonal_notes','contact'] as $key)if(!empty($tr[$key])):?><h3><?=e(practicalExtraLabel($key))?></h3><p><?=nl2br(e($tr[$key]))?></p><?php endif?>
 <?php if($url!==''):?><div class="practical-official"><h3><?=e(practicalText('official'))?></h3><p><?=e(practicalText('official_note'))?></p><a href="<?=e($url)?>" rel="noopener noreferrer" dir="ltr"><?=e($url)?></a></div><?php endif?>
 <?php practicalMap($p);?>
 <?php if(practicalVisible($p,'map')&&is_numeric($p['latitude']??null)&&is_numeric($p['longitude']??null)):?><p><a class="button secondary" href="<?=e('https://www.waze.com/ul?ll='.rawurlencode($p['latitude'].','.$p['longitude']).'&navigate=yes')?>" rel="noopener noreferrer"><?=e(practicalExtraLabel('waze'))?></a></p><?php endif?>
 <p class="small"><?=e(practicalText('verify'))?></p>
 </section>
 <?php
}
