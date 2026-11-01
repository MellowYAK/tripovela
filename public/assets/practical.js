document.addEventListener('click',e=>{
 const open=e.target.closest('[data-practical-open]');
 if(open){const panel=open.closest('.practical-panel'),dialog=panel.querySelector('dialog');if(typeof dialog.showModal!=='function')return;e.preventDefault();dialog.querySelector('iframe').src=open.href;dialog.showModal();dialog._opener=open;}
 const close=e.target.closest('[data-practical-close]');if(close)close.closest('dialog').close();
 const load=e.target.closest('[data-map-load]');if(load){const frame=document.querySelector('[data-map-frame]');frame.src=load.dataset.mapSrc;frame.hidden=false;load.hidden=true;}
});
document.querySelectorAll('.practical-dialog').forEach(d=>d.addEventListener('close',()=>{d.querySelector('iframe').removeAttribute('src');d._opener?.focus();}));
