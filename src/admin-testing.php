<?php
// Loaded before any application database is opened. The live admin session is the sole authority.
function amViewing(): bool {return !empty($GLOBALS['admin_member_view']);}
function qaActive(): bool {return !empty($GLOBALS['qa_active']);}
function qaAdminId(): int {return (int)($GLOBALS['qa_admin_id']??0);}
function qaDatabase(string $file): PDO {$d=new PDO('sqlite:'.$file);$d->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$d->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$d->exec('PRAGMA busy_timeout=5000');return $d;}
function qaBoot(string $base): string {
 if(PHP_SAPI==='cli')return $base;
 ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');session_name('tripovela_'.substr(hash('sha256',APP_URL),0,10));session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>APP_ENV!=='local'||(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')]);session_start();
 require_once __DIR__.'/admin-remember.php';adminRememberRestore();
 if(!empty($_SESSION['user'])){$_SESSION['logged_in_at']??=time();$_SESSION['last_active']=time();$d=qaDatabase(getenv('TRIPOVELA_DB')?:$base.'/tripovela.sqlite');$q=$d->prepare("SELECT id FROM users WHERE id=? AND role='admin'");$q->execute([$_SESSION['user']]);$GLOBALS['qa_admin_id']=(int)$q->fetchColumn();$q->closeCursor();$q=null;$d=null;if(qaAdminId()&&empty($_SESSION['admin_remember_checked']))adminRememberIssue(qaAdminId());}
 if(!qaAdminId()){unset($_SESSION['qa_workspace'],$_SESSION['qa_state'],$_SESSION['admin_member_view']);return $base;}
 $w=$_SESSION['qa_workspace']??null;$path=rawurldecode(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/');
 if(!preg_match('~^/KNISA(?:/|$)~i',$path)&&!empty($_SESSION['admin_member_view'])){
  $outer=$_SESSION;$GLOBALS['admin_member_view']=$outer['admin_member_view'];$_SESSION=$outer['admin_member_view']['state'];$_SESSION['member_seen']=time();
  register_shutdown_function(function()use($outer){if(session_status()!==PHP_SESSION_ACTIVE)return;$outer['admin_member_view']['state']=$_SESSION;$outer['csrf']=$_SESSION['csrf']??$outer['csrf'];$_SESSION=$outer;});return $base;
 }
 if(!$w||empty($w['enabled'])||preg_match('~^/KNISA(?:/|$)~i',$path))return $base;
 if(!preg_match('/^[a-f0-9]{32}$/D',$w['id']??''))return $base;
 $dir=$base.'/admin-tests/'.$w['id'];if(!is_file($dir.'/ready.json')||(int)(json_decode(file_get_contents($dir.'/ready.json'),true)['admin']??0)!==qaAdminId()){unset($_SESSION['qa_workspace'],$_SESSION['qa_state']);return $base;}
 $GLOBALS['qa_active']=true;$GLOBALS['qa_workspace']=$w;$outer=$_SESSION;
 $_SESSION=$_SESSION['qa_state']??[];$_SESSION['csrf']=$outer['csrf']??bin2hex(random_bytes(32));
 register_shutdown_function(function()use($outer){if(session_status()!==PHP_SESSION_ACTIVE)return;$outer['qa_state']=$_SESSION;$outer['csrf']=$_SESSION['csrf']??$outer['csrf'];$_SESSION=$outer;});
 return $dir;
}
function qaPhotoPath(string $id): string {$file=STORAGE_PATH.'/community-images/'.$id.'.jpg';return qaActive()&&!is_file($file)?LIVE_STORAGE_PATH.'/community-images/'.$id.'.jpg':$file;}
function qaT(string $key): string {static $d;$d??=json_decode(file_get_contents(ROOT.'/content/admin-testing-labels.json'),true);return $d[siteLanguage()][$key]??$d['en'][$key]??$key;}
function qaRequestToken(): string {return qaActive()?($GLOBALS['qa_workspace']['id'].':'.($GLOBALS['qa_workspace']['epoch']??'')):'';}
function qaGuard(string $path): void {
 if(str_starts_with($path,'/KNISA/'))return;$given=$_SERVER['HTTP_X_TRIPOVELA_TEST']??$_POST['_qa_workspace']??'';$expected=qaRequestToken();
 if(($given!==''&&!hash_equals($expected,(string)$given))||(qaActive()&&$_SERVER['REQUEST_METHOD']==='POST'&&!hash_equals($expected,(string)$given))){http_response_code(409);header('Content-Type: application/json');echo json_encode(['ok'=>false,'message'=>qaT('stale')]);exit;}
}
function qaHead(): void {if(amViewing())echo '<meta name="qa-storage" content="member-'.e($GLOBALS['admin_member_view']['id']).'">';echo '<meta name="qa-view" content="'.e(qaRequestToken()).'"><meta name="qa-workspace" content="'.e(qaActive()?($GLOBALS['qa_workspace']['id']??''):'').'"><meta name="qa-admin" content="'.(qaAdminId()?'1':'0').'"><script src="/assets/admin-testing.js?v='.filemtime(ROOT.'/public/assets/admin-testing.js').'"></script>';if(qaAdminId())echo '<link rel="stylesheet" href="/assets/admin-testing.css?v='.filemtime(ROOT.'/public/assets/admin-testing.css').'">';}
function qaBar(): void {
 if(!qaAdminId())return;$w=$GLOBALS['qa_workspace']??($_SESSION['qa_workspace']??null);
 if(amViewing()){echo '<aside class="qa-bar"><strong>'.e(qaT('member_view')).'</strong><bdi>'.e($GLOBALS['admin_member_view']['handle']).'</bdi><small>'.e(qaT('live_member_hint')).'</small><form method="post" action="/KNISA/testing/?lang='.e(siteLanguage()).'">'.csrf().'<button name="action" value="exit">'.e(qaT('exit')).'</button></form><a href="/KNISA/testing/">'.e(qaT('tools')).'</a></aside>';return;}
 echo '<aside class="qa-bar" aria-label="'.e(qaT('tools')).'"><strong>'.e(qaT(qaActive()?'sandbox':'live_admin')).'</strong>';
 if(qaActive())echo '<span>'.e(qaT(empty($_SESSION['member_id'])?'guest':($w['mode']??'returning'))).'</span><bdi>'.e($w['handle']??'').'</bdi><small>'.e(qaT('isolated')).'</small>';
 echo '<details><summary>'.e(qaT('switch')).'</summary><form method="post" action="/KNISA/testing/?lang='.e(siteLanguage()).'">'.csrf().'<label>'.e(qaT('mode')).'<select name="mode">';foreach(['guest','new_member','returning','existing'] as $m)echo '<option value="'.$m.'"'.(($w['mode']??'guest')===$m?' selected':'').'>'.e(qaT($m)).'</option>';echo '</select></label><label>'.e(qaT('handle')).'<input name="handle" maxlength="30" autocomplete="off"></label><button name="action" value="switch">'.e(qaT('start')).'</button></form></details><form method="post" action="/KNISA/testing/?lang='.e(siteLanguage()).'">'.csrf().'<button name="action" value="reset">'.e(qaT('reset')).'</button><button name="action" value="exit">'.e(qaT('exit')).'</button></form><a href="/KNISA/testing/?lang='.e(siteLanguage()).'">'.e(qaT('tools')).'</a><a href="/KNISA/">'.e(qaT('admin')).'</a></aside>';
}
function qaRemoveWorkspace(string $id): void {
 if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('invalid workspace');$root=realpath(LIVE_STORAGE_PATH.'/admin-tests');$dir=realpath(LIVE_STORAGE_PATH.'/admin-tests/'.$id);if(!$dir)return;if(!$root||dirname($dir)!==$root||is_link($dir))throw new RuntimeException('invalid workspace');
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
}
function qaCreateWorkspace(): array {
 $id=bin2hex(random_bytes(16));$dir=LIVE_STORAGE_PATH.'/admin-tests/'.$id;if(!is_dir(dirname($dir)))mkdir(dirname($dir),0700,true);mkdir($dir,0700);try{
 foreach(['tripovela.sqlite','community.sqlite','catalog.sqlite','community-ratings.sqlite'] as $file){$source=$file==='tripovela.sqlite'?(getenv('TRIPOVELA_DB')?:LIVE_STORAGE_PATH.'/'.$file):LIVE_STORAGE_PATH.'/'.$file;if(!is_file($source))continue;$d=qaDatabase($source);$d->exec('VACUUM INTO '.$d->quote($dir.'/'.$file));chmod($dir.'/'.$file,0600);}
 $main=qaDatabase($dir.'/tripovela.sqlite');$main->exec("UPDATE users SET password=''");
 // Authentication secrets and measurement queues are not needed in the private snapshot.
 $d=qaDatabase($dir.'/community.sqlite');$d->exec("UPDATE members SET password='', recovery=''");foreach(['admin_created_members','member_remember','reddit_capi_outbox','acquisition_registrations','acquisition_actions','member_email_identity'] as $table){if($d->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=".$d->quote($table))->fetchColumn())$d->exec('DELETE FROM '.$table);}
 file_put_contents($dir.'/ready.json',json_encode(['admin'=>qaAdminId(),'created'=>time()]));chmod($dir.'/ready.json',0600);qaPruneWorkspaces($id);return ['id'=>$id,'mode'=>'guest'];
 }catch(Throwable $e){qaRemoveWorkspace($id);throw $e;}
}

function qaPruneWorkspaces(string $keep): void {
 $rows=[];foreach(glob(LIVE_STORAGE_PATH.'/admin-tests/*/ready.json')?:[] as $file){$m=json_decode(file_get_contents($file),true);if((int)($m['admin']??0)!==qaAdminId())continue;$id=basename(dirname($file));if($id!==$keep)$rows[]=['id'=>$id,'created'=>(int)($m['created']??0)];}
 usort($rows,fn($a,$b)=>$b['created']<=>$a['created']);foreach(array_slice($rows,2) as $row)qaRemoveWorkspace($row['id']);
}
