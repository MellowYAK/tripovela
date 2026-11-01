<?php
declare(strict_types=1);
function journeyReport(array $raw): string {
 $u=socialRequire();socialLimit('field_report',10,86400);$id=socialString($raw['place']??'',100,1);if(!networkItemForLanguage($id,siteLanguage()))throw new DomainException('NOT_FOUND');
 $type=(string)($raw['type']??'');if(!in_array($type,['closed','price','hours','access'],true))throw new DomainException('INVALID_INPUT');$observed=(int)($raw['observed_at']??0);if($observed<1||$observed>time())throw new DomainException('INVALID_FACT_DATE');
 $evidence=socialString($raw['evidence']??'',4000,10);$rid=bin2hex(random_bytes(16));jq('INSERT INTO journey_reports(id,place,owner,type,observed,evidence,created) VALUES(?,?,?,?,?,?,?)',[$rid,journeyPlace($id),$u['id'],$type,$observed,$evidence,time()]);return $rid;
}
function journeyReview(array $raw): string {
 $u=socialRequire();socialLimit('visit_feedback',15,86400);$kind=(string)($raw['kind']??'');$id=socialString($raw['target']??'',100,1);$locale=siteLanguage();
 if($kind==='place'){if(!networkItemForLanguage($id,$locale))throw new DomainException('NOT_FOUND');$source=null;}
 elseif($kind==='trip'){$route=socialPublicTrip($id);if(!$route)throw new DomainException('NOT_FOUND');$source=$route['public_version'];}
 else throw new DomainException('INVALID_INPUT');
 $visited=(string)($raw['visited_on']??'');$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$visited);if(!$dt||$dt->format('Y-m-d')!==$visited||$visited>date('Y-m-d'))throw new DomainException('INVALID_DATE');
 $score=(int)($raw['rating']??0);if($score<1||$score>5)throw new DomainException('INVALID_INPUT');$body=socialString($raw['body']??'',3000,10);
 foreach(['actual_minutes','actual_cost_minor'] as $key)if(isset($raw[$key])&&(!is_int($raw[$key])||$raw[$key]<0||$raw[$key]>10000000))throw new DomainException('INVALID_INPUT');
 $d=journeyDb();$d->exec('BEGIN IMMEDIATE');try{
  $old=jq('SELECT * FROM journey_reviews WHERE owner=? AND kind=? AND target=?',[$u['id'],$kind,$id])->fetch();if($old&&$old['state']==='blocked')throw new DomainException('NOT_FOUND');$rev=$old?(int)$old['revision']:0;if($rev!==(int)($raw['expected_revision']??0))throw new DomainException('CONFLICT');$rid=$old['id']??bin2hex(random_bytes(16));
  if($old)jq("UPDATE journey_reviews SET revision=?,rating=?,visited=?,route_version=?,state='pending' WHERE id=?",[$rev+1,$score,$visited,$source,$rid]);else jq('INSERT INTO journey_reviews VALUES(?,?,?,?,?,?,?,?,?)',[$rid,$u['id'],$kind,$id,1,$score,$visited,$source,'pending']);
  jq('INSERT INTO journey_review_versions VALUES(?,?,?,?,?,?,?)',[$rid,$rev+1,$locale,$body,$raw['actual_minutes']??null,$raw['actual_cost_minor']??null,time()]);$d->exec('COMMIT');cmAutoApprove();journeyEvent('visit_feedback','',0,'SELF_REPORTED',$rid.':'.($rev+1));return $rid;
 }catch(Throwable $e){$d->exec('ROLLBACK');throw $e;}
}
function journeyReviews(string $kind,string $id): array {
 if(($kind==='place'&&!networkItemForLanguage($id,siteLanguage()))||($kind==='trip'&&!socialPublicTrip($id))||!in_array($kind,['place','trip'],true))return [];
 $rows=jq("SELECT r.owner,r.id,r.rating,r.visited,r.route_version,r.revision,v.body,v.actual_minutes,v.actual_cost_minor,v.created FROM journey_reviews r JOIN journey_review_versions v ON v.review=r.id AND v.revision=r.revision JOIN members m ON m.id=r.owner WHERE r.kind=? AND r.target=? AND r.state='approved' AND v.locale=? AND m.state='active' ORDER BY v.created DESC LIMIT 50",[$kind,$id,siteLanguage()])->fetchAll();
 $out=[];foreach($rows as $row){if(networkBlocked(socialUser()['id']??'',$row['owner']))continue;unset($row['owner']);$out[]=$row;}return $out;
}
function journeyModerate(string $kind,string $id,string $decision,string $reason,int $expected=0,int $factVersion=0): void {
 requireAdmin();$table=['review'=>'journey_reviews','report'=>'journey_reports'][$kind]??null;
 if(!$table||!in_array($decision,['approved','rejected','blocked'],true)||mb_strlen($reason)<10)throw new DomainException('INVALID_INPUT');
 $d=journeyDb();$d->exec('BEGIN IMMEDIATE');try{
  $row=jq("SELECT * FROM $table WHERE id=? AND state='pending'",[$id])->fetch();if(!$row||($kind==='review'&&(int)$row['revision']!==$expected))throw new DomainException('CONFLICT');
  if($kind==='report'&&$decision==='approved'){
   $type=['closed'=>'operating','hours'=>'hours','price'=>'price','access'=>'accessibility'][$row['type']];$fact=journeyFact($row['place'],$type);
   if(!$fact||$fact['version']!==$factVersion||!JourneyEngine::current($fact,time(),date('Y-m-d'),$type)||$fact['checked_at']<$row['created'])throw new DomainException('VERIFIED_FACT_REQUIRED');
   $reason.=' [fact:'.$factVersion.']';
  }
  jq("UPDATE $table SET state=? WHERE id=?",[$decision,$id]);if($kind==='report')jq('UPDATE journey_reports SET reason=? WHERE id=?',[$reason,$id]);
  jq('INSERT INTO journey_decisions(kind,target,actor,reason,decision,created) VALUES(?,?,?,?,?,?)',[$kind,$id,(int)$_SESSION['user'],$reason,$decision,time()]);$d->exec('COMMIT');
 }catch(Throwable $e){$d->exec('ROLLBACK');throw $e;}
}
function journeyReviewAppeal(string $id,int $revision,string $reason): void {
 $u=socialRequire();socialLimit('review_appeal',5,86400);$reason=socialString($reason,2000,10);$d=journeyDb();$d->exec('BEGIN IMMEDIATE');
 try{$review=jq("SELECT * FROM journey_reviews WHERE id=? AND owner=? AND revision=? AND state IN ('rejected','blocked')",[$id,$u['id'],$revision])->fetch();if(!$review)throw new DomainException('CONFLICT');
  jq("UPDATE journey_reviews SET state='pending' WHERE id=?",[$id]);jq('INSERT INTO journey_decisions(kind,target,actor,reason,decision,created) VALUES(?,?,?,?,?,?)',['review_appeal',$id,0,$reason,'owner-request:'.$revision,time()]);$d->exec('COMMIT');
 }catch(Throwable $e){$d->exec('ROLLBACK');throw $e;}
}
function journeyFeedbackPanel(string $kind,string $id): void {
 echo '<section class="form-panel"><h2>'.e(jt('feedback')).'</h2><p>'.e(jt('self_report')).'</p>';
 foreach(journeyReviews($kind,$id) as $review)echo '<article><p>'.(int)$review['rating'].' / 5 · '.e($review['visited']).' · '.(int)$review['revision'].'</p><p>'.nl2br(e($review['body'])).'</p></article>';
 echo '<a class="button" href="'.e(languageUrl('/day-feedback/?kind='.$kind.'&target='.$id,siteLanguage())).'">'.e(jt('submit_review')).'</a></section>';
}
function journeyEraseMember(string $id): void {
 jq('DELETE FROM journey_review_versions WHERE review IN(SELECT id FROM journey_reviews WHERE owner=?)',[$id]);jq('DELETE FROM journey_reviews WHERE owner=?',[$id]);
 jq("UPDATE journey_reports SET evidence='',owner='deleted' WHERE owner=?",[$id]);jq('DELETE FROM journey_trip_history WHERE owner=?',[$id]);
 foreach(['journey_proposals','journey_versions','journey_impacts'] as $table)jq("DELETE FROM $table WHERE trip IN(SELECT id FROM journey_trips WHERE principal=?)",['member:'.$id]);jq('DELETE FROM journey_trips WHERE principal=?',['member:'.$id]);
}
