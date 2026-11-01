<?php
$id=$pm[1];$doc=editorialDocuments()[$id]??null;if(!$doc){http_response_code(404);exit;}
$p=practicalData($id);$lang=siteLanguage();$tr=$p['translations'][$lang]??[];$view=($_GET['view']??'')==='map'?'map':'official';
if(!practicalVisible($p,$view)){http_response_code(404);exit;}
// Only this read-only document can be embedded, and only by this site.
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; frame-src https://www.google.com; frame-ancestors 'self'; object-src 'none'; base-uri 'none'");
header('X-Frame-Options: SAMEORIGIN');
?><!doctype html><html lang="<?=e($lang)?>" dir="<?=$lang==='he'?'rtl':'ltr'?>"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e(practicalText('title'))?></title><link rel="stylesheet" href="/assets/practical.css"><script src="/assets/practical.js" defer></script><body class="practical-document"><h1><?=e($doc['editorial']['translations'][$lang]['title']??$id)?></h1>
<?php if($view==='map'&&practicalMapQuery($p)!==''):?>
 <?php practicalMap($p);?>
<?php else:?><h2><?=e(practicalText('official'))?></h2><p><?=e(practicalText('official_note'))?></p><?php if($url=practicalOfficialUrl($p)):?><a class="practical-url" dir="ltr" href="<?=e($url)?>" rel="noopener noreferrer"><?=e($url)?></a><?php endif?><?php endif?>
<?php foreach(['address','hours','directions'] as $field)if(practicalVisible($p,$field)&&!empty($tr[$field])):?><h2><?=e(practicalText($field))?></h2><p><?=nl2br(e($tr[$field]))?></p><?php endif?>
<p><?=e(practicalText('verify'))?></p></body></html>
