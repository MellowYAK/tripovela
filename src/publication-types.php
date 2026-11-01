<?php
require_once __DIR__.'/ux-audit.php';
// Read-time registry: legacy records and URLs are deliberately not rewritten.
function publicationTypes(): array {
 return ['experience'=>['label'=>'share_story','icon'=>'story','renderer'=>'story'],
 'place'=>['label'=>'places_type','icon'=>'place','renderer'=>'collection'],
 'trip'=>['label'=>'trip','icon'=>'route','renderer'=>'route'],
 'question'=>['label'=>'question','icon'=>'question','renderer'=>'question']];
}
function publicationLabel(string $kind): string {
 $type=publicationTypes()[$kind]??null;
 return $type?($kind==='place'?sxT($type['label']):nt($type['label'])):nt(in_array($kind,['guide','update'],true)?$kind:'post');
}
function publicationFilterLabels(): array {
 $out=[''=>nt('all')];foreach(publicationTypes() as $kind=>$type)$out[$kind]=publicationLabel($kind);return $out;
}
