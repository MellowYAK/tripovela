<?php
// Informational additions only; no routing, supplier URLs or tracking changes.
function renderEditorialExpansion(string $path): void {
 if(in_array($path,['/','/experiences/','/search/','/planning/','/destinations/','/stays/','/ski/','/transport/','/travel-essentials/','/offers/','/offers/eshet/','/offers/walla/'],true))return; // Viator view provides its own booking guidance.
 $pages=json_decode(file_get_contents(ROOT.'/content/page-expansions'.(siteLanguage()==='ka'?'.ka':'').'.json'),true,512,JSON_THROW_ON_ERROR);
 $key=in_array($path,['/offers/eshet/','/offers/walla/'],true)?'/offers/':$path;
 if(!isset($pages[$key]))return;
 echo '<section class="section spacer-bottom prose editorial-expansion">'.md($pages[$key]).'</section>';
}
function renderCampaignExpansion(string $campaign): void {
 $pages=json_decode(file_get_contents(ROOT.'/content/campaign-expansions'.(siteLanguage()==='ka'?'.ka':'').'.json'),true,512,JSON_THROW_ON_ERROR);
 if(!isset($pages[$campaign]))return;
 echo '<section class="section prose editorial-expansion">'.md($pages[$campaign]).'</section>';
}
