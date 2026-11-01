<?php $facts=tdFacts($snapshot['facts']??[]);if(array_filter($facts)):?><aside class="td-shared-facts"><strong><?=e(tdLabels()['at_a_glance'])?></strong><ul>
<?php if(!empty($facts['mode'])):?><li><?=e(tdLabels()['mode'])?>: <?=e($facts['mode']==='mixed'?tdLabels()['mixed']:rxT($facts['mode']))?></li><?php endif?>
<?php if(!empty($facts['duration'])):?><li><?=e(tdLabels()['duration'])?>: <?=e(tdLabels()[$facts['duration']])?></li><?php endif?>
<?php if(isset($facts['minutes'])):?><li><?=e(tdReadableTime($facts['minutes']))?></li><?php endif?>
<?php if(isset($facts['walking_meters'])):?><li><?=e(rxT('distance'))?>: <?=e(rxDistance($facts['walking_meters']))?></li><?php endif?>
<?php foreach($facts['highlights']??[] as $highlight):?><li><?=e(tdLabels()[$highlight])?></li><?php endforeach?>
</ul><?php if(isset($facts['source'])):?><small><?=e(rxT('author'))?></small><?php endif?></aside><?php endif?>
