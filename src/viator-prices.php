<?php
declare(strict_types=1);
require_once __DIR__.'/viator-live.php';

// Production readers intentionally never open the Sandbox cache.
function viatorPriceRecord(string $code, ?string $storage=null, ?int $now=null): ?array {
    if (!preg_match('/^[A-Za-z0-9]{1,40}$/D', $code)) return null;
    $file=($storage??STORAGE_PATH).'/viator-prices-production.json';
    if (!is_file($file) || filesize($file)>1000000) return null;
    $data=json_decode((string)@file_get_contents($file),true);
    if (!is_array($data) || ($data['schema']??null)!==1 || ($data['environment']??'')!=='production') return null;
    $p=$data['products'][$code]??null;$now=$now??time();
    if (!is_array($p) || ($p['product_code']??'')!==$code || ($p['status']??'')!=='ok' || ($p['basis']??'')!=='from_per_person') return null;
    if (!is_int($p['checked_at']??null) || !is_int($p['expires_at']??null) || $p['checked_at']>$now+60 || $p['checked_at']<$now-7200 || $p['expires_at']<=$now) return null;
    if (!is_numeric($p['amount']??null) || !is_finite((float)$p['amount']) || (float)$p['amount']<=0 || !preg_match('/^[A-Z]{3}$/D',(string)($p['currency']??''))) return null;
    return $p;
}

function viatorPriceHtml(string $code, string $lang='en', ?string $storage=null, ?int $now=null): string {
    $labels=[
        'en'=>['From','per person','Updated','Starting price; varies by date, option and party size. Confirm the final total on Viator.','Check current prices on Viator.'],
        'he'=>['החל מ־','לאדם','עודכן','מחיר התחלתי; תלוי בתאריך, באפשרות ובהרכב הנוסעים. בדקו את הסכום הסופי ב־Viator.','בדקו מחיר עדכני ב־Viator.'],
        'ru'=>['От','за человека','Обновлено','Начальная цена зависит от даты, варианта и состава группы. Уточните итог на Viator.','Уточните актуальную цену на Viator.']
    ];$labels['ka']=['ფასი','ერთ ადამიანზე','განახლებულია','საწყისი ფასი დამოკიდებულია თარიღზე, ვარიანტსა და ჯგუფის შემადგენლობაზე. საბოლოო თანხა გადაამოწმეთ Viator-ზე.','მიმდინარე ფასი გადაამოწმეთ Viator-ზე.'];$l=$labels[$lang]??$labels['en'];$live=$storage===null&&!empty($GLOBALS['viator_live_prices']);
    $cached=$storage===null&&!empty($GLOBALS['viator_cached_only']);
    $p=$cached?activityCachedPrice($code):($live?viatorLivePrice($code):viatorPriceRecord($code,$storage,$now));
    if($p&&$live&&!$cached)$p=viatorConvertPrice($p,function_exists('tripCurrency')?tripCurrency():($lang==='he'?'ILS':'USD'));
    if (!$p) return '<span class="viator-price-unavailable">'.htmlspecialchars($l[4],ENT_QUOTES,'UTF-8').'</span>';
    $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    return '<span class="viator-price"><strong>'.$escape($l[0]).' <bdi>'.$escape($p['currency']).' '.number_format((float)$p['amount'],2).'</bdi> '.$escape($l[1]).'</strong><br><small>'.$escape($l[2]).' <time datetime="'.gmdate('c',$p['checked_at']).'">'.gmdate('Y-m-d H:i',$p['checked_at']).' UTC</time>. '.$escape($l[3]).'</small></span>';
}

function viatorPriceTokens(string $html, string $lang='en'): string {
    return preg_replace_callback('/\{\{VIATOR_PRICE:([A-Za-z0-9]{1,40})\}\}/',static fn($m)=>viatorPriceHtml($m[1],$lang),$html);
}
