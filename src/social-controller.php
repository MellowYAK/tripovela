<?php
require_once ROOT.'/src/guest-trips.php';
if(str_starts_with($path,'/api/community/')){
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
 try{
  $action=$_POST['action']??$_GET['action']??'';
  if($_SERVER['REQUEST_METHOD']==='GET'){
   if($action==='session')$result=['user'=>socialUser(),'csrf'=>$_SESSION['csrf']];
   elseif($action==='document_search')$result=tdSearch((string)($_GET['q']??''));
   elseif($action==='social_trip'){$result=sxTrip(socialRequire(),(string)($_GET['id']??''));}
   elseif($action==='guest_places'){$result=gtPlaces(explode(',',(string)($_GET['ids']??'')),($_GET['partial']??'')==='1');}
   elseif($action==='guest_route'){$result=gtRoute(['kind'=>(($_GET['kind']??'')==='share'?'share':'trip'),'target'=>(string)($_GET['target']??''),'edition'=>(string)($_GET['edition']??'')]);}
   elseif($action==='source'){$source=sxSource(['kind'=>(string)($_GET['kind']??''),'target'=>(string)($_GET['target']??''),'edition'=>(string)($_GET['edition']??''),'comment'=>(string)($_GET['comment']??'')]);if(!$source)throw new DomainException('not_found');$result=array_intersect_key($source,array_flip(['kind','target','edition','name','title','url','comment','revision']));}
   elseif($action==='list'){$u=socialRequire();$result=['trips'=>sxTripCards($u)];}
   elseif($action==='load'){$u=socialRequire();$r=socialQuery('SELECT id,revision,title,plan FROM member_trips WHERE id=? AND owner=?',[(string)($_GET['id']??''),$u['id']])->fetch();if(!$r)throw new DomainException('not_found');$r['plan']=networkResolvePlan(json_decode($r['plan'],true));$result=$r;}
   elseif(in_array($action,['copy','copy_shared'],true)){$id=(string)($_GET['id']??'');$r=$action==='copy_shared'?socialSharedTrip($id):socialPublicTrip($id);if(!$r)throw new DomainException('not_found');$p=networkResolvePlan(json_decode($r['public_plan'],true));unset($p['photos']);$p['parent_route_id']=$id;$p['parent_version_id']=$r['public_version']??0;$catalog=array_column(tripCatalog(),null,'id');foreach($p['items'] as &$x){$known=$catalog[$x['id']]??null;if(!$known&&($name=networkItemForLanguage($x['id'],$p['language']??networkContentLanguage())))$known=['title'=>$name];$x=['optional'=>!empty($x['optional']),'stay_minutes'=>$x['stay_minutes']??null,'travel_minutes'=>$x['travel_minutes']??null,'mode'=>$x['mode']??'walk','id'=>$x['id'],'day'=>$x['day'],'title'=>$known['title']??$x['title'],'type'=>$known?'experience':'custom','note'=>'','booked'=>false];}unset($x);$p+=['travelers'=>2,'start'=>'','end'=>'','activeDay'=>0];$result=['plan'=>$p];}
   else throw new DomainException('invalid');
  }elseif($_SERVER['REQUEST_METHOD']==='POST'){
   $u=$action==='document_resolve'?null:socialRequire();socialLimit('trip',600,3600);
   if($action==='document_resolve'){$data=json_decode($_POST['payload']??'{}',true,8,JSON_THROW_ON_ERROR);$result=tppResolve((string)($data['candidate']??''));echo json_encode(['ok'=>true,'result'=>$result]);exit;}
   if($action==='guest_import'){$result=gtImport($u,json_decode($_POST['payload']??'{}',true,64,JSON_THROW_ON_ERROR));}
   elseif($action==='delete_trip'){$result=sxDeleteTrip($u,json_decode($_POST['payload']??'{}',true,64,JSON_THROW_ON_ERROR));}
   elseif($action==='adopt'){$result=sxWrite($u,json_decode($_POST['payload']??'{}',true,64,JSON_THROW_ON_ERROR));}
   elseif($action==='undo_adoption'){$result=sxUndo($u,json_decode($_POST['payload']??'{}',true,64,JSON_THROW_ON_ERROR));}
   elseif($action==='switch_draft'){$result=sxSwitchDraft($u,json_decode($_POST['payload']??'{}',true,64,JSON_THROW_ON_ERROR));}
   elseif($action==='observe'){$result=sxObserve($u,json_decode($_POST['payload']??'{}',true,64,JSON_THROW_ON_ERROR));}
   elseif($action==='add_places'){$result=scAddPlaces($u,json_decode($_POST['payload']??'{}',true,64,JSON_THROW_ON_ERROR));}
   elseif($action==='save'){$raw=json_decode((string)($_POST['payload']??''),true,64,JSON_THROW_ON_ERROR);if(!is_array($raw))throw new DomainException('invalid');$before=socialQuery('SELECT plan FROM member_trips WHERE id=? AND owner=?',[$raw['id']??'',$u['id']])->fetchColumn();$result=!empty($_POST['request_token'])?scSaveOnce($u,$raw,(string)$_POST['request_token']):socialSaveTrip($u,$raw);$savedPlan=json_decode(socialQuery('SELECT plan FROM member_trips WHERE id=? AND owner=?',[$result['id'],$u['id']])->fetchColumn(),true);if(count($savedPlan['items'])>count(json_decode($before?:'{}',true)['items']??[]))rmAction($u['id'],!empty($savedPlan['parent_route_id'])&&!$before?'trip_copied':'place_added_to_trip',$result['id'].':'.$result['revision']);}
   elseif(in_array($action,['publish','share'],true))$result=socialPublish($u,(string)($_POST['id']??''),(int)($_POST['revision']??0),$action==='share');else throw new DomainException('invalid');
  }else{http_response_code(405);exit;}
  echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG);
 }catch(DomainException $e){http_response_code($e->getMessage()==='login_required'?401:($e->getMessage()==='conflict'?409:422));echo json_encode(['ok'=>false,'message'=>socialT($e->getMessage())],JSON_UNESCAPED_UNICODE);}
 catch(JsonException $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>socialT('invalid')]);}exit;
}
if(preg_match('~^/community/media/([a-f0-9]{32})/$~D',$path,$sm)){
 $r=socialQuery('SELECT p.*,m.state member_state FROM member_places p JOIN members m ON m.id=p.owner WHERE p.id=COALESCE((SELECT place_id FROM member_images WHERE id=?),?)',[$sm[1],$sm[1]])->fetch();$u=socialUser();
 $allowed=$r&&(($r['state']==='approved'&&$r['member_state']==='active'&&socialQuery("SELECT 1 FROM network_localizations WHERE kind='place' AND target=? AND state='published'",[$r['id']])->fetchColumn())||($u&&$u['id']===$r['owner']));if(!$allowed&&!empty($_SESSION['user'])){requireAdmin();$allowed=(bool)$r;}
 if(!$r){$tr=socialQuery('SELECT t.*,m.state member_state FROM member_trip_images i JOIN member_trips t ON t.id=i.trip_id JOIN members m ON m.id=t.owner WHERE i.id=?',[$sm[1]])->fetch();if($tr){$allowed=$u&&$u['id']===$tr['owner'];if($tr['member_state']==='active'&&$tr['state']!=='blocked')foreach(['public_plan','shared_plan'] as $column)if(($column==='shared_plan'||socialQuery("SELECT 1 FROM network_localizations WHERE kind='trip' AND target=? AND state='published'",[$tr['id']])->fetchColumn())&&in_array($sm[1],json_decode($tr[$column]??'null',true)['photos']??[],true))$allowed=true;if(!$allowed&&!empty($_SESSION['user'])){requireAdmin();$allowed=true;}}}
 $nr=socialQuery('SELECT i.owner,i.post_id,p.state FROM network_images i JOIN network_posts p ON p.id=i.post_id JOIN members m ON m.id=i.owner WHERE i.id=? AND m.state=?',[$sm[1],'active'])->fetch();if($nr)$allowed=($nr['state']==='published'&&socialQuery("SELECT 1 FROM network_localizations l JOIN network_images i ON i.post_id=l.target WHERE l.kind='post' AND l.state='published' AND i.id=?",[$sm[1]])->fetchColumn()&&!networkBlocked($u['id']??'',$nr['owner']))||($u&&$u['id']===$nr['owner'])||!empty($_SESSION['user']);
 if(!$allowed&&!empty($tr)&&$tr['member_state']==='active'&&$tr['state']==='published'&&saCanView('trip',$tr['id'],$tr['owner']))$allowed=(bool)socialQuery("SELECT 1 FROM network_versions v JOIN network_localizations l ON l.kind=v.kind AND l.target=v.target AND l.language=v.language AND l.state='published' WHERE v.kind='trip' AND v.target=? AND EXISTS(SELECT 1 FROM json_each(v.document,'$.photos') WHERE value=?)",[$tr['id'],$sm[1]])->fetchColumn();
 $owner=$r['owner']??$tr['owner']??$nr['owner']??'';if($owner&&networkBlocked($u['id']??'',$owner)&&empty($_SESSION['user']))$allowed=false;
 if(!$allowed&&$u){scSchema();$allowed=(bool)socialQuery("SELECT 1 FROM social_drafts WHERE owner=? AND EXISTS(SELECT 1 FROM json_each(document,'$.photos') WHERE value=?)",[$u['id'],$sm[1]])->fetchColumn();}
 if(!$allowed){scSchema();$comment=socialQuery("SELECT c.* FROM network_comments c JOIN social_comment_links l ON l.comment=c.id JOIN members m ON m.id=c.owner WHERE m.state='active' AND EXISTS(SELECT 1 FROM json_each(l.document,'$.photos') WHERE value=?)",[$sm[1]])->fetch();if($comment&&!networkBlocked($u['id']??'',$comment['owner']))$allowed=($u&&$u['id']===$comment['owner'])||!empty($_SESSION['user'])||($comment['state']==='published'&&networkTarget($comment['kind'],$comment['target']));}
 if(empty($_SESSION['user'])){if(!empty($tr)&&!saCanView('trip',$tr['id'],$tr['owner']))$allowed=false;if(!empty($nr)&&!saCanView('post',$nr['post_id'],$nr['owner']))$allowed=false;if(!empty($r)&&!saCanView('place',$r['id'],$r['owner']))$allowed=false;}
 if(!$allowed)$allowed=cpMediaAllowed($sm[1]);
 $allowed=$allowed||($u&&(bool)socialQuery("SELECT 1 FROM community_photo_review WHERE id=? AND owner=? AND state!='discarded'",[$sm[1],$u['id']])->fetchColumn());$allowed=$allowed&&crPhotoAllowed($sm[1],true);if(crAdmin()||(crCapability('reviewer')&&crPhotoSubmitted($sm[1])))$allowed=(bool)socialQuery('SELECT 1 FROM community_photo_review WHERE id=?',[$sm[1]])->fetchColumn()||$allowed;$file=qaPhotoPath($sm[1]);if(!$allowed||!is_file($file)){http_response_code(404);exit;}
 header('Content-Type: image/jpeg');header('Content-Disposition: inline');header('Cache-Control: private, no-store');readfile($file);exit;
}
if(preg_match('~^/community/places/([a-f0-9]{32})/$~D',$path,$aliasMatch)){$canonical=networkPlaceAlias($aliasMatch[1]);if($canonical&&$canonical!==$aliasMatch[1])redirect(languageUrl('/community/places/'.$canonical.'/?content_lang='.rawurlencode(networkContentLanguage()),siteLanguage()));}
$socialError='';$socialMessage=$_SESSION['social_message']??'';unset($_SESSION['social_message']);
if($path==='/community/account/'&&isset($_GET['return_to'])){
 $returnTo=(string)$_GET['return_to'];
 $returnPath=parse_url($returnTo,PHP_URL_PATH);
 if(is_string($returnPath)&&!preg_match('/[\r\n]/',$returnTo)&&!str_starts_with($returnTo,'//')&&!parse_url($returnTo,PHP_URL_HOST)&&preg_match('~^/(?:|(?:my-trip|plan|explore|day-plan|community|community/create|community/contribute|community/saved)/|community/(?:posts|trips)/[a-f0-9]{32}/|community/members/[a-z0-9_-]{3,30}/)$~D',$returnPath)){
  parse_str((string)parse_url($returnTo,PHP_URL_QUERY),$returnQuery);$safe=[];foreach(['lang','tab','type','city','q','budget','minutes','cursor','kind','destination','country','version','feed_language','content_lang','trip'] as $key)if(isset($returnQuery[$key])&&is_string($returnQuery[$key])&&strlen($returnQuery[$key])<=1000)$safe[$key]=$returnQuery[$key];
  $_SESSION['member_return_to']=$returnPath.($safe?'?'.http_build_query($safe):'');
 }
}
if($_SERVER['REQUEST_METHOD']==='POST')try{
 $action=(string)($_POST['action']??'');
 if($path==='/community/account/'&&in_array($action,['register','login','recover'],true)){socialAccountAction($action);$returnTo=$_SESSION['member_return_to']??'/community/account/';unset($_SESSION['member_return_to']);redirect(languageUrl($action==='register'?'/community/account/':$returnTo,siteLanguage()));}
 $u=socialRequire();
 if($action==='logout'&&$path==='/community/account/'){memberRememberForget();unset($_SESSION['member_id'],$_SESSION['member_version'],$_SESSION['member_seen'],$_SESSION['new_recovery']);session_regenerate_id(true);redirect(languageUrl('/community/',siteLanguage()));}
 elseif($action==='unpublish'&&$path==='/community/account/'){
  socialQuery("UPDATE network_localizations SET state='hidden' WHERE kind='trip' AND target IN(SELECT id FROM member_trips WHERE id=? AND owner=? AND revision=?)",[(string)($_POST['id']??''),$u['id'],(int)($_POST['revision']??0)]);$q=socialQuery("UPDATE member_trips SET public_plan=NULL,pending_plan=NULL,shared_plan=NULL,state=CASE WHEN state='blocked' THEN 'blocked' ELSE 'private' END,revision=revision+1 WHERE id=? AND owner=? AND revision=?",[(string)($_POST['id']??''),$u['id'],(int)($_POST['revision']??0)]);if(!$q->rowCount())throw new DomainException('conflict');$_SESSION['social_message']=socialT('withdrawn');
 }elseif($action==='trip_photos'&&$path==='/community/account/'){
  socialTripPhotos($u,(string)($_POST['id']??''),(int)($_POST['revision']??0));$_SESSION['social_message']=socialT('photos_saved');
 }elseif($action==='contribute'&&$path==='/community/contribute/'){
  $placeId=socialPlaceUpload($u);$_SESSION['social_message']=crT('new_pending');redirect(languageUrl('/community/account/',siteLanguage()));
 }elseif($action==='rate'){
  socialRate($u,(string)($_POST['kind']??''),(string)($_POST['target']??''),(int)($_POST['score']??0));$_SESSION['social_message']=socialT('rating_saved');
 }elseif($action==='report'){
  socialLimit('report',10,3600);$kind=(string)($_POST['kind']??'');$target=(string)($_POST['target']??'');if(!in_array($kind,['trip','member','place'],true)||!preg_match('/^[a-f0-9]{32}$/D',$target))throw new DomainException('invalid');
  socialQuery('INSERT INTO member_reports(reporter,kind,target,reason,created) SELECT ?,?,?,?,? WHERE NOT EXISTS(SELECT 1 FROM member_reports WHERE reporter=? AND kind=? AND target=?)',[$u['id'],$kind,$target,socialString($_POST['reason']??'',1000,10),time(),$u['id'],$kind,$target]);$_SESSION['social_message']=socialT('reported');
 }else throw new DomainException('invalid');
 redirect(languageUrl($path.'?'.http_build_query(array_intersect_key($_GET,array_flip(['content_lang','version']))),siteLanguage()));
}catch(DomainException $e){$socialError=socialT($e->getMessage());http_response_code(422);}
$title=socialT('community');$description=socialT('publish_notice');ob_start();require ROOT.'/views/community.php';$content=ob_get_clean();require ROOT.'/views/layout.php';exit;
