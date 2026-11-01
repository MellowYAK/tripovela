<?php
requireAdmin();require_once ROOT.'/src/admin-members.php';$title=qaT('tools');$error='';
if($_SERVER['REQUEST_METHOD']==='POST')try{
 $action=(string)($_POST['action']??'');$w=$_SESSION['qa_workspace']??null;
 if($action==='toggle_editor'){
  if(($_POST['scope']??'')!=='live')throw new DomainException('invalid');
  $member=amMember(socialDb(),socialString($_POST['member']??'',32,32),qaAdminId());
  socialDb()->exec('CREATE TABLE IF NOT EXISTS trip_editor_pilot(member TEXT PRIMARY KEY,enabled_by INTEGER NOT NULL,enabled_at INTEGER NOT NULL)');
  if(tdPilot($member['id']))socialQuery('DELETE FROM trip_editor_pilot WHERE member=?',[$member['id']]);
  else socialQuery('INSERT INTO trip_editor_pilot VALUES(?,?,?)',[$member['id'],qaAdminId(),time()]);
  audit('trip-editor-pilot',$member['id']);redirect(languageUrl('/KNISA/testing/',siteLanguage()));
 }
 if($w){$meta=preg_match('/^[a-f0-9]{32}$/D',$w['id']??'')?LIVE_STORAGE_PATH.'/admin-tests/'.$w['id'].'/ready.json':'';if(!$meta||!is_file($meta)||(int)(json_decode(file_get_contents($meta),true)['admin']??0)!==qaAdminId()){$w=null;unset($_SESSION['qa_workspace'],$_SESSION['qa_state']);}}
 if($action==='exit'){unset($_SESSION['admin_member_view']);if($w)$_SESSION['qa_workspace']['enabled']=false;audit('testing-exit','workspace');redirect(languageUrl('/',siteLanguage()));}
 if(in_array($action,['create_member','enter_member'],true)){
  $scope=(string)($_POST['scope']??'live');if(!in_array($scope,['private','live'],true))throw new DomainException('invalid');
  if($scope==='private'&&!$w){$w=qaCreateWorkspace();$_SESSION['qa_workspace']=$w;$_SESSION['qa_state']=[];}
  $d=$scope==='private'?qaDatabase(LIVE_STORAGE_PATH.'/admin-tests/'.$w['id'].'/community.sqlite'):socialDb();
  if($action==='create_member'){
   $created=amCreate($d,$_POST,qaAdminId(),siteLanguage());$_SESSION['am_created']=$created+['scope'=>$scope];audit('admin-member-created',$scope.':'.$created['id']);redirect(languageUrl('/KNISA/testing/',siteLanguage()));
  }
  $member=amMember($d,socialString($_POST['member']??'',32,32),qaAdminId());session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
  if($scope==='private'){
   unset($_SESSION['admin_member_view']);$w['epoch']=bin2hex(random_bytes(12));$w['mode']='returning';$w['handle']=$member['handle'];$w['test_member']=$member['id'];$w['enabled']=true;$_SESSION['qa_workspace']=$w;$_SESSION['qa_state']=amState($member);
  }else{
   if($w)$_SESSION['qa_workspace']['enabled']=false;
   $_SESSION['admin_member_view']=['id'=>$member['id'],'handle'=>$member['handle'],'state'=>amState($member)];
  }
  audit('admin-member-enter',$scope.':'.$member['id']);redirect(languageUrl('/community/',siteLanguage()));
 }
 if(!in_array($action,['switch','reset'],true))throw new DomainException('invalid');
 unset($_SESSION['admin_member_view']);
 $mode=$action==='reset'?($w['mode']??'guest'):(string)($_POST['mode']??'guest');if(!in_array($mode,['guest','new_member','returning','existing'],true))throw new DomainException('invalid');
 $handle=$action==='reset'?($w['handle']??''):trim((string)($_POST['handle']??''));
 if($mode==='existing'&&!socialQuery("SELECT 1 FROM members WHERE handle=? AND state='active'",[$handle])->fetchColumn())throw new DomainException('not_found');
 if(!$w||$action==='reset'){ $old=$w['id']??'';$w=qaCreateWorkspace();$_SESSION['qa_state']=[];if($old)qaRemoveWorkspace($old);}
 $d=qaDatabase(LIVE_STORAGE_PATH.'/admin-tests/'.$w['id'].'/community.sqlite');$state=['csrf'=>$_SESSION['csrf']];
 if($mode!=='guest'){
  if($mode==='existing'){$q=$d->prepare("SELECT id,session_version FROM members WHERE handle=? AND state='active'");$q->execute([$handle]);$member=$q->fetch();if(!$member)throw new DomainException('not_found');}
  else{
   $id=$w['test_member']??bin2hex(random_bytes(16));$q=$d->prepare('SELECT id,session_version FROM members WHERE id=?');$q->execute([$id]);$member=$q->fetch();
   if(!$member){$q=$d->prepare('INSERT INTO members(id,handle,password,recovery,created) VALUES(?,?,?,?,?)');$q->execute([$id,'test_'.substr($w['id'],0,12),'','',time()]);$d->prepare('INSERT INTO member_profiles(member,name,language,updated) VALUES(?,?,?,?)')->execute([$id,qaT('test_member'),siteLanguage(),time()]);$d->prepare('INSERT INTO network_language_preferences(member,publication_language,feed_language,interface_language) VALUES(?,?,?,?)')->execute([$id,siteLanguage(),siteLanguage(),siteLanguage()]);$member=['id'=>$id,'session_version'=>1];$w['test_member']=$id;}
  }
  $state+=['member_id'=>$member['id'],'member_version'=>$member['session_version'],'member_seen'=>time(),'member_remember_checked'=>true];
  if($mode==='new_member')$state['new_recovery']=qaT('simulated_code');
 }
 $w['epoch']=bin2hex(random_bytes(12));$w['mode']=$mode;$w['handle']=$handle;$w['enabled']=true;$_SESSION['qa_workspace']=$w;$_SESSION['qa_state']=$state;audit('testing-'.$action,$mode.($mode==='existing'?':'.$handle:''));
 redirect(languageUrl($mode==='new_member'?'/community/profile/':'/community/',siteLanguage()));
}catch(Throwable $e){$error=$e instanceof DomainException?socialT($e->getMessage()):qaT('failed');}
ob_start();?><section class="page-heading"><h1><?=e(qaT('tools'))?></h1><p><?=e(qaT('explanation'))?></p></section><?php if($error):?><p role="alert"><?=e($error)?></p><?php endif?>
<?php $created=$_SESSION['am_created']??null;unset($_SESSION['am_created']);if($created):?><section class="social-card" role="status"><h2><?=e(qaT('member_created'))?></h2><p><?=e(qaT($created['scope']==='private'?'private_member':'live_member'))?> · <bdi><?=e($created['handle'])?></bdi></p><?php if($created['password']!==''):?><p><?=e(qaT('credentials_once'))?></p><label><?=e(mrLabels('password'))?><input type="text" readonly autocomplete="off" value="<?=e($created['password'])?>"></label><?php endif?><form method="post"><?=csrf()?><input type="hidden" name="member" value="<?=e($created['id'])?>"><input type="hidden" name="scope" value="<?=e($created['scope'])?>"><button name="action" value="enter_member"><?=e(qaT('enter_member'))?></button></form></section><?php endif?>
<section class="social-card"><h2><?=e(qaT('create_member'))?></h2><form method="post" data-admin-member-create><?=csrf()?><input type="hidden" name="request_token" value="<?=e(bin2hex(random_bytes(16)))?>"><label><?=e(qaT('member_scope'))?><select name="scope"><option value="live"><?=e(qaT('live_member'))?></option><option value="private"><?=e(qaT('private_member'))?></option></select><small><?=e(qaT('create_member_hint'))?></small></label><label><?=e(socialT('handle'))?><input name="new_handle" maxlength="30" autocomplete="off" pattern="[a-zA-Z0-9][a-zA-Z0-9_-]{2,29}"><small><?=e(qaT('auto_handle'))?></small></label><label><?=e(qaT('member_name'))?><input name="display_name" maxlength="60" autocomplete="off"></label><label><input type="checkbox" name="generate_password" value="1" checked> <?=e(qaT('generate_password'))?></label><label><?=e(mrLabels('password'))?><input name="new_password" type="password" minlength="8" maxlength="72" autocomplete="new-password"></label><button class="button" name="action" value="create_member"><?=e(qaT('create_member'))?></button></form></section>
<section class="social-card"><h2><?=e(qaT('created_members'))?></h2><?php $workspace=$_SESSION['qa_workspace']??null;foreach(['live','private'] as $scope):$rows=[];if($scope==='live')$rows=amRows(socialDb(),qaAdminId());elseif($workspace&&preg_match('/^[a-f0-9]{32}$/D',$workspace['id']??'')&&is_file(LIVE_STORAGE_PATH.'/admin-tests/'.$workspace['id'].'/ready.json'))$rows=amRows(qaDatabase(LIVE_STORAGE_PATH.'/admin-tests/'.$workspace['id'].'/community.sqlite'),qaAdminId());foreach($rows as $m):?><form method="post"><?=csrf()?><input type="hidden" name="scope" value="<?=$scope?>"><input type="hidden" name="member" value="<?=e($m['id'])?>"><bdi><?=e($m['handle'])?></bdi> · <?=e(qaT($scope==='private'?'private_member':'live_member'))?> <button name="action" value="enter_member"><?=e(qaT('enter_member'))?></button></form><?php endforeach;endforeach?></section>
<section class="social-card"><form method="post"><?=csrf()?><label><?=e(qaT('mode'))?><select name="mode"><?php foreach(['guest','new_member','returning','existing'] as $mode):?><option value="<?=$mode?>"><?=e(qaT($mode))?></option><?php endforeach?></select></label><label><?=e(qaT('handle'))?><input name="handle" maxlength="30" list="qa-members" autocomplete="off"></label><datalist id="qa-members"><?php foreach(socialQuery("SELECT handle FROM members WHERE state='active' ORDER BY handle LIMIT 1000") as $m):?><option value="<?=e($m['handle'])?>"><?php endforeach?></datalist><p><?=e(qaT('copy_hint'))?></p><button class="button" name="action" value="switch"><?=e(qaT('start'))?></button></form></section>
<?php if(!empty($_SESSION['qa_workspace'])):$w=$_SESSION['qa_workspace'];?><section class="social-card"><h2><?=e(qaT('session'))?></h2><p><?=e(qaT($w['mode']))?> <?=e($w['handle']??'')?></p><p><?=e(qaT('reset_hint'))?></p><form method="post"><?=csrf()?><button name="action" value="reset"><?=e(qaT('reset'))?></button><button name="action" value="exit"><?=e(qaT('exit'))?></button></form><p><?=e(qaT('diagnostics'))?>: <code><?=e(substr($w['id'],0,8))?></code></p><p><?=e(qaT('simulation_hint'))?></p></section><?php endif?>
<link rel="stylesheet" href="/assets/admin-members.css?v=<?=filemtime(ROOT.'/public/assets/admin-members.css')?>"><script defer src="/assets/admin-members.js?v=<?=filemtime(ROOT.'/public/assets/admin-members.js')?>"></script>
<?php $content=ob_get_clean();require ROOT.'/views/layout.php';
