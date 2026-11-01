<?php
declare(strict_types=1);
function editorialDocuments(): array {
 static $rows;if($rows!==null)return $rows;$rows=[];$db=tripCatalogDb();if(!$db)return $rows;
 try{foreach($db->query('SELECT id,published FROM editorial_records WHERE published IS NOT NULL') as $r)$rows[$r['id']]=json_decode($r['published'],true);}catch(Throwable $e){}
 return $rows;
}
function editorialMedia(array $ed,string $role='cover'): array {
 $db=tripCatalogDb();if(!$db)return [];
 foreach($ed['media']??[] as $ref){if(($ref['role']??'')!==$role)continue;$q=$db->prepare('SELECT payload FROM editorial_media WHERE id=?');$q->execute([$ref['asset_id']]);$raw=$q->fetchColumn();if(!$raw)continue;$a=json_decode($raw,true);$url=parse_url($a['url']);if(($a['rights_state']??'')==='approved_by_editor'&&($url['host']??'')===(parse_url(APP_URL,PHP_URL_HOST)??'')&&strpos($url['path']??'','/assets/')===0)return ['image'=>$url['path'],'credit'=>$ref['caption'][siteLanguage()]??$a['credit']??'','alt'=>$ref['alt'][siteLanguage()]??''];}
 return [];
}
function editorialExtraItems(array $destinations): array {
 $out=[];
 foreach(editorialDocuments() as $id=>$doc){if(strpos($id,'item-')===0||!in_array($doc['record_type'],['place','experience'],true))continue;$ed=$doc['editorial'];$tr=$ed['translations'][siteLanguage()]??null;if(!publishedTranslation($tr))continue;$city=null;foreach($destinations as $key=>$d)if(in_array('dest-'.$d['slug'],$ed['destination_ids']??[],true)||in_array($key,$ed['destination_ids']??[],true)){$city=$key;break;}if(!$city)continue;
 $media=editorialMedia($ed);$free=freeAttractionData($id);$range=$free['suggested_visit_minutes']??[];$minutes=$range['min']??null;$category=$ed['category_ids'][0]??'cat-city';$type=preg_replace('/^(?:free-)?cat-/','',$category);$out[]=['id'=>$id,'city'=>$city,'title'=>$tr['title'],'summary'=>$tr['summary']??'','type'=>$type,'duration'=>$minutes?($minutes.(!empty($range['max'])&&$range['max']!=$minutes?'–'.$range['max']:'').' '.languagePhrase('דקות · אומדן ללא נסיעה ותורים','min · estimate excluding travel and queues','мин · без дороги и очередей')):'','minutes'=>$minutes,'image'=>$media['image']??'','credit'=>$free?languagePhrase('איור AI להמחשה','AI illustration','Иллюстрация ИИ'):($media['credit']??''),'url'=>languageUrl('/content/'.$id.'/',siteLanguage()),'cost'=>$doc['record_type']==='experience'?'paid':(($ed['visit_cost']['classification']??'')==='free'?'free':'unknown'),'product'=>null];
 }return $out;
}
function freeAttractionData(string $id): array {
 static $cache=[];if(isset($cache[$id]))return $cache[$id];if(strpos($id,'free50-')!==0)return [];
 $db=tripCatalogDb();if(!$db)return [];try{$q=$db->prepare('SELECT payload FROM free_import_state WHERE id=?');$q->execute([$id]);return $cache[$id]=json_decode($q->fetchColumn()?:'[]',true);}catch(Throwable $e){return [];}
}
function editorialArticleBody(string $body,array $doc): string {
 $body=preg_replace_callback('/\[\[(place|experience):([a-z0-9_-]+)\]\]/',function($m){$d=editorialDocuments()[$m[2]]??null;if(!$d)return '';$title=$d['editorial']['translations'][siteLanguage()]['title']??$m[2];return '['.str_replace(['[',']'],['',''],$title).'](/content/'.$m[2].'/)';},$body);
 return md($body);
}

function editorialApplyArticle(array $article): array {
 $doc=editorialDocuments()['article-'.strtolower($article['id'])]??null;if(!$doc)return $article;$tr=$doc['editorial']['translations'][siteLanguage()]??null;if(!$tr)return $article;
 foreach(['title','summary','body_markdown','seo_description'] as $field)if(isset($tr[$field]))$article[$field==='body_markdown'?'body':$field]=$tr[$field];
 if(!empty($article['_experience'])&&!empty($tr['body_markdown']))$article['_lang']=siteLanguage();
 return $article;
}
