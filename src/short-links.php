<?php
function shortT(string $key): string {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/short-link-labels.json'),true);return $all[siteLanguage()][$key]??$all['en'][$key]??$key;}
function shortSchema(PDO $db): void {$db->exec('CREATE TABLE IF NOT EXISTS short_links(code TEXT PRIMARY KEY COLLATE NOCASE,target TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,created INTEGER NOT NULL,clicks INTEGER NOT NULL DEFAULT 0)');$db->exec('CREATE TABLE IF NOT EXISTS short_link_visits(id INTEGER PRIMARY KEY,code TEXT NOT NULL,created INTEGER NOT NULL,referrer TEXT NOT NULL)');$db->exec('CREATE INDEX IF NOT EXISTS short_visits_code ON short_link_visits(code,created)');}
function shortTrackedTarget(string $target,array $input): string {
 $target=shortTarget($target);$fragment='';if(($pos=strpos($target,'#'))!==false){$fragment=substr($target,$pos);$target=substr($target,0,$pos);}
 $parts=explode('?',$target,2);$pairs=isset($parts[1])?explode('&',$parts[1]):[];
 foreach(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ref','param'] as $key){$value=trim((string)($input[$key]??''));if($value==='')continue;if(strlen($value)>250||preg_match('/[\x00-\x1f]/',$value))throw new DomainException('invalid');$pairs=array_values(array_filter($pairs,fn($p)=>rawurldecode(explode('=',$p,2)[0])!==$key));$pairs[]=rawurlencode($key).'='.rawurlencode($value);}
 return shortTarget($parts[0].($pairs?'?'.implode('&',$pairs):'').$fragment);
}
function shortTarget(string $target): string {
 $target=trim($target);if(str_starts_with($target,'/')&&!str_starts_with($target,'//'))$target=rtrim(APP_URL,'/').$target;
 $p=parse_url($target);if(strlen($target)>8000||preg_match('/[\x00-\x20\\\\]/',$target)||!filter_var($target,FILTER_VALIDATE_URL)||!in_array(strtolower($p['scheme']??''),['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass']))throw new DomainException('invalid');
 if(strtolower($p['host'])===strtolower(parse_url(APP_URL,PHP_URL_HOST))&&preg_match('~^/s(?:/|$)~i',$p['path']??''))throw new DomainException('invalid');
 return $target;
}
function shortCreate(PDO $db,string $target,string $code=''): string {
 $target=shortTarget($target);$code=trim($code);if($code!==''&&!preg_match('/^[a-zA-Z0-9_-]{1,24}$/D',$code))throw new DomainException('invalid');shortSchema($db);
 if($code===''){$q=$db->prepare('SELECT code FROM short_links WHERE target=? AND active=1 ORDER BY created LIMIT 1');$q->execute([$target]);if($old=$q->fetchColumn())return $old;}
 $custom=$code!=='';for($try=0;$try<20;$try++){
  if(!$custom){$code='';$alphabet='23456789abcdefghjkmnpqrstuvwxyz';for($i=0;$i<4;$i++)$code.=$alphabet[random_int(0,strlen($alphabet)-1)];}
  try{$q=$db->prepare('INSERT INTO short_links(code,target,created) VALUES(?,?,?)');$q->execute([$code,$target,time()]);return $code;}catch(PDOException $e){if($e->getCode()!=='23000')throw $e;$q=$db->prepare('SELECT target,active FROM short_links WHERE code=?');$q->execute([$code]);$row=$q->fetch(PDO::FETCH_ASSOC);if($row&&$row['target']===$target&&$row['active'])return $code;if($custom)throw new DomainException('taken');}
 }throw new RuntimeException('Short link allocation failed');
}
function shortResolve(PDO $db,string $code,bool $measure=false,string $referrer=''): ?string {shortSchema($db);$q=$db->prepare('SELECT code,target FROM short_links WHERE code=? AND active=1');$q->execute([$code]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
 if($measure)try{$p=parse_url($referrer);$ref='';if($p&&in_array(strtolower($p['scheme']??''),['http','https'],true)&&!empty($p['host']))$ref=strtolower($p['scheme']).'://'.strtolower($p['host']).substr($p['path']??'/',0,1000);$db->prepare('INSERT INTO short_link_visits(code,created,referrer) VALUES(?,?,?)')->execute([$row['code'],time(),$ref]);$db->prepare('UPDATE short_links SET clicks=clicks+1 WHERE code=?')->execute([$row['code']]);}catch(Throwable $e){error_log('Short link measurement failed');}
 return $row['target'];}
