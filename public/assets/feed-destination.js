(()=>{'use strict';
 const normalize=s=>String(s).normalize('NFKD').replace(/[\u0300-\u036f\u0591-\u05c7]/g,'').toLocaleLowerCase().replace(/[’'׳״"־–—-]/g,' ').replace(/\s+/g,' ').trim();
 document.querySelectorAll('[data-feed-destination]').forEach((picker,index)=>{
  const rows=JSON.parse(picker.dataset.rows),input=picker.querySelector('input'),list=picker.querySelector('[data-feed-destination-results]');const compose=!!picker.closest('[data-post-destination]');let active=-1;
  list.id='feed-destinations-'+index;list.setAttribute('role','listbox');input.setAttribute('role','combobox');input.setAttribute('aria-controls',list.id);input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-expanded','false');
  function render(){
   const q=normalize(input.value);const matches=rows.filter(r=>q?r.aliases.some(a=>normalize(a).includes(q))&&(!compose||!r.city||!picker.closest('form').elements.country.value||r.country===picker.closest('form').elements.country.value):!r.city);
   list.replaceChildren();active=-1;input.removeAttribute('aria-activedescendant');
   for(const [i,r] of [{country:'',city:'',label:picker.dataset.all,detail:''},...matches].entries()){
    const link=document.createElement('a'),url=new URL(picker.dataset.url,location.origin);url.searchParams.delete('cursor');
    for(const k of ['country','city'])r[k]?url.searchParams.set(k,r[k]):url.searchParams.delete(k);
    link.href=url.pathname+url.search;link.id=list.id+'-'+i;link.setAttribute('role','option');link.setAttribute('aria-selected','false');
    // A destination must use the current selection, not the value captured at page load.
    link.addEventListener('click',e=>{const form=picker.closest('form');if(!form)return;e.preventDefault();for(const k of ['country','city'])form.elements[k].value=r[k]||'';if(compose){const summary=picker.querySelector('summary');summary.querySelector('.network-destination-label').textContent=r.label+(r.city?' · '+r.detail:'');summary.querySelector('img,.network-destination-globe')?.remove();const icon=document.createElement(r.country?'img':'span');if(r.country){icon.src='/assets/flags/'+(r.flag||r.country)+'.svg';icon.width=24;icon.height=16;icon.alt='';}else{icon.className='network-destination-globe';icon.textContent='🌐';}summary.prepend(icon);for(const k of ['country','city'])form.elements[k].dispatchEvent(new Event('change',{bubbles:true}));picker.open=false;}else form.requestSubmit();});
    if(r.country){const img=document.createElement('img');img.src='/assets/flags/'+(r.flag||r.country)+'.svg';img.width=24;img.height=16;img.alt='';link.append(img);}else{const globe=document.createElement('span');globe.className='network-destination-globe';globe.textContent='🌐';globe.setAttribute('aria-hidden','true');link.append(globe);}
    const text=document.createElement('span');text.textContent=r.label+(r.detail?' · '+r.detail:'');link.append(text);list.append(link);
   }
   if(!matches.length){const empty=document.createElement('p');empty.textContent=picker.dataset.empty;list.append(empty);}
  }
  picker.addEventListener('toggle',()=>{input.setAttribute('aria-expanded',String(picker.open));if(picker.open){input.value='';render();input.focus();}});
  input.addEventListener('input',render);
  input.addEventListener('keydown',e=>{
   const choices=[...list.querySelectorAll('[role=option]')];
   if(e.key==='Escape'){e.preventDefault();picker.open=false;picker.querySelector('summary').focus();}
   if(['ArrowDown','ArrowUp'].includes(e.key)&&choices.length){e.preventDefault();active=(active+(e.key==='ArrowDown'?1:-1)+choices.length)%choices.length;choices.forEach((c,i)=>c.setAttribute('aria-selected',String(i===active)));input.setAttribute('aria-activedescendant',choices[active].id);choices[active].scrollIntoView({block:'nearest'});}
   if(e.key==='Enter'){e.preventDefault();if(active>=0)choices[active].click();else if(choices.length===2)choices[1].click();}
  });
  document.addEventListener('click',e=>{if(!picker.contains(e.target))picker.open=false;});
 });
})();
