/* Local dictionary substitution only. Never translate authored community text. */
(()=>{'use strict';
 const config=document.querySelector('#site-dictionary');if(!config)return;
 const dictionary=JSON.parse(config.dataset.dictionary||'{}'),lang=document.documentElement.lang;
 const normalize=s=>String(s).replace(/\s+/g,' ').trim();
 const text=s=>{const key=normalize(s);return Object.hasOwn(dictionary,key)?String(s).replace(/\S[\s\S]*\S|\S/,dictionary[key]):s;};
 window.TripovelaI18n={text,choose:values=>{if(!['he','en','ru','ka'].includes(lang)&&Object.hasOwn(dictionary,normalize(values[1]||'')))return text(values[1]);return Object.hasOwn(dictionary,normalize(values[0]))?text(values[0]):text(values[{he:0,en:1,ru:2,ka:3}[lang]??1]||values[1]||'');}};
 if(['he','en','ru','ka'].includes(lang))return;
 const skip=el=>!el||el.closest('script,style,textarea,pre,code,.network-card-text,.network-author-link,.network-comment p,.network-pending-private li,[data-user-content]')||el.closest('[lang]')?.getAttribute('lang')!==lang&&el.closest('[lang]')!==document.documentElement;
 function apply(root){
  if(root.nodeType===Node.TEXT_NODE){if(!skip(root.parentElement)){let result=text(root.nodeValue);if(result!==root.nodeValue)root.nodeValue=result;}return;}
  if(root.nodeType!==Node.ELEMENT_NODE||skip(root))return;
  for(const attr of ['placeholder','title','aria-label','alt'])if(root.hasAttribute(attr)){let value=root.getAttribute(attr),result=text(value);if(result!==value)root.setAttribute(attr,result);}
  for(const child of root.childNodes)apply(child);
 }
 apply(document.body);
 const observer=new MutationObserver(records=>{observer.disconnect();for(const r of records){if(r.type==='childList')r.addedNodes.forEach(apply);else apply(r.target);}observer.observe(document.body,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['placeholder','title','aria-label','alt']});});
 observer.observe(document.body,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['placeholder','title','aria-label','alt']});
})();
