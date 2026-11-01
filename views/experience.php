<?php
// This route contains an original Tripovela editorial guide, not just an API record.
contentIndexMark('article');
$meta=$article['_meta'];$item=null;foreach(tripCatalog() as $choice)if(($choice['product']??'')===$meta['product_code']){$item=$choice;break;}
if($item){$title=providerText($item['product'],'title')?:$item['title'];require ROOT.'/views/activity-market.php';}
?>
<?php if(siteLanguage()===$article['_lang']):?><section class="market-editorial" translate="no" lang="<?=e($article['_lang'])?>" dir="<?=$article['_lang']==='he'?'rtl':'ltr'?>"><details><summary><?=e(languagePhrase('המדריך של Tripovela לחוויה','Tripovela’s guide to this experience','Путеводитель Tripovela по этой экскурсии'))?></summary><div class="article-body"><?=experienceTokens(md(preg_replace('/\{\{VIATOR_(?:PRICE:[A-Za-z0-9]+|LINK:[A-Za-z0-9]+:top)\}\}/','',$article['body'])))?></div></details></section><?php endif?>
