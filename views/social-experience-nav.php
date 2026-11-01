<?php
function sxNavigation(bool $mobile=false): void {
 $u=socialUser();$profile=$u?'/community/members/'.$u['handle'].'/':'/community/account/';
 $links=[['home','home','/community/'],['place','places_library','/explore/'],['map','my_trips','/my-trip/'],['save','saved','/community/saved/'],['user','profile',$profile]];
 if($mobile)$links=[ $links[0],$links[1],['story','create','/community/create/'],$links[2],$links[4] ];
 echo '<nav class="'.($mobile?'sx-mobile-nav':'sx-trip-nav').'" aria-label="'.e(nt('home')).'">';
 foreach($links as [$icon,$label,$url])echo '<a href="'.e(languageUrl($url,siteLanguage())).'">'.scIcon($icon).'<span>'.e($label==='home'?sxT('feed_nav'):($label==='places_library'?sxT('explore_nav'):nt($label))).'</span></a>';
 echo '</nav>';
}
