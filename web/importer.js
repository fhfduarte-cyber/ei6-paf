/* Reads the three source workbooks and returns the JSON the tool stores in the `datasets` table.
   Works on an ExcelJS workbook (browser or node). Nothing here touches the network. */
(function (root) {
  'use strict';
  const IN = 25.4, LB2KG = 2.20462;
  const r9 = n => Math.round(n * 1e9) / 1e9;
  const norm = s => String(s == null ? '' : s).replace(/\s+/g, ' ').trim();
  function val(c) {                       // ExcelJS cell value -> plain JS (formula results, rich text)
    let v = c && c.value;
    if (v && typeof v === 'object') {
      if ('result' in v) v = v.result;
      else if (v.richText) v = v.richText.map(t => t.text).join('');
      else if (v.text != null) v = v.text;
    }
    return v === undefined ? null : v;
  }
  const num = v => (typeof v === 'number' && isFinite(v)) ? v : null;
  function sheet(wb, name) {
    const ws = wb.getWorksheet(name);
    if (!ws) throw new Error('Sheet "' + name + '" not found');
    return ws;
  }
  const cell = (ws, r, c) => val(ws.getCell(r, c));

  /* ---------- Devaluation workbook (Rules_Blocks / Rules_Rods / Rules_Rounds) ---------- */
  function odTable(ws, headerText, label) {
    let hr = 0;
    ws.eachRow((row, r) => { if (!hr && norm(val(row.getCell(1))) === headerText) hr = r; });
    if (!hr) throw new Error(label + ': header "' + headerText + '" not found');
    const out = [];
    for (let r = hr + 1; r <= ws.rowCount; r++) {
      const od = num(cell(ws, r, 1));
      if (od == null) break;
      const b = [2, 3, 4, 5, 6].map(c => num(cell(ws, r, c)));
      if (b.some(x => x == null || x < 0)) throw new Error(label + ' row ' + r + ': the 5 band values must be numbers');
      out.push([od, b.map(r9)]);
    }
    if (!out.length) throw new Error(label + ': no rows');
    return out;
  }
  function devaluation(wb, srcName) {
    const wsB = sheet(wb, 'Rules_Blocks'), blocks = [];
    for (let r = 5; r <= 40; r++) {
      const n = num(cell(wsB, r, 1)); if (n == null) break;
      const v = [2, 3, 4, 5, 6, 7].map(c => num(cell(wsB, r, c)));
      if (v.some(x => x == null)) throw new Error('Rules_Blocks row ' + r + ': values must be numbers');
      blocks.push({ n, minIn: r9(v[0]), maxIn: r9(v[1]), mid: r9(v[2] * IN), big: r9(v[3] * IN), ok: r9(v[4] * LB2KG), no: r9(v[5] * LB2KG) });
    }
    if (blocks.length < 3) throw new Error('Rules_Blocks: fewer than 3 bands found');
    return {
      source: srcName || 'Devaluation workbook', blocks,
      rods: odTable(sheet(wb, 'Rules_Rods'), 'OD (in)', 'Rules_Rods'),
      rounds: odTable(sheet(wb, 'Rules_Rounds'), 'OD (in)', 'Rules_Rounds'),
    };
  }

  /* ---------- Machining price list ("2026 Master Price List") ---------- */
  const NUM = '(\\d+(?:\\.\\d+)?)';
  function dims(name, n) {
    const sep = '\\s*(?:"|\u201d)?\\s*[xX\u00d7]\\s*';
    const m = new RegExp('^\\s*(?:[A-Za-z]{1,3}\\s+)?' + Array(n).fill(NUM).join(sep)).exec(name);
    return m ? m.slice(1).map(Number) : null;
  }
  function prices(wb) {
    const ws = sheet(wb, '2026 Master Price List');
    const secs = {}; let cur = null, cols = null;
    ws.eachRow((row, r) => {
      const a = norm(val(row.getCell(1))), b = norm(val(row.getCell(2)));
      if (b === 'Pieces Available') {
        cur = a; secs[cur] = [];
        cols = { w: 3, p: 5 };
        row.eachCell((c, i) => { const t = norm(val(c)).toLowerCase(); if (t === 'price per lb') cols.p = i; if (t === 'tl weight') cols.w = i; });
        return;
      }
      if (!cur) return;
      if (!a) { if (![2, 3, 4, 5].some(i => val(row.getCell(i)) != null)) cur = null; return; }
      if (a === 'Nominal Size') return;
      secs[cur].push({ name: a, w: num(val(row.getCell(cols.w))), lb: num(val(row.getCell(cols.p))) });
    });
    const pick = (sec, n, grp) => {
      if (!secs[sec]) throw new Error('Section "' + sec + '" not found in the price list');
      const out = [];
      secs[sec].forEach(x => {
        if (!(x.lb > 0) || !(x.w > 0)) return;
        const d = dims(x.name, n); if (!d) return;
        const o = { name: x.name };
        if (grp) o.grp = grp;
        const lb6 = Math.round(x.lb * 1e6) / 1e6; o.lb = lb6;
        if (n === 2) o.L = d[1];
        o.kg = r9(lb6 / 0.45359237); o.w = x.w; o.d = d;
        out.push(o);
      });
      if (!out.length) throw new Error('Section "' + sec + '": no priced items');
      return out;
    };
    const blocks = [];
    [['Blocks', 'PFR blocks'], ['PF Blocks', 'PF Blocks'], ['SEC', 'SEC'], ['PFR-N Blocks', 'PFR-N Blocks'], ['ISO Blocks', 'ISO Blocks']]
      .forEach(([s, g]) => { if (secs[s]) blocks.push(...pick(s, 3, g)); });
    if (!blocks.length) throw new Error('No block prices found');
    return { prices_blocks: blocks, prices_rods: pick('Extruded rods', 2), prices_rounds: pick('PDF-N Molded Rounds', 2) };
  }

  /* ---------- Parma M Aged Report (sheet "Data") ---------- */
  function frac(s) { const p = s.trim().split(/\s+/); if (p.length === 2) { const [a, b] = p[1].split('/'); return Number(p[0]) + Number(a) / Number(b); } return Number(s); }
  function parma(wb, srcName) {
    const ws = sheet(wb, 'Data');
    const head = {}; ws.getRow(1).eachCell((c, i) => { head[norm(val(c)).toLowerCase()] = i; });
    const need = ['description', 'lot #', 'location', 'qty on hand', 'unit cost'];
    need.forEach(k => { if (!head[k]) throw new Error('Parma "Data" sheet: column "' + k + '" not found'); });
    const N = '(\\d+(?:\\.\\d+)?(?:\\s+\\d+/\\d+)?)', X = '\\s*x\\s*';
    const reBL = new RegExp('^' + N + X + N + X + N, 'i'), re2 = new RegExp('^' + N + X + N, 'i'), reCL = new RegExp('^' + N + X + N + '\\s*,\\s*' + N + X + N, 'i');
    const rows = [];
    for (let r = 2; r <= ws.rowCount; r++) {
      const desc = norm(val(ws.getCell(r, head['description'])));
      if (!/nominal|offall/i.test(desc)) continue;
      const qty = num(val(ws.getCell(r, head['qty on hand'])));
      if (!(qty > 0)) continue;
      const lot = norm(val(ws.getCell(r, head['lot #'])));
      const m = /(?:^|[\s-])(BL|RD|RN|CL)\s*-?\s*(?=\d)(.*)$/.exec(lot.replace(/[\u2013\u2014]/g, '-'));
      if (!m) continue;
      const cat = m[1], rest = m[2]; let d = null, mm;
      if (cat === 'BL') { mm = reBL.exec(rest); d = mm && mm.slice(1).map(frac); }
      else if (cat === 'CL') { mm = reCL.exec(rest); d = mm && mm.slice(1).map(frac); if (d && d[0] > d[2]) d = [d[2], d[3], d[0], d[1]]; }
      else { mm = re2.exec(rest); if (mm && /^\s*x\s*\d/i.test(rest.slice(mm[0].length))) mm = null; d = mm && mm.slice(1).map(frac); }
      if (!d || d.some(x => !isFinite(x))) continue;
      const cost = num(val(ws.getCell(r, head['unit cost']))); if (cost == null) continue;
      rows.push([/offall/i.test(desc) ? 1 : 0, cat, d, lot, desc.replace(/\s*(NOMINAL STOCK|OFFALL)\s*$/i, '').trim(), qty, norm(val(ws.getCell(r, head['location']))), cost]);
    }
    if (!rows.length) throw new Error('No usable stock rows found in the Parma "Data" sheet');
    return { source: srcName || 'Parma M Aged Report', rows };
  }

  root.Ei6Import = {
    /* kind: 'devaluation' | 'prices' | 'parma'  ->  { dataset_key: data, ... } */
    parse(kind, wb, srcName) {
      if (kind === 'devaluation') return { devaluation: devaluation(wb, srcName) };
      if (kind === 'prices') return prices(wb);
      if (kind === 'parma') return { parma_stock: parma(wb, srcName) };
      throw new Error('Unknown file kind');
    },
  };
  if (typeof module !== 'undefined') module.exports = root.Ei6Import;
})(typeof window !== 'undefined' ? window : globalThis);
