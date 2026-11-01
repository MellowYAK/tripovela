<?php
// Route facts are separate from a reader's private starting point.
function rxLabels(): array {
 static $all; $all??=json_decode(file_get_contents(ROOT.'/content/route-labels.json'),true);
 return array_replace($all['en'],$all[siteLanguage()]??[],['distance_unit'=>rxUnit(),'distance_units_config'=>rxUnitConfig()['units']]);
}
function rxT(string $key): string {return rxLabels()[$key]??$key;}
function rxNumber($n,int $max): ?int {return is_numeric($n)&&$n!==''&&$n>=0&&$n<=$max?(int)$n:null;}
function rxLocationFingerprint(string $id): string {
 $data=practicalData($id);$raw=[];
 foreach($data['translations']??[] as $language=>$translation)$raw[$language]=$translation['address']??'';ksort($raw);
 $lat=$data['latitude']??null;$lon=$data['longitude']??null;$address='';
 if(str_starts_with($id,'community-')){$doc=json_decode(socialQuery("SELECT document FROM member_places WHERE id=? AND (state='approved' OR owner=?)",[substr($id,10),socialUser()['id']??''])->fetchColumn()?:'{}',true);$lat=$lat??$doc['latitude']??null;$lon=$lon??$doc['longitude']??null;$address=$doc['address']??'';}
 $fields=crPlaceFields($id,'en',['latitude'=>$lat,'longitude'=>$lon,'address'=>$address]);
 return hash('sha256',json_encode([$fields,$raw],JSON_UNESCAPED_UNICODE));
}
function rxConnections(array $items,array $raw=[],string $main='walk'): array {
 $items=array_values(array_filter($items,fn($item)=>($item['active']??true)!==false));
 $modes=['walk','transit','car','bike'];if(!in_array($main,$modes,true))$main='walk';$out=[];
 for($i=1;$i<count($items);$i++){
  $from=$items[$i-1]['item_id']??$items[$i-1]['id'];$to=$items[$i]['item_id']??$items[$i]['id'];$found=null;
  foreach($raw as $leg)if(is_array($leg)&&($leg['from']??'')===$from&&($leg['to']??'')===$to){$found=$leg;break;}
  $counts=array_count_values(array_column($items,'id'));if(!$found&&($counts[$items[$i-1]['id']]??0)===1&&($counts[$items[$i]['id']]??0)===1)foreach($raw as $legacy)if(($legacy['from']??'')===$items[$i-1]['id']&&($legacy['to']??'')===$items[$i]['id']){$found=$legacy;break;}
  $mode=in_array($found['mode']??'',$modes,true)?$found['mode']:$main;
  // Browser-supplied values are always author estimates, never provider results.
  $minutes=$found?rxNumber($found['minutes']??null,10080):null;
  $meters=$found?rxNumber($found['meters']??null,2000000):null;
  $fromLocation=rxLocationFingerprint($items[$i-1]['id']);$toLocation=rxLocationFingerprint($items[$i]['id']);
  $stale=($found&&isset($found['from_location'],$found['to_location'])&&($found['from_location']!==$fromLocation||$found['to_location']!==$toLocation));
  if($stale){$minutes=null;$meters=null;$found['transit_line']='';$found['station_number']='';}
  if(!$raw&&array_key_exists('travel_minutes',$items[$i])){$minutes=rxNumber($items[$i]['travel_minutes'],10080);$mode=in_array($items[$i]['mode']??'',$modes,true)?$items[$i]['mode']:$main;}
  $out[]=['from'=>$from,'to'=>$to,'from_location'=>$fromLocation,'to_location'=>$toLocation,'mode'=>$mode,'transit_line'=>$mode==='transit'?socialString($found['transit_line']??'',100):'','station_number'=>$mode==='transit'?socialString($found['station_number']??'',100):'','minutes'=>$minutes,'meters'=>$meters,'inherited'=>($found['inherited']??true)!==false,'source'=>($minutes!==null||$meters!==null)?'author':'unavailable'];
 }return $out;
}
function rxTotals(array $items,array $legs): array {
 $items=array_values(array_filter($items,fn($item)=>($item['active']??true)!==false));$travel=0;$visits=0;$known=0;$visitKnown=0;
 foreach($legs as $leg)if($leg['minutes']!==null){$travel+=$leg['minutes'];$known++;}
 foreach($items as $item)if(($n=rxNumber($item['stay_minutes']??null,10080))!==null){$visits+=$n;$visitKnown++;}
 $complete=count($items)>1&&$known===count($items)-1;
 return ['travel'=>$complete?$travel:null,'known_travel'=>$travel,'known_legs'=>$known,'legs'=>count($legs),'visits'=>$visitKnown===count($items)?$visits:null,'known_visits'=>$visits,'known_visits_count'=>$visitKnown,'total'=>$complete&&$visitKnown===count($items)?$travel+$visits:null,'modes'=>array_values(array_unique(array_column($legs,'mode')))];
}
function rxLocation(array $place): array {
 if(str_starts_with($place['id']??'','external-')&&isset($place['provider_source'])){$coords=$place['coords']??null;$address=$place['address']??'';return ['id'=>$place['id'],'title'=>$place['title'],'address'=>$address,'query'=>$coords?implode(',',$coords):$address,'coords'=>$coords];}
 $p=practicalData($place['id']);$address='';$communityQuery='';$lat=$p['latitude']??null;$lon=$p['longitude']??null;
 if(practicalVisible($p,'address'))foreach([siteLanguage(),'en','ka','he','ru'] as $lang)if($address=($p['translations'][$lang]['address']??''))break;
 if(str_starts_with($place['id'],'community-')){
  $row=socialQuery("SELECT document FROM member_places WHERE id=? AND state='approved'",[substr($place['id'],10)])->fetchColumn();
  $doc=json_decode($row?:'{}',true);$address=$address?:($doc['address']??'');$communityQuery=(string)($doc['map_query']??'');$lat=$lat??$doc['latitude']??null;$lon=$lon??$doc['longitude']??null;
 }
 $reviewed=crPlaceFields($place['id'],networkContentLanguage(),['address'=>$address,'latitude'=>$lat,'longitude'=>$lon]);$address=$reviewed['address'];$lat=$reviewed['latitude'];$lon=$reviewed['longitude'];
 $coords=is_numeric($lat)&&is_numeric($lon)&&abs((float)$lat)<=90&&abs((float)$lon)<=180?[(float)$lat,(float)$lon]:null;
 $query=practicalVisible($p,'map')?(practicalMapQuery($p)?:($address?:$communityQuery)):'';
 return ['id'=>$place['id'],'title'=>$place['title'],'address'=>$address,'query'=>$coords?implode(',',$coords):($query?:($address?:$communityQuery)),'coords'=>$coords];
}
function rxDirections(array $place,?array $origin=null,string $mode='walk'): string {
 $target=rxLocation($place);if(!$target['coords']&&!$target['address'])return ''; 
 return '<button type="button" class="rx-directions" data-rx-directions="'.e(json_encode(['target'=>$target,'origin'=>$origin?rxLocation($origin):null,'mode'=>$mode],JSON_UNESCAPED_UNICODE)).'">'.scIcon('route').' '.e(rxT('directions')).'</button>';
}
function rxSummary(array $p): string {
 $legs=rxConnections($p['items']??[],$p['connections']??[],$p['travel_mode']??'walk');$t=rxTotals($p['items']??[],$legs);
 $text=$t['travel']===null?rxT('travel_unavailable'):$t['travel'].' '.rxT('minutes').' · '.rxT('travel_only');
 if($t['known_legs']&&$t['travel']===null)$text.=' · '.$t['known_legs'].'/'.$t['legs'].' '.rxT('known_connections');
 if($t['total']!==null)$text=$t['travel'].' + '.$t['visits'].' = '.$t['total'].' '.rxT('minutes').' · '.rxT('travel_visits');
 elseif($t['known_visits_count'])$text.=' · '.$t['known_visits'].' '.rxT('minutes').' '.rxT('visits_partial');
 return $text;
}

function rxUnitConfig(): array {static $config;return $config??=json_decode(file_get_contents(ROOT.'/content/distance-units.json'),true);}
function rxUnitDefault(string $country): string {$c=rxUnitConfig();return $c['countries'][strtoupper($country)]??$c['default'];}
function rxUnitPreference(): string {$u=socialUser();return $u?(socialQuery('SELECT unit FROM route_unit_preferences WHERE member=?',[$u['id']])->fetchColumn()?:'auto'):'auto';}
function rxUnit(): string {$unit=rxUnitPreference();return isset(rxUnitConfig()['units'][$unit])?$unit:rxUnitDefault(visitorCountry());}
function rxDistance($meters,?string $unit=null): string {if($meters===null)return ''; $unit=$unit??rxUnit();$config=rxUnitConfig()['units'][$unit];$value=(float)$meters/$config['meters'];return rtrim(rtrim(number_format($value,3,'.',''),'0'),'.').' '.rxT($config['label']);}
function rxUnitFields(): void {echo '<label>'.e(rxT('distance_units')).'<select name="distance_unit">';foreach(['auto'=>'units_auto']+array_map(fn($v)=>$v['label'],rxUnitConfig()['units']) as $value=>$key)echo '<option value="'.$value.'" '.(rxUnitPreference()===$value?'selected':'').'>'.e(rxT($key)).'</option>';echo '</select></label>';}
function rxSaveUnit(array $u,array $raw): void {if(!array_key_exists('distance_unit',$raw))return;$unit=$raw['distance_unit'];if($unit!=='auto'&&!isset(rxUnitConfig()['units'][$unit]))throw new DomainException('invalid');socialQuery('INSERT INTO route_unit_preferences VALUES(?,?) ON CONFLICT(member) DO UPDATE SET unit=excluded.unit',[$u['id'],$unit]);}

function rxInputMeters($value,string $unit): ?float {if($value===''||$value===null)return null;$c=rxUnitConfig()['units'][$unit]??null;if(!$c||!is_numeric($value)||(float)$value<0)throw new DomainException('invalid');return (float)$value*$c['meters'];}
function rxUnitOptions(string $selected): string {$html='';foreach(rxUnitConfig()['units'] as $key=>$c)$html.='<option value="'.e($key).'" '.($key===$selected?'selected':'').'>'.e(rxT($c['label'])).'</option>';return $html;}
