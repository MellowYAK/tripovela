<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$storage=$root.'/storage';
if(is_file($root.'/.env')||is_file($storage.'/site.env')||glob($storage.'/*.sqlite')){fwrite(STDERR,"An installation already exists. No data was changed.\n");exit(1);}
if(!is_dir($storage)&&!mkdir($storage,0700,true))throw new RuntimeException('Cannot create storage');
$token=bin2hex(random_bytes(32));
file_put_contents($storage.'/install-token.json',json_encode(['hash'=>hash('sha256',$token),'expires'=>time()+1800]),LOCK_EX);
chmod($storage.'/install-token.json',0600);
echo "Open this path on your own site within 30 minutes:\n/install/?token=".$token."\n";
