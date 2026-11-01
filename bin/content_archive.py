"""Private, model-ready exports. No credentials, visitor data or network calls."""
import base64,copy,io,json,zipfile,subprocess,tempfile,os
from pathlib import Path
from collections import Counter
ROOT=Path(__file__).resolve().parents[1]

README='''# חבילת תוכן Tripovela — למודל מתרגם / עורך

## מתחילים כאן
- `editorial/ALL.json`: כל רשומות העריכה בקובץ אחד, באותו פורמט שמערכת הייבוא מקבלת.
- `editorial/by-type/`: מאמרים, מקומות, חוויות, יעדים וקטגוריות בנפרד.
- `editorial/items/`: קובץ נפרד לכל פריט. אפשר להחזיר רק את הקובץ שהשתנה.
- `supplier/ALL.json` ו־`supplier/items/`: פרטי מוצר מקוריים של Viator ותרגומיהם. זהו פורמט נפרד, לא מאמר מקורי.
- `coverage.json` / `coverage.csv`: חוסרים בשדות של רשומת העריכה. חסר כאן אינו בהכרח חסר באתר: חלק מהמאמרים מתורגמים דרך מילון מקומי. עיינו במקורות להשוואה.
- `reference/`: תוכן מקור קיים, תצוגת תרגומים ממילון, מדיה וטקסונומיה. לעיון בלבד, לא לייבוא.
- `templates/`: דוגמאות למאמר, מקום וחוויה חדשים והוראות לקבלת מזהה חדש מהמערכת.

## הנחיות למודל
תרגמו או תקנו רק את השפה והשדות שהתבקשתם לערוך. שמרו את כל שאר השפות. אל תמחקו שדות חסרים או תמציאו פרטים. החזירו JSON תקין בקידוד UTF-8, ללא הסברים בתוך הקובץ.
ברשומות העריכה ניתן לערוך את `editorial.translations[קוד_שפה]`: title, summary, body_markdown, seo_description, image_alt. אין לשנות slug קיים. שימרו מבנה Markdown, מזהים, tokens וקישורים. השתמשו ב־translation_status=human_review_required.
אין לשנות manifest, assets, id, record_type, operation, base_revision או context. תמונות נשלחות כהפניות, לא כקובצי תמונה. אין להמציא קרדיט או רישיון.
מידע חסר על מחיר, זמינות, גיל, שפת סיור, כתובת או תנאי כניסה אינו הזמנה להשלמת השערות. אין להמיר מחיר לטקסט קבוע. אל תתרגמו מזהי מוצר, כתובות וקישורי אפילייט. אין להוסיף סקריפטים או HTML.
ברשומות supplier אין לשנות source/source_hash/product_code; יש לשמור על מפתחות השדות ומספר איברי הרשימות. תרגום שפת האתר אינו משנה את שפת ההדרכה.

## החזרה למערכת
בניהול: ייבוא/ייצוא → בדיקה ותצוגת שינויים → אישור שמירת טיוטות. הפרסום נפרד. ייבוא מקביל של ALL ושל קבצים בודדים מאותה גרסה עלול להיתקל בהתנגשות: החזירו פעם אחת את הקבצים שנערכו. אם התוכן השתנה מאז הייצוא יש להוציא גרסה חדשה ולשלב את התיקון.
קובצי ספק מוחזרים בנפרד למנגנון provider_translation.py import (קודם ללא --apply לבדיקה). הם אינם קובצי editorial.
שפות היעד רשומות ב־manifest.locales וניתן להוסיף שפה בניהול. הוספת קוד שפה חדש לקובץ לבדו אינה מפעילה שפה באתר.

## תוכן חדש
אין להחליף מזהה של פריט קיים. השתמשו בניהול בכפתור 'תבנית חדשה להורדה', בחרו סוג וכמות. המערכת מקצה מזהים חדשים ומורידה קובץ שמוכן למילוי ולהחזרה. הדוגמאות שב־templates הן ללימוד המבנה בלבד ולא ניתן לייבא אותן ישירות. לא נוצר פרסום אוטומטי.
למקום/חוויה: בחרו destination_ids/category_ids אמיתיים מתוך ALL. מלאו תוכן בשלוש השפות לפי המידע הידוע. הוספת תמונה או שינוי טענת חינם דורשים סקירת מנהל; אל תעתיקו אישור מתמונה/מקום אחר.
'''

def dumps(obj):return json.dumps(obj,ensure_ascii=False,indent=2)
def supplier_bundle(code=''):
 from provider_translation import export
 with tempfile.TemporaryDirectory() as temp:
  file=Path(temp)/'supplier.json';export(file);bundle=json.loads(file.read_text(encoding='utf-8'))
 if code:bundle['records']=[r for r in bundle['records'] if r['product_code']==code]
 if not bundle['records']:raise ValueError('Unknown supplier product')
 return bundle
def build(db,args,operation):
 bundle=operation(db,{'mode':'export','ids':args.get('ids',[])})
 kinds=set(args.get('kinds',[]))
 if kinds:bundle['records']=[r for r in bundle['records'] if r['record_type'] in kinds]
 if not bundle['records']:raise ValueError('No matching records')
 rows=[]
 for record in bundle['records']:
  ed=record['editorial'];required=['title'] if record['record_type'] in ('destination','category') else ['title','summary']
  if record['record_type']=='article':required+=['body_markdown']
  for lang in bundle['manifest']['locales']:
   tr=ed.get('translations',{}).get(lang,{})
   rows.append({'id':record['id'],'type':record['record_type'],'language':lang,'missing_fields':[k for k in required if not tr.get(k)],'review_status':tr.get('translation_status','missing')})
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
  def put(path,obj):z.writestr(path,dumps(obj))
  z.writestr('READ_ME_HE.md',README)
  put('editorial/ALL.json',bundle)
  put('practical/ALL.json',operation(db,{'mode':'practical_export','ids':[r['id'] for r in bundle['records']]}))
  put('translation-locales.json',operation(db,{'mode':'locales'}))
  for kind in sorted({r['record_type'] for r in bundle['records']}):put('editorial/by-type/'+kind+'.json',{**bundle,'records':[r for r in bundle['records'] if r['record_type']==kind]})
  for r in bundle['records']:put('editorial/items/'+r['record_type']+'/'+r['id']+'.json',{**bundle,'records':[r]})
  put('coverage.json',rows)
  import csv
  csvfile=io.StringIO();w=csv.writer(csvfile);w.writerow(['id','type','language','missing_fields','review_status'])
  for r in rows:w.writerow([r['id'],r['type'],r['language'],';'.join(r['missing_fields']),r['review_status']])
  z.writestr('coverage.csv','\ufeff'+csvfile.getvalue())
  # Render only known public content fields; never export the database/configuration.
  php=os.environ.get('TRIPOVELA_PHP') or ('C:/xampp/php/php.exe' if os.name=='nt' else 'php')
  ref=subprocess.run([php,str(ROOT/'bin/content_reference.php')],capture_output=True,check=True)
  reference=json.loads(ref.stdout.decode('utf-8-sig'));put('reference/site-content.json',reference)
  for item in reference['articles']:put('reference/articles/'+item['id']+'.json',item)
  put('reference/media.json',bundle['assets'])
  put('reference/discovery-taxonomy.json',json.loads((ROOT/'content/discovery-taxonomy.json').read_text(encoding='utf-8-sig')))
  supplier=supplier_bundle()
  put('supplier/ALL.json',supplier)
  for r in supplier['records']:put('supplier/items/'+r['product_code']+'.json',{**supplier,'records':[r]})
  for kind in ['article','place','experience']:
   put('templates/'+kind+'-EXAMPLE-NOT-FOR-IMPORT.json',{'example_only':True,'instruction':'Create a reserved draft in admin before importing. Replace this example with the downloaded template.','record_type':kind,'editorial':{'workflow_state':'draft','translations':{lang:{'title':'','summary':'','body_markdown':'','translation_status':'human_review_required'} for lang in bundle['manifest']['locales']},**({'article_kind':'guide'} if kind=='article' else {'destination_ids':[],'category_ids':[]})}})
  put('content-schema.json',json.loads((ROOT/'content/content-schema.json').read_text(encoding='utf-8')))
  counts=dict(Counter(r['record_type'] for r in bundle['records']));put('MANIFEST.json',{'format':'tripovela-model-workspace-v1','editorial_export_id':bundle['manifest']['export_id'],'counts':counts,'supplier_products':len(supplier['records']),'reference_articles':len(reference['articles']),'missing_field_rows':sum(bool(r['missing_fields']) for r in rows),'importable':'editorial/*.json and editorial/items/**/*.json; supplier files use separate importer','no_credentials':True})
 return {'filename':'Tripovela_Content_Workspace.zip','base64':base64.b64encode(out.getvalue()).decode(),'counts':counts,'missing_field_rows':sum(bool(r['missing_fields']) for r in rows)}
