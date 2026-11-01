<?php
function jt(string $key): string {static $d;$d??=json_decode(file_get_contents(ROOT.'/content/journey-labels.json'),true);return $d[$key][siteLanguage()]??uiText($d[$key]['en']??$key);}
