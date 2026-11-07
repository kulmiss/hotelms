"""Makes the developer-doc diagrams (docs/images/*.svg). Plain SVG, white background (reads in light and dark)."""
import html
import os
OUT=os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'docs', 'images') + os.sep
TEAL='#0d9488'; INK='#18181b'; MUTED='#71717a'; LINE='#a1a1aa'; SOFT='#f4f4f5'; ORANGE='#ea580c'; RED='#dc2626'

class D:
    def __init__(s,w,h,title):
        s.w,s.h=w,h; s.p=[f'<rect width="{w}" height="{h}" rx="12" fill="#fff"/>',
          f'<text x="24" y="34" font-size="16" font-weight="700" fill="{INK}">{html.escape(title)}</text>']
    def box(s,x,y,w,h,lines,fill=SOFT,stroke=LINE,color=INK,bold_first=True):
        s.p.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="10" fill="{fill}" stroke="{stroke}" stroke-width="1.5"/>')
        n=len(lines); y0=y+h/2-(n-1)*9
        for i,l in enumerate(lines):
            fw='700' if (i==0 and bold_first) else '400'; c=color if i==0 else MUTED
            s.p.append(f'<text x="{x+w/2}" y="{y0+i*18+4.5}" text-anchor="middle" font-size="{13 if i==0 else 12}" font-weight="{fw}" fill="{c}">{html.escape(l)}</text>')
    def arrow(s,pts,label=None,color=LINE,lx=None,ly=None,dash=False,anchor='middle'):
        d='M'+' L'.join(f'{x},{y}' for x,y in pts)
        m={'#a1a1aa':'a','#0d9488':'t','#dc2626':'r','#ea580c':'o'}[color]
        s.p.append(f'<path d="{d}" fill="none" stroke="{color}" stroke-width="1.8" {"stroke-dasharray=\"5 4\"" if dash else ""} marker-end="url(#{m})"/>')
        if label:
            if lx is None: (x1,y1),(x2,y2)=pts[0],pts[1]; lx,ly=(x1+x2)/2,(y1+y2)/2-7
            s.p.append(f'<text x="{lx}" y="{ly}" text-anchor="{anchor}" font-size="12" fill="{MUTED}" paint-order="stroke" stroke="#fff" stroke-width="4">{html.escape(label)}</text>')
    def text(s,x,y,t,size=12,color=MUTED,anchor='start',weight='400'):
        s.p.append(f'<text x="{x}" y="{y}" text-anchor="{anchor}" font-size="{size}" font-weight="{weight}" fill="{color}">{html.escape(t)}</text>')
    def save(s,name):
        marks=''.join(f'<marker id="{k}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="{c}"/></marker>' for k,c in [('a',LINE),('t',TEAL),('r',RED),('o',ORANGE)])
        svg=(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {s.w} {s.h}" width="{s.w}" height="{s.h}" font-family="Inter, Segoe UI, Roboto, Arial, sans-serif">'
             f'<defs>{marks}</defs>'+''.join(s.p)+'</svg>\n')
        open(OUT+name,'w').write(svg)

# 1. How a request travels
d=D(900,330,'How one request travels')
y=90
d.box(24,y,150,70,['Browser','React app'],fill='#f0fdfa',stroke=TEAL)
d.box(214,y,150,70,['auth:sanctum','who are you?'])
d.box(404,y,150,70,['tenant','hotel = user.hotel_id'],fill='#fff7ed',stroke=ORANGE)
d.box(594,y,130,70,['can:… / module','allowed?'])
d.box(764,y,112,70,['Controller','→ Service'])
for a,b in [(174,214),(364,404),(554,594),(724,764)]: d.arrow([(a,y+35),(b,y+35)])
d.text(24,y-14,'GET /api/v1/reservations/42 (cookie session)')
d.box(764,220,112,60,['MySQL','scoped query'],fill='#f0fdfa',stroke=TEAL)
d.arrow([(820,160),(820,220)])
d.arrow([(764,250),(99,250),(99,160)],label='JSON  { "data": … }  money as strings "4662.50"',color=TEAL,lx=430,ly=243)
d.text(404,190,'Sets TenantContext before route binding,',size=11)
d.text(404,205,'so {reservation} is already this hotel only.',size=11)
d.text(24,312,'401 = not signed in · 403 = no permission / module off · 404 = not found or another hotel\'s record · 422 = check the form',size=11.5)
d.save('request-flow.svg')

# 2. Tenancy: three layers
d=D(900,340,'Hotel isolation: three layers (a leak between hotels is the worst bug)')
cols=[('1. Database','every hotel table has hotel_id','(and branch_id where it matters)'),
      ('2. Model','BelongsToHotel trait: where hotel_id = current','fills hotel_id; no hotel → no rows'),
      ('3. Request','hotel comes from the signed-in user','never from the URL, body or a header')]
for i,(a,b,c) in enumerate(cols):
    x=24+i*292
    d.box(x,64,268,96,[a,b,c],fill='#fff7ed' if i==1 else SOFT,stroke=ORANGE if i==1 else LINE)
d.text(24,196,'Not scoped — add the filter yourself:',size=13,color=INK,weight='700')
for i,t in enumerate(['• User (sign-in is platform-wide) → where hotel_id = …','• DB::table(...) raw queries → where hotel_id = …']): d.text(24,222+i*22,t)
for i,t in enumerate(['• Jobs / seeders → TenantContext::runAs($hotel, fn () => …)','• Super Admin views → acrossAllHotels(fn () => …)']): d.text(460,222+i*22,t)
d.text(24,300,'Tested by tests/Feature/Tenancy/IsolationSweepTest.php: every API route, called as another hotel and as a one-branch user.',size=11.5)
d.save('tenancy.svg')

# 3. Booking status
d=D(900,330,'Booking (reservation) status')
B={'pending':(40,150),'confirmed':(230,150),'checked_in':(440,150),'checked_out':(660,150),'cancelled':(230,262),'no_show':(440,262)}
for k,(x,y) in B.items():
    good=k in('confirmed','checked_in','checked_out')
    d.box(x,y-28,170 if k!='pending' else 150,56,[k],fill='#f0fdfa' if good else SOFT,stroke=TEAL if good else LINE)
d.arrow([(190,150),(230,150)],'confirm',lx=210,ly=112)
d.arrow([(400,150),(440,150)],'check in',lx=420,ly=112)
d.arrow([(610,150),(660,150)],'check out',lx=635,ly=112,color=TEAL)
d.arrow([(115,178),(115,262),(230,262)],'cancel',lx=125,ly=230,anchor='start')
d.arrow([(315,178),(315,234)],'cancel',lx=325,ly=212,anchor='start')
d.arrow([(370,178),(370,206),(500,206),(500,234)],'no-show (after cut-off)',lx=510,ly=226,anchor='start')
d.box(40,52,150,40,['walk-in'],fill='#fff7ed',stroke=ORANGE)
d.arrow([(190,72),(525,72),(525,122)],'walk-in / quick check-in: straight to checked_in',color=ORANGE,lx=360,ly=64)
d.text(660,214,'Early leave: nights stayed + fee.',size=11.5)
d.text(660,232,'Overpay needs a "yes".',size=11.5)
d.text(660,250,'Hourly overstay: extra hours.',size=11.5)
d.text(24,314,'Code: app/Services/Booking/ReservationService.php, FrontDesk.php · Reservation::STATUSES',size=11.5)
d.save('booking-status.svg')

# 4. Room status
d=D(900,320,'Room status cycle')
R={'available':(40,130),'occupied':(260,130),'dirty':(480,130),'cleaning':(690,130)}
for k,(x,y) in R.items(): d.box(x,y-28,160,56,[k],fill='#f0fdfa' if k=='available' else SOFT,stroke=TEAL if k=='available' else LINE)
d.arrow([(200,130),(260,130)],'check in',lx=230,ly=120)
d.arrow([(420,130),(480,130)],'check out',lx=450,ly=120)
d.arrow([(640,130),(690,130)],'start',lx=665,ly=120)
d.arrow([(770,158),(770,200),(120,200),(120,158)],'cleaner taps "Done" → available',color=TEAL,lx=445,ly=192)
d.text(450,82,'room move: old room → dirty, new room → occupied',size=11.5,anchor='middle')
d.box(40,236,200,50,['maintenance'])
d.box(270,236,200,50,['out_of_order'])
d.text(500,258,'Set by a manager. Not sold until set back to available.',size=11.5)
d.text(500,276,'Code: FrontDesk.php, Modules/Housekeeping.php · Room::STATUSES',size=11.5)
d.save('room-status.svg')
print('ok')
