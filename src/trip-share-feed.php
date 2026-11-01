<?php
// Only published snapshots enter discovery. Never read a private plan as content.
function tsFeedRows(array $f,int $before,string $last,string $lang): array {
 tsSchema();$actor=socialUser()['id']??'';$q=mb_substr((string)($f['q']??''),0,100);
 $where=["s.visibility='public'","s.revoked=0","m.state='active'","COALESCE(t.state,'private')!='blocked'","(s.updated<:before OR (s.updated=:before AND 'share:'||s.token<:last))",
 "NOT EXISTS(SELECT 1 FROM network_edges e WHERE e.kind='block' AND ((e.actor=:actor AND e.target=s.owner) OR (e.actor=s.owner AND e.target=:actor)))",
 "NOT EXISTS(SELECT 1 FROM network_edges e WHERE e.actor=:actor AND e.kind IN ('hide','less') AND e.target='share:'||s.token)"];
 $args=['before'=>$before,'last'=>$last?:'~','actor'=>$actor];
 $language="COALESCE(json_extract(s.snapshot,'$.language'),'')";
 if($lang!=='all'){$where[]="($language='' OR $language=:lang)";$args['lang']=$lang;}
 foreach(['country','city'] as $key)if(!empty($f[$key])){$where[]="json_extract(s.snapshot,'$.$key')=:$key";$args[$key]=$f[$key];}
 if(!empty($f['owner'])){$where[]='s.owner=:owner';$args['owner']=$f['owner'];}
 if(!empty($f['type'])){if(!in_array($f['type'],['trip','route','collection','story'],true))return [];if($f['type']!=='trip'){$where[]="json_extract(s.snapshot,'$.kind')=:type";$args['type']=$f['type'];}}
 if($q!==''){$text="COALESCE(json_extract(s.snapshot,'$.title'),'')||' '||COALESCE(json_extract(s.snapshot,'$.description'),'')";$where[]=str_starts_with($q,'#')?"social_hashtag($text,:q)=1":"instr(unicode_lower($text),unicode_lower(:q))>0";$args['q']=$q;}
 if(in_array($f['budget']??'',['free','paid'],true)){$where[]="json_extract(s.snapshot,'$.cost')=:budget";$args['budget']=$f['budget'];}
 if(($minutes=max(0,min(1440,(int)($f['minutes']??0))))>0){$where[]="json_extract(s.snapshot,'$.total_minutes')<=CAST(:minutes AS INTEGER)";$args['minutes']=$minutes;}
 if(($f['tab']??'')==='saved')$where[]="EXISTS(SELECT 1 FROM network_edges e WHERE e.actor=:actor AND e.kind='save' AND e.target='share:'||s.token)";
 if(($f['tab']??'')==='following')$where[]="EXISTS(SELECT 1 FROM network_edges e WHERE e.actor=:actor AND ((e.kind='follow' AND e.target=s.owner) OR (e.kind='follow_city' AND e.target=json_extract(s.snapshot,'$.city'))))";
 return socialQuery("SELECT 'share' kind,s.token target,$language language,'published' state,s.snapshot document,s.revision source_version,s.updated,s.owner,m.handle,COALESCE(json_extract(s.snapshot,'$.city'),'') city FROM trip_shares s JOIN members m ON m.id=s.owner LEFT JOIN member_trips t ON t.id=s.trip AND t.owner=s.owner WHERE ".implode(' AND ',$where)." ORDER BY s.updated DESC,s.token DESC LIMIT 7",$args)->fetchAll();
}
function tsFeedCard(array $r): void {
 $share=tsRow($r['target']);if(!$share||$share['visibility']!=='public'||!tsReadable($share))return;
 $s=json_decode($share['snapshot'],true);$url=tsUrl($share['token']);
 echo '<article class="network-card sc-card sc-card-trip" data-feed-item="share:'.e($share['token']).'" data-content-language="'.e($r['language']).'">';networkAuthor($r['owner'],(int)$r['updated']);
 echo '<div class="sc-card-meta"><span class="sc-type">'.e(tsT($s['kind'])).'</span><span dir="auto">'.e(tdDestinationText($s)).'</span></div><h2 dir="auto"><a href="'.e($url).'">'.e($s['title']).'</a></h2>';
 if($intro=tdIntro($s))echo '<p dir="auto">'.e($intro).'</p>';
 if($image=tsCoverUrl($share,$s))echo '<a href="'.e($url).'"><img class="ts-feed-cover" src="'.e($image).'" loading="lazy" alt="'.e($s['title']).'" style="width:100%;max-height:320px;object-fit:cover;border-radius:12px"></a>';
 echo '<p>'.count($s['items']).' '.e(tsT('stops')).'</p><div class="ts-actions"><a class="button" href="'.e($url).'">'.e(tsT('open_shared')).'</a><button class="button secondary" data-network-share="'.e($url).'">'.e(nt('share')).'</button></div></article>';
}
