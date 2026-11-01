<?php
function revLabels(): array {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/review-labels.json'),true,512,JSON_THROW_ON_ERROR);return array_replace($all['en'],$all[siteLanguage()]??[]);}
function revT(string $key): string {return revLabels()[$key]??nt('unavailable');}
function revError(string $code): string {return in_array($code,['too_many_linked_routes','too_many_linked_places','reply_unavailable','route_unavailable'],true)?str_replace(['{routes}','{places}'],[revAttachmentLimits()['trip'],revAttachmentLimits()['place']],revT($code)):'';}
function revAttachmentLimits(): array {static $limits;$limits??=json_decode(file_get_contents(ROOT.'/content/comment-attachments.json'),true,512,JSON_THROW_ON_ERROR);return $limits;}
function revReplyParent(string $kind,string $target,string $id,array $u): ?array {
 if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!networkTarget($kind,$target))return null;
 $row=socialQuery("SELECT c.*,m.handle FROM network_comments c JOIN members m ON m.id=c.owner WHERE c.id=? AND c.kind=? AND c.target=? AND c.state='published' AND m.state='active'",[$id,$kind,$target])->fetch();
 return $row&&!networkBlocked($u['id'],$row['owner'])&&!crHidden('comment',$id)?$row:null;
}
function revReplyFields(string $kind,string $target,array $u): string {
 $retry=($_POST['network_action']??'')==='comment'&&($_POST['kind']??'')===$kind&&($_POST['target']??'')===$target;
 $id=$retry&&is_string($_POST['parent_comment']??null)?$_POST['parent_comment']:'';
 if(!preg_match('/^[a-f0-9]{32}$/D',$id))$id='';
 $parent=$id?revReplyParent($kind,$target,$id,$u):null;
 $html='<input type="hidden" name="parent_comment" value="'.e($id).'"><p data-sx-reply-status aria-live="polite">';
 if($id){$html.=$parent?'<a href="#comment-'.e($id).'">'.e(str_replace('{name}',$parent['handle'],revT('reply_to'))).'</a>':e(revT('reply_unavailable'));$html.=' <button type="button" data-reply-cancel>'.e(revT('cancel_reply')).'</button>';}
 return $html.'</p>';
}
function revCommentToken(): string {$token=$_POST['comment_token']??'';return is_string($token)&&preg_match('/^[a-f0-9]{16,64}$/D',$token)?$token:bin2hex(random_bytes(16));}
// Comment links must be visible to every reader, irrespective of the author's private access.
// Resolve a published edition without binding attachment eligibility to the current UI language.
function revAttachableRoute(string $id): ?array {
 if(!preg_match('/^[a-f0-9]{32}$/D',$id)||crHidden('trip',$id))return null;
 $r=socialQuery("SELECT t.*,m.handle FROM member_trips t JOIN members m ON m.id=t.owner WHERE t.id=? AND t.public_plan IS NOT NULL AND t.state NOT IN ('blocked','removed') AND m.state='active'",[$id])->fetch();
 if(!$r||!saCanView('trip',$id,$r['owner'],'')||!saCanView('trip',$id,$r['owner'])||networkBlocked(socialUser()['id']??'',$r['owner']))return null;
 $l=socialQuery("SELECT * FROM network_localizations WHERE kind='trip' AND target=? AND state='published' ORDER BY source_version LIMIT 1",[$id])->fetch();
 if(!$l||!networkRouteVisible(json_decode($l['document'],true),$l['language']))return null;
 $r['public_plan']=$l['document'];$r['language']=$l['language'];return $r;
}
