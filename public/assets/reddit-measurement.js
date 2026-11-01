(()=>{'use strict';if(window.TripovelaReddit)return;
const n=document.querySelector('#measurement-config');if(!n)return;const c=JSON.parse(n.textContent);let frame=null,active=false,claiming=false;
const send=body=>fetch('/api/measurement/',{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf:c.csrf,...body})}).then(r=>r.ok?r.json():null).catch(()=>null);
function choice(yes,explicit=false){active=yes;
 // Mirror the existing choice so the server can honor it on registration and source capture.
 document.cookie='tripovela_measurement_server='+encodeURIComponent(JSON.stringify({yes,at:Math.floor(Date.now()/1000),version:c.version}))+'; Path=/; Max-Age='+c.days*86400+'; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');
 if(!yes){frame?.remove();frame=null;send({action:'decline'});for(const entry of document.cookie.split(';')){const name=entry.split('=')[0].trim();if(/^_rdt_/.test(name))for(const domain of ['',location.hostname,'.'+location.hostname])document.cookie=name+'=; Max-Age=0; Path=/'+(domain?'; Domain='+domain:'');}return;}
 if(!c.reddit||frame)return;frame=document.createElement('iframe');frame.hidden=true;frame.setAttribute('aria-hidden','true');frame.referrerPolicy='no-referrer';frame.src='/measurement/reddit/'+(c.clickId?'?rdt_cid='+encodeURIComponent(c.clickId):'');document.body.append(frame);
}
window.TripovelaReddit={choice};
addEventListener('message',async e=>{if(!active||!frame||e.source!==frame.contentWindow||e.origin!==location.origin||e.data?.type!=='tripovela-reddit-ready'||claiming||!c.registrationEvent)return;claiming=true;const r=await send({action:'claim',event:c.registrationEvent});if(active&&frame&&r?.event)frame.contentWindow.postMessage({type:'tripovela-reddit-signup',id:r.event},location.origin);});
// Consent is driven by measurement.js, not a second independent preference system.
})();
