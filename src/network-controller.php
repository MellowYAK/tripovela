<?php
require __DIR__.'/community-review-controller.php';
require __DIR__.'/community-places-controller.php';
if($path==='/community/create/'&&$_SERVER['REQUEST_METHOD']==='GET'&&($_GET['kind']??'')==='trip'&&empty($_GET['draft'])){
 header('Location: '.languageUrl('/my-trip/?create_share=1',siteLanguage()));exit;
}
if($path==='/community/create/'&&$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['network_action']??'')==='draft_trash'){
 header('Content-Type: application/json');try{$u=socialRequire();scTrashDraft($u,(string)($_POST['draft_id']??''),($_POST['restore']??'')==='1');echo '{"ok":true}';}catch(DomainException $e){http_response_code(422);echo '{"ok":false}';}exit;
}

if($path==='/api/community-events/'&&$_SERVER['REQUEST_METHOD']==='POST'){header('Content-Type: application/json');$event=(string)($_POST['event']??'');$kind=(string)($_POST['kind']??'');$id=(string)($_POST['target']??'');try{socialLimit('event',300,3600);if(!in_array($event,['feed_view','share_intent'],true)||!networkTarget($kind,$id))throw new DomainException('invalid');networkEvent($event,$kind,$id,$event.':'.$kind.':'.$id.':'.gmdate('Y-m-d-H'));echo '{"ok":true}';}catch(DomainException $e){http_response_code(422);echo '{"ok":false}';}exit;}
if($path==='/community/create/'&&$_SERVER['REQUEST_METHOD']==='POST'&&in_array($_POST['network_action']??'',['route_save','composer_save'],true)){
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
 try{$u=socialUser();if(!$u){http_response_code(401);echo json_encode(['error'=>'login']);exit;}
  if($_POST['network_action']==='route_save')$_POST['post_kind']='trip';$url=scSubmit($u);$result=['ok'=>true,'url'=>$url,'published'=>($_POST['intent']??'')!=='draft'];
  if(!$result['published']){parse_str(parse_url($url,PHP_URL_QUERY)??'',$args);$draft=scDraft($u,$args['draft']);$result['draft_id']=$draft['id'];$result['revision']=(int)$draft['revision'];$result['document']=json_decode($draft['document'],true);}
  else $_SESSION['network_message']=!empty($GLOBALS['sc_pending_submission'])?nt('pending_notice'):rxT('publication_published');echo json_encode($result,JSON_UNESCAPED_UNICODE);
 }catch(DomainException $e){http_response_code($e->getMessage()==='conflict'?409:422);echo json_encode(['error'=>$e->getMessage(),'message'=>($e->getMessage()==='located_required'?sxT('located_required'):socialT($e->getMessage()))]);}
 catch(Throwable $e){error_log('route_save: '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'save_failed']);}exit;
}
// Invoked after session, CSRF and language selection, before the legacy community routes.
if(preg_match('~^/community/avatar/([a-f0-9]{32})/$~D',$path,$nm)){
 $p=networkProfile($nm[1]);$file=qaPhotoPath($p['avatar']??'');
 if(!$p||networkBlocked(socialUser()['id']??'',$p['id'])||empty($p['avatar'])||!is_file($file)||!crPhotoAllowed($p['avatar'],true)){http_response_code(404);exit;}
 header('Content-Type: image/jpeg');header('Cache-Control: private, no-store');readfile($file);exit;
}
if($path==='/community/export/'){
 $u=socialUser();if(!$u){http_response_code(401);exit;}$data=['exported_at'=>gmdate('c'),'profile'=>networkProfile($u['id'])];
 foreach(['trips'=>['member_trips','owner'],'places'=>['member_places','owner'],'posts'=>['network_posts','owner'],'translations'=>['network_submissions','owner'],'appeals'=>['network_appeals','owner'],'comments'=>['network_comments','owner'],'preferences'=>['network_edges','actor']] as $key=>[$table,$field])$data[$key]=socialQuery("SELECT * FROM $table WHERE $field=?",[$u['id']])->fetchAll();
 scSchema();$data['drafts']=socialQuery('SELECT * FROM social_drafts WHERE owner=?',[$u['id']])->fetchAll();$data['comment_attachments']=socialQuery('SELECT l.* FROM social_comment_links l JOIN network_comments c ON c.id=l.comment WHERE c.owner=?',[$u['id']])->fetchAll();
 $data['language_preferences']=networkLanguagePreferences($u);$data['distance_unit']=rxUnitPreference();
 sxSchema();$data['trip_sources']=socialQuery('SELECT s.* FROM social_trip_sources s JOIN member_trips t ON t.id=s.trip WHERE t.owner=?',[$u['id']])->fetchAll();$data['continuations']=socialQuery('SELECT s.* FROM social_continuations s JOIN network_posts p ON p.id=s.post WHERE p.owner=?',[$u['id']])->fetchAll();$data['flow_events']=socialQuery('SELECT * FROM social_flow_events WHERE actor=?',[$u['id']])->fetchAll();
 mrSchema();$data['email_identity']=socialQuery('SELECT email,verified_at FROM member_email_identity WHERE member=?',[$u['id']])->fetch()?:null;$data['audiences']=socialQuery('SELECT * FROM social_audiences WHERE owner=?',[$u['id']])->fetchAll();$data['audience_recipients']=socialQuery('SELECT r.* FROM social_recipients r JOIN social_audiences a ON a.kind=r.kind AND a.target=r.target WHERE a.owner=?',[$u['id']])->fetchAll();$data['friendships']=socialQuery('SELECT * FROM social_friendships WHERE a=? OR b=?',[$u['id'],$u['id']])->fetchAll();
 $data['post_texts']=socialQuery("SELECT l.* FROM network_localizations l JOIN network_posts p ON p.id=l.target AND l.kind='post' WHERE p.owner=?",[$u['id']])->fetchAll();
 header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="tripovela-my-data.json"');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
}
if($path==='/community/account/'&&$_SERVER['REQUEST_METHOD']==='GET'&&socialUser()){redirect(languageUrl('/community/profile/',siteLanguage()));}
$networkPaths=['/community/search/','/','/community/','/community/profile/','/community/create/','/community/saved/','/community/notifications/','/community/translations/','/community/source-update/'];
$networkPage=in_array($path,$networkPaths,true)||preg_match('~^/community/(members/[a-z0-9_-]{3,30}|(?:posts|trips)/[a-f0-9]{32})/$~D',$path);
if($path==='/community/profile/'&&isset($_SESSION['social_message'])){$_SESSION['network_message']=$_SESSION['social_message'];unset($_SESSION['social_message']);}
$networkError='';$networkMessage=$_SESSION['network_message']??'';unset($_SESSION['network_message']);
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['network_action'])){
 try{
  if(($_POST['network_action']??'')==='feed_language'){networkSaveLanguagePreferences(socialUser(),$_POST);$_SESSION['network_message']=nt('done');$query=array_intersect_key($_GET,array_flip(['tab','country','city','q','budget','minutes','type','lang']));$query['feed_language']=$_POST['feed_language'];foreach(['country','city'] as $destinationField)if(is_string($_POST[$destinationField]??null))$query[$destinationField]=$_POST[$destinationField];redirect(languageUrl($path.'?'.http_build_query($query),siteLanguage()));}
  $u=socialRequire();$action=(string)$_POST['network_action'];$kind=(string)($_POST['kind']??'');$target=(string)($_POST['target']??'');
  if($action==='edit_start'){redirect(seStart($u,$kind,$target));}
  elseif($action==='compose'){ $url=scSubmit($u);$_SESSION['network_message']=($_POST['intent']??'')==='draft'?scText('Draft saved','הטיוטה נשמרה','Черновик сохранён'):(!empty($GLOBALS['sc_pending_submission'])?nt('pending_notice'):rxT('publication_published'));redirect($url);}
  elseif($action==='language_preferences'){rxSaveUnit($u,$_POST);networkSaveLanguagePreferences($u,$_POST);$_SESSION['network_message']=nt('done');redirect(languageUrl('/community/profile/',$_POST['interface_language']??siteLanguage()));}
  elseif($action==='translation')networkSubmitTranslation($u);
  elseif($action==='appeal')networkAppeal($u);
  elseif($action==='withdraw_language'){networkOwnTarget($u,$kind,$target);$edition=networkLanguage($_POST['content_language']??'');socialQuery("UPDATE network_localizations SET state='hidden' WHERE kind=? AND target=? AND language=?",[$kind,$target,$edition]);socialQuery("UPDATE network_submissions SET state='withdrawn' WHERE kind=? AND target=? AND language=? AND state='pending'",[$kind,$target,$edition]);}
  elseif($action==='profile')networkProfileSave($u);
  elseif($action==='friend')saFriendAction($u,$target,(string)($_POST['friend_action']??''));
  elseif($action==='post')networkPostSave($u);
  elseif($action==='edge')networkEdge($u,(string)($_POST['edge']??''),$kind,$target,($_POST['on']??'')==='1');
  elseif($action==='comment')networkComment($u);
  elseif($action==='delete_comment')socialQuery("UPDATE network_comments SET state='removed',body='' WHERE id=? AND owner=?",[$target,$u['id']]);
  elseif($action==='helpful'){
   $r=socialQuery("SELECT * FROM network_comments WHERE id=? AND state='published'",[$target])->fetch();if(!$r||$r['owner']===$u['id']||networkBlocked($u['id'],$r['owner'])||!networkTarget($r['kind'],$r['target']))throw new DomainException('invalid');
   if(($_POST['on']??'')==='1')socialQuery("INSERT OR IGNORE INTO network_edges VALUES(?,'helpful',?,?)",[$u['id'],$target,time()]);else socialQuery("DELETE FROM network_edges WHERE actor=? AND kind='helpful' AND target=?",[$u['id'],$target]);
  }elseif($action==='report'){
   socialLimit('report',10,3600);$comment=$kind==='comment'?socialQuery("SELECT * FROM network_comments WHERE id=? AND state='published'",[$target])->fetch():null;$validComment=$comment&&networkTarget($comment['kind'],$comment['target'])&&!networkBlocked($u['id'],$comment['owner']);if(!networkTarget($kind,$target)&&!($kind==='member'&&networkProfile($target))&&!$validComment)throw new DomainException('not_found');
   socialQuery('INSERT INTO member_reports(reporter,kind,target,reason,created) SELECT ?,?,?,?,? WHERE NOT EXISTS(SELECT 1 FROM member_reports WHERE reporter=? AND kind=? AND target=?)',[$u['id'],$kind,$target,socialString($_POST['reason']??'',1000,10),time(),$u['id'],$kind,$target]);
  }elseif($action==='notices')socialQuery('UPDATE network_notices SET seen=1 WHERE member=?',[$u['id']]);
  elseif($action==='delete_post'){
   if(!socialQuery('SELECT 1 FROM network_posts WHERE id=? AND owner=?',[$target,$u['id']])->fetchColumn())throw new DomainException('not_found');
   socialQuery("UPDATE network_posts SET state='removed' WHERE id=?",[$target]);socialQuery("UPDATE network_localizations SET state='removed' WHERE kind='post' AND target=?",[$target]);
  }elseif($action==='delete_account'){
   $m=socialQuery('SELECT * FROM members WHERE id=?',[$u['id']])->fetch();if(($_POST['confirm_handle']??'')!==$u['handle']||!password_verify((string)($_POST['password']??''),$m['password']))throw new DomainException('login_failed');
   memberRememberForget();networkDeleteAccount($u);unset($_SESSION['member_id'],$_SESSION['member_version']);session_regenerate_id(true);redirect(languageUrl('/community/',siteLanguage()));
  }else throw new DomainException('invalid');
  if(in_array($action,['profile','edge','friend','comment','post','helpful'],true))$_SESSION['measurement_success']=$action==='edge'&&in_array($_POST['edge']??'',['follow','like','save'],true)?$_POST['edge']:$action;
  $_SESSION['network_message']=$action==='edge'&&($_POST['edge']??'')==='save'?(gtLabels()[($_POST['on']??'')==='1'?($kind==='place'?'place_saved':'post_saved'):'removed']):($action==='post'?rxT('publication_published'):($action==='comment'?uxT('comment_published'):($action==='translation'?nt('sent'):nt('done'))));
  if($action==='profile')redirect(languageUrl('/community/profile/',$_POST['interface_language']??siteLanguage()));
  if(in_array($action,['translation','withdraw_language'],true))redirect(languageUrl('/community/translations/',siteLanguage()).'&'.http_build_query(['kind'=>$kind,'id'=>$target,'edition'=>$_POST['content_language']]));
  redirect(languageUrl($path.'?'.http_build_query(array_intersect_key($_GET,array_flip(['tab','country','city','q','budget','minutes','type','cursor','lang','feed_language','content_lang','cost','category','sort','view','page']))),siteLanguage()));
 }catch(DomainException $e){$networkError=revError($e->getMessage())?:($e->getMessage()==='edit_expired'?seT('expired'):($e->getMessage()==='located_required'?sxT('located_required'):socialT($e->getMessage())));http_response_code(422);}
}
if($networkPage){$title=nt('home');$description=nt('composer');ob_start();require ROOT.'/views/network.php';$content=ob_get_clean();require ROOT.'/views/layout.php';exit;}
