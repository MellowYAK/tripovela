<?php
declare(strict_types=1);
// Permanent provider rule, including languages added in the future.
function contentIndexRule(string $language,string $kind,string $provider='',bool $free=false): string {
 return $kind!=='article'&&!$free&&strtolower($provider)==='viator'&&$language!=='he'?'noindex, follow':'index, follow';
}
function contentIndexMark(string $kind,string $provider='',bool $free=false): void {
 $GLOBALS['content_indexing']=['kind'=>$kind,'provider'=>$provider,'free'=>$free];
}
function contentIndexDocument(array $doc): void {
 $provider='';foreach($doc['context']['provider_bindings']??[] as $binding)if(strtolower($binding['provider']??'')==='viator'){$provider='viator';break;}else{$provider=$binding['provider']??'';}
 // An affiliate binding alone does not imply API-origin content.
 $apiOrigin=($doc['context']['content_origin']??'')==='provider_api'||!empty($doc['context']['provider_owned_fields']);
 contentIndexMark($doc['record_type']??'article',$apiOrigin?$provider:'',($doc['editorial']['visit_cost']['classification']??'')==='free');
}
function contentRobots(?string $language=null): string {
 if(APP_ENV!=='production'||preview()||http_response_code()>=400)return 'noindex, nofollow';
 $path=$GLOBALS['path']??'/';
 if(preg_match('~^/(?:KNISA|api|go|place-info|preview|ratings)(?:/|$)~',$path)||in_array($path,['/plan/','/my-trip/','/search/','/notebook/','/day-plan/','/day-feedback/'],true))return 'noindex, nofollow';
 if(preg_match('~^/community/(account|contribute|media|shared|profile|create|saved|notifications|translations|source-update|avatar|export)(?:/|$)~',$path))return 'noindex, nofollow';
 $r=$GLOBALS['content_indexing']??null;if(!$r)return 'index, follow';
 return contentIndexRule($language??siteLanguage(),$r['kind'],$r['provider'],$r['free']);
}
