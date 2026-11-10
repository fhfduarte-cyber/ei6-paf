/* ---- "Update data": upload of the source spreadsheets, protected by the unlock password ---- */
(function(){
  const KINDS=[
    {k:'prices',label:'Machining price list',hint:'"2026 Master Price List" sheet · prices of Blocks, Rods and Rounds',need:['prices_blocks','prices_rods','prices_rounds']},
    {k:'devaluation',label:'Depreciation workbook',hint:'PFR (blocks), PRD-N (rods) and PDF-N (rounds) sheets · depreciation model',need:['devaluation']},
    {k:'parma',label:'Parma M Aged Report',hint:'"Data" sheet · stock for the Material Digger',need:['parma_stock']}];
  const NAMES={prices_blocks:'block prices',prices_rods:'rod prices',prices_rounds:'round prices',parma_stock:'Parma stock lines'};
  const cnt=(k,d)=>k==='devaluation'?('PFR · PRD-N · PDF-N parameters (model v'+d.version+')'):k==='parma_stock'?d.rows.length+' stock lines':d.length+' '+NAMES[k];
  const size=(k,d)=>k==='devaluation'?(d.blocks.p0.length+d.rods.p0.length+d.rounds.p0.length):k==='parma_stock'?d.rows.length:d.length;
  const E=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const parsed={};            // dataset key -> data
  let ov=null;
  function close(){if(ov){ov.remove();ov=null;document.removeEventListener('keydown',onKey)}}
  function onKey(e){if(e.key==='Escape')close()}
  function refresh(){
    const has=Object.keys(parsed).length>0, pw=ov.querySelector('#upd-pw').value;
    ov.querySelector('#upd-go').disabled=!(has&&pw.length>0);
  }
  async function onFile(kind,inp){
    const row=inp.closest('.upd-row'), st=row.querySelector('.upd-st'), f=inp.files[0];
    KINDS.find(x=>x.k===kind).need.forEach(k=>delete parsed[k]);
    st.className='upd-st'; st.textContent='';
    if(!f){refresh();return}
    if(f.size>30e6){st.className='upd-st bad';st.textContent='File too large (max 30 MB).';refresh();return}
    st.textContent='Reading…';
    try{
      await rptLib();
      const wb=new ExcelJS.Workbook(); await wb.xlsx.load(await f.arrayBuffer());
      const out=Ei6Import.parse(kind,wb,f.name.slice(0,150));
      const lines=[],warn=[];
      Object.entries(out).forEach(([k,d])=>{
        parsed[k]=d; const before=size(k,__D[k]), now=size(k,d);
        lines.push(cnt(k,d)+' <span class="upd-was">(now in the tool: '+cnt(k,__D[k])+')</span>');
        if(before>=5&&now<before*0.75)warn.push('Much smaller than the current data ('+now+' vs '+before+'). Check that this is the right file.');
      });
      st.className='upd-st ok'; st.innerHTML='✓ '+lines.map(x=>x).join('<br>')+(warn.length?'<br><b class="upd-warn">'+E(warn[0])+'</b>':'');
    }catch(e){
      st.className='upd-st bad'; st.textContent='Could not read this file: '+(e&&e.message||e);
    }
    refresh();
  }
  async function send(){
    const msg=ov.querySelector('.msg'), go=ov.querySelector('#upd-go'), pw=ov.querySelector('#upd-pw');
    msg.className='msg'; msg.textContent='Updating…'; go.disabled=true;
    try{
      const r=await api('PUT','/api/datasets',{password:pw.value,sets:parsed});
      pw.value='';
      msg.className='msg ok'; msg.innerHTML='Data updated. Save your PAF if needed, then reload the page to use the new data. <button type="button" class="upd-reload">Reload now</button>';
      msg.querySelector('.upd-reload').onclick=()=>location.reload();
      ov.querySelector('.upd-files').style.opacity='.5'; ov.querySelector('#upd-go').hidden=true;
    }catch(e){
      msg.className='msg'; msg.textContent=e.message||'Update failed'; if(e.status===403)pw.select();
      refresh();
    }
  }
  function open(){
    if(ov)return;
    ov=document.createElement('div'); ov.className='rpt-ov';
    ov.innerHTML=`<div class="rpt-dlg upd-dlg" role="dialog" aria-modal="true" aria-labelledby="upd-t">
      <header><h2 id="upd-t">Update reference data</h2></header>
      <div class="bd">
        <p class="upd-h">Choose the spreadsheets that changed (one, two or all three). Nothing is saved until you type the unlock password and press Update.</p>
        <div class="upd-files">${KINDS.map(x=>`<div class="upd-row"><label>${E(x.label)}</label><input type="file" accept=".xlsx" data-k="${x.k}" aria-label="${E(x.label)}"><p class="upd-h">${E(x.hint)}</p><div class="upd-st" aria-live="polite"></div></div>`).join('')}</div>
        <label for="upd-pw">Unlock password</label>
        <input id="upd-pw" type="password" autocomplete="off" maxlength="200" spellcheck="false">
        <p class="msg" role="status"></p>
      </div>
      <footer><button type="button" id="upd-x">Close</button><button type="button" class="go" id="upd-go" disabled>Update</button></footer></div>`;
    document.body.appendChild(ov); document.addEventListener('keydown',onKey);
    ov.addEventListener('mousedown',e=>{if(e.target===ov)close()});
    ov.querySelectorAll('input[type=file]').forEach(i=>i.onchange=()=>onFile(i.dataset.k,i));
    ov.querySelector('#upd-pw').oninput=refresh; ov.querySelector('#upd-pw').onkeydown=e=>{if(e.key==='Enter'&&!ov.querySelector('#upd-go').disabled)send()};
    ov.querySelector('#upd-x').onclick=close; ov.querySelector('#upd-go').onclick=send;
    Object.keys(parsed).forEach(k=>delete parsed[k]);
    ov.querySelector('input[type=file]').focus();
  }
  $('upd-open').onclick=open;
})();
