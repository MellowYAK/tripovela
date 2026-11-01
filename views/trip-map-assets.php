<?php $mapLabels=json_decode(file_get_contents(ROOT.'/content/trip-map-labels.json'),true);$mapLabels=($mapLabels[siteLanguage()]??$mapLabels['en'])+['close'=>rxT('close'),'map'=>rxT('map'),'map_error'=>rxT('map_error'),'location_missing'=>tsT('location_missing')]; ?>
<script type="application/json" id="trip-map-labels"><?=json_encode($mapLabels,JSON_HEX_TAG|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE)?></script>
<link rel="stylesheet" href="/assets/trip-map.css?v=<?=filemtime(ROOT.'/public/assets/trip-map.css')?>">
<script defer src="/assets/trip-map.js?v=<?=filemtime(ROOT.'/public/assets/trip-map.js')?>"></script>
