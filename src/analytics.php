<?php
declare(strict_types=1);
function analyticsDomain(): string {
 $host=strtolower((string)parse_url($_SERVER['HTTP_REFERER']??'',PHP_URL_HOST));
 return preg_match('/^[a-z0-9.-]{1,253}$/D',$host)&&$host!==strtolower((string)parse_url(APP_URL,PHP_URL_HOST))?$host:'';
}
function analyticsCountable(): bool {if(qaActive())return false;
 return countableOutbound($_SERVER,attribution())&&empty($_SESSION['user'])&&http_response_code()<400&&!preg_match('/bot|crawler|spider|headless|preview/i',$_SERVER['HTTP_USER_AGENT']??'');
}
function analyticsPage(string $page): void {
 if(!analyticsCountable())return;
 $a=attribution();$domain=analyticsDomain();$now=time();$v=$_SESSION['analytics_visit']??null;
 $fingerprint=hash('sha256',json_encode(array_intersect_key($a,array_flip(['ref','param','source','medium','ad_campaign','ad']))));
 if(!$v||($v['last']??0)<$now-1800||($v['context']??'')!==$fingerprint){
  $id=bin2hex(random_bytes(16));
  query('INSERT INTO visit_sessions(id,started_at,last_at,source,medium,ad_campaign,ad,ref,param,referrer_domain,language,landing) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$now,$now,$a['source'],$a['medium'],$a['ad_campaign'],$a['ad'],$a['ref'],$a['param'],$domain,siteLanguage(),$page]);
  $v=['id'=>$id,'context'=>$fingerprint];
 }
 $v['last']=$now;$_SESSION['analytics_visit']=$v;
 query('UPDATE visit_sessions SET last_at=?,views=views+1 WHERE id=?',[$now,$v['id']]);
 query("INSERT INTO visit_actions(visit_id,at,kind,page,language) VALUES(?,?,'view',?,?)",[$v['id'],$now,$page,siteLanguage()]);
 $token=bin2hex(random_bytes(16));$_SESSION['analytics_pages'][$token]=['visit'=>$v['id'],'page'=>$page,'language'=>siteLanguage(),'at'=>$now,'clicks'=>0,'engaged'=>false];
 $_SESSION['analytics_pages']=array_slice($_SESSION['analytics_pages'], -20,null,true);$GLOBALS['analytics_token']=$token;
}
function analyticsOutbound(string $provider,string $page,string $placement,string $productId=''): void {
 if(!analyticsCountable())return;$v=$_SESSION['analytics_visit']??null;if(!$v||($v['last']??0)<time()-1800)return;
 query('UPDATE visit_sessions SET outbound=outbound+1,last_at=? WHERE id=?',[time(),$v['id']]);
 query("INSERT INTO visit_actions(visit_id,at,kind,page,language,placement,provider,target) VALUES(?,?,'outbound',?,?,?,?,?)",[$v['id'],time(),$page,siteLanguage(),$placement,$provider,$productId]);
 $_SESSION['analytics_visit']['last']=time();
}
function analyticsReceive(): void {
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);return;}
 $token=is_string($_POST['page_token']??null)?$_POST['page_token']:'';$p=$_SESSION['analytics_pages'][$token]??null;
 if(!$p||$p['at']<time()-1800||!empty($_SESSION['user'])){http_response_code(204);return;}
 $kind=$_POST['kind']??'';$target='';$placement='';
 if($kind==='engaged'){
  if($p['engaged']||time()-$p['at']<10){http_response_code(204);return;}
  $_SESSION['analytics_pages'][$token]['engaged']=true;
  query('UPDATE visit_sessions SET engaged=1,last_at=? WHERE id=?',[time(),$p['visit']]);
   }elseif(in_array($kind,['card_view','detail_open'],true)){
    $code=is_string($_POST['product']??null)?$_POST['product']:'';
    if(!isset(activityMetadata()[$code])||$p['clicks']>=100){http_response_code(204);return;}
    $eventKey=$kind.':'.$code;
    if(!empty($_SESSION['analytics_pages'][$token]['activity_events'][$eventKey])){http_response_code(204);return;}
    $_SESSION['analytics_pages'][$token]['activity_events'][$eventKey]=true;
    $_SESSION['analytics_pages'][$token]['clicks']++;
    $target=$code;$placement='activity-card';
   }elseif(in_array($kind,['trip_item_added','trip_day_assigned','trip_returned','explore_viewed','filters_applied'],true)){
  if($p['clicks']>=100){http_response_code(204);return;}
  $target=is_string($_POST['target']??null)?$_POST['target']:'';
  if(!preg_match('/^[a-zA-Z0-9_-]{0,80}$/D',$target))$target='';
  $placement='planner';$_SESSION['analytics_pages'][$token]['clicks']++;
 }elseif($kind==='click'){
  if($p['clicks']>=100){http_response_code(204);return;}
  $target=is_string($_POST['target']??null)?$_POST['target']:'';
  if(!preg_match('~^(?:/[a-zA-Z0-9/_-]{0,200}|#[a-zA-Z0-9_-]{1,80})$~D',$target))$target='button';
  $placement=is_string($_POST['placement']??null)?$_POST['placement']:'';
  if(!preg_match('/^(header|footer|top|middle|bottom):[0-9]{1,4}$/D',$placement))$placement='unknown';
  $_SESSION['analytics_pages'][$token]['clicks']++;
  query('UPDATE visit_sessions SET last_at=? WHERE id=?',[time(),$p['visit']]);
 }else{http_response_code(422);return;}
 query('INSERT INTO visit_actions(visit_id,at,kind,page,language,target,placement) VALUES(?,?,?,?,?,?,?)',[$p['visit'],time(),$kind,$p['page'],$p['language'],$target,$placement]);
 if(($_SESSION['analytics_visit']['id']??'')===$p['visit'])$_SESSION['analytics_visit']['last']=time();
 http_response_code(204);
}
