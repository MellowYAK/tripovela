<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
require ROOT.'/src/config.php';
require ROOT.'/vendor/autoload.php';
require ROOT.'/src/partners.php';
date_default_timezone_set('UTC');
function db(): PDO { static $db; if (!$db) { $db=new PDO('sqlite:'.(qaActive()?STORAGE_PATH.'/tripovela.sqlite':(getenv('TRIPOVELA_DB') ?: STORAGE_PATH.'/tripovela.sqlite'))); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC); $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;'); } return $db; }
function query(string $sql,array $args=[]): PDOStatement { $q=db()->prepare($sql); $q->execute($args); return $q; }
function e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function setting(string $key,string $default=''): string { $v=query('SELECT value FROM settings WHERE key=?',[$key])->fetchColumn(); return $v===false?$default:(string)$v; }
function md(string $body): string { $p=new Parsedown(); $p->setSafeMode(true); return str_replace(['<table>','</table>'],['<div class="table-scroll" role="region" aria-label="טבלת השוואה" tabindex="0"><table>','</table></div>'],viatorPriceTokens($p->text($body),str_contains($body,'{{VIATOR_PRICE:')&&function_exists('siteLanguage')?siteLanguage():'en')); }
function categories(): array { return ['destinations'=>'יעדים ומסלולים','stays'=>'מלונות וחופשות','ski'=>'חופשות סקי','transport'=>'מתניידים בחו״ל','travel-essentials'=>'אטרקציות וציוד']; }
// Public outbound navigation must go through the registered /go/ affiliate routes.
function publicLinks(string $html): string {
 $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
 $doc->loadHTML('<?xml encoding="UTF-8"><div id="public-content">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
 libxml_clear_errors();libxml_use_internal_errors($old);
 foreach(iterator_to_array($doc->getElementsByTagName('a')) as $index=>$a){
  viatorAutoAnchor($a,parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/',$index);if(!$a->parentNode)continue;
  $href=trim($a->getAttribute('href'));$url=parse_url($href);
  if(filter_var(setting('contact_email'),FILTER_VALIDATE_EMAIL)&&$href==='mailto:'.setting('contact_email'))continue;
  if(!affiliateNavigationEnabled() && !preg_match('~^/go/viator/(?:(?:guide/|catalog/|article/[0-9]{2}/)[A-Za-z0-9]{1,40}|auto/[a-f0-9]{64})/$~D',$url['path']??'') && $url!==false && preg_match('~^/go(?:/|$)~i',rawurldecode($url['path']??''))){$a->parentNode->removeChild($a);continue;}
  $internal=$url!==false&&!isset($url['scheme'])&&!isset($url['host'])&&strpos($href,'//')!==0&&strpos($href,'\\')===false;
  if(!$internal&&$url!==false&&in_array(strtolower($url['scheme']??''),['http','https'],true)&&strtolower($url['host']??'')===strtolower(parse_url(APP_URL,PHP_URL_HOST)??'')&&!isset($url['user'])&&!isset($url['pass']))$internal=true;
  if(!$internal){while($a->firstChild)$a->parentNode->insertBefore($a->firstChild,$a);$a->parentNode->removeChild($a);}
 }
 $root=$doc->getElementById('public-content');$out='';foreach($root->childNodes as $node)$out.=$doc->saveHTML($node);return $out;
}
function localRequest(): bool { return in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true); }
function preview(): bool { return !empty($_SESSION['preview']) && (APP_ENV==='local'&&localRequest() || !empty($_SESSION['user'])&&query("SELECT count(*) FROM users WHERE id=? AND role='admin'",[$_SESSION['user']])->fetchColumn()>0); }
function visibility(): string { return preview()?"status IN ('draft','in_review','published')":"status='published'"; }
function csrf(): string { return (qaActive()?'<input type="hidden" name="_qa_workspace" value="'.e(qaRequestToken()).'">':'').'<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function redirect(string $url): void { header('Location: '.$url,true,303); exit; }
function audit(string $action,string $entity): void { query('INSERT INTO audit(actor,action,entity,created_at) VALUES(?,?,?,?)',[(string)($_SESSION['user']??'cli'),$action,$entity,gmdate('c')]); }
function requireAdmin(): void { if (empty($_SESSION['user'])) redirect('/KNISA/'); $user=query('SELECT role FROM users WHERE id=?',[$_SESSION['user']])->fetch();if(!$user){unset($_SESSION['user']);redirect('/KNISA/');}if($user['role']!=='admin'){http_response_code(403);exit('אין הרשאה לפעולה זו.');} }
function throttle(string $key,int $limit,int $seconds): bool { $key=hash('sha256',$key); $r=query('SELECT * FROM attempts WHERE key=?',[$key])->fetch(); if (!$r || (int)$r['expires']<time()) { query('INSERT OR REPLACE INTO attempts(key,count,expires) VALUES(?,1,?)',[$key,time()+$seconds]); return true; } query('UPDATE attempts SET count=count+1 WHERE key=?',[$key]); return (int)$r['count']<$limit; }
function money($raw): ?int { if ($raw===null || trim((string)$raw)==='') return null; $s=trim((string)$raw); if (!preg_match('/^\d{1,8}(?:[.,]\d{1,2})?$/D',$s)) throw new InvalidArgumentException('יש להזין סכום חיובי ועד שתי ספרות אחרי הנקודה.'); $p=explode('.',str_replace(',','.',$s)); return ((int)$p[0])*100+(int)str_pad($p[1]??'',2,'0'); }
function amount(int $n): string { return number_format($n/100,2); }
function budget(array $rows): array { $r=['known'=>0,'estimate'=>0,'missing'=>[],'filled'=>0]; if(count($rows)>15) throw new InvalidArgumentException('עד 15 סעיפים.'); foreach($rows as $row) { $status=$row['status']??'missing'; if (!in_array($status,['known','estimate','missing','included','not_needed'],true)) throw new InvalidArgumentException('מצב סעיף לא תקין.'); if(in_array($status,['included','not_needed'],true))continue; $value=money($row['amount']??null); if($status==='missing'||$value===null){$r['missing'][]=mb_substr((string)($row['label']??'סעיף'),0,60);continue;} $qty=filter_var($row['qty']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]); if($qty===false)throw new InvalidArgumentException('כמות חייבת להיות מספר שלם בין 1 ל־1,000.'); $r[$status]+=$value*$qty; $r['filled']++; } $r['total']=$r['known']+$r['estimate']; return $r; }
function imageFor(array $a): string {
 if(!empty($a['_experience']))return 'experiences/'.$a['slug'].'/01-cover.jpg';
 if(preg_match('/^\d{2}$/D',(string)$a['id'])&&is_file(ROOT.'/public/assets/guides/'.$a['id'].'-hero.webp'))return 'guides/'.$a['id'].'-hero.webp';
 if(in_array($a['id'],['04','31','32','33','34']))return 'prague.jpg';
 if($a['category']==='ski')return 'alps.jpg';
 if($a['category']==='transport'||$a['id']==='06')return 'guide-transport.svg';
 if(in_array($a['id'],['13','25','30']))return 'guide-budget.svg';
 if(in_array($a['id'],['10','11','26']))return 'guide-family.svg';
 if(in_array($a['id'],['27','28']))return 'guide-connectivity.svg';
 if($a['id']==='29')return 'guide-luggage.svg';
 if(in_array($a['id'],['03','05','08','12','15','24']))return 'guide-city.svg';
 return 'coast.jpg';
}
function card(array $a): void { ?><a class="article-card" href="/guides/<?=e($a['slug'])?>/"><div class="card-image"><img src="/assets/<?=e(cardImageFor($a))?>" alt="" width="640" height="427" loading="lazy" decoding="async"><span class="tag"><?=e(categories()[$a['category']]??'מדריך')?></span></div><div class="card-content"><span class="eyebrow"><?=e(languagePhrase('מדריך לבחירה טובה יותר','A guide to better choices','Руководство для осознанного выбора'))?> · <?=max(3,(int)ceil(mb_strlen($a['body'])/1000))?> <?=e(languagePhrase('דקות קריאה','min read','мин. чтения'))?></span><h3><?=e($a['title'])?></h3><p><?=e(mb_strimwidth($a['summary'],0,125,'…'))?></p><span class="read-link">למדריך המלא <span aria-hidden="true">↙</span></span></div></a><?php }



// Search published content by relevance; topic membership is editorial, not filler.
function guideTopics(): array { return [
 'ילדים'=>['01','10','11','26','28','31'],
 'תקציב'=>['08','13','18','20','25','30','34'],
 'סקי'=>['17','18','19','35','36'],
 'מלון'=>['09','10','11','12','14','16','32'],
 'רכב'=>['06','20','22','23','37'],
 'פראג'=>['04','31','32','33','34']
]; }
function searchGuides(array $articles,string $q): array {
 $q=trim($q);if($q==='')return array_values($articles);
 $aliases=['children'=>'ילדים','kids'=>'ילדים','family'=>'ילדים','дети'=>'ילדים','детей'=>'ילדים','семья'=>'ילדים','budget'=>'תקציב','бюджет'=>'תקציב','ski'=>'סקי','skiing'=>'סקי','горные лыжи'=>'סקי','лыжи'=>'סקי','hotel'=>'מלון','hotels'=>'מלון','отель'=>'מלון','отели'=>'מלון','car'=>'רכב','cars'=>'רכב','автомобиль'=>'רכב','машина'=>'רכב','prague'=>'פראג','прага'=>'פראג'];
 $q=$aliases[mb_strtolower($q)]??$q;
 $tokens=preg_split('/\s+/u',mb_strtolower($q),-1,PREG_SPLIT_NO_EMPTY);$rank=[];
 foreach($articles as $a){
  $curated=in_array($a['id'],guideTopics()[$q]??[],true);
  if(isset(guideTopics()[$q])&&!$curated)continue;
  $text=mb_strtolower($a['title'].' '.$a['summary'].' '.strip_tags(md($a['body'])).' '.(categories()[$a['category']]??''));
  if(siteLanguage()!=='he')$text.=' '.mb_strtolower(localizedSearchText($a));
  $match=true;foreach($tokens as $token)if(mb_strpos($text,$token)===false){$match=false;break;}
  if(!$match&&!$curated)continue;
  $rank[]=['article'=>$a,'score'=>($curated?100:0)+(mb_stripos($a['title'],$q)!==false?30:0)+(mb_stripos($a['summary'],$q)!==false?10:0)];
 }
 usort($rank,fn($a,$b)=>($b['score']<=>$a['score'])?:strcmp($a['article']['id'],$b['article']['id']));return array_column($rank,'article');
}
function cardImageFor(array $a): string {$image=imageFor($a);return strpos($image,'guides/')===0?str_replace('-hero.webp','-card.webp',$image):$image;}
function guideImageCaption(array $a): string {if(strpos(imageFor($a),'guides/')===0)return '';if(strpos(imageFor($a),'.svg')!==false)return 'איור מערכתי · Tripovela · להמחשת נושא המדריך';return ['coast.jpg'=>'פוזיטנו, איטליה · צילום: Alexis Lours · תמונת אווירה','prague.jpg'=>'פראג · צילום: Slyronit · תמונת אווירה','alps.jpg'=>'האלפים · צילום: Henk Monster · תמונת אווירה'][imageFor($a)];}

require_once ROOT.'/src/editorial-expansion.php';

require_once ROOT.'/src/i18n.php';

require ROOT.'/src/analytics.php';

require ROOT.'/src/viator.php';
require ROOT.'/src/viator-affiliate-auto.php';
require ROOT.'/src/experiences.php';
require ROOT.'/src/viator-editorial.php';
require ROOT.'/src/discovery.php';

require_once ROOT.'/src/legal.php';

require_once ROOT.'/src/catalog.php';

require_once ROOT.'/src/editorial-catalog.php';

require_once ROOT.'/src/provider-content.php';

require_once ROOT.'/src/place-practical.php';

require_once ROOT.'/src/provider-refresh.php';
require_once ROOT.'/src/content-indexing.php';

require_once ROOT.'/src/community-ratings.php';

require_once ROOT.'/src/provider-catalog.php';

require_once ROOT.'/src/social.php';
require_once ROOT.'/src/social-experience.php';
require_once ROOT.'/src/place-offers.php';
require_once ROOT.'/src/journey-store.php';
require_once ROOT.'/src/journey-labels.php';
require_once ROOT.'/src/journey-feedback.php';
require_once ROOT.'/src/journey-walking.php';

require_once ROOT.'/src/content-languages.php';
require_once ROOT.'/src/network-metadata.php';

require_once ROOT.'/src/community-auto-review.php';

require_once ROOT.'/src/guest-trips.php';
require_once ROOT.'/src/trip-document.php';
require_once ROOT.'/src/trip-sharing.php';
