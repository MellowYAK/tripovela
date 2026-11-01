"""Private compact inventory, source research and reserved editorial templates."""
import os,json,subprocess,sqlite3,re,io,zipfile,base64,uuid,hashlib,csv
from pathlib import Path
from urllib.parse import urlsplit,urlunsplit
ROOT=Path(__file__).resolve().parents[1]
FIELDS=['address','hours','directions','special_notes','accessibility','booking_notes','seasonal_notes','contact']
def dump(x):return json.dumps(x,ensure_ascii=False,indent=2)
def short(x,n=150):return re.sub(r'\s+',' ',str(x or '')).strip()[:n]
def url(x):
    try:
        p=urlsplit(x or '')
        if p.scheme not in ('http','https') or not p.hostname or p.username or p.password:return ''
        return urlunsplit((p.scheme,p.netloc,p.path,p.query if not any(k in p.query.lower() for k in ['key=','token=','secret=','password=']) else '', ''))
    except ValueError:return ''
def storage(db):return Path(db.execute('PRAGMA database_list').fetchone()[2]).parent
def inventory(db):
    env={**os.environ,'TRIPOVELA_STORAGE':str(storage(db))};php=env.get('TRIPOVELA_PHP') or ('C:/xampp/php/php.exe' if os.name=='nt' else 'php')
    data=json.loads(subprocess.run([php,str(ROOT/'bin/model_inventory.php')],env=env,capture_output=True,check=True).stdout.decode('utf-8-sig'))
    rows={};docs={r['id']:r for r in db.execute('SELECT * FROM editorial_records')};items={x['id']:x for x in data['items']}
    places={x['id']:x for x in json.loads((ROOT/'content/places.json').read_text(encoding='utf-8-sig'))}
    for id,r in docs.items():
        if r['kind'] not in ['place','experience','article']:continue
        doc=json.loads(r['document']);ed=doc['editorial'];trs=ed.get('translations',{});tr=trs.get('he') or trs.get('en') or next(iter(trs.values()),{})
        item=items.get(id) or items.get(id.removeprefix('item-')) or {};legacy=places.get(id.removeprefix('item-'),{})
        city=item.get('city') or next(iter(ed.get('destination_ids',[])),'');dest=data['destinations'].get(city,{})
        source=ed.get('official_url') or legacy.get('source') or next((s.get('url') for s in ed.get('provenance',[]) if s.get('url')),'')
        try:
            free=db.execute('SELECT payload FROM free_import_state WHERE id=?',(id,)).fetchone()
            if free:source=json.loads(free[0]).get('official_url') or source
        except sqlite3.OperationalError:pass
        site=item.get('url','')
        if not site and r['published'] and not id.startswith(('item-','article-')):site='/content/'+id+'/'
        rows[id]={'id':id,'kind':r['kind'],'cost':item.get('cost') or ed.get('visit_cost',{}).get('classification','unknown'),'title':short(tr.get('title'),180),'title_en':short(trs.get('en',{}).get('title'),180),'summary':short(tr.get('summary')),'city':city,'country':dest.get('country',''),'categories':ed.get('category_ids',[]),'languages':[l for l,t in trs.items() if t.get('title')],'state':'published' if r['published'] else 'draft','product_code':item.get('product',''),'source_url':url(source),'site_url':('https://tripovela.com'+site if site.startswith('/') else url(site)),'practical_id':item.get('id') or id}
    for x in data['items']:
        id=x['id'] if x['id'] in rows else 'item-'+x['id']
        if id not in rows:
            dest=data['destinations'].get(x.get('city'),{})
            rows[id]={'id':id,'kind':'experience' if x.get('product') else 'place','cost':x.get('cost','unknown'),'title':short(x['title'],180),'title_en':'','summary':short(x.get('summary')),'city':x.get('city',''),'country':dest.get('country',''),'categories':[x.get('type','')],'languages':['he'],'state':'published','product_code':x.get('product',''),'source_url':'','site_url':'https://tripovela.com'+x.get('url',''),'practical_id':x['id']}
    for a in data['articles']:
        id='article-'+str(a['id']).lower();rows.setdefault(id,{'id':id,'kind':'article','cost':'unknown','title':short(a['title'],180),'title_en':'','summary':short(a['summary']),'city':'','country':'','categories':[],'languages':['he'],'state':a['status'],'product_code':'','source_url':'','site_url':'','practical_id':id})
        rows[id]['site_url']='https://tripovela.com/guides/'+a['slug']+'/'
        rows[id]['state']=a['status']
    products={}
    try:products={r['id']:json.loads(r['payload']) for r in db.execute("SELECT id,payload FROM provider_records WHERE stream='products'")}
    except sqlite3.OperationalError:pass
    for file in ['activity-details.json','activity-metadata.json','provider-catalog.json']:
        if (ROOT/'content'/file).is_file():
            datafile=json.loads((ROOT/'content'/file).read_text(encoding='utf-8-sig'))
            if isinstance(datafile,dict):
                for key,v in datafile.items():
                    if isinstance(v,dict):products.setdefault(key,v)
    links=json.loads((ROOT/'content/partners/viator-guide-links.json').read_text(encoding='utf-8-sig')).get('products',{})
    for code,p in products.items():
        matches=[r for r in rows.values() if r['product_code']==code]
        source=url(p.get('productUrl') or p.get('source_url') or p.get('url') or links.get(code,{}).get('affiliate_url'))
        if source and 'viator.com' in urlsplit(source).hostname:source=source.split('?')[0]
        if not matches:
            id='provider-'+code;rows[id]={'id':id,'kind':'experience','cost':'paid','title':short(p.get('title') or code,180),'title_en':short(p.get('title'),180),'summary':short(p.get('description')),'city':'','country':'','categories':[],'languages':['en'],'state':p.get('status','cached'),'product_code':code,'source_url':source,'site_url':'https://tripovela.com/activities/'+code+'/','practical_id':'offer-'+code.lower()};matches=[rows[id]]
        for row in matches:
            if source:row['source_url']=source
            if not row['title_en']:row['title_en']=short(p.get('title') or links.get(code,{}).get('title'),180)
    for row in rows.values():
        d=data['destinations'].get(row['city'],{})
        if d.get('labels'):row['city']=d['labels'][1] or row['city']
    # Blank reserved templates do not represent existing attractions.
    return sorted([r for r in rows.values() if r['title']],key=lambda r:(r['kind'],r['country'],r['city'],r['id']))
def table(rows):
    out=io.StringIO();cols=['id','kind','cost','title','title_en','summary','city','country','product_code','source_url','site_url','state','languages']
    writer=csv.writer(out,delimiter='\t');writer.writerow(cols)
    for row in rows:
        cells=[','.join(row[k]) if isinstance(row[k],list) else str(row.get(k,'')) for k in cols]
        writer.writerow(["'"+x if x.startswith(('=','+','-','@')) else x for x in cells])
    return out.getvalue()
def schema(db):
    db.executescript('CREATE TABLE IF NOT EXISTS model_research_exports(id TEXT PRIMARY KEY,payload TEXT NOT NULL,created_at TEXT); CREATE TABLE IF NOT EXISTS model_research_jobs(id TEXT PRIMARY KEY,digest TEXT UNIQUE,payload TEXT NOT NULL,state TEXT NOT NULL); CREATE TABLE IF NOT EXISTS model_research_metadata(id TEXT PRIMARY KEY,document TEXT NOT NULL,updated_at TEXT); CREATE TABLE IF NOT EXISTS model_demo_packages(id TEXT PRIMARY KEY,payload TEXT NOT NULL,created_at TEXT);')
def pack(files,name):
    b=io.BytesIO()
    with zipfile.ZipFile(b,'w',zipfile.ZIP_DEFLATED) as z:
        for path,value in files.items():z.writestr(path,value if isinstance(value,str) else dump(value))
    return {'filename':name,'base64':base64.b64encode(b.getvalue()).decode()}
def research(db,rows):
    from content_transfer import stamp
    uid=uuid.uuid4().hex;records=[]
    for r in rows:
        if r['kind'] not in ['place','experience']:continue
        try:old=db.execute('SELECT * FROM place_practical WHERE id=?',(r['practical_id'],)).fetchone()
        except sqlite3.OperationalError:old=None
        known=json.loads(old['document']) if old else {}
        metadata=db.execute('SELECT document FROM model_research_metadata WHERE id=?',(r['id'],)).fetchone()
        records.append({'id':r['id'],'practical_id':r['practical_id'],'base_revision':old['revision'] if old else 0,'reference':{k:r[k] for k in ['title','city','country','source_url','site_url','product_code']},'existing':known,'previous_research':json.loads(metadata[0]) if metadata else {},'updates':{'official_url':None,'latitude':None,'longitude':None,'timezone':None,'checked_at':None,'translations':{l:{k:None for k in FIELDS} for l in ['he','en','ru','ka']},'evidence':[]}})
    bundle={'schema':'tripovela-research/1','export_id':uid,'instructions':'Fill updates only. Null means preserve/unknown. No guessing. Each populated field needs evidence with field, source_url, checked_at. Distinguish tour meeting point from attraction entrance. Opening hours must include timezone, season/holiday exceptions if known. Return only changed records. Admin review required.','records':records}
    with db:db.execute('INSERT INTO model_research_exports VALUES(?,?,?)',(uid,dump(bundle),stamp()))
    return bundle
def validate_research(db,bundle):
    from content_transfer import safe_url
    if bundle.get('schema')!='tripovela-research/1':raise ValueError('Wrong research schema')
    saved=db.execute('SELECT payload FROM model_research_exports WHERE id=?',(bundle.get('export_id'),)).fetchone()
    if not saved:raise ValueError('Unknown export')
    source={r['id']:r for r in json.loads(saved[0])['records']};changes=[];seen=set()
    for r in bundle.get('records',[]):
        id=r.get('id');o=source.get(id)
        if not o or id in seen:raise ValueError('Unknown/duplicate ID')
        seen.add(id)
        if {k:v for k,v in r.items() if k!='updates'}!={k:v for k,v in o.items() if k!='updates'}:raise ValueError('Protected reference changed')
        try:current=db.execute('SELECT revision FROM place_practical WHERE id=?',(r['practical_id'],)).fetchone()
        except sqlite3.OperationalError:current=None
        if (current[0] if current else 0)!=r['base_revision']:raise ValueError('Stale revision: '+id)
        u=r['updates'];populated=[]
        if set(u)!=set(o['updates']) or set(u['translations'])!=set(o['updates']['translations']):raise ValueError('Unknown update field')
        for key in ['official_url','timezone','checked_at']:
            if u[key] is not None:
                if not isinstance(u[key],str) or not u[key] or len(u[key])>1000 or '<' in u[key]:raise ValueError('Invalid text')
                populated.append(key)
        if u['official_url']:safe_url(u['official_url'])
        if u['checked_at']:
            from datetime import date
            if date.fromisoformat(u['checked_at'])>date.today():raise ValueError('Future evidence date')
        if u['timezone']:
            from zoneinfo import ZoneInfo
            ZoneInfo(u['timezone'])
        for key,limit in [('latitude',90),('longitude',180)]:
            v=u[key]
            if v is not None:
                if type(v) not in [int,float] or not -limit<=v<=limit:raise ValueError('Invalid coordinates')
                populated.append(key)
        if (u['latitude'] is None)!=(u['longitude'] is None):raise ValueError('Both coordinates required')
        for lang,tr in u['translations'].items():
            if set(tr)!=set(FIELDS):raise ValueError('Unknown translation field')
            for key,v in tr.items():
                if v is not None:
                    if not isinstance(v,str) or not v.strip() or len(v)>5000 or re.search(r'<[^>]*>|javascript:|data:',v,re.I):raise ValueError('Unsafe/empty text')
                    populated.append('translations.'+lang+'.'+key)
        evidences=u['evidence']
        if not isinstance(evidences,list) or len(evidences)>100:raise ValueError('Invalid evidence')
        covered=set()
        for e in evidences:
            if set(e)!={'field','source_url','checked_at'} or e['field'] not in populated:raise ValueError('Invalid evidence field')
            safe_url(e['source_url']);from datetime import date
            if date.fromisoformat(e['checked_at'])>date.today():raise ValueError('Future evidence')
            covered.add(e['field'])
        required=set(populated)-{'checked_at'}
        if required-covered:raise ValueError('Missing evidence for '+','.join(sorted(required-covered)))
        if populated and not u['checked_at']:raise ValueError('Review date required')
        if any('.hours' in f for f in populated) and not u['timezone'] and not r['previous_research'].get('timezone'):raise ValueError('Opening hours require timezone')
        if populated:changes.append(r)
    if not changes:raise ValueError('No completed fields')
    return changes
def operation(db,args):
    from content_transfer import stamp,canonical,operation as transfer
    schema(db);mode=args['mode']
    if mode=='model_export':
        rows=inventory(db);scope=args.get('scope','all')
        if scope in ['free','paid']:rows=[r for r in rows if r['cost']==scope]
        elif scope!='all':raise ValueError('Invalid scope')
        research_bundle=research(db,rows)
        return pack({'QUICK-LIST.tsv':table(rows),'QUICK-LIST.json':{'schema':'tripovela-inventory/1','records':rows},'COMPLETE-DETAILS.json':research_bundle,'READ-ME.txt':README},'Tripovela_Quick_Research_'+scope+'.zip')
    if mode=='model_template':
        kind=args.get('kind','place');bundle=transfer(db,{'mode':'template_export','kind':kind,'count':int(args.get('count',1))})
        tax=[{'id':r['id'],'kind':r['kind'],'translations':json.loads(r['document'])['editorial'].get('translations',{})} for r in db.execute("SELECT * FROM editorial_records WHERE kind IN ('category','destination')")]
        return pack({'NEW-CONTENT.json':bundle,'EXISTING-QUICK-LIST.tsv':table(inventory(db)),'DESTINATIONS-CATEGORIES.json':tax,'READ-ME.txt':README+'\nNEW-CONTENT is the reserved importable editorial template. Return through existing content import; draft only. Paid offers require provider/affiliate verification separately. Never invent price/rating/image rights.','METADATA-TEMPLATE.json':{'reference_only':True,'official_url':'','source_url':'','latitude':None,'longitude':None,'timezone':'','checked_at':'','translations':{l:{f:'' for f in FIELDS} for l in ['he','en','ru','ka']},'media':[{'file':'','source_url':'','author':'','license':'','rights_evidence':'','alt':{'he':'','en':'','ru':'','ka':''}}]}},'Tripovela_New_'+kind+'.zip')
    if mode=='model_research_stage':
        changes=validate_research(db,args['bundle']);digest=hashlib.sha256(canonical(args['bundle']).encode()).hexdigest();prior=db.execute('SELECT * FROM model_research_jobs WHERE digest=?',(digest,)).fetchone()
        if prior:return {'state':prior['state'],'job':prior['id'],'changes':changes}
        id=uuid.uuid4().hex
        with db:db.execute('INSERT INTO model_research_jobs VALUES(?,?,?,?)',(id,digest,dump(args['bundle']),'staged'))
        return {'state':'staged','job':id,'changes':changes}
    if mode=='model_research_apply':
        row=db.execute('SELECT * FROM model_research_jobs WHERE id=?',(args['id'],)).fetchone()
        if not row or row['state']!='staged':raise ValueError('Job not staged')
        bundle=json.loads(row['payload'])
        db.execute('CREATE TABLE IF NOT EXISTS place_practical(id TEXT PRIMARY KEY,revision INTEGER NOT NULL,document TEXT NOT NULL,updated_at TEXT)')
        with db:
            db.execute('BEGIN IMMEDIATE')
            changes=validate_research(db,bundle)
            if len({r['practical_id'] for r in changes})!=len(changes):raise ValueError('Duplicate practical target')
            for r in changes:
                p=r['existing'];u=r['updates'];p.setdefault('translations',{});p.setdefault('visibility',dict.fromkeys(['address','hours','map','official','directions'],True))
                for key in ['official_url','latitude','longitude','checked_at']:
                    if u[key] is not None:p[key]=u[key]
                for lang,tr in u['translations'].items():
                    for key,v in tr.items():
                        if v is not None:p['translations'].setdefault(lang,{})[key]=v
                p['timezone']=u['timezone'] or p.get('timezone','')
                db.execute('INSERT INTO place_practical VALUES(?,1,?,?) ON CONFLICT(id) DO UPDATE SET revision=revision+1,document=excluded.document,updated_at=excluded.updated_at',(r['practical_id'],dump(p),stamp()))
                old=r['previous_research']
                for key,v in u.items():
                    if key=='translations':
                        for lang,tr in v.items():
                            old.setdefault('translations',{}).setdefault(lang,{}).update({k:x for k,x in tr.items() if x is not None})
                    elif key=='evidence':old.setdefault('evidence',[]).extend(v)
                    elif v is not None:old[key]=v
                db.execute('INSERT INTO model_research_metadata VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET document=excluded.document,updated_at=excluded.updated_at',(r['id'],dump(old),stamp()))
            db.execute("UPDATE model_research_jobs SET state='applied' WHERE id=?",(args['id'],))
        return {'state':'applied','count':len(changes)}
    if mode.startswith('model_community_'):
        from community_exchange import operation as community
        return community(db,args)
    raise ValueError('Unknown workspace operation')
README='''Tripovela — רשימה קצרה ומידע להשלמה
QUICK-LIST: כותרת, תקציר קצר, יעד, מקור, מזהה מוצר ומצב. כולל טיוטות כדי לא ליצור כפילויות. זו רשימת עזר, לא מנוע שמבטיח זיהוי שמות חלופיים. השוו גם יעד, מקור ומזהה מוצר. מאמר ומוצר שאליו הוא מפנה יכולים להופיע בנפרד. אל תוסיפו תוכן כפול בגלל שפה שונה.
COMPLETE-DETAILS.json: פתחו source_url במקור הרשמי. site_url הוא דף Tripovela לעיון בלבד. אם מקור חסר, חפשו מקור רשמי ואמתו אותו. אין להמציא שעות, כתובת, מחיר או נגישות. השאירו null אם לא מצאתם. מלאו רק updates. לכל שדה מלא הוסיפו evidence עם field, source_url, checked_at. שעות כוללות אזור זמן וחריגים כשידועים. הכתובת היא של נקודת הביקור או המפגש המתאימה; אל תחליפו ביניהן. הוסיפו הערות ילדים, מדרגות, הזמנה מראש ועונתיות רק לפי מקור.
הקובץ המקוצר אינו ליבוא. קובץ ההשלמה מוחזר בניהול → קבצים למודל → בדיקת השלמות → אישור. אפשר להחזיר רק רשומות ששונו. אין לשנות מזהים, גרסאות ו-reference. שדות שלא מולאו נשמרים. אין פרסום אוטומטי ואין קריאה אוטומטית לאתרים חיצוניים.
תבנית תוכן חדש: NEW-CONTENT.json משתמשת במזהים שהמערכת הקצתה וביבואן הקיים. מלאו רק editorial לפי הפורמט. תרגומים תחת אותו מזהה. שדות המטה הנוספים הם רשימת מחקר נפרדת להשלמה אחרי יצירת הטיוטה, ולא מפתחות חדשים להדבקה ל-editorial. תמונות מחייבות מקור והרשאה. אין להוסיף דירוגים מומצאים.
אין בחבילה סיסמאות, מפתחות או תוכן פרטי של משתמשים.
'''
