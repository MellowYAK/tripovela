<?php
// Administrative provisioning only. Never emits an advertising signup conversion.
function amCreate(PDO $d,array $input,int $admin,string $lang): array {
 $token=socialString($input['request_token']??'',32,32);if(!ctype_xdigit($token))throw new DomainException('invalid');
 $d->exec('CREATE TABLE IF NOT EXISTS admin_created_members(request_token TEXT PRIMARY KEY,admin_id INTEGER NOT NULL,member TEXT NOT NULL UNIQUE REFERENCES members(id),created INTEGER NOT NULL)');
 $handle=strtolower(socialString($input['new_handle']??'',30));if($handle==='')$handle='tester_'.bin2hex(random_bytes(5));
 if(!preg_match('/^[a-z0-9][a-z0-9_-]{2,29}$/D',$handle))throw new DomainException('handle_hint');
 $password=!empty($input['generate_password'])?bin2hex(random_bytes(12)):socialString($input['new_password']??'',72,8);
 if(strlen($password)>72)throw new DomainException('invalid');
 $name=socialString($input['display_name']??'',60)?:$handle;
 $d->exec('BEGIN IMMEDIATE');try{
  $q=$d->prepare('SELECT m.id,m.handle FROM admin_created_members a JOIN members m ON m.id=a.member WHERE a.request_token=? AND a.admin_id=?');$q->execute([$token,$admin]);if($row=$q->fetch()){$d->exec('COMMIT');return $row+['password'=>''];}
  $q=$d->prepare('SELECT 1 FROM members WHERE handle=?');$q->execute([$handle]);if($q->fetchColumn())throw new DomainException('handle_taken');
  $id=bin2hex(random_bytes(16));$d->prepare('INSERT INTO members(id,handle,password,recovery,created) VALUES(?,?,?,?,?)')->execute([$id,$handle,password_hash($password,PASSWORD_DEFAULT),hash('sha256',random_bytes(32)),time()]);
  $d->prepare('INSERT INTO member_profiles(member,name,language,updated) VALUES(?,?,?,?)')->execute([$id,$name,$lang,time()]);
  $d->prepare('INSERT INTO network_language_preferences(member,publication_language,feed_language,interface_language) VALUES(?,?,?,?)')->execute([$id,$lang,$lang,$lang]);
  $d->prepare('INSERT INTO admin_created_members VALUES(?,?,?,?)')->execute([$token,$admin,$id,time()]);$d->exec('COMMIT');return ['id'=>$id,'handle'=>$handle,'password'=>$password];
 }catch(Throwable $e){if($d->inTransaction())$d->rollBack();throw $e;}
}
function amRows(PDO $d,int $admin): array {
 if(!$d->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='admin_created_members'")->fetchColumn())return [];
 $q=$d->prepare("SELECT m.id,m.handle FROM admin_created_members a JOIN members m ON m.id=a.member WHERE a.admin_id=? AND m.state='active' ORDER BY a.created DESC LIMIT 100");$q->execute([$admin]);return $q->fetchAll();
}
function amMember(PDO $d,string $id,int $admin): array {
 foreach(amRows($d,$admin) as $m)if(hash_equals($m['id'],$id)){$q=$d->prepare('SELECT id,handle,session_version FROM members WHERE id=?');$q->execute([$id]);return $q->fetch();}throw new DomainException('not_found');
}
function amState(array $member): array {return ['csrf'=>$_SESSION['csrf'],'member_id'=>$member['id'],'member_version'=>$member['session_version'],'member_seen'=>time(),'member_remember_checked'=>true];}
