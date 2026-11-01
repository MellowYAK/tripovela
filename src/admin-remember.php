<?php
// Persistent administrator authentication, stored only in the live database.
function adminRememberName(): string {return 'tripovela_admin_'.substr(hash('sha256',APP_URL),0,10);}
function adminRememberDb(): PDO {
 static $d;if($d)return $d;
 $d=qaDatabase(getenv('TRIPOVELA_DB')?:LIVE_STORAGE_PATH.'/tripovela.sqlite');
 // Avoid a schema write on every request, particularly while another connection reads users.
 $q=$d->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admin_remember'");$exists=$q->fetchColumn();$q->closeCursor();
 if(!$exists)$d->exec('CREATE TABLE IF NOT EXISTS admin_remember(token_hash TEXT PRIMARY KEY,user_id INTEGER NOT NULL,password_version TEXT NOT NULL,expires INTEGER NOT NULL)');return $d;
}
function adminRememberCookie(string $token,int $expires): void {setcookie(adminRememberName(),$token,['expires'=>$expires,'path'=>'/','secure'=>APP_ENV!=='local','httponly'=>true,'samesite'=>'Lax']);if($token==='')unset($_COOKIE[adminRememberName()]);else $_COOKIE[adminRememberName()]=$token;}
function adminRememberForget(): void {
 $token=$_COOKIE[adminRememberName()]??'';
 if(is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token))adminRememberDb()->prepare('DELETE FROM admin_remember WHERE token_hash=?')->execute([hash('sha256',$token)]);
 adminRememberCookie('',time()-3600);unset($_SESSION['admin_remember_checked']);
}
function adminRememberIssue(int $id): void {
 $d=adminRememberDb();$q=$d->prepare("SELECT password FROM users WHERE id=? AND role='admin'");$q->execute([$id]);$password=$q->fetchColumn();$q->closeCursor();if($password===false)return;
 adminRememberForget();$token=bin2hex(random_bytes(32));$expiry=time()+365*86400;
 $d->prepare('DELETE FROM admin_remember WHERE expires<=?')->execute([time()]);
 $d->prepare('INSERT INTO admin_remember VALUES(?,?,?,?)')->execute([hash('sha256',$token),$id,hash('sha256',$password),$expiry]);
 adminRememberCookie($token,$expiry);$_SESSION['admin_remember_checked']=time();
}
function adminRememberRestore(): void {
 $token=$_COOKIE[adminRememberName()]??'';if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))return;
 $d=adminRememberDb();$q=$d->prepare("SELECT r.*,u.password FROM admin_remember r JOIN users u ON u.id=r.user_id WHERE token_hash=? AND expires>? AND u.role='admin'");$q->execute([hash('sha256',$token),time()]);$r=$q->fetch();$q->closeCursor();
 if(!$r||!hash_equals($r['password_version'],hash('sha256',$r['password']))){adminRememberForget();unset($_SESSION['user'],$_SESSION['qa_workspace'],$_SESSION['qa_state'],$_SESSION['preview']);return;}
 if(empty($_SESSION['user'])){session_regenerate_id(true);$_SESSION['user']=(int)$r['user_id'];$_SESSION['csrf']=bin2hex(random_bytes(32));}
 if($r['expires']<time()+364*86400){$expiry=time()+365*86400;$d->prepare('UPDATE admin_remember SET expires=? WHERE token_hash=?')->execute([$expiry,hash('sha256',$token)]);adminRememberCookie($token,$expiry);}
 $_SESSION['admin_remember_checked']=time();
}
