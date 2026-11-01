<?php
declare(strict_types=1);
function discoveryTaxonomy(): array {
 static $data;return $data??=json_decode(file_get_contents(ROOT.'/content/discovery-taxonomy.json'),true,512,JSON_THROW_ON_ERROR)['groups'];
}
/** Additive classification. Ambiguous existing types receive a parent only, never a guessed child. */
function discoveryClassify(array $x): array {
 $map=[
 'adventure'=>['adventure_active'], 'nature'=>['nature_outdoors'], 'food'=>['food_drink'], 'water'=>['water_coast'],
 'city'=>['city_culture'], 'culture'=>['city_culture'], 'shows'=>['shows_nightlife'],
 'helicopter'=>['adventure_active.airborne_activities'], 'cycling'=>['adventure_active.cycling'],
 'museums'=>['city_culture.museums_galleries'], 'beaches'=>['water_coast.beaches_swimming'],
 'waterfront'=>['water_coast.waterfront_walks'], 'viewpoints'=>['nature_outdoors.viewpoints'],
 'markets'=>['markets_shopping'], 'memorials'=>['city_culture.historic_sites']
 ];
 // Curated IDs based on existing editorial descriptions, not title matching.
 $reviewed=['old-tbilisi'=>['city_culture.city_walks_tours'],
 'offer-9396p5'=>['city_culture.city_walks_tours','city_culture.historic_sites'],
 'offer-75503p11'=>['city_culture.city_walks_tours'],
 'offer-35015p2'=>['water_coast.boat_cruises'],
 'free50-free-027-rike-park'=>['nature_outdoors.parks_gardens'],
 'free50-free-021-schoenbrunn-park'=>['nature_outdoors.parks_gardens']];
 $doc=editorialDocuments()[$x['id']]['editorial']??editorialDocuments()['item-'.$x['id']]['editorial']??[];
 $valid=[];foreach(discoveryTaxonomy() as $id=>$g){$valid[]=$id;$valid=array_merge($valid,array_keys($g['children']));}
 $manual=array_values(array_intersect(array_merge($doc['category_ids']??[],$doc['subcategory_ids']??[]),$valid));
 $tags=$manual?:($reviewed[$x['id']]??$map[$x['type']]??[]);
 $x['subcategory_ids']=array_values(array_filter($tags,fn($v)=>str_contains($v,'.')));
 $x['category_ids']=array_values(array_unique(array_map(fn($v)=>explode('.',$v)[0],$tags)));
 $x['classification_source']=$manual?'editorial':(isset($reviewed[$x['id']])?'reviewed_id':'legacy_type');
 $x['classification_version']=1;
 $x['kind']=!empty($x['product'])?'experience':'place';
 $x['minutes_max']=$x['minutes']??null;
 if(!empty($x['product'])){
  $details=json_decode(file_get_contents(ROOT.'/content/activity-details.json'),true);
  $duration=providerCatalog()[$x['product']]['duration']??activityMetadata()[$x['product']]['duration']??$details[$x['product']]['duration']??[];
  $x['minutes_max']=$duration['fixedDurationInMinutes']??$duration['variableDurationToMinutes']??null;
 }elseif($free=freeAttractionData($x['id'])){$x['minutes_max']=$free['suggested_visit_minutes']['max']??null;}
 $x['visit_mode']=empty($x['product'])&&$x['cost']==='free'?'self_guided':'unknown';
 $x['setting']='unknown';$x['city_relation']='unknown';
 return $x;
}
