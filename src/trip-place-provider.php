<?php
// Optional Geoapify pilot. No remote call without both explicit enablement and a private key.
function tppEnabled(): bool {return tdEnabled()&&getenv('TRIPOVELA_PLACE_PROVIDER')==='geoapify'&&trim(getenv('GEOAPIFY_API_KEY')?:'')!=='';}
function tppSchema(): void {static $ready=false;if($ready)return;socialDb()->exec("CREATE TABLE IF NOT EXISTS trip_provider_places(id TEXT PRIMARY KEY,provider TEXT NOT NULL,provider_id TEXT NOT NULL,document TEXT NOT NULL,aliases TEXT NOT NULL,updated INTEGER NOT NULL,UNIQUE(provider,provider_id))");$ready=true;}
function tppNormalize(array $raw,string $language): ?array {
 $providerId=$raw['place_id']??'';$title=trim((string)($raw['name']??$raw['address_line1']??''));if(!is_string($providerId)||$providerId===''||strlen($providerId)>2000||$title==='')return null;
 $coords=null;if(isset($raw['lat'],$raw['lon'])&&is_numeric($raw['lat'])&&is_numeric($raw['lon'])&&abs((float)$raw['lat'])<=90&&abs((float)$raw['lon'])<=180)$coords=[(float)$raw['lat'],(float)$raw['lon']];
 return ['provider'=>'geoapify','provider_id'=>$providerId,'title'=>mb_substr($title,0,180),'city'=>mb_substr((string)($raw['city']??''),0,160),'country'=>mb_substr((string)($raw['country']??''),0,160),'country_code'=>strtoupper((string)($raw['country_code']??'')),'address'=>mb_substr((string)($raw['formatted']??''),0,500),'coords'=>$coords,'language'=>$language,'source'=>'Geoapify','attribution'=>(string)($raw['datasource']['attribution']??'© OpenStreetMap contributors'),'license'=>(string)($raw['datasource']['license']??''),'source_url'=>(string)($raw['datasource']['url']??'https://www.openstreetmap.org/copyright')];
}
function tppSearch(string $query): array {
 if(!tppEnabled())return [];if(!throttle('place-provider:'.session_id(),120,3600))return [];
 $ch=curl_init('https://api.geoapify.com/v1/geocode/autocomplete?'.http_build_query(['text'=>$query,'lang'=>siteLanguage(),'limit'=>6,'format'=>'json','apiKey'=>getenv('GEOAPIFY_API_KEY')]));
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);$body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($status!==200||!is_string($body)||strlen($body)>200000)return [];$parsed=json_decode($body,true);if(!is_array($parsed['results']??null))return [];
 // Short-lived candidates are not catalogue imports. Only an explicit resolve persists a place.
 $candidates=[];$out=[];foreach(array_slice($parsed['results'],0,6) as $raw){$p=tppNormalize($raw,siteLanguage());if(!$p)continue;$key=bin2hex(random_bytes(16));$candidates[$key]=['place'=>$p,'expires'=>time()+600];$out[]=['candidate'=>$key,'title'=>$p['title'],'city'=>implode(' · ',array_filter([$p['city'],$p['country']])),'image'=>'','provider'=>'Geoapify','attribution'=>$p['attribution']];}
 $_SESSION['trip_place_candidates']=$candidates;return $out;
}
function tppResolve(string $candidate): array {
 if(!tppEnabled())throw new DomainException('not_found');$record=$_SESSION['trip_place_candidates'][$candidate]??null;if(!$record||$record['expires']<time())throw new DomainException('not_found');$p=$record['place'];return tppPersist($p);
}
function tppPersist(array $p): array {
 tppSchema();$db=socialDb();$db->exec('BEGIN IMMEDIATE');try{$old=socialQuery('SELECT * FROM trip_provider_places WHERE provider=? AND provider_id=?',[$p['provider'],$p['provider_id']])->fetch();$id=$old['id']??('external-'.bin2hex(random_bytes(16)));$aliases=$old?json_decode($old['aliases'],true):[];$aliases[$p['language']]=$p['title'];
 if($old)socialQuery('UPDATE trip_provider_places SET aliases=?,updated=? WHERE id=?',[json_encode($aliases,JSON_UNESCAPED_UNICODE),time(),$id]);
 else socialQuery('INSERT INTO trip_provider_places VALUES(?,?,?,?,?,?)',[$id,$p['provider'],$p['provider_id'],json_encode($p,JSON_UNESCAPED_UNICODE),json_encode($aliases,JSON_UNESCAPED_UNICODE),time()]);
 $db->exec('COMMIT');scCatalogue(true);return ['id'=>$id];}catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function tppCatalogue(): array {
 if(!socialQuery("SELECT 1 FROM sqlite_master WHERE type='table' AND name='trip_provider_places'")->fetchColumn())return [];$out=[];
 foreach(socialQuery('SELECT * FROM trip_provider_places') as $row){$p=json_decode($row['document'],true);$aliases=json_decode($row['aliases'],true);$out[$row['id']]=['id'=>$row['id'],'title'=>$aliases[siteLanguage()]??$p['title'],'aliases'=>array_values($aliases),'city'=>$p['city'],'country'=>$p['country_code'],'address'=>$p['address'],'coords'=>$p['coords'],'image'=>'','credit'=>'','summary'=>'','minutes'=>null,'duration'=>'','cost'=>'unknown','type'=>'place','provider_attribution'=>$p['attribution'],'provider_source'=>$p['source'],'provider_license'=>$p['license'],'url'=>''];}
 return $out;
}
