<?php
// Session and CSRF validation run in public/index.php before this controller.
$journeyError='';$calculation=null;$proposal=null;$saved=null;$sourceRoute=null;
try{
 if(isset($_GET['route'])){$sourceRoute=socialPublicTrip((string)$_GET['route'],isset($_GET['version'])?(int)$_GET['version']:null);if(!$sourceRoute)throw new DomainException('CONTENT_UNAVAILABLE');}
 if($_SERVER['REQUEST_METHOD']==='POST'){
  socialLimit('journey',60,3600);$action=(string)($_POST['journey_action']??'');
  if(in_array($action,['calculate','save','replan','apply'],true)&&!journeyEnabled($action==='replan'||$action==='apply'?'replanning':'planning'))throw new DomainException('FEATURE_DISABLED');
  if($action==='calculate'||$action==='save'){
   $raw=isset($_POST['payload'])?json_decode((string)$_POST['payload'],true):[
    'content_locale'=>siteLanguage(),'stops'=>$_POST['stops']??[],'start_id'=>$_POST['start_id']??'','end_id'=>$_POST['end_id']??'',
    'service_date'=>$_POST['service_date']??'','start_at'=>$_POST['start_at']??'','finish_by'=>$_POST['finish_by']??'',
    'timezone'=>'Asia/Tbilisi','party'=>['adults'=>(int)($_POST['adults']??1),'children'=>(int)($_POST['children']??0)],'currency'=>'GEL',
    'budget_minor'=>isset($_POST['budget'])&&$_POST['budget']!==''?(int)round((float)$_POST['budget']*100):null,
    'max_walk_meters'=>isset($_POST['walk'])&&$_POST['walk']!==''?(int)$_POST['walk']:null,'required_step_free'=>!empty($_POST['step_free']),
    'private_notes'=>$_POST['private_notes']??'','tags'=>$_POST['tags']??'','optional'=>$_POST['optional']??[],'route_version_id'=>(int)($_POST['route_version_id']??0)];
   if(!is_array($raw))throw new DomainException('INVALID_INPUT');if(!isset($_POST['payload'])){foreach($_POST['anchor_start']??[] as $id=>$start){if($start==='')continue;$raw['anchors'][$id]=['start'=>$start,'end'=>$_POST['anchor_end'][$id]??'','checkin_by'=>$_POST['anchor_checkin'][$id]??''];}$raw['paid']=$_POST['paid']??[];}$input=journeyInput($raw);$calculation=journeyCalculate($input);
   if($action==='save')$saved=journeySave($calculation,(string)($_POST['title']??jt('title')));journeyEvent('adapt',$saved['id']??'',1,$calculation['result']['status'],hash('sha256',(string)($_POST['event_key']??json_encode($input).':'.intdiv(time(),60))));
  }elseif($action==='replan'){
   $progress=json_decode($_POST['progress']??'[]',true)??[];
   if(!empty($_POST['progress_count'])){$owned=journeyOwn((string)$_POST['trip']);$v=journeyVersion($owned['id'],(int)$owned['revision']);$count=(int)$_POST['progress_count'];if($count<1||$count>count($v['input']['stops']))throw new DomainException('INVALID_PROGRESS');$done=array_slice($v['input']['stops'],0,$count);$progress=['completed'=>$done,'current_place'=>end($done),'current_time'=>(string)($_POST['current_time']??'')];}
   $proposal=journeyProposal((string)$_POST['trip'],(int)$_POST['revision'],$progress);
  }
  elseif($action==='apply')$saved=['id'=>(string)$_POST['trip']]+journeyApply((string)$_POST['trip'],(string)$_POST['proposal'],(string)$_POST['selection'],(int)$_POST['revision'],(string)$_POST['idempotency_key']);
  elseif($action==='undo')$saved=['id'=>(string)$_POST['trip']]+journeyUndo((string)$_POST['trip'],(int)$_POST['revision'],(int)$_POST['restore']);
  elseif($action==='start'){$t=journeyOwn((string)$_POST['trip']);journeyEvent('start_self_report',$t['id'],(int)$t['revision'],'SELF_REPORTED',$t['id'].':'.$t['revision']);}
  else throw new DomainException('INVALID_ACTION');
 }
 if(isset($_GET['trip'])){$t=journeyOwn((string)$_GET['trip']);$saved=['id'=>$t['id'],'revision'=>(int)$t['revision']]+journeyVersion($t['id'],(int)$t['revision']);}
}catch(DomainException $ex){$journeyError=$ex->getMessage();http_response_code(['CONFLICT'=>409,'NOT_FOUND'=>404,'CONTENT_UNAVAILABLE'=>404,'FEATURE_DISABLED'=>403,'EXPIRED'=>410][$journeyError]??422);}catch(TypeError $ex){$journeyError='INVALID_INPUT';http_response_code(422);}
if($path==='/api/day-plan/'){
 header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$journeyError?:null,'calculation'=>$calculation,'saved'=>$saved,'proposal'=>$proposal],JSON_UNESCAPED_UNICODE);exit;
}
$title=jt('title');$description=jt('intro');ob_start();require ROOT.'/views/journey.php';$content=ob_get_clean();require ROOT.'/views/layout.php';exit;
