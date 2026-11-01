"""Authenticated full-site translation handoff; never exports credentials or visitor data."""
import base64,io,json,os,re,shutil,sqlite3,subprocess,sys,tempfile,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def build(db,args,operation):
 target=args.get('target','ka')
 if not isinstance(target,str) or not re.fullmatch(r'[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*',target):raise ValueError('Invalid target language code')
 if target in ('he','en','ru'):raise ValueError('Use this export for a new language; existing languages use the editorial workspace')
 node=os.environ.get('TRIPOVELA_NODE') or shutil.which('node')
 if not node and os.name=='nt':node=str(Path.home()/'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe')
 if not node or not Path(node).exists():raise ValueError('Translation export requires Node.js on the server')
 php=os.environ.get('TRIPOVELA_PHP') or ('C:/xampp/php/php.exe' if os.name=='nt' else 'php')
 storage=Path(db.execute('PRAGMA database_list').fetchone()[2]).parent
 bundle=operation(db,{'mode':'export'})
 with tempfile.TemporaryDirectory(prefix='translation-',dir=storage) as tmp:
  tmp=Path(tmp);snapshot=tmp/'snapshot';snapshot.mkdir();out=tmp/'package'
  def put(name,data):
   p=snapshot/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(data,ensure_ascii=False),encoding='utf-8')
  put('editorial.json',bundle)
  put('practical.json',operation(db,{'mode':'practical_export'}))
  put('published.json',[{'id':x['id'],'published':json.loads(x['published']) if x['published'] else None} for x in db.execute('select id,published from editorial_records')])
  source=sqlite3.connect('file:'+str(storage/'tripovela.sqlite')+'?mode=ro',uri=True);source.row_factory=sqlite3.Row
  put('articles.json',[dict(x) for x in source.execute('select id,slug,title,summary,body,status from articles')])
  put('dictionary-settings.json',{x['key']:x['value'] for x in source.execute("select key,value from settings where key like 'language_dictionary_%'")});source.close()
  for base,exts in [('content',{'.json','.md'}),('src',{'.php'}),('views',{'.php'}),('public/assets',{'.js'})]:
   for p in (ROOT/base).rglob('*'):
    rel=p.relative_to(ROOT)
    if not p.is_file() or p.suffix not in exts or 'imports' in rel.parts or 'partners' in rel.parts or p.name=='config.php' or 'admin' in p.name:continue
    dest=snapshot/rel;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(p,dest)
  put('snapshot.json',{'release':ROOT.name,'editorial_records':len(bundle['records'])})
  for cmd in [[php,str(ROOT/'bin/site_translation_tokens.php'),str(snapshot),str(tmp/'georgian-php-tokens.json')],[node,str(ROOT/'bin/site_translation_tokens.cjs'),str(snapshot),str(tmp/'georgian-js-tokens.json')],[sys.executable,str(ROOT/'bin/site_translation_build.py'),str(snapshot),str(out),target]]:
   proc=subprocess.run(cmd,capture_output=True,timeout=120)
   if proc.returncode:raise ValueError('Translation export failed: '+proc.stderr.decode('utf-8','replace')[-250:])
  summary=json.loads((out/'SUMMARY.json').read_text(encoding='utf-8'))
  instructions=f'''# חבילת תרגום מלאה של Tripovela

שפת היעד: `{target}`. גאורגית היא `ka` (לא `ge`); כיוון הכתיבה משמאל לימין.

## מה לשלוח למודל
בחרו מסלול אחד בלבד:
- `TRANSLATE/parts/`: החלקים הקטנים, מומלץ להעביר לפי הסדר.
- `TRANSLATE/ALL-TEXTS.{target}.json`: כל הטקסטים בקובץ אחד.
- `BY-CONTENT/`: קובץ לכל מאמר, אטרקציה או רכיב, לתרגום פריטים בודדים. כאן ייתכנו טקסטים משותפים לכמה פריטים.

בחבילה הראשית {summary['unique_texts']} טקסטים ייחודיים ב־{summary['translation_parts']} חלקים. האטרקציות כוללות {summary['supplier_products']} מוצרי ספק, וכן מקומות וחוויות ממערכת העריכה. רשומות ריקות אינן דורשות תרגום. `REFERENCE/content-index.json` מחבר בין פריטים, שדות ומזהי טקסט. רשומות טיוטה מסומנות בנפרד; אין לפרסם אותן בעקבות התרגום.

## הוראות למודל המתרגם
מלאו רק את שדה `{target}` בכל יחידת טקסט. השתמשו ב־`source_language` כמקור העיקרי ובשפות האחרות לעזרה. תרגמו את הטקסט המלא, ללא קיצור או סיכום, בשפה טבעית ובמונחי תיירות עקביים. תרגמו גם שמות מדינות וערים ושמות מקומות באופן המקובל בשפת היעד.

אל תשנו `id`, `source`, `source_hash`, `source_language`, manifest, record או מפתחות. שימרו מבנה Markdown, רשימות, מספרים, שמות מותג, כתובות URL, כתובות דוא״ל, מזהי מוצר, סימוני {{VIATOR_PRICE:...}}, {{VIATOR_LINK:...}}, [[place:...]], [[experience:...]], %s, %d ו־placeholders. אל תתרגמו כתובות אינטרנט ואל תמציאו שעות פתיחה, מחירים, דירוגים או תנאי כניסה. אל תשנו את שפת ההדרכה המוצעת בפועל. קרדיטים וזכויות תמונה אינם משתנים.

טקסטי ממשק קצרים צריכים להישאר קצרים. כאשר יש קטע HTML שמור, תרגמו רק את הטקסט ושימרו את התגיות. אל תוסיפו קוד או קישורים. החזירו JSON תקין ב־UTF-8 באותו מבנה. אין צורך להחזיר את תיקיית REFERENCE.

## החזרת הקבצים
שלחו את קובצי JSON המתורגמים או ZIP שלהם חזרה ל־Codex. זהו פורמט חדש לשפה נוספת: אין להעלות אותו ליבואן הישן שמיועד רק ל־he/ru או ליבואן מאמרים. לפני השילוב תבוצע בדיקת מזהים, שלמות, גרסאות וקישורים; הפעלת השפה באתר היא שלב נפרד. הייצוא אינו מפעיל שפה ואינו משנה תוכן ציבורי.

## הורדה עצמאית בעתיד
ניהול → קטלוג, תוכן וסנכרון → חבילת תרגום של האתר כולו. בחרו קוד שפה ולחצו הורדה. בכל פעם מיוצא התוכן הנוכחי של השרת באמצעות אותו שירות. נשמר עותק פרטי של הייצוא לצורך בדיקת הקבצים שיחזרו.

לא נכללים מפתחות API, סיסמאות, נתוני משתמשים, סטטיסטיקות או בסיס הנתונים. תמונות אינן דורשות תרגום; הטקסט החלופי שלהן נכלל כאשר הוא קיים. הממשק הציבורי כלול; מסכי ניהול טכניים אינם חלק מתרגום אתר המטיילים.
'''
  (out/'START-HERE-HE.md').write_text(instructions,encoding='utf-8')
  buf=io.BytesIO()
  with zipfile.ZipFile(buf,'w',zipfile.ZIP_DEFLATED) as z:
   for p in sorted(out.rglob('*')):
    if p.is_file():z.write(p,p.relative_to(out))
  data=buf.getvalue()
  archive=storage/'translation-exports';archive.mkdir(exist_ok=True,mode=0o700)
  name='Tripovela_All_Content_'+target+'_'+bundle['manifest']['export_id']+'.zip'
  path=archive/name;path.write_bytes(data);path.chmod(0o600)
  return {'filename':name,'base64':base64.b64encode(data).decode(),'summary':summary,'export_id':bundle['manifest']['export_id']}
