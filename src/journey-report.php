<?php
// Included only by the authenticated reports controller.
requireAdmin();
$timelineSql="SELECT * FROM (SELECT 'view' AS event_type,p.created_at,p.created_at AS entry_at,'' AS provider,'' AS campaign,p.source,p.medium,p.ad_campaign,p.ad,p.ref,p.param,p.page,'' AS placement,p.referrer_domain FROM page_events p UNION ALL SELECT 'outbound',o.created_at,p.created_at,o.provider,o.campaign,o.source,o.medium,o.ad_campaign,o.ad,o.ref,o.param,o.page,o.placement,COALESCE(p.referrer_domain,'') FROM outbound_events o LEFT JOIN page_events p ON p.id=o.entry_id) events".$where.' ORDER BY created_at DESC';
$timelineColumns=['event_type'=>'אירוע','created_at'=>'מועד האירוע — ישראל','entry_at'=>'מועד הכניסה — ישראל','provider'=>'ספק','campaign'=>'סוג הצעה','source'=>'מקור','medium'=>'ערוץ','ad_campaign'=>'קמפיין פרסום','ad'=>'מזהה מודעה','ref'=>'REF','param'=>'PARAM','page'=>'עמוד באתר','placement'=>'מיקום לחיצה','referrer_domain'=>'דומיין מפנה'];
$timelineValue=static function($key,$value) use ($catalog): string {
 if($value===null||$value==='')return 'לא ידוע';
 if(in_array($key,['created_at','entry_at'],true))return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Jerusalem'))->format('d/m/Y H:i:s');
 if($key==='event_type')return $value==='view'?'צפייה בדף':'יציאה לספק';
 if($key==='provider')return $catalog[$value]['name']??$value;
 if($key==='placement')return ['family_top'=>'תחילת המדריך','family_middle'=>'אמצע המדריך','family_bottom'=>'סוף המדריך'][$value]??$value;
 return (string)$value;
};
if(isset($_GET['timeline_csv'])){
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="tripovela-journey.csv"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array_values($timelineColumns));
 foreach(query($timelineSql,$args) as $event){$values=[];foreach($timelineColumns as $key=>$label)$values[]=$timelineValue($key,$event[$key]);fputcsv($out,$values);}fclose($out);exit;
}
ob_start();?>
<section class="form-panel"><h2>כניסות ולחיצות לפי שעה ומודעה</h2><p>הטבלה מציגה צפיות בדפים ויציאות לספק בנפרד. השעות בטבלה הן שעון ישראל; מסנן התאריכים למעלה הוא UTC. איסוף צפיות החל ב־23/09/2026. כניסות ישנות אינן ניתנות לשחזור. מזהה מודעה ומקור מוצגים כאשר הגיעו בקישור; דומיין מפנה מוצג רק אם נמסר מהדפדפן. בקשות חוזרות ובוטים עשויים להיספר — אלה אינם אנשים ייחודיים או רכישות.</p><p><a class="button secondary" href="?<?=e(http_build_query(array_merge($_GET,['timeline_csv'=>'1'])))?>">ייצוא כניסות ולחיצות CSV</a></p><div class="table-scroll"><table><thead><tr><?php foreach($timelineColumns as $label):?><th><?=e($label)?></th><?php endforeach?></tr></thead><tbody><?php $events=query($timelineSql.' LIMIT 300',$args)->fetchAll();foreach($events as $event):?><tr><?php foreach($timelineColumns as $key=>$label):?><td><?=e($timelineValue($key,$event[$key]))?></td><?php endforeach?></tr><?php endforeach?><?php if(!$events):?><tr><td colspan="14">אין אירועים תואמים עדיין.</td></tr><?php endif?></tbody></table></div><p>מוצגים עד 300 אירועים אחרונים לפי הסינון; הייצוא כולל את כל האירועים התואמים. סינון לפי ספק מציג רק יציאות לאותו ספק.</p></section>
<?php $journeyReport=ob_get_clean();
