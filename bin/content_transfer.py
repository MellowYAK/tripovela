#!/usr/bin/env python3
"""Admin-only editorial transfer. JSON stdin/stdout, no remote URL fetching."""
import sys, os, json, sqlite3, uuid, hashlib, re, ipaddress
from pathlib import Path
from datetime import datetime, timezone
from urllib.parse import urlsplit
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'vendor/python'))
from jsonschema import Draft202012Validator, FormatChecker

def canonical(x):return json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))
def stamp():return datetime.now(timezone.utc).isoformat()
def locales(db):
 return [dict(r) for r in db.execute('SELECT code,label,direction FROM editorial_locales ORDER BY rowid')]
def connect(storage):
 d=sqlite3.connect(Path(storage)/'catalog.sqlite',timeout=30);d.row_factory=sqlite3.Row
 d.executescript('''CREATE TABLE IF NOT EXISTS editorial_records(id TEXT PRIMARY KEY,kind TEXT,revision INTEGER NOT NULL,document TEXT NOT NULL,published TEXT,updated_at TEXT);
 CREATE TABLE IF NOT EXISTS editorial_exports(id TEXT PRIMARY KEY,payload TEXT NOT NULL,created_at TEXT);
 CREATE TABLE IF NOT EXISTS editorial_imports(id TEXT PRIMARY KEY,digest TEXT UNIQUE,payload TEXT NOT NULL,state TEXT,created_at TEXT);
 CREATE TABLE IF NOT EXISTS editorial_revisions(id INTEGER PRIMARY KEY,record_id TEXT,revision INTEGER,document TEXT,published TEXT,created_at TEXT);
 CREATE TABLE IF NOT EXISTS editorial_media(id TEXT PRIMARY KEY,payload TEXT NOT NULL);
 CREATE TABLE IF NOT EXISTS editorial_locales(code TEXT PRIMARY KEY,label TEXT NOT NULL,direction TEXT NOT NULL);
 INSERT OR IGNORE INTO editorial_locales VALUES('he','עברית','rtl'),('en','English','ltr'),('ru','Русский','ltr'),('ka','ქართული','ltr');''');return d

def safe_url(value):
 p=urlsplit(value)
 if p.scheme!='https' or not p.hostname or p.username or p.password or p.port not in [None,443]:raise ValueError('Only public HTTPS URLs allowed')
 host=p.hostname.lower()
 if '.' not in host or host.endswith(('.invalid','.local','.localhost','.internal')) or host=='localhost':raise ValueError('Invalid source host')
 try:
  if not ipaddress.ip_address(host).is_global:raise ValueError('Private IP not allowed')
 except ValueError as e:
  if 'Private IP' in str(e):raise

def merge(old,patch):
 if isinstance(old,dict) and isinstance(patch,dict):
  out=dict(old)
  for k,v in patch.items():out[k]=merge(out.get(k),v)
  return out
 return patch

def validate(db,bundle):
 schema=json.loads((ROOT/'content/content-schema.json').read_text(encoding='utf-8'))
 errors=list(Draft202012Validator(schema,format_checker=FormatChecker()).iter_errors(bundle))
 if errors:raise ValueError('Schema: '+str(errors[0].json_path)+' '+errors[0].message[:160])
 if bundle['manifest']['purpose']!='editorial_transfer':raise ValueError('Demo data cannot be imported')
 snapshot=db.execute('SELECT payload FROM editorial_exports WHERE id=?',(bundle['manifest']['export_id'],)).fetchone()
 if not snapshot:raise ValueError('Unknown export')
 original=json.loads(snapshot[0])
 if bundle['manifest']!=original['manifest'] or bundle['assets']!=original['assets']:raise ValueError('Protected manifest/media changed')
 originals={x['id']:x for x in original['records']};seen=set();changes=[]
 allrows={r['id']:json.loads(r['document']) for r in db.execute('SELECT * FROM editorial_records')}
 for row in bundle['records']:
  id=row['id']
  if id in seen or id not in originals:raise ValueError('Duplicate or unauthorized identity')
  seen.add(id);old=originals[id]
  if any(row[k]!=old[k] for k in ['id','record_type','operation','base_revision','context']):raise ValueError('Protected fields changed: '+id)
  current=db.execute('SELECT * FROM editorial_records WHERE id=?',(id,)).fetchone()
  if not current or str(current['revision'])!=row['base_revision']:raise ValueError('Revision conflict: '+id)
  doc=merge(json.loads(current['document']),{'editorial':row['editorial']})
  ed=doc['editorial']
  if set(ed.get('translations',{}))-{x['code'] for x in locales(db)}:raise ValueError('Register target locale before importing')
  for locale,tr in ed.get('translations',{}).items():
   before=json.loads(current['document'])['editorial'].get('translations',{}).get(locale,{})
   if before.get('slug') and tr.get('slug')!=before['slug']:raise ValueError('Existing slug is immutable')
   for value in tr.values():
    if isinstance(value,str) and (len(value)>200000 or re.search(r'<[^>]+>|javascript:|data:|vbscript:',value,re.I)):raise ValueError('Unsafe or oversized text')
   if tr!=before:tr['translation_status']='human_review_required'
  # Source-language changes mark other translations for review without deleting them.
  prev=json.loads(current['document'])['editorial']
  if ed.get('translations',{}).get('he')!=prev.get('translations',{}).get('he'):
   for locale,tr in ed.get('translations',{}).items():
    if locale!='he':tr['translation_status']='human_review_required'
  for source in ed.get('provenance',[]):
   if source.get('url'):safe_url(source['url'])
   if source not in prev.get('provenance',[]) and source.get('verification_state')=='reviewed':raise ValueError('New source needs admin review')
  if ed.get('official_url'):safe_url(ed['official_url'])
  for media in ed.get('media',[]):
   if not any(a['asset_id']==media['asset_id'] for a in bundle['assets']):raise ValueError('Unknown media')
  # Cost claims cannot be upgraded through an external editor.
  if ed.get('visit_cost')!=prev.get('visit_cost'):raise ValueError('Cost evidence requires separate admin review')
  changed=canonical(ed)!=canonical(prev)
  if changed:ed['workflow_state']='draft'
  allrows[id]=doc
  changes.append({'id':id,'changed':changed,'before':prev,'after':ed,'document':doc,'revision':current['revision']})
 relation={'category_ids':'category','destination_ids':'destination','place_ids':'place','experience_ids':'experience','related_article_ids':'article','parent_category_id':'category','parent_destination_id':'destination'}
 slugs={}
 for id,doc in allrows.items():
  ed=doc['editorial']
  for field,kind in relation.items():
   vals=ed.get(field,[]);vals=[vals] if isinstance(vals,str) else vals or []
   for ref in vals:
    if ref not in allrows or allrows[ref]['record_type']!=kind:raise ValueError('Broken relationship: '+id+' -> '+ref)
  for locale,tr in ed.get('translations',{}).items():
   slug=tr.get('slug')
   if slug:
    key=(doc['record_type'],locale,slug)
    if key in slugs and slugs[key]!=id:raise ValueError('Duplicate slug')
    slugs[key]=id
  for stop in ed.get('itinerary_stops',[]):
   ref=stop.get('place_id') or stop.get('experience_id')
   if ref and ref not in allrows:raise ValueError('Unknown itinerary stop')
  for tr in ed.get('translations',{}).values():
   for kind,ref in re.findall(r'\[\[(place|experience):([a-z0-9_-]+)\]\]',tr.get('body_markdown','')):
    if ref not in ed.get(kind+'_ids',[]) or ref not in allrows:raise ValueError('Unbound content reference')
  for parent in ['parent_destination_id','parent_category_id']:
   visited={id};ref=ed.get(parent)
   while ref:
    if ref in visited:raise ValueError('Hierarchy cycle')
    visited.add(ref);ref=allrows[ref]['editorial'].get(parent)
 return changes

def operation(db,args):
 mode=args['mode']
 if mode.startswith('model_'):
  from model_workspace import operation as model_operation
  return model_operation(db,args)
 if mode.startswith('practical_'):
  from practical_transfer import operation as practical_operation
  return practical_operation(db,args)
 if mode=='locales':return {'locales':locales(db)}
 if mode=='locale_save':
  code=args.get('code','');label=args.get('label','').strip();direction=args.get('direction','ltr')
  if not re.fullmatch(r'[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*',code) or not 1<=len(label)<=60 or direction not in ('ltr','rtl'):raise ValueError('Invalid locale')
  with db:db.execute('INSERT INTO editorial_locales VALUES(?,?,?) ON CONFLICT(code) DO UPDATE SET label=excluded.label,direction=excluded.direction',(code,label,direction))
  return {'state':'translation_locale_saved','locales':locales(db)}
 if mode=='template_export':
  kind=args.get('kind','article');count=int(args.get('count',1))
  if kind not in ['article','place','experience','destination','category'] or not 1<=count<=10:raise ValueError('Invalid template request')
  ids=[]
  with db:
   for _ in range(count):
    id=kind+'-'+uuid.uuid4().hex[:12];ids.append(id)
    ed={'workflow_state':'draft','translations':{x['code']:{'title':'','summary':'','body_markdown':'','translation_status':'human_review_required'} for x in locales(db)}}
    if kind=='article':ed['article_kind']='guide'
    if kind in ['place','experience']:ed.update({'destination_ids':[],'category_ids':[]})
    doc={'id':id,'record_type':kind,'operation':'upsert','base_revision':'1','context':{'provider_bindings':[],'provider_owned_fields':[]},'editorial':ed}
    db.execute('INSERT INTO editorial_records VALUES(?,?,1,?,NULL,?)',(id,kind,canonical(doc),stamp()))
  return operation(db,{'mode':'export','ids':ids})
 if mode=='supplier_export':
  from content_archive import supplier_bundle
  return supplier_bundle(args.get('code',''))
 if mode=='site_translation_export':
  from site_translation_export import build
  return build(db,args,operation)
 if mode=='export_pack':
  from content_archive import build
  return build(db,args,operation)
 if mode in ('free_stage','free_apply'):
  from free_attractions_import import stage,apply
  storage=Path(db.execute('PRAGMA database_list').fetchone()[2]).parent
  if mode=='free_apply':return apply(db,storage,args['id'])
  paths=[Path(p).resolve() for p in args.get('paths',[])]
  if not 1<=len(paths)<=5 or any(not p.is_relative_to((storage/'free-uploads').resolve()) for p in paths):raise ValueError('Invalid private upload paths')
  return stage(db,storage,paths)
 if mode=='export':
  rows=db.execute('SELECT * FROM editorial_records ORDER BY id').fetchall();ids=args.get('ids',[])
  records=[]
  for r in rows:
   if ids and r['id'] not in ids:continue
   doc=json.loads(r['document']);doc['base_revision']=str(r['revision']);records.append(doc)
  if not records:raise ValueError('No records selected')
  uid=str(uuid.uuid4());data={'manifest':{'schema_version':'1.0.0','mode':'patch','purpose':'editorial_transfer','export_id':uid,'base_revision':uid,'exported_at':stamp(),'site_key':'tripovela','default_locale':'he','locales':[x['code'] for x in locales(db)],'apply_policy':'stage_drafts_only','omitted_policy':'preserve'},'assets':[json.loads(r[0]) for r in db.execute('SELECT payload FROM editorial_media')],'records':records}
  with db:db.execute('INSERT INTO editorial_exports VALUES(?,?,?)',(uid,canonical(data),stamp()))
  return data
 if mode=='publish_check':
  bundle=operation(db,{'mode':'export','ids':[args['id']]});validate(db,bundle)
  row=bundle['records'][0];ed=row['editorial']
  for lang in ['he','en']:
   tr=ed.get('translations',{}).get(lang,{})
   if not tr.get('title') or not tr.get('summary') and row['record_type'] not in ['destination','category']:raise ValueError('Title and summary required in Hebrew and English')
  if row['record_type'] in ['place','experience'] and not ed.get('destination_ids'):raise ValueError('Destination required')
  if row['record_type'] in ['place','experience','article'] and not ed.get('media'):raise ValueError('Approved cover image required')
  assets={x['asset_id']:x for x in bundle['assets']}
  for ref in ed.get('media',[]):
   if assets.get(ref['asset_id'],{}).get('rights_state')!='approved_by_editor':raise ValueError('Image rights require review')
  return {'state':'valid'}
 if mode=='stage':
  data=args['bundle'];digest=hashlib.sha256(canonical(data).encode()).hexdigest()
  existing=db.execute('SELECT id,state FROM editorial_imports WHERE digest=?',(digest,)).fetchone()
  if existing and existing['state']=='applied':return {'id':existing['id'],'state':'already_applied','changes':[]}
  changes=validate(db,data);uid=existing['id'] if existing else str(uuid.uuid4())
  with db:db.execute('INSERT OR IGNORE INTO editorial_imports VALUES(?,?,?,?,?)',(uid,digest,canonical(data),'staged',stamp()))
  return {'id':uid,'state':'staged','changes':[{k:v for k,v in x.items() if k!='document'} for x in changes]}
 if mode=='apply':
  db.execute('BEGIN IMMEDIATE')
  try:
   job=db.execute('SELECT * FROM editorial_imports WHERE id=?',(args['id'],)).fetchone()
   if not job:raise ValueError('Unknown import')
   if job['state']=='applied':db.rollback();return {'state':'already_applied'}
   changes=validate(db,json.loads(job['payload']))
   for x in changes:
    if not x['changed']:continue
    r=db.execute('SELECT * FROM editorial_records WHERE id=?',(x['id'],)).fetchone()
    db.execute('INSERT INTO editorial_revisions(record_id,revision,document,published,created_at) VALUES(?,?,?,?,?)',(r['id'],r['revision'],r['document'],r['published'],stamp()))
    db.execute('UPDATE editorial_records SET document=?,revision=revision+1,updated_at=? WHERE id=?',(canonical(x['document']),stamp(),x['id']))
   db.execute('UPDATE editorial_imports SET state=? WHERE id=?',('applied',args['id']));db.commit()
   return {'state':'applied','count':sum(x['changed'] for x in changes)}
  except:db.rollback();raise
 if mode=='restore':
  db.execute('BEGIN IMMEDIATE')
  try:
   row=db.execute('SELECT * FROM editorial_records WHERE id=?',(args['id'],)).fetchone()
   old=db.execute('SELECT * FROM editorial_revisions WHERE record_id=? AND revision=?',(args['id'],args['target'])).fetchone()
   if not row or not old or row['revision']!=args['revision']:raise ValueError('Restore revision conflict')
   db.execute('INSERT INTO editorial_revisions(record_id,revision,document,published,created_at) VALUES(?,?,?,?,?)',(row['id'],row['revision'],row['document'],row['published'],stamp()))
   db.execute('UPDATE editorial_records SET document=?,revision=revision+1,updated_at=? WHERE id=?',(old['document'],stamp(),args['id']));db.commit();return {'state':'restored_draft'}
  except:db.rollback();raise
 raise ValueError('Unknown operation')

if __name__=='__main__':
 sys.stdout.reconfigure(encoding='utf-8');sys.stdin.reconfigure(encoding='utf-8')
 try:
  raw=sys.stdin.read(16000001)
  if len(raw)>16000000:raise ValueError('Request too large')
  args=json.loads(raw);db=connect(sys.argv[1]);print(canonical({'ok':True,'result':operation(db,args)}))
 except Exception as e:print(canonical({'ok':False,'error':str(e)[:300]}));sys.exit(1)
