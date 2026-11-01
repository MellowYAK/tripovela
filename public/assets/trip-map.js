(()=>{'use strict';
const labels=JSON.parse(document.querySelector('#trip-map-labels')?.textContent||'{}'),t=k=>labels[k]||k;
const el=(tag,text)=>{const n=document.createElement(tag);if(text)n.textContent=text;return n;};
const valid=c=>Array.isArray(c)&&c.length===2&&c.every(Number.isFinite)&&Math.abs(c[0])<=90&&Math.abs(c[1])<=180;
function mount(node,raw,kind='route'){
 if(node.tripMap)return node.tripMap;
 const points=raw.map((p,n)=>({...p,number:p.number||n+1})).filter(p=>valid(p.coords));
 const wrap=el('div');wrap.className='tm-wrap';node.before(wrap);wrap.append(node);
 const total=Number(node.dataset.mapTotal)||raw.length;if(total){const partial=el('p',t(points.length<total?'partial':'full').replace('{shown}',points.length).replace('{total}',total));partial.className=points.length<total?'tm-partial':'tm-complete';wrap.append(partial);}
 const status=el('p');status.className='tm-status';status.setAttribute('role','status');wrap.append(status);
 if(!points.length||!window.L){status.textContent=t(points.length?'map_error':'location_missing');return null;}
 const controls=el('div');controls.className='tm-controls';const expand=el('button',t('expand'));expand.type='button';controls.append(expand);wrap.prepend(controls);
 if(kind==='route')wrap.append(el('small',t('schematic')));
 const details=node.closest('details');if(details&&matchMedia('(min-width:701px)').matches)details.open=true;
 const map=L.map(node,{scrollWheelZoom:false});map.attributionControl.setPrefix(false);
 L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,referrerPolicy:'strict-origin-when-cross-origin',attribution:'© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'}).on('tileerror',()=>{status.textContent=t('map_error');}).addTo(map);
 const markers=new Map();points.forEach(p=>{
  const box=el('div'),name=el('strong',(kind==='route'?p.number+'. ':'')+p.title);box.append(name);
  const target=p.target||('stop-'+(p.item_id||p.id));const section=document.getElementById(target);
  if(section){const link=el('a',t('view_stop'));link.href='#'+target;link.onclick=e=>{e.preventDefault();const go=()=>{section.closest('[data-ts-panel]')?.classList.remove('ts-mobile-hidden');section.scrollIntoView({block:'center',behavior:'instant'});section.tabIndex=-1;section.focus({preventScroll:true});};if(dialog?.open){dialog.addEventListener('close',go,{once:true});dialog.close();}else go();};box.append(el('br'),link);}
  const marker=L.marker(p.coords,{title:p.title,alt:(kind==='route'?p.number+'. ':'')+p.title,icon:L.divIcon({className:'rx-map-pin',html:kind==='route'?String(p.number):'•',iconSize:[30,30],iconAnchor:[15,15]})}).addTo(map).bindPopup(box);markers.set(target,marker);
 });
 // This is order only, never a walking path or a source of distance/time estimates.
 if(kind==='route'&&points.length>1)L.polyline(points.map(p=>p.coords),{color:'#007f6d',weight:3,dashArray:'7 9',interactive:false}).addTo(map);
 const fit=()=>{map.invalidateSize();map.fitBounds(points.map(p=>p.coords),{padding:[40,40],maxZoom:15,animate:false});};
 let dialog=null;expand.onclick=()=>{
  const focus=document.activeElement,x=scrollX,y=scrollY,body=document.body,old=body.style.cssText;
  dialog=el('dialog');dialog.className='tm-dialog';dialog.setAttribute('aria-label',t('map'));const head=el('header'),title=el('h2',t('map')),close=el('button','×');close.type='button';close.setAttribute('aria-label',t('close'));head.append(title,close);
  const slot=el('div');slot.className='tm-slot';wrap.before(slot);dialog.append(head,wrap);document.body.append(dialog);
  Object.assign(body.style,{position:'fixed',top:-y+'px',left:-x+'px',right:'0',width:'100%',overflow:'hidden'});
  expand.hidden=true;close.onclick=()=>dialog.close();dialog.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();dialog.close();}if(e.key==='Tab'){const items=[...dialog.querySelectorAll('button,a[href],[tabindex="0"]')].filter(n=>!n.disabled&&!n.hidden&&n.getClientRects().length),first=items[0],last=items.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
  dialog.addEventListener('close',()=>{slot.replaceWith(wrap);dialog.remove();body.style.cssText=old;expand.hidden=false;focus?.focus({preventScroll:true});window.scrollTo({left:x,top:y,behavior:'instant'});map.invalidateSize();},{once:true});dialog.showModal();close.focus();requestAnimationFrame(fit);
 };
 node.tripMap={map,fit,locate:id=>{const marker=markers.get(id);if(!marker)return;const details=node.closest('details');if(details)details.open=true;node.closest('[data-ts-panel]')?.classList.remove('ts-mobile-hidden');requestAnimationFrame(()=>{map.invalidateSize();map.stop();map.setView(marker.getLatLng(),Math.max(map.getZoom(),14),{animate:false});marker.openPopup();node.scrollIntoView({block:'center',behavior:'instant'});});}};
 node.closest('details')?.addEventListener('toggle',()=>{if(node.closest('details').open)fit();});// Fit only when the actual container size changes, never on pan or zoom.
 let size='',resizePending=false;new ResizeObserver(entries=>{
  const r=entries[0]?.contentRect;if(!r||r.width<=0||r.height<=0)return;
  const next=r.width+':'+r.height;if(next===size)return;size=next;
  if(!resizePending){resizePending=true;requestAnimationFrame(()=>{resizePending=false;fit();});}
 }).observe(node);requestAnimationFrame(fit);return node.tripMap;
}
window.TripMap={mount,valid};
document.querySelectorAll('[data-rx-map]').forEach(node=>{try{mount(node,JSON.parse(node.dataset.rxMap),node.dataset.rxCollection==='1'?'collection':'route');}catch{node.textContent=t('map_error');}});
document.addEventListener('click',e=>{const b=e.target.closest('[data-map-stop]');if(!b)return;for(const node of document.querySelectorAll('[data-rx-map],[data-ts-map]'))if(node.tripMap)node.tripMap.locate(b.dataset.mapStop);});
})();
