"""Admin ZIP exchange. No credentials. Synthetic activity is demo-account-only."""
import base64,hashlib,io,json,re,sqlite3,time,uuid,zipfile
from pathlib import Path,PurePosixPath
from PIL import Image,ImageOps

KEYS=['users','posts','comments','follows','likes','friendships','trips','places','media']
def dump(v): return json.dumps(v,ensure_ascii=False,indent=2)
def ident(v):
    if not isinstance(v,str) or not re.fullmatch(r'[a-zA-Z0-9_-]{1,64}',v): raise ValueError('Invalid ID')
    return v
def txt(v,n=12000):
    if not isinstance(v,str) or len(v)>n or '\x00' in v: raise ValueError('Invalid text')
    return v
def demo(bio): return 'Demo profile' in bio or 'חשבון הדגמה' in bio
def connect(path):
    d=sqlite3.connect(path,timeout=20);d.row_factory=sqlite3.Row;d.execute('PRAGMA foreign_keys=ON');return d
def export(root,scope):
    d=connect(root/'community.sqlite');b={'schema':'tripovela-community/1',**{k:[] for k in KEYS}};files={};media=set()
    for r in d.execute("SELECT m.id,m.handle,p.* FROM members m JOIN member_profiles p ON p.member=m.id WHERE m.state='active'"):
        if scope=='demo' and not demo(r['bio']): continue
        b['users'].append({k:r[k] for k in ['id','handle','name','bio','language','city','avatar']})
        if r['avatar']:media.add(r['avatar'])
    users={u['id'] for u in b['users']}
    for r in d.execute('SELECT p.*,l.language,l.document FROM network_posts p JOIN network_localizations l ON l.kind="post" AND l.target=p.id'):
        if r['owner'] not in users or (scope!='demo' and r['state']!='published'):continue
        doc=json.loads(r['document']);b['posts'].append({'id':r['id'],'owner':r['owner'],'document':doc});media.update(doc.get('photos',[]))
    for table,key,field in [('member_trips','trips','public_plan'),('member_places','places','document')]:
        for r in d.execute('SELECT * FROM '+table):
            if r['owner'] not in users or not r[field] or (key=='places' and scope!='demo' and r['state']!='approved'):continue
            doc=json.loads(r[field]);
            for private in ['start','end','travelers']:doc.pop(private,None)
            for item in doc.get('items',[]):
                for private in ['note','booked']:item.pop(private,None)
            b[key].append({'id':r['id'],'owner':r['owner'],'document':doc});media.update(doc.get('photos',[]))
    targets={kind+':'+r['id'] for kind,key in [('post','posts'),('trip','trips'),('place','places')] for r in b[key]}
    for r in d.execute('SELECT * FROM network_comments'):
        if r['owner'] in users and r['kind']+':'+r['target'] in targets and (scope=='demo' or r['state']=='published'):b['comments'].append({k:r[k] for k in ['id','owner','kind','target','language','body']})
    for r in d.execute("SELECT * FROM network_edges WHERE kind IN ('follow','like')"):
        if r['actor'] not in users:continue
        if r['kind']=='follow' and r['target'] in users:b['follows'].append({'actor':r['actor'],'target':r['target']})
        if r['kind']=='like' and r['target'] in targets:
            kind,target=r['target'].split(':',1);b['likes'].append({'actor':r['actor'],'kind':kind,'target':target})
    for mid in sorted(media):
        ident(mid);p=root/'community-images'/(mid+'.jpg')
        if p.is_file():
            name='images/'+mid+'.jpg';files[name]=p.read_bytes();b['media'].append({'id':mid,'file':name,'rights':'Existing site image; preserve original attribution. AI-generated for demo avatars.'})
    d.close()
    files['COMMUNITY.json']=dump(b).encode();files['README_HE.txt']=README.encode();files['EXAMPLES.json']=dump(examples(b)).encode()
    out=io.BytesIO()
    with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
        for name,data in files.items():z.writestr(name,data)
    return {'filename':'Tripovela_Community_'+scope+'.zip','base64':base64.b64encode(out.getvalue()).decode(),'users':len(users)}

def examples(b):
    ids=[u['id'] for u in b['users']];a=ids[0] if ids else 'new-demo-user';other=ids[1] if len(ids)>1 else 'another-demo-user'
    return {'instructions':'Copy desired entries into COMMUNITY.json arrays. EXAMPLES.json is never imported.',
      'post':{'id':'new-post-001','owner':a,'document':{'title':'כותרת למילוי','body':'תוכן הדגמה למילוי המבוסס על מקומות אמיתיים.','kind':'experience','language':'he','city':'Tbilisi','source':'','visited_at':'','commercial':False,'photos':[]}},
      'comment':{'id':'new-comment-001','owner':other,'kind':'post','target':'new-post-001','language':'he','body':'תגובה לדוגמה'},
      'follow':{'actor':a,'target':other},'friendship':{'actor':a,'target':other},'like':{'actor':other,'kind':'post','target':'new-post-001'},
      'media':{'id':'new-photo-001','file':'images/new-photo-001.jpg','rights':'שם היוצר, מקור ורישיון או תמונה שנוצרה ב-AI'},
      'trip':{'id':'new-route-001','owner':a,'document':{'public_title':'מסלול לדוגמה','public_summary':'תיאור המסלול','city':'Tbilisi','language':'he','days':1,'items':[{'id':'CATALOGUE-ID','title':'שם מקום','day':1,'custom':True}],'photos':[]}},
      'place':{'id':'new-place-001','owner':a,'document':{'title':'מקום לדוגמה','description':'מידע מאומת למילוי על המקום והביקור בו','city':'Tbilisi','category':'culture','language':'he','cost':'free','price':'','address':'','hours':'','source_url':'','latitude':None,'longitude':None,'photos':[]}}}

def validate(b):
    if set(b)!=set(KEYS+['schema']) or b['schema']!='tripovela-community/1':raise ValueError('Unsupported schema')
    for key in KEYS:
        if not isinstance(b[key],list) or len(b[key])>1000:raise ValueError('Too many '+key)
    for key in ['users','posts','comments','trips','places','media']:
        seen=set()
        for row in b[key]:
            rid=ident(row.get('id'))
            unique=(rid,row.get('document',{}).get('language')) if key=='posts' else rid
            if unique in seen:raise ValueError('Duplicate ID in '+key)
            seen.add(unique)
    users={u['id'] for u in b['users']};images={m['id'] for m in b['media']};targets={k+':'+r['id'] for k,arr in [('post','posts'),('trip','trips'),('place','places')] for r in b[arr]}
    for u in b['users']:
        if set(u)!=set(['id','handle','name','bio','language','city','avatar']):raise ValueError('Invalid user fields; passwords are prohibited')
        if not re.fullmatch('[a-z0-9][a-z0-9_-]{2,29}',u['handle']):raise ValueError('Invalid handle')
        txt(u['name'],60);txt(u['bio'],400);txt(u['city'],100)
        if u['language'] not in ['he','en','ru','ka'] or u['avatar'] and u['avatar'] not in images:raise ValueError('Invalid profile language/avatar')
    for key in ['posts','trips','places']:
        for r in b[key]:
            if set(r)!= {'id','owner','document'} or r['owner'] not in users:raise ValueError('Invalid owner')
            p=r['document'];txt(dump(p),25000)
            if p.get('language') not in ['he','en','ru','ka']:raise ValueError('Invalid language')
            txt(p.get('city'),100)
            if not isinstance(p.get('photos',[]),list) or len(p.get('photos',[]))>5 or set(p.get('photos',[]))-images:raise ValueError('Unknown photo')
            if key=='posts':
                txt(p.get('title'),160);txt(p.get('body'));txt(p.get('source',''),1000)
                if p.get('kind') not in ['experience','question','guide','update']:raise ValueError('Invalid post kind')
            if key=='places':
                txt(p.get('title'),180);txt(p.get('description'),6000)
                if p.get('cost') not in ['free','paid','unknown'] or p.get('category') not in ['city','nature','museums','food','shows','water','markets','culture']:raise ValueError('Invalid place category/cost')
                for f,n in [('address',500),('hours',1000),('price',200)]:txt(p.get(f,''),n)
                if p.get('source_url') and not re.match(r'^https?://[^\s]+$',p['source_url']):raise ValueError('Invalid source URL')
            if key=='trips':
                txt(p.get('public_title'),120)
                if type(p.get('days'))!=int or not 0<=p['days']<=30 or not isinstance(p.get('items'),list) or len(p['items'])>100:raise ValueError('Invalid trip')
                for item in p['items']:
                    ident(item.get('id'));txt(item.get('title'),180)
                    if type(item.get('day'))!=int or not 0<=item['day']<=p['days']:raise ValueError('Invalid day')
    for r in b['comments']:
        if set(r)!={'id','owner','kind','target','language','body'} or r['owner'] not in users or r['kind']+':'+r['target'] not in targets or r['language'] not in ['he','en','ru','ka']:raise ValueError('Invalid comment')
        txt(r['body'],2000)
    for key in ['follows','friendships','likes']:
        for r in b[key]:
            if set(r)!=({'actor','kind','target'} if key=='likes' else {'actor','target'}) or r['actor'] not in users:raise ValueError('Invalid relation')
            if key=='likes':
                if r['kind']+':'+r['target'] not in targets:raise ValueError('Unknown like target')
            elif r['target'] not in users or r['target']==r['actor']:raise ValueError('Invalid follow target')
    for m in b['media']:
        if set(m)!= {'id','file','rights'}:raise ValueError('Invalid media fields')
        txt(m['rights'],1000)
        if not m['rights'].strip():raise ValueError('Image rights required')

def preview(root,b):
    d=connect(root/'community.sqlite');rows=[];owners={};seen=set()
    try:
        for u in b['users']:
            if u['handle'] in seen:raise ValueError('Duplicate handle')
            seen.add(u['handle']);old=d.execute('SELECT m.id,p.bio FROM members m LEFT JOIN member_profiles p ON p.member=m.id WHERE handle=?',(u['handle'],)).fetchone()
            if old and not demo(old['bio'] or ''):raise ValueError('Protected real account: '+u['handle'])
            owners[u['id']]=old['id'] if old else None
            rows.append({'collection':'users','id':u['id'],'title':u['handle'],'action':'update' if old else 'create'})
        for kind,key,table in [('post','posts','network_posts'),('trip','trips','member_trips'),('place','places','member_places'),('comment','comments','network_comments')]:
            for r in b[key]:
                rid=r['id'];mapped_row=None
                if d.execute("SELECT 1 FROM sqlite_master WHERE name='community_bundle_map'").fetchone():mapped_row=d.execute('SELECT internal_id FROM community_bundle_map WHERE kind=? AND external_id=?',(kind,rid)).fetchone()
                old=d.execute('SELECT owner FROM '+table+' WHERE id=?',((mapped_row[0] if mapped_row else rid),)).fetchone()
                if old and old['owner']!=owners[r['owner']]:raise ValueError('Ownership conflict: '+rid)
                doc=r.get('document',{});rows.append({'collection':key,'id':rid,'title':doc.get('title',doc.get('public_title',r.get('body','')))[:160],'action':'update' if old else 'create'})
        for key in ['follows','friendships','likes','media']:
            rows.append({'collection':key,'id':'','title':str(len(b[key])),'action':'merge_without_duplicates'})
        return rows
    finally:d.close()

def fingerprint(folder,b):
    h=hashlib.sha256(json.dumps(b,sort_keys=True,ensure_ascii=False).encode())
    for m in sorted(b['media'],key=lambda x:x['id']):h.update(m['id'].encode());h.update((folder/(m['id']+'.jpg')).read_bytes())
    return h.hexdigest()

def stage(root,path):
    if Path(path).stat().st_size>50_000_000:raise ValueError('ZIP exceeds 50MB; no import performed')
    job=uuid.uuid4().hex;folder=root/'community-bundles'/job;folder.mkdir(parents=True,mode=0o700)
    with zipfile.ZipFile(path) as z:
        names=z.namelist()
        if len(names)>1200 or len(names)!=len(set(names)) or sum(i.file_size for i in z.infolist())>100_000_000:raise ValueError('ZIP size/entry limit')
        for info in z.infolist():
            name=info.filename;p=PurePosixPath(name)
            if p.is_absolute() or '..' in p.parts or '\\' in name or ':' in name or (info.external_attr>>16)&0o170000==0o120000:raise ValueError('Unsafe ZIP path')
        if z.getinfo('COMMUNITY.json').file_size>12000000:raise ValueError('JSON too large')
        b=json.loads(z.read('COMMUNITY.json'));validate(b);changes=preview(root,b)
        for m in b['media']:
            if not re.fullmatch(r'images/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)',m['file']) or z.getinfo(m['file']).file_size>5000000:raise ValueError('Invalid image path/size')
            with Image.open(io.BytesIO(z.read(m['file']))) as im:
                if im.format not in ['JPEG','PNG','WEBP'] or im.width*im.height>20_000_000:raise ValueError('Invalid image')
                im=ImageOps.exif_transpose(im).convert('RGB');im.thumbnail((1800,1800));im.save(folder/(m['id']+'.jpg'),quality=85,optimize=True)
    (folder/'bundle.json').write_text(dump(b),encoding='utf-8')
    d=connect(root/'community.sqlite')
    try:
        exists=d.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='community_bundle_fingerprints'").fetchone()
        if exists and d.execute('SELECT 1 FROM community_bundle_fingerprints WHERE hash=?',(fingerprint(folder,b),)).fetchone():
            for change in changes:change['action']='skip'
    finally:d.close()
    return {'state':'community_staged','id':job,'counts':{k:len(b[k]) for k in KEYS},'changes':changes,'received_bytes':Path(path).stat().st_size}

def apply(root,job):
    if not re.fullmatch('[a-f0-9]{32}',job):raise ValueError('Invalid job')
    folder=root/'community-bundles'/job;b=json.loads((folder/'bundle.json').read_text(encoding='utf-8'));validate(b);d=connect(root/'community.sqlite')
    (root/'community-images').mkdir(exist_ok=True)
    d.executescript('CREATE TABLE IF NOT EXISTS community_bundle_fingerprints(hash TEXT PRIMARY KEY,job TEXT NOT NULL); CREATE TABLE IF NOT EXISTS network_member_flags(member TEXT PRIMARY KEY,audience TEXT NOT NULL); CREATE TABLE IF NOT EXISTS community_bundle_map(kind TEXT,external_id TEXT,internal_id TEXT,PRIMARY KEY(kind,external_id)); CREATE TABLE IF NOT EXISTS community_bundle_jobs(id TEXT PRIMARY KEY,created INTEGER);')
    digest=fingerprint(folder,b)
    if d.execute('SELECT 1 FROM community_bundle_jobs WHERE id=?',(job,)).fetchone() or d.execute('SELECT 1 FROM community_bundle_fingerprints WHERE hash=?',(digest,)).fetchone():
        d.close();return {'state':'community_applied','id':job,'already_applied':True}
    backup=connect(folder/'before.sqlite');d.backup(backup);backup.close();now=int(time.time())
    def mapped(kind,key):
        row=d.execute('SELECT internal_id FROM community_bundle_map WHERE kind=? AND external_id=?',(kind,key)).fetchone()
        if row:return row[0]
        value=key if re.fullmatch('[a-f0-9]{32}',key) else uuid.uuid4().hex
        d.execute('INSERT INTO community_bundle_map VALUES(?,?,?)',(kind,key,value));return value
    try:
        d.execute('BEGIN IMMEDIATE');um={};tm={};im={}
        for u in b['users']:
            existing=d.execute('SELECT m.id,p.bio FROM members m LEFT JOIN member_profiles p ON p.member=m.id WHERE m.handle=?',(u['handle'],)).fetchone()
            if existing:
                if not demo(existing['bio'] or ''):raise ValueError('Import only updates labelled demo accounts: '+u['handle'])
                uid=existing['id']
            else:
                uid=mapped('member',u['id']);d.execute('INSERT INTO members(id,handle,password,recovery,created) VALUES(?,?,?,?,?)',(uid,u['handle'],'!',uuid.uuid4().hex,now))
            um[u['id']]=uid
            d.execute("INSERT OR REPLACE INTO network_member_flags VALUES(?,'demo')",(uid,))
        for m in b['media']:
            # Namespace imported files separately; never overwrite another member's image.
            data=(folder/(m['id']+'.jpg')).read_bytes();mid=hashlib.sha256(('community-bundle:'+m['id']).encode()+data).hexdigest()[:32];im[m['id']]=mid
            dest=root/'community-images'/(mid+'.jpg');dest.write_bytes(data);dest.chmod(0o600)
        for u in b['users']:
            bio=u['bio'] if demo(u['bio']) else ('Demo profile · '+u['bio'])[:400]
            d.execute('INSERT INTO member_profiles(member,name,bio,avatar,language,city,business,updated) VALUES(?,?,?,?,?,?,0,?) ON CONFLICT(member) DO UPDATE SET name=excluded.name,bio=excluded.bio,avatar=excluded.avatar,language=excluded.language,city=excluded.city,updated=excluded.updated',(um[u['id']],u['name'],bio,im.get(u['avatar'],''),u['language'],u['city'],now))
        for kind,key in [('post','posts'),('trip','trips'),('place','places')]:
            for r in b[key]:tm[kind+':'+r['id']]=mapped(kind,r['id'])
        for kind,key,table in [('post','posts','network_posts'),('trip','trips','member_trips'),('place','places','member_places')]:
            for r in b[key]:
                rid=mapped(kind,r['id']);owner=um[r['owner']];tm[kind+':'+r['id']]=rid
                old=d.execute('SELECT owner FROM '+table+' WHERE id=?',(rid,)).fetchone()
                if old and old[0]!=owner:raise ValueError('Ownership conflict')
                p=dict(r['document']);photos=[]
                for source in p.get('photos',[]):
                    mid=hashlib.sha256((kind+':'+rid+':'+im[source]).encode()).hexdigest()[:32]
                    dest=root/'community-images'/(mid+'.jpg');dest.write_bytes((root/'community-images'/(im[source]+'.jpg')).read_bytes());dest.chmod(0o600);photos.append(mid)
                p['photos']=photos
                if kind=='post':
                    p.setdefault('source','');p.setdefault('visited_at','');p.setdefault('commercial',False)
                    d.execute('INSERT INTO network_posts VALUES(?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET state="pending"',(rid,owner,p['kind'],p['city'],now,'pending'))
                    d.execute('INSERT INTO network_localizations VALUES(?,?,?,?,?,?,?) ON CONFLICT(kind,target,language) DO UPDATE SET document=excluded.document,state="pending",updated=excluded.updated',(kind,rid,p['language'],'pending',dump(p),1,now))
                    for mid in p['photos']:d.execute('INSERT OR IGNORE INTO network_images VALUES(?,?,?)',(mid,rid,owner))
                elif kind=='place':
                    p['photo']=p['photos'][0] if p['photos'] else '';p.setdefault('offer',{});
                    for f in ['address','hours','price','source_url']:p.setdefault(f,'')
                    d.execute('INSERT INTO member_places(id,owner,document,state,created) VALUES(?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET document=excluded.document,state="pending"',(rid,owner,dump(p),'pending',now))
                    for mid in p['photos']:d.execute('INSERT OR IGNORE INTO member_images VALUES(?,?)',(mid,rid))
                else:
                    p.update(version=1,travelers=2,start='',end='',activeDay=0)
                    for x in p['items']:
                        if 'place:'+x['id'] in tm:x['id']='community-'+tm['place:'+x['id']];x['custom']=False
                        x.update(note='',booked=False,type='custom' if x.get('custom') else 'experience')
                    d.execute('INSERT INTO member_trips(id,owner,title,plan,pending_plan,state,updated) VALUES(?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET title=excluded.title,plan=excluded.plan,pending_plan=excluded.pending_plan,revision=revision+1,updated=excluded.updated',(rid,owner,p['public_title'],dump(p),dump(p),'private',now))
                    for mid in p['photos']:d.execute('INSERT OR IGNORE INTO member_trip_images VALUES(?,?,?)',(mid,rid,now))
        for r in b['comments']:
            cid=mapped('comment',r['id']);old=d.execute('SELECT owner FROM network_comments WHERE id=?',(cid,)).fetchone()
            if old and old[0]!=um[r['owner']]:raise ValueError('Comment ownership conflict')
            d.execute('INSERT INTO network_comments VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET body=excluded.body,state="pending"',(cid,um[r['owner']],r['kind'],tm[r['kind']+':'+r['target']],r['language'],r['body'],'pending',now))
        for key in ['follows','friendships','likes']:
            for r in b[key]:
                actor=um[r['actor']];target=um[r['target']] if key!='likes' else r['kind']+':'+tm[r['kind']+':'+r['target']];kind='like' if key=='likes' else 'follow'
                d.execute('INSERT OR IGNORE INTO network_edges VALUES(?,?,?,?)',(actor,kind,target,now))
                if key=='friendships':d.execute('INSERT OR IGNORE INTO network_edges VALUES(?,?,?,?)',(target,kind,actor,now))
        d.execute('INSERT INTO community_bundle_fingerprints VALUES(?,?)',(digest,job))
        d.execute('INSERT INTO community_bundle_jobs VALUES(?,?)',(job,now));d.commit()
    except: d.rollback();d.close();raise
    d.close()
    return {'state':'community_applied','id':job,'counts':{k:len(b[k]) for k in KEYS}}

def operation(db,args):
    from model_workspace import storage
    root=storage(db);mode=args['mode']
    if mode=='model_community_export':return export(root,'all' if args.get('scope')=='all' else 'demo')
    if mode=='model_community_stage':return stage(root,args['path'])
    if mode=='model_community_apply':return apply(root,args['id'])
    raise ValueError('Unknown community operation')

README='''חבילת משתמשים ותוכן Tripovela — גרסה 1
ערכו COMMUNITY.json והחזירו אותו ואת תיקיית images בתוך ZIP. EXAMPLES.json מכיל דוגמאות שאינן נטענות אוטומטית.
שמרו מזהים קיימים. לרשומה חדשה בחרו מזהה ייחודי באנגלית, ספרות, מקף או קו תחתון. אין סיסמאות, אימיילים, אסימונים או מפתחות.
users: id,handle,name,bio,language,city,avatar. השפות: he,en,ru,ka. אין לשנות handle של חשבון קיים.
posts: id,owner,document; מסמך בכל שפה תחת אותו ID. kind: experience/question/guide/update. comments: תגובות, לא הודעות פרטיות.
follows: actor,target; friendships: מעקב הדדי; likes: actor,kind,target כאשר kind הוא post/trip/place. הפיד נבנה מהפרסומים והמעקבים, אינו שדה נפרד.
trips ו-places: ראו דוגמאות. השתמשו רק במקומות אמיתיים ובמקורות בדוקים. אל תציגו ביקור בדיוני כביקור מאומת.
media: id,file,rights. קובץ images/ID.jpg או png/webp; עד 5MB לתמונה, עד 20 מגה-פיקסל. העלאה מפוענחת ומקודדת מחדש ללא מטא-דאטה. התמונות אינן קוד להרצה.
המערכת בודקת לפני שמירה. אישור הייבוא שומר תוכן כממתין למודרציה; אינו מאשר פרסום. חשבונות ופעילות מיובאת מסומנים להדגמה. משתמש חדש נוצר ללא אפשרות התחברות עד שיוגדרו לו פרטי כניסה בנפרד. חשבונות קיימים שומרים את סיסמתם.
ייבוא סינתטי מוגבל לחשבונות הדגמה; אי אפשר לשנות משתמש אמיתי או להוסיף בשמו לייקים. ייצוא כלל המשתמשים כולל רק פרופילים ותוכן ציבורי וקשרים שבין החשבונות המיוצאים; אין תוכן פרטי, שמורים, הודעות, תאריכים מדויקים או סימוני הזמנה.
עד 1000 רשומות בכל אוסף, ZIP עד 50MB, תוכן מפורק עד 100MB. ייבוא חוזר של אותה עבודה אינו משוכפל; אין מחיקה של תוכן שלא נכלל בחבילה.
'''
