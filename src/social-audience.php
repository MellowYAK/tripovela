<?php
// Publication audiences apply to every edition and attachment, independently of follows.
function saMigrate(PDO $db): void {
 $db->exec("CREATE TABLE IF NOT EXISTS social_audiences(kind TEXT NOT NULL,target TEXT NOT NULL,owner TEXT NOT NULL,audience TEXT NOT NULL,PRIMARY KEY(kind,target));
 CREATE TABLE IF NOT EXISTS social_recipients(kind TEXT NOT NULL,target TEXT NOT NULL,member TEXT NOT NULL,PRIMARY KEY(kind,target,member));
 CREATE TABLE IF NOT EXISTS social_friendships(a TEXT NOT NULL,b TEXT NOT NULL,requester TEXT NOT NULL,state TEXT NOT NULL,created INTEGER NOT NULL,PRIMARY KEY(a,b));
 CREATE TABLE IF NOT EXISTS social_display_preferences(member TEXT PRIMARY KEY,mode TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS social_mentions(kind TEXT NOT NULL,target TEXT NOT NULL,member TEXT NOT NULL,PRIMARY KEY(kind,target,member));
 CREATE INDEX IF NOT EXISTS social_recipient_member ON social_recipients(member,kind,target);");
}
function saText(string $key): string {
 $labels=[
 'audience'=>['Who can see this post?','מי יכול לראות את הפרסום?','Кто видит публикацию?'],
 'public'=>['Public','כולם','Все'], 'friends'=>['Friends','חברים','Друзья'],
 'extended'=>['Friends and their friends','חברים וחברים של חברים','Друзья и друзья друзей'],
 'specific'=>['Specific people','אנשים מסוימים','Выбранные люди'],
 'recipients'=>['Specific people: @usernames, separated by commas','אנשים מסוימים: שמות משתמש מופרדים בפסיקים','Выбранные люди: @имена через запятую'],
 'locked'=>['Choose before publishing. The audience is locked after submission.','בחרו לפני הפרסום. הקהל ננעל לאחר השליחה.','Выберите до публикации. После отправки аудиторию изменить нельзя.'],
 'display'=>['Display me as','הציגו אותי בתור','Показывать меня как'],
 'name'=>['Name','שם','Имя'], 'nickname'=>['Nickname (@username)','כינוי (@שם משתמש)','Псевдоним (@имя пользователя)'],
 'request'=>['Add friend','הוספת חבר','Добавить в друзья'], 'accept'=>['Accept friendship','אישור חברות','Принять запрос'],
 'remove'=>['Remove / cancel friendship','ביטול חברות / בקשה','Удалить друга / отменить запрос'],
 'pending'=>['Friend request sent','בקשת חברות נשלחה','Запрос отправлен'],
 'mention'=>['You were mentioned','הזכירו אותך','Вас упомянули'],
 'friend_request'=>['Friend request','בקשת חברות','Запрос дружбы'],
 'hint'=>['Use @username to mention someone and #topic for hashtags.','השתמשו ב־@שם_משתמש לאזכור וב־#נושא להאשטאג.','Используйте @имя для упоминаний и #тема для хештегов.'],
 ];static $translated;$translated??=json_decode(file_get_contents(ROOT.'/content/social-audience-labels.json'),true);if(isset($translated[siteLanguage()][$key]))return $translated[siteLanguage()][$key];$r=$labels[$key]??[$key,$key,$key];return scText($r[0],$r[1],$r[2]);
}
function saFriendSql(string $a,string $b): string {
 return "EXISTS(SELECT 1 FROM social_friendships sf WHERE sf.state='accepted' AND ((sf.a=$a AND sf.b=$b) OR (sf.b=$a AND sf.a=$b)))";
}
function saSql(string $kind,string $target,string $owner,string $viewer): string {
 $v=socialDb()->quote($viewer);$friend=saFriendSql($owner,$v);
 $fof=saFriendSql($owner,'sm.id').' AND '.saFriendSql('sm.id',$v);
 return "(NOT EXISTS(SELECT 1 FROM social_audiences sa WHERE sa.kind=$kind AND sa.target=$target AND sa.audience!='public') OR ($v!='' AND ($owner=$v OR EXISTS(SELECT 1 FROM social_audiences sa WHERE sa.kind=$kind AND sa.target=$target AND ((sa.audience IN ('friends','extended') AND $friend) OR (sa.audience='extended' AND EXISTS(SELECT 1 FROM members sm WHERE sm.state='active' AND $fof AND NOT EXISTS(SELECT 1 FROM network_edges bl WHERE bl.kind='block' AND ((bl.actor=sm.id AND bl.target IN ($v,$owner)) OR (bl.target=sm.id AND bl.actor IN ($v,$owner)))))) OR (sa.audience='specific' AND EXISTS(SELECT 1 FROM social_recipients sr WHERE sr.kind=$kind AND sr.target=$target AND sr.member=$v)))))))";
}
function saCanView(string $kind,string $id,string $owner,?string $viewer=null): bool {
 $viewer=$viewer??(socialUser()['id']??'');
 if(networkBlocked($viewer,$owner))return false;
 // Same anonymous branch as saSql, without preparing unreachable friendship subqueries.
 // Read on every call so moderation/audience changes are never hidden by a permission cache.
 if($viewer==='')return !socialQuery("SELECT 1 FROM social_audiences WHERE kind=? AND target=? AND audience!='public' LIMIT 1",[$kind,$id])->fetchColumn();
 $d=socialDb();return (bool)socialQuery('SELECT 1 WHERE '.saSql($d->quote($kind),$d->quote($id),$d->quote($owner),$viewer))->fetchColumn();
}
function saInput(array $raw,string $owner,bool $draft=false): array {
 $audience=$raw['audience']??'public';if(!is_string($audience)||!in_array($audience,['public','friends','extended','specific'],true))throw new DomainException('invalid');
 $text=socialString($raw['audience_people']??'',2000);$ids=[];
 if($audience==='specific'){
  foreach(preg_split('/[\s,;]+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY) as $handle){
   $handle=ltrim($handle,'@');$id=socialQuery("SELECT id FROM members WHERE handle=? AND state='active'",[mb_strtolower($handle)])->fetchColumn();
   if(!$id||networkBlocked($owner,$id)){if($draft)continue;throw new DomainException('not_found');}if($id!==$owner)$ids[$id]=$id;
  }
  if((!$ids&&!$draft)||count($ids)>50)throw new DomainException('invalid');
 }
 return ['audience'=>$audience,'audience_people'=>$text,'audience_members'=>array_values($ids)];
}
function saStore(string $kind,string $id,string $owner,array $p): void {
 // No UPDATE endpoint: visitors and authors cannot alter a submitted audience.
 $existing=socialQuery('SELECT * FROM social_audiences WHERE kind=? AND target=?',[$kind,$id])->fetch();
 if($existing){if($existing['owner']!==$owner||$existing['audience']!==$p['audience'])throw new DomainException('conflict');return;}
 socialQuery('INSERT INTO social_audiences VALUES(?,?,?,?)',[$kind,$id,$owner,$p['audience']]);
 foreach($p['audience_members']??[] as $member)socialQuery('INSERT INTO social_recipients VALUES(?,?,?)',[$kind,$id,$member]);
}
function saFields(array $doc=[]): void {
 $p=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:$doc;$selected=$p['audience']??'public';
 echo '<fieldset class="sa-audience"><legend>'.e(saText('audience')).'</legend><select name="audience" aria-label="'.e(saText('audience')).'">';
 foreach(['public','friends','extended','specific'] as $key)echo '<option value="'.$key.'" '.($selected===$key?'selected':'').'>'.e(saText($key)).'</option>';
 echo '</select><label>'.e(saText('recipients')).'<input name="audience_people" maxlength="2000" placeholder="@username, @another" value="'.e($p['audience_people']??'').'" autocomplete="off"></label><small>'.e(saText('locked')).'</small></fieldset><small class="sa-writing-hint">'.e(saText('hint')).'</small>';
}
function saFriendAction(array $u,string $target,string $action): void {
 if($u['id']===$target||!networkProfile($target)||networkBlocked($u['id'],$target))throw new DomainException('not_found');
 socialLimit('friend',60,3600);$pair=[$u['id'],$target];sort($pair);[$a,$b]=$pair;
 if($action==='request'){
  $q=socialQuery("INSERT OR IGNORE INTO social_friendships VALUES(?,?,?,'pending',?)",[$a,$b,$u['id'],time()]);
  if($q->rowCount())networkNotice($target,'friend_request',$u['id'],networkProfile($target)['language']?:'en');
 }elseif($action==='accept')socialQuery("UPDATE social_friendships SET state='accepted' WHERE a=? AND b=? AND requester=? AND state='pending'",[$a,$b,$target]);
 elseif($action==='remove')socialQuery('DELETE FROM social_friendships WHERE a=? AND b=?',[$a,$b]);
 else throw new DomainException('invalid');
}
function saFriendControls(string $target): void {
 $u=socialUser();if(!$u||$u['id']===$target||networkBlocked($u['id'],$target))return;
 $pair=[$u['id'],$target];sort($pair);$r=socialQuery('SELECT * FROM social_friendships WHERE a=? AND b=?',$pair)->fetch();
 $actions=$r?($r['state']==='pending'&&$r['requester']!==$u['id']?['accept','remove']:['remove']):['request'];
 if($r)echo '<small>'.e(saText($r['state']==='accepted'?'friends':($r['requester']===$u['id']?'pending':'friend_request'))).'</small>';
 foreach($actions as $action)echo '<form method="post" class="sa-friend">'.csrf().'<input type="hidden" name="network_action" value="friend"><input type="hidden" name="target" value="'.e($target).'"><button class="button secondary" name="friend_action" value="'.$action.'">'.e(saText($action)).'</button></form>';
}
function saDisplayFields(array $p): void {
 echo '<label>'.e(saText('display')).'<select name="display_mode">';foreach(['name','nickname'] as $key)echo '<option value="'.$key.'" '.(($p['display_mode']??'name')===$key?'selected':'').'>'.e(saText($key)).'</option>';echo '</select></label>';
}
function saFriendsPanel(array $u): void {
 echo '<section class="network-card network-form"><h2>'.e(saText('friends')).'</h2>';
 $rows=socialQuery('SELECT * FROM social_friendships WHERE a=? OR b=? ORDER BY created DESC',[$u['id'],$u['id']])->fetchAll();
 foreach($rows as $r){$id=$r['a']===$u['id']?$r['b']:$r['a'];$p=networkProfile($id);if(!$p||networkBlocked($u['id'],$id))continue;
  echo '<div class="sa-friend-row"><a href="'.e(languageUrl('/community/members/'.$p['handle'].'/',siteLanguage())).'">'.e($p['name']?:$p['handle']).' · @'.e($p['handle']).'</a>';saFriendControls($id);echo '</div>';
 }echo '<a href="'.e(languageUrl('/community/search/',siteLanguage())).'">'.e(nt('search')).'</a></section>';
}
function saTokens(string $text,string $prefix): array {
 $pattern=$prefix==='@'?'/(?<![\p{L}\p{N}_@])@([a-zA-Z0-9_-]{3,30})(?![a-zA-Z0-9_-])/u':'/(?<![\p{L}\p{N}_#])#([\p{L}\p{N}_]{1,64})(?![\p{L}\p{N}_])/u';
 preg_match_all($pattern,$text,$m);return array_values(array_unique(array_map('mb_strtolower',$m[1])));
}
function saRichText(string $text): string {
 $pattern='/(?<![\p{L}\p{N}_@#])(@[a-zA-Z0-9_-]{3,30}(?![a-zA-Z0-9_-])|#[\p{L}\p{N}_]{1,64}(?![\p{L}\p{N}_]))/u';
 $parts=preg_split($pattern,$text,-1,PREG_SPLIT_DELIM_CAPTURE);$out='';
 foreach($parts as $i=>$part){if($i%2===0){$out.=e($part);continue;}$token=mb_substr($part,1);$url='';
  if($part[0]==='#')$url=languageUrl('/community/?q='.rawurlencode('#'.$token).'&feed_language='.rawurlencode(networkFeedLanguage()),siteLanguage());
  else{$member=socialQuery("SELECT id,handle FROM members WHERE handle=? AND state='active'",[mb_strtolower($token)])->fetch();if($member&&!networkBlocked(socialUser()['id']??'',$member['id']))$url=languageUrl('/community/members/'.$member['handle'].'/',siteLanguage());}
  $out.=$url?'<a class="sa-token" href="'.e($url).'">'.e($part).'</a>':e($part);
 }return nl2br($out);
}
function saMentionNotices(string $kind,string $id,string $owner,string $text,string $lang,string $noticeKey='',string $actor=''): void {
 foreach(array_slice(saTokens($text,'@'),0,30) as $handle){$m=socialQuery("SELECT id FROM members WHERE handle=? AND state='active'",[$handle])->fetchColumn();
  if(!$m||$m===($actor?:$owner)||networkBlocked($actor?:$owner,$m)||!saCanView($kind,$id,$owner,$m))continue;
  $key=$noticeKey?:$id;$q=socialQuery('INSERT OR IGNORE INTO social_mentions VALUES(?,?,?)',[$kind,$key,$m]);
  if($q->rowCount())networkNotice($m,'mention',$kind.':'.$id.':'.$lang,$lang);
 }
}
function saNoticeUrl(array $n): string {
 if(($n['member']??'')!==(socialUser()['id']??''))return '';
 if($n['kind']==='reply'){
  $parts=explode(':',$n['target']);$source=sxSource(['kind'=>$parts[0]??'','target'=>$parts[1]??'','comment'=>$parts[2]??'','edition'=>$n['language']]);return $source['url']??'';
 }
 if(in_array($n['kind'],['approve','reject','block'],true)){
  foreach(['post'=>'network_posts','trip'=>'member_trips','place'=>'member_places'] as $kind=>$table)if(socialQuery("SELECT 1 FROM $table WHERE id=? AND owner=?",[$n['target'],$n['member']])->fetchColumn())return (sxSource(['kind'=>$kind,'target'=>$n['target'],'edition'=>$n['language']])['url']??languageUrl('/community/profile/',siteLanguage()));return '';
 }
 if($n['kind']==='review_update')return $n['member']===(socialUser()['id']??'')?languageUrl('/community/profile/',siteLanguage()).'#community-review-status':'';
 if($n['kind']==='continuation'){
  sxSchema();$viewer=socialUser()['id']??'';if(!$viewer||($n['member']??'')!==$viewer)return '';
  foreach(socialQuery('SELECT * FROM social_continuations WHERE post=? AND author=?',[$n['target'],$viewer]) as $source)if(sxSource($source))return sxSource(['kind'=>'post','target'=>$n['target'],'edition'=>$n['language']])['url']??'';
  return '';
 }

 if($n['kind']==='friend_request'){$p=networkProfile($n['target']);return $p&&!networkBlocked(socialUser()['id']??'',$p['id'])?languageUrl('/community/members/'.$p['handle'].'/',siteLanguage()):'';}
 if($n['kind']==='mention'){$parts=explode(':',$n['target']);if(count($parts)!==3)return '';[$kind,$id,$lang]=$parts;$owner=socialQuery('SELECT owner FROM social_audiences WHERE kind=? AND target=?',[$kind,$id])->fetchColumn();
  $table=['post'=>'network_posts','trip'=>'member_trips','place'=>'member_places'][$kind]??'';if(!$table)return '';
  $owner=$owner?:socialQuery("SELECT owner FROM $table WHERE id=?",[$id])->fetchColumn();if(!$owner||!saCanView($kind,$id,$owner))return '';
  return languageUrl('/community/'.['post'=>'posts','trip'=>'trips','place'=>'places'][$kind].'/'.$id.'/?content_lang='.rawurlencode($lang),siteLanguage());
 }return '';
}
