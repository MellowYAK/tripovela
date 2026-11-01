<?php
if($path==='/api/community-places/'){
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
 try{
  if($_SERVER['REQUEST_METHOD']==='GET'){
   if(isset($_GET['q'])&&($_GET['type']??'')==='trip'){
    $q=trim(mb_substr((string)$_GET['q'],0,100));$result=['routes'=>[]];
    if(mb_strlen($q)>=2)foreach(networkFeed(['q'=>$q,'type'=>'trip','language'=>'all'])['rows'] as $r){if(!($attachable=revAttachableRoute($r['target'])))continue;$p=json_decode($attachable['public_plan'],true);$result['routes'][]=['id'=>$r['target'],'title'=>$p['public_title']??$p['title']??'','city'=>$p['city']??'','country'=>$p['country']??'','image'=>!empty($p['photos'][0])&&crPhotoAllowed($p['photos'][0])?'/community/media/'.$p['photos'][0].'/':''];if(count($result['routes'])>=12)break;}
   }
   elseif(isset($_GET['q']))$result=['places'=>cpSearch((string)$_GET['q'])];
   else{$id=cpCanonical((string)($_GET['id']??''));$p=scCatalogue()[$id]??(socialUser()?(crPendingPlaces(socialUser())[$id]??null):null);if(!$p)throw new DomainException('not_found');$rows=[];foreach(cpContributions($id) as $r){$author=networkProfile($r['owner']);$rows[]=['id'=>$r['id'],'author'=>$author['name']?:$author['handle'],'handle'=>$author['handle'],'language'=>$r['language'],'document'=>crDocument(json_decode($r['document'],true),$r['owner']===(socialUser()['id']??'')),'pending_photos'=>$r['owner']===(socialUser()['id']??'')?array_values(array_filter(json_decode($r['document'],true)['photos']??[],fn($im)=>!crPhotoAllowed($im))):[],'detail_votes'=>(int)$r['detail_votes'],'photo_votes'=>(int)$r['photo_votes'],'current_votes'=>(int)$r['current_votes'],'created'=>(int)$r['created'],'mine'=>$r['owner']===(socialUser()['id']??'')];}$result=['place'=>cpEnrich($p)+['location'=>$p['location']??rxLocation($p)],'contributions'=>$rows,'personal_label'=>crT('visit')];}
  }else{
   $u=socialRequire();$action=$_POST['place_action']??'';
   if($action==='save'){$id=cpSave($u,$_POST);$p=scCatalogue()[$id]??null;if(!$p){$row=socialQuery("SELECT document FROM member_places WHERE id=? AND owner=? AND state='pending'",[substr($id,10),$u['id']])->fetchColumn();if(!$row)throw new DomainException('not_found');$doc=json_decode($row,true);$p=['id'=>$id,'title'=>$doc['title'],'city'=>$doc['city'],'city_label'=>$doc['city'],'image'=>'','cost'=>'unknown','pending'=>true,'staged'=>!empty($doc['staged']),'location'=>['coords'=>isset($doc['latitude'],$doc['longitude'])?[$doc['latitude'],$doc['longitude']]:null,'address'=>$doc['address']??''],'url'=>''];$result=['place'=>$p,'pending'=>true,'message'=>crT('new_pending')];}else $result=['place'=>cpEnrich($p)+['location'=>rxLocation($p),'city_label'=>$p['city']]];}
   elseif($action==='vote'){cpVote($u,(string)($_POST['contribution']??''),(string)($_POST['aspect']??''));$result=['saved'=>true];}
   elseif($action==='merge'){$source=cpCanonical((string)($_POST['place']??''));$target=cpCanonical((string)($_POST['merge_target']??''));$catalog=scCatalogue();if($source===$target||!isset($catalog[$source],$catalog[$target])||!str_starts_with($source,'community-')||!str_starts_with($target,'community-'))throw new DomainException('invalid');socialLimit('merge_suggestion',10,3600);socialQuery("INSERT INTO member_reports(reporter,kind,target,reason,created) VALUES(?,'place',?,?,?)",[$u['id'],substr($source,10),'Duplicate place suggestion: '.$target,time()]);$result=['saved'=>true];}
   else throw new DomainException('invalid');
  }echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG);
 }catch(DomainException $e){http_response_code($e->getMessage()==='login_required'?401:422);echo json_encode(['ok'=>false,'message'=>socialT($e->getMessage())],JSON_UNESCAPED_UNICODE);}
 catch(Throwable $e){error_log('Community places: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>rxT('save_error')]);}exit;
}
