(() => {
  document.querySelectorAll('.social-page > h2').forEach(h=>{let count=0,n=h.nextElementSibling;while(n&&n.tagName!=='H2'){if(n.matches('article.social-card'))count++;n=n.nextElementSibling;}if(/לבדיקה|ממתינים|ערעורים|דיווחים|פרסומים בקהילה/.test(h.textContent)){h.append(' ('+count+')');if(!count){const p=document.createElement('p');p.textContent='אין פריטים שממתינים לבדיקה באזור זה.';h.after(p);}}});
  const notice = document.getElementById('moderation-status');
  if (notice) {
    const next = document.querySelector('[data-review-pending]');
    if (next) next.prepend(notice);
    else notice.querySelector('[data-review-next-message]').textContent = 'אין עוד פריטים שממתינים לאישור בתור התוכן.';
    requestAnimationFrame(() => {
      notice.style.scrollMarginTop = '110px';
      notice.focus({preventScroll:true});
      notice.scrollIntoView({block:'start'});
    });
  }
  document.querySelectorAll('.social-page form').forEach(form => {
    form.addEventListener('submit', event => {
      if (form.dataset.submitting) { event.preventDefault(); return; }
      form.dataset.submitting = '1';
      if (event.submitter) {
        event.submitter.textContent = 'שומר…';
        event.submitter.setAttribute('aria-disabled','true');
      }
      form.setAttribute('aria-busy','true');
    });
  });
})();
