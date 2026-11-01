<?php
$title=languagePhrase('בונים את הטיול שלכם','Build your trip','Спланируйте путешествие');
$items=tripCatalog();$dest=[];foreach(tripDestinations() as $key=>$d)$dest[$key]=activityLabel($d['labels']);
$types=[];foreach(activityTypes() as $key=>$labels)$types[$key]=activityLabel($labels);
if($path==='/my-trip/'){require_once ROOT.'/views/social-experience-nav.php';echo '<div class="sx-trip-shell">';sxNavigation();echo '<div>';}
?>
<?php if($path==='/my-trip/'):?><section id="sx-my-trips" class="network-card"><h1><?=e(nt('my_trips'))?></h1><div data-sx-trips></div></section><details class="sx-legacy-planner"><summary><?=e(nt('build_trip'))?></summary><?php endif?>
<section id="trip-planner" translate="no" data-taxonomy="<?=e(json_encode(discoveryTaxonomy(),JSON_UNESCAPED_UNICODE))?>" data-country="<?=e($country??'')?>" data-destination-meta="<?=e(json_encode(tripDestinations(),JSON_UNESCAPED_UNICODE))?>" data-query="<?=e(isset($selected)&&$selected?'':($q??''))?>" data-activity-type="<?=e($type??'')?>" data-language="<?=e(siteLanguage())?>" data-step="<?=$path==='/my-trip/'?4:($path==='/explore/'?3:1)?>" data-destination="<?=e($selected??'')?>" data-catalog="<?=e(json_encode($items,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP))?>" data-destinations="<?=e(json_encode($dest,JSON_UNESCAPED_UNICODE))?>" data-types="<?=e(json_encode($types,JSON_UNESCAPED_UNICODE))?>">
<nav id="planner-steps" aria-label="<?=e($title)?>"></nav><div id="planner-screen"><p role="status"><?=e(languagePhrase('טוענים רעיונות לטיול…','Loading trip ideas…','Загружаем идеи…'))?></p></div><p id="planner-status" role="status" aria-live="polite"></p>
<div hidden id="planner-guide-titles"><?php foreach($articles??[] as $savedArticle):?><span data-guide-id="<?=e($savedArticle['id'])?>"><?=e(translatedText($savedArticle['title']))?></span><?php endforeach?></div>
<noscript><?=e(languagePhrase('המתכנן דורש JavaScript. המדריכים זמינים בתפריט האתר.','The planner needs JavaScript. Guides remain available from the site menu.','Для планировщика нужен JavaScript. Статьи доступны в меню сайта.'))?></noscript>
</section>

<?php if($path==='/my-trip/'):?></details></div></div><?php endif?>
