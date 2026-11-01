"""Check the staged public distribution without loading application data."""
from pathlib import Path
import json,re,subprocess,sys

root=Path(__file__).resolve().parents[1]
files=subprocess.check_output(['git','ls-files','-z'],cwd=root).decode().split('\0')
errors=[]
secret_patterns=[r'-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----',r'\bAKIA[0-9A-Z]{16}\b',r'\bgh[pousr]_[A-Za-z0-9]{30,}\b',r'\bgithub_pat_[A-Za-z0-9_]{40,}\b']
for name in filter(None,files):
 p=Path(name)
 if (p.parts[0] in ['storage','vendor','.venv','output','tmp'] and name!='storage/.gitkeep') or (p.name=='.env') or p.suffix.lower() in ['.sqlite','.sqlite3','.db','.log','.pem','.key','.zip']:
  errors.append('Runtime or credential file staged: '+name)
 if p.suffix in ['.php','.py','.js','.json','.md','.html','.sql']:
  text=(root/p).read_text(encoding='utf-8-sig')
  if any(re.search(pattern,text) for pattern in secret_patterns):errors.append('Possible secret in '+name)
languages=set(json.loads((root/'content/interface-languages.json').read_text(encoding='utf-8')))
for name in ['admin-release-labels.json','install-labels.json']:
 data=json.loads((root/'content'/name).read_text(encoding='utf-8'))
 if set(data)!=languages:errors.append(name+': language mismatch')
 for lang,values in data.items():
  if set(values)!=set(data['en']):errors.append(name+': missing keys in '+lang)
  for key,value in values.items():
   if not isinstance(value,str) or not value.strip():errors.append(name+': empty '+lang+'/'+key)
   elif set(re.findall(r'\{\w+\}',value))!=set(re.findall(r'\{\w+\}',data['en'][key])):errors.append(name+': placeholder mismatch '+lang+'/'+key)
m=json.loads((root/'content/measurement.json').read_text())
if m.get('enabled') or m.get('reddit_enabled') or m.get('measurement_id') or m.get('reddit_pixel_id'):errors.append('Measurement must be unconfigured in the distribution')
for name in ['places.json','community-destinations.json','provider-catalog.json','experiences/catalog.json']:
 if json.loads((root/'content'/name).read_text()):errors.append('Production catalog data included: '+name)
if errors:
 print('\n'.join(errors));sys.exit(1)
print(f'PASS: {len([f for f in files if f])} tracked files; runtime exclusions; secret patterns; 14-language key and placeholder coverage; empty catalogs; tracking disabled')
