<?php
declare(strict_types=1);
// Local translations only. No external translation service is called by a page visit.
function providerSource(string $code): array {
 static $all;if($all===null)$all=array_replace(providerCatalog(),activityMetadata(),json_decode(file_get_contents(ROOT.'/content/activity-details.json'),true));
 $source=$all[$code]??[];$file=ROOT.'/content/provider-source.json';static $fresh;if($fresh===null)$fresh=is_file($file)?json_decode(file_get_contents($file),true):[];
 if(isset($fresh[$code])){$source=array_replace($source,$fresh[$code]);unset($source['translations']);}
 return $source;
}
function providerTextRaw(string $code,string $field): mixed {
 $source=providerSource($code);$lang=siteLanguage();
 if($lang==='en')return $source[$field]??($field==='inclusions'?[]:'');
 $file=ROOT.'/content/provider-translations.json';static $translations;if($translations===null)$translations=is_file($file)?json_decode(file_get_contents($file),true):[];
 $row=$translations[$code]??[];
 // A translation is bound to its source, preventing stale imports from silently replacing newer copy.
 if(($row['source'][$field]??null)===($source[$field]??null)&&isset($row['translations'][$lang][$field]))return $row['translations'][$lang][$field];
 return $source['translations'][$lang][$field]??($field==='inclusions'?[]:'');
}
function providerText(string $code,string $field): mixed {
 $value=providerTextRaw($code,$field);$source=providerSource($code)[$field]??null;
 $file=ROOT.'/content/provider-review-holds.json';static $holds=null;if($holds===null)$holds=is_file($file)?json_decode(file_get_contents($file),true):[];
 $rules=$holds[$code]['fields'][$field]??[];
 foreach($rules as $fragment){if(is_array($source)){foreach($source as $i=>$text)if(stripos($text,$fragment)!==false)unset($value[$i]);}elseif(is_string($source)&&stripos($source,$fragment)!==false)return '';}
 if($field==='additional'&&is_array($source))foreach($source as $i=>$text)if(stripos($text,'Suitable for all physical fitness levels')!==false)unset($value[$i]);
 if(is_array($value))$value=providerCleanList($value,is_array($source)?$source:[],$field);
 if(siteLanguage()==='en'){$clean=fn($text)=>html_entity_decode(strip_tags(preg_replace('/<br\s*\/?\s*>/i',"\n",$text)),ENT_QUOTES|ENT_HTML5,'UTF-8');return is_array($value)?array_map($clean,$value):$clean($value);}
 return $value;
}
// Keep source indices intact: review holds and approved translations use those indices.
function providerCleanList(array $values,array $source,string $field): array {
 $seen=[];$result=[];
 foreach($values as $i=>$text){
  if(!is_string($text))continue;
  $plain=trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8')));
  $original=strtolower(trim((string)($source[$i]??'')," \t\n\r\0\x0B."));
  // These describe a visit mode, not a stop. Without a resolved location they are orphan labels.
  if($field==='itinerary'&&in_array($original,['outside view','pass by','pass-by','pass by without stopping'],true))continue;
  $key=mb_strtolower($plain,'UTF-8');
  if($key===''||isset($seen[$key]))continue;
  $seen[$key]=true;$result[$i]=$text;
 }
 return $result;
}
function providerReviewNotice(string $code): void {
 $file=ROOT.'/content/provider-review-holds.json';$holds=is_file($file)?json_decode(file_get_contents($file),true):[];
 if(isset($holds[$code]))echo '<p class="notice">'.e(($holds[$code]['notice'][siteLanguage()]??translatedText($holds[$code]['notice']['he']))).'</p>';
}
function providerLanguageName(string $code): string {
 $names=['cs'=>['צ׳כית','Czech','Чешский'],'de'=>['גרמנית','German','Немецкий'],'en'=>['אנגלית','English','Английский'],'es'=>['ספרדית','Spanish','Испанский'],'fr'=>['צרפתית','French','Французский'],'it'=>['איטלקית','Italian','Итальянский'],'ar'=>['ערבית','Arabic','Арабский'],'cmn'=>['מנדרינית','Mandarin','Мандаринский'],'da'=>['דנית','Danish','Датский'],'el'=>['יוונית','Greek','Греческий'],'fi'=>['פינית','Finnish','Финский'],'he'=>['עברית','Hebrew','Иврит'],'hr'=>['קרואטית','Croatian','Хорватский'],'hu'=>['הונגרית','Hungarian','Венгерский'],'ja'=>['יפנית','Japanese','Японский'],'ko'=>['קוריאנית','Korean','Корейский'],'nl'=>['הולנדית','Dutch','Нидерландский'],'no'=>['נורווגית','Norwegian','Норвежский'],'pl'=>['פולנית','Polish','Польский'],'pt'=>['פורטוגזית','Portuguese','Португальский'],'ro'=>['רומנית','Romanian','Румынский'],'ru'=>['רוסית','Russian','Русский'],'sk'=>['סלובקית','Slovak','Словацкий'],'sv'=>['שוודית','Swedish','Шведский'],'th'=>['תאית','Thai','Тайский'],'tr'=>['טורקית','Turkish','Турецкий']];
 return isset($names[$code])?languagePhrase(...$names[$code]):$code;
}
function providerInclusions(string $code): array {
 $values=providerText($code,'inclusions');$source=providerSource($code)['inclusions']??[];
 // Editorial holds match exact source fragments, not a translated array position alone.
 $holds=['43493P3'=>['beacon or veggie option'],'2800SCC'=>['See below for more']];
 foreach($source as $i=>$text)foreach($holds[$code]??[] as $fragment)if(str_contains($text,$fragment))unset($values[$i]);
 return array_values($values);
}
function providerUnavailablePage(): void {
 http_response_code(404);
 echo '<section class="page-heading"><h1>'.e(languagePhrase('הפעילות אינה זמינה כרגע','This activity is currently unavailable','Это занятие сейчас недоступно')).'</h1><p>'.e(languagePhrase('הספק השבית את ההצעה. אפשר לגלות פעילויות אחרות.','The provider has deactivated this offer. Explore other activities.','Поставщик отключил предложение. Посмотрите другие занятия.')).'</p><a class="button" href="'.e(languageUrl('/explore/',siteLanguage())).'">'.e(languagePhrase('לפעילויות נוספות','Explore activities','Другие занятия')).'</a></section>';
}
function providerTaxonomy(array $item,?array $overview=null): void {
 $dest=tripDestinations()[$item['city']]??null;if(!$dest)return;
 $type=$item['type']??'city';$extra=['museums'=>['מוזיאונים','Museums','Музеи'],'beaches'=>['חופים','Beaches','Пляжи'],'markets'=>['שווקים','Markets','Рынки'],'viewpoints'=>['תצפיות','Viewpoints','Смотровые площадки'],'waterfront'=>['טיילות','Waterfront walks','Набережные'],'memorials'=>['אתרי הנצחה','Memorials','Мемориалы']];$label=isset(activityTypes()[$type])?activityLabel(activityTypes()[$type]):(isset($extra[$type])?activityLabel($extra[$type]):languagePhrase('מקומות לביקור','Places to visit','Места для посещения'));
 echo '<nav class="activity-taxonomy" aria-label="'.e(languagePhrase('מדינה וסוג פעילות','Country and activity type','Страна и тип занятия')).'">';
 echo '<a href="'.e(languageUrl('/explore/?q='.urlencode($dest['country']),siteLanguage())).'">'.e(activityLabel($dest['country_labels'])).'</a> · ';
 echo '<a href="'.e(languageUrl('/explore/?destination='.urlencode($item['city']),siteLanguage())).'">'.e(activityLabel($dest['labels'])).'</a> · ';
 echo '<a href="'.e(languageUrl('/explore/?q='.urlencode($dest['country']).'&type='.urlencode($type),siteLanguage())).'">'.e($overview['subtype'][siteLanguage()]??$label).'</a></nav>';
}
function providerFullDetails(string $code,bool $supplemental=false): void {
 providerReviewNotice($code);
 $description=providerText($code,'description');
 if($description)echo '<h2>'.e(languagePhrase('על החוויה — לפי הספק','Overview — from the provider','Обзор — по данным поставщика')).'</h2><p>'.nl2br(e($description)).'</p>';
 $stops=providerText($code,'itinerary');
 if($stops){echo '<h3>'.e(languagePhrase('מה עושים בדרך','What you will do','Что вы будете делать')).'</h3><ol>';foreach($stops as $stop)echo '<li>'.e($stop).'</li>';echo '</ol>';}
 if($supplemental){echo '<details><summary>'.e(languagePhrase('מה כלול, נקודת מפגש ותנאים','Inclusions, meeting point and conditions','Что включено, место встречи и условия')).'</summary><dl>';
 foreach(['meeting'=>['נקודת מפגש','Meeting point','Место встречи'],'inclusions'=>['פרטי ההצעה לפי הספק','Offer details from the provider','Сведения от поставщика'],'exclusions'=>['לא כלול','Not included','Не включено'],'additional'=>['מידע נוסף','Additional information','Дополнительная информация'],'cancellation'=>['ביטול — יש לוודא אצל הספק','Cancellation — confirm with provider','Отмена — уточните у поставщика']] as $field=>$label){$v=$field==='inclusions'?providerInclusions($code):providerText($code,$field);if(!$v)continue;echo '<dt>'.e(languagePhrase(...$label)).'</dt><dd>';if(is_array($v)){echo '<ul>';foreach($v as $text)echo '<li>'.e($text).'</li>';echo '</ul>';}else echo nl2br(e($v));echo '</dd>';}
 echo '</dl></details>';}
 $file=ROOT.'/content/provider-media.json';$media=is_file($file)?json_decode(file_get_contents($file),true):[];
 if(!empty($media[$code]['images'])){echo '<details class="provider-photos"><summary>'.e(languagePhrase('תמונות מהחוויה','Experience photos','Фотографии экскурсии')).' ('.count($media[$code]['images']).')</summary><div class="provider-gallery">';foreach($media[$code]['images'] as $i=>$photo){$path=$photo['path']??'';if(!str_starts_with($path,'/assets/provider/'))continue;echo '<figure><img loading="lazy" src="'.e($path).'" width="'.(int)$photo['width'].'" height="'.(int)$photo['height'].'" alt="'.e(languagePhrase('תמונת ספק מהחוויה','Provider photo of the experience','Фото экскурсии от поставщика')).' '.($i+1).'"><figcaption>Viator · '.e(languagePhrase('תמונת הספק','Supplier image','Фото поставщика')).'</figcaption></figure>';}echo '</div></details>';}
}
