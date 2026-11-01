<?php
$r=$argv[1];$out=[];
foreach(['src','views'] as $dir)foreach(glob($r.'/'.$dir.'/*.php') as $p){$s=file_get_contents($p);$pos=0;$rows=[];foreach(token_get_all($s) as $t){$v=is_array($t)?$t[1]:$t;if(is_array($t)&&in_array($t[0],[T_CONSTANT_ENCAPSED_STRING,T_INLINE_HTML],true))$rows[]=['start'=>mb_strlen(substr($s,0,$pos),'UTF-8'),'end'=>mb_strlen(substr($s,0,$pos+strlen($v)),'UTF-8'),'raw'=>$v,'inline'=>$t[0]===T_INLINE_HTML];$pos+=strlen($v);}$out[$dir.'/'.basename($p)]=$rows;}
file_put_contents($argv[2],json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
