<?php
require_once __DIR__.'/trip-place-provider.php';
require_once __DIR__.'/trip-document-photos.php';
// Additive document format. Existing private notes are never migrated into public prose.
function tdPilot(string $member): bool {
 if($member===''||!socialQuery("SELECT 1 FROM sqlite_master WHERE type='table' AND name='trip_editor_pilot'")->fetchColumn())return false;
 return (bool)socialQuery('SELECT 1 FROM trip_editor_pilot WHERE member=?',[$member])->fetchColumn();
}
function tdEnabled(): bool {
 return true;
}
function tdKnownFacts(array $p): array {
 $facts=tdFacts($p['facts']??[]);
 if(($p['content_kind']??'route')!=='route')return $facts;
 if(empty($facts['mode'])&&isset($p['travel_mode'])&&array_filter($p['connections']??[],fn($leg)=>isset($leg['minutes'])||isset($leg['meters'])))$facts['mode']=$p['travel_mode'];
 $totals=rxTotals($p['items']??[],rxConnections($p['items']??[],$p['connections']??[],$p['travel_mode']??'walk'));
 if(!isset($facts['minutes'])&&isset($totals['total'])){$facts['minutes']=$totals['total'];$facts['duration']='custom';$facts['source']='author';}
 return tdFacts($facts);
}
function tdReadableTime(int $minutes): string {
 $labels=json_decode(file_get_contents(ROOT.'/content/directions-labels.json'),true)[siteLanguage()];
 $rounded=(int)(round($minutes/5)*5);
 return strtr($labels['time'],['{h}'=>(string)intdiv($rounded,60),'{m}'=>(string)($rounded%60)]);
}
// Destination IDs are shared with the feed, never inferred from authored prose.
function tdDestination(array $raw): array {
 $city=socialString($raw['city']??'',100);$country=strtolower(socialString($raw['country']??'',64));
 if($country&&!destinationCountryValid($country))throw new DomainException('invalid');
 $destination=$city?(tripDestinations()[$city]??null):null;
 if($city&&!$destination)throw new DomainException('invalid');
 if($destination){if($country&&$destination['country']!==$country)throw new DomainException('invalid');$country=$destination['country'];}
 return ['city'=>$city,'country'=>$country,'region'=>socialString($raw['region']??$destination['region']??'',100)];
}
function tdDestinationText(array $p): string {
 $d=tripDestinations()[$p['city']??'']??[];
 $city=$d?(destinationCityNames($d)[siteLanguage()]??$d['labels'][1]):($p['city']??'');
 return implode(' · ',array_filter([$city,$p['region']??$d['region']??'',uxCountryName(strtolower($p['country']??$d['country']??''))]));
}
function tdDestinationOptions(): array {
 return ['country_label'=>nt('country'),'city_label'=>nt('city'),'rows'=>networkDestinationRows()];
}
// Read-only adapter for old publications. It never reads private notes or changes a snapshot.
function tdPublicDocument(array $p,array $items): array {
 foreach($items as &$item)$item['item_id']=$item['item_id']??$item['id'];unset($item);
 if(!empty($p['document']['blocks']))return tdDocument($p['document'],$items);
 $text=$p['body']??$p['summary']??$p['public_summary']??$p['description']??'';$blocks=[];$mention=0;
 foreach(preg_split('/\R\s*\R/u',$text,-1,PREG_SPLIT_NO_EMPTY) as $index=>$paragraph){
  $children=[['type'=>'text','text'=>$paragraph]];
  foreach($items as $item){$name=$item['title']??'';if($name==='')continue;$next=[];
   foreach($children as $node){if($node['type']!=='text'){$next[]=$node;continue;}$parts=explode($name,$node['text']);foreach($parts as $n=>$part){if($n)$next[]=['type'=>'place','id'=>'legacy-mention-'.($mention++), 'stop_id'=>$item['item_id'],'text'=>$name];if($part!=='')$next[]=['type'=>'text','text'=>$part];}}$children=$next;
  }$blocks[]=['id'=>'legacy-block-'.$index,'type'=>'paragraph','children'=>$children];
 }return tdDocument(['schemaVersion'=>1,'blocks'=>$blocks],$items);
}
function tdIsPlace(array $place): bool {return empty($place['product'])&&($place['kind']??'place')==='place';}
function tdRemap(array $old,array $new): array {$map=[];foreach($old as $index=>$item)if(isset($new[$index]['item_id'])&&(isset($item['id'])||isset($item['item_id'])))$map[$item['item_id']??$item['id']]=$new[$index]['item_id'];return $map;}
function tdLabels(): array {static $labels;$labels??=json_decode(file_get_contents(ROOT.'/content/trip-document-labels.json'),true,512,JSON_THROW_ON_ERROR);return $labels[siteLanguage()]??$labels['en'];}
function tdSearch(string $query): array {
 if(!tdEnabled())throw new DomainException('not_found');$query=mb_strtolower(trim(mb_substr($query,0,160)));if(mb_strlen($query)<2)return [];$out=[];
 foreach(scCatalogue() as $place){if(!tdIsPlace($place))continue;$destination=tripDestinations()[$place['city']??'']??[];$city=$destination?activityLabel($destination['labels']):($place['city']??'');$country=uxCountryName((string)($place['country']??$destination['country']??''));$text=mb_strtolower(implode(' ',array_filter([$place['title']??'',$place['city']??'',$city,$country,implode(' ',$destination['labels']??[]),$place['country']??'',$place['address']??'',implode(' ',$place['aliases']??[])])));if(!str_contains($text,$query))continue;
 $out[]=['id'=>$place['id'],'title'=>$place['title'],'image'=>$place['image']??'','city'=>implode(' · ',array_filter([$city,$country]))];if(count($out)>=20)break;
 }return count($out)<6?array_merge($out,tppSearch($query)):$out;
}
function tdDocument($raw,array $items,array $remap=[]): array {
 if($raw===null)return ['schemaVersion'=>1,'blocks'=>[]];
 if(!is_array($raw)||($raw['schemaVersion']??null)!==1||!is_array($raw['blocks']??null)||count($raw['blocks'])>1000)throw new DomainException('invalid');
 $ids=array_column($items,'item_id');$out=[];$seen=[];$length=0;
 foreach($raw['blocks'] as $b){if(!is_array($b)||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$b['id']??'')||isset($seen[$b['id']])||!in_array($b['type']??'', ['paragraph','heading','list_item'],true)||!is_array($b['children']??null)||count($b['children'])>1000)throw new DomainException('invalid');$seen[$b['id']]=true;$children=[];
  foreach($b['children'] as $n){if(!is_array($n)||!in_array($n['type']??'',['text','place'],true))throw new DomainException('invalid');$text=(string)($n['text']??'');$length+=mb_strlen($text);if($length>80000)throw new DomainException('invalid');$clean=['type'=>'text','text'=>$text];
   if($n['type']==='place'){$stop=$remap[$n['stop_id']??'']??($n['stop_id']??'');if(in_array($stop,$ids,true)){if(!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$n['id']??'')||isset($seen[$n['id']]))throw new DomainException('invalid');$seen[$n['id']]=true;$clean+=['id'=>$n['id'],'stop_id'=>$stop];$clean['type']='place';}}
   else {if(!empty($n['bold']))$clean['bold']=true;if(!empty($n['italic']))$clean['italic']=true;if(isset($n['href'])){$url=(string)$n['href'];$p=parse_url($url);if(strlen($url)>2000||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower($p['scheme']??''),['https','http'],true)||isset($p['user'])||isset($p['pass'])||preg_match('/[\x00-\x20\\\\]/',$url))throw new DomainException('invalid');$clean['href']=$url;}}
   $children[]=$clean;
  }$out[]=['id'=>$b['id'],'type'=>$b['type'],'children'=>$children];
 }return ['schemaVersion'=>1,'blocks'=>$out];
}
function tdFacts($raw): array {
 if(!is_array($raw))throw new DomainException('invalid');$out=[];
 foreach(['mode'=>['walk','car','bike','transit','mixed'],'duration'=>['short','half_day','full_day','more','custom']] as $key=>$allowed)if(isset($raw[$key])&&$raw[$key]!==''){if(!in_array($raw[$key],$allowed,true))throw new DomainException('invalid');$out[$key]=$raw[$key];}
 foreach(['minutes'=>43200,'walking_meters'=>2000000] as $key=>$max)if(isset($raw[$key])&&$raw[$key]!==''){if(!is_numeric($raw[$key])||$raw[$key]<0||$raw[$key]>$max)throw new DomainException('invalid');$out[$key]=(int)$raw[$key];}
 $out['highlights']=array_values(array_unique($raw['highlights']??[]));if(count($out['highlights'])>2||array_diff($out['highlights'],['nature','views','culture','food']))throw new DomainException('invalid');
 if(isset($out['minutes'])||isset($out['duration'])||isset($out['walking_meters']))$out['source']='author';return $out;
}
function tdPlain(array $doc): string {
 return implode("\n\n",array_map(function($block){
  $text='';$previous=null;
  foreach($block['children'] as $node){
   $value=(string)$node['text'];if($value==='')continue;
   // Separate mention boundaries without changing authored text runs or IDs.
   if($text!==''&&($previous==='place'||$node['type']==='place')&&!preg_match('/\s$/u',$text)&&!preg_match('/^[\s.,;:!?،؛)\]—]/u',$value))$text.=' ';
   $text.=$value;$previous=$node['type'];
  }return $text;
 },$doc['blocks']??[]));
}
function tdIntro(array $snapshot): string {
 if(!empty($snapshot['document']['blocks'])){
  $hasProse=false;foreach($snapshot['document']['blocks'] as $block)foreach($block['children'] as $node)if($node['type']==='text'&&trim($node['text'])!=='')$hasProse=true;
  if(!$hasProse)return '';
 }
 $introSource=!empty($snapshot['document']['blocks'])?tdPlain($snapshot['document']):(string)($snapshot['description']??'');$text=trim(preg_replace('/\s+/u',' ',preg_split('/\R\s*\R/u',$introSource)[0]??''));
 if(mb_strlen($text)<=260)return $text;
 $cut=mb_substr($text,0,260);$space=mb_strrpos($cut,' ');return rtrim($space!==false?mb_substr($cut,0,$space):$cut).'…';
}
function tdMatchesFacts(array $snapshot,array $filters): bool {
 $facts=tdFacts($snapshot['facts']??[]);
 if(!empty($filters['max_stops'])&&count($snapshot['items']??[])>(int)$filters['max_stops'])return false;
 if(!empty($filters['max_minutes'])&&(!isset($facts['minutes'])||$facts['minutes']>(int)$filters['max_minutes']))return false;
 if(!empty($filters['max_walking'])&&(!isset($facts['walking_meters'])||$facts['walking_meters']>(int)$filters['max_walking']))return false;
 return true;
}
function tdRender(array $doc,callable $stop): void {
 $seen=[];foreach($doc['blocks']??[] as $b){$tag=['paragraph'=>'p','heading'=>'h2','list_item'=>'p'][$b['type']]??'p';echo '<'.$tag.' dir="auto"'.($b['type']==='list_item'?' data-list-item="1"':'').'>';$cards=[];
  foreach($b['children'] as $n){$text=e($n['text']);if($n['type']==='place'){$id=$n['stop_id'];echo '<a class="td-mention" href="#stop-'.e($id).'">'.$text.'</a>';if(!isset($seen[$id])){$cards[]=$id;$seen[$id]=true;}}
   else{if(!empty($n['bold']))$text='<strong>'.$text.'</strong>';if(!empty($n['italic']))$text='<em>'.$text.'</em>';if(!empty($n['href']))$text='<a href="'.e($n['href']).'" rel="nofollow noreferrer">'.$text.'</a>';echo $text;}
  }echo '</'.$tag.'>';foreach($cards as $id)$stop($id);
 }
}
