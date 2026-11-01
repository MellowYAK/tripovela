<?php
$item=null;foreach(tripCatalog() as $x)if(($x['product']??'')===$m[1]&&(isset($x['editorial_article'])||!empty($x['provider_catalog'])))$item=$x;
$details=json_decode(file_get_contents(ROOT.'/content/activity-details.json'),true)[$m[1]]??providerCatalog()[$m[1]]??null;
if(!$item||!$details){http_response_code(404);require ROOT.'/views/not-found.php';return;}$title=providerText($m[1],'title')?:$item['title'];
$overview=json_decode(file_get_contents(ROOT.'/content/activity-overviews.json'),true)[$m[1]]??null;
contentIndexMark('experience','viator');
require ROOT.'/views/activity-market.php';
