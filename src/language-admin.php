<?php
// Routed only after requireAdmin(); global POST CSRF validation is performed by index.php.
$title='ניהול שפות';$config=languageSettings();$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  db()->beginTransaction();
  $current=setting('language_settings','');
  if(!hash_equals(hash('sha256',$current),(string)($_POST['version']??'')))throw new InvalidArgumentException('ההגדרות השתנו. רעננו את הדף לפני שמירה.');
  $action=$_POST['action']??'';
  if($action==='save'){
   $enabled=$_POST['enabled']??[];if(!is_array($enabled))$enabled=[];
   foreach($config['languages'] as $code=>&$row){
    $row['enabled']=in_array($code,$enabled,true);
    if($row['enabled']&&!interfaceLanguageReady($code))throw new InvalidArgumentException('השפה החדשה נשמרת כטיוטה עד להכנת תרגום מלא ובדיקת האתר בשפה זו.');
   }unset($row);
   $default=(string)($_POST['default']??'');
   if(empty($config['languages'][$default]['enabled']))throw new InvalidArgumentException('שפת ברירת המחדל חייבת להיות פעילה. יש להשאיר לפחות שפה אחת פעילה.');
   $config['default']=$default;$config['hide_he']=false;
  }elseif($action==='feed-default'){
   $config['feed_country_default']=($_POST['feed_country_default']??'')==='1';
  }elseif($action==='countries'){
   $blocked=$_POST['blocked_countries']??[];if(!is_array($blocked))throw new InvalidArgumentException('Invalid country selection.');
   $allowed=regionCountryNames();$blocked=array_values(array_unique(array_map('strtoupper',$blocked)));
   foreach($blocked as $country)if(!isset($allowed[$country]))throw new InvalidArgumentException('Invalid country selection.');
   $config['blocked_countries']=$blocked;
  }elseif($action==='add'){
   $code=strtolower(trim((string)($_POST['code']??'')));$label=trim((string)($_POST['label']??''));
   if(!preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/D',$code)||mb_strlen($label)<2||mb_strlen($label)>40)throw new InvalidArgumentException('הזינו קוד שפה תקין ושם בן 2–40 תווים.');
   if(isset($config['languages'][$code]))throw new InvalidArgumentException('השפה כבר קיימת.');
   if(count($config['languages'])>=30)throw new InvalidArgumentException('אפשר לנהל עד 30 שפות.');
   $config['languages'][$code]=['label'=>$label,'enabled'=>false,'dir'=>($_POST['dir']??'ltr')==='rtl'?'rtl':'ltr'];
   $config['removed_languages']=array_values(array_diff($config['removed_languages']??[],[$code]));
  }elseif($action==='delete'){
   $code=(string)($_POST['code']??'');
   if($code===$config['default'])throw new InvalidArgumentException('בחרו ושמרו שפת ברירת מחדל אחרת לפני מחיקת שפה זו.');
   unset($config['languages'][$code]);
   $config['removed_languages']=array_values(array_unique(array_merge($config['removed_languages']??[],[$code])));
  }elseif($action==='restore'){
   foreach(interfaceLanguageManifest() as $code=>$row)
    if(!isset($config['languages'][$code]))$config['languages'][$code]=array_replace($row,['enabled'=>false]);
   $config['removed_languages']=[];
  }else throw new InvalidArgumentException('פעולה לא מוכרת.');
  query('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)',['language_settings',json_encode($config,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
  audit('language-'.$action,'site-languages');db()->commit();redirect('/KNISA/languages/?saved=1');
 }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();$error=$ex->getMessage();$config=languageSettings();}
}
$version=hash('sha256',setting('language_settings',''));
ob_start();?>
<section class="page-heading"><span class="eyebrow">TRIPOVELA</span><h1>ניהול שפות ומדינות</h1><p>שפה בקישור קודמת להגדרות החשבון ולעוגייה. בביקור הראשון השפה נבחרת לפי המדינה. כשאין תרגום זמין, מוצגת אנגלית או שפת ברירת המחדל הפעילה.</p></section>
<nav class="admin-nav"><a href="/KNISA/">חזרה לניהול</a><a href="/KNISA/articles/">מדריכים</a><a href="/KNISA/settings/">הגדרות</a></nav>
<?php if($error):?><p class="notice error" role="alert"><?=e($error)?></p><?php endif?>
<?php if(isset($_GET['saved'])):?><p class="notice" role="status">הגדרות השפות נשמרו.</p><?php endif?>
<script src="/assets/admin-countries.js" defer></script><div class="language-manager">
<form method="post" class="form-panel"><?=csrf()?><input type="hidden" name="version" value="<?=$version?>"><input type="hidden" name="action" value="save">
<fieldset><legend>שפות האתר</legend>
<?php foreach($config['languages'] as $code=>$row):?><label><input type="checkbox" name="enabled[]" value="<?=e($code)?>" <?=!empty($row['enabled'])?'checked':''?> <?=!interfaceLanguageReady($code)?'disabled':''?>> <?php if(isset($row['flag'])):?><img src="/assets/flags/<?=e($row['flag'])?>.svg" width="24" height="16" alt=""><?php endif?> <span lang="<?=e($code)?>"><?=e($row['label'])?></span> (<?=e($code)?>) <?=!interfaceLanguageReady($code)?'— טיוטה: נדרש תרגום מלא לפני הפעלה':''?></label><?php endforeach?>
<label>שפת ברירת מחדל <select name="default"><?php foreach($config['languages'] as $code=>$row):?><option value="<?=e($code)?>" <?=$code===$config['default']?'selected':''?>><?=e($row['label'])?></option><?php endforeach?></select></label>
<p>שפה מושבתת אינה זמינה גם בקישור ישיר. התרגומים נשמרים וניתן להפעיל אותה שוב.</p>
<button class="button">שמירת הגדרות</button></fieldset></form>
<form method="post" class="form-panel"><?=csrf()?><input type="hidden" name="version" value="<?=$version?>"><input type="hidden" name="action" value="feed-default"><fieldset><legend><?=e(languagePhrase('ברירת מחדל של שפת הפיד','Feed language default','Язык ленты по умолчанию'))?></legend>
<label><input type="checkbox" name="feed_country_default" value="1" <?=!empty($config['feed_country_default'])?'checked':''?>> <?=e(languagePhrase('עברית בישראל; אנגלית בשאר המדינות','Hebrew in Israel; English everywhere else','Иврит в Израиле; английский в остальных странах'))?></label>
<p><?=e(languagePhrase('חל רק אם לא נבחרה שפה לפיד. העדפות שמורות ושפת פיד מפורשת בקישור קודמות לכלל. כשהאפשרות כבויה, הפיד משתמש בשפת הממשק.','Applies only when no feed language was selected. Saved preferences and explicit feed links take priority. When off, the feed follows the interface language.','Действует, если язык ленты не выбран. Сохранённые настройки и язык ленты в ссылке имеют приоритет. При отключении используется язык интерфейса.'))?></p>
<button class="button"><?=e(languagePhrase('שמירה','Save','Сохранить'))?></button></fieldset></form>
<form method="post" class="form-panel"><?=csrf()?><input type="hidden" name="version" value="<?=$version?>"><input type="hidden" name="action" value="countries"><fieldset><legend>חסימת גישה לפי מדינה</legend>
<p>סמנו מדינות לחסימה. רשימה ריקה מאפשרת גישה מכל המדינות. אזור הניהול נשאר נגיש כדי לאפשר ביטול חסימה. זיהוי המדינה מבוסס על כתובת הרשת; כשאין זיהוי הגישה נשארת פתוחה.</p>
<label>חיפוש מדינה <input type="search" data-country-filter autocomplete="off"></label><div class="admin-country-list">
<?php $countryNames=regionCountryNames();asort($countryNames,SORT_NATURAL|SORT_FLAG_CASE);foreach($countryNames as $countryCode=>$countryName):?><label data-country-row><input type="checkbox" name="blocked_countries[]" value="<?=e($countryCode)?>" <?=in_array($countryCode,$config['blocked_countries'],true)?'checked':''?>> <?=e($countryName)?> (<?=e($countryCode)?>)</label><?php endforeach?></div><button class="button">שמירת חסימת מדינות</button></fieldset></form>
<form method="post" class="form-panel"><?=csrf()?><input type="hidden" name="version" value="<?=$version?>"><input type="hidden" name="action" value="add"><fieldset><legend>הוספת שפה</legend>
<p>שפה חדשה נוספת כטיוטה. ההוספה אינה מתרגמת מאמרים אוטומטית. הפעלה של שפה חדשה דורשת הכנת תרגומים ובדיקת הממשק והכלים.</p>
<label>קוד שפה <input type="text" name="code" placeholder="fr" pattern="[a-zA-Z]{2,3}(-[a-zA-Z]{2})?" required maxlength="6"></label><label>שם בשפת המקור <input type="text" name="label" placeholder="Français" required maxlength="40"></label><label>כיוון <select name="dir"><option value="ltr">משמאל לימין</option><option value="rtl">מימין לשמאל</option></select></label><button class="button">הוספת שפה כטיוטה</button></fieldset></form>
<section class="form-panel"><h2>הסרת שפה מהאתר</h2><p>הסרה שומרת את התרגומים ואת המאמרים. אפשר לשחזר את השפות המוכנות בהמשך.</p>
<?php foreach($config['languages'] as $code=>$row):if($code===$config['default'])continue;?><form method="post" class="language-row"><?=csrf()?><input type="hidden" name="version" value="<?=$version?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="code" value="<?=e($code)?>"><span><?=e($row['label'])?></span> <button class="button secondary">הסרת השפה מהאתר</button></form><?php endforeach?>
<form method="post"><?=csrf()?><input type="hidden" name="version" value="<?=$version?>"><input type="hidden" name="action" value="restore"><button class="button secondary">שחזור שפות מוכנות שהוסרו (ללא הפעלה)</button></form></section>
</div>
<?php $content=ob_get_clean();require ROOT.'/views/layout.php';
