<?php
$doc=editorialDocuments()[$m[1]]??null;$tr=$doc['editorial']['translations'][siteLanguage()]??null;
if(!$doc||!publishedTranslation($tr)){contentLanguageUnavailable(array_keys($doc['editorial']['translations']??[]));return;}
contentIndexDocument($doc);
$title=$tr['title'];$description=$tr['summary']??'';$media=editorialMedia($doc['editorial']);$detail=editorialMedia($doc['editorial'],'inline');$free=freeAttractionData($doc['id']);$catalogItem=null;
foreach(tripCatalog() as $item)if($item['id']===$doc['id']){$catalogItem=$item;break;}
if($catalogItem&&$catalogItem['cost']==='free'){$item=$catalogItem;require ROOT.'/views/free-market.php';return;}
?>
<article class="place-page" translate="no" dir="<?=siteLanguage()==='he'?'rtl':'ltr'?>">
<h1><?=e($title)?></h1>
<?php if($catalogItem):?><p><?=e(activityLabel(tripDestinations()[$catalogItem['city']]['labels']))?> · <?=e($catalogItem['duration'])?></p><?php endif?>
<?php if($media):?><figure><img class="place-cover" width="1536" height="1024" src="<?=e($media['image'])?>" alt="<?=e($media['alt']?:$title)?>"><figcaption><?=e($media['credit'])?></figcaption></figure><?php endif?>
<p class="lead"><?=e($description)?></p>
<?php if($catalogItem&&$catalogItem['cost']==='paid'):?><section class="social-card"><?php tripCurrencySelector();?><h2><?=e($catalogItem['price_label']??$catalogItem['price']??offerLabel('check_price'))?></h2></section><?php endif?>
<?php if($free):?><p class="notice"><?=e(languagePhrase('ללא דמי כניסה לביקור המתואר בלבד. ההחרגות והתוספות מפורטות בהמשך.','No entrance fee for the described visit only. Exclusions and extras are explained below.','Без входной платы за описанное посещение. Исключения и дополнительные услуги указаны ниже.'))?></p><?php endif?>
<div class="article-body"><?=editorialArticleBody($tr['body_markdown']??'',$doc)?></div>
<?php if($detail):?><figure><img class="place-cover" width="1536" height="1024" loading="lazy" src="<?=e($detail['image'])?>" alt="<?=e($detail['alt']?:$title)?>"><figcaption><?=e($detail['credit'])?></figcaption></figure><?php endif?>
<?php if($free):?><p><?=e(languagePhrase('תנאי הכניסה נבדקו במקור בתאריך: ','Source check date: ','Дата проверки источника: ').($free['admission']['checked_at']??''))?>. <?=e(languagePhrase('בדקו לפני היציאה: תנאים ושעות עשויים להשתנות.','Check before visiting: conditions and hours can change.','Проверьте перед визитом: условия и часы работы могут измениться.'))?></p>
<?php endif?>
<?php practicalPanel($doc['id']);?>
<?php if($catalogItem)echo tripSaveButton($catalogItem);?>
<p><a href="<?=e(languageUrl('/explore/',siteLanguage()))?>"><?=e(languagePhrase('עוד רעיונות לטיול','More trip ideas','Другие идеи для поездки'))?></a></p></article>
