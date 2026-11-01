<?php
requireAdmin();$checks=[];$checked=gmdate('Y-m-d H:i:s').' UTC';
foreach(['תוכן'=>db(),'קהילה'=>socialDb()] as $label=>$database){try{$result=$database->query('PRAGMA quick_check')->fetchColumn();$checks[]=[$label,$result==='ok'?'תקין':'נדרשת בדיקה','SQLite quick_check: '.$result];}catch(Throwable $ex){$checks[]=[$label,'שגיאה','בדיקת מסד הנתונים נכשלה; יש לעיין ביומן השרת.'];}}
$checks[]=['אחסון פרטי',is_writable(STORAGE_PATH)?'זמין לכתיבה':'שגיאה','בדיקת הרשאות כתיבה לספריית נתוני הריצה'];
$checks[]=['PHP','נמדד',uiMessage('admin.system.version',['version'=>PHP_VERSION]).'; upload_max_filesize='.ini_get('upload_max_filesize').'; post_max_size='.ini_get('post_max_size')];
$checks[]=['קהילה',networkEnabled()?'פרסום פעיל':'פרסום מושהה','הגדרת publishing במסד הנתונים'];
$checks[]=['גיבוי ושחזור','לא נבדק','יש לבצע שחזור נפרד ולתעד תאריך ותוצאה. זמינות מסד הנתונים אינה הוכחת גיבוי.'];
$checks[]=['ביצועים ועומס','לא נבדק','נדרשת מדידה בתנאי רשת וכמות תוכן מוגדרים.'];
?><h2>מצב מערכת — בדיקות מהבקשה הנוכחית</h2><p><span>זמן בדיקה:</span> <time><?=e($checked)?></time>. <span>אחראי לטיפול: מנהל האתר. בדיקה מקומית אינה בדיקת זמינות מכל רשת.</span></p><table><thead><tr><th>רכיב</th><th>תוצאה</th><th>ראיה</th></tr></thead><tbody><?php foreach($checks as [$label,$state,$evidence]):?><tr><td><?=e($label)?></td><td><?=e($state)?></td><td><?=e($evidence)?></td></tr><?php endforeach?></tbody></table><p><a href="/KNISA/model-files/">בדיקת חבילת ייבוא</a> · <a href="/KNISA/community/?view=history#product-metrics">מדדי מוצר</a></p>
