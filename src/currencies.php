<?php
declare(strict_types=1);
function tripCurrencies(): array {return ['ILS'=>'₪ ILS','USD'=>'$ USD','EUR'=>'€ EUR','GEL'=>'₾ GEL','GBP'=>'£ GBP','CAD'=>'C$ CAD','AUD'=>'A$ AUD','NZD'=>'NZ$ NZD','CHF'=>'CHF','JPY'=>'¥ JPY','CZK'=>'Kč CZK','HUF'=>'Ft HUF'];}
function tripDefaultCurrency(string $language): string {
 // Owner rule: interface language, not the destination or a visitor's location.
 $language=strtolower(explode('-',str_replace('_','-',$language))[0]);
 $configured=languageSettings()['languages'][$language]['currency']??localeRegions()[$language]['currency']??null;
 if(is_string($configured)&&isset(tripCurrencies()[$configured]))return $configured;
 return match($language) {'he'=>'ILS','de','fr','es','el','it','pt','nl','fi','et','lv','lt','sk','sl','hr','ga','mt'=>'EUR',default=>'USD'};
}
function tripCurrencySelector(): void {
 $label=['he'=>'מטבע לתצוגת המחיר','en'=>'Display currency','ru'=>'Валюта цены','ka'=>'ფასის ვალუტა'][siteLanguage()]??'Display currency';
 echo '<form method="get" class="price-currency" translate="no"><label>'.e($label).'<select name="currency" data-currency>';
 foreach(tripCurrencies() as $code=>$name)echo '<option value="'.e($code).'"'.(tripCurrency()===$code?' selected':'').'>'.e($name).'</option>';
 echo '</select></label>';
 foreach($_GET as $key=>$value)if($key!=='currency'&&is_string($value))echo '<input type="hidden" name="'.e($key).'" value="'.e($value).'">';
 echo '<noscript><button>'.e(nt('filter')).'</button></noscript></form>';
}
function tripReferenceConversion(array $row,string $target): ?array {
 $file=STORAGE_PATH.'/reference-fx.json';$fx=is_file($file)?json_decode((string)file_get_contents($file),true):null;
 $source=$row['currency']??'';$a=$fx['gel_per_unit'][$source]??null;$b=$fx['gel_per_unit'][$target]??null;
 if(($fx['expires_at']??0)<=time()||!is_numeric($a)||!is_numeric($b)||!is_finite((float)$a)||!is_finite((float)$b)||$a<=0||$b<=0)return null;
 $row['amount']=round((float)$row['amount']*$a/$b,2);$row['currency']=$target;$row['converted']=true;$row['fx_source']='NBG';return $row;
}
// Listing pages never make network requests. Missing/expired FX means no converted price.
function tripCachedConversion(array $row,string $target): ?array {
 if(!isset(tripCurrencies()[$target]))return null;
 if(isset($row['display_prices'][$target])){$row['amount']=$row['display_prices'][$target];$row['currency']=$target;return $row;}
 $source=$row['currency']??'';if($source===$target)return $row;
 if(!preg_match('/^[A-Z]{3}$/D',$source))return null;
 $file=STORAGE_PATH.'/viator-live/fx-'.$source.'-'.$target.'.json';$fx=is_file($file)?json_decode((string)file_get_contents($file),true):null;
 if(($fx['status']??'')!=='ok'||($fx['expires_at']??0)<=time()||!is_numeric($fx['rate']??null)||!is_finite((float)$fx['rate'])||$fx['rate']<=0)return tripReferenceConversion($row,$target);
 $row['amount']=round((float)$row['amount']*(float)$fx['rate'],2);$row['currency']=$target;$row['converted']=true;return $row;
}
