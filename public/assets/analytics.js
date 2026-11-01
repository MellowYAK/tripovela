'use strict';
(()=>{
 const token=document.querySelector('meta[name="visit-token"]')?.content;
 const csrf=document.querySelector('meta[name="visit-csrf"]')?.content;
 if(!token||!csrf)return;
 const send=(kind,values={})=>{const data=new URLSearchParams({csrf,page_token:token,kind,...values});fetch('/api/analytics/',{method:'POST',body:data,credentials:'same-origin',keepalive:true}).catch(()=>{});};
 document.addEventListener('tripovela:event',e=>{const {kind,target}=e.detail||{};if(['trip_item_added','trip_day_assigned','trip_returned','explore_viewed','filters_applied'].includes(kind))send(kind,{target:target||''});});
 if('IntersectionObserver' in window){const seen=new Set();const observer=new IntersectionObserver(entries=>{for(const entry of entries)if(entry.isIntersecting&&entry.intersectionRatio>=.5){const product=entry.target.dataset.product;if(!seen.has(product)){seen.add(product);send('card_view',{product});}observer.unobserve(entry.target);}},{threshold:.5});document.querySelectorAll('.activity-card[data-product]').forEach(card=>observer.observe(card));}
 let visible=0,last=performance.now(),sent=false;
 const tick=()=>{const now=performance.now();if(!document.hidden)visible+=Math.min(now-last,1500);last=now;if(!sent&&visible>=10000){sent=true;send('engaged');clearInterval(timer);}};
 const timer=setInterval(tick,1000);document.addEventListener('visibilitychange',()=>{last=performance.now();});
 document.addEventListener('click',event=>{
  if(!event.isTrusted)return;const el=event.target.closest('a,button');if(!el||el.matches('[data-affiliate]'))return;
  if(el.dataset.productDetail){send('detail_open',{product:el.dataset.productDetail});return;}
  let target=el.id?'#'+el.id:'button';if(el.tagName==='A'){try{const u=new URL(el.href,location.href);if(u.origin!==location.origin)return;target=u.pathname;}catch{return;}}
  const position=(el.getBoundingClientRect().top+scrollY)/Math.max(document.documentElement.scrollHeight,1);
  const section=el.closest('header')?'header':el.closest('footer')?'footer':position<.33?'top':position<.67?'middle':'bottom';
  const index=Array.from(document.querySelectorAll('a,button')).indexOf(el);
  send('click',{target,placement:section+':'+index});
 },{capture:true});
})();
