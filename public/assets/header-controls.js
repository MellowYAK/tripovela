document.querySelector('[data-language-url]')?.addEventListener('change',event=>{
  const target=new URL(event.target.value,location.href);
  if(target.origin===location.origin){
    // Discovery state changes without a document reload; use the current URL.
    const current=new URL(location.href);
    current.searchParams.set('lang',target.searchParams.get('lang')||'he');
    current.searchParams.delete('currency');
    current.searchParams.set('switch_lang','1');
    location.assign(current.href);
  }
});

// Keep language choices inside the visible viewport in both LTR and RTL.
document.querySelectorAll('details.header-language').forEach(picker=>{
 const menu=picker.querySelector('.header-language-options'),trigger=picker.querySelector('summary');
 if(!menu||!trigger)return;
 const place=()=>{
  if(!picker.open)return;
  const viewport=window.visualViewport,w=viewport?.width||innerWidth,h=viewport?.height||innerHeight;
  const ox=viewport?.offsetLeft||0,oy=viewport?.offsetTop||0,rect=trigger.getBoundingClientRect();
  const width=Math.min(260,w-24),left=Math.max(ox+12,Math.min(rect.left,ox+w-width-12));
  const top=Math.min(Math.max(oy+12,rect.bottom+6),oy+h-100);
  Object.assign(menu.style,{position:'fixed',insetInlineStart:'auto',insetInlineEnd:'auto',right:'auto',bottom:'auto',left:left+'px',top:top+'px',width:width+'px',minWidth:'0',boxSizing:'border-box',maxHeight:Math.max(72,Math.min(480,oy+h-top-80))+'px',overflowY:'auto',overscrollBehavior:'contain'});
 };
 picker.addEventListener('toggle',place);
 addEventListener('resize',place);addEventListener('scroll',place,{passive:true});
 window.visualViewport?.addEventListener('resize',place);window.visualViewport?.addEventListener('scroll',place);
 document.addEventListener('click',e=>{if(picker.open&&!picker.contains(e.target))picker.open=false;});
 picker.addEventListener('keydown',e=>{if(e.key==='Escape'){picker.open=false;trigger.focus();}});
});
