<?php
if($path==='/api/community-review/'){
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
 try{if($_SERVER['REQUEST_METHOD']==='GET'){
  $place=cpCanonical((string)($_GET['place']??''));$language=networkLanguage($_GET['content_lang']??networkContentLanguage());$doc=crPlaceBase($place,$language);$rows=[];
  foreach(socialQuery("SELECT c.*,m.handle FROM community_corrections c JOIN members m ON m.id=c.owner WHERE c.place=? AND c.state!='hidden' AND m.state='active' ORDER BY c.created DESC",[$place]) as $r){if(networkBlocked(socialUser()['id']??'',$r['owner'])||crHidden('correction',$r['id']))continue;$r['previous']=json_decode($r['previous'],true);$r['value']=json_decode($r['value'],true);$rows[]=$r;}
  $result=['document'=>crDocument($doc),'language'=>$language,'proposals'=>$rows,'signed_in'=>(bool)socialUser()];
 }else{$u=socialRequire();if(($_POST['action']??'')==='propose')$result=['id'=>crPropose($u,$_POST),'message'=>crT('saved')];elseif(($_POST['action']??'')==='report'){crReport($u,$_POST);$result=['message'=>crT('report_saved')];}else throw new DomainException('invalid');}echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG);
 }catch(DomainException $e){http_response_code($e->getMessage()==='login_required'?401:422);echo json_encode(['ok'=>false,'message'=>socialT($e->getMessage())],JSON_UNESCAPED_UNICODE);}exit;
}
if($path==='/community/review/') {require ROOT.'/views/community-review.php';exit;}
