<?php
declare(strict_types=1);
// Provider-neutral, manually reviewed offers. No API credentials or automatic affiliate claims.
function offerLabel(string $key): string {
 static $labels;$labels=$labels??json_decode(file_get_contents(ROOT.'/content/offer-labels.json'),true);return $labels[$key][siteLanguage()]??uiText($labels[$key]['en']??$key);
}
function placeOfferUrl(string $url): string {
 $url=networkSource($url);$host=strtolower(parse_url($url,PHP_URL_HOST)??'');
 if(parse_url($url,PHP_URL_SCHEME)!=='https'||strpos($host,'.')===false||filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)||preg_match('/(?:^|\.)(localhost|local|internal|test|invalid)$/D',$host)||preg_match('/[\x00-\x20\\\\]/',$url))throw new DomainException('invalid');
 return $url;
}
function placeOfferInput(array $input,string $cost): array {
 $amount=trim((string)($input['amount']??''));$currency=(string)($input['offer_currency']??'USD');$basis=(string)($input['price_basis']??'person');$kind=(string)($input['price_kind']??'from');
 if(!in_array($currency,['ILS','USD','EUR','GBP','GEL','CZK','HUF','JPY','AUD','NZD'],true)||!in_array($basis,['person','group','entry'],true)||!in_array($kind,['from','fixed'],true))throw new DomainException('invalid');
 if($amount!==''&&(!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D',$amount)||(float)$amount<=0||$cost!=='paid'))throw new DomainException('invalid');
 $link=trim((string)($input['booking_url']??''));if($link!=='')$link=placeOfferUrl($link);
 return ['version'=>1,'provider_name'=>socialString($input['provider_name']??'',100),'booking_url'=>$link,'amount'=>$amount===''?null:$amount,'currency'=>$currency,'basis'=>$basis,'price_kind'=>$kind,'checked_at'=>gmdate('Y-m-d'),'source'=>'member','relationship'=>'direct'];
}
function placeOfferPrice(array $p): string {
 if(($p['cost']??'')==='free')return offerLabel('free');$o=$p['offer']??[];
 if(empty($o['amount']))return offerLabel('check_price');
 $display=tripCachedConversion(['amount'=>(float)$o['amount'],'currency'=>$o['currency']],tripCurrency());$value=($display&&!empty($display['converted'])?'≈ ':'').number_format((float)($display['amount']??$o['amount']),2).' '.($display['currency']??$o['currency']);if(($o['price_kind']??'from')==='from')$value=siteLanguage()==='ka'?$value.'-დან':offerLabel('from').' '.$value;return $value.' · '.offerLabel($o['basis']);
}
function placeOfferForm(array $p=[]): void {
 $o=$p['offer']??[];echo '<fieldset><legend>'.e(offerLabel('details')).'</legend><p>'.e(offerLabel('review')).'</p>';
 foreach(['provider_name'=>100,'booking_url'=>1000,'amount'=>12] as $key=>$limit)echo '<label>'.e(offerLabel($key)).'<input name="'.$key.'" '.($key==='booking_url'?'type="url"':($key==='amount'?'inputmode="decimal"':'' )).' maxlength="'.$limit.'" value="'.e((string)($o[$key]??'')).'"></label>';
 foreach(['offer_currency'=>['USD','ILS','EUR','GBP','GEL','CZK','HUF','JPY','AUD','NZD'],'price_basis'=>['person','group','entry'],'price_kind'=>['from','fixed']] as $name=>$values){$key=['offer_currency'=>'currency','price_basis'=>'basis','price_kind'=>'price_kind'][$name];echo '<label>'.e(offerLabel($name)).'<select name="'.$name.'">';foreach($values as $value)echo '<option value="'.$value.'"'.(($o[$key]??$values[0])===$value?' selected':'').'>'.e($name==='offer_currency'?$value:offerLabel($value)).'</option>';echo '</select></label>';}
 echo '<p>'.e(offerLabel('price_hint')).'</p></fieldset>';
}
function placeOfferPanel(array $p,string $id): void {
 $o=$p['offer']??[];echo '<section class="social-card">';
 if(($p['cost']??'')==='paid')tripCurrencySelector();
 echo '<h2>'.e(placeOfferPrice($p)).'</h2>';
 if(!empty($o['provider_name']))echo '<p>'.e(offerLabel('provider_name')).': '.e($o['provider_name']).'</p>';
 if(!empty($o['checked_at']))echo '<p>'.e(offerLabel('reported')).' '.e($o['checked_at']).'</p>';
 if(!empty($o['booking_url']))echo '<a class="button" rel="ugc nofollow noopener" href="/go/place/'.$id.'/booking/">'.e(($p['cost']??'')==='free'?offerLabel('register'):offerLabel('book')).' ↗</a>';
 if(!empty($p['source_url']))echo '<p><a rel="ugc nofollow noopener" href="/go/place/'.$id.'/official/">'.e(offerLabel('official')).' ↗</a></p>';
 echo '<p>'.e(offerLabel('disclaimer')).'</p></section>';
}
function placeOfferRedirect(string $id,string $kind): void {
 if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);return;}
 $row=socialQuery("SELECT p.document FROM member_places p JOIN members m ON m.id=p.owner WHERE p.id=? AND p.state='approved' AND m.state='active'",[$id])->fetch();
 if(!$row){http_response_code(404);return;}$p=json_decode($row['document'],true);$url=$kind==='booking'?($p['offer']['booking_url']??''):($p['source_url']??'');
 try{if(!$url)throw new DomainException('invalid');$url=placeOfferUrl($url);$host=strtolower(parse_url($url,PHP_URL_HOST)??'');if($host==='viator.com'||str_ends_with($host,'.viator.com')){$url=viatorAutoRegister($url,'/community/places/'.$id.'/',0);if(!$url)throw new DomainException('invalid');}}catch(Throwable $e){http_response_code(410);return;}
 header('Referrer-Policy: no-referrer');header('Location: '.$url,true,302);
}
