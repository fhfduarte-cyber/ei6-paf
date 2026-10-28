"""One-off conversion of the legacy single-file calculator into templates/app.html.
Replaces the embedded datasets by values injected by the server (see src/App.php)."""
import sys,re
src,dst=sys.argv[1:3]
s=open(src,encoding='utf8').read()
def block(name):
    i=s.index(f'const {name}=');j=s.index('=',i)+1;d=0;ins=None;k=j
    while k<len(s):
        c=s[k]
        if ins:
            if c=='\\':k+=1
            elif c==ins:ins=None
        elif c in '"\'`':ins=c
        elif c in '[{':d+=1
        elif c in ']}':
            d-=1
            if d==0:k+=1;break
        k+=1
    if s[k]==';':k+=1
    return i,k
def sub(name,new):
    global s
    i,k=block(name);s=s[:i]+new+s[k:]
sub('RULES',"const RULES=__D.devaluation.blocks;")
sub('PRICES',"const PRICES=__D.prices_blocks;")
sub('DIGGER_DATA',"const DIGGER_DATA=__D.parma_stock.rows;")
sub('RODPRICES',"const RODPRICES=__D.prices_rods;")
sub('ROUNDPRICES',"const ROUNDPRICES=__D.prices_rounds;")
sub('ROD_V7',"const ROD_V7=__D.devaluation.rods;")
sub('RND_V7',"const RND_V7=__D.devaluation.rounds;")
# data bootstrap at the very start of the main script
s=s.replace('<script>\n','<script nonce="{{NONCE}}">\n/* Datasets and session come from the server (Supabase) */\nconst __D=JSON.parse(document.getElementById("ei6-data").textContent), __U=JSON.parse(document.getElementById("ei6-user").textContent);\n',1)
s=s.replace('<!doctype html>','<!doctype html>',1)
# data holders before the script
s=s.replace('<div class="app">','<script type="application/json" id="ei6-data">{{DATA_JSON}}</script>\n<script type="application/json" id="ei6-user">{{USER_JSON}}</script>\n<div class="app">',1)
s=s.replace('<style>','<style nonce="{{NONCE}}">',1)
open(dst,'w',encoding='utf8').write(s)
print(len(s))
