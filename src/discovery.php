<?php
declare(strict_types=1);
function activityMetadata(): array {static $data;return $data??=json_decode(file_get_contents(ROOT.'/content/experiences/metadata.json'),true,512,JSON_THROW_ON_ERROR);}
function activityDestinations(): array {return ['Las Vegas'=>['לאס וגאס','Las Vegas','Лас-Вегас'],'New York'=>['ניו יורק','New York','Нью-Йорк'],'San Francisco'=>['סן פרנסיסקו','San Francisco','Сан-Франциско'],'Miami'=>['מיאמי','Miami','Майами'],'New Orleans'=>['ניו אורלינס','New Orleans','Новый Орлеан'],'Hawaii'=>['הוואי','Hawaii','Гавайи']];}
function activityTypes(): array {return ['helicopter'=>['מסוקים','Helicopter','Вертолёты'],'water'=>['שיט ומים','Boat & water','На воде'],'nature'=>['טבע וטיולי יום','Nature & day trips','Природа'],'cycling'=>['אופניים','Cycling','Велосипеды'],'food'=>['אוכל ויין','Food & wine','Еда и вино'],'city'=>['עיר ותרבות','City & culture','Город и культура']];}
function activityLabel(array $labels): string {return $labels[siteLanguage()]??languagePhrase(...array_slice(array_values(array_filter($labels,fn($key)=>is_int($key),ARRAY_FILTER_USE_KEY)),0,3));}
function activityCachedPrice(string $code): ?array {
 if(!preg_match('/^[A-Za-z0-9]{1,40}$/D',$code))return null;
 $file=STORAGE_PATH.'/viator-live/weekly-price-'.$code.'.json';$row=is_file($file)?json_decode((string)@file_get_contents($file),true):null;
 if(($row['status']??'')!=='ok'||($row['product_code']??'')!==$code||($row['expires_at']??0)<=time()||!is_numeric($row['amount']??null)||$row['amount']<=0)return null;
 // Lists read an existing snapshot only. No API or FX fan-out while rendering.
 $currency=function_exists('tripCurrency')?tripCurrency():(siteLanguage()==='he'?'ILS':'USD');return tripCachedConversion($row,$currency);
}
function activityDuration(string $code): string {
 $d=activityMetadata()[$code]['duration']??[];$min=$d['fixedDurationInMinutes']??$d['variableDurationFromMinutes']??null;$max=$d['variableDurationToMinutes']??$min;
 if(!$min)return '';$format=static fn($n)=>$n%60===0?($n/60).' '.languagePhrase('שעות','h','ч'):$n.' '.languagePhrase('דקות','min','мин');
 return $format($min).($max&&$max!==$min?' – '.$format($max):'');
}
function activityTitle(array $a): string {return explode(':',$a['title'],2)[0];}
function activitySave(array $a): string {return '<button type="button" class="save-article secondary" data-id="'.e($a['id']).'" data-title="'.e($a['title']).'" data-slug="'.e($a['slug']).'">♡ '.e(languagePhrase('שמירה','Save guide','Сохранить')).'</button>';}
function activityCard(array $a,string $placement='catalog'): void {
 $m=$a['_meta'];$code=$m['product_code'];$lang=$a['_lang'];$credit=$m['images'][0];$old=$GLOBALS['viator_cached_only']??false;$GLOBALS['viator_cached_only']=true;
 echo '<article class="activity-card" translate="no" data-product="'.e($code).'"><figure><img loading="lazy" width="600" height="400" src="/assets/'.e(imageFor($a)).'" alt="'.e($credit['caption_'.$lang]??activityTitle($a)).'"><figcaption>'.e(($credit['author']??'').' · '.($credit['license']??'')).'</figcaption></figure><div class="activity-content"><span class="eyebrow">'.e(activityLabel(activityDestinations()[$m['region']])).'</span><h3>'.e(activityTitle($a)).'</h3><p class="activity-duration">'.e(activityDuration($code)).'</p><p>'.e($m['languages'][$lang]['best_for']).'</p><div class="activity-price">'.viatorPriceHtml($code,siteLanguage()).'</div>';
 if(viatorEnabled()&&experienceAffiliate($code))echo '<a class="button" data-affiliate="viator" rel="sponsored nofollow" href="'.e(experienceHref($code,$placement)).'">'.e(languagePhrase('בדיקת זמינות ב־Viator','Check availability on Viator','Проверить наличие на Viator')).' ↗</a>';
 echo '<div class="activity-actions"><a class="text-link activity-main-link" data-product-detail="'.e($code).'" href="/guides/'.e($a['slug']).'/">'.e(languagePhrase('לפרטים ולמדריך','Details & guide','Подробнее')).'</a>'.activitySave($a).'</div></div></article>';$GLOBALS['viator_cached_only']=$old;
}
function activityResults(array $filters): array {
 $q=mb_strtolower(trim(is_string($filters['q']??null)?mb_substr($filters['q'],0,80):''));$destination=is_string($filters['destination']??null)?$filters['destination']:'';$type=is_string($filters['type']??null)?$filters['type']:'';
 foreach(activityDestinations() as $english=>$aliases)foreach($aliases as $alias)if($q===mb_strtolower($alias)||($english==='Las Vegas'&&in_array($q,['וגאס','vegas'],true)))$q=mb_strtolower($english);
 foreach(activityTypes() as $key=>$aliases)foreach($aliases as $alias)if($q===mb_strtolower($alias))$q=$key;
 $max=is_scalar($filters['max_price']??null)&&is_numeric($filters['max_price'])?max(0,min(100000,(float)$filters['max_price'])):0;
 return array_values(array_filter(experienceArticles(),function($a)use($q,$destination,$type,$max){if(!articleLanguageAvailable($a))return false;$m=$a['_meta'];$meta=activityMetadata()[$m['product_code']]??[];
 if($destination!==''&&$destination!==$m['region'])return false;if($type!==''&&$type!==($meta['type']??''))return false;
 $text=mb_strtolower($m['region'].' '.($meta['title']??'').' '.($meta['type']??'').' '.$m['languages']['he']['title'].' '.$m['languages']['en']['title']);
 if($q!==''&&mb_strpos($text,$q)===false)return false;
 if($max>0){$p=activityCachedPrice($m['product_code']);if(!$p||$p['amount']>$max)return false;}return true;}));
}
function activityFilters(string $action='/experiences/'): void { ?>
<form action="<?=e($action)?>" class="activity-filters" translate="no"><input type="hidden" name="lang" value="<?=e(siteLanguage())?>">
<label><?=e(languagePhrase('עיר או פעילות','City or activity','Город или занятие'))?><input name="q" maxlength="80" value="<?=e(is_string($_GET['q']??null)?$_GET['q']:'')?>" placeholder="<?=e(languagePhrase('למשל לאס וגאס','e.g. Las Vegas','Например, Las Vegas'))?>"></label>
<label><?=e(languagePhrase('יעד','Destination','Направление'))?><select name="destination"><option value=""><?=e(languagePhrase('כל היעדים','All destinations','Все направления'))?></option><?php foreach(activityDestinations() as $v=>$labels):?><option value="<?=e($v)?>" <?=($_GET['destination']??'')===$v?'selected':''?>><?=e(activityLabel($labels))?></option><?php endforeach?></select></label>
<label><?=e(languagePhrase('סוג פעילות','Activity type','Тип'))?><select name="type"><option value=""><?=e(languagePhrase('כל הסוגים','All types','Все типы'))?></option><?php foreach(activityTypes() as $v=>$labels):?><option value="<?=e($v)?>" <?=($_GET['type']??'')===$v?'selected':''?>><?=e(activityLabel($labels))?></option><?php endforeach?></select></label>
<label><?=e(languagePhrase('מחיר התחלתי עד (ILS לאדם)','Starting price up to (USD/person)','Цена от, не более (USD/чел.)'))?><input type="number" name="max_price" min="0" max="100000" step="0.01" value="<?=e(is_scalar($_GET['max_price']??null)?$_GET['max_price']:'')?>"></label><button class="button"><?=e(languagePhrase('מצאו חוויות','Find experiences','Найти впечатления'))?></button></form>
<?php }
function activityDisclosure(): void {echo '<p class="notice" translate="no">'.e(languagePhrase('מחירי החל מ־לאדם לפי סיכום Viator לקבוצה של לפחות שני משתתפים (או מינימום ההזמנה). אינם מחיר לקבוצה או אישור זמינות. המחירים נשמרים לשבוע; פתיחת מדריך מרעננת מחיר שפג תוקפו. ההזמנה ב־Viator; ייתכן שנקבל עמלה ללא תוספת עלות לכם.','Starting prices are per-person reference prices from Viator for at least two participants (or the minimum bookable group), not a group total or a date availability check. Prices are cached for one week; opening a guide refreshes an expired price. Book on Viator; we may earn a commission at no extra cost to you.','Начальная цена на человека рассчитана Viator для двух участников или минимальной группы. Это не итог за группу и не подтверждение наличия. Кэш хранится неделю; открытие путеводителя обновляет устаревшую цену. Бронирование на Viator; мы можем получить комиссию без доплаты с вашей стороны.')).'</p>';}
