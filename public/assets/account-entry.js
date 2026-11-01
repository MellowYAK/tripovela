(()=>{'use strict';
const root=document.querySelector('[data-account-entry]');if(!root)return;
const modes=['login','register','recover'],forms=[...root.querySelectorAll('form[id]')],links=[...root.querySelectorAll('[data-account-mode]')];
function show(){const hash=location.hash.slice(1),mode=modes.includes(hash)?hash:root.dataset.action;for(const form of forms)form.hidden=form.id!==mode;for(const link of links){if(link.dataset.accountMode===mode)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');}}
addEventListener('hashchange',show);show();
})();
