<?php
declare(strict_types=1);
// This entry point never migrates, resets or replaces an existing database.
function installPath(): string {return dirname(__DIR__).'/storage';}
function installText(string $key,string $lang): string {
 static $labels;$labels??=json_decode(file_get_contents(dirname(__DIR__).'/content/install-labels.json'),true,512,JSON_THROW_ON_ERROR);
 return htmlspecialchars($labels[$lang][$key]??$labels['en'][$key],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function installGate(): void {
 $root=dirname(__DIR__);
 // Configured installations continue through their established bootstrap.
 if(is_file($root.'/.env')||is_file(installPath().'/site.env')||getenv('TRIPOVELA_STORAGE'))return;
 $manifest=json_decode(file_get_contents($root.'/content/interface-languages.json'),true);
 $lang=(string)($_GET['lang']??$_POST['lang']??'en');if(!isset($manifest[$lang]))$lang='en';
 $t=fn($key)=>installText($key,$lang);
 header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex');
 ini_set('session.use_strict_mode','1');session_name('tripovela_install');session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);session_start();
 $_SESSION['csrf']??=bin2hex(random_bytes(32));
 $storage=installPath();$secret=is_file($storage.'/install-token.json')?json_decode(file_get_contents($storage.'/install-token.json'),true):[];
 $token=(string)($_GET['token']??'');
 if($token!==''&&strlen($token)===64&&($secret['expires']??0)>time()&&hash_equals($secret['hash']??'',hash('sha256',$token))){session_regenerate_id(true);$_SESSION['install_until']=min(time()+900,$secret['expires']);header('Location: /install/?lang='.rawurlencode($lang),true,303);exit;}
 $authorized=($_SESSION['install_until']??0)>time()&&($secret['expires']??0)>time();$error='';
 if($_SERVER['REQUEST_METHOD']==='POST'&&$authorized){
  if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf'])){$error='failed';}
  else{
   $username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');$url=rtrim(trim((string)($_POST['url']??'')),'/');$u=parse_url($url);
   $local=in_array($u['host']??'',['localhost','127.0.0.1','::1'],true);
   if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,63}$/D',$username)||strlen($password)<12||strlen($password)>200||!filter_var($url,FILTER_VALIDATE_URL)||!in_array($u['scheme']??'',['http','https'],true)||(!$local&&($u['scheme']??'')!=='https')||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||!in_array($u['path']??'',['','/'],true)||preg_match('/[\r\n"\\\\]/',$url))$error='invalid';
   else{
    $lock=fopen($storage.'/install.lock','c');
    if(!$lock||!flock($lock,LOCK_EX)){$error='failed';}
    else try{
     if(is_file($root.'/.env')||is_file($storage.'/site.env')||glob($storage.'/*.sqlite'))throw new RuntimeException('Existing installation');
     $temp=$storage.'/install-'.bin2hex(random_bytes(12)).'.sqlite';
     $db=new PDO('sqlite:'.$temp);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
     foreach(glob($root.'/database/migrations/*.sql') as $migration)$db->exec(file_get_contents($migration));
     $db->prepare('INSERT INTO users(email,password,role) VALUES(?,?,?)')->execute([$username,password_hash($password,PASSWORD_DEFAULT),'admin']);
     $db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)')->execute(['language_settings',json_encode(['default'=>$lang,'languages'=>[]])]);
     $db=null;
     $catalogTemp=$storage.'/install-catalog-'.bin2hex(random_bytes(12)).'.sqlite';
     $catalog=new PDO('sqlite:'.$catalogTemp);$catalog->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
     $catalog->exec(file_get_contents($root.'/database/catalog.sql'));
     foreach($manifest as $code=>$row)$catalog->prepare('INSERT INTO editorial_locales(code,label,direction) VALUES(?,?,?)')->execute([$code,$row['label'],$row['dir']??'ltr']);
     $catalog=null;
     $env="APP_ENV=".($local?'local':'production')."\nAPP_URL=\"".$url."\"\nANALYTICS_ENABLED=false\nPUBLIC_INDEXING_ENABLED=false\n";
     // Exclusive config creation is the final installation guard.
     if(!rename($catalogTemp,$storage.'/catalog.sqlite'))throw new RuntimeException('Catalog installation failed');
     if(!rename($temp,$storage.'/tripovela.sqlite'))throw new RuntimeException('Database installation failed');
     $file=fopen($storage.'/site.env','x');if(!$file)throw new RuntimeException('Configuration exists');
     if(fwrite($file,$env)!==strlen($env)){fclose($file);throw new RuntimeException('Configuration write failed');}fclose($file);@chmod($storage.'/site.env',0600);
     unlink($storage.'/install-token.json');unset($_SESSION['install_until']);session_regenerate_id(true);
     header('Location: /KNISA/?lang='.rawurlencode($lang),true,303);exit;
    }catch(Throwable $ex){error_log('Tripovela installation: '.$ex->getMessage());$error='failed';}
    finally{flock($lock,LOCK_UN);fclose($lock);}
   }
  }
 }
 $dir=in_array($lang,['he','ar'],true)?'rtl':'ltr';
 ?><!doctype html><html lang="<?=htmlspecialchars($lang)?>" dir="<?=$dir?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=$t('title')?></title><link rel="stylesheet" href="/assets/install.css"></head><body><main><img src="/assets/tripovela-logo.svg" width="190" alt="Tripovela"><h1><?=$t('title')?></h1><form method="get"><label><?=$t('language')?><select name="lang" onchange="this.form.submit()"><?php foreach($manifest as $code=>$row):?><option value="<?=htmlspecialchars($code)?>" <?=$code===$lang?'selected':''?>><?=htmlspecialchars($row['label'])?></option><?php endforeach?></select></label></form>
 <?php if(!$authorized):?><p><?=$t('locked')?></p><code>php bin/install-token.php</code><?php else:?><p><?=$t('intro')?></p><?php if($error):?><p role="alert"><?=$t($error)?></p><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>"><input type="hidden" name="lang" value="<?=htmlspecialchars($lang)?>"><label><?=$t('url')?><input name="url" type="url" required placeholder="https://example.com" value="<?=htmlspecialchars((string)($_POST['url']??''),ENT_QUOTES)?>"></label><label><?=$t('username')?><input name="username" required minlength="3" maxlength="64" pattern="[a-zA-Z0-9][a-zA-Z0-9_.\-]{2,63}" autocomplete="username" value="<?=htmlspecialchars((string)($_POST['username']??''),ENT_QUOTES)?>"></label><label><?=$t('password')?><input name="password" type="password" required minlength="12" maxlength="200" autocomplete="new-password"><small><?=$t('password_hint')?></small></label><button><?=$t('install')?></button></form><?php endif?></main></body></html><?php exit;
}
