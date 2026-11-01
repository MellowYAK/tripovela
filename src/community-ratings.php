<?php
declare(strict_types=1);
// Supplier scores take precedence; preserve existing community votes privately.
function communityRatingAllowed(array $item): bool {
 if(empty($item['product']))return true;
 $reviews=providerCurrentFacts((string)$item['product'])['reviews']??[];
 return !((float)($reviews['combinedAverageRating']??0)>0&&(int)($reviews['totalReviews']??0)>0);
}
function communityRatingDb(): PDO {
 static $db;if($db)return $db;
 $db=new PDO('sqlite:'.STORAGE_PATH.'/community-ratings.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA busy_timeout=5000');
 $db->exec('CREATE TABLE IF NOT EXISTS ratings (item_id TEXT NOT NULL, voter_hash TEXT NOT NULL, score INTEGER NOT NULL CHECK(score BETWEEN 1 AND 5), updated_at INTEGER NOT NULL, PRIMARY KEY(item_id,voter_hash))');
 return $db;
}
function communityVoter(bool $create=false): ?string {
 $id=$_COOKIE['tripovela_rater']??'';
 if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)){
  if(!$create)return null;$id=bin2hex(random_bytes(32));
  setcookie('tripovela_rater',$id,['expires'=>time()+31536000,'path'=>'/','secure'=>APP_ENV!=='local','httponly'=>true,'samesite'=>'Lax']);$_COOKIE['tripovela_rater']=$id;
 }
 return hash('sha256',$id);
}
function communityRatingSummary(string $id): array {
 $q=communityRatingDb()->prepare('SELECT COUNT(*) AS count,AVG(score) AS average FROM ratings WHERE item_id=?');$q->execute([$id]);return $q->fetch();
}
function communityRatingSave(string $id,string $voter,int $score): void {
 if($score<1||$score>5)throw new InvalidArgumentException('Invalid score');
 $q=communityRatingDb()->prepare('INSERT INTO ratings(item_id,voter_hash,score,updated_at) VALUES(?,?,?,?) ON CONFLICT(item_id,voter_hash) DO UPDATE SET score=excluded.score,updated_at=excluded.updated_at');$q->execute([$id,$voter,$score,time()]);
}
function communityRatingHandle(): void {
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit;}
 // index.php has already verified the session CSRF token.
 $id=$_POST['item']??null;$score=filter_var($_POST['score']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>5]]);
 if(!is_string($id)||$score===false){http_response_code(422);exit('Invalid rating');}
 $item=null;foreach(tripCatalog() as $x)if($x['id']===$id){$item=$x;break;}
 $item??=scCatalogue()[$id]??null;
 if(!$item){http_response_code(404);exit('Unknown activity');}
 if(!communityRatingAllowed($item)){http_response_code(409);exit('Provider ratings are available for this activity');}
 if(!throttle('rating:'.($_SERVER['REMOTE_ADDR']??'unknown'),30,3600)){http_response_code(429);header('Retry-After: 3600');exit('Please try again later');}
 communityRatingSave($id,communityVoter(true),$score);$_SESSION['rating_saved']=$id;
 // Only a published catalogue URL is used, never a submitted return URL.
 redirect($item['url'].'#community-ratings');
}
function communityRatingPanel(array $item): void {
 if(!str_starts_with($item['id'],'community-')){cpCommunityPanel($item['id'],[]);$reviewed=crPlaceFields($item['id'],siteLanguage(),[]);if($reviewed){echo '<section class="social-card"><h3>'.e(crT('approved')).'</h3>';foreach($reviewed as $k=>$v)if(is_scalar($v))echo '<p>'.e(cpT($k)).': '.e((string)$v).'</p>';echo '</section>';}}
 if(!communityRatingAllowed($item))return;
 if(($item['city']??'')==='Tbilisi'&&journeyEnabled()){journeyFeedbackPanel('place',$item['id']);return;}
 $r=communityRatingSummary($item['id']);$voter=communityVoter();$mine=null;
 if($voter){$q=communityRatingDb()->prepare('SELECT score FROM ratings WHERE item_id=? AND voter_hash=?');$q->execute([$item['id'],$voter]);$mine=$q->fetchColumn();}
 $saved=($_SESSION['rating_saved']??null)===$item['id'];if($saved)unset($_SESSION['rating_saved']);
 $label=fn($he,$en,$ru)=>e(languagePhrase($he,$en,$ru));
 ?>
 <section class="market-section community-ratings" id="community-ratings" aria-labelledby="community-title"><h2 id="community-title"><?=$label('דירוגי גולשים ב־Tripovela','Tripovela community ratings','Оценки пользователей Tripovela')?></h2>
 <?php if($r['count']):?><p class="community-score"><span aria-hidden="true">★</span> <b dir="ltr"><?=number_format((float)$r['average'],1)?> / 5</b> · <?=(int)$r['count']?> <?=$label('דירוגים באתר שלנו','ratings on our site','оценок на нашем сайте')?></p><?php else:?><p><?=$label('עדיין אין דירוגים. ביקרתם כאן? נשמח לדירוג הראשון שלכם.','No ratings yet. Have you visited? Be the first to rate it.','Оценок пока нет. Уже побывали здесь? Поделитесь первой оценкой.')?></p><?php endif?>
 <?php if($saved):?><p role="status" class="community-saved"><?=$label('תודה! הדירוג שלכם נשמר.','Thank you! Your rating has been saved.','Спасибо! Ваша оценка сохранена.')?></p><?php endif?>
 <form action="/ratings/?lang=<?=e(siteLanguage())?>" method="post"><?=csrf()?><input type="hidden" name="item" value="<?=e($item['id'])?>"><fieldset><legend><?=$label('איך הייתה החוויה שלכם?','How was your experience?','Как вам понравилось?')?></legend><div class="community-stars" dir="ltr"><?php for($star=1;$star<=5;$star++):?><label><input type="radio" name="score" value="<?=$star?>" required <?=$mine==$star?'checked':''?>><span><b aria-hidden="true">★</b><span class="sr-only"><?=$star?> / 5</span><small aria-hidden="true"><?=$star?></small></span></label><?php endfor?></div></fieldset><button class="button" type="submit"><?=$mine?$label('עדכון הדירוג שלי','Update my rating','Обновить оценку'):$label('שליחת דירוג','Submit rating','Отправить оценку')?></button></form>
 <p class="market-muted"><?=$label('דירוגים שנשלחו באתר Tripovela; אינם דירוגי Viator או ביקורים מאומתים. נשמר מזהה אקראי בדפדפן כדי לאפשר דירוג אחד ועדכונו באותו דפדפן.','Submitted on Tripovela; these are not Viator ratings or verified visits. A random browser identifier allows one rating and updates from this browser.','Оценки оставлены на Tripovela; это не оценки Viator и не подтверждённые посещения. Случайный идентификатор браузера позволяет оставить одну оценку и обновлять её.')?></p>
 </section>
 <?php
}
