"""usage: python3 tests/dep_lots.py <Depreciation workbook.xlsx>  (needs the local servers: tests/run_servers.sh)
Checks depBlock/depLen of the app against the per-lot 'Model $/lb' column of the workbook."""
# check app depBlock/depLen against the workbook's per-lot "Model $/lb" column (v5 file)
import openpyxl, json, sys
from playwright.sync_api import sync_playwright
F=sys.argv[1]
wb=openpyxl.load_workbook(F,data_only=True)
lots=[]
for name in ['PRD-N','PDF-N']:
    ws=wb[name]
    for r in range(78,ws.max_row+1):
        if ws.cell(r,1).value is None: continue
        kind,od,L,od2,L2,days=[ws.cell(r,c).value for c in (2,3,4,5,6,8)]
        if ws.cell(r,18).value not in (None,''): continue
        if not isinstance(od,(int,float)): continue
        lots.append(dict(sheet=name,kind=kind,od=od,L=L,od2=od2,L2=L2,days=days or 0,m=ws.cell(r,14).value))
ws=wb['PFR']
for r in range(63,ws.max_row+1):
    d=[ws.cell(r,c).value for c in (3,4,5)]
    if ws.cell(r,1).value is None or ws.cell(r,18).value not in (None,''): continue
    if not all(isinstance(x,(int,float)) for x in d): continue
    lots.append(dict(sheet='PFR',dims=d,m=ws.cell(r,14).value))
print(len(lots),'lots')
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_page()
    B='http://127.0.0.1:8081'
    pg.route('**/*',lambda r:(r.fulfill(path='/tmp/xl/node_modules/exceljs/dist/exceljs.min.js',content_type='text/javascript') if 'exceljs' in r.request.url else r.continue_() if r.request.url.startswith('http://127.0.0.1') else r.abort()))
    pg.goto(B+'/'); pg.fill('#em','bob@ei6.test'); pg.fill('#pw','bob-pass'); pg.click('#go'); pg.wait_for_url('**/app'); pg.wait_for_timeout(800)
    res=pg.evaluate("""(lots)=>lots.map(l=>{
      if(l.sheet==='PFR') return depBlock(l.dims).v;
      const p=l.sheet==='PRD-N'?DEP.rods:DEP.rounds;
      return l.kind==='Collar'?depLen(p,l.od,l.L,l.od2,l.L2).v:depLen(p,l.od,l.L).v;})""",lots)
    bad=[(l,v) for l,v in zip(lots,res) if abs(v-l['m'])>1e-6]
    aged=[x for x in bad if x[0].get('days',0)>=730]   # workbook applies the AGED retention to old lots; the tool values new Offall (age 0), so only those may differ
    print('mismatches',len(bad),'(aged lots:',len(aged),')'); print('non-aged mismatches:',[x for x in bad if x[0].get('days',0)<730]); pass
    sys.exit(0 if len(bad)==len(aged) else 1)
