#!/usr/bin/env python3
"""Versioned free-attraction adapter for the existing editorial catalogue. Never publishes."""
import hashlib,io,json,re,stat,uuid,zipfile
from pathlib import Path,PurePosixPath
from content_transfer import canonical,stamp,safe_url
from PIL import Image
SCHEMA='tripovela-free-attractions/1.0.0'
KNOWN={'cz-prague':'dest-prague','hu-budapest':'dest-budapest','ge-tbilisi':'dest-tbilisi','us-new-york-city':'dest-new-york'}
def digest(x):return hashlib.sha256(canonical(x).encode()).hexdigest()
def tables(db):
 db.executescript('CREATE TABLE IF NOT EXISTS free_import_state(id TEXT PRIMARY KEY,input_hash TEXT,document_hash TEXT,payload TEXT); CREATE TABLE IF NOT EXISTS free_import_jobs(id TEXT PRIMARY KEY,payload TEXT,created_at TEXT);')
def identifier(x):
 if not isinstance(x,str) or not re.fullmatch('[a-zA-Z0-9_-]{2,90}',x):raise ValueError('Invalid identifier')
 return x

def read_zip(path):
 if Path(path).stat().st_size>40_000_000:raise ValueError('ZIP exceeds 40 MB')
 with zipfile.ZipFile(path) as z:
  entries=z.infolist()
  if len(entries)>400 or sum(i.file_size for i in entries)>100_000_000:raise ValueError('ZIP expanded size/file limit exceeded')
  names=set()
  for i in entries:
   n=PurePosixPath(i.filename)
   if i.filename in names or n.is_absolute() or '..' in n.parts or '\\' in i.filename or ':' in i.filename or stat.S_ISLNK(i.external_attr>>16):raise ValueError('Unsafe or duplicate ZIP path')
   names.add(i.filename)
   if i.flag_bits&1:raise ValueError('Encrypted ZIP is unsupported')
   if not i.is_dir() and n.suffix.lower() not in ('.webp','.json','.md','.html','.py'):raise ValueError('Unsupported ZIP file type')
  # Ancillary previews/docs/code are not extracted, served or executed.
  candidates=[n for n in names if re.search(r'/part-\d+\.import\.json$',n)]
  if len(candidates)!=1:raise ValueError('Expected one part import JSON')
  name=candidates[0]
  if z.getinfo(name).file_size>2_000_000:raise ValueError('JSON too large')
  data=json.loads(z.read(name));base=name.rsplit('/',1)[0]+'/'
  media={}
  for a in data.get('assets',[]):
   f=a.get('file','');p=PurePosixPath(f)
   if not re.fullmatch(r'assets/[a-z0-9_-]+/(cover|detail)\.webp',f):raise ValueError('Unsafe media path')
   member=base+f
   if member not in names:raise ValueError('Missing media: '+f)
   if z.getinfo(member).file_size>8_000_000:raise ValueError('Image too large')
   raw=z.read(member)
   if hashlib.sha256(raw).hexdigest()!=a.get('sha256'):raise ValueError('Image hash mismatch: '+f)
   with Image.open(io.BytesIO(raw)) as im:
    if im.format!='WEBP' or im.size!=(a.get('width'),a.get('height')) or im.width*im.height>16_000_000 or getattr(im,'n_frames',1)!=1:raise ValueError('Invalid image: '+f)
    im.verify()
   media[a['id']]=raw
  return data,media

def validate(data):
 m=data.get('manifest',{})
 if m.get('schema')!=SCHEMA or m.get('apply_policy')!='stage_drafts' or m.get('omission_policy')!='preserve_existing':raise ValueError('Unsupported schema/import policy')
 if set(m.get('locales',[]))!={'he','en'}:raise ValueError('Expected Hebrew and English')
 groups={}
 for key in ('countries','cities','categories','records','assets'):
  rows=data.get(key,[]);ids=[identifier(r['id']) for r in rows]
  if len(ids)!=len(set(ids)):raise ValueError('Duplicate '+key+' ID')
  groups[key]={r['id']:r for r in rows}
 if len(groups['records'])!=m.get('record_count') or len(groups['assets'])!=m.get('media_count') or len(groups['assets'])!=2*len(groups['records']):raise ValueError('Record/media count mismatch')
 if not 1<=len(groups['records'])<=50:raise ValueError('Invalid record count')
 for c in groups['cities'].values():
  if c['country_id'] not in groups['countries']:raise ValueError('Unknown city country')
 for r in groups['records'].values():
  if r.get('record_type')!='independent_attraction' or r.get('provider') is not None or r.get('affiliate_url') is not None:raise ValueError('Expected independent attraction')
  if r.get('city_id') not in groups['cities'] or groups['cities'][r['city_id']]['country_id']!=r['country_id']:raise ValueError('City/country mismatch')
  if not r.get('category_ids') or any(k not in groups['categories'] for k in r['category_ids']):raise ValueError('Unknown category')
  a=r.get('admission',{})
  if a.get('amount')!=0 or a.get('type')!='free_for_described_visit' or a.get('live_price_feed') is not False:raise ValueError('Invalid free admission classification')
  for lang in ('he','en'):
   tr=r.get('translations',{}).get(lang,{})
   if any(not tr.get(k) for k in ('title','summary','body_markdown','planning_tip','image_disclosure')):raise ValueError('Missing translation')
   if not a.get('free_visit_scope',{}).get(lang) or not a.get('optional_paid_extras',{}).get(lang):raise ValueError('Missing admission scope')
   if any(re.search(r'<[^>]+>|javascript:|data:|vbscript:',str(v),re.I) or len(str(v))>200000 for v in tr.values()):raise ValueError('Unsafe translated text')
  safe_url(r['official_url']);safe_url(r['location']['map_search_url'])
  for src in r.get('provenance',[]):safe_url(src['url'])
  refs=r.get('media',[])
  if len(refs)!=2 or {x['role'] for x in refs}!={'cover','detail'}:raise ValueError('Cover/detail pair required')
  for x in refs:
   if x['asset_id'] not in groups['assets'] or groups['assets'][x['asset_id']]['file']!=x['file']:raise ValueError('Broken media reference')
 return groups

def doc(id,kind,ed):return {'id':id,'record_type':kind,'operation':'upsert','base_revision':'1','context':{'provider_bindings':[],'provider_owned_fields':[]},'editorial':{'workflow_state':'draft',**ed}}
def translations(names):return {l:{'title':v,'translation_status':'human_review_required'} for l,v in names.items()}
def map_data(db,data):
 g=validate(data);docs={};citymap={};used={r['city_id'] for r in data['records']}
 existing={r['id']:json.loads(r['document']) for r in db.execute('SELECT id,document FROM editorial_records')}
 for cid in used:
  c=g['cities'][cid];co=g['countries'][c['country_id']];iso=co['iso2'];countryid='free-country-'+iso.lower()
  if not re.fullmatch('[A-Z]{2}',iso):raise ValueError('Invalid ISO2')
  if countryid not in existing:docs[countryid]=doc(countryid,'destination',{'destination_kind':'country','country_code':iso,'translations':translations(co['names'])})
  candidates=[id for id,d in existing.items() if d['record_type']=='destination' and d['editorial'].get('destination_kind')=='city' and d['editorial'].get('country_code')==iso and d['editorial'].get('translations',{}).get('en',{}).get('title','').casefold()==c['names']['en'].casefold()]
  if cid in KNOWN and KNOWN[cid] in existing:candidates=[KNOWN[cid]]
  if len(candidates)>1:raise ValueError('Ambiguous city mapping: '+cid)
  id=candidates[0] if candidates else 'free-city-'+cid;citymap[cid]=id
  if id not in existing:docs[id]=doc(id,'destination',{'destination_kind':'city','country_code':iso,'parent_destination_id':countryid,'translations':translations(c['names'])})
 for r in data['records']:
  id='free50-'+r['id'];tr={l:{k:v for k,v in r['translations'][l].items() if k in ('title','summary','body_markdown','seo_title','seo_description')} for l in ('he','en')}
  for l in tr:tr[l].update(slug=r['slug'],translation_status='human_review_required')
  cats=[]
  for k in r['category_ids']:
   category={'nature':'cat-nature','culture':'cat-city'}.get(k,'free-cat-'+k);cats.append(category)
   if category not in existing:docs[category]=doc(category,'category',{'translations':translations(g['categories'][k]['names'])})
  provenance=[{'source_id':'source-'+hashlib.sha256(x['url'].encode()).hexdigest()[:20],'url':x['url'],'publisher':x['publisher_domain'],'checked_at':x['checked_at']+'T00:00:00Z','verification_state':'unverified','supports':x['supports']} for x in r['provenance']]
  docs[id]=doc(id,'place',{'place_kind':'museum' if 'museums' in r['category_ids'] else 'other','translations':tr,'destination_ids':[citymap[r['city_id']]],'category_ids':cats,'tags':r['interest_ids'],'official_url':r['official_url'],'provenance':provenance,'visit_cost':{'classification':'free','source_id':provenance[0]['source_id'],'verified_at':provenance[0]['checked_at']},'media':[{'asset_id':'free50-'+x['asset_id'],'role':'cover' if x['role']=='cover' else 'inline','alt':x['alt'],'caption':x['caption']} for x in r['media']]})
 return docs,citymap

def preview(db,data):
 docs,mapping=map_data(db,data);new=[];unchanged=[];updates=[];conflicts=[]
 existing={r['id']:r for r in db.execute('SELECT * FROM editorial_records')}
 for id,d in docs.items():
  current=existing.get(id);state=db.execute('SELECT * FROM free_import_state WHERE id=?',(id,)).fetchone()
  if current:
   if not state or digest(json.loads(current['document']))!=state['document_hash']:conflicts.append(id+': manually edited or unmanaged')
   elif digest(d)==state['document_hash']:unchanged.append(id)
   else:updates.append(id)
  else:new.append(id)
  for other in existing.values():
   if other['id']==id:continue
   od=json.loads(other['document'])
   if od['record_type']!=d['record_type']:continue
   for l,t in d['editorial'].get('translations',{}).items():
    if t.get('slug') and t['slug']==od['editorial'].get('translations',{}).get(l,{}).get('slug'):conflicts.append(id+': slug collision '+other['id'])
 return {'new':new,'updates':updates,'unchanged':unchanged,'conflicts':list(set(conflicts)),'city_mapping':mapping,'attractions':len(data['records']),'media':len(data['assets']),'locales':['he','en'],'missing_locales':['ru']},docs

def stage(db,storage,paths):
 tables(db);records={};assets={};shared={};media={}
 for path in paths:
  data,images=read_zip(path);validate(data)
  for key in ('countries','cities','categories'):
   dest=shared.setdefault(key,{})
   for row in data[key]:
    if row['id'] in dest and dest[row['id']]!=row:raise ValueError('Conflicting shared dictionary')
    dest[row['id']]=row
  for row in data['records']:
   if row['id'] in records and records[row['id']]!=row:raise ValueError('Conflicting record across parts')
   records[row['id']]=row
  for a in data['assets']:
   if a['id'] in assets and assets[a['id']]!=a:raise ValueError('Conflicting asset across parts')
   assets[a['id']]=a
  media.update(images)
 bundle={'manifest':{**data['manifest'],'record_count':len(records),'media_count':len(assets)},**{k:list(v.values()) for k,v in shared.items()},'records':list(records.values()),'assets':list(assets.values())}
 result,_=preview(db,bundle);uid=uuid.uuid4().hex;directory=Path(storage)/'free-imports'/uid;directory.mkdir(parents=True)
 for a in assets.values():(directory/(a['sha256']+'.webp')).write_bytes(media[a['id']])
 with db:db.execute('INSERT INTO free_import_jobs VALUES(?,?,?)',(uid,canonical(bundle),stamp()))
 return {'state':'free_dry_run','job':uid,**result}

def apply(db,storage,job):
 if not re.fullmatch('[a-f0-9]{32}',job):raise ValueError('Invalid job')
 db.execute('BEGIN IMMEDIATE')
 try:
  row=db.execute('SELECT payload FROM free_import_jobs WHERE id=?',(job,)).fetchone()
  if not row:raise ValueError('Unknown job')
  data=json.loads(row[0]);result,docs=preview(db,data)
  if result['conflicts']:raise ValueError('Conflicts require review: '+', '.join(result['conflicts'][:3]))
  assets={a['id']:a for a in data['assets']};destination=Path(storage)/'free-media';destination.mkdir(exist_ok=True)
  for a in assets.values():
   src=Path(storage)/'free-imports'/job/(a['sha256']+'.webp');raw=src.read_bytes()
   if hashlib.sha256(raw).hexdigest()!=a['sha256']:raise ValueError('Staged image changed')
   target=destination/(a['sha256']+'.webp')
   if not target.exists():target.write_bytes(raw)
   asset={'asset_id':'free50-'+a['id'],'url':'https://tripovela.com/assets/free50/'+a['sha256']+'.webp','media_type':'image/webp','credit':'AI-generated illustration; not a documentary photograph.','license_url':None,'source_url':None,'rights_state':'unverified'}
   db.execute('INSERT OR IGNORE INTO editorial_media VALUES(?,?)',(asset['asset_id'],canonical(asset)))
  originals={'free50-'+r['id']:r for r in data['records']}
  for id,d in docs.items():
   if id in result['unchanged']:continue
   old=db.execute('SELECT * FROM editorial_records WHERE id=?',(id,)).fetchone()
   if old:
    db.execute('INSERT INTO editorial_revisions(record_id,revision,document,published,created_at) VALUES(?,?,?,?,?)',(id,old['revision'],old['document'],old['published'],stamp()))
    db.execute('UPDATE editorial_records SET document=?,revision=revision+1,updated_at=? WHERE id=?',(canonical(d),stamp(),id))
   else:db.execute('INSERT INTO editorial_records VALUES(?,?,1,?,NULL,?)',(id,d['record_type'],canonical(d),stamp()))
   payload=originals.get(id,{})
   db.execute('INSERT OR REPLACE INTO free_import_state VALUES(?,?,?,?)',(id,digest(payload),digest(d),canonical(payload)))
  db.commit();return {'state':'free_drafts_saved','published':0,**result}
 except:db.rollback();raise
