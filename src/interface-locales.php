<?php
declare(strict_types=1);
function interfaceLanguageManifest(): array {static $m;return $m??=json_decode(file_get_contents(ROOT.'/content/interface-languages.json'),true,512,JSON_THROW_ON_ERROR);}
function interfaceLanguageReady(string $lang): bool {return isset(interfaceLanguageManifest()[$lang])&&($lang==='he'||is_file(ROOT.'/content/i18n/'.$lang.'.json'));}
function fallbackSiteLanguage(): string {
 $enabled=enabledLanguages();return isset($enabled['en'])?'en':(isset($enabled[languageSettings()['default']])?languageSettings()['default']:(array_key_first($enabled)??'en'));
}
function uiText(string $text): string {return translatedText($text);}
function uiTranslateTree($value){if(is_string($value))return uiText($value);if(is_array($value))return array_map('uiTranslateTree',$value);return $value;}
function regionCountryNames(): array {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/country-locales.json'),true);return $all[siteLanguage()]??$all['en'];}
function regionBlocked(string $country): bool {return $country!==''&&in_array($country,languageSettings()['blocked_countries']??[],true);}
function enforceRegionAccess(string $path): void {
 // Keep the private management login reachable so administrators can undo a block.
 if(str_starts_with($path,'/KNISA/')||!regionBlocked(visitorCountry()))return;
 http_response_code(403);header('X-Robots-Tag: noindex, nofollow');
 if(str_starts_with($path,'/api/')){header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>'region_unavailable','message'=>uiText('This service is not available in your country.')],JSON_UNESCAPED_UNICODE);exit;}
 echo '<!doctype html><html lang="'.e(siteLanguage()).'" dir="'.e(languageSettings()['languages'][siteLanguage()]['dir']??'ltr').'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tripovela</title><body><main><h1>Tripovela</h1><p>'.e(uiText('This service is not available in your country.')).'</p></main></body></html>';exit;
}
function rememberAdminLanguage(): void {
 $lang=$_GET['lang']??$_POST['lang']??null;
 if(validSiteLanguage($lang))$_SESSION['admin_language']=$lang;
}

// Stable interface keys; user-authored content must never pass through this lookup.
function uiMessage(string $key,array $values=[]): string {
 static $messages;$messages??=json_decode(file_get_contents(ROOT.'/content/admin-release-labels.json'),true,512,JSON_THROW_ON_ERROR);
 $message=$messages[siteLanguage()][$key]??$messages['en'][$key]??$key;
 foreach($values as $name=>$value)$message=str_replace('{'.$name.'}',(string)$value,$message);
 return $message;
}
