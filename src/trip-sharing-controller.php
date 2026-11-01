<?php
if($path==='/api/trip-share/'){
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
 try{
  if($_SERVER['REQUEST_METHOD']==='GET'){
   $u=socialUser();if(isset($_GET['friends'])){if(!$u)throw new DomainException('auth');echo json_encode(['ok'=>true,'result'=>tsFriends($u)],JSON_HEX_TAG);exit;}if(isset($_GET['request'])&&tsCapability()){tsSchema();$r=socialQuery('SELECT * FROM trip_shares WHERE request_id=? AND cap_hash=?',[(string)$_GET['request'],hash('sha256',tsCapability())])->fetch();}elseif(isset($_GET['trip'])&&$u){tsSchema();$r=socialQuery('SELECT * FROM trip_shares WHERE trip=? AND owner=? AND revoked=0 ORDER BY created DESC LIMIT 1',[(string)$_GET['trip'],$u['id']])->fetch();}else $r=tsRow((string)($_GET['token']??''));$pastCreator=$r&&!$r['owner']&&tsCapability()&&hash_equals($r['cap_hash'],hash('sha256',tsCapability()));if(!$r||(!tsCanEdit($r,$u)&&!$pastCreator))throw new DomainException('not_found');$result=tsStatus($r,$u);
  }else{
   // This separate abuse limit intentionally does not use the paused community publication quota.
   if(!throttle('trip-share:'.hash('sha256',($_SERVER['REMOTE_ADDR']??'')),80,3600))throw new DomainException('rate_limit');
   $input=json_decode($_POST['payload']??'{}',true,32,JSON_THROW_ON_ERROR);if(!is_array($input)||strlen($_POST['payload']??'')>180000)throw new DomainException('invalid');$result=tsWrite($input,socialUser());
  }
  echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG);
 }catch(Throwable $e){http_response_code($e instanceof DomainException?400:500);$key=$e instanceof DomainException?$e->getMessage():'invalid';echo json_encode(['ok'=>false,'message'=>str_starts_with($key,'share_password_')||$key==='share_select_friends'?tsT($key):(in_array($key,['share_reuse','share_expired'],true)?tsT($key==='share_reuse'?'reuse_blocked':'expired'):socialT($key))]);}exit;
}
if(!preg_match('~^/community/shared-plan/([a-f0-9]{32})/(?:([a-z]+)\/)?$~D',$path,$m)){http_response_code(404);exit;}
$share=tsRow($m[1]);$unlockError='';if($share&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($m[2]??'')===''&&isset($_POST['share_password'])){try{if(tsUnlock($share,(string)$_POST['share_password'])){header('Location: '.tsUrl($share['token']),true,303);exit;}$unlockError=tsT('share_password_wrong');}catch(DomainException $e){$unlockError=socialT($e->getMessage());}}$locked=$share&&!$share['revoked']&&in_array($share['visibility'],['password','friends','selected'],true);$available=$share&&tsReadable($share);$snapshot=$available?json_decode($share['snapshot'],true):[];
if($available)foreach($snapshot['items'] as &$item){if(empty($item['coords']))$item['coords']=tsCoordinatePair((string)($item['address']??''));}unset($item);
header('Referrer-Policy: strict-origin-when-cross-origin');header('X-Robots-Tag: '.($available&&$share['visibility']==='public'?'index, follow':'noindex, nofollow'));header('Cache-Control: private, no-store');
if(($m[2]??'')==='photo'){if(!$available){http_response_code(410);exit;}tdpServe(tdpReference(['id'=>$_GET['id']??'','source'=>$share['token']]));}
if(($m[2]??'')==='image'){
 // Server-rendered social image. No client data, private plan, third-party fetch or edit capability.
 if(!$available){http_response_code(404);exit;}tsImage($share,$snapshot);exit;
}
if(($m[2]??'')==='cover'){
 if(!$available||!preg_match('~^/community/media/([a-f0-9]{32})/$~D',$snapshot['cover']??'',$cm)||!is_file($file=qaPhotoPath($cm[1]))){http_response_code(404);exit;}
 header('Content-Type: image/jpeg');readfile($file);exit;
}
if(($m[2]??'')!==''){http_response_code(404);exit;}
if(!$available)http_response_code($locked?403:410);
require ROOT.'/views/shared-plan.php';
