"""Authenticated administrator content exchange; additive by default, explicit optimistic updates."""
import base64,csv,hashlib,io,json,re,sqlite3,time,uuid,zipfile
from pathlib import Path,PurePosixPath
from urllib.parse import urlsplit
from PIL import Image,ImageOps
from community_bundle import connect,dump

SCHEMA='tripovela-community/2'
KINDS={'users','posts','trips','places','comments','edges','ratings','preferences','translations'}
def digest(value):return hashlib.sha256(json.dumps(value,sort_keys=True,ensure_ascii=False).encode()).hexdigest()
def uid(value):
    if not isinstance(value,str) or not re.fullmatch('[a-zA-Z0-9_-]{1,64}',value):raise ValueError('Invalid identifier')
    return value if re.fullmatch('[a-f0-9]{32}',value) else hashlib.sha256(('community-exchange:'+value).encode()).hexdigest()[:32]
def language(value):
    if not isinstance(value,str) or len(value)>35 or not re.fullmatch('[a-z]{2,3}(?:-[a-z0-9]{2,8})*',value):raise ValueError('Invalid language code')
    return value
def text(value,limit=12000):
    if not isinstance(value,str) or len(value)>limit or '\x00' in value:raise ValueError('Invalid text')
    return value
def validate_links(value):
    if isinstance(value,dict):
        for k,v in value.items():
            if (k.endswith('_url') or k=='url') and v:
                text(v,2000);parsed=urlsplit(v)
                if parsed.scheme not in ['http','https'] or not parsed.hostname or parsed.username or parsed.password:raise ValueError('Expected public HTTP(S) URL')
            if k in ['latitude','longitude'] and v not in [None,'']:
                limit=90 if k=='latitude' else 180
                if isinstance(v,bool) or not -limit<=float(v)<=limit:raise ValueError('Invalid coordinates')
            validate_links(v)
    elif isinstance(value,list):
        for v in value:validate_links(v)

def record(collection,key,owner,data):return dict(collection=collection,id=key,owner=owner,action='keep',expected=digest(data),data=data)
def snapshots(db,owners):
    records=[]
    for owner in owners:
        u=db.execute('SELECT id,handle,state,created FROM members WHERE id=?',(owner,)).fetchone()
        if not u:raise ValueError('Unknown selected account: '+owner)
        p=db.execute('SELECT name,bio,avatar,language,city,business FROM member_profiles WHERE member=?',(owner,)).fetchone()
        records.append(record('users',owner,owner,dict(handle=u['handle'],**(dict(p) if p else dict(name='',bio='',avatar='',language='en',city='',business=0)))))
    for r in db.execute('SELECT p.*,l.language,l.document,l.state AS locale_state,l.source_version FROM network_posts p JOIN network_localizations l ON l.kind="post" AND l.target=p.id'):
        if r['owner'] in owners:records.append(record('posts',r['id'],r['owner'],dict(document=json.loads(r['document']),state=r['state'],locale_state=r['locale_state'],source_version=r['source_version'])))
    for table,collection in [('member_trips','trips'),('member_places','places'),('network_comments','comments')]:
        for r in db.execute('SELECT * FROM '+table):
            if r['owner'] not in owners:continue
            data={k:r[k] for k in r.keys() if k not in ['id','owner']}
            for k in ['plan','public_plan','pending_plan','document']:
                if k in data and data[k]:data[k]=json.loads(data[k])
            records.append(record(collection,r['id'],r['owner'],data))
    for r in db.execute('SELECT * FROM network_edges'):
        if r['actor'] in owners:records.append(record('edges',digest([r['actor'],r['kind'],r['target']])[:32],r['actor'],dict(kind=r['kind'],target=r['target'])))
    for r in db.execute('SELECT * FROM member_ratings'):
        if r['voter'] in owners:records.append(record('ratings',digest([r['voter'],r['kind'],r['target']])[:32],r['voter'],dict(kind=r['kind'],target=r['target'],score=r['score'])))
    for r in db.execute('SELECT * FROM network_language_preferences'):
        if r['member'] in owners:records.append(record('preferences',r['member'],r['member'],{k:r[k] for k in r.keys() if k!='member'}))
    for table,kind in [('member_trips','trip'),('member_places','place')]:
        for r in db.execute('SELECT l.*,t.owner FROM network_localizations l JOIN '+table+' t ON l.target=t.id WHERE l.kind=?',(kind,)):
            if r['owner'] in owners:
                data={k:r[k] for k in r.keys() if k!='owner'};data['document']=json.loads(data['document'])
                records.append(record('translations',digest([kind,r['target'],r['language']])[:32],r['owner'],data))
    return records
def key(r):return (r['collection'],uid(r['id']),r['data'].get('document',{}).get('language','') if r['collection']=='posts' else '')
def archive(db,owners):
    # Supplemental audit/history is read-only and cannot be replayed as user activity.
    out={}
    tables={'network_notices':'member','member_reports':'reporter','network_versions':'actor','network_submissions':'owner','network_events':'member'}
    for table,field in tables.items():out[table]=[dict(r) for r in db.execute('SELECT * FROM '+table) if r[field] in owners]
    existing={r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    if 'social_drafts' in existing:out['drafts']=[dict(r) for r in db.execute('SELECT * FROM social_drafts') if r['owner'] in owners]
    if 'social_comment_links' in existing:out['comment_attachments']=[dict(r) for r in db.execute('SELECT l.*,c.owner FROM social_comment_links l JOIN network_comments c ON c.id=l.comment') if r['owner'] in owners]
    for table in ['drafts','comment_attachments']:
        for row in out.get(table,[]):row['document']=json.loads(row['document'])
    targets={x['id'] for x in snapshots(db,owners) if x['collection'] in ['posts','trips','places']}
    out['incoming_edges']=[dict(r) for r in db.execute('SELECT * FROM network_edges') if r['target'] in owners or r['target'].split(':')[-1] in targets]
    out['accounts']=[dict(r) for r in db.execute('SELECT id,handle,state,created FROM members') if r['id'] in owners]
    out['incoming_comments']=[dict(r) for r in db.execute('SELECT * FROM network_comments') if r['target'] in targets and r['owner'] not in owners]
    out['incoming_ratings']=[dict(r) for r in db.execute('SELECT * FROM member_ratings') if r['target'] in targets or r['target'] in owners]
    return out
def photos(value):
    found=set()
    if isinstance(value,dict):
        for k,v in value.items():
            if k in ['avatar','photo'] and isinstance(v,str) and v:found.add(v)
            elif k=='photos' and isinstance(v,list):found.update(x for x in v if isinstance(x,str))
            else:found.update(photos(v))
    elif isinstance(value,list):
        for v in value:found.update(photos(v))
    return found
def examples(owners):
    owner=owners[0] if owners else 'SELECTED-USER-ID'
    def new(c,data):return dict(collection=c,id=uuid.uuid4().hex,owner=owner,action='create',expected='',data=data)
    return [new('posts',{'document':{'language':'en','kind':'experience','title':'Write a title','body':'Write the post here.','city':'Tbilisi','photos':[]}}),
      new('posts',{'document':{'language':'de','kind':'question','title':'Your question','body':'Write your question here.','city':'','photos':[]}}),
      new('places',{'document':{'language':'en','title':'Place name','description':'Verified details','city':'Tbilisi','category':'culture','cost':'free','price':'','address':'','hours':'','source_url':'','photos':[]}}),
      new('trips',{'title':'Route title','plan':{'language':'en','city':'Tbilisi','days':1,'items':[],'photos':[]},'submit':True}),
      new('edges',{'kind':'follow','target':'EXISTING-USER-ID'}),new('edges',{'kind':'like','target':'post:EXISTING-POST-ID'}),
      new('comments',{'kind':'post','target':'EXISTING-POST-ID','language':'en','body':'Your comment'})]
CSV_FIELDS=['collection','action','id','owner','expected','language','title','body','kind','city','target','score','data_json']
def csv_bytes(records):
    out=io.StringIO(newline='');w=csv.DictWriter(out,fieldnames=CSV_FIELDS);w.writeheader()
    for r in records:
        d=r['data'];p=d.get('document',d);row={k:r[k] for k in ['collection','action','id','owner','expected']};row['data_json']=json.dumps(d,ensure_ascii=False)
        for k in ['language','title','body','kind','city','target','score']:row[k]=p.get(k,'')
        # Prevent spreadsheet formulas while allowing exact round trips.
        for k,v in row.items():
            if isinstance(v,str) and v.startswith(('=','+','-','@')):row[k]="'"+v
        w.writerow(row)
    return ('\ufeff'+out.getvalue()).encode('utf8')
def export(root,args):
    db=connect(root/'community.sqlite');all_ids=[r[0] for r in db.execute("SELECT id FROM members WHERE state!='deleted'")]
    owners=all_ids if args.get('scope')=='all' else args.get('users',[])
    if not isinstance(owners,list) or not owners or len(owners)>1000 or set(owners)-set(all_ids):raise ValueError('Select at least one existing account')
    owners=list(dict.fromkeys(owners));rows=snapshots(db,owners);template=args.get('export_mode')=='template'
    if template:rows=[r for r in rows if r['collection']=='users']
    bundle={'schema':SCHEMA,'users':owners,'records':rows,'media':[]};files={};missing=[]
    if not template:
        history=archive(db,owners)
        for mid in photos(rows)|photos(history):
            if not re.fullmatch('[a-f0-9]{32}',mid):continue
            path=root/'community-images'/(mid+'.jpg')
            if path.is_file():
                name='images/'+mid+'.jpg';files[name]=path.read_bytes();rights='Existing site media; preserve attribution'
                if db.execute("SELECT 1 FROM sqlite_master WHERE name='community_exchange_media'").fetchone():
                    credit=db.execute('SELECT rights FROM community_exchange_media WHERE id=?',(mid,)).fetchone()
                    if credit:rights=credit[0]
                bundle['media'].append(dict(id=mid,file=name,sha256=hashlib.sha256(files[name]).hexdigest(),rights=rights))
            else:missing.append(mid)
        files['READ_ONLY_HISTORY.json']=dump(history).encode()
    db.close();files['COMMUNITY.json']=dump(bundle).encode();files['COMMUNITY.csv']=csv_bytes(rows);files['EXAMPLES.json']=dump(examples(owners)).encode();files['INSTRUCTIONS_HE.txt']=README.encode();files['MEDIA_REPORT.json']=dump({'missing_files':missing}).encode()
    fmt=args.get('format','zip')
    if fmt=='json':data=files['COMMUNITY.json'];mime='application/json'
    elif fmt=='csv':data=files['COMMUNITY.csv'];mime='text/csv; charset=utf-8'
    elif fmt=='zip':
        out=io.BytesIO()
        with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
            for name,data in files.items():z.writestr(name,data)
        data=out.getvalue();mime='application/zip'
    else:raise ValueError('Invalid format')
    return dict(filename='Tripovela_Users_'+('Template' if template else 'Full')+'.'+fmt,base64=base64.b64encode(data).decode(),mime=mime,users=len(owners))
def validate(db,b,allow_updates=False):
    if not isinstance(b,dict) or set(b)!={'schema','users','records','media'} or b['schema']!=SCHEMA:raise ValueError('Expected tripovela-community/2')
    if not isinstance(b['users'],list) or not b['users'] or len(b['users'])>1000:raise ValueError('Invalid account selection')
    for owner in b['users']:
        if not isinstance(owner,str) or not db.execute("SELECT 1 FROM members WHERE id=? AND state!='deleted'",(owner,)).fetchone():raise ValueError('Unknown account')
    if not isinstance(b['records'],list) or len(b['records'])>10000 or not isinstance(b['media'],list) or len(b['media'])>1000:raise ValueError('Record limit exceeded')
    current={key(r):r for r in snapshots(db,set(b['users']))};seen=set();changes=[]
    for r in b['records']:
        if not isinstance(r,dict) or set(r)!={'collection','id','owner','action','expected','data'} or r['collection'] not in KINDS or r['owner'] not in b['users'] or not isinstance(r['data'],dict):raise ValueError('Invalid record')
        uid(r['id']);text(dump(r['data']),200000);old=current.get(key(r));action=r['action'];d=r['data'];k=r['collection'];p=d.get('document',d)
        if k in ['edges','ratings']:
            natural=next((x for x in current.values() if x['collection']==k and x['owner']==r['owner'] and all(x['data'].get(f)==d.get(f) for f in ['kind','target'])),None)
            if natural and key(natural)!=key(r):raise ValueError('Relation already exists; use its exported record ID')
        table={'posts':'network_posts','trips':'member_trips','places':'member_places','comments':'network_comments'}.get(k)
        if table:
            existing=db.execute('SELECT owner FROM '+table+' WHERE id=?',(uid(r['id']),)).fetchone()
            if existing and existing['owner']!=r['owner']:raise ValueError('Ownership conflict: '+r['id'])
            if existing and action=='create' and k!='posts':raise ValueError('Identifier already exists: '+r['id'])
        if key(r) in seen:raise ValueError('Duplicate record')
        seen.add(key(r))
        if action not in ['keep','create','update']:raise ValueError('Use keep, create or update; deletion is not supported')
        if action=='keep':
            if not old:raise ValueError('Kept record no longer exists')
            changes.append(dict(collection=k,title=r['id'],action='keep'));continue
        if action=='update':
            if not allow_updates:raise ValueError('Updates require the explicit update option')
            if not old or r['owner']!=old['owner'] or r['expected']!=old['expected']:raise ValueError('Record changed since export: '+r['id'])
        elif old:raise ValueError('Identifier already exists; use a new ID or explicit update: '+r['id'])
        if k=='users':
            if action!='update' or set(d)!=set(old['data']) or d['handle']!=old['data']['handle']:raise ValueError('Accounts must already exist; handles and credentials cannot be changed')
            for field,limit in [('name',60),('bio',400),('city',100)]:text(d[field],limit)
            language(d['language'])
        validate_links(d)
        if k in ['posts','places']:
            if not isinstance(p,dict):raise ValueError('Invalid document')
            language(p.get('language'));text(p.get('title'),180);text(p.get('city',''),100)
            if k=='posts':
                text(p.get('body'))
                if p.get('kind') not in ['experience','question','guide','update']:raise ValueError('Invalid story/question kind')
            else:
                text(p.get('description'),12000)
                if p.get('cost') not in ['free','paid','unknown']:raise ValueError('Invalid cost')
            if not isinstance(p.get('photos',[]),list) or len(p.get('photos',[]))>5:raise ValueError('At most five photos')
        if k=='trips':
            p=d.get('plan');text(d.get('title'),160)
            if not isinstance(p,dict) or type(p.get('days'))!=int or not 0<=p['days']<=30 or not isinstance(p.get('items'),list) or len(p['items'])>100:raise ValueError('Invalid route plan')
            language(p.get('language'))
            for stop in p['items']:
                text(stop.get('id'),128);text(stop.get('title'),180)
                if type(stop.get('day'))!=int or not 0<=stop['day']<=p['days']:raise ValueError('Invalid route day')
        if k=='comments':text(d.get('body'),2000);language(d.get('language'))
        if k in ['edges','ratings','comments']:
            target=d.get('target');text(target,160)
            if k=='edges' and d.get('kind') not in ['follow','like','save','block']:raise ValueError('Invalid relation')
            if k=='ratings' and (type(d.get('score'))!=int or not 1<=d['score']<=5):raise ValueError('Invalid rating')
            if action=='update' and any(d.get(f)!=old['data'].get(f) for f in ['kind','target']):raise ValueError('Relation target cannot change; add a new record')
        if k=='preferences':
            for field in ['publication_language','feed_language']:
                if d.get(field)!='all' or field!='feed_language':language(d.get(field))
            if d.get('interface_language'):language(d['interface_language'])
        if k=='translations':
            language(d.get('language'))
            table={'trip':'member_trips','place':'member_places'}.get(d.get('kind'))
            if not table or not db.execute('SELECT 1 FROM '+table+' WHERE id=? AND owner=?',(d.get('target'),r['owner'])).fetchone():raise ValueError('Invalid translation owner/target')
            if not isinstance(d.get('document'),dict) or d['document'].get('language')!=d['language']:raise ValueError('Translation language mismatch')
            if old and any(d.get(f)!=old['data'].get(f) for f in ['kind','target','language']):raise ValueError('Translation identity cannot change')
            if not old and db.execute('SELECT 1 FROM network_localizations WHERE kind=? AND target=? AND language=?',(d['kind'],d['target'],d['language'])).fetchone():raise ValueError('Translation exists; use its exported ID')
        changes.append(dict(collection=k,title=p.get('title',d.get('title',r['id'])),action=action))
    # Validate all relation targets, including references outside selected accounts.
    proposed={(r['collection'],uid(r['id'])) for r in b['records'] if r['action']=='create'}
    for r in b['records']:
        if r['action']=='keep' or r['collection'] not in ['edges','ratings','comments']:continue
        d=r['data'];kind=d['kind'];target=d['target']
        if r['collection']=='edges':
            if kind in ['follow','block']:kind='member'
            else:
                if ':' not in target:raise ValueError('Use kind:id for like/save target')
                kind,target=target.split(':',1)
        table={'member':'members','post':'network_posts','trip':'member_trips','place':'member_places'}.get(kind)
        if not table:raise ValueError('Unsupported target kind')
        coll={'member':'users','post':'posts','trip':'trips','place':'places'}[kind]
        if (coll,uid(target)) not in proposed and not db.execute('SELECT 1 FROM '+table+' WHERE id=?',(target,)).fetchone():raise ValueError('Unknown relation target: '+target)
    return changes
def stage(root,path,allow_updates=False):
    path=Path(path)
    if path.stat().st_size>50_000_000:raise ValueError('File exceeds 50MB')
    files={};raw=path.read_bytes()
    if zipfile.is_zipfile(path):
        with zipfile.ZipFile(path) as z:
            if len(z.namelist())>1200 or len(z.namelist())!=len(set(z.namelist())) or sum(i.file_size for i in z.infolist())>100_000_000:raise ValueError('ZIP limit exceeded')
            for info in z.infolist():
                n=info.filename;p=PurePosixPath(n)
                if p.is_absolute() or '..' in p.parts or '\\' in n or ':' in n or ((info.external_attr>>16)&0o170000)==0o120000:raise ValueError('Unsafe archive path')
                files[n]=z.read(n)
        raw=files.get('COMMUNITY.json',files.get('COMMUNITY.csv',b''))
    if len(raw)>12000000:raise ValueError('Content file exceeds 12MB')
    content=raw.decode('utf-8-sig')
    if content.lstrip().startswith('{'):b=json.loads(content)
    else:
        rows=[]
        for row in csv.DictReader(io.StringIO(content)):
            if set(row)!=set(CSV_FIELDS):raise ValueError('Invalid CSV columns')
            row={k:v[1:] if v.startswith(("'=","'+","'-","'@")) else v for k,v in row.items()}
            d=json.loads(row['data_json']);p=d.get('document',d)
            for k in ['language','title','body','kind','city','target','score']:
                if k in p or row[k]:p[k]=int(row[k]) if k=='score' else row[k]
            rows.append({**{k:row[k] for k in ['collection','action','id','owner','expected']},'data':d})
        b=dict(schema=SCHEMA,users=list(dict.fromkeys(r['owner'] for r in rows)),records=rows,media=[])
    db=connect(root/'community.sqlite')
    try:changes=validate(db,b,allow_updates)
    finally:db.close()
    job=uuid.uuid4().hex;folder=root/'community-exchange'/job;folder.mkdir(parents=True,mode=0o700)
    media_ids=set()
    for m in b['media']:
        mid=m.get('id');uid(mid)
        if mid in media_ids:raise ValueError('Duplicate media ID')
        media_ids.add(mid);name=m.get('file','');text(m.get('rights'),1000)
        if not m['rights'].strip() or not re.fullmatch(r'images/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)',name):raise ValueError('Image rights and safe filename required')
        if name in files:
            data=files[name]
            if len(data)>5000000:raise ValueError('Image exceeds 5MB')
            try:
                with Image.open(io.BytesIO(data)) as im:
                    if im.format not in ['JPEG','PNG','WEBP'] or im.width*im.height>20_000_000:raise ValueError('Invalid image')
                    im=ImageOps.exif_transpose(im).convert('RGB');im.thumbnail((1800,1800));im.save(folder/(mid+'.jpg'),quality=85,optimize=True)
            except (OSError,Image.DecompressionBombError) as e:raise ValueError('Invalid or damaged image') from e
        elif not (root/'community-images'/(mid+'.jpg')).is_file():raise ValueError('Image must be supplied in ZIP: '+mid)
    for mid in photos([r for r in b['records'] if r['action']!='keep']):
        uid(mid)
        if mid not in media_ids and not (root/'community-images'/(mid+'.jpg')).is_file():raise ValueError('Missing photo: '+mid)
    (folder/'bundle.json').write_text(dump(b),encoding='utf8');(folder/'options.json').write_text(dump({'allow_updates':allow_updates}),encoding='utf8')
    return dict(state='community_staged',id=job,counts={k:sum(r['collection']==k for r in b['records']) for k in sorted(KINDS)},changes=changes)

def apply(root,job):
    if not re.fullmatch('[a-f0-9]{32}',job):raise ValueError('Invalid job')
    folder=root/'community-exchange'/job;b=json.loads((folder/'bundle.json').read_text(encoding='utf8'));options=json.loads((folder/'options.json').read_text())
    db=connect(root/'community.sqlite');db.executescript('CREATE TABLE IF NOT EXISTS community_exchange_jobs(id TEXT PRIMARY KEY,created INTEGER NOT NULL); CREATE TABLE IF NOT EXISTS community_exchange_media(id TEXT PRIMARY KEY,rights TEXT NOT NULL);');db.commit()
    if db.execute('SELECT 1 FROM community_exchange_jobs WHERE id=?',(job,)).fetchone():db.close();return dict(state='community_applied',already_applied=True)
    backup=connect(folder/'before.sqlite');db.backup(backup);backup.close();now=int(time.time());(root/'community-images').mkdir(exist_ok=True)
    try:
        db.execute('BEGIN IMMEDIATE');validate(db,b,options['allow_updates'])
        media={}
        for m in b['media']:
            source=folder/(m['id']+'.jpg')
            if source.exists():
                data=source.read_bytes();mid=hashlib.sha256(data).hexdigest()[:32];dest=root/'community-images'/(mid+'.jpg');dest.write_bytes(data);dest.chmod(0o600);media[m['id']]=mid
                db.execute('INSERT OR IGNORE INTO community_exchange_media VALUES(?,?)',(mid,m['rights']))
        for r in sorted(b['records'],key=lambda r:['users','preferences','posts','places','trips','translations','comments','edges','ratings'].index(r['collection'])):
            if r['action']=='keep':continue
            c=r['collection'];rid=uid(r['id']);owner=r['owner'];d=json.loads(json.dumps(r['data']));p=d.get('document',d);new=r['action']=='create'
            if c=='users':
                db.execute('INSERT INTO member_profiles(name,bio,avatar,language,city,business,updated,member) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(member) DO UPDATE SET name=excluded.name,bio=excluded.bio,avatar=excluded.avatar,language=excluded.language,city=excluded.city,business=excluded.business,updated=excluded.updated',(d['name'],d['bio'],media.get(d['avatar'],d['avatar']),d['language'],d['city'],int(bool(d['business'])),now,owner));continue
            if c in ['posts','places','trips','translations']:
                if c=='trips':p=d['plan']
                final=[]
                for old in p.get('photos',[]):
                    base=media.get(old,old);source=root/'community-images'/(base+'.jpg');mid=hashlib.sha256((c+rid+owner+base).encode()).hexdigest()[:32]
                    target_file=root/'community-images'/(mid+'.jpg');target_file.write_bytes(source.read_bytes());target_file.chmod(0o600);final.append(mid)
                    credit=db.execute('SELECT rights FROM community_exchange_media WHERE id=?',(base,)).fetchone()
                    if credit:db.execute('INSERT OR IGNORE INTO community_exchange_media VALUES(?,?)',(mid,credit[0]))
                p['photos']=final
            if c=='posts':
                existing=db.execute('SELECT owner FROM network_posts WHERE id=?',(rid,)).fetchone()
                if existing and existing[0]!=owner:raise ValueError('Post ownership conflict')
                if new and existing and db.execute('SELECT 1 FROM network_localizations WHERE kind="post" AND target=? AND language=?',(rid,p['language'])).fetchone():raise ValueError('Post already exists')
                db.execute('INSERT INTO network_posts(id,owner,kind,city,created,state) VALUES(?,?,?,?,?,"pending") ON CONFLICT(id) DO UPDATE SET kind=excluded.kind,city=excluded.city,state="pending"',(rid,owner,p['kind'],p.get('city',''),now))
                db.execute('INSERT INTO network_localizations(kind,target,language,state,document,source_version,updated) VALUES("post",?,?,"pending",?,1,?) ON CONFLICT(kind,target,language) DO UPDATE SET document=excluded.document,state="pending",source_version=source_version+1,updated=excluded.updated',(rid,p['language'],dump(p),now))
                for mid in p['photos']:db.execute('INSERT OR IGNORE INTO network_images VALUES(?,?,?)',(mid,rid,owner))
            elif c=='places':
                p['photo']=p['photos'][0] if p['photos'] else '';p.setdefault('offer',{})
                db.execute('INSERT INTO member_places(id,owner,document,state,created) VALUES(?,?,?,"pending",?) ON CONFLICT(id) DO UPDATE SET document=excluded.document,state="pending"',(rid,owner,dump(p),now))
                for mid in p['photos']:db.execute('INSERT OR IGNORE INTO member_images VALUES(?,?)',(mid,rid))
            elif c=='trips':
                public=json.loads(json.dumps(p));public.pop('start',None);public.pop('end',None);public.pop('travelers',None);public.setdefault('public_title',d['title']);public.setdefault('public_summary','')
                for stop in public['items']:stop.pop('note',None);stop.pop('booked',None);stop.setdefault('custom',True)
                pending=dump(public) if d.get('submit',False) else None
                if new:db.execute('INSERT INTO member_trips(id,owner,title,plan,pending_plan,state,updated) VALUES(?,?,?,?,?,"private",?)',(rid,owner,d['title'],dump(p),pending,now))
                else:db.execute('UPDATE member_trips SET title=?,plan=?,pending_plan=COALESCE(?,pending_plan),revision=revision+1,updated=? WHERE id=? AND owner=?',(d['title'],dump(p),pending,now,rid,owner))
                for mid in p['photos']:db.execute('INSERT OR IGNORE INTO member_trip_images VALUES(?,?,?)',(mid,rid,now))
            elif c=='comments':
                db.execute('INSERT INTO network_comments VALUES(?,?,?,?,?,?,"pending",?) ON CONFLICT(id) DO UPDATE SET body=excluded.body,language=excluded.language,state="pending"',(rid,owner,d['kind'],uid(d['target']),d['language'],d['body'],now))
            elif c=='edges':
                target=d['target']
                if ':' in target:a,t=target.split(':',1);target=a+':'+uid(t)
                else:target=uid(target)
                db.execute('INSERT OR IGNORE INTO network_edges VALUES(?,?,?,?)',(owner,d['kind'],target,now))
            elif c=='ratings':db.execute('INSERT INTO member_ratings VALUES(?,?,?,?,?) ON CONFLICT(kind,target,voter) DO UPDATE SET score=excluded.score,updated=excluded.updated',(d['kind'],uid(d['target']),owner,d['score'],now))
            elif c=='preferences':db.execute('INSERT INTO network_language_preferences(member,publication_language,feed_language,interface_language,feed_custom) VALUES(?,?,?,?,?) ON CONFLICT(member) DO UPDATE SET publication_language=excluded.publication_language,feed_language=excluded.feed_language,interface_language=excluded.interface_language,feed_custom=excluded.feed_custom',(owner,d['publication_language'],d['feed_language'],d.get('interface_language',''),int(bool(d.get('feed_custom',1)))))
            elif c=='translations':
                for mid in p['photos']:
                    if d['kind']=='trip':db.execute('INSERT OR IGNORE INTO member_trip_images VALUES(?,?,?)',(mid,d['target'],now))
                    else:db.execute('INSERT OR IGNORE INTO member_images VALUES(?,?)',(mid,d['target']))
                db.execute('INSERT INTO network_submissions(id,kind,target,language,owner,document,base_version,created) VALUES(?,?,?,?,?,?,?,?)',(uuid.uuid4().hex,d['kind'],d['target'],d['language'],owner,dump(d['document']),d.get('source_version',0),now))
        db.execute('INSERT INTO community_exchange_jobs VALUES(?,?)',(job,now));db.commit()
    except:db.rollback();raise
    finally:db.close()
    return dict(state='community_applied',id=job)

def operation(db,args):
    from model_workspace import storage
    root=storage(db);mode=args['mode']
    if mode=='model_community_export':return export(root,args)
    if mode=='model_community_stage':return stage(root,args['path'],bool(args.get('allow_updates')))
    if mode=='model_community_apply':return apply(root,args['id'])
    raise ValueError('Unknown operation')

README='''Tripovela — ייצוא, תבניות וייבוא משתמשים ותוכן, גרסה 2
זהו כלי ניהול רגיל. בחרו חשבון אחד, כמה חשבונות או את כולם. אין סיסמאות, קודי שחזור או אסימוני גישה בקובץ.
COMMUNITY.json הוא הקובץ הראשי. COMMUNITY.csv הוא חלופה לעריכה בטבלה. בתוך ZIP המערכת קוראת JSON בעדיפות; אם ערכתם CSV, העלו אותו לבדו או הסירו מה-ZIP את JSON.
ייצוא מלא כולל פרופילים, פוסטים בכל שפותיהם ומצביהם, מסלולים פרטיים וציבוריים, מקומות, תגובות, מעקבים, לייקים, שמורים, חסימות, דירוגים והעדפות. READ_ONLY_HISTORY.json כולל היסטוריה וקשרים נכנסים לקריאה בלבד. אין ייבוא של היסטוריית פעילות או עקיפת מודרציה.
קובץ מלא עשוי להכיל תוכן פרטי של המשתמשים שנבחרו. בדקו אותו לפני העברה למודל או לצד שלישי. ZIP כולל תמונות; JSON/CSV לבדם מפנים לתמונות הקיימות בשרת.
תבנית ריקה מכילה רק מזהי החשבונות ופרופיליהם עם action=keep. הוסיפו רשומות מתוך EXAMPLES.json; הדוגמאות אינן מיובאות אוטומטית.
לכל רשומה: collection,id,owner,action,expected,data. owner חייב להיות מזהה חשבון שנבחר. המשתמשים חייבים להיות קיימים; אין שינוי סיסמה, שם משתמש או הרשאות.
action=keep משאיר רשומה ללא שינוי. action=create מוסיף רשומה עם מזהה חדש ייחודי. action=update מעדכן במפורש את הרשומה, עם expected מהייצוא המקורי ועם סימון אפשרות העדכון במסך הייבוא. אם הרשומה השתנתה מאז הייצוא, הייבוא נעצר. אין מחיקות ואין דריסה של רשומות שלא נכללו.
ארבע אפשרויות היצירה: חוויה/סיפור (posts עם kind=experience), שאלה (posts עם kind=question), מקום (places), מסלול (trips). פוסטים ותיקים מסוג guide/update נתמכים גם הם.
language הוא קוד שפת המקור כגון he,en,ru,ka,de,fr או קוד תקני נוסף כגון eo. אין צורך בתרגום הממשק לאותה שפה ואין תרגום אוטומטי של הפוסט.
ב-CSV השדות language,title,body,kind,city,target,score נוחים לעריכה; מבנים כמו תמונות ותחנות נמצאים ב-data_json. רוקנו גם שדה עליון אם מחקתם אותו מ-data_json. לא לשנות collection/id/owner של רשומה קיימת.
edges: kind=follow למעקב; like או save עם target=post:ID / trip:ID / place:ID; block לחסימה. חברות היא שני מעקבים הדדיים. הפיד נבנה מהתוכן והקשרים ואינו טבלה נפרדת.
מסלולים: data.plan כולל מידע פרטי; data.submit=true שולח עותק ציבורי למודרציה, ללא תאריכים מדויקים, הערות פרטיות וסימוני הזמנה. ברירת המחדל היא שמירה פרטית בלבד.
תמונות חדשות: רשומת media עם id חדש,file=images/ID.jpg,rights=קרדיט/מקור/רישיון; photos במסמך מכיל את המזהים. פרופיל משתמש משתמש בשדה avatar. עד חמש תמונות לפוסט, 5MB לתמונה ו-20 מגה-פיקסל. התמונות מפוענחות ומקודדות מחדש, ללא קוד או EXIF. אין הורדת תמונות מכתובות חיצוניות בזמן הייבוא.
העלו ZIP/JSON/CSV, בדקו את רשימת ההוספות והעדכונים ואשרו. תוכן פומבי חדש או מעודכן עובר מודרציה; אישור הייבוא אינו אישור פרסום. העלאה חוזרת אינה מיועדת ליצור כפילויות: שמרו מזהים ואל תיצרו מחדש אותה רשומה.
'''
