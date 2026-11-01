<?php
// CLI-only allowlist. Never exports member data or credentials.
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
$_GET['lang']='he';$out=['items'=>[],'articles'=>[],'destinations'=>tripDestinations()];
foreach(tripCatalog() as $x){if(str_starts_with($x['id'],'community-'))continue;$out['items'][]=array_intersect_key($x,array_flip(['id','city','title','summary','type','url','cost','product']));}
foreach(query('SELECT id,slug,title,summary,status FROM articles ORDER BY id') as $a)$out['articles'][]=$a;
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
