<?php
// Mutable share access is separate from immutable publication audiences.
function tsAccessSchema(): void {
 socialDb()->exec("CREATE TABLE IF NOT EXISTS trip_share_access(token TEXT PRIMARY KEY,password_hash TEXT NOT NULL DEFAULT '',recipients TEXT NOT NULL DEFAULT '[]',version INTEGER NOT NULL DEFAULT 1)");
}
function tsAccessRow(string $token): array {
 $r=socialQuery('SELECT * FROM trip_share_access WHERE token=?',[$token])->fetch()?:['password_hash'=>'','recipients'=>'[]','version'=>1];
 $r['recipients_list']=json_decode($r['recipients'],true)?:[];return $r;
}
function tsFriends(array $u): array {
 $id=$u['id'];$rows=socialQuery("SELECT m.id,m.handle,COALESCE(p.name,'') name FROM social_friendships f JOIN members m ON m.id=CASE WHEN f.a=? THEN f.b ELSE f.a END LEFT JOIN member_profiles p ON p.member=m.id WHERE f.state='accepted' AND (f.a=? OR f.b=?) AND m.state='active' ORDER BY m.handle",[$id,$id,$id])->fetchAll();
 return array_values(array_filter($rows,fn($r)=>!networkBlocked($id,$r['id'])));
}
function tsAccessReadable(array $r,?array $u): bool {
 if($r['revoked']||($r['owner']&&(!networkProfile($r['owner'])||networkBlocked($u['id']??'',$r['owner']))))return false;
 if(tsCanEdit($r,$u))return true;
 if(in_array($r['visibility'],['public','unlisted'],true))return true;
 $access=tsAccessRow($r['token']);
 if($r['visibility']==='password'){
  $grant=$_SESSION['trip_share_unlock'][$r['token']]??[];
  return !empty($access['password_hash'])&&(int)($grant['until']??0)>time()&&hash_equals(hash('sha256',$access['password_hash'].':'.$access['version']),$grant['key']??'');
 }
 if(!$u||!$r['owner']||!in_array($r['visibility'],['friends','selected'],true))return false;
 $friend=(bool)socialQuery("SELECT 1 FROM social_friendships WHERE state='accepted' AND ((a=? AND b=?) OR (b=? AND a=?))",[$r['owner'],$u['id'],$r['owner'],$u['id']])->fetchColumn();
 return $friend&&($r['visibility']==='friends'||in_array($u['id'],$access['recipients_list'],true));
}
function tsAccessInput(array $input,?array $u,?array $existing=null): array {
 $visibility=$input['visibility']??$existing['visibility']??'unlisted';
 if(!is_string($visibility)||!in_array($visibility,['public','unlisted','password','friends','selected'],true))throw new DomainException('invalid');
 if(in_array($visibility,['public','friends','selected'],true)&&!$u)throw new DomainException('auth');
 $old=$existing?tsAccessRow($existing['token']):['password_hash'=>'','recipients_list'=>[]];$hash='';$recipients=[];
 if($visibility==='password'){
  $password=$input['password']??'';if(!is_string($password))throw new DomainException('invalid');
  if($password==='')$hash=($existing['visibility']??'')==='password'?$old['password_hash']:'';
  else{if(mb_strlen($password)<8||strlen($password)>72)throw new DomainException('share_password_length');$hash=password_hash($password,PASSWORD_DEFAULT);}
  if(!$hash)throw new DomainException('share_password_length');
 }
 if($visibility==='selected'){
  $recipients=$input['recipients']??$old['recipients_list'];
  if(!is_array($recipients)||!$recipients||count($recipients)>50)throw new DomainException('share_select_friends');
  $allowed=array_column(tsFriends($u),'id');foreach($recipients as $id)if(!is_string($id)||!in_array($id,$allowed,true))throw new DomainException('share_select_friends');
  $recipients=array_values(array_unique($recipients));sort($recipients);
 }
 return compact('visibility','hash','recipients');
}
function tsAccessStore(string $token,array $access): void {
 socialQuery('INSERT INTO trip_share_access(token,password_hash,recipients) VALUES(?,?,?) ON CONFLICT(token) DO UPDATE SET password_hash=excluded.password_hash,recipients=excluded.recipients,version=trip_share_access.version+1',[$token,$access['hash'],json_encode($access['recipients'])]);
 unset($_SESSION['trip_share_unlock'][$token]);
}
function tsUnlock(array $r,string $password): bool {
 if($r['revoked']||$r['visibility']!=='password')return false;
 $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'');
 if(!throttle('share-password-ip:'.$ip,30,900)||!throttle('share-password:'.$r['token'].':'.$ip,10,900))throw new DomainException('rate_limit');
 $access=tsAccessRow($r['token']);if(strlen($password)>72||!$access['password_hash']||!password_verify($password,$access['password_hash']))return false;
 session_regenerate_id(true);$_SESSION['trip_share_unlock'][$r['token']]=['key'=>hash('sha256',$access['password_hash'].':'.$access['version']),'until'=>time()+43200];return true;
}
