"""Render an allowlisted snapshot preview; no network or database access."""
import json,sys,os
from pathlib import Path
from PIL import Image,ImageOps,ImageDraw,ImageFont
if sys.platform!='win32':
 import resource
 resource.setrlimit(resource.RLIMIT_CPU,(8,8));resource.setrlimit(resource.RLIMIT_AS,(512*1024*1024,512*1024*1024))
try:
 data=json.load(sys.stdin);im=Image.new('RGB',(1200,630),'#eef7f3');draw=ImageDraw.Draw(im)
 if data.get('cover'):
  with Image.open(data['cover']) as cover:
   cover=ImageOps.fit(cover.convert('RGB'),(1200,420));im.paste(cover,(0,0))
 else:
  draw.line([(65,360),(210,180),(380,330),(570,130),(810,370),(1100,230)],fill='#00856f',width=18)
 font_path=next((p for p in ['C:/Windows/Fonts/arial.ttf','/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'] if Path(p).is_file()),None)
 font=ImageFont.truetype(font_path,36) if font_path else ImageFont.load_default()
 small=ImageFont.truetype(font_path,25) if font_path else font
 draw.rounded_rectangle((25,430,1175,610),20,fill='white')
 title=str(data['title'])[:100]
 while draw.textlength(title,font=font)>1070:title=title[:-2]+'…'
 draw.text((55,456),title,font=font,fill='#153b30')
 draw.text((55,540),str(data['count'])+' · Tripovela',font=small,fill='#00856f')
 im.save(sys.argv[1],'PNG',optimize=True)
except Exception:
 sys.exit(1)
