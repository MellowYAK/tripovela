<?php
require_once __DIR__.'/reddit-measurement.php';
// Owner-controlled testing default; opt-in mode remains available. Never send user content.
function measurementConfig(): array {static $c;return $c??=json_decode(file_get_contents(ROOT.'/content/measurement.json'),true,512,JSON_THROW_ON_ERROR);}
function measurementGroup(string $path): string {
 $fixed=['/'=>'feed','/community/'=>'feed','/explore/'=>'explore','/my-trip/'=>'my_trips','/plan/'=>'planner','/community/create/'=>'composer','/community/profile/'=>'profile_settings','/community/account/'=>'account','/community/saved/'=>'saved','/community/search/'=>'search','/community/notifications/'=>'notifications','/offers/'=>'offers','/experiences/'=>'activities','/privacy/'=>'privacy','/terms/'=>'terms','/contact/'=>'contact','/about/'=>'about'];
 if(isset($fixed[$path]))return $fixed[$path];
 foreach(['posts'=>'post','trips'=>'route','places'=>'place','members'=>'member_profile','shared'=>'shared_trip'] as $segment=>$group)if(preg_match('~^/community/'.$segment.'/[^/]+/$~D',$path))return $group;
 if(in_array(trim($path,'/'),['destinations','stays','ski','transport','travel-essentials'],true))return 'category';
 if(str_starts_with($path,'/places/')||str_starts_with($path,'/content/'))return 'place';
 if(str_starts_with($path,'/activities/'))return 'activity';
 if(str_starts_with($path,'/tools/'))return 'tool';
 if(str_starts_with($path,'/category/'))return 'category';
 if(str_starts_with($path,'/guides/')||str_starts_with($path,'/guide/')||str_starts_with($path,'/articles/'))return 'guide';
 return 'other';
}
function measurementEligible(string $path): bool {if(qaActive())return false;
 $c=measurementConfig();if(empty($c['enabled'])||!preg_match('/^G-[A-Z0-9]+$/D',$c['measurement_id'])||APP_ENV!=='production'||!empty($_SESSION['user'])||preview()||http_response_code()>=400||preg_match('~^/(KNISA|api|preview|go)/~i',$path))return false;
 $handle=socialUser()['handle']??'';
 return !in_array($handle,['noa_travels','elena_routes','maya_explores','sara_journeys','nino_wanders','daniel_trails','adam_citywalks','leo_discovers','michael_roams','giorgi_paths'],true)&&!preg_match('/^(flow_|ux_)/',$handle);
}
function measurementLabels(): array {static $all;$all??=json_decode(file_get_contents(ROOT.'/content/measurement-labels.json'),true,512,JSON_THROW_ON_ERROR);$labels=$all[siteLanguage()]??$all['en'];if((measurementConfig()['consent_mode']??'opt_in')==='testing_auto')$labels['body']=$labels['testing_body'];return $labels;}
function measurementUi(string $path): void {
 $success=$_SESSION['measurement_success']??'';unset($_SESSION['measurement_success']);
 if(!measurementEligible($path))return;$labels=measurementLabels();$c=measurementConfig();
 $config=['success'=>in_array($success,['profile','follow','like','save','edge','friend','comment','post','helpful','register'],true)?$success:'','id'=>$c['measurement_id'],'mode'=>($c['consent_mode']??'opt_in'),'version'=>$c['version'],'days'=>$c['consent_days'],'group'=>measurementGroup($path),'language'=>siteLanguage(),'member'=>socialUser()?'member':'guest','labels'=>$labels,'reddit'=>!empty($c['reddit_enabled']),'csrf'=>$_SESSION['csrf'],'registrationEvent'=>$_SESSION['registration_event']??'','clickId'=>rmAllowed()?($_SESSION['acquisition']['values']['rdt_cid']??''):''];
 echo '<script type="application/json" id="measurement-config">'.json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP).'</script><script defer src="/assets/reddit-measurement.js?v=1"></script><script defer src="/assets/measurement.js?v='.filemtime(ROOT.'/public/assets/measurement.js').'"></script><link rel="stylesheet" href="/assets/measurement.css?v='.filemtime(ROOT.'/public/assets/measurement.css').'">';
 echo '<section class="measurement-choice" data-measurement-choice hidden role="region" aria-label="'.e($labels['title']).'"><strong>'.e($labels['title']).'</strong><p>'.e($labels['body']).'</p><div><button type="button" data-measurement-accept>'.e($labels['accept']).'</button><button type="button" data-measurement-decline>'.e($labels['decline']).'</button><a href="'.e(languageUrl('/privacy/',siteLanguage())).'">'.e(nt('privacy')).'</a></div></section><button type="button" class="measurement-settings" data-measurement-settings>'.e($labels['settings']).'</button>';
}
