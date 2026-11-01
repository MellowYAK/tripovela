(()=>{'use strict';
const config=JSON.parse(document.querySelector('#community-config').dataset.languagePicker),normalize=s=>String(s).normalize('NFKD').replace(/[\u0300-\u036f\u0591-\u05c7]/g,'').toLocaleLowerCase();
let names;try{names=new Intl.DisplayNames([document.documentElement.lang],{type:'language'});}catch{}
function init(scope=document){scope.querySelectorAll('[data-publication-language]').forEach(select=>{
 if(select.dataset.enhanced)return;select.dataset.enhanced='1';
 const root=select.parentElement,details=document.createElement('details'),summary=document.createElement('summary'),menu=document.createElement('div'),search=document.createElement('input'),empty=document.createElement('p');
 details.className='publication-language-menu';menu.className='publication-language-options';search.type='search';search.placeholder=config.labels.search;search.setAttribute('aria-label',config.labels.search);search.autocomplete='off';empty.textContent=config.labels.empty;empty.setAttribute('role','status');empty.hidden=true;menu.append(search);
 const label=select.closest('label')?.firstChild?.textContent?.trim()||select.name;summary.setAttribute('aria-label',label);summary.setAttribute('aria-haspopup','true');
 function display(option){let local;try{local=names?.of(option.value);}catch{}return local&&local!==option.value&&local.toLocaleLowerCase()!==option.textContent.toLocaleLowerCase()?local+' · '+option.textContent:option.textContent;}
 function contents(target,option){target.replaceChildren();if(option.dataset.flag){const img=document.createElement('img');img.src='/assets/flags/'+option.dataset.flag+'.svg';img.width=24;img.height=16;img.alt='';target.append(img);}else{const icon=document.createElement('span');icon.textContent='🌐';icon.setAttribute('aria-hidden','true');target.append(icon);}const text=document.createElement('bdi');text.textContent=display(option);target.append(text);}
 const buttons=[];for(const option of select.options){const button=document.createElement('button');button.type='button';button.dataset.value=option.value;contents(button,option);button.addEventListener('click',event=>{event.preventDefault();select.value=option.value;details.open=false;select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true}));if(summary.isConnected)summary.focus();});menu.append(button);buttons.push(button);}menu.append(empty);
 function filter(){const q=normalize(search.value);buttons.forEach(b=>{b.hidden=!normalize(b.textContent+' '+b.dataset.value).includes(q);});empty.hidden=buttons.some(b=>!b.hidden);}
 search.addEventListener('input',filter);
 function sync(){const option=select.selectedOptions[0];if(!option)return;contents(summary,option);const arrow=document.createElement('span');arrow.className='publication-language-arrow';arrow.textContent='⌄';arrow.setAttribute('aria-hidden','true');summary.append(arrow);buttons.forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.value===select.value)));}
 details.append(summary,menu);root.append(details);select.hidden=true;sync();select.addEventListener('change',sync);select.form?.addEventListener('reset',()=>setTimeout(sync,0));
 details.addEventListener('toggle',()=>{if(details.open){sync();search.value='';filter();const rect=summary.getBoundingClientRect();details.classList.toggle('opens-up',innerHeight-rect.bottom<280&&rect.top>innerHeight-rect.bottom);search.focus();}});
 details.addEventListener('keydown',event=>{if(event.key==='Escape'){details.open=false;summary.focus();event.preventDefault();}const choices=buttons.filter(b=>!b.hidden);if(['ArrowDown','ArrowUp'].includes(event.key)&&choices.length){event.preventDefault();if(!details.open){details.open=true;return;}const i=choices.indexOf(document.activeElement);choices[event.key==='ArrowDown'?(i+1)%choices.length:(i<=0?choices.length-1:i-1)].focus();}if(event.key==='Enter'&&event.target===search){event.preventDefault();if(choices.length===1)choices[0].click();}});
 });}
window.TripovelaLanguagePicker=init;init();
document.addEventListener('click',event=>document.querySelectorAll('.publication-language-menu[open]').forEach(d=>{if(!d.parentElement.contains(event.target))d.open=false;}));

})();
