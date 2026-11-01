#!/usr/bin/env python3
"""Export/import local supplier translations; no model service or keys in bundles."""
import argparse, hashlib, json, os, re, sys
from pathlib import Path
from datetime import datetime, timezone
from viator_prices import fetch_json
ROOT=Path(__file__).resolve().parents[1]
FIELDS=('title','description','meeting','inclusions','exclusions','additional','cancellation','itinerary','instructions')
def read(path, default=None):
    return json.loads(path.read_text(encoding='utf-8')) if path.exists() else (default or {})
def write(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    tmp=path.with_suffix(path.suffix+'.tmp');tmp.write_text(json.dumps(data,ensure_ascii=False,indent=2),encoding='utf-8');tmp.replace(path)
def sources():
    data=read(ROOT/'content/provider-catalog.json')
    data.update(read(ROOT/'content/experiences/metadata.json'))
    data.update(read(ROOT/'content/activity-details.json'))
    for code,row in read(ROOT/'content/provider-source.json').items():
        if code in data:
            data[code].update(row);data[code].pop('translations',None)
    return {code:{k:row[k] for k in FIELDS if k in row} for code,row in data.items()}
def digest(row):
    return hashlib.sha256(json.dumps(row,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()).hexdigest()
def export(output):
    current=read(ROOT/'content/provider-translations.json');records=[]
    for code,source in sources().items():
        old=current.get(code,{})
        tr=old.get('translations',{}) if old.get('source')==source else {}
        records.append({'product_code':code,'source_hash':digest(source),'source':source,
                        'translations':{lang:tr.get(lang,{k:(['']*len(v) if isinstance(v,list) else '') for k,v in source.items()}) for lang in ('he','ru')}})
    write(output,{'schema':1,'purpose':'supplier_translation','records':records})
def import_bundle(path,apply=False):
    bundle=read(path);current=sources();out=read(ROOT/'content/provider-translations.json');seen=set()
    if bundle.get('schema')!=1 or bundle.get('purpose')!='supplier_translation':raise ValueError('Wrong schema/purpose')
    for row in bundle['records']:
        code=row['product_code']
        if code in seen or code not in current:raise ValueError('Unknown/duplicate product')
        seen.add(code);source=current[code]
        if row['source']!=source or row['source_hash']!=digest(source):raise ValueError('Source changed: '+code)
        translations=row['translations']
        if set(translations)!={'he','ru'}:raise ValueError('Both he/ru are required')
        for lang,fields in translations.items():
            if set(fields)!=set(source):raise ValueError('Fields changed: '+code)
            for key,value in fields.items():
                original=source[key]
                if isinstance(original,list):
                    if not isinstance(value,list) or len(value)!=len(original):raise ValueError('List length changed')
                    vals=value
                else:vals=[value]
                for text in vals:
                    if not isinstance(text,str) or not text.strip() or len(text)>50000 or re.search(r'<[^>]*>|javascript:|https?://',text,re.I):raise ValueError('Empty/unsafe translation: '+code)
        out[code]={'source':source,'translations':translations,'imported_at':datetime.now(timezone.utc).isoformat()}
    if apply:write(ROOT/'content/provider-translations.json',out)
    print(('Imported' if apply else 'Validated'),len(seen),'products')
def refresh(storage, codes=None):
    # Fetch into staging only: approved source and translations are never overwritten.
    from provider_ingest import stage
    stage(storage,codes or list(sources()))

def main():
    p=argparse.ArgumentParser();sub=p.add_subparsers(dest='cmd',required=True)
    e=sub.add_parser('export');e.add_argument('file',type=Path)
    i=sub.add_parser('import');i.add_argument('file',type=Path);i.add_argument('--apply',action='store_true')
    f=sub.add_parser('refresh-source');f.add_argument('--storage',type=Path,default=ROOT/'storage');f.add_argument('--products',nargs='+')
    a=p.parse_args()
    if a.cmd=='export':export(a.file)
    elif a.cmd=='import':import_bundle(a.file,a.apply)
    else:refresh(a.storage,a.products)
if __name__=='__main__':
    try:main()
    except Exception as exc:print('Failed:',type(exc).__name__,str(exc) if isinstance(exc,ValueError) else '',file=sys.stderr);sys.exit(1)
