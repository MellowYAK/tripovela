(()=>{'use strict';
const cfg=document.querySelector('#community-config');if(!cfg)return;
const lang=cfg.dataset.lang,ix={he:0,en:1,ru:2,ka:3}[lang]??1;
const labels={hint:['JPG, PNG או WebP, עד 20MB לתמונה ועד 5 תמונות. התמונות יוקטנו אוטומטית לפני העלאה.','JPG, PNG or WebP, up to 20 MB each and 5 images. Images are compressed before upload.','JPG, PNG или WebP, до 20 МБ и 5 изображений. Перед загрузкой фотографии сжимаются.','JPG, PNG ან WebP, თითოეული 20 მბ-მდე, მაქსიმუმ 5 სურათი. ატვირთვამდე სურათები იკუმშება.'],busy:['מקטינים תמונות…','Compressing images…','Сжимаем фотографии…','სურათები იკუმშება…'],error:['לא ניתן להכין את התמונה. בחרו JPG, PNG או WebP עד 20MB ו־20 מיליון פיקסלים, או הקטינו אותה ונסו שוב. הטופס לא נמחק.','Could not prepare this image. Choose JPG, PNG or WebP up to 20 MB and 20 megapixels, or resize it and retry. Your form is preserved.','Не удалось обработать фото. Выберите JPG, PNG или WebP до 20 МБ и 20 мегапикселей либо уменьшите файл. Данные формы сохранены.','სურათი ვერ დამუშავდა. აირჩიეთ JPG, PNG ან WebP 20 მბ-მდე და 20 მეგაპიქსელამდე, ან შეამცირეთ ფაილი. ფორმის მონაცემები შენარჩუნებულია.']};
const text=k=>window.TripovelaI18n.choose(labels[k]);
const uploadLabels=JSON.parse(document.querySelector('#ux-labels')?.textContent||'{}');
async function compress(file){
 if(!['image/jpeg','image/png','image/webp'].includes(file.type))throw Error(uploadLabels.upload_type);if(file.size>20000000)throw Error(uploadLabels.upload_size);
 const img=await createImageBitmap(file);try{
  if(img.width*img.height>20000000)throw Error(uploadLabels.upload_dimensions);
  const scale=Math.min(1,1800/Math.max(img.width,img.height)),canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(img.width*scale));canvas.height=Math.max(1,Math.round(img.height*scale));const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(img,0,0,canvas.width,canvas.height);
  let blob;for(const quality of [.84,.72,.6,.45]){blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',quality));if(blob&&blob.size<=1000000)break;}
  if(!blob||blob.size>5000000)throw Error();return new File([blob],file.name.replace(/\.[^.]*$/,'')+'.jpg',{type:'image/jpeg'});
 }finally{img.close();}
}
window.TripovelaCompressImage=compress;
document.querySelectorAll('form[enctype="multipart/form-data"]').forEach(form=>{
 if(form.matches('[data-route-composer]'))return;
 const inputs=[...form.querySelectorAll('input[type=file][name^="photo"]')];if(!inputs.length)return;
 const note=document.createElement('p');note.textContent=inputs[0].hasAttribute('data-profile-photo')?JSON.parse(document.querySelector('#gt-labels').textContent).avatar_hint:uploadLabels.upload_hint;note.className='small';inputs[0].after(note);const status=document.createElement('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');note.after(status);let busy=false,ready=false;
 if(form.matches('[data-sc-editor]'))return;
 form.addEventListener('submit',async e=>{
  if(ready)return;const count=inputs.reduce((n,input)=>n+input.files.length,0);if(!count)return;e.preventDefault();if(busy)return;busy=true;form.setAttribute('aria-busy','true');status.textContent=text('busy');
  try{if(count>5)throw Error();const replacements=[];for(const input of inputs){const dt=new DataTransfer();for(const file of input.files)dt.items.add(await compress(file));replacements.push([input,dt.files]);}for(const [input,files] of replacements)input.files=files;ready=true;form.requestSubmit(e.submitter||undefined);}
  catch(error){status.textContent=Object.values(uploadLabels).includes(error.message)?error.message:text('error');status.setAttribute('role','alert');}finally{busy=false;form.removeAttribute('aria-busy');}
 });
});
const hints=()=>document.querySelectorAll('input[type=file][accept*="image/"]').forEach(input=>{if(input.dataset.uploadHint)return;input.dataset.uploadHint='1';const form=input.closest('form');if(form&&!form.matches('[data-route-composer]')&&form.enctype==='multipart/form-data')return;if(form?.matches('[data-route-composer]'))return;const hint=document.createElement('small');hint.textContent=uploadLabels.upload_hint;hint.className='upload-constraints';input.after(hint);});hints();new MutationObserver(hints).observe(document.body,{childList:true,subtree:true});
})();
