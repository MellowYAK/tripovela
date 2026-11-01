(()=>{'use strict';
const list=document.querySelector('[data-feed-pages]');let next=document.querySelector('[data-feed-next]');if(!list||!next)return;
const seen=new Set([...list.querySelectorAll('[data-feed-item]')].map(x=>x.dataset.feedItem));let busy=false,observer;
async function load(event){event?.preventDefault();if(busy||!next)return;busy=true;const link=next;link.setAttribute('aria-busy','true');observer?.unobserve(link);
try{
 const response=await fetch(link.href,{credentials:'same-origin'});if(!response.ok)throw Error();
 const doc=new DOMParser().parseFromString(await response.text(),'text/html'),incoming=doc.querySelector('[data-feed-pages]');
 if(!incoming||doc.querySelector('meta[name="qa-view"]')?.content!==document.querySelector('meta[name="qa-view"]')?.content)throw Error();
 const added=[];for(const card of [...incoming.children]){const id=card.dataset.feedItem;if(id&&seen.has(id))continue;if(id)seen.add(id);list.append(card);added.push(card);}
 const more=doc.querySelector('[data-feed-next]');next=more;
 if(more){link.replaceWith(more);more.addEventListener('click',load);observer?.observe(more);}else link.remove();
 document.dispatchEvent(new CustomEvent('feed-appended',{detail:{cards:added}}));
}catch{ // Keep a usable manual retry instead of an automatic retry loop.
 next=link;link.removeAttribute('aria-busy');
}finally{busy=false;}
}
next.addEventListener('click',load);
if('IntersectionObserver' in window){observer=new IntersectionObserver(entries=>{if(entries.some(e=>e.isIntersecting))load();},{rootMargin:'350px'});observer.observe(next);}
})();
