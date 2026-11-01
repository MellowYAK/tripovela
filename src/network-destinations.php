<?php
declare(strict_types=1);
require_once __DIR__.'/destination-labels.php';
function networkDestinationRows(): array {
 $countries=destinationLocales();$rows=[];$cities=[];$lang=siteLanguage();
 foreach($countries['en'] as $iso=>$name){if(!preg_match('/^[A-Z]{2}$/D',$iso))continue;$country=destinationCountryId($iso);$aliases=[$iso,$country,$name];foreach($countries as $names)if(isset($names[$iso]))$aliases[]=$names[$iso];$rows[$country]=['country'=>$country,'city'=>'','flag'=>is_file(ROOT.'/public/assets/flags/'.strtolower($iso).'.svg')?strtolower($iso):'globe','label'=>$countries[$lang][$iso]??$name,'aliases'=>array_values(array_unique($aliases)),'detail'=>nt('country')];}
 foreach(tripDestinations() as $key=>$d){$names=destinationCityNames($d);$iso=destinationCountryIso($d);$country=$d['country'];$label=$names[$lang]??$names['en'];$countryName=$countries[$lang][$iso]??activityLabel($d['country_labels']);$cities[]=['country'=>$country,'city'=>$key,'flag'=>is_file(ROOT.'/public/assets/flags/'.strtolower($iso).'.svg')?strtolower($iso):'globe','label'=>$label,'aliases'=>array_values(array_unique(array_merge([$key],array_values($names),$d['labels']))),'detail'=>$countryName];}
 return array_merge(array_values($rows),$cities);
}
function networkCountryCities(string $country): array {
 return array_keys(array_filter(tripDestinations(),fn($d)=>$d['country']===$country));
}
function networkDestinationPicker(string $country,string $city,string $url): void {
 $rows=networkDestinationRows();$selected=null;
 foreach($rows as $row)if($city!==''?$row['city']===$city:($row['city']===''&&$row['country']===$country)){$selected=$row;break;}
 echo '<div class="network-destination-field"><span><span aria-hidden="true">📍</span> '.e(nt('feed_destination')).'</span><details class="network-destination" data-feed-destination data-url="'.e($url).'" data-rows="'.e(json_encode($rows,JSON_UNESCAPED_UNICODE)).'" data-all="'.e(nt('all_countries')).'" data-empty="'.e(nt('no_destination_match')).'"><summary>';
 if($selected)echo '<img src="/assets/flags/'.e($selected['flag']??$selected['country']).'.svg" alt="" width="24" height="16">';
 else echo '<span class="network-destination-globe" aria-hidden="true">🌐</span>';
 echo '<span class="network-destination-label">'.e($selected?($selected['label'].($city?' · '.$selected['detail']:'')):nt('all_countries')).'</span><span aria-hidden="true">⌄</span></summary><div class="network-destination-popover"><input type="search" data-feed-destination-search autocomplete="off" placeholder="'.e(nt('search_destination')).'" aria-label="'.e(nt('search_destination')).'"><div data-feed-destination-results></div></div></details></div>';
 echo '<input type="hidden" name="country" value="'.e($country).'"><input type="hidden" name="city" value="'.e($city).'">';
}
