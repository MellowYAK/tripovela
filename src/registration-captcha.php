<?php
declare(strict_types=1);
// Private runtime configuration, never part of a code release or public API response.
function mrCaptchaConfig(): array {
 $file=STORAGE_PATH.'/registration-captcha.json';
 if(!is_file($file))return ['enabled'=>APP_ENV==='production'];
 try{$c=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);return is_array($c)?$c:['enabled'=>true];}
 catch(Throwable $e){return ['enabled'=>true];}
}
function mrCaptchaReady(array $c): bool {
 if(empty($c['site_key'])||empty($c['secret_key'])||empty($c['hostnames'])||!is_array($c['hostnames']))return false;
 // Never allow Cloudflare's public testing credentials on the live site.
 return APP_ENV==='local'||(!preg_match('/^[123]x0+/',(string)$c['site_key'])&&!preg_match('/^[123]x0+/',(string)$c['secret_key']));
}
function mrCaptchaResultValid(array $result,array $config): bool {
 return ($result['success']??false)===true&&($result['action']??'')==='register'
  &&is_string($result['hostname']??null)&&in_array(strtolower($result['hostname']),$config['hostnames'],true);
}
function mrCaptchaVerify(array $input): void {
 $c=mrCaptchaConfig();if(empty($c['enabled']))return;
 if(!mrCaptchaReady($c)||!function_exists('curl_init'))throw new DomainException('captcha_unavailable');
 $token=$input['cf-turnstile-response']??$input['captcha_token']??'';
 if(!is_string($token)||trim($token)===''||strlen($token)>2048)throw new DomainException('captcha_required');
 $curl=curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
 curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['secret'=>$c['secret_key'],'response'=>$token]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
 if($body===false||$status!==200)throw new DomainException('captcha_unavailable');
 try{$result=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new DomainException('captcha_unavailable');}
 if(!is_array($result)||!mrCaptchaResultValid($result,$c))throw new DomainException('captcha_required');
}
function mrCaptchaWidget(): void {
 $c=mrCaptchaConfig();if(empty($c['enabled']))return;
 if(!mrCaptchaReady($c)){echo '<p role="alert">'.e(mrLabels('captcha_unavailable')).'</p>';return;}
 $language=siteLanguage()==='ka'?'auto':siteLanguage();
 echo '<fieldset><legend>'.e(mrLabels('captcha_title')).'</legend><div class="cf-turnstile" data-sitekey="'.e($c['site_key']).'" data-action="register" data-language="'.e($language).'" data-size="flexible" data-theme="light"></div></fieldset><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
}
