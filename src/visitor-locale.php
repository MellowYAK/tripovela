<?php
declare(strict_types=1);

// Shared registry for future interface languages. An entry never enables a translation.
function localeRegions(): array {
 static $rows;return $rows??=json_decode(file_get_contents(ROOT.'/content/locale-regions.json'),true,512,JSON_THROW_ON_ERROR);
}
function countryPreferredLanguage(string $country): string {
 foreach(array_replace_recursive(localeRegions(),languageSettings()['languages']) as $lang=>$row)if(in_array(strtoupper($country),$row['countries']??[],true))return $lang;
 return '';
}
function countrySiteLanguage(string $country): string {
 $lang=countryPreferredLanguage($country);return validSiteLanguage($lang)?$lang:fallbackSiteLanguage();
}
function visitorCountry(): string {
 // Only the server's actual peer address; never trust visitor-supplied country/XFF headers.
 $ip=$_SERVER['REMOTE_ADDR']??'';
 if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return '';
 if(isset($_SESSION['visitor_country'])&&($_SESSION['visitor_country_peer']??'')===hash('sha256',$ip))return (string)$_SESSION['visitor_country'];
 $file=STORAGE_PATH.'/geo-country.sqlite';if(!is_file($file))return '';
 try{
  $packed=inet_pton($ip);$family=strlen($packed)===4?4:6;$hex=bin2hex($packed);
  $db=new PDO('sqlite:'.$file);$db->exec('PRAGMA query_only=ON');
  $q=$db->prepare('SELECT last,country FROM ranges WHERE family=? AND first<=? ORDER BY first DESC LIMIT 1');$q->execute([$family,$hex]);$row=$q->fetch(PDO::FETCH_ASSOC);
  $country=$row&&strcmp($hex,$row['last'])<=0?$row['country']:'';
  $_SESSION['visitor_country']=$country;$_SESSION['visitor_country_peer']=hash('sha256',$ip);return $country;
 }catch(Throwable $e){return '';}
}
function memberInterfaceLanguage(): ?string {
 if(empty($_SESSION['member_id'])||!function_exists('socialUser'))return null;
 if(array_key_exists('trip_member_interface',$GLOBALS))return $GLOBALS['trip_member_interface'];
 if(!($user=socialUser()))return null;
 $lang=socialQuery('SELECT interface_language FROM network_language_preferences WHERE member=?',[$user['id']])->fetchColumn();
 return $GLOBALS['trip_member_interface']=validSiteLanguage($lang)?$lang:null;
}
function automaticLanguageNotice(): string {return '';}
