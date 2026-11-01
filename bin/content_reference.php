<?php
// Private CLI helper: allowlisted public content only; no HTTP/API calls.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
$out=['purpose'=>'read_only_reference_not_import','articles'=>[],'page_expansions'=>[]];
$rows=query('SELECT id,slug,title,summary,body,status FROM articles ORDER BY id')->fetchAll();
foreach(experienceCatalog() as $row){$l=$row['languages']['he'];$rows[]=['id'=>$row['id'],'slug'=>$row['slug'],'title'=>$l['title'],'summary'=>$l['meta_description'],'body'=>file_get_contents(ROOT.'/content/experiences/'.$row['slug'].'/he.md'),'status'=>'published','experience_source'=>$row];}
foreach($rows as $a){
 $record=['id'=>'article-'.strtolower($a['id']),'status'=>$a['status'],'url'=>'/guides/'.$a['slug'].'/','translations'=>[]];
 foreach(['he','en','ru'] as $lang){
  $source=$a;$format='markdown';$body=$a['body'];$coverage=null;
  if(isset($a['experience_source'])&&$lang==='en'){$source['title']=$a['experience_source']['languages']['en']['title'];$source['summary']=$a['experience_source']['languages']['en']['meta_description'];$body=file_get_contents(ROOT.'/content/experiences/'.$a['slug'].'/en.md');}
  elseif($lang!=='he'){
   $parser=new Parsedown();$parser->setSafeMode(true);$html=$parser->text($body);$dom=new DOMDocument();$old=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8"><div id="reference-body">'.$html.'</div>');libxml_clear_errors();libxml_use_internal_errors($old);$xp=new DOMXPath($dom);$translated=0;$untranslated=0;
   foreach($xp->query('//text()') as $node){$before=$node->nodeValue;$after=translatedText($before,$lang);if(preg_match('/[\x{0590}-\x{05ff}]/u',$before)){if($before===$after)$untranslated++;else $translated++;}$node->nodeValue=$after;}
   $body='';foreach($dom->getElementById('reference-body')->childNodes as $node)$body.=$dom->saveHTML($node);$format='html_reference_only';$coverage=['translated_text_nodes'=>$translated,'untranslated_hebrew_nodes'=>$untranslated];
  }
  $record['translations'][$lang]=['title'=>translatedText($source['title'],$lang),'summary'=>translatedText($source['summary'],$lang),'body'=>$body,'body_format'=>$format,'dictionary_coverage'=>$coverage];
 }
 $out['articles'][]=$record;
}
foreach(['page-expansions.json','campaign-expansions.json'] as $file)$out['page_expansions'][$file]=json_decode(file_get_contents(ROOT.'/content/'.$file),true);
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
