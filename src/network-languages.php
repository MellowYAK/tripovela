<?php
declare(strict_types=1);
// Publication languages do not imply a translated interface or machine translation.
function networkContentLanguages(): array {
 $languages=['he'=>'עברית','en'=>'English','ru'=>'Русский','ka'=>'ქართული','de'=>'Deutsch','fr'=>'Français','es'=>'Español','it'=>'Italiano','pt'=>'Português','ar'=>'العربية','uk'=>'Українська','pl'=>'Polski','nl'=>'Nederlands','el'=>'Ελληνικά','tr'=>'Türkçe','cs'=>'Čeština','ro'=>'Română','hu'=>'Magyar','sv'=>'Svenska','da'=>'Dansk','nb'=>'Norsk','fi'=>'Suomi','is'=>'Íslenska','bg'=>'Български','sr'=>'Српски','hr'=>'Hrvatski','sk'=>'Slovenčina','sl'=>'Slovenščina','lt'=>'Lietuvių','lv'=>'Latviešu','et'=>'Eesti','hy'=>'Հայերեն','az'=>'Azərbaycanca','fa'=>'فارسی','hi'=>'हिन्दी','zh'=>'中文','ja'=>'日本語','ko'=>'한국어','th'=>'ไทย','vi'=>'Tiếng Việt','id'=>'Bahasa Indonesia','ms'=>'Bahasa Melayu'];
 try{foreach(socialQuery('SELECT DISTINCT language FROM network_localizations UNION SELECT DISTINCT language FROM network_comments UNION SELECT DISTINCT publication_language AS language FROM network_language_preferences') as $row){$code=$row['language'];if(networkValidContentLanguage($code)&&!isset($languages[$code]))$languages[$code]=$code;}}catch(Throwable $e){}
 return $languages;
}
function networkValidContentLanguage($value): bool {return is_string($value)&&preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D',$value)===1&&strlen($value)<=35;}
function networkLanguagePickerConfig(): array {static $data; $data??=json_decode(file_get_contents(ROOT.'/content/language-picker.json'),true);return ['labels'=>$data['labels'][siteLanguage()]??$data['labels']['en'],'flags'=>$data['flags']];}
function networkPublicationLanguageInput(string $name,string $selected): string {
 $languages=networkContentLanguages();if(networkValidContentLanguage($selected)&&!isset($languages[$selected]))$languages[$selected]=$selected;
 $manifest=interfaceLanguageManifest();$html='<span class="publication-language" translate="no"><select name="'.e($name).'" required data-publication-language>';
 foreach($languages as $code=>$label){$flag=networkLanguagePickerConfig()['flags'][$code]??$manifest[$code]['flag']??'';$html.='<option value="'.e($code).'" lang="'.e($code).'" data-flag="'.e($flag).'"'.($code===$selected?' selected':'').'>'.e($label).'</option>';}
 return $html.'</select></span>';
}
// A manual guest choice survives PHP session expiry; QA sessions stay isolated.
function networkGuestFeedLanguage(): string {
 foreach([$_SESSION['guest_feed_language']??null,qaActive()?null:($_COOKIE['tripovela_feed_language']??null)] as $value)
  if(is_string($value)&&($value==='all'||networkValidContentLanguage($value)))return $value;
 return networkFeedDefault();
}
function networkLanguagePreferences(?array $user=null): array {
 $user??=socialUser();
 if(!$user)return ['publication_language'=>siteLanguage(),'feed_language'=>networkGuestFeedLanguage()];
 $row=socialQuery('SELECT * FROM network_language_preferences WHERE member=?',[$user['id']])->fetch();
 if(!$row){$default=networkProfile($user['id'])['language']??siteLanguage();if(!networkValidContentLanguage($default))$default=siteLanguage();socialQuery('INSERT OR IGNORE INTO network_language_preferences(member,publication_language,feed_language) VALUES(?,?,?)',[$user['id'],$default,$default]);$row=socialQuery('SELECT * FROM network_language_preferences WHERE member=?',[$user['id']])->fetch();}
 if(empty($row['feed_custom']))$row['feed_language']=networkFeedDefault();
 return $row;
}
function networkPublicationDefault(): string {return networkLanguagePreferences()['publication_language'];}
function networkFeedDefault(): string {
 if(!empty(languageSettings()['feed_country_default']))return visitorCountry()==='IL'?'he':'en';
 return siteLanguage();
}
function networkFeedLanguage(): string {
 $prefs=networkLanguagePreferences();
 $default=socialUser()?(!empty($prefs['feed_custom'])?$prefs['feed_language']:networkFeedDefault()):(networkGuestFeedLanguage());
 $value=$_GET['feed_language']??$default;
 return is_string($value)&&($value==='all'||networkValidContentLanguage($value))?$value:$default;
}
function networkSaveLanguagePreferences(?array $user,array $input): void {
 $feed=$input['feed_language']??'';if($feed!=='all')networkLanguage($feed);
 if(!$user){
  $_SESSION['guest_feed_language']=$feed;
  if(!qaActive()){
   setcookie('tripovela_feed_language',$feed,['expires'=>time()+31536000,'path'=>'/','secure'=>APP_ENV!=='local','httponly'=>true,'samesite'=>'Lax']);
   $_COOKIE['tripovela_feed_language']=$feed;
  }
  return;
 }
 $old=networkLanguagePreferences($user);$publication=networkLanguage($input['publication_language']??$old['publication_language']);
 $interface=$input['interface_language']??($old['interface_language']??'');
 if($interface!==''&&!validSiteLanguage($interface))throw new DomainException('invalid');
 socialQuery('UPDATE network_language_preferences SET publication_language=?,feed_language=?,interface_language=?,feed_custom=1 WHERE member=?',[$publication,$feed,$interface,$user['id']]);
}
function networkContentLanguage(): string {
 $value=$GLOBALS['network_card_language']??$_POST['content_lang']??$_GET['content_lang']??siteLanguage();
 return is_string($value)&&networkValidContentLanguage($value)?$value:siteLanguage();
}
function networkLanguageOptions(string $selected,bool $all=false): string {
 $options=$all?['all'=>nt('all_languages')]+networkContentLanguages():networkContentLanguages();$html='';
 foreach($options as $code=>$label)$html.='<option lang="'.e($code).'" value="'.e($code).'"'.($code===$selected?' selected':'').'>'.e($label).'</option>';
 return $html;
}
function networkContentLanguageField(): string {return '<input type="hidden" name="content_lang" value="'.e(networkContentLanguage()).'">';}
function networkLanguageBadge(string $language): string {return '<span class="network-language-badge">'.e(nt('original_language')).': <bdi>'.e(networkContentLanguages()[$language]??$language).'</bdi></span>';}
