<?php
$label=fn($he,$en,$ru)=>e(languagePhrase($he,$en,$ru));$photos=[];
foreach([$media??[],$detail??[]] as $photo)if(!empty($photo['image'])&&!in_array($photo['image'],array_column($photos,'path'),true))$photos[]=['path'=>$photo['image']];
if(!$photos&&!empty($item['image']))$photos[]=['path'=>$item['image']];
$rating=communityRatingSummary($item['id']);
?>
<article class="activity-market free-market" translate="no" dir="<?=siteLanguage()==='he'?'rtl':'ltr'?>">
<?php providerTaxonomy($item);?><h1><?=e($title)?></h1>
<div class="market-meta"><span class="free-label"><?=$label('חינם','Free','Бесплатно')?></span><a href="#community-ratings"><span class="market-stars" aria-hidden="true">★</span><?php if($rating['count']):?><b dir="ltr"><?=number_format((float)$rating['average'],1)?> / 5</b> · <?=(int)$rating['count']?><?php else:?><?=$label('עדיין אין דירוגים','No ratings yet','Пока нет оценок')?><?php endif?> · Tripovela</a></div>
<div class="market-layout"><div class="market-main">
<?php if($photos)require ROOT.'/views/market-gallery.php';?>
<small class="market-credit"><?=e($media['credit']??$item['credit']??'')?></small>
<div class="market-facts"><span>◷ <?=e($item['duration'])?></span><span><?=$label('ביקור עצמאי','Independent visit','Самостоятельное посещение')?></span></div>
<nav class="market-jumps"><a href="#market-overview"><?=$label('על המקום','Overview','О месте')?></a><a href="#free-practical"><?=$label('מידע לביקור','Plan your visit','Перед посещением')?></a><a href="#community-ratings"><?=$label('דירוגי Tripovela','Tripovela ratings','Оценки Tripovela')?></a></nav>
<section class="market-section" id="market-overview"><h2><?=$label('על המקום והביקור','About this experience','Об этом месте')?></h2><p><?=e($description??$item['summary'])?></p>
<?php if(isset($doc)):?><div class="article-body"><?=editorialArticleBody($tr['body_markdown']??'',$doc)?></div><?php else:?><?php if(!empty($item['route'])):?><h3><?=$label('מסלול מוצע','Suggested route','Предлагаемый маршрут')?></h3><p><?=e(activityLabel($item['route']))?></p><p><?=e(activityLabel($item['practical']))?></p><?php endif?><?php endif?></section>
<section class="market-section" id="free-practical"><h2><?=$label('לפני שמגיעים','Before you visit','Перед посещением')?></h2><p><?=$label('הביקור המתואר חינמי. תערוכות מיוחדות, פעילויות נוספות או שירותים במקום עשויים להיות בתשלום. בדקו את שעות הפעילות ותנאי הכניסה לפני ההגעה.','The described visit is free. Special exhibitions, extra activities or on-site services may cost extra. Check opening hours and entry conditions before visiting.','Описанное посещение бесплатно. Специальные выставки, дополнительные мероприятия и услуги могут быть платными. Перед поездкой проверьте часы работы и условия входа.')?></p><?php if(!empty($free['admission']['checked_at'])):?><p class="market-muted"><?=$label('נבדק בתאריך','Last checked','Дата проверки')?>: <?=e($free['admission']['checked_at'])?></p><?php endif?><?php practicalPanel($item['id']);?></section>
<?php communityRatingPanel($item);?>
</div><aside class="market-booking"><div class="market-booking-card"><span><?=$label('מתכננים את הביקור','Plan your visit','Планируйте посещение')?></span><h2 class="free-price"><?=$label('חינם','Free','Бесплатно')?></h2><small><?=$label('ללא דמי כניסה לביקור המתואר','No entry fee for the described visit','Без входной платы за описанное посещение')?></small><?=tripSaveButton($item)?><div class="market-booking-note"><?=$label('ייתכן שנדרשת הרשמה מראש גם כאשר הכניסה חינם. בדקו את הפרטים לפני ההגעה.','Advance registration may be required even when entry is free. Check the details before visiting.','Даже при бесплатном входе может требоваться предварительная регистрация. Проверьте условия перед посещением.')?></div><p><a href="#free-practical"><?=$label('מידע ותכנון הביקור','Visit information','Информация для посещения')?> ↓</a></p><a href="<?=e(languageUrl('/my-trip/',siteLanguage()))?>"><?=$label('פתיחת הטיול שלי','Open my trip','Открыть мой план')?></a><p class="market-muted"><?=$label('שמירה לטיול אינה הזמנה או שמירת מקום.','Saving to a trip is not a booking or reservation.','Сохранение в план не является бронированием.')?></p></div></aside></div>
<?php require ROOT.'/views/market-related.php';?>
</article>

<?php require_once ROOT.'/src/explore.php';exRelatedRoutes($item['id']);?>
