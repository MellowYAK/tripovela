<?php
// Read-only aggregates. Caller must authorize before requesting any member data.
function adLabel(string $key): string {
 static $labels; $labels??=json_decode(file_get_contents(ROOT.'/content/admin-dashboard-labels.json'),true,512,JSON_THROW_ON_ERROR);
 return $labels[siteLanguage()][$key]??$labels['en'][$key]??$key;
}
function adStats(int $days=7,bool $includeTests=false,?int $now=null): array {
 requireAdmin();$now??=time();$days=in_array($days,[7,30,90],true)?$days:7;
 $today=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Jerusalem'))->setTime(0,0);
 $start=$today->modify('-'.($days-1).' days')->getTimestamp();$previous=$today->modify('-'.(2*$days-1).' days')->getTimestamp();
 $eligible="SELECT m.id FROM members m LEFT JOIN network_member_flags f ON f.member=m.id WHERE m.state!='deleted'".($includeTests?'':" AND COALESCE(f.audience,'member') NOT IN ('demo','qa','admin')");
 $scalar=fn($sql,$params=[])=>(int)socialQuery($sql,$params)->fetchColumn();
 $registrations=socialQuery("SELECT created FROM members WHERE id IN ($eligible) AND created>=? AND created<=?",[$previous,$now])->fetchAll(PDO::FETCH_COLUMN);
 $daily=[];for($i=0;$i<$days;$i++)$daily[$today->modify('-'.($days-1-$i).' days')->format('Y-m-d')]=0;
 $new=0;$before=0;$todayNew=0;foreach($registrations as $at){if($at<$start){$before++;continue;}$new++;if($at>=$today->getTimestamp())$todayNew++;$date=(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Asia/Jerusalem'))->format('Y-m-d');$daily[$date]++;}
 // Count stored contributions, not views or duplicate telemetry. Removed likes/saves no longer count.
 $actions="SELECT owner member,created at,'posts' category FROM network_posts WHERE state='published'
 UNION ALL SELECT t.owner,COALESCE((SELECT MIN(v.created) FROM network_versions v WHERE v.kind='trip' AND v.target=t.id),t.updated),'posts' FROM member_trips t WHERE t.state='published'
 UNION ALL SELECT owner,created,'comments' FROM network_comments WHERE state='published'
 UNION ALL SELECT owner,created,'places' FROM member_places WHERE state='approved'
 UNION ALL SELECT actor,created,'interactions' FROM network_edges
 UNION ALL SELECT voter,updated,'interactions' FROM member_ratings";
 $cte="WITH activity AS ($actions), selected AS (SELECT * FROM activity WHERE member IN ($eligible) AND at>=CAST(? AS INTEGER) AND at<=CAST(? AS INTEGER)) ";
 $counts=['posts'=>0,'comments'=>0,'places'=>0,'interactions'=>0];foreach(socialQuery($cte.'SELECT category,COUNT(*) total FROM selected GROUP BY category',[$start,$now]) as $row)$counts[$row['category']]=(int)$row['total'];
 $active=$scalar($cte.'SELECT COUNT(DISTINCT member) FROM selected',[$start,$now]);
 $top=socialQuery($cte."SELECT m.id,m.handle,COUNT(*) total,SUM(category='posts') posts,SUM(category='comments') comments,SUM(category='places') places,SUM(category='interactions') interactions,MAX(at) last_at FROM selected a JOIN members m ON m.id=a.member GROUP BY m.id ORDER BY total DESC,last_at DESC,m.id LIMIT 10",[$start,$now])->fetchAll();
 $members=socialQuery("SELECT id,handle,created FROM members WHERE id IN ($eligible) AND created BETWEEN ? AND ? ORDER BY created DESC,id LIMIT 10",[$start,$now])->fetchAll();
 $publications="SELECT id,owner,'post' kind,created FROM network_posts WHERE state='published' UNION ALL SELECT t.id,t.owner,'trip',COALESCE((SELECT MIN(v.created) FROM network_versions v WHERE v.kind='trip' AND v.target=t.id),t.updated) FROM member_trips t WHERE t.state='published'";
 $posts=socialQuery("WITH publications AS ($publications) SELECT p.*,m.handle,(SELECT document FROM network_localizations l WHERE l.kind=p.kind AND l.target=p.id AND l.state='published' ORDER BY l.updated DESC LIMIT 1) document FROM publications p JOIN members m ON m.id=p.owner WHERE p.owner IN ($eligible) AND p.created BETWEEN CAST(? AS INTEGER) AND CAST(? AS INTEGER) ORDER BY p.created DESC,p.id LIMIT 10",[$start,$now])->fetchAll();
 return compact('days','now','start','todayNew','new','before','daily','counts','active','top','members','posts')+['total'=>$scalar("SELECT COUNT(*) FROM members WHERE id IN ($eligible)"),'excluded'=>$scalar("SELECT COUNT(*) FROM members m JOIN network_member_flags f ON f.member=m.id WHERE f.audience IN ('demo','qa','admin') AND m.state!='deleted'")];
}
