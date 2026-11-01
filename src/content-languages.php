<?php
declare(strict_types=1);
function publishedTranslation(?array $translation): bool {return $translation&&!empty(trim($translation['title']??''))&&!empty(trim($translation['body_markdown']??$translation['description']??''))&&!in_array($translation['status']??'published',['draft','pending','rejected','hidden'],true);}
function catalogItemTranslationAvailable(string $id,?array $translation): bool {
 if(publishedTranslation($translation))return true;
 // Legacy places have structured route/practical sections, not body_markdown.
 static $legacy;$legacy??=array_column(json_decode(file_get_contents(ROOT.'/content/places.json'),true),'id');
 return in_array(preg_replace('/^item-/','',$id),$legacy,true)&&$translation&&!empty(trim($translation['title']??''))&&!empty(trim($translation['summary']??''))&&!in_array($translation['status']??'published',['draft','pending','rejected','hidden'],true);
}
function articleLanguageAvailable(array $article): bool {
 $doc=editorialDocuments()['article-'.strtolower((string)$article['id'])]??null;
 if($doc)return publishedTranslation($doc['editorial']['translations'][siteLanguage()]??null);
 if(siteLanguage()==='he')return true;
 if(!in_array(siteLanguage(),['en','ru'],true))return false;
 if(!empty($article['_experience']))return ($article['_lang']??'')===siteLanguage();
 $title=trim(preg_replace('/\s+/u',' ',$article['title']));return isset(languageDictionary()[$title]);
}
function catalogLanguageAvailable(array $item): bool {
 if(!empty($item['community']))return true; // socialCatalog already selects a published localization.
 if(!empty($item['product']))return (bool)providerText((string)$item['product'],'title')&&(bool)providerText((string)$item['product'],'description');
 $doc=editorialDocuments()[$item['id']]??editorialDocuments()['item-'.$item['id']]??null;
 if($doc)return catalogItemTranslationAvailable($item['id'],$doc['editorial']['translations'][siteLanguage()]??null);
 return in_array(siteLanguage(),['he','en','ru'],true);
}
function contentLanguageUnavailable(array $available=['he','en','ru']): void {$GLOBALS['content_indexing']=['kind'=>'unavailable','provider'=>'','free'=>false];networkUnavailable($available);}
