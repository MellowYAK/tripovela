<?php
declare(strict_types=1);
const TERMS_VERSION='2026-10-01.2';
// Owner request 2026-10-01: hide the consent gate while the site is being revised.
// Policy pages and acceptance/CSRF handling remain intact. Restore only on request.
const TERMS_GATE_ENABLED=false;
function legalCopy(): array {static $all; $all??=json_decode(file_get_contents(ROOT.'/content/legal.json'),true,512,JSON_THROW_ON_ERROR);return $all[siteLanguage()]??uiTranslateTree($all['en']);}
function termsAccepted(): bool {return ($_COOKIE['tripovela_terms']??'')===TERMS_VERSION;}
function legalPolicyPath(string $path): bool {return in_array($path,['/terms/','/privacy/','/data-storage/','/accessibility/','/contact/'],true);}
function legalReturn($url): string {
 if(!is_string($url)||strlen($url)>3000)return '/';
 $decoded=rawurldecode($url);
 if(!str_starts_with($decoded,'/')||str_starts_with($decoded,'//')||preg_match('/[\\\\\x00-\x20]/',$decoded))return '/';
 return $url;
}

function readingOptions(): void {$c=legalCopy(); ?>
<details class="reading-options" translate="no"><summary><?=e($c['a11y'])?></summary><div><?php foreach(['large','contrast','links','motion'] as $id):?><label><input type="checkbox" data-reading="<?=$id?>"> <?=e($c[$id])?></label><?php endforeach?><button type="button" data-reading-reset><?=e($c['reset'])?></button></div></details>
<?php }
function legalSections(): void {foreach(legalCopy()['sections'] as [$heading,$text])echo '<h2>'.e($heading).'</h2><p>'.e($text).'</p>';}
function legalPage(string $path): void {if($path==='/privacy/'&&!empty(measurementConfig()['enabled'])){$ml=measurementLabels();echo '<section class="notice"><h2>'.e($ml['title']).'</h2><p>'.e($ml['body']).'</p></section>';}$c=legalCopy();$key=['/terms/'=>'title','/privacy/'=>'privacy','/data-storage/'=>'retention','/accessibility/'=>'accessibility'][$path];$GLOBALS['title']=$c[$key];echo '<section class="page-heading" translate="no"><h1>'.e($c[$key]).'</h1></section><div class="prose" translate="no">';if($path==='/terms/')legalSections();else foreach($c[(['privacy'=>'privacy','retention'=>'retention','accessibility'=>'access'][$key]).'_body'] as $text)echo '<p>'.e($text).'</p>';if(in_array($path,['/privacy/','/data-storage/'],true))echo '<p>'.e(languagePhrase('דירוגי גולשים: בעת שליחת דירוג נשמרים בשרת ציון, מזהה הפעילות, מועד העדכון וגיבוב של מזהה דפדפן אקראי. המזהה נשמר בעוגייה למשך שנה לצורך עדכון דירוג מאותו דפדפן; השם ופרטי ההזמנה אינם נדרשים. הציונים מוצגים לציבור במצטבר בלבד.','Community ratings: submitting a rating stores the score, activity ID, update time and a hash of a random browser identifier on our server. The identifier is kept in a cookie for one year so ratings can be updated from that browser; no name or booking details are required. Only aggregate scores are public.','Оценки пользователей: при отправке оценки сервер сохраняет балл, идентификатор места, время обновления и хеш случайного идентификатора браузера. Идентификатор хранится в cookie один год для изменения оценки из того же браузера; имя и сведения о бронировании не требуются. Публично показываются только совокупные оценки.')).'</p>';echo '<p><a href="'.e(languageUrl('/',siteLanguage())).'">'.e($c['back']).'</a></p></div>';}
function termsHandle(string $path): void {
 if($path==='/terms/accept/'&&$_SERVER['REQUEST_METHOD']==='POST'){
  if(($_POST['decision']??'')==='accept'){
   setcookie('tripovela_terms',TERMS_VERSION,['expires'=>time()+31536000,'path'=>'/','secure'=>APP_ENV!=='local','httponly'=>true,'samesite'=>'Lax']);
   $_SESSION['terms_acceptance']=['version'=>TERMS_VERSION,'at'=>time(),'language'=>siteLanguage()];
   redirect(legalReturn($_POST['return']??'/'));
  }
  $_SESSION['terms_declined']=true;redirect(legalReturn($_POST['return']??'/'));
 }
 if(!TERMS_GATE_ENABLED||termsAccepted()||legalPolicyPath($path)||$_SERVER['REQUEST_METHOD']==='HEAD')return;
 $c=legalCopy();$return=legalReturn($_SERVER['REQUEST_URI']??'/');
 $GLOBALS['terms_declined']=!empty($_SESSION['terms_declined']);unset($_SESSION['terms_declined']);
 if(str_starts_with($path,'/go/')){require ROOT.'/views/terms-gate.php';exit;}
 $GLOBALS['terms_pending']=true;
 $background='__TRIPOVELA_BACKGROUND__';$pageStyles='__TRIPOVELA_STYLES__';
 ob_start();require ROOT.'/views/terms-gate.php';$gate=ob_get_clean();
 ob_start(static function(string $html)use($gate):string {
  preg_match('~<body[^>]*>(.*)</body>~si',$html,$body);
  $background=preg_replace('~<script\b[^>]*>.*?</script>~si','',$body[1]??'');
  preg_match_all('~<link\b[^>]*rel=["\']stylesheet["\'][^>]*>~i',$html,$styles);
  return str_replace(['__TRIPOVELA_BACKGROUND__','__TRIPOVELA_STYLES__'],[$background,implode('',$styles[0])],$gate);
 });
}
