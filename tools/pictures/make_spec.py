"""Build an annotate.py spec from marks measured in the browser (see tools/pictures/guide-pictures.md) and run it.

usage: python3 tools/pictures/make_spec.py <key> marks.json screenshot.jpg <screenshot width in px>
"""
import json,sys,subprocess
# usage: make.py key marks.json image.jpg frameW
key, mj, img, fw = sys.argv[1], sys.argv[2], sys.argv[3], float(sys.argv[4])
m=json.load(open(mj)); k=fw/m['iw']
marks=[x for x in m['marks'] if not x.get('missing')]
sy,ih,iw=m['sy'],m['ih'],m['iw']
needs_side=any(x['box'][0] < m['mainX'] for x in marks)
ox = 0 if needs_side else max(0, m['mainX']-12)
ys=[x['box'][1] for x in marks]; ye=[x['box'][1]+x['box'][3] for x in marks]
oy=max(sy, min(ys)-70); bottom=min(sy+ih, max(ye)+70)
if bottom-oy < 330: bottom=min(sy+ih, oy+330)
spec={'out':f'./frontend/public/guide/{key}.webp','tiles':[{'src':img,'at':[0,sy]}],'origin':[ox,oy],
      'size':[round((iw-ox)*k), round((bottom-oy)*k)],'scale':k,
      'marks':[{'n':x['n'],'box':x['box'],'side':x.get('side','left')} for x in marks]}
import os; os.makedirs('/tmp/guide-pics', exist_ok=True); json.dump(spec,open(f'/tmp/guide-pics/{key}.json','w'))
print(subprocess.run(['python3','./tools/pictures/annotate.py',f'/tmp/guide-pics/{key}.json'],capture_output=True,text=True))
print(spec['size'], [x['n'] for x in m['marks'] if x.get('missing')])
