(()=>{'use strict';
const list=document.querySelector('[data-feed-pages]');let next=document.querySelector('[data-feed-next]');if(!list||!next)return;
const seen=new Set([...list.querySelectorAll('[data-feed-item]')].map(x=>x.dataset.feedItem));let busy=false;
async function load(event){event?.preventDefault();if(busy||!next)return;busy=true;const link=next;link.setAttribute('aria-busy','true');
try{
 const response=await fetch(link.href,{credentials:'same-origin'});if(!response.ok)throw Error();
 const doc=new DOMParser().parseFromString(await response.text(),'text/html'),incoming=doc.querySelector('[data-feed-pages]');
 if(!incoming||doc.querySelector('meta[name="qa-view"]')?.content!==document.querySelector('meta[name="qa-view"]')?.content)throw Error();
 const added=[];for(const card of [...incoming.children]){const id=card.dataset.feedItem;if(id&&seen.has(id))continue;if(id)seen.add(id);list.append(card);added.push(card);}
 const more=doc.querySelector('[data-feed-next]');next=more;
 if(more){link.replaceWith(more);more.addEventListener('click',load);}else link.remove();
 document.dispatchEvent(new CustomEvent('feed-appended',{detail:{cards:added}}));
 const first=added[0]?.querySelector('h2 a,a');if(first)first.focus({preventScroll:true});
}catch{ // Keep a usable manual retry instead of an automatic retry loop.
 next=link;link.removeAttribute('aria-busy');
}finally{busy=false;}
}
next.addEventListener('click',load);
// Loading is deliberately explicit: the footer must remain reachable.
})();
