<?php
function tdpSchema(): void {static $ready=false;if($ready)return;socialDb()->exec("CREATE TABLE IF NOT EXISTS trip_document_photos(id TEXT PRIMARY KEY,owner TEXT NOT NULL,cap_hash TEXT NOT NULL,credit TEXT NOT NULL,created INTEGER NOT NULL)");$ready=true;}
function tdpReference($raw): array {if(!is_array($raw)||!preg_match('/^[a-f0-9]{32}$/D',(string)($raw['id']??'')))return [];return ['id'=>$raw['id'],'source'=>preg_match('/^[a-f0-9]{32}$/D',(string)($raw['source']??''))?$raw['source']:''];}
function tdpRow(array $ref): ?array {tdpSchema();return socialQuery('SELECT * FROM trip_document_photos WHERE id=?',[$ref['id']??''])->fetch()?:null;}
function tdpAllowed(array $ref,?array $user=null): bool {
 $row=tdpRow($ref);if(!$row)return false;if($row['owner']!==''){$user??=socialUser();if($user&&hash_equals($row['owner'],$user['id']))return true;}elseif(tsCapability()&&hash_equals($row['cap_hash'],hash('sha256',tsCapability())))return true;
 if(!empty($ref['source'])){$source=tsRow($ref['source']);if($source&&tsReadable($source))foreach(json_decode($source['snapshot'],true)['items']??[] as $item)if(($item['photo_override']['id']??'')===($ref['id']??''))return true;}return false;
}
function tdpUrl(array $ref): string {return '/api/trip-photo/?'.http_build_query(['id'=>$ref['id'],'source'=>$ref['source']??'']);}
function tdpUpload(?array $user): array {
 if(!tdEnabled()||empty($_POST['rights']))throw new DomainException('invalid');$file=$_FILES['photo']??[];if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($file['size']??0)>20*1024*1024||!is_uploaded_file($file['tmp_name']??''))throw new DomainException('invalid');
 $size=@getimagesize($file['tmp_name']);if(!$size||!in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)||$size[0]*$size[1]>20000000||disk_free_space(STORAGE_PATH)<500000000)throw new DomainException('invalid');
 $id=bin2hex(random_bytes(16));$dir=STORAGE_PATH.'/trip-document-photos';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('photo_storage');$path=$dir.'/'.$id.'.jpg';
 // Reuse the established resource-limited decoder rather than add a GD dependency.
 $python=getenv('TRIPOVELA_PYTHON')?:(PHP_OS_FAMILY==='Windows'?'python':'python3');
 $proc=proc_open([$python,ROOT.'/bin/community_image.py',$file['tmp_name'],$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new DomainException('invalid');fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);if(proc_close($proc)!==0){@unlink($path);throw new DomainException('invalid');}chmod($path,0600);

 try{tdpSchema();$credit=socialString($_POST['photo_author']??'',180);if(!empty($_POST['ai']))$credit=trim($credit.' · '.photoLabels()[5],' ·');socialQuery('INSERT INTO trip_document_photos VALUES(?,?,?,?,?)',[$id,$user['id']??'',$user?'':hash('sha256',tsCapability(true)),$credit,time()]);}catch(Throwable $error){@unlink($path);throw $error;}
 return ['id'=>$id,'source'=>'','credit'=>$credit];
}
function tdpClaim(array $plan,array $user): void {
 foreach($plan['items'] as $item){$ref=$item['photo_override']??[];if(!$ref)continue;if(!tdpAllowed($ref,$user))throw new DomainException('not_found');$row=tdpRow($ref);if($row['owner']===''&&tsCapability()&&hash_equals($row['cap_hash'],hash('sha256',tsCapability())))socialQuery("UPDATE trip_document_photos SET owner=?,cap_hash='' WHERE id=?",[$user['id'],$ref['id']]);}
}
function tdpServe(array $ref): void {if(!tdpAllowed($ref)||!is_file($path=STORAGE_PATH.'/trip-document-photos/'.($ref['id']??'').'.jpg')){http_response_code(404);exit;}header('Content-Type: image/jpeg');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($path);exit;}
function tdpSnapshotUrls(array $snapshot,string $token): array {foreach($snapshot['items'] as &$item)if(!empty($item['photo_override']['id'])){$old=$item['image'];$item['image']='/community/shared-plan/'.$token.'/photo/?id='.$item['photo_override']['id'];if($snapshot['cover']===$old)$snapshot['cover']=$item['image'];}unset($item);return $snapshot;}
