<?php
$GLOBALS['sc_composer_kind']='trip';$title=$editing?seT('edit'):rxT('publish');
$catalog=[];foreach(scCatalogue()+($u?crPendingPlaces($u):[]) as $place){$city=tripDestinations()[$place['city']]??[];$catalog[]=array_intersect_key($place,array_flip(['id','title','city','image','cost']))+['city_label'=>activityLabel($city['labels']??[$place['city'],$place['city'],$place['city']]).' · '.activityLabel($city['country_labels']??['','','']),'location'=>$place['location']??rxLocation($place)];}
$saved=[];if($u)foreach(socialQuery('SELECT id,title,plan FROM member_trips WHERE owner=? ORDER BY updated DESC',[$u['id']]) as $trip){$plan=json_decode($trip['plan'],true);$saved[]=['title'=>$trip['title'],'ids'=>array_column($plan['items']??[],'id')];}
?>
<section class="network-card rx-composer" data-pending-stop="<?=e(socialT('unapproved_stop'))?>" data-rate-limit="<?=e(socialT('rate_limit'))?>" data-rx-labels="<?=e(json_encode(rxLabels(),JSON_UNESCAPED_UNICODE))?>">
<h1><?=e($editing?seT('edit'):scText('Share a route','שתפו מסלול','Поделитесь маршрутом'))?></h1>
<?php networkCreationChoices(true);?>
<?php if($u):?><div class="sc-editor-author"><?=networkAvatar($me)?><strong><?=e($me['name']?:$u['handle'])?></strong><small><?=e(rxT('publication_notice'))?></small></div><?php endif?>
<p class="rx-help"><?=e(sxT('library_notice'))?></p>
<form method="post" enctype="multipart/form-data" data-route-composer data-edit-expired="<?=e(seT('expired'))?>" data-editing="<?=$editing?'1':''?>" data-owner="<?=e($u['id']??'guest')?>" data-catalog="<?=e(json_encode($catalog,JSON_UNESCAPED_UNICODE))?>" data-document="<?=e(json_encode($d,JSON_UNESCAPED_UNICODE))?>" data-saved-trips="<?=e(json_encode($saved,JSON_UNESCAPED_UNICODE))?>">
<?=csrf()?><input type="hidden" name="network_action" value="route_save"><input type="hidden" name="draft_id" value="<?=e($draft['id']??'')?>"><input type="hidden" name="revision" value="<?=e((string)($draft['revision']??0))?>">
<?php if(!$editing)saFields($d);?>
<label><?=e(sxT('optional_title'))?><input name="title" maxlength="120" value="<?=e($v('title'))?>"></label>
<div class="post-destination" data-post-destination><label><?=e(nt('search_destination'))?></label><?php networkDestinationPicker((string)$v('country'),(string)$v('city'),'');?><label><?=e(tdLabels()['region'])?><input name="region" maxlength="100" value="<?=e($v('region'))?>"></label><script defer src="/assets/feed-destination.js?v=<?=filemtime(ROOT.'/public/assets/feed-destination.js')?>"></script></div>
<label><?=e(rxT('body'))?><textarea name="body" rows="3" maxlength="12000" placeholder="<?=e(rxT('body'))?>"><?=e($v('body'))?></textarea></label>
<label><?=e(rxT('places'))?><input type="search" data-rx-search placeholder="<?=e(rxT('search'))?>" autocomplete="off"></label><div data-rx-results class="rx-results"></div>
<ol data-rx-stops class="rx-stops"></ol>
<div class="rx-secondary"><button type="button" data-rx-saved><?=e(rxT('saved_trip'))?></button><a href="<?=e(languageUrl('/community/contribute/',siteLanguage()))?>"><?=e(rxT('suggest'))?></a></div>
<p class="rx-help"><?=e(rxT('connection_hint'))?></p><fieldset class="rx-modes"><legend><?=e(rxT('default_mode'))?></legend><?php foreach(['walk'=>'♙','transit'=>'▣','car'=>'▰'] as $mode=>$icon):?><label><input type="radio" name="travel_mode" value="<?=$mode?>" <?=$v('travel_mode','walk')===$mode?'checked':''?>> <span><?=scIcon($mode)?> <?=e(rxT($mode))?></span></label><?php endforeach?></fieldset>
<label class="rx-photo-pick"><?=scIcon('story')?> <?=e(rxT('photos'))?><input class="visually-hidden" type="file" name="photo[]" multiple accept="image/jpeg,image/png,image/webp" data-rx-photos></label><p class="small"><?=e(revT('upload_hint'))?></p><div data-rx-photos-preview class="rx-photos"></div><label class="rx-rights"><input type="checkbox" name="rights"> <?=e(nt('rights'))?></label>
<?php photoDisclosureFields($d);?>
<details class="rx-more"><summary>⚙ <?=e(rxT('more'))?></summary>
<label><input type="radio" name="route_status" value="planned" <?=$v('route_status','planned')==='planned'?'checked':''?>><?=e(rxT('planned'))?></label><label><input type="radio" name="route_status" value="tried" <?=$v('route_status')==='tried'?'checked':''?>><?=e(rxT('tried'))?></label>
<?php cpFactsFields($d);?><div data-rx-visits></div></details>
<div class="sc-editor-footer sc-publication-footer"><label><?=e(scText('Post language','שפת הפרסום','Язык публикации'))?><?=networkPublicationLanguageInput('content_language',$v('language',networkPublicationDefault()))?></label><?php if($u):?><button class="button rx-primary" type="submit"><?=e($editing?seT('save'):rxT('publish'))?></button><?php else:?><a class="button rx-primary" href="<?=e(networkLoginUrl())?>"><?=e(rxT('login'))?></a><?php endif?></div>
<p data-rx-status role="status" aria-live="polite"></p><p data-rx-error role="alert"></p><button type="button" data-rx-retry hidden><?=e(rxT('retry'))?></button>
</form></section>
<?php if($u)scDraftList($u);?>
