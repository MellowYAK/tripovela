<?php
declare(strict_types=1);
require_once __DIR__.'/visitor-locale.php';
require_once __DIR__.'/interface-locales.php';

function languageSettings(): array {
 if(isset($GLOBALS['trip_language_settings']))return $GLOBALS['trip_language_settings'];
 $saved=json_decode(setting('language_settings',''),true);$config=is_array($saved)?$saved:['default'=>'en','languages'=>[]];
 foreach(interfaceLanguageManifest() as $code=>$row){
  if(in_array($code,$config['removed_languages']??[],true))continue;
  $config['languages'][$code]=array_replace($row,['enabled'=>interfaceLanguageReady($code)],$config['languages'][$code]??[]);
  if(!interfaceLanguageReady($code))$config['languages'][$code]['enabled']=false;
 }
 $config['default']=$config['default']??'en';$config['hide_he']=false;$config['blocked_countries']=$config['blocked_countries']??[];
 return $GLOBALS['trip_language_settings']=$config;
}
function enabledLanguages(): array {return array_filter(languageSettings()['languages'],fn($v)=>!empty($v['enabled']));}
function validSiteLanguage($value): bool {return is_string($value)&&isset(enabledLanguages()[$value]);}
function siteLanguage(): string {
 if(strpos($_SERVER['REQUEST_URI']??'', '/KNISA/')===0){foreach([$_GET['lang']??$_POST['lang']??null,$_SESSION['admin_language']??'en'] as $candidate)if(validSiteLanguage($candidate))return $candidate;return fallbackSiteLanguage();}
 foreach([$_GET['lang']??null,$_POST['lang']??null] as $lang)if(validSiteLanguage($lang))return $lang;
 if(validSiteLanguage($_COOKIE['tripovela_lang']??null))return $_COOKIE['tripovela_lang'];
 if($lang=memberInterfaceLanguage())return $lang;
 return languageSettings()['default']??'en';
}
function rememberSiteLanguage(): void {
 if(strpos($_SERVER['REQUEST_URI']??'','/KNISA/')===0)return;
 $explicit=siteLanguage();
 if(validSiteLanguage($explicit)){
  setcookie('tripovela_lang',$explicit,['expires'=>time()+31536000,'path'=>'/','secure'=>APP_ENV!=='local','httponly'=>true,'samesite'=>'Lax']);
  $_COOKIE['tripovela_lang']=$explicit;
  if(($_GET['switch_lang']??'')==='1'&&!isset($_GET['currency'])){
   $currency=tripDefaultCurrency($explicit);$key='tripovela_currency_'.$explicit;
   setcookie($key,$currency,['expires'=>time()+31536000,'path'=>'/','secure'=>APP_ENV!=='local','httponly'=>false,'samesite'=>'Lax']);$_COOKIE[$key]=$currency;
  }
 }
}
// Explicit language in every link takes precedence over remembered browser language.
function preserveSharedLinkLanguage(string $path): void {}
function languageSwitchUrl(string $url,string $lang): string {
 $url=languageUrl($url,$lang);$fragment='';if(str_contains($url,'#'))[$url,$fragment]=explode('#',$url,2);[$base,$query]=array_pad(explode('?',$url,2),2,'');parse_str($query,$params);unset($params['currency']);$params['switch_lang']='1';return $base.'?'.http_build_query($params).($fragment!==''?'#'.$fragment:'');
}
function needsLanguageChoice(): bool {return false;}
function languageChooser(bool $full=false): string {
 $html='<section class="language-welcome'.($full?' language-welcome-full':'').'" translate="no" aria-label="Choose your language"><span class="eyebrow">TRIPOVELA</span><h1>Choose your language</h1><p lang="ru">Выберите язык</p><p lang="he" dir="rtl">בחרו את שפת האתר</p><div class="language-choices">';
 foreach(enabledLanguages() as $code=>$row)$html.='<a class="button" hreflang="'.e($code).'" lang="'.e($code).'" href="'.e(languageUrl($_SERVER['REQUEST_URI']??'/',$code)).'">'.e($row['label']).'</a>';
 return $html.'</div><p>We’ll remember your choice · Мы запомним ваш выбор · הבחירה תישמר</p></section>';
}
function languageDictionary(?string $lang=null): array {
 static $cache=[];$lang=$lang??siteLanguage();
 if($lang==='he')return [];
 if(!isset($cache[$lang])){
  $file=ROOT.'/content/i18n/'.$lang.'.json';
  $cache[$lang]=is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):[];
  $custom=json_decode(setting('language_dictionary_'.$lang,''),true);if(is_array($custom))$cache[$lang]=array_replace($cache[$lang],$custom);
 }
 return $cache[$lang];
}
function translatedText(string $text,?string $lang=null): string {
 // Request-local dictionaries only: no rendered HTML, member data or cross-request cache.
 static $resolved=[];$lang=$lang??siteLanguage();if($lang==='he')return $text;
 $dict=$resolved[$lang]??=($lang==='en'?languageDictionary('en'):array_replace(languageDictionary('en'),languageDictionary($lang)));
 $key=trim(preg_replace('/\s+/u',' ',$text));
 if(isset($dict[$key]))return preg_replace_callback('/\S(?:.*\S)?/us',fn($m)=>$dict[$key],$text,1);
 // Variable counts and labels are assembled by templates, not sent to an external translator.
 if(preg_match('/^([\d,.]+)\s+(.+)$/u',$key,$m)&&isset($dict[$m[2]]))return $m[1].' '.$dict[$m[2]];
 if(preg_match('/^(.+?)(:\s+|\s+[·|]\s+)(.+)$/us',$text,$m))return translatedText($m[1],$lang).$m[2].translatedText($m[3],$lang);
 if(preg_match('/^(.+?)\s+([\d,.]+)$/u',$key,$m)&&isset($dict[$m[1]]))return $dict[$m[1]].' '.$m[2];
 if(($lang??siteLanguage())!=='he'){$english=languageDictionary('en');if(isset($english[$key]))return $english[$key];}
 return $text;
}
function languagePhrase(string $he,string $en,string $ru): string {
 $lang=siteLanguage();if(isset(['he'=>1,'en'=>1,'ru'=>1][$lang]))return ['he'=>$he,'en'=>$en,'ru'=>$ru][$lang];
 $dict=languageDictionary();$value=$dict[$en]??$dict[$he]??$en;
 return $value.(in_array($he,['החל מ־','הטיול שלי ב'],true)?' ':'');
}
function languageUrl(string $url,string $lang): string {
 if(!validSiteLanguage($lang))$lang=fallbackSiteLanguage();
 $u=parse_url($url);if($u===false)return $url;
 $prefix='';
 if(strpos($url,'//')===0||strpos($url,'\\')!==false)return $url;
 if(isset($u['scheme'])||isset($u['host'])){
  if(!in_array(strtolower($u['scheme']??''),['http','https'],true)||strtolower($u['host']??'')!==strtolower(parse_url(APP_URL,PHP_URL_HOST)??'')||isset($u['user'])||isset($u['pass']))return $url;
  $prefix=$u['scheme'].'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'');
 }
 $path=$u['path']??'';
 if(preg_match('~^/(?:go|assets)(?:/|$)~i',$path)||($path===''&&!isset($u['query'])))return $url;
 parse_str($u['query']??'',$query);$query['lang']=$lang;
 if($lang!=='he'&&is_string($query['q']??null)&&isset(guideTopics()[$query['q']]))$query['q']=translatedText($query['q'],$lang);
 return $prefix.$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986).(isset($u['fragment'])?'#'.$u['fragment']:'');
}
function languageNavigation(): string {

 $current=$_SERVER['REQUEST_URI']??'/';if(strpos($current,'/go/')===0)$current='/';
 $flags=[];foreach(interfaceLanguageManifest() as $code=>$row)$flags[$code]=$row['flag'];$languages=enabledLanguages();$selected=siteLanguage();
 $flag=static function($code)use($flags){return isset($flags[$code])?'<img src="/assets/flags/'.e($flags[$code]).'.svg" width="24" height="16" alt="">':'';};
 $html='<details class="header-language" translate="no"><summary aria-label="'.e(languagePhrase('בחירת שפה','Choose language','Выбрать язык')).'">'.$flag($selected).'<span>'.e($languages[$selected]['label']??$selected).'</span><span aria-hidden="true">⌄</span></summary><nav class="header-language-options" aria-label="'.e(languagePhrase('שפה','Language','Язык')).'">';
 foreach($languages as $code=>$row){
  if($code==='he'&&$selected!=='he'&&!empty(languageSettings()['hide_he']))continue;
  $html.='<a lang="'.e($code).'" hreflang="'.e($code).'" href="'.e(languageSwitchUrl($current,$code)).'"'.($code===$selected?' aria-current="true"':'').'>'.$flag($code).'<span>'.e($row['label']).'</span></a>';
 }
 return $html.'</nav></details>';
}
function localizePage(string $html): string {
 $lang=siteLanguage();if($lang==='he')return $html;
 $doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();libxml_use_internal_errors($old);
 $xpath=new DOMXPath($doc);
 foreach($doc->getElementsByTagName('option') as $option){$code=$option->getAttribute('value');if(function_exists('networkContentLanguages')&&isset(networkContentLanguages()[$code])&&trim($option->textContent)===networkContentLanguages()[$code])$option->setAttribute('lang',$code);}
 $newInterface=!in_array($lang,['he','en','ru','ka'],true);
 $selector=$newInterface?'//text()[not(ancestor::script or ancestor::style or ancestor::textarea or ancestor::pre or ancestor::code or ancestor::*[@data-user-content] or ancestor::*[contains(concat(" ",normalize-space(@class)," ")," network-card-text ")] or ancestor::*[contains(concat(" ",normalize-space(@class)," ")," network-author-link ")] or ancestor::option)]':'//text()[not(ancestor::script or ancestor::style or ancestor::textarea or ancestor::*[@translate="no"] or ancestor::*[@data-user-content] or ancestor::*[contains(concat(" ",normalize-space(@class)," ")," network-card-text ")] or ancestor::*[contains(concat(" ",normalize-space(@class)," ")," network-author-link ")])]';
 foreach($xpath->query($selector) as $node){$parent=$node->parentNode;$foreign=false;while($parent instanceof DOMElement){if($parent->hasAttribute('lang')&&$parent->tagName!=='html'&&$parent->getAttribute('lang')!==$lang){$foreign=true;break;}$parent=$parent->parentNode;}if(!$foreign)$node->nodeValue=translatedText($node->nodeValue);}
 // Options are UI except native language-name options, which have no dictionary match.
 if($newInterface)foreach($xpath->query('//option[not(@lang)]/text()') as $node)$node->nodeValue=translatedText($node->nodeValue);
 foreach($doc->getElementsByTagName('*') as $el){
  foreach(['alt','title','placeholder','aria-label','data-title'] as $attr)if($el->hasAttribute($attr))$el->setAttribute($attr,translatedText($el->getAttribute($attr)));
  if($el->tagName==='meta'&&in_array($el->getAttribute('name')?:$el->getAttribute('property'),['description','og:title','og:description'],true))$el->setAttribute('content',translatedText($el->getAttribute('content')));
  if($el->tagName==='a'&&$el->hasAttribute('href')&&!$el->hasAttribute('hreflang'))$el->setAttribute('href',languageUrl($el->getAttribute('href'),$lang));
 }
 foreach($doc->getElementsByTagName('form') as $form){
  $input=$doc->createElement('input');$input->setAttribute('type','hidden');$input->setAttribute('name','lang');$input->setAttribute('value',$lang);$form->appendChild($input);
 }
 foreach(iterator_to_array($doc->childNodes) as $node)if($node->nodeType===XML_PI_NODE)$doc->removeChild($node);
 return $doc->saveHTML();
}
function localizedSearchText(array $article): string {
 $body=md($article['body']);$doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8">'.$body);libxml_clear_errors();libxml_use_internal_errors($old);
 $parts=[translatedText($article['title']),translatedText($article['summary'])];
 $xp=new DOMXPath($doc);foreach($xp->query('//text()') as $node)$parts[]=translatedText($node->nodeValue);
 return implode(' ',$parts);
}

function clientLanguageDictionary(): array {
 $lang=siteLanguage();if($lang==='he')return [];
 $keys=json_decode(file_get_contents(ROOT.'/content/i18n/client-keys.json'),true)??[];
 $dictionary=$lang==='ka'&&is_file(ROOT.'/content/i18n/ka-client.json')?json_decode(file_get_contents(ROOT.'/content/i18n/ka-client.json'),true):languageDictionary();
 return array_intersect_key(array_replace(languageDictionary('en'),$dictionary),array_fill_keys($keys,true));
}
