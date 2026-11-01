<?php
declare(strict_types=1);
function networkAuditLabel(string $key): string {
 $labels=['pending_title'=>['התוכן שלי שממתין לאישור','My content awaiting approval','Мои материалы на проверке','ჩემი მასალა შემოწმების მოლოდინში'],'pending_private'=>['רק אתם רואים רשימה זו. התוכן טרם פורסם לקהילה.','Only you can see this list. This content is not yet published to the community.','Этот список виден только вам. Материалы ещё не опубликованы в сообществе.','ეს სია მხოლოდ თქვენთვის ჩანს. მასალა საზოგადოებაში ჯერ არ გამოქვეყნებულა.'],'missing_locations'=>['תחנות שחסרות במפה','Stops missing from the map','Остановки без места на карте','რუკაზე არმყოფი გაჩერებები'],'map_partial'=>['אין עדיין מיקום מאומת לתחנות האלו. הן נשארות במסלול ואינן מסומנות במפה.','These stops do not yet have a verified map location. They remain in the itinerary but are not shown on the map.','Для этих остановок пока нет подтверждённого места на карте. Они остаются в маршруте.','ამ გაჩერებებისთვის რუკის მდებარეობა ჯერ არ არის დადასტურებული. ისინი მარშრუტში რჩება.'],'no_public'=>['אין עדיין פרסומים מאושרים להצגה. התוכן הממתין שלכם מופיע למעלה.','No approved publications yet. Your pending content is listed above.','Одобренных публикаций пока нет. Ваши материалы на проверке показаны выше.','დამტკიცებული პუბლიკაციები ჯერ არ არის. თქვენი მომლოდინე მასალა ზემოთაა.']];
 return isset($labels[$key])?languagePhrase(...array_slice($labels[$key],0,3)):$key;
}
function networkOwnPending(string $owner): int {
 $u=socialUser();if(!$u||$u['id']!==$owner)return 0;
 $rows=socialQuery("SELECT l.target,l.language,l.document FROM network_localizations l JOIN network_posts p ON p.id=l.target WHERE l.kind='post' AND p.owner=? AND p.state='pending' AND l.state='pending' ORDER BY p.created DESC",[$u['id']])->fetchAll();
 if(!$rows)return 0;
 echo '<section class="network-card network-pending-private"><h2>'.e(networkAuditLabel('pending_title')).'</h2><p>'.e(networkAuditLabel('pending_private')).'</p><ul>';
 foreach($rows as $r){$doc=json_decode($r['document'],true);echo '<li lang="'.e($r['language']).'" dir="auto"><strong>'.e($doc['title']??'').'</strong> <small>('.e($r['language']).')</small> — '.e(socialT('pending')).'</li>';}
 echo '</ul></section>';return count($rows);
}
