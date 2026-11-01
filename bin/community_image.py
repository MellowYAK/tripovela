"""Decode and re-encode uploads; strip metadata, scripts and original file names."""
import sys,warnings
from PIL import Image,ImageOps
if sys.platform != 'win32':
 import resource
 resource.setrlimit(resource.RLIMIT_CPU,(15,15))
 resource.setrlimit(resource.RLIMIT_AS,(512*1024*1024,512*1024*1024))
 resource.setrlimit(resource.RLIMIT_FSIZE,(10*1024*1024,10*1024*1024))
Image.MAX_IMAGE_PIXELS=20000000
warnings.simplefilter('error',Image.DecompressionBombWarning)
try:
 with Image.open(sys.argv[1]) as im:
  if im.format not in ('JPEG','PNG','WEBP'):raise ValueError('format')
  im.load();im=ImageOps.exif_transpose(im).convert('RGB');im.thumbnail((1800,1800));im.save(sys.argv[2],'JPEG',quality=85,optimize=True)
except Exception:
 sys.exit(1)
