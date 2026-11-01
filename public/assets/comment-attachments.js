(()=>{'use strict';
const labels=JSON.parse(document.querySelector('#ux-labels')?.textContent||'{}');
const node=(tag,text)=>{const n=document.createElement(tag);if(text)n.textContent=text;return n;};
document.querySelectorAll('[data-comment-attachments]').forEach(root=>{
 const input=root.querySelector('input[type=search]'),results=root.querySelector('[data-attachment-results]'),selected=root.querySelector('[data-attachment-selected]'),status=root.querySelector('[role=status]');
 let controller,timer,sequence=0;const kind=root.dataset.commentAttachments,limit=Number(root.dataset.limit);
 const full=()=>selected.querySelectorAll('input').length>=limit;const update=()=>{input.disabled=full();status.textContent=full()?labels.attachment_full:'';};
 function add(p){if(full()){status.textContent=labels.attachment_full;return;}if([...selected.querySelectorAll('input')].some(i=>i.value===p.id))return;
  const chip=node('div'),hidden=node('input'),remove=node('button','×');chip.className='ux-attachment';hidden.type='hidden';hidden.name=kind==='trip'?'linked_routes[]':'linked_places[]';hidden.value=p.id;remove.type='button';remove.setAttribute('aria-label',labels.remove+' '+p.title);remove.onclick=()=>{chip.remove();update();input.focus();};chip.append(hidden,node('span',p.title),remove);selected.append(chip);input.value='';results.replaceChildren();update();if(!input.disabled)input.focus();
 }
 try{JSON.parse(root.dataset.selected||'[]').forEach(add);}catch{}
 input.addEventListener('input',()=>{if(full()){update();return;}clearTimeout(timer);controller?.abort();const request=++sequence,q=input.value.trim();results.replaceChildren();status.textContent='';if(q.length<2)return;
 timer=setTimeout(async()=>{controller=new AbortController();status.textContent=labels.loading;try{
  const url=new URL('/api/community-places/',location.origin);url.search=new URLSearchParams({lang:document.documentElement.lang,q,type:kind});const response=await fetch(url,{signal:controller.signal,credentials:'same-origin'});const data=await response.json();if(!response.ok||!data.ok)throw Error();if(sequence!==request)return;const rows=kind==='trip'?data.routes:data.places;status.textContent=rows.length?'':labels.no_results;
  for(const p of rows){const b=node('button');b.type='button';if(p.image){const image=node('img');image.src=p.image;image.alt='';image.loading='lazy';b.append(image);}const text=node('span',p.title);text.append(node('small',p.city_label||[p.city,p.country].filter(Boolean).join(' · ')));b.append(text);b.onclick=()=>add(p);results.append(b);}
 }catch(e){if(e.name!=='AbortError'&&sequence===request)status.textContent=labels.error;}},220);});
});
})();
