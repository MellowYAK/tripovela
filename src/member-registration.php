<?php
require_once __DIR__.'/registration-captcha.php';
// Shared application service: no HTTP globals, redirects, cookies or mail delivery.
function mrLabels(string $key): string {static $labels;$labels??=json_decode(file_get_contents(ROOT.'/content/member-registration-labels.json'),true);return $labels[siteLanguage()][$key]??$labels['en'][$key]??$key;}
function mrSchema(): void {
 socialDb()->exec('CREATE TABLE IF NOT EXISTS member_email_identity(member TEXT PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,email TEXT NOT NULL UNIQUE,verified_at INTEGER,token_hash TEXT,token_expires INTEGER,updated INTEGER NOT NULL)');
}
function mrRegister(array $input,string $language): array {
 $handle=strtolower(socialString($input['handle']??'',30,3));
 if(!preg_match('/^[a-z0-9][a-z0-9_-]{2,29}$/D',$handle))throw new DomainException('handle_hint');
 $password=socialString($input['password']??'',128,8);
 if(strlen($password)>72)throw new DomainException('invalid');
 if(!is_string($input['password_confirm']??null)||!hash_equals((string)$input['password'],$input['password_confirm']))throw new DomainException('password_mismatch');
 if(empty($input['adult']))throw new DomainException('adult_required');
 // One public username is enough at signup; profile names can be edited later.
 $name=socialString($input['display_name']??$handle,60,1);
 $email=strtolower(socialString($input['email']??'',254,3));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new DomainException('email_invalid');
 $language=networkLanguage($language);mrCaptchaVerify($input);mrSchema();$d=socialDb();$d->beginTransaction();
 try{
  if(socialQuery('SELECT 1 FROM members WHERE handle=?',[$handle])->fetchColumn())throw new DomainException('handle_taken');
  if(socialQuery('SELECT 1 FROM member_email_identity WHERE email=?',[$email])->fetchColumn())throw new DomainException('registration_unavailable');
  $id=bin2hex(random_bytes(16));$recovery=bin2hex(random_bytes(20));
  socialQuery('INSERT INTO members(id,handle,password,recovery,created) VALUES(?,?,?,?,?)',[$id,$handle,password_hash($password,PASSWORD_DEFAULT),hash('sha256',$recovery),time()]);
  socialQuery('INSERT INTO member_profiles(member,name,language,updated) VALUES(?,?,?,?)',[$id,$name,$language,time()]);
  socialQuery('INSERT INTO network_language_preferences(member,publication_language,feed_language,interface_language) VALUES(?,?,?,?)',[$id,$language,$language,$language]);
  socialQuery('INSERT INTO member_email_identity(member,email,updated) VALUES(?,?,?)',[$id,$email,time()]);
  $d->commit();$event=rmRegistration($id);return ['member_id'=>$id,'recovery_code'=>$recovery,'email_verified'=>false,'registration_event'=>$event];
 }catch(Throwable $e){if($d->inTransaction())$d->rollBack();throw $e;}
}
// Transport adapter must send this secret privately; never return it in a public API.
// Issuing a replacement invalidates the previous link. No sender is configured yet.
function mrIssueEmailToken(string $member): string {
 mrSchema();$token=bin2hex(random_bytes(32));
 $q=socialQuery('UPDATE member_email_identity SET token_hash=?,token_expires=?,updated=? WHERE member=? AND verified_at IS NULL',[hash('sha256',$token),time()+3600,time(),$member]);
 if(!$q->rowCount())throw new DomainException('invalid');return $token;
}
function mrVerifyEmailToken(string $token): bool {
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))return false;mrSchema();
 return socialQuery('UPDATE member_email_identity SET verified_at=?,token_hash=NULL,token_expires=NULL,updated=? WHERE token_hash=? AND token_expires>? AND verified_at IS NULL',[time(),time(),hash('sha256',$token),time()])->rowCount()===1;
}
