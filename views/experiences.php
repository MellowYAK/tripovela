<?php $title=languagePhrase('אטרקציות וסיורים','Tours & activities','Экскурсии');$results=activityResults($_GET); ?>
<section class="page-heading compact-heading" translate="no"><span class="eyebrow">Tripovela · Viator</span><h1><?=e($title)?></h1><p><?=e(languagePhrase('בחרו יעד, השוו את הפרטים ובדקו זמינות.','Choose a destination, compare the details and check availability.','Выберите направление, сравните детали и проверьте наличие.'))?></p></section>
<?php activityFilters($path==='/offers/'?'/offers/':'/experiences/');?>
<p translate="no"><?=count($results)?> <?=e(languagePhrase('חוויות מתאימות','matching experiences','подходящих вариантов'))?> · <a href="/experiences/"><?=e(languagePhrase('ניקוי מסננים','Clear filters','Сбросить фильтры'))?></a></p>
<?php if(!$results):?><p class="notice"><?=e(languagePhrase('אין פעילות תואמת. נסו יעד אחר או חפשו במדריכי התכנון.','No matching activity. Try another destination or search the planning guides.','Подходящих вариантов нет. Измените поиск.'))?></p><?php endif?>
<div class="activity-grid"><?php foreach($results as $a)activityCard($a,$path==='/offers/'?'offers':'catalog');?></div>
<?php activityDisclosure();?>
