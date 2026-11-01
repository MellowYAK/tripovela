document.querySelectorAll('.market-gallery').forEach(gallery=>{
 const buttons=[...gallery.querySelectorAll('[data-market-photo]')],image=gallery.querySelector('[data-market-main]'),count=gallery.querySelector('[data-market-count]');let current=0;
 const show=index=>{current=(index+buttons.length)%buttons.length;image.src=buttons[current].dataset.marketPhoto;buttons.forEach((b,i)=>b.setAttribute('aria-pressed',String(i===current)));count.textContent=`${current+1} / ${buttons.length}`;const b=buttons[current],rail=b.parentElement;rail.scrollTo({left:b.offsetLeft-rail.offsetLeft,top:b.offsetTop-rail.offsetTop,behavior:'instant'});};
 buttons.forEach((button,i)=>button.addEventListener('click',()=>show(i)));
 gallery.querySelector('[data-market-prev]').addEventListener('click',()=>show(current-1));
 gallery.querySelector('[data-market-next]').addEventListener('click',()=>show(current+1));
 gallery.addEventListener('keydown',event=>{if(event.key==='ArrowRight'||event.key==='ArrowLeft'){event.preventDefault();show(current+(event.key==='ArrowRight'?1:-1));buttons[current].focus({preventScroll:true});}});
});
