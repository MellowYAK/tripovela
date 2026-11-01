"""Versioned practical data; independent of article translation revisions."""
import json,re
from urllib.parse import urlsplit,parse_qs

def operation(db,args):
 from content_transfer import canonical,stamp,safe_url,merge,locales
 db.execute('CREATE TABLE IF NOT EXISTS place_practical(id TEXT PRIMARY KEY,revision INTEGER NOT NULL,document TEXT NOT NULL,updated_at TEXT)')
 def get(id):
  row=db.execute('SELECT document FROM editorial_records WHERE id=?',(id,)).fetchone()
  if not row:raise ValueError('Unknown content ID')
  ed=json.loads(row[0])['editorial'];free={}
  try:
   f=db.execute('SELECT payload FROM free_import_state WHERE id=?',(id,)).fetchone()
   if f:free=json.loads(f[0])
  except Exception:pass
  query=parse_qs(urlsplit(free.get('location',{}).get('map_search_url','')).query).get('query',[''])[0]
  default={'official_url':free.get('official_url',ed.get('official_url','')) or '', 'map_query':query,'timezone':'','latitude':None,'longitude':None,'checked_at':free.get('admission',{}).get('checked_at',''),'visibility':dict.fromkeys(['address','map','official','hours','directions'],True),'translations':{x['code']:dict.fromkeys(['address','hours','directions','special_notes','accessibility','booking_notes','seasonal_notes','contact'],'') for x in locales(db)}}
  saved=db.execute('SELECT * FROM place_practical WHERE id=?',(id,)).fetchone()
  return {'id':id,'revision':saved['revision'] if saved else 0,'data':merge(default,json.loads(saved['document'])) if saved else default}
 if args['mode']=='practical_export':
  ids=args.get('ids') or [r[0] for r in db.execute('SELECT id FROM editorial_records ORDER BY id')]
  return {'schema':'tripovela-practical/1','records':[get(id) for id in ids]}
 if args['mode']=='practical_save':
  bundle=args['bundle']
  if bundle.get('schema')!='tripovela-practical/1':raise ValueError('Wrong practical schema')
  seen=set()
  with db:
   for row in bundle['records']:
    id=row['id'];old=get(id)
    if id in seen or row['revision']!=old['revision']:raise ValueError('Practical revision conflict')
    seen.add(id);p=merge(old['data'],row['data'])
    if set(p)-set(old['data']):raise ValueError('Unknown practical field')
    for key in ['official_url','map_query','checked_at','timezone']:
     if not isinstance(p[key],str) or len(p[key])>2000 or '<' in p[key]:raise ValueError('Invalid practical text')
    if p['official_url']:safe_url(p['official_url'])
    if p['checked_at'] and not re.fullmatch(r'\d{4}-\d{2}-\d{2}',p['checked_at']):raise ValueError('Invalid date')
    for key,limit in [('latitude',90),('longitude',180)]:
     if p[key] is not None and (type(p[key]) not in (int,float) or not -limit<=p[key]<=limit):raise ValueError('Invalid coordinate')
    if (p['latitude'] is None)!=(p['longitude'] is None):raise ValueError('Both coordinates required')
    if set(p['visibility'])!={'address','map','official','hours','directions'} or any(type(v)!=bool for v in p['visibility'].values()):raise ValueError('Invalid visibility')
    if set(p['translations'])-{x['code'] for x in locales(db)}:raise ValueError('Unknown translation locale')
    for tr in p['translations'].values():
     if set(tr)-{'address','hours','directions','special_notes','accessibility','booking_notes','seasonal_notes','contact'}:raise ValueError('Unknown translated field')
     if any(not isinstance(v,str) or len(v)>15000 or re.search(r'<[^>]*>|javascript:',v,re.I) for v in tr.values()):raise ValueError('Invalid translated text')
    db.execute('INSERT INTO place_practical VALUES(?,1,?,?) ON CONFLICT(id) DO UPDATE SET revision=revision+1,document=excluded.document,updated_at=excluded.updated_at',(id,canonical(p),stamp()))
  return {'state':'practical_saved','count':len(seen)}
 raise ValueError('Unknown practical mode')
