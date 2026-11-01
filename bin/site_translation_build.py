from pathlib import Path
import json,re,hashlib,zipfile,collections,datetime,html
import sys
R=Path(sys.argv[1]);O=Path(sys.argv[2]);TARGET=sys.argv[3]
O.mkdir(exist_ok=True)
def read(p,default=None):
 p=R/p;return json.loads(p.read_text(encoding='utf-8-sig')) if p.exists() else default
def write(p,d):
 p=O/p.replace('.ka.',f'.{TARGET}.');p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(d,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
def digest(s):return hashlib.sha256(s.encode()).hexdigest()
units={};entities={};locations={}
def add(entity,kind,path,src,context=None):
 src={k:v.strip() for k,v in src.items() if isinstance(v,str) and v.strip()}
 if not src:return
 primary='en' if kind=='paid_attraction' and src.get('en') else ('he' if src.get('he') else ('en' if src.get('en') else next(iter(src))))
 text=src[primary]
 uid='text-'+digest(primary+'\0'+text)[:24]
 if uid not in units:units[uid]={'id':uid,'source_language':primary,'source':src,'source_hash':digest(text),TARGET:''}
 else:
  for k,v in src.items():units[uid]['source'].setdefault(k,v)
 e=entities.setdefault(entity,{'id':entity,'kind':kind,'context':context or {},'fields':[]})
 ref={'path':path,'text_id':uid}
 if ref not in e['fields']:e['fields'].append(ref)
 locations.setdefault(uid,[])
 ref={'record':entity,'path':path}
 if ref not in locations[uid]:locations[uid].append(ref)
SKIP={'slug','translation_status','workflow_state','id','product_code','source_hash','image','url','official_url','map_query','path','credit','role','asset_id','source_url','checked_at','country_code','country_id','city_id','type','status','record_type','body_format','source','language','format','rights_state','verification_state'}
def flatten(x,path=''):
 if isinstance(x,dict):
  for k,v in x.items():
   if k not in SKIP:yield from flatten(v,path+'/'+str(k))
 elif isinstance(x,list):
  for i,v in enumerate(x):yield from flatten(v,path+'/'+str(i))
 elif isinstance(x,str) and x.strip():yield path,x
def localized(entity,kind,path,tr,context=None):
 maps={l:dict(flatten(v)) for l,v in tr.items() if l in ['he','en','ru']}
 for key in dict.fromkeys(k for m in maps.values() for k in m):
  add(entity,kind,path+key,{l:m[key] for l,m in maps.items() if key in m},context)
def human(s):
 if not isinstance(s,str) or not s.strip():return False
 if re.match(r'^(https?://|/assets/|#[\w-]+$)',s):return False
 return bool(re.search(r'[\u0590-\u05ff\u0400-\u04ff]',s) or re.search(r'[A-Za-z]{2,}\s+[A-Za-z]{2,}',s))
def lang(s):return 'he' if re.search(r'[\u0590-\u05ff]',s) else ('ru' if re.search(r'[\u0400-\u04ff]',s) else 'en')
def tree(entity,kind,x,path='',context=None):
 if isinstance(x,dict):
  if any(l in x for l in ['he','en','ru']):
   if all(isinstance(x[l],str) for l in ['he','en','ru'] if l in x):add(entity,kind,path,{l:x[l] for l in ['he','en','ru'] if l in x},context)
   else:localized(entity,kind,path,x,context)
   for k,v in x.items():
    if k not in ['he','en','ru'] and k not in SKIP:tree(entity,kind,v,path+'/'+str(k),context)
  else:
   for k,v in x.items():
    if k not in SKIP:tree(entity,kind,v,path+'/'+str(k),context)
 elif isinstance(x,list):
  if len(x)==3 and all(isinstance(v,str) for v in x) and re.search(r'[\u0590-\u05ff]',x[0]):add(entity,kind,path,dict(zip(['he','en','ru'],x)),context)
  else:
   for i,v in enumerate(x):tree(entity,kind,v,path+'/'+str(i),context)
 elif human(x):add(entity,kind,path,{lang(x):x},context)

bundle=read('editorial.json');published={x['id']:x['published'] for x in read('published.json')}
for rec in bundle['records']:
 doc=published.get(rec['id']) or rec;ed=doc['editorial'];rid=rec['id']
 ctx={'base_revision':rec['base_revision'],'publication':'published' if published.get(rid) else 'draft','destination_ids':ed.get('destination_ids',[]),'category_ids':ed.get('category_ids',[])}
 localized(rid,rec['record_type'],'/editorial/translations',ed.get('translations',{}),ctx)
 for key in ['media','itinerary_stops']:tree(rid,rec['record_type'],ed.get(key,[]),'/editorial/'+key,ctx)
 # Retain distinct unpublished revisions, clearly separate from live text.
 if published.get(rid) and ed.get('translations')!=rec['editorial'].get('translations'):
  localized(rid+'--draft',rec['record_type'],'/editorial/translations',rec['editorial'].get('translations',{}),{**ctx,'publication':'draft','original_id':rid})
for a in read('articles.json'):
 rid='article-'+str(a['id']).lower()
 if rid not in entities:
  for k in ['title','summary','body']:add(rid,'article','/'+k,{'he':a.get(k,'')},{'url':'/guides/'+a['slug']+'/','publication':a['status']})

sources=read('content/provider-source.json',{});translated=read('content/provider-translations.json',{})
metadata={**read('content/experiences/metadata.json',{}),**read('content/activity-details.json',{}),**read('content/provider-catalog.json',{})}
fields=['title','description','meeting','inclusions','exclusions','additional','cancellation','itinerary','instructions']
for code in sorted(set(metadata)|set(sources)|set(translated)):
 src={**metadata.get(code,{}),**sources.get(code,{})};stored=translated.get(code,{})
 for key,value in stored.get('source',{}).items():src.setdefault(key,value)
 source={k:src[k] for k in fields if k in src}
 tr={'en':source,**stored.get('translations',{})}
 localized('viator-'+code,'paid_attraction','/provider/translations',tr,{'product_code':code,'source_hash':digest(json.dumps(source,ensure_ascii=False,sort_keys=True,separators=(',',':'))),'provider':'Viator','city':metadata.get(code,{}).get('city'),'publication':'catalogue'})
 # Landmark names are independent of itinerary prose.
 tree('viator-'+code,'paid_attraction',src.get('itinerary_context',[]),'/itinerary_context')
for p in read('practical.json')['records']:
 localized(p['id'],'practical','/practical/translations',p['data'].get('translations',{}),{'revision':p['revision']})

# Complete public dictionaries, including runtime overrides.
en=read('content/i18n/en.json',{});ru=read('content/i18n/ru.json',{})
for key,value in read('dictionary-settings.json',{}).items():
 if key.endswith('_en'):en.update(json.loads(value))
 if key.endswith('_ru'):ru.update(json.loads(value))
for he in sorted(set(en)|set(ru)):add('site-dictionary','site_interface','/dictionary/'+he,{'he':he,'en':en.get(he,''),'ru':ru.get(he,'')})
for name in ['legal.json','practical-labels.json','community-labels.json','discovery-taxonomy.json','provider-destinations.json','places.json','planner-ru.json','activity-overviews.json','page-expansions.json','campaign-expansions.json','guide-expansion.json','longform-release.json']:
 tree('content-'+name,'site_content',read('content/'+name,{}),'/'+name)
for p in (R/'content').rglob('*.md'):
 add('markdown-'+p.relative_to(R/'content').as_posix().replace('/','--'),'article_reference','/body_markdown',{lang(p.read_text(encoding='utf-8')):p.read_text(encoding='utf-8')},{'source_file':p.relative_to(R).as_posix(),'role':'fallback_source'})

# Extract text literals, never ship PHP/JS source code to the translation model.
literal=re.compile(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"",re.S)
def decode(q):
 s=q[1:-1];return s.replace('\\'+q[0],q[0]).replace('\\\\','\\').replace('\\n','\n').replace('\\r','\r').replace('\\t','\t')
tokens={}
for f in ['georgian-php-tokens.json','georgian-js-tokens.json']:
 tokens.update(json.loads((R.parent/f).read_text(encoding='utf-8-sig')))
class Token:
 def __init__(self,t):self.t=t
 def start(self):return self.t['start']
 def end(self):return self.t['end']
 def group(self):return self.t['raw']
for base,glob in [('src','*.php'),('views','*.php'),('public/assets','*.js')]:
 for p in (R/base).glob(glob):
  if p.name in ['analytics.php','analytics.js','import.php','partners.php','viator-live.php','viator-affiliate-auto.php','journey-report.php']:continue
  text=p.read_text(encoding='utf-8-sig');rel=p.relative_to(R).as_posix();ms=[Token(t) for t in tokens.get(rel,[]) if not t.get('inline')];covered=set()
  for i in range(len(ms)-2):
   group=ms[i:i+3];vals=[decode(m.group()) for m in group]
   if re.search(r'[\u0590-\u05ff]',vals[0]) and not re.search(r'[\u0590-\u05ff\u0400-\u04ff]',vals[1]) and re.search(r'[\u0400-\u04ff]',vals[2]) and all(re.fullmatch(r'\s*,\s*',text[group[j].end():group[j+1].start()]) for j in [0,1]):
    add('ui-'+p.stem,'site_interface',rel+':'+str(group[0].start()),dict(zip(['he','en','ru'],vals)));covered.update(m.start() for m in group)
  for m in ms:
   if m.start() in covered:continue
   v=decode(m.group())
   if not human(v):continue
   if any(t in v for t in ['SELECT ','CREATE TABLE','INSERT INTO','PRAGMA ','function(','document.','<?php','=>','Content-Security-Policy']):continue
   if '<' in v and '>' in v:
    pieces=re.findall(r'>([^<>]+)<',v)+re.findall(r'(?:title|placeholder|aria-label)=[\"\']([^\"\']+)',v)
   else:pieces=[v]
   for n,s in enumerate(pieces):
    if human(s) and not re.search(r'\$[A-Za-z_]|\{\{?|\}\}?|^[.#][a-zA-Z]|^[a-z-]+[=:]|^data-',s):add('ui-'+p.stem,'site_interface',rel+':'+str(m.start())+'/'+str(n),{lang(s):html.unescape(s)})
  # Plain HTML outside PHP blocks: headings, accessibility text and placeholders.
  if p.suffix=='.php':
   static=''.join(t['raw'] for t in tokens.get(rel,[]) if t.get('inline'))
   for n,s in enumerate(re.findall(r'>([^<>]+)<',static)+re.findall(r'(?:title|placeholder|aria-label)="([^"]+)"',static)):
    if human(s):add('ui-'+p.stem,'site_interface',rel+':html/'+str(n),{lang(s):html.unescape(s)})

manifest={'schema':'tripovela-site-translation/1','export_id':bundle['manifest']['export_id'],'exported_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'target_language':TARGET,'language_name':{'ka':'ქართული'}.get(TARGET,TARGET),'direction':'rtl' if TARGET.split('-')[0] in ['ar','fa','ur'] else 'ltr','apply_policy':'review_before_import','source_release':read('snapshot.json')['release'],'translation_field':TARGET,'immutable_fields':['id','source','source_hash','source_language'],'note':'Translation handoff, not directly accepted by legacy he/ru supplier importer. Return to Codex for validated integration and Georgian activation.'}
rows=list(units.values())
write('TRANSLATE/ALL-TEXTS.ka.json',{'manifest':manifest,'units':rows})
# Small packs bounded by source volume as well as unit count.
chunks=[];chunk=[];size=0
for u in rows:
 n=sum(len(s) for s in u['source'].values())
 if chunk and (size+n>90000 or len(chunk)>=100):chunks.append(chunk);chunk=[];size=0
 chunk.append(u);size+=n
if chunk:chunks.append(chunk)
for i,chunk in enumerate(chunks,1):write(f'TRANSLATE/parts/part-{i:03}.ka.json',{'manifest':manifest,'units':chunk})
for e in entities.values():
 ids=list(dict.fromkeys(f['text_id'] for f in e['fields']));name=re.sub(r'[^A-Za-z0-9_.-]','_',e['id'])
 write('BY-CONTENT/'+e['kind']+'/'+name+'.ka.json',{'manifest':manifest,'record':e,'units':[units[k] for k in ids]})
write('REFERENCE/content-index.json',list(entities.values()))
write('REFERENCE/text-locations.json',locations)
write('REFERENCE/export-manifest.json',bundle['manifest'])
summary={'unique_texts':len(rows),'translation_parts':len(chunks),'content_records':len(entities),'record_types':dict(collections.Counter(e['kind'] for e in entities.values())),'source_editorial_records':dict(collections.Counter(r['record_type'] for r in bundle['records'])),'supplier_products':sum(e['kind']=='paid_attraction' for e in entities.values()),'source_characters':sum(len(u['source'][u['source_language']]) for u in rows),'target_language':TARGET,'public_site_unchanged':True}
write('SUMMARY.json',summary)
print(json.dumps(summary,ensure_ascii=False,indent=2))

