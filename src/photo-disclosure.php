<?php
// Public image attribution, stored with the publication and its draft.
function photoLabels(): array {
 $rows=[
 'en'=>['Image credits and AI label','Photographer / creator','Image source','License / permission','Contains AI-generated images','AI image','Optional credits apply to the uploaded images in this post. Mark this if any uploaded image was generated with AI.'],
 'he'=>['קרדיט לתמונות וסימון AI','שם הצלם / היוצר','מקור התמונות','רישיון / אישור שימוש','כולל תמונות שנוצרו ב־AI','תמונת AI','הקרדיט האופציונלי חל על התמונות שהעליתם לפוסט. סמנו אם אחת מהן נוצרה באמצעות AI.'],
 'ru'=>['Авторство изображений и отметка ИИ','Фотограф / автор','Источник изображений','Лицензия / разрешение','Содержит изображения, созданные ИИ','Изображение ИИ','Необязательные сведения относятся к загруженным изображениям. Отметьте, если хотя бы одно создано с помощью ИИ.'],
 'ka'=>['სურათების ავტორობა და AI ნიშანი','ფოტოგრაფი / ავტორი','სურათების წყარო','ლიცენზია / ნებართვა','შეიცავს AI-ით შექმნილ სურათებს','AI სურათი','არასავალდებულო ინფორმაცია ეხება ატვირთულ სურათებს. მონიშნეთ, თუ რომელიმე მათგანი AI-ით შეიქმნა.'],
 'es'=>['Créditos y etiqueta de IA','Fotógrafo / creador','Fuente de las imágenes','Licencia / permiso','Contiene imágenes generadas con IA','Imagen de IA','Los créditos opcionales se aplican a las imágenes subidas. Marca la casilla si alguna se generó con IA.'],
 'de'=>['Bildnachweise und KI-Kennzeichnung','Fotograf / Urheber','Bildquelle','Lizenz / Erlaubnis','Enthält KI-generierte Bilder','KI-Bild','Die optionalen Angaben gelten für die hochgeladenen Bilder. Markiere dies, wenn eines davon mit KI erstellt wurde.'],
 'fr'=>['Crédits et mention IA','Photographe / créateur','Source des images','Licence / autorisation','Contient des images générées par IA','Image IA','Les crédits facultatifs concernent les images importées. Cochez si une image a été créée par IA.'],
 'it'=>['Crediti e indicazione IA','Fotografo / autore','Fonte delle immagini','Licenza / permesso','Contiene immagini generate con IA','Immagine IA','I crediti facoltativi si applicano alle immagini caricate. Seleziona se almeno una è stata generata con IA.'],
 'ar'=>['حقوق الصور وعلامة الذكاء الاصطناعي','المصور / المنشئ','مصدر الصور','الترخيص / الإذن','يتضمن صورًا مولّدة بالذكاء الاصطناعي','صورة بالذكاء الاصطناعي','تنطبق المعلومات الاختيارية على الصور المرفوعة. حدد الخيار إذا كانت أي صورة مولّدة بالذكاء الاصطناعي.'],
 'cs'=>['Autorství snímků a označení AI','Fotograf / autor','Zdroj snímků','Licence / svolení','Obsahuje snímky vytvořené AI','Snímek AI','Volitelné údaje platí pro nahrané snímky. Zaškrtněte, pokud byl některý vytvořen pomocí AI.'],
 'hu'=>['Képek forrása és MI-jelölés','Fotós / alkotó','Képek forrása','Licenc / engedély','MI-vel készített képeket tartalmaz','MI-kép','Az opcionális adatok a feltöltött képekre vonatkoznak. Jelöld be, ha valamelyik MI-vel készült.'],
 'nl'=>['Beeldcredits en AI-label','Fotograaf / maker','Afbeeldingsbron','Licentie / toestemming','Bevat AI-gegenereerde afbeeldingen','AI-afbeelding','De optionele credits gelden voor de geüploade afbeeldingen. Vink aan als er een met AI is gemaakt.'],
 'pl'=>['Autorstwo zdjęć i oznaczenie AI','Fotograf / autor','Źródło zdjęć','Licencja / zgoda','Zawiera obrazy wygenerowane przez AI','Obraz AI','Opcjonalne informacje dotyczą przesłanych zdjęć. Zaznacz, jeśli którekolwiek zostało wygenerowane przez AI.'],
 'pt'=>['Créditos e etiqueta de IA','Fotógrafo / criador','Fonte das imagens','Licença / autorização','Contém imagens geradas por IA','Imagem de IA','Os créditos opcionais aplicam-se às imagens carregadas. Assinale se alguma foi gerada por IA.']
 ];return $rows[siteLanguage()]??$rows['en'];
}
function photoMetadata(array $raw): array {
 return ['photo_author'=>socialString($raw['photo_author']??'',180),'photo_source'=>socialString($raw['photo_source']??'',500),'photo_license'=>socialString($raw['photo_license']??'',200),'photo_illustration'=>in_array($raw['photo_illustration']??false,[true,1,'1','on'],true)];
}
function photoDisclosureFields(array $doc=[]): void {
 $l=photoLabels();$values=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:$doc;
 echo '<details class="photo-disclosure-fields"><summary>'.e($l[0]).'</summary><p class="small">'.e($l[6]).'</p>';
 foreach(['photo_author'=>[1,180],'photo_source'=>[2,500],'photo_license'=>[3,200]] as $key=>$info)echo '<label>'.e($l[$info[0]]).'<input type="text" name="'.$key.'" maxlength="'.$info[1].'" value="'.e(is_string($values[$key]??null)?$values[$key]:'').'"></label>';
 echo '<label class="photo-ai-choice"><input type="checkbox" name="photo_illustration" value="1" '.(!empty($values['photo_illustration'])?'checked':'').'> '.e($l[4]).'</label></details>';
}
function photoAiBadge(bool $collection=false): string {return '<span class="photo-ai-badge" title="'.e(photoLabels()[4]).'"><span aria-hidden="true">✧</span> '.e(photoLabels()[$collection?4:5]).'</span>';}
function photoDisclosure(array $p): void {
 if(empty($p['photos'])&&empty($p['photo']))return;
 if(!empty($p['photo_illustration']))echo photoAiBadge(true);
 $l=photoLabels();$parts=[];foreach(['photo_author'=>1,'photo_source'=>2,'photo_license'=>3] as $key=>$index)if(!empty($p[$key]))$parts[]=$l[$index].': '.$p[$key];
 if($parts)echo '<small class="sc-credit" dir="auto">'.e(implode(' · ',$parts)).'</small>';
}
