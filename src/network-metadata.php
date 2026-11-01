<?php
declare(strict_types=1);
function publicPageLanguages(): array {
 $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$all=array_keys(enabledLanguages());
 if(preg_match('~^/community/(posts|trips|places)/([a-f0-9]{32})/$~D',$path,$m))return networkPublicLanguages(['posts'=>'post','trips'=>'trip','places'=>'place'][$m[1]],$m[2]);
 if(preg_match('~^/community/shared/([a-f0-9]{32})/$~D',$path,$m)){$raw=socialSharedTrip($m[1])['public_plan']??null;return $raw?[json_decode($raw,true)['language']??'he']:[];}
 if(preg_match('~^/content/([a-z0-9_-]+)/$~D',$path,$m)){$doc=editorialDocuments()[$m[1]]??[];return array_keys(array_filter($doc['editorial']['translations']??[],'publishedTranslation'));}
 if(preg_match('~^/activities/([A-Za-z0-9]+)/$~D',$path,$m)){$old=$_GET['lang']??null;$langs=[];try{foreach($all as $l){$_GET['lang']=$l;if(providerText($m[1],'title')&&providerText($m[1],'description'))$langs[]=$l;}}finally{if($old===null)unset($_GET['lang']);else $_GET['lang']=$old;}return $langs;}
 if(preg_match('~^/guides/([a-z0-9_-]+)/$~D',$path,$m)){$old=$_GET['lang']??null;$langs=[];try{foreach($all as $l){$_GET['lang']=$l;$a=experienceArticle($m[1])??query('SELECT * FROM articles WHERE slug=? AND '.visibility(),[$m[1]])->fetch();if($a&&articleLanguageAvailable($a))$langs[]=$l;}}finally{if($old===null)unset($_GET['lang']);else $_GET['lang']=$old;}return $langs;}
 
 return $all;
}
function networkShareMetadata(): array {
 $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);if(!preg_match('~^/community/(posts|trips|places)/([a-f0-9]{32})/$~D',$path,$m))return [];
 $kind=['posts'=>'post','trips'=>'trip','places'=>'place'][$m[1]];if(!networkTarget($kind,$m[2]))return [];$edition=networkLocale($kind,$m[2])['document'];if($kind==='trip'){$route=socialPublicTrip($m[2],isset($_GET['version'])?(int)$_GET['version']:null);if(!$route)return [];$edition=$route['public_plan'];}$p=json_decode($edition,true);$image='';
 if(!empty($p['photos'][0]))$image='/community/media/'.$p['photos'][0].'/';
 if(!$image&&$kind==='trip'){$catalog=array_column(tripCatalog(),null,'id');foreach($p['items']??[] as $x)if(!empty($catalog[$x['id']]['image'])){$image=$catalog[$x['id']]['image'];break;}}
 return ['description'=>mb_substr($p['body']??$p['summary']??$p['description']??'',0,180),'image'=>$image];
}
