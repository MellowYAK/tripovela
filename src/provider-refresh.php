<?php
declare(strict_types=1);
// Private snapshots are separate from reviewed, translated editorial content.
function providerRefresh(string $code,bool $force=false): ?array {
 if(!preg_match('/^[A-Za-z0-9]{1,40}$/D',$code))return null;
 return viatorPrivateCache('provider-weekly-'.$code,function()use($code){
  $p=viatorApiJson('/products/'.$code);
  if(($p['productCode']??'')!==$code||!in_array($p['status']??'',['ACTIVE','INACTIVE'],true))return null;
  $dir=STORAGE_PATH.'/viator-live';$now=time();
  $state=['product_code'=>$code,'status'=>$p['status'],'checked_at'=>$now,'expires_at'=>$now+VIATOR_STATUS_TTL,'last_check_ok'=>true];
  $tmp=tempnam($dir,'.state-');if($tmp!==false){file_put_contents($tmp,json_encode($state));chmod($tmp,0640);rename($tmp,$dir.'/product-state-'.$code.'.json');}
  // A complete private source for future retranslation, including image references.
  $snapshot=['product_code'=>$code,'original_url'=>$p['productUrl']??'','api_path'=>'/products/'.$code,'fetched_at'=>gmdate('c',$now),'raw'=>$p];
  $tmp=tempnam($dir,'.source-');if($tmp!==false){file_put_contents($tmp,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));chmod($tmp,0640);rename($tmp,$dir.'/provider-latest-'.$code.'.json');}
  $baseline=json_decode((string)@file_get_contents(ROOT.'/content/provider-facts.json'),true)[$code]['content_hash']??null;
  $content=[];foreach(['title','description','inclusions','exclusions','additionalInfo','logistics','itinerary','cancellationPolicy','images'] as $key)$content[$key]=$p[$key]??null;
  $hash=hash('sha256',json_encode($content,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
  return ['status'=>'ok','product_code'=>$code,'supplier_status'=>$p['status'],'reviews'=>$p['reviews']??[],'checked'=>gmdate('c',$now),'checked_at'=>$now,'expires_at'=>$now+7*86400,'content_hash'=>$hash,'needs_editorial_review'=>$baseline!==null&&!hash_equals($baseline,$hash)];
 },$force);
}
function providerCurrentFacts(string $code): array {
 $base=json_decode((string)@file_get_contents(ROOT.'/content/provider-facts.json'),true)[$code]??[];
 $live=json_decode((string)@file_get_contents(STORAGE_PATH.'/viator-live/provider-weekly-'.$code.'.json'),true);
 return ($live['status']??'')==='ok'?array_replace($base,$live):$base;
}
function viatorIntegration(): array {
 $file=STORAGE_PATH.'/viator-integration.json';$v=is_file($file)?json_decode((string)file_get_contents($file),true):[];
 $pid=getenv('VIATOR_PARTNER_ID')?:($v['partner_id']??'');$mcid=getenv('VIATOR_MCID')?:($v['mcid']??'');
 return ['partner_id'=>preg_match('/^P[0-9]+$/D',$pid)?$pid:'','mcid'=>preg_match('/^[0-9]+$/D',$mcid)?$mcid:''];
}
