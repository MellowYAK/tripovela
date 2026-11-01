(()=>{'use strict';
if(matchMedia('(min-width:701px)').matches)document.querySelectorAll('.td-public-map').forEach(d=>d.open=true);
const labels=JSON.parse(document.querySelector('#directions-labels')?.textContent||'{}');
document.addEventListener('click',e=>{
 if(e.target.closest('.directions-choice'))return;
 const trigger=e.target.closest('[data-rx-directions],[data-sc-directions],a[href*="google.com/maps/dir/"]');if(!trigger)return;
 let target={};try{if(trigger.dataset.rxDirections)target=JSON.parse(trigger.dataset.rxDirections).target;else if(trigger.dataset.scDirections)target=JSON.parse(trigger.dataset.scDirections);else{const card=trigger.closest('article,li');target={title:card?.querySelector('h2,h3,strong')?.textContent||'',address:Array.from(card?.querySelectorAll('p')||[]).at(-1)?.textContent||'',query:new URL(trigger.href).searchParams.get('destination')};}}catch{return;}
 const coords=Array.isArray(target.coords)?target.coords:String(target.query||'').match(/^(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)$/)?.slice(1).map(Number);
 let valid=coords?.length===2&&coords.every(Number.isFinite)&&Math.abs(coords[0])<=90&&Math.abs(coords[1])<=180;
 // Verified official address; both map services snap the published hotel coordinates to a different POI/street.
 if(valid&&Math.abs(coords[0]-48.0740245)<0.00001&&Math.abs(coords[1]-7.3591214)<0.00001&&String(target.title).includes('Colombier')){valid=false;target.address='Hôtel Le Colombier, 7 rue de Turenne, 68000 Colmar, France';}
 const query=valid?coords.join(','):(target.address||target.query||'').trim();if(!query)return;
 e.preventDefault();e.stopImmediatePropagation();const d=document.createElement('dialog');d.className='directions-choice';
 const add=(tag,text)=>{const n=document.createElement(tag);n.textContent=text;d.append(n);return n;};
 add('h2',labels.open).id='directions-title';d.setAttribute('aria-labelledby','directions-title');add('strong',target.title||'').dir='auto';add('p',target.address||query).dir='auto';
 const google=new URL('https://www.google.com/maps/dir/');google.search=new URLSearchParams({api:'1',destination:query});
 const waze=new URL('https://www.waze.com/ul');waze.search=new URLSearchParams({[valid?'ll':'q']:query,navigate:'yes'});
 for(const [name,url]of [['Google Maps',google],['Waze',waze]]){const a=add('a',name);a.className='button';a.href=url.href;a.target='_blank';a.rel='noopener noreferrer';}
 add('small',labels.driving);const cancel=add('button',labels.cancel);cancel.type='button';cancel.className='button secondary';cancel.onclick=()=>d.close();
 d.addEventListener('keydown',e=>{if(e.key!=='Tab')return;const controls=[...d.querySelectorAll('a,button')],first=controls[0],last=controls.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus({preventScroll:true});}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus({preventScroll:true});}});
 const x=scrollX,y=scrollY;d.addEventListener('close',()=>{d.remove();trigger.focus({preventScroll:true});window.scrollTo(x,y);},{once:true});document.body.append(d);d.showModal();cancel.focus({preventScroll:true});
},true);
})();
