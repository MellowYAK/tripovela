<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/installer.php';
installGate();
require dirname(__DIR__).'/src/bootstrap.php';
set_exception_handler(function(Throwable $ex){error_log(get_class($ex).': '.$ex->getMessage());http_response_code(500);echo e(uiText('לא ניתן להשלים את הבקשה. נסו שוב.'));});
if(APP_ENV==='local'&&!localRequest()){http_response_code(403);exit('This local preview is available only on this computer.');}
$requestHost=strtolower(parse_url('http://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST)??'');$allowedHosts=APP_ENV==='local'?['localhost','127.0.0.1','[::1]']:[strtolower(parse_url(APP_URL,PHP_URL_HOST)??'')];if(!in_array($requestHost,$allowedHosts,true)){http_response_code(403);exit('Unrecognized hostname.');}
if(APP_ENV!=='local'&&($_SERVER['HTTPS']??'')!=='on'){header('Location: '.APP_URL.parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),true,308);exit;}
if((int)($_SERVER['CONTENT_LENGTH']??0)>(in_array(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),['/KNISA/catalog/','/KNISA/model-files/'],true)?60000000:(in_array(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),['/community/contribute/','/community/account/','/community/profile/','/community/create/','/api/community-places/','/api/trip-photo/'],true)?27000000:350000))){http_response_code(413);header('Content-Type: text/html; charset=utf-8');readfile(__DIR__.(str_starts_with($_SERVER['REQUEST_URI']??'','/KNISA/')?'/admin-upload-too-large.html':'/upload-too-large.html'));exit;}
$_SESSION['csrf']=$_SESSION['csrf']??bin2hex(random_bytes(32));
header('Content-Type: text/html; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: strict-origin-when-cross-origin');header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: no-store');header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://tile.openstreetmap.org https://www.google-analytics.com; style-src 'self'; script-src 'self' https://www.googletagmanager.com https://challenges.cloudflare.com; connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com https://www.googletagmanager.com https://challenges.cloudflare.com; frame-src 'self' https://www.google.com https://challenges.cloudflare.com; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
$path='/'.trim(rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/'),'/');$path=$path==='/'?'/':$path.'/';
if(preg_match('~^/knisa(?:/|$)~i',$path)&&strpos($path,'/KNISA/')!==0){header('Location: /KNISA/'.substr($path,7),true,308);exit;}
qaGuard($path);
enforceRegionAccess($path);
rmSafe(fn()=>rmCapture($_GET));
$error=''; $title='מתכננים חופשה שמתאימה לכם';$description='מדריכים, השוואות וכלים לחופשה שלכם. Tripovela — מתכננים חופשה שמתאימה לכם.';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf'])){if($path==='/terms/accept/'){$_SESSION['terms_retry']=true;redirect(legalReturn($_POST['return']??'/'));}http_response_code(403);exit(e(uiText('הטופס פג תוקף. רעננו את הדף ונסו שוב.')));}
 if($path==='/preview/'){if(APP_ENV!=='local')requireAdmin();$_SESSION['preview']=empty($_POST['close']);redirect('/');}
}
if(preg_match('~^/s/([a-zA-Z0-9_-]{1,24})/$~D',$path,$shortMatch)){
 require_once ROOT.'/src/short-links.php';
 if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);exit;}
 $destination=shortResolve(db(),$shortMatch[1],$_SERVER['REQUEST_METHOD']==='GET'&&rmAllowed(),(string)($_SERVER['HTTP_REFERER']??''));
 if($destination===null){http_response_code(404);echo e(shortT('unavailable'));exit;}
 header('Referrer-Policy: no-referrer');header('Location: '.$destination,true,302);exit;
}
if($path==='/robots.txt/'){header('Content-Type: text/plain');echo "User-agent: *\nDisallow: /KNISA/\nDisallow: /api/\nDisallow: /go/\nDisallow: /place-info/\nSitemap: https://tripovela.com/sitemap.xml\n";exit;}
if($path==='/sitemap.xml/'){header('Content-Type: application/xml');echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach(query("SELECT slug FROM articles WHERE status='published'") as $a)echo '<url><loc>'.e(APP_URL).'/guides/'.e($a['slug']).'/</loc></url>';foreach(providerCatalog() as $code=>$row)if(!catalogProductInactive($code))echo '<url><loc>'.e(APP_URL).'/activities/'.e($code).'/?lang=he</loc></url>';echo '</urlset>';exit;}
if(strpos($path,'/KNISA/')===0){rememberAdminLanguage();require ROOT.'/src/admin.php';exit;}
if($path==='/api/analytics/'){analyticsReceive();exit;}
if($path==='/api/measurement/'){rmEndpoint();exit;}
if($path==='/measurement/reddit/'){
 if(empty(measurementConfig()['reddit_enabled'])||!rmAllowed()){http_response_code(204);exit;}
 header('Referrer-Policy: no-referrer');
 header("Content-Security-Policy: default-src 'none'; script-src 'self' https://www.redditstatic.com; connect-src https://www.redditstatic.com https://alb.reddit.com https://www.reddit.com; img-src https://alb.reddit.com https://www.reddit.com; frame-ancestors 'self'");
 echo '<!doctype html><html><head><title>Tripovela</title></head><body><script src="/assets/reddit-pixel.js"></script></body></html>';exit;
}
if($path===viatorLandingPath()&&!validSiteLanguage($_GET['lang']??$_POST['lang']??null))$_GET['lang']='en';
preserveSharedLinkLanguage($path);
rememberSiteLanguage();
if($path==='/api/trip-photo/'){require ROOT.'/src/trip-document-photo-controller.php';exit;}
if($path==='/api/trip-share/'||str_starts_with($path,'/community/shared-plan/')){require ROOT.'/src/trip-sharing-controller.php';exit;}
if($path==='/day-feedback/'){require ROOT.'/src/journey-feedback-controller.php';exit;}
if(in_array($path,['/day-plan/','/api/day-plan/'],true)){require ROOT.'/src/journey-controller.php';exit;}
if(preg_match('~^/go/place/([a-f0-9]{32})/(booking|official)/$~D',$path,$om)){placeOfferRedirect($om[1],$om[2]);exit;}
if(in_array($path,['/','/explore/'],true)||in_array($path,['/api/community-events/','/api/community-places/','/api/community-review/'],true)||str_starts_with($path,'/community/'))require ROOT.'/src/network-controller.php';
if(str_starts_with($path,'/community/')||$path==='/api/community/'){require ROOT.'/src/social-controller.php';exit;}
if($path==='/ratings/'){communityRatingHandle();exit;}
if(preg_match('~^/place-info/([a-z0-9_-]+)/$~D',$path,$pm)){require ROOT.'/views/place-info.php';exit;}
if(isset($_GET['currency'])&&in_array($_GET['currency'],['ILS','USD'],true))setcookie('tripovela_currency',$_GET['currency'],['expires'=>time()+31536000,'path'=>'/','samesite'=>'Lax','secure'=>APP_ENV!=='local','httponly'=>true]);
captureAttribution($_GET);
termsHandle($path);
// Explicit, editorial place navigation only. Never accept an arbitrary redirect URL.
if(preg_match('~^/reference/(free50-[a-z0-9-]+)/(map|official)/$~D',$path,$ref)){
 $published=editorialDocuments()[$ref[1]]??null;$free=$published?freeAttractionData($ref[1]):[];
 $url=$ref[2]==='map'?($free['location']['map_search_url']??''):($published['editorial']['official_url']??'');
 if($free&&filter_var($url,FILTER_VALIDATE_URL)&&parse_url($url,PHP_URL_SCHEME)==='https'&&!preg_match('/[\r\n]/',$url)){header('Location: '.$url,true,302);exit;}
 http_response_code(404);exit('Reference unavailable');
}
if(preg_match('~^/map/([a-z0-9-]+)/$~D',$path,$pm)){
 foreach(json_decode(file_get_contents(ROOT.'/content/places.json'),true) as $place)if($place['id']===$pm[1]&&!empty($place['map_query'])){header('Location: https://www.google.com/maps/search/?api=1&query='.rawurlencode($place['map_query']),true,302);exit;}
 http_response_code(404);exit('Map unavailable');
}
if(preg_match('~^/go/viator/catalog/([A-Za-z0-9]{1,40})/$~D',$path,$cm)){providerCatalogRedirect($cm[1]);exit;}
if(preg_match('~^/go/viator/auto/([a-f0-9]{64})/$~D',$path,$am)){viatorAutoRedirect($am[1]);exit;}
if(preg_match('~^/go/viator/article/([0-9]{2})/([A-Za-z0-9]{1,40})/$~D',$path,$gm)){editorialViatorRedirect($gm[1],$gm[2]);exit;}
if(preg_match('~^/go/viator/guide/([A-Za-z0-9]{1,40})/$~D',$path,$gm)){experienceRedirect($gm[1]);exit;}
if(preg_match('~^/go/viator/(landing|air|ranch)/$~D',$path,$vm)){viatorRedirect($vm[1]);exit;}
if($path===viatorLandingPath()){$GLOBALS['viator_live_prices']=true;try{recordPageView($path);}catch(Throwable $ex){error_log('Landing measurement unavailable');}require ROOT.'/views/grand-canyon.php';exit;}
if(preg_match('~^/go/(eshet|walla)/([a-z-]+)/$~D',$path,$match)){
 $provider=$match[1];$campaign=$match[2];
 if(isset(partnerCatalog()[$provider]['campaigns'][$campaign])&&partnerEnabled($provider)){
  if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
  $a=attribution();$a['param']=partnerParam($provider,$campaign,$a);$destination=partnerUrl($provider,$campaign,$a['param']);
  try{recordOutbound($provider,$campaign,$a,trackingLabel($_GET['placement']??'')?:'direct');}catch(Throwable $ex){error_log('Outbound measurement unavailable');}
  header('Referrer-Policy: no-referrer');header('Location: '.$destination,true,302);exit;
 }
}
if(strpos($path,'/go/')===0){http_response_code(410);$title='הקישור אינו פעיל';ob_start();echo '<section class="page-heading"><h1>הקישור אינו פעיל כרגע</h1><p>אפשר להמשיך לקרוא ולתכנן את החופשה.</p><a class="button" href="/">חזרה למדריכים</a></section>';$content=ob_get_clean();require ROOT.'/views/layout.php';exit;}
// Only the requested product is checked; lists never fan out API calls.
$requestedProduct=null;
if(preg_match('~^/activities/([A-Za-z0-9]{1,40})/$~D',$path,$pm)&&(isset(json_decode(file_get_contents(ROOT.'/content/activity-details.json'),true)[$pm[1]])||isset(providerCatalog()[$pm[1]])))$requestedProduct=$pm[1];
foreach(experienceCatalog() as $entry)if($path==='/guides/'.$entry['slug'].'/')$requestedProduct=$entry['product_code'];
if($requestedProduct&&(!providerText($requestedProduct,'title')||!providerText($requestedProduct,'description'))){ob_start();contentLanguageUnavailable();$content=ob_get_clean();require ROOT.'/views/layout.php';exit;}
if($requestedProduct){providerRefresh($requestedProduct);viatorProductState($requestedProduct,true);if(catalogProductInactive($requestedProduct)){ob_start();providerUnavailablePage();$content=ob_get_clean();require ROOT.'/views/layout.php';exit;}viatorLivePrice($requestedProduct);}
$articles=query('SELECT * FROM articles WHERE '.visibility().' ORDER BY id')->fetchAll();
$articles=array_map('editorialApplyArticle',array_values(array_filter(array_merge($articles,experienceArticles()),'articleLanguageAvailable')));
$allById=[];foreach($articles as $a)$allById[$a['id']]=$a;
ob_start();
if(in_array($path,['/terms/','/privacy/','/data-storage/','/accessibility/'],true)){legalPage($path);}
elseif($path==='/'){require ROOT.'/views/home.php';}
elseif(in_array($path,['/plan/','/my-trip/'],true)){require ROOT.'/views/planner.php';}
elseif($path==='/planning/'){require ROOT.'/views/planning.php';}
elseif(preg_match('~^/content/([a-z0-9_-]+)/$~D',$path,$m)){require ROOT.'/views/editorial-content.php';}
elseif($path==='/explore/'){require ROOT.'/views/explore.php';}
elseif(preg_match('~^/places/([a-z0-9-]+)/$~D',$path,$m)){require ROOT.'/views/place.php';}
elseif(preg_match('~^/activities/([A-Za-z0-9]{1,40})/$~D',$path,$m)){require ROOT.'/views/activity.php';}
elseif($path==='/experiences/'){require ROOT.'/views/experiences.php';}
elseif(preg_match('~^/offers/(?:((?:eshet|walla))/)?$~D',$path,$m)){require ROOT.'/views/offers.php';}
elseif(preg_match('~^/offers/(eshet|walla)/([a-z-]+)/$~D',$path,$m)){require ROOT.'/views/campaign.php';}
elseif(preg_match('~^/guides/([a-z0-9-]+)/$~D',$path,$m)){
 $article=experienceArticle($m[1])??query('SELECT * FROM articles WHERE slug=? AND '.visibility(),[$m[1]])->fetch();
 if($article&&!articleLanguageAvailable($article)){contentLanguageUnavailable();$article=null;$GLOBALS['language_unavailable']=true;}elseif($article)$article=editorialApplyArticle($article);
 if(!$article){http_response_code(404);if(empty($GLOBALS['language_unavailable']))require ROOT.'/views/not-found.php';}else{$GLOBALS['viator_live_prices']=true;$title=$article['title'];$description=$article['seo_description']?:$article['summary'];require ROOT.(!empty($article['_experience'])?'/views/experience.php':'/views/article.php');}
}
elseif(isset(categories()[trim($path,'/')])){$cat=trim($path,'/');$title=categories()[$cat];require ROOT.'/views/category.php';}
elseif($path==='/search/'){$title='מחפשים את החופשה שלכם';require ROOT.'/views/search.php';}
elseif(in_array($path,['/tools/','/tools/trip-budget/','/tools/package-compare/','/notebook/'])){require ROOT.'/views/tools.php';}
elseif($path==='/api/v1/search/'){
 ob_end_clean();header('Content-Type: application/json; charset=utf-8');$q=trim((string)($_GET['q']??''));if(mb_strlen($q)<2||mb_strlen($q)>80){http_response_code(422);echo json_encode(['error'=>['code'=>'validation','message'=>'2–80 characters required']]);exit;} $found=[];foreach(searchGuides($articles,$q) as $a)$found[]=['title'=>translatedText($a['title']),'summary'=>translatedText($a['summary']),'url'=>languageUrl('/guides/'.$a['slug'].'/',siteLanguage()),'category'=>$a['category']];echo json_encode(array_slice($found,0,20),JSON_UNESCAPED_UNICODE);exit;
}
elseif(preg_match('~^/destinations/(cyprus|greece|prague|budapest)/$~',$path,$m)){
 $dest=['cyprus'=>['קפריסין',['01','06']],'greece'=>['יוון',['01','02']],'prague'=>['פראג',['04','31','32','33','34']],'budapest'=>['בודפשט',['03']]][$m[1]];$title=$dest[0];?><section class="page-heading"><span class="eyebrow">בחרו יעד, מצאו את הקצב שלכם</span><h1><?=e($title)?></h1><p>מדריכים שיעזרו לבחור איפה לישון, מה לשלב ביום ואילו שאלות לבדוק לפני ההזמנה.</p></section><div class="cards"><?php foreach($dest[1] as $id)if(isset($allById[$id]))card($allById[$id]);?></div><?php
}
else{require ROOT.'/views/pages.php';}
if(!in_array($path,['/my-trip/','/plan/','/explore/'],true))renderEditorialExpansion($path);$content=publicLinks(ob_get_clean());try{recordPageView($path);}catch(Throwable $ex){error_log('Page measurement unavailable');}require ROOT.'/views/layout.php';




