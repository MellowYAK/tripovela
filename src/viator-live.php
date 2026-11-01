<?php
declare(strict_types=1);
const VIATOR_PRICE_TTL = 7 * 86400;
const VIATOR_STATUS_TTL = 3600;
function viatorProductState(string $code,bool $refresh=false): ?array {
 if(!preg_match('/^[A-Za-z0-9]{1,40}$/D',$code))return null;
 $dir=STORAGE_PATH.'/viator-live';$file=$dir.'/product-state-'.$code.'.json';
 $read=static function()use($file,$code){$r=is_file($file)?json_decode((string)@file_get_contents($file),true):null;return is_array($r)&&($r['product_code']??'')===$code?$r:null;};
 $old=$read();if(!$refresh||($old['expires_at']??0)>time())return $old;
 if(($GLOBALS['viator_status_requests']??0)>=3)return $old;
 if(!is_dir($dir)&&!@mkdir($dir,0750,true))return $old;
 $lock=@fopen($file.'.lock','c');if(!$lock)return $old;
 if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return $old;}
 try{
  $old=$read();if(($old['expires_at']??0)>time())return $old;
  $GLOBALS['viator_status_requests']=($GLOBALS['viator_status_requests']??0)+1;
  $p=viatorApiJson('/products/'.$code);
  $ok=($p['productCode']??'')===$code&&in_array($p['status']??'', ['ACTIVE','INACTIVE'],true);
  // Network/auth/rate-limit errors never deactivate an article. Preserve last confirmed state.
  $row=$ok?['product_code'=>$code,'status'=>$p['status'],'checked_at'=>time()]:($old??['product_code'=>$code,'status'=>'UNKNOWN']);
  $row['expires_at']=time()+($ok?VIATOR_STATUS_TTL:300);$row['last_check_ok']=$ok;
  $tmp=tempnam($dir,'.state-');if($tmp!==false){file_put_contents($tmp,json_encode($row));chmod($tmp,0640);rename($tmp,$file);}
  return $row;
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
// Server-only credentials; never send an API key or API response directly to a browser.
function viatorApiJson(string $path, ?array $body=null): ?array {
 $keyFile=getenv('VIATOR_KEY_FILE')?:STORAGE_PATH.'/viator-production.key';
 $key=getenv('VIATOR_API_KEY')?:trim((string)@file_get_contents($keyFile));
 if($key===''||!function_exists('curl_init'))return null;
 $ch=curl_init('https://api.viator.com/partner'.$path);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>['exp-api-key: '.$key,'Accept: application/json;version=2.0','Accept-Language: en-US','Content-Type: application/json;version=2.0'],CURLOPT_USERAGENT=>'TripovelaPriceDisplay/1.0']);
 if($body!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));}
 $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if($status!==200||!is_string($raw)||strlen($raw)>8000000)return null;
 $data=json_decode($raw,true);return is_array($data)?$data:null;
}
function viatorPrivateCache(string $name, callable $fetch, bool $force=false): ?array {
 $dir=STORAGE_PATH.'/viator-live';if(!is_dir($dir)&&!@mkdir($dir,0750,true))return null;
 $file=$dir.'/'.$name.'.json';$old=is_file($file)?json_decode((string)@file_get_contents($file),true):null;
 if(!$force&&is_array($old)&&($old['expires_at']??0)>time())return $old;
 $lock=@fopen($dir.'/'.$name.'.lock','c');if(!$lock)return null;
 if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return null;}
 try{
  $data=$fetch();if(!is_array($data))$data=['status'=>'unavailable','expires_at'=>time()+3600];
  $temp=tempnam($dir,'.new-');if($temp!==false){file_put_contents($temp,json_encode($data));chmod($temp,0640);rename($temp,$file);}
  return $data;
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function viatorLivePrice(string $code, bool $force=false): ?array {
 if(function_exists('catalogProductInactive')&&catalogProductInactive($code))return null;
 if(!preg_match('/^[A-Za-z0-9]{1,40}$/D',$code))return null;
 $allowed=false;foreach(viatorProducts() as $p)if($p['id']===$code)$allowed=true;
 if(function_exists('experienceCatalog'))foreach(experienceCatalog() as $p)if($p['product_code']===$code)$allowed=true;
 if(function_exists('editorialViatorProduct')&&editorialViatorProduct($code))$allowed=true;
 if(function_exists('providerCatalog')&&isset(providerCatalog()[$code]))$allowed=true;
 if(!$allowed)return null;
 $row=viatorPrivateCache('weekly-price-'.$code,function()use($code){
  $data=viatorApiJson('/availability/schedules/'.$code);
  $amount=$data['extraChargesSummary']['fromPrice']??$data['summary']['fromPrice']??null;
  if(($data['productCode']??'')!==$code||empty($data['bookableItems'])||!is_numeric($amount)||!is_finite((float)$amount)||$amount<=0||!preg_match('/^[A-Z]{3}$/D',$data['currency']??''))return null;
  $row=['status'=>'ok','product_code'=>$code,'amount'=>round((float)$amount,2),'currency'=>$data['currency'],'basis'=>'from_per_person','checked_at'=>time(),'expires_at'=>time()+VIATOR_PRICE_TTL];
  // Freeze both displayed currencies at the price-check time; do not re-query FX on visits.
  $prices=[];foreach(array_keys(tripCurrencies()) as $currency){$converted=viatorConvertPrice($row,$currency);if(!$converted)continue;$prices[$currency]=$converted['amount'];}
  $row['display_prices']=$prices;return $row;
 },$force);return ($row['status']??'')==='ok'?$row:null;
}
function viatorConvertPrice(array $row,string $target): ?array {
 if(isset($row['display_prices'][$target])){$row['amount']=$row['display_prices'][$target];$row['currency']=$target;return $row;}
 $source=$row['currency'];if($source===$target)return $row;
 if($source==='GEL'||in_array($target,['GEL','CZK','HUF'],true))return function_exists('tripCachedConversion')?tripCachedConversion($row,$target):null;
 $fx=viatorPrivateCache('fx-'.$source.'-'.$target,function()use($source,$target){
  $data=viatorApiJson('/exchange-rates',['sourceCurrencies'=>[$source],'targetCurrencies'=>[$target]]);
  foreach($data['rates']??[] as $rate){$expiry=strtotime($rate['expiry']??'');
   if(($rate['sourceCurrency']??'')===$source&&($rate['targetCurrency']??'')===$target&&is_numeric($rate['rate']??null)&&is_finite((float)$rate['rate'])&&$rate['rate']>0&&$expiry>time())return ['status'=>'ok','rate'=>(float)$rate['rate'],'expires_at'=>$expiry];
  }return null;
 });if(($fx['status']??'')!=='ok')return null;
 $row['amount']=round($row['amount']*$fx['rate'],2);$row['currency']=$target;$row['converted']=true;$row['expires_at']=min($row['expires_at'],$fx['expires_at']);return $row;
}
