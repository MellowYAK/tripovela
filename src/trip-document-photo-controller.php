<?php
if($_SERVER['REQUEST_METHOD']==='GET')tdpServe(tdpReference($_GET));
header('Content-Type: application/json; charset=utf-8');
try{if($_SERVER['REQUEST_METHOD']!=='POST'||!throttle('trip-photo:'.session_id(),40,3600)||!throttle('trip-photo-ip:'.hash('sha256',$_SERVER['REMOTE_ADDR']??''),80,3600))throw new DomainException('invalid');$result=tdpUpload(socialUser());echo json_encode(['ok'=>true,'result'=>$result],JSON_HEX_TAG|JSON_UNESCAPED_UNICODE);}catch(Throwable $error){http_response_code(422);echo json_encode(['ok'=>false,'message'=>cpT('error')],JSON_UNESCAPED_UNICODE);}exit;
