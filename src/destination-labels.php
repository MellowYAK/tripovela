<?php
function destinationLocales(): array {static $rows;return $rows??=json_decode(file_get_contents(ROOT.'/content/country-locales.json'),true);}
function destinationCountryId(string $iso): string {return ['GE'=>'georgia','CZ'=>'czechia','HU'=>'hungary','US'=>'united-states'][$iso]??strtolower($iso);}
function destinationCountryIso(array $d): string {
 if(!empty($d['country_code']))return strtoupper($d['country_code']);
 foreach(destinationLocales()['en'] as $iso=>$name)if(destinationCountryId($iso)===$d['country']||$name===($d['country_labels'][1]??''))return $iso;
 return strtoupper($d['country']);
}
function destinationCityNames(array $d): array {
 static $translations=[];$out=['he'=>$d['labels'][0]??'','en'=>$d['labels'][1]??'','ru'=>$d['labels'][2]??''];
 foreach(interfaceLanguageManifest() as $lang=>$unused){if(isset($out[$lang])&&$out[$lang]!=='')continue;if(!isset($translations[$lang]))$translations[$lang]=json_decode((string)@file_get_contents(ROOT.'/content/i18n/'.$lang.'.json'),true)?:[];$out[$lang]=$translations[$lang][$out['en']]??$out['en'];}
 return $out;
}
function destinationCountryValid(string $id): bool {foreach(destinationLocales()['en'] as $iso=>$label)if(preg_match('/^[A-Z]{2}$/D',$iso)&&destinationCountryId($iso)===$id)return true;return false;}
