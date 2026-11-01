<?php
declare(strict_types=1);

// Publications are immutable-until-explicit-update snapshots of the existing planner.
function tsLabels(): array {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/trip-sharing-labels.json'),true,512,JSON_THROW_ON_ERROR);return ($all[siteLanguage()]??$all['en'])+tdLabels();}
function tsT(string $key): string {return tsLabels()[$key]??tdLabels()[$key]??$key;}
function tsDisplayCredit(array $item): string {
 // Migrate only exact legacy UI labels; never translate photographer names or licences.
 $credit=(string)($item['credit']??'');
 foreach(['איור AI להמחשה','AI illustration','Иллюстрация ИИ'] as $label){
  $parts=explode(' · ',$credit);foreach($parts as &$part)if($part===$label)$part=photoLabels()[5];unset($part);$credit=implode(' · ',$parts);
 }
 return $credit;
}
function tsSchema(): void {
 static $ready=false;if($ready)return;sxSchema();socialDb()->exec("CREATE TABLE IF NOT EXISTS trip_shares(token TEXT PRIMARY KEY,owner TEXT NOT NULL DEFAULT '',cap_hash TEXT NOT NULL,expires INTEGER NOT NULL,request_id TEXT NOT NULL,trip TEXT NOT NULL,snapshot TEXT NOT NULL,visibility TEXT NOT NULL DEFAULT 'unlisted',reuse INTEGER NOT NULL DEFAULT 0,revision INTEGER NOT NULL DEFAULT 1,revoked INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL,updated INTEGER NOT NULL,UNIQUE(cap_hash,request_id)); CREATE INDEX IF NOT EXISTS trip_shares_owner ON trip_shares(owner,visibility,revoked); CREATE TABLE IF NOT EXISTS trip_share_events(id TEXT PRIMARY KEY,event TEXT NOT NULL,created INTEGER NOT NULL);");tsAccessSchema();$ready=true;
}
function tsCapability(bool $create=false): string {
 $value=$_COOKIE['tv_share_edit']??'';if(is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value))return $value;
 if(!$create)return '';$value=bin2hex(random_bytes(32));setcookie('tv_share_edit',$value,['expires'=>time()+31536000,'path'=>'/','secure'=>APP_ENV!=='local','httponly'=>true,'samesite'=>'Lax']);$_COOKIE['tv_share_edit']=$value;return $value;
}
function tsRow(string $token): ?array {tsSchema();if(!preg_match('/^[a-f0-9]{32}$/D',$token))return null;return socialQuery('SELECT * FROM trip_shares WHERE token=?',[$token])->fetch()?:null;}
function tsCanEdit(array $r,?array $u=null): bool {
 if($r['owner']!=='')return $u&&hash_equals($r['owner'],$u['id']);
 $cap=tsCapability();return $cap!==''&&(int)$r['expires']>time()&&hash_equals($r['cap_hash'],hash('sha256',$cap));
}
function tsReadable(array $r): bool {return tsAccessReadable($r,socialUser());}
function tsUrl(string $token): string {return languageUrl('/community/shared-plan/'.$token.'/',siteLanguage());}
function tsEvent(string $event,string $key): void {
 try{if(!in_array($event,['draft_created','preview_opened','link_created','copy_link_clicked','send_clicked','shared_page_view','trip_copied','first_edit','derivative_published'],true)||!rmAllowed())return;
 tsSchema();socialQuery('INSERT OR IGNORE INTO trip_share_events VALUES(?,?,?)',[hash('sha256',session_id().':'.$event.':'.$key),$event,time()]);}catch(Throwable $e){/* Measurement is never a publication dependency. */}
}
function tsSnapshot(array $input,?array $u): array {
 $local=($input['local']??false)===true;$trip=(string)($input['trip']??'');
 if(!$local&&$u){$r=sxTrip($u,$trip);$plan=$r['plan'];$title=$r['title'];$refs=socialQuery('SELECT kind,target FROM social_trip_sources WHERE trip=?',[$trip])->fetchAll();}
 else {$plan=socialPlan(is_array($input['plan']??null)?$input['plan']:[]);$title=socialString($input['title']??'',120,1);$refs=[];foreach($input['sources']??[] as $sources)foreach($sources as $ref)$refs[]=$ref;}
 if(!empty($plan['origin_share']))$refs[]=['kind'=>'share','target'=>$plan['origin_share']];$origins=[];foreach($refs as $ref)if(($ref['kind']??'')==='share'){$origin=tsRow((string)($ref['target']??''));if(!$origin||!tsReadable($origin)||$origin['visibility']!=='public'||!$origin['reuse'])throw new DomainException('share_reuse');$origins[$origin['token']]=['token'=>$origin['token'],'revision'=>(int)$origin['revision']];}
 $publicSources=[];foreach($refs as $ref)if(($ref['kind']??'')==='trip'&&($source=sxSource($ref,'')))$publicSources[$source['target']]=array_intersect_key($source,array_flip(['kind','target','edition']));
 $selected=$input['selected']??[];if(!is_array($selected)||count($selected)>100)throw new DomainException('invalid');$items=[];$catalog=scCatalogue();
 foreach($plan['items'] as $item){$key=$item['item_id'];if(!isset($selected[$key])||!is_array($selected[$key]))continue;$opts=$selected[$key];$p=$catalog[$item['id']]??null;
  if(!$p&&($item['type']??'')!=='custom')throw new DomainException('not_found');
  $loc=$p?rxLocation($p):['address'=>'','coords'=>null];
  if(!empty($opts['location'])&&!empty($item['private_address']))$loc=['address'=>$item['private_address'],'coords'=>tsCoords($item['private_coords']??null)??tsCoordinatePair($item['private_address'])];
  $credit=$p?implode(' · ',array_unique(array_filter(array_merge([(string)($p['credit']??'')],array_values(array_intersect_key($p['image_disclosure']??[],array_flip(['photo_author','photo_source','photo_license']))))))):'';
  $items[]=['id'=>$item['id'],'item_id'=>$key,'type'=>$p?'experience':'custom','title'=>$p?$p['title']:$item['title'],'image'=>$p?tsPublicImage($p['image']??''):'','data_credit'=>$p['provider_attribution']??'','credit'=>$credit,'note'=>!empty($opts['note'])?socialString($item['note']??'',500):'','address'=>!empty($opts['location'])?socialString($loc['address']??'',300):'','coords'=>!empty($opts['location'])?($loc['coords']??null):null,'stay_minutes'=>$item['stay_minutes']??null,'active'=>true];if(!empty($item['photo_override'])&&tdpAllowed($item['photo_override'],$u)){$last=count($items)-1;$items[$last]['photo_override']=['id'=>$item['photo_override']['id']];$items[$last]['image']=tdpUrl($item['photo_override']);$items[$last]['credit']=tdpRow($item['photo_override'])['credit'];}
 }
 if(!$items&&!trim(tdPlain($plan['document']??[])))throw new DomainException('invalid');$kind=!$items?'story':(($input['kind']??'route')==='collection'?'collection':'route');
 $legs=$kind==='route'?array_values(array_filter(rxConnections($plan['items'],$plan['connections']??[]),fn($l)=>in_array($l['from'],array_column($items,'item_id'),true)&&in_array($l['to'],array_column($items,'item_id'),true))):[];
 $cover=array_values(array_filter(array_column($items,'image')))[0]??'';$coverCredit='';
 if(!$local&&$u){$private=json_decode(sxOwn($u,$trip)['plan'],true);foreach($private['photos']??[] as $photo)if(preg_match('/^[a-f0-9]{32}$/D',(string)$photo)&&socialQuery('SELECT 1 FROM member_trip_images WHERE id=? AND trip_id=?',[$photo,$trip])->fetchColumn()&&crPhotoAllowed($photo,true)){$cover='/community/media/'.$photo.'/';$coverCredit=implode(' · ',array_filter(array_intersect_key($private,array_flip(['photo_author','photo_source','photo_license']))));break;}}
 return ['language'=>$plan['language']??siteLanguage(),'country'=>$plan['country']??'','city'=>$plan['city']??'','region'=>$plan['region']??'','document'=>tdDocument($plan['document']??null,$items),'facts'=>tdFacts($plan['facts']??[]),'title'=>$title,'description'=>socialString($input['description']??'',600),'kind'=>$kind,'items'=>$items,'connections'=>$legs,'cover'=>$cover,'cover_credit'=>$coverCredit,'public_sources'=>array_values($publicSources),'origins'=>array_values($origins)];
}
function tsWrite(array $input,?array $u): array {
 tsSchema();$action=$input['action']??'';
 if($action==='prepare'){tsCapability(true);return ['ready'=>true];}
 if($action==='preview'){$snapshot=tsSnapshot($input,$u);tsEvent('preview_opened',(string)($input['trip']??''));return ['snapshot'=>$snapshot];}
 if($action==='event'){tsEvent((string)($input['event']??''),socialString($input['key']??'',64));return ['ok'=>true];}
 $db=socialDb();$db->exec('BEGIN IMMEDIATE');try{
  if($action==='create'){
   $cap=tsCapability();if(!$cap)throw new DomainException('conflict');$hash=hash('sha256',$cap);$request=socialString($input['request']??'',64,16);
   $r=socialQuery('SELECT * FROM trip_shares WHERE cap_hash=? AND request_id=?',[$hash,$request])->fetch();
   if(!$r){$draft=socialString($input['trip']??'',64,1);$r=$u?socialQuery('SELECT * FROM trip_shares WHERE owner=? AND trip=? AND revoked=0 ORDER BY created DESC LIMIT 1',[$u['id'],$draft])->fetch():socialQuery("SELECT * FROM trip_shares WHERE owner='' AND cap_hash=? AND trip=? AND revoked=0 AND expires>? ORDER BY created DESC LIMIT 1",[$hash,$draft,time()])->fetch();}
   if(!$r){$snapshot=tsSnapshot($input,$u);$access=tsAccessInput($input,$u);$visibility=$access['visibility'];$token=bin2hex(random_bytes(16));$snapshot=tdpSnapshotUrls($snapshot,$token);$now=time();
    socialQuery('INSERT INTO trip_shares(token,owner,cap_hash,expires,request_id,trip,snapshot,visibility,reuse,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$token,$u['id']??'',$hash,$now+3600,$request,socialString($input['trip']??'',64),json_encode($snapshot,JSON_UNESCAPED_UNICODE),$visibility,$visibility==='public'&&!empty($input['reuse'])?1:0,$now,$now]);tsAccessStore($token,$access);$r=tsRow($token);tsEvent('link_created',$token);if($snapshot['origins'])tsEvent('derivative_published',$token);
   }
  }else{
   $r=tsRow((string)($input['token']??''));if($r&&!$r['owner']&&(int)$r['expires']<=time()&&tsCapability()&&hash_equals($r['cap_hash'],hash('sha256',tsCapability())))throw new DomainException('share_expired');if(!$r||!tsCanEdit($r,$u))throw new DomainException('not_found');
   if($action==='claim'){
    if(!$u)throw new DomainException('auth');if($r['revoked'])throw new DomainException('not_found');if(!$r['owner']){
     $backing=(string)($input['backing']??'');
     if(!$backing&&socialQuery("SELECT 1 FROM sqlite_master WHERE type='table' AND name='guest_imports'")->fetchColumn()){$imported=json_decode(socialQuery("SELECT result FROM guest_imports WHERE owner=? AND local_id=? AND kind='trip'",[$u['id'],$r['trip']])->fetchColumn()?:'{}',true);$backing=$imported['id']??'';}
     if($backing)sxOwn($u,$backing);
     else{$snap=json_decode($r['snapshot'],true);$items=[];foreach($snap['items'] as $item)$items[]=['id'=>$item['id'],'item_id'=>$item['item_id'],'title'=>$item['title'],'type'=>$item['type'],'photo_override'=>isset($item['photo_override']['id'])?['id'=>$item['photo_override']['id'],'source'=>$r['token']]:[],'day'=>0,'note'=>$item['note'],'private_coords'=>tsCoords($item['coords']??null),'private_address'=>$item['address']?:($item['coords']?implode(',',$item['coords']):''),'stay_minutes'=>$item['stay_minutes'],'active'=>true];$saved=socialSaveTrip($u,['title'=>$snap['title'],'plan'=>['city'=>$snap['city']??'','country'=>$snap['country']??'','region'=>$snap['region']??'','days'=>0,'travelers'=>1,'items'=>$items,'description'=>$snap['description'],'connections'=>$snap['connections'],'document'=>$snap['document']??null,'facts'=>$snap['facts']??[],'content_kind'=>$snap['kind']]]);$backing=$saved['id'];}
     socialQuery('UPDATE trip_shares SET owner=?,trip=? WHERE token=?',[$u['id'],$backing,$r['token']]);
    }
   }
   elseif($action==='revoke')socialQuery("UPDATE trip_shares SET revoked=1,snapshot='{}',updated=? WHERE token=?",[time(),$r['token']]);
   elseif($action==='update_locations'){
    if(!$u||!$r['owner']||$r['revoked']||(int)($input['revision']??0)!==(int)$r['revision'])throw new DomainException('conflict');
    $private=json_decode(sxOwn($u,$r['trip'])['plan'],true);$byId=array_column($private['items'],null,'item_id');$snapshot=json_decode($r['snapshot'],true);
    $catalog=scCatalogue();foreach($snapshot['items'] as &$item){$source=$byId[$item['item_id']]??null;if(!$source||$source['id']!==$item['id']||empty($item['address']))continue;$location=!empty($source['private_address'])?['address'=>$source['private_address'],'coords'=>tsCoords($source['private_coords']??null)]:(isset($catalog[$source['id']])?rxLocation($catalog[$source['id']]):[]);if(($location['address']??'')!==$item['address'])continue;$coords=tsCoords($location['coords']??null);if($coords)$item['coords']=$coords;}unset($item);
    socialQuery('UPDATE trip_shares SET snapshot=?,revision=revision+1,updated=? WHERE token=?',[json_encode($snapshot,JSON_UNESCAPED_UNICODE),time(),$r['token']]);
   }
   elseif($action==='update'){if($r['revoked']||(int)($input['revision']??0)!==(int)$r['revision'])throw new DomainException('conflict');$snapshot=tdpSnapshotUrls(tsSnapshot($input,$u),$r['token']);$access=tsAccessInput($input,$u,$r);$visibility=$access['visibility'];socialQuery('UPDATE trip_shares SET snapshot=?,visibility=?,reuse=?,revision=revision+1,updated=? WHERE token=?',[json_encode($snapshot,JSON_UNESCAPED_UNICODE),$visibility,$visibility==='public'&&!empty($input['reuse'])?1:0,time(),$r['token']]);tsAccessStore($r['token'],$access);}
   else throw new DomainException('invalid');$r=tsRow($r['token']);
  }
  $db->exec('COMMIT');if(in_array($action,['update','update_locations','revoke'],true))tsPurgeImages($r['token']);return tsStatus($r,$u);
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function tsStatus(array $r,?array $u): array {return ['token'=>$r['token'],'trip'=>$r['trip'],'url'=>tsUrl($r['token']),'revision'=>(int)$r['revision'],'editable'=>tsCanEdit($r,$u),'claimable'=>!$r['owner']&&tsCanEdit($r,$u),'expires'=>(int)$r['expires'],'revoked'=>(bool)$r['revoked'],'visibility'=>$r['visibility'],'reuse'=>(bool)$r['reuse'],'snapshot'=>json_decode($r['snapshot'],true),'recipients'=>tsAccessRow($r['token'])['recipients_list']];}
function tsCoverUrl(array $row,array $snapshot): string {return str_starts_with($snapshot['cover']??'','/community/media/')?'/community/shared-plan/'.$row['token'].'/cover/':($snapshot['cover']??'');}
function tsPurgeImages(string $token): void {if(!preg_match('/^[a-f0-9]{32}$/D',$token))return;foreach(glob(STORAGE_PATH.'/share-previews/'.$token.'-*.png')?:[] as $file)if(is_file($file))@unlink($file);}
function tsSource(string $token): ?array {
 $r=tsRow($token);if(!$r||!tsReadable($r))return null;$s=json_decode($r['snapshot'],true);$profile=$r['owner']?networkProfile($r['owner']):[];
 $items=array_map(fn($i)=>['id'=>$i['id'],'item_id'=>$i['item_id'],'title'=>$i['title'],'type'=>$i['type'],'photo_override'=>isset($i['photo_override']['id'])?['id'=>$i['photo_override']['id'],'source'=>$token]:[],'note'=>$i['note'],'private_coords'=>tsCoords($i['coords']??null),'private_address'=>$i['address']?:($i['coords']?implode(',',$i['coords']):''),'stay_minutes'=>$i['stay_minutes'],'active'=>true],$s['items']);
 return ['kind'=>'share','target'=>$token,'comment'=>'','edition'=>'','revision'=>(int)$r['revision'],'author'=>$r['owner'],'name'=>$profile?($profile['name']?:$profile['handle']):tsT('traveler'),'title'=>$s['title'],'url'=>tsUrl($token),'places'=>array_column($items,'id'),'routes'=>[],'document'=>['country'=>$s['country']??'','city'=>$s['city']??'','region'=>$s['region']??'','items'=>$items,'connections'=>$s['connections'],'description'=>$s['description'],'document'=>tdDocument($s['document']??null,$items),'facts'=>$s['facts']??[],'content_kind'=>$s['kind']],'snapshot'=>$s];
}
function tsProfile(string $owner): void {
 tsSchema();$rows=socialQuery("SELECT token,snapshot FROM trip_shares WHERE owner=? AND visibility='public' AND revoked=0 ORDER BY updated DESC LIMIT 30",[$owner])->fetchAll();
 echo '<button type="button" data-ts-copy-page>'.e(tsT('copy_page')).'</button>';if(!$rows)return;echo '<section class="ts-profile"><h2>'.e(tsT('public_trips')).'</h2>';
 foreach($rows as $r){$s=json_decode($r['snapshot'],true);echo '<a class="ts-card" href="'.e(tsUrl($r['token'])).'">';if($s['cover'])echo '<img loading="lazy" src="'.e(tsCoverUrl($r,$s)).'" alt="">';echo '<strong dir="auto">'.e($s['title']).'</strong><small>'.e(tsT($s['kind'])).' · '.count($s['items']).' '.e(tsT('stops')).'</small></a>';}echo '</section>';
}
function tsImage(array $r,array $snapshot): void {
 $dir=STORAGE_PATH.'/share-previews';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/'.$r['token'].'-'.(int)$r['revision'].'.png';
 if(!is_file($file)){
  $cover='';$url=parse_url($snapshot['cover']??'',PHP_URL_PATH)?:'';
  if(str_starts_with($url,'/assets/')){$candidate=realpath(ROOT.'/public'.$url);$base=realpath(ROOT.'/public/assets');if($candidate&&$base&&str_starts_with($candidate,$base.DIRECTORY_SEPARATOR)&&is_file($candidate))$cover=$candidate;}
  elseif(preg_match('~^/community/shared-plan/[a-f0-9]{32}/photo/$~D',$url)&&preg_match('/^[a-f0-9]{32}$/D',(string)(explode('id=',parse_url($snapshot['cover'],PHP_URL_QUERY)?:'')[1]??''))){$candidate=STORAGE_PATH.'/trip-document-photos/'.explode('id=',parse_url($snapshot['cover'],PHP_URL_QUERY))[1].'.jpg';if(is_file($candidate))$cover=$candidate;}
  elseif(preg_match('~^/community/media/([a-f0-9]{32})/$~D',$url,$m)){$candidate=qaPhotoPath($m[1]);if(is_file($candidate))$cover=$candidate;}
  $temporary=$file.'.'.bin2hex(random_bytes(6));$python=getenv('TRIPOVELA_PYTHON')?:(PHP_OS_FAMILY==='Windows'?'python':'python3');
  $p=proc_open([$python,ROOT.'/bin/share_image.py',$temporary],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p)){http_response_code(503);return;}
  fwrite($pipes[0],json_encode(['title'=>$snapshot['title'],'count'=>count($snapshot['items']),'cover'=>$cover],JSON_UNESCAPED_UNICODE));fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);
  if($code!==0||!is_file($temporary)){if(is_file($temporary))unlink($temporary);http_response_code(503);return;}chmod($temporary,0600);rename($temporary,$file);
 }
 header('Content-Type: image/png');header('Content-Length: '.filesize($file));readfile($file);
}
function tsDiscoveryRows(array $filters=[]): array {
 tsSchema();$rows=socialQuery("SELECT s.* FROM trip_shares s JOIN members m ON m.id=s.owner WHERE s.visibility='public' AND s.revoked=0 AND m.state='active' ORDER BY s.updated DESC")->fetchAll();
 return array_values(array_filter($rows,function($r)use($filters){
  if(!tsReadable($r))return false;$s=json_decode($r['snapshot'],true);if(!tdMatchesFacts($s,$filters))return false;
  $q=mb_strtolower(trim($filters['q']??''));$text=mb_strtolower(($s['title']??'').' '.($s['description']??'').' '.implode(' ',array_column($s['items']??[],'title')));
  if($q!==''&&mb_strpos($text,$q)===false)return false;
  if(!empty($filters['country'])||!empty($filters['city'])){$catalog=scCatalogue();$found=false;foreach($s['items']??[] as $i){$p=$catalog[$i['id']]??[];if((empty($filters['country'])||($p['country']??'')===$filters['country'])&&(empty($filters['city'])||($p['city']??'')===$filters['city'])){$found=true;break;}}if(!$found)return false;}
  return true;
 }));
}
function tsDiscovery(array $filters=[]): void {
 $rows=tsDiscoveryRows($filters);if(!$rows)return;
 echo '<section class="ts-profile"><h2>'.e(tsT('public_trips')).'</h2>';foreach($rows as $r){$s=json_decode($r['snapshot'],true);echo '<a class="ts-card" href="'.e(tsUrl($r['token'])).'">';if($s['cover'])echo '<img loading="lazy" src="'.e(tsCoverUrl($r,$s)).'" alt="">';echo '<strong dir="auto">'.e($s['title']).'</strong><small>'.e(tsT($s['kind'])).' · '.count($s['items']).' '.e(tsT('stops')).'</small></a>';}echo '</section>';
}

// Parse coordinates only when explicitly provided as a pair; never geocode or match by name.
function tsCoordinatePair(string $value): ?array {
 if(!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/D',$value,$m))return null;
 $a=(float)$m[1];$b=(float)$m[2];return abs($a)<=90&&abs($b)<=180?[$a,$b]:null;
}
function tsPublicImage(string $url): string {
 if(str_starts_with($url,'/')&&!str_starts_with($url,'//'))return $url;
 return filter_var($url,FILTER_VALIDATE_URL)&&parse_url($url,PHP_URL_SCHEME)==='https'?$url:'';
}

require_once ROOT.'/src/trip-share-access.php';
require_once ROOT.'/src/trip-share-feed.php';
function tsCoords($v): ?array {return is_array($v)&&count($v)===2&&is_numeric($v[0])&&is_numeric($v[1])&&is_finite((float)$v[0])&&is_finite((float)$v[1])&&abs((float)$v[0])<=90&&abs((float)$v[1])<=180?[(float)$v[0],(float)$v[1]]:null;}
