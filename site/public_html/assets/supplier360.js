// KSDD CRM 360 — Supplier Bill + Stock IN (staff) and Supplier Dues / Master / Review (owner). Loaded after crm360.js.
(function(){
'use strict';
const C=window.CRM; if(!C) return;
const rs=p=>fmt(p), L=C.L, opt=C.opt;
const G={sup:null,sups:[],detail:null,files:[],units:[]};
/* exact client-side preview maths (server re-checks everything) */
const paise=v=>{ v=String(v??'').trim(); if(!/^\d{1,10}(\.\d{0,2})?$/.test(v)) return null; const [w,f='']=v.split('.'); return Number(w)*100+Number((f+'00').slice(0,2)); };
const spaise=v=>{ v=String(v??'').trim(); if(v==='')return 0; const n=v.startsWith('-'); const p=paise(v.replace(/^[-+]/,'')); return p===null?null:(n?-p:p); };
const milli=v=>{ v=String(v??'').trim(); if(!/^\d{1,8}(\.\d{0,3})?$/.test(v)) return null; const [w,f='']=v.split('.'); return Number(w)*1000+Number((f+'000').slice(0,3)); };
const gross=(qm,r)=>Math.floor((qm*r+500)/1000);
const supLabel=s=>`${s.name} · ${s.balance>0?'Due '+rs(s.balance):s.balance<0?'Credit '+rs(-s.balance):'₹0 — No dues'}${s.overdue>0?' · Late '+rs(s.overdue):''}`;

/* ---------------- Staff: Supplier Bill + Stock IN ---------------- */
async function openGoodsIn(prefill){
  const r=await api('/api/crm-suppliers'); G.sups=r.suppliers.filter(s=>s.active); G.units=r.units; G.sup=null; G.files=[]; G.detail=null;
  if(!G.sups.length) return toast('No active suppliers. Ask the owner to add the supplier first.',true);
  const recent=G.sups.filter(s=>s.recent), rest=G.sups.filter(s=>!s.recent);
  const H=C.S.home;
  C.sheet('Supplier Bill + Stock IN','बिल + माल आया',`
   <label class="crm-field">${L('Select Supplier','सप्लायर चुनें')} *
     <input type="search" id="sup-filter" placeholder="Type to filter / खोजें" autocomplete="off">
     <select name="supplier_id" id="sup-select" required size="1"><option value="">— Select / चुनें —</option>${recent.length?`<optgroup label="Recent">${recent.map(s=>`<option value="${s.id}">${esc(supLabel(s))}</option>`).join('')}</optgroup>`:''}<optgroup label="All suppliers">${rest.map(s=>`<option value="${s.id}">${esc(supLabel(s))}</option>`).join('')}</optgroup></select></label>
   <div id="sup-summary" class="sup-summary" hidden></div>
   <div class="sup-photos"><label class="crm-field crm-photo">📷 ${L('Take Bill Photo','बिल की फोटो')} * <small>1–5 photos + 1 PDF</small><input type="file" accept="image/*,application/pdf" capture="environment" id="sup-photo" multiple></label><div id="sup-thumbs" class="sup-thumbs"></div></div>
   <div class="sup-two">${C.fld('Bill number / बिल नंबर *','invoice_ref','text','autocomplete="off"')}${C.fld('Bill date','invoice_date','date',`value="${H.businessDate}" max="${H.businessDate}"`)}</div>
   <div class="sup-two">${C.sel('Store location','location_id',(H.locations||[]).filter(l=>l.kind==='store').map(l=>`<option value="${l.id}">${esc(l.name)}</option>`).join(''))}<label class="crm-field">Against order (optional)<select name="po_id" id="sup-po"><option value="">—</option></select></label></div>
   <div class="sup-lines-head"><b>${L('Items','सामान')}</b><small>Qty × Rate = Amount</small></div>
   <div id="sup-lines"></div><button type="button" class="crm-add-line" data-sup="add">+ ${L('Add another item','और सामान')}</button>
   <details class="sup-extra"><summary>Bill discount / tax / transport / rounding (optional)</summary><div class="sup-two">${C.fld('Bill discount ₹','bill_discount','number','step="0.01" min="0" inputmode="decimal"')}${C.fld('Bill tax ₹ (if not in rates)','bill_tax','number','step="0.01" min="0" inputmode="decimal"')}${C.fld('Transport / other ₹','other_charges','number','step="0.01" min="0" inputmode="decimal"')}${C.fld('Rounding ± ₹ (max ₹1)','rounding','text','inputmode="decimal" placeholder="e.g. -0.40"')}</div></details>
   <label class="crm-field">${L('Bill total on paper ₹','बिल पर कुल रकम')} *<input name="invoice_total" type="number" step="0.01" min="0" inputmode="decimal" class="crm-big" id="sup-invoice"></label>
   <div class="sup-totals" id="sup-totals"></div>
   ${C.fld('Note (for review)','note')}
   <div id="sup-hold" hidden><p class="crm-hint">This cannot be saved as final. Save it for the manager to review — stock and supplier balance will NOT change until approved.</p>${C.sel('Reason','hold_reason',opt([['mismatch','Bill total does not match'],['no-photo','No bill photo'],['invoice-pending','Goods received, invoice pending'],['damaged','Damaged / wrong goods'],['duplicate-suspect','Possible duplicate bill'],['other','Other']]))}<button type="button" class="crm-submit sup-hold-btn" data-sup="hold">Save as Hold for Review / जाँच के लिए रखें</button></div>`,'sup-goods-in','Verify & Save Goods IN / सामान दर्ज करें');
  const f=modal.querySelector('form'); f.querySelector('.crm-submit:not(.sup-hold-btn)').classList.add('sup-primary');
  addLine(); addLine(); recalc();
  if(prefill?.supplier_id){ f.supplier_id.value=prefill.supplier_id; await pickSupplier(prefill.supplier_id); }
}
function itemOptions(sel){ const items=C.S.home.items||[], fav=new Set((G.detail?.lastPrices||[]).map(x=>x.item_id));
  const o=i=>`<option value="${i.id}" ${i.id==sel?'selected':''}>${esc(i.name)}${i.name_hi?' · '+esc(i.name_hi):''} (${esc(i.unit)})</option>`;
  const f=items.filter(i=>fav.has(i.id)); return `<option value="">— Item / सामान —</option>${f.length?`<optgroup label="This supplier's items">${f.map(o).join('')}</optgroup>`:''}<optgroup label="All items">${items.filter(i=>!fav.has(i.id)).map(o).join('')}</optgroup>`; }
function addLine(v={}){ const box=$('#sup-lines'); if(box.children.length>=100) return toast('Maximum 100 items per bill.',true);
  box.insertAdjacentHTML('beforeend',`<div class="sup-line"><div class="sup-line-top"><select data-l="item_id" aria-label="Item">${itemOptions(v.item_id)}</select><button type="button" data-sup="dup" title="Duplicate row" aria-label="Duplicate row">⧉</button><button type="button" data-sup="del" aria-label="Remove row">×</button></div>
   <div class="sup-line-nums"><input data-l="qty" type="number" step="0.001" min="0" inputmode="decimal" placeholder="Qty" value="${esc(v.qty??'')}" aria-label="Quantity"><select data-l="unit" aria-label="Unit"></select><input data-l="rate" type="number" step="0.01" min="0" inputmode="decimal" placeholder="Rate ₹" value="${esc(v.rate??'')}" aria-label="Rate"><output data-l="amount">₹0</output></div>
   <small class="sup-hint" data-l="hint"></small></div>`);
  const row=box.lastElementChild; syncUnit(row,v.unit); }
function syncUnit(row,pick){ const it=(C.S.home.items||[]).find(i=>i.id==row.querySelector('[data-l=item_id]').value); const u=row.querySelector('[data-l=unit]');
  const base=it?it.unit:'kg', alt={kg:['g'],litre:['ml']}[base]||[]; u.innerHTML=[base,...alt].map(x=>`<option ${x===(pick||base)?'selected':''}>${esc(x)}</option>`).join('');
  const hint=row.querySelector('[data-l=hint]'), r=row.querySelector('[data-l=rate]');
  if(!it){ hint.textContent=''; return; } const last=(G.detail?.lastPrices||[]).find(x=>x.item_id==it.id&&x.purchase_unit===u.value);
  hint.dataset.last=last?last.rate:''; if(last&&!r.value) r.value=(last.rate/100).toFixed(2);
  hint.textContent=`Stock ${Number(it.quantity).toLocaleString('en-IN')} ${it.unit}${last?` · last rate ${rs(last.rate)}/${last.purchase_unit}`:''}`; }
function recalc(){ const f=modal.querySelector('form[data-crm-form="sup-goods-in"]'); if(!f) return; let sub=0,bad=false; const pct=10;
  f.querySelectorAll('.sup-line').forEach(row=>{ const q=milli(row.querySelector('[data-l=qty]').value), r=paise(row.querySelector('[data-l=rate]').value), out=row.querySelector('[data-l=amount]'), h=row.querySelector('[data-l=hint]');
    if(q===null||r===null||!row.querySelector('[data-l=item_id]').value){ out.textContent='₹0'; if(row.querySelector('[data-l=qty]').value||row.querySelector('[data-l=rate]').value) bad=true; return; }
    const g=gross(q,r); sub+=g; out.textContent=rs(g); const last=Number(h.dataset.last||0); h.classList.toggle('warn',!!last&&Math.abs(r-last)*100>last*pct);
    if(last&&Math.abs(r-last)*100>last*pct&&!h.textContent.includes('now')) h.textContent+=` · ⚠ was ${rs(last)}, now ${rs(r)}`; });
  const bd=paise(f.bill_discount.value||'0')??0, bt=paise(f.bill_tax.value||'0')??0, oc=paise(f.other_charges.value||'0')??0, rd=spaise(f.rounding.value)??0;
  const calc=sub-bd+bt+oc+rd, inv=paise(f.invoice_total.value), diff=inv===null?null:inv-calc;
  $('#sup-totals').innerHTML=`<div><span>Calculated / हिसाब</span><b>${rs(calc)}</b></div><div><span>Invoice / बिल</span><b>${inv===null?'—':rs(inv)}</b></div><div class="${diff?'bad':diff===0?'good':''}"><span>Difference / फर्क</span><b>${diff===null?'—':(diff===0?'✓ ₹0':rs(diff))}</b></div>${G.sup?`<div><span>Supplier due after</span><b>${rs(G.sup.balance+(inv||0))}</b></div>`:''}`;
  f.querySelector('.sup-primary').disabled=bad; }
async function pickSupplier(id){ G.detail=null; const box=$('#sup-summary'); if(!id){ box.hidden=true; return; }
  box.hidden=false; box.innerHTML='Loading…'; const d=await api('/api/crm-supplier?id='+encodeURIComponent(id)); G.detail=d; const s=d.supplier; G.sup=s;
  box.innerHTML=`<div class="sup-sum-grid"><div><small>${L('Previous outstanding','पुराना बकाया')}</small><b>${s.balance?rs(s.balance):'₹0 — No dues'}</b></div><div><small>${L('Overdue','लेट पेमेंट')}</small><b class="${s.overdue?'neg':''}">${rs(s.overdue)}</b></div><div><small>${L('Next payment','अगला भुगतान')}</small><b>${s.next_due?esc(s.next_due)+' · '+rs(s.next_due_amount):'—'}</b></div><div><small>Unpaid bills</small><b>${s.unpaid_count}</b></div></div>
   <small>${esc(d.termsLabel)}${s.last_purchase?` · last bill ${esc(s.last_invoice||'')} on ${esc(s.last_purchase)}`:''}${s.holds?` · ${s.holds} on hold`:''}</small> <button type="button" class="crm-link" data-sup="bills">View supplier bills</button>`;
  $('#sup-po').innerHTML='<option value="">—</option>'+d.openPos.map(p=>`<option value="${p.id}">${esc(p.po_no)} · ${esc(p.status)}</option>`).join('');
  document.querySelectorAll('.sup-line').forEach(r=>{ const v=r.querySelector('[data-l=item_id]').value; r.querySelector('[data-l=item_id]').innerHTML=itemOptions(v); syncUnit(r); }); recalc(); }
async function addPhotos(input){ const st=$('#sup-thumbs');
  for(const f of [...input.files]){ if(G.files.length>=6){ toast('Maximum 5 photos + 1 PDF.',true); break; }
    const tmp=document.createElement('div'); tmp.className='sup-thumb'; tmp.textContent='Uploading…'; st.append(tmp);
    try{ let blob=f; if(f.type.startsWith('image/')){ const img=await createImageBitmap(f); const sc=Math.min(1,1600/Math.max(img.width,img.height)); const c=document.createElement('canvas'); c.width=Math.round(img.width*sc); c.height=Math.round(img.height*sc); c.getContext('2d').drawImage(img,0,0,c.width,c.height); blob=await new Promise(r=>c.toBlob(r,'image/jpeg',0.75)); }
      const data=await new Promise(r=>{const fr=new FileReader();fr.onload=()=>r(String(fr.result).split(',')[1]);fr.readAsDataURL(blob);});
      const r=await api('/api/crm-upload',{name:f.name,data}); G.files.push(r.file_id); const url=f.type.startsWith('image/')?URL.createObjectURL(blob):'';
      tmp.innerHTML=`${url?`<img src="${url}" alt="Bill photo">`:'📄 PDF'}<button type="button" data-sup="rmphoto" data-id="${r.file_id}" aria-label="Remove">×</button>`; tmp.dataset.id=r.file_id;
      if(url) tmp.querySelector('img').onclick=()=>window.open(url,'_blank');
    }catch(e){ tmp.remove(); toast(e.message,true); } }
  input.value=''; }
function collect(f){ const lines=[...f.querySelectorAll('.sup-line')].map((r,i)=>({item_id:r.querySelector('[data-l=item_id]').value,qty:r.querySelector('[data-l=qty]').value,unit:r.querySelector('[data-l=unit]').value,rate:r.querySelector('[data-l=rate]').value})).filter(l=>l.item_id||l.qty||l.rate);
  const b=Object.fromEntries(new FormData(f)); return {...b,lines,file_ids:G.files}; }
async function submitGoods(f,action){ const body=collect(f); body.action=action; const err=f.querySelector('#crm-error'); err.textContent='';
  try{ let r; try{ r=await api('/api/crm-goods-receipt',body); }catch(e){ if(/same date and amount/.test(e.message)&&confirm(e.message+'\n\nOK = yes, different bill')) r=await api('/api/crm-goods-receipt',{...body,confirm_duplicate:1}); else throw e; }
    modal.close(); showResult(r); }
  catch(e){ err.textContent=e.message; if(/Hold for Review|hold/i.test(e.message)) { $('#sup-hold').hidden=false; $('#sup-hold').scrollIntoView({behavior:'smooth'}); } } }
function showResult(r){ const ok=r.status==='posted';
  C.sheet(ok?'Goods IN saved':'Saved for review',ok?'सामान दर्ज हुआ':'जाँच के लिए भेजा',`<div class="sup-result ${ok?'good':'hold'}"><b>${ok?'✓ Stock added · Bill recorded':'⏸ On hold — stock and balance NOT changed'}</b><p>Receipt <b>${esc(r.receipt_no)}</b>${ok?` · Bill ${rs(r.bill_amount)} · ${r.lines} item(s)`:''}</p>${ok?`<p>Supplier balance now <b>${rs(r.supplier_balance)}</b>${r.due_date?` · this bill due <b>${esc(r.due_date)}</b>`:''}</p>`:`<p>${esc(r.message||'')}</p>`}${(r.warnings||[]).map(w=>`<p class="neg">⚠ ${esc(w.item)} rate changed ${rs(w.last)} → ${rs(w.now)}</p>`).join('')}<p>Nothing was paid. Payment is made later by accounts.</p></div>`,'sup-done','Done / ठीक है');
  C.loadHome().then(()=>C.staffShell()); }
async function myReceipts(){ const r=await api('/api/crm-receipts'); app.querySelector('.crm-main').innerHTML=`<button class="crm-link" data-crm="home">← Home</button>`+C.listCard(L('My receipts','मेरी रसीदें'),r.receipts.map(x=>`<div class="crm-row"><span><b>${esc(x.supplier)}</b> · ${esc(x.invoice_ref||'no bill no.')}<small>${esc(x.receipt_no)} · ${esc(x.invoice_date||'')}${x.review_note?' · '+esc(x.review_note):''}</small></span><span>${rs(x.invoice_total||x.calculated_total)} ${badge(x.status,x.status==='posted'?'blue':x.status==='hold'?'warn':'')}</span></div>`),'No receipts yet.'); }

C.SHEETS['goods-in']=()=>openGoodsIn(); C.SHEETS['add-bill']=()=>openGoodsIn();
C.staffActions={...(C.staffActions||{}),'my-receipts':myReceipts};
C.submitters['sup-goods-in']=f=>submitGoods(f,'post'); C.submitters['sup-done']=()=>{ modal.close(); };
document.addEventListener('click',async ev=>{ const t=ev.target.closest('[data-sup]'); if(!t) return; ev.preventDefault(); const a=t.dataset.sup;
  try{ if(a==='add') { addLine(); recalc(); }
   if(a==='del'){ t.closest('.sup-line').remove(); if(!$('#sup-lines').children.length) addLine(); recalc(); }
   if(a==='dup'){ const r=t.closest('.sup-line'); addLine({item_id:'',qty:r.querySelector('[data-l=qty]').value,rate:r.querySelector('[data-l=rate]').value}); recalc(); }
   if(a==='rmphoto'){ G.files=G.files.filter(x=>x!=t.dataset.id); t.closest('.sup-thumb').remove(); }
   if(a==='hold') await submitGoods(t.closest('form'),'hold');
   if(a==='bills'){ const d=G.detail; alert(d.bills.length?d.bills.map(b=>`${b.date}  ${b.reference}  ${(b.amount/100).toFixed(2)}  due ${b.due_date}  ${b.status}`).join('\n'):'No bills yet.'); }
  }catch(e){ toast(e.message,true); } });
document.addEventListener('change',ev=>{ const t=ev.target;
  if(t.id==='sup-select') return pickSupplier(t.value).catch(e=>toast(e.message,true));
  if(t.id==='sup-photo') return addPhotos(t);
  if(t.matches('.sup-line [data-l=item_id],.sup-line [data-l=unit]')){ const row=t.closest('.sup-line'); if(t.dataset.l==='item_id') syncUnit(row); else { row.querySelector('[data-l=rate]').value=''; syncUnit(row,t.value); } recalc(); } });
document.addEventListener('input',ev=>{ const t=ev.target;
  if(t.id==='sup-filter'){ const q=t.value.toLowerCase(), s=$('#sup-select'); [...s.querySelectorAll('option')].forEach(o=>{ if(o.value) o.hidden=!!q&&!o.textContent.toLowerCase().includes(q); }); const m=[...s.options].filter(o=>o.value&&!o.hidden); if(m.length===1){ s.value=m[0].value; pickSupplier(m[0].value); } return; }
  if(t.closest('form[data-crm-form="sup-goods-in"]')) recalc(); });

/* ---------------- Owner / Accounts: Supplier Dues, Master, Review ---------------- */
function duesCard(a){ const d=a.supplierDues; if(!d) return ''; const c=d.cards, al=d.alerts;
  const y=a.yesterday?.items?.length?`<div class="sup-yesterday"><b>Yesterday (${esc(a.yesterday.date)}) — still open</b>${a.yesterday.items.map(i=>`<div>• ${esc(i.text)}</div>`).join('')}</div>`:'';
  const chip=(t,v,cls='')=>v?`<span class="badge ${cls}">${t}: ${typeof v==='number'&&v>100?rs(v):v}</span>`:'';
  return card('Supplier Dues','Overdue first. Tap a supplier for bills, photos, payments and terms.',`${y}
   <div class="crm-stats sup-cards"><div class="crm-stat featured"><small>Total outstanding</small><b>${rs(c.outstanding)}</b></div><div class="crm-stat ${c.overdue?'warn':''}"><small>Overdue</small><b>${rs(c.overdue)}</b></div><div class="crm-stat"><small>Due next 7 days</small><b>${rs(c.next7)}</b></div><div class="crm-stat ${c.holds?'warn':''}"><small>Bills awaiting review</small><b>${c.holds}</b></div><div class="crm-stat"><small>Paid this month</small><b>${rs(c.paidMonth)}</b></div><div class="crm-stat"><small>Due rest of month</small><b>${rs(c.monthForecast)}</b></div></div>
   <div class="sup-chips">${chip('Due today',al.dueToday,'red')}${chip('Tomorrow',al.tomorrow,'warn')}${chip('Next 3 days',al.next3)}${chip('Overdue',al.overdue,'red')}${al.unconfigured?`<span class="badge warn">Schedule not set: ${al.unconfigured}</span>`:''}${al.mismatch?`<span class="badge warn">Bills on hold: ${al.mismatch}</span>`:''}${al.supplierCredit?`<span class="badge">Supplier credit: ${al.supplierCredit}</span>`:''}</div>
   <input type="search" class="sup-search" id="sup-dues-q" placeholder="Search supplier">
   ${table(['Supplier','Outstanding','Overdue','Next due','Last purchase',''],d.suppliers.map(s=>`<tr data-supname="${esc(s.name.toLowerCase())}"><td><b>${esc(s.name)}</b><small> ${s.terms_mode?'':'· schedule not set'}${s.holds?' · '+s.holds+' on hold':''}</small></td><td>${s.balance<0?'<span class="pos">Credit '+rs(-s.balance)+'</span>':rs(s.balance)}</td><td class="${s.overdue?'neg':''}">${rs(s.overdue)}</td><td>${s.next_due?esc(s.next_due)+'<small> '+rs(s.next_due_amount)+'</small>':'—'}</td><td>${esc(s.last_purchase||'—')}</td><td>${btn('View / Pay','crm-sup-view','',`data-id="${s.id}"`)}</td></tr>`),'No supplier dues')}
   <h4>Next 30 days</h4>${table(['Due date','Supplier','Bills','Amount'],d.calendar.map(x=>`<tr class="${x.due_date<d.today?'sup-late':''}"><td>${esc(x.due_date)}</td><td>${esc(x.supplier)}</td><td>${x.bills}</td><td>${rs(Number(x.amount))}</td></tr>`),'Nothing due')}
   <div class="crm-actions-row">${me.user.role==='owner'?btn('+ New supplier','crm-sup-edit'):''}${btn('Purchase report','crm-sup-report')}</div>`); }
async function viewSupplier(id){ const d=await api('/api/crm-supplier?id='+id), s=d.supplier, owner=me.user.role==='owner';
  const bills=d.bills.map(b=>`<tr><td>${esc(b.date)}</td><td><b>${esc(b.reference)}</b>${b.kind!=='invoice'?' <small>('+esc(b.kind)+')</small>':''}${b.legacy?' <small>Legacy bill — no item lines</small>':''}${(b.lines||[]).length?`<details><summary>${b.lines.length} items</summary>${b.lines.map(l=>`<div><small>${esc(l.item_name)} ${Number(l.purchase_qty)} ${esc(l.purchase_unit)} × ${rs(l.rate)} = ${rs(l.total)}</small></div>`).join('')}</details>`:''}${(b.photos||[]).map(p=>` <a href="api.php?route=file&id=${p}" target="_blank" rel="noopener">📷</a>`).join('')}</td><td>${rs(b.amount)}</td><td>${rs(b.paid+b.credited)}</td><td><b>${rs(b.outstanding)}</b></td><td>${esc(b.due_date)}${b.original_due_date&&b.original_due_date!==b.due_date?'<small> (was '+esc(b.original_due_date)+')</small>':''}</td><td>${badge(b.status)} ${owner&&b.status!=='paid'?btn('Reschedule','crm-sup-resched','',`data-id="${b.id}" data-due="${b.due_date}"`):''}</td></tr>`);
  const open=d.bills.filter(b=>b.outstanding>0);
  showModal(esc(s.name),`Outstanding ${rs(s.balance)} · overdue ${rs(s.overdue)} · ${esc(d.termsLabel)}`,
   `<div class="crm-actions-row">${btn('Request payment','crm-sup-pay','primary',`data-id="${s.id}"`)}${btn('Credit / return','crm-sup-credit','',`data-id="${s.id}"`)}${owner?btn('Payment schedule','crm-sup-terms','',`data-id="${s.id}"`)+btn('Edit supplier','crm-sup-edit','',`data-id="${s.id}"`):''}<a href="api.php?route=crm-supplier-statement&id=${s.id}&from=2000-01-01&format=csv"><button type="button">Statement CSV</button></a><a href="api.php?route=crm-supplier-statement&id=${s.id}&from=2000-01-01&format=pdf"><button type="button">Statement PDF</button></a></div>
    ${table(['Date','Bill','Amount','Paid/credited','Outstanding','Due','Status'],bills,'No bills')}
    ${d.credits.length?'<h4>Credits & advances</h4>'+table(['Type','Amount','Applied to bill','Reason'],d.credits.map(c=>`<tr><td>${esc(c.kind)}</td><td>${rs(c.amount)}</td><td>${c.bill_id?('#'+c.bill_id):(open.length?btn('Apply to oldest bill','crm-sup-apply','',`data-id="${c.id}" data-bill="${open[0].id}"`):'unallocated')}</td><td>${esc(c.reason)}</td></tr>`)):''}
    ${d.lastPrices.length?'<h4>Last prices</h4>'+table(['Item','Unit','Rate'],d.lastPrices.map(l=>`<tr><td>${esc(l.item_name)}</td><td>${esc(l.purchase_unit)}</td><td>${rs(l.rate)}</td></tr>`)):''}`,'x','Close');
  G.view=d; const f=modal.querySelector('form'); f.removeAttribute('data-form'); f.dataset.crmForm='sup-close'; }
function fix(kind){ const f=modal.querySelector('form'); f.removeAttribute('data-form'); f.dataset.crmForm=kind; }
const A=C.adminActions;
A['crm-sup-view']=t=>viewSupplier(t.dataset.id);
A['crm-sup-pay']=t=>{ const d=G.view, open=d.bills.filter(b=>b.outstanding>0);
  showModal('Request supplier payment',esc(d.supplier.name)+' · approval → cashier pays → bills updated',`<input type="hidden" name="supplier_id" value="${d.supplier.id}"><input type="hidden" name="client_key" value="${C.key()}">${open.map(b=>`<label class="field">${esc(b.reference)} · due ${esc(b.due_date)} · open ${rs(b.outstanding)}<input name="a_${b.id}" type="number" step="0.01" min="0" max="${b.outstanding/100}" placeholder="0.00"></label>`).join('')||'<p>No open bills.</p>'}${(me.perms||[]).some(p=>p==='*'||p==='vendor')?`<label class="field">Or advance to supplier (no bill) ₹<input name="advance" type="number" step="0.01" min="0"></label>`:''}${field('Note','note')}`,'x','Create request'); fix('sup-pay'); };
C.adminSubmit['sup-pay']=async(f,b)=>{ const allocations=Object.entries(b).filter(([k,v])=>k.startsWith('a_')&&Number(v)>0).map(([k,v])=>({bill_id:k.slice(2),amount:v}));
  let body; if(Number(b.advance)>0){ if(allocations.length) throw Error('Choose either bills or an advance, not both.'); body={kind:'supplier',supplier_id:b.supplier_id,amount:b.advance,is_advance:1,note:b.note,client_key:b.client_key}; }
  else { const tot=allocations.reduce((s,a)=>s+(paise(a.amount)||0),0); if(!tot) throw Error('Enter an amount for at least one bill.'); body={kind:'supplier',supplier_id:b.supplier_id,amount:(tot/100).toFixed(2),allocations,note:b.note,client_key:b.client_key}; }
  const r=await api('/api/crm-voucher',body); modal.close(); toast('Payment request '+r.voucher_no+' sent for approval'); await refresh(); };
A['crm-sup-credit']=t=>{ const d=G.view, open=d.bills.filter(b=>b.outstanding>0); showModal('Credit note / return / debit',esc(d.supplier.name),`<input type="hidden" name="supplier_id" value="${d.supplier.id}"><input type="hidden" name="client_key" value="${C.key()}"><div class="form-grid">${select('Type','kind',opt([['credit-note','Credit note (reduces due)'],['return','Goods returned (reduces due)'],['debit','Debit / extra charge (adds due)']]))}${select('Against bill','bill_id','<option value="">— none (unallocated) —</option>'+opt(open.map(b=>[b.id,b.reference+' · open '+fmt(b.outstanding)])))}${field('Amount ₹','amount','number','','required step="0.01" min="0.01"')}${field('Supplier note no.','reference')}</div>${field('Reason','reason','text','','required')}<p class="crm-hint">Stock for returned goods: record it separately as Wastage → Return to supplier.</p>`,'x','Save'); fix('sup-credit'); };
C.adminSubmit['sup-credit']=async(f,b)=>{ await api('/api/crm-supplier-credit',b); modal.close(); toast('Saved'); await refresh(); };
A['crm-sup-apply']=async(t,done)=>{ await api('/api/crm-supplier-apply-credit',{credit_id:t.dataset.id,bill_id:t.dataset.bill}); modal.close(); done('Credit applied'); };
A['crm-sup-resched']=t=>{ showModal('Reschedule payment','Original invoice date and due date stay in history.',`<input type="hidden" name="bill_id" value="${t.dataset.id}">${field('New due date','due_date','date',t.dataset.due,'required')}${field('Reason','reason','text','','required')}`,'x','Save'); fix('sup-resched'); };
C.adminSubmit['sup-resched']=async(f,b)=>{ await api('/api/crm-bill-reschedule',b); modal.close(); toast('Due date changed'); await refresh(); };
A['crm-sup-terms']=t=>{ const d=G.view, tm=d.terms||{}, sg=d.suggestedCycle;
  showModal('Payment schedule',esc(d.supplier.name)+(sg?` · suggested: every ${sg} days`:''),`<input type="hidden" name="supplier_id" value="${d.supplier.id}"><div class="form-grid">${select('Type','mode',opt([['cycle','Cycle settlement (every N days from a start date)'],['invoice_days','Each invoice + N days'],['weekday','Every week on a day'],['monthday','Every month on a date'],['none','Not set']],tm.mode||(sg?'cycle':'invoice_days')))}${field('Cycle days (7 / 10 / 15)','cycle_days','number',tm.cycle_days||sg||'','min="1" max="90"')}${field('Cycle start (anchor) date','anchor_date','date',tm.anchor_date||selectedDate)}${field('Invoice + days','credit_days','number',tm.credit_days??'','min="0" max="180"')}${select('Weekday','weekday',opt([['','—'],...['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'].map((w,i)=>[i,w])],tm.weekday??''))}${field('Day of month','day_of_month','number',tm.day_of_month||'','min="1" max="31"')}${field('Remind days before','reminder_days','number',tm.reminder_days??1,'min="0" max="15"')}</div><label class="crm-check"><input type="checkbox" name="apply_to_open" value="1"> Recalculate due dates of current unpaid bills</label><p class="crm-hint">Cycle: bills dated before each settlement date are paid on that date. Invoice + N: every bill has its own due date.</p>`,'x','Save schedule'); fix('sup-terms'); };
C.adminSubmit['sup-terms']=async(f,b)=>{ await api('/api/crm-supplier-terms',b); modal.close(); toast('Schedule saved'); await refresh(); };
A['crm-sup-edit']=async t=>{ const s=t.dataset.id?(await api('/api/crm-supplier?id='+t.dataset.id)).supplier:{};
  showModal(s.id?'Edit supplier':'New supplier','Owner/Admin only. Staff choose suppliers from the dropdown.',`<input type="hidden" name="id" value="${s.id||''}"><div class="form-grid">${field('Supplier name','name','text',s.name||'','required')}${field('Phone','phone','tel',s.phone||'')}${field('Category (milk, paneer, gas…)','category','text',s.category||'')}${field('GSTIN (optional)','gstin','text','')}${s.id?select('Active','active',opt([['1','Yes'],['','No — stop new bills']],s.active?'1':'')):field('Opening balance owed ₹ (cutover)','opening_payable','number','','step="0.01" min="0"')}</div>`,'x','Save'); fix('sup-edit'); };
C.adminSubmit['sup-edit']=async(f,b)=>{ try{ await api('/api/crm-supplier-save',b); }catch(e){ if(/already exists/.test(e.message)&&me.user.role==='owner'&&confirm(e.message+'\n\nCreate anyway?')) await api('/api/crm-supplier-save',{...b,confirm_duplicate:1}); else throw e; } modal.close(); toast('Supplier saved'); await refresh(); };
A['crm-sup-report']=async()=>{ const r=await api('/api/crm-purchase-report'); showModal('Purchases '+esc(r.from)+' → '+esc(r.to),'From posted bill lines',`<h4>By item</h4>${table(['Item','Qty','Amount','Rate min–max'],r.byItem.map(x=>`<tr><td>${esc(x.item_name)}</td><td>${Number(x.qty)} ${esc(x.stock_unit)}</td><td>${rs(Number(x.amount))}</td><td>${rs(Number(x.min_rate))}–${rs(Number(x.max_rate))}</td></tr>`))}<h4>By supplier</h4>${table(['Supplier','Bills','Amount'],r.bySupplier.map(x=>`<tr><td>${esc(x.name)}</td><td>${x.bills}</td><td>${rs(Number(x.amount))}</td></tr>`))}<h4>Waiting for review</h4>${table(['Receipt','Supplier','Bill','Calculated','Invoice','Reason'],r.pendingReview.map(x=>`<tr><td>${esc(x.receipt_no)}</td><td>${esc(x.supplier)}</td><td>${esc(x.invoice_ref||'')}</td><td>${rs(x.calculated_total)}</td><td>${rs(x.invoice_total)}</td><td>${esc(x.hold_reason)}</td></tr>`),'None')}`,'x','Close'); fix('sup-close'); };
C.adminSubmit['sup-close']=async()=>modal.close();
A['crm-sup-review']=async t=>{ const r=(await api('/api/crm-receipt?id='+t.dataset.id)).receipt, p=r.payload||{};
  showModal('Review '+esc(r.receipt_no),esc(r.supplier)+' · '+esc(r.hold_reason||'')+' · by staff note: '+esc(r.note||''),`<input type="hidden" name="id" value="${r.id}">
   <div class="sup-totals"><div><span>Calculated</span><b>${rs(r.calculated_total)}</b></div><div><span>Invoice</span><b>${rs(r.invoice_total)}</b></div><div class="${r.difference?'bad':'good'}"><span>Difference</span><b>${rs(r.difference)}</b></div></div>
   ${table(['Item','Qty','Unit','Rate'],(p.lines||[]).map(l=>{const it=(data.inventory||[]).find(i=>i.id==l.item_id);return `<tr><td>${esc(it?it.name:'#'+l.item_id)}</td><td>${esc(l.qty)}</td><td>${esc(l.unit||'')}</td><td>₹${esc(l.rate)}</td></tr>`;}))}
   <p>${r.files.map(f=>`<a href="api.php?route=file&id=${f}" target="_blank" rel="noopener">📷 Photo ${f}</a>`).join(' · ')||'<b>No photo attached</b>'}</p>
   <div class="form-grid">${select('Decision','decision',opt([['approve','Approve & post'],['return','Return for correction'],['photo','Request replacement photo'],['reject','Reject']]))}${field('Corrected bill no.','invoice_ref','text',r.invoice_ref||'')}${field('Corrected invoice total ₹','invoice_total','number','','step="0.01"')}${field('Bill discount ₹','bill_discount','number','','step="0.01"')}${field('Bill tax ₹','bill_tax','number','','step="0.01"')}${field('Other charges ₹','other_charges','number','','step="0.01"')}${field('Rounding ± ₹','rounding','text','')}</div>
   ${me.user.role==='owner'?'<label class="crm-check"><input type="checkbox" name="waive_photo" value="1"> Owner: approve without photo (logged)</label>':''}<label class="crm-check"><input type="checkbox" name="accept_duplicate_photo" value="1"> Photo matches another receipt but this is a different bill</label>
   ${field('Review note (required)','note','text','','required')}<p class="crm-hint">Approval re-checks the totals. A mismatch can only be fixed by entering the real discount/tax/charges/rounding — it cannot be overridden.</p>`,'x','Save decision'); fix('sup-review'); };
C.adminSubmit['sup-review']=async(f,b)=>{ Object.keys(b).forEach(k=>{ if(b[k]==='') delete b[k]; }); const r=await api('/api/crm-receipt-decide',b); modal.close(); toast(r.status==='posted'?'Approved — stock and bill posted':'Saved: '+r.status); await refresh(); };
C.adminCards.push(duesCard);
document.addEventListener('input',ev=>{ if(ev.target.id==='sup-dues-q'){ const q=ev.target.value.toLowerCase(); document.querySelectorAll('[data-supname]').forEach(r=>r.hidden=!!q&&!r.dataset.supname.includes(q)); } });
})();
