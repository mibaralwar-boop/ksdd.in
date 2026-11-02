/* Finance 360 — Dashboard, Morning Closing wizard, Sales history, Cash Acess/Short, Expenses, Entries hub. */
'use strict';
route(/^\/$/, 'dashboard', 'dashboard.view');
route(/^\/closing\/?(\d{4}-\d{2}-\d{2})?$/, 'closing', 'closing.view');
route(/^\/sales$/, 'sales', ['sales.view', 'closing.view']);
route(/^\/cash-acess$/, 'cashacess', 'closing.variance');
route(/^\/expenses$/, 'expenses', 'expenses.view');
route(/^\/add$/, 'add', null);

/* ============================== Dashboard ============================== */
F.pages.dashboard = async (_, q, tk) => {
  title('Dashboard'); const d = await api('dashboard'); if (stale(tk)) return;
  const c = d.closing, y = d.yesterday;
  const sale = (k) => c && c.sales && c.sales[k] !== null ? c.sales[k] : (y ? y[k] : null);
  const tot = c && c.sales && c.sales.total ? c.sales.total : (y ? y.total : null);
  let h = pendingBanner();
  if (c) {
    const done = c.locked; const diff = c.calc?.operational_diff;
    h += `<section class="card"><div class="bd"><div class="row between"><div><div class="xs b" style="letter-spacing:1.6px;color:var(--orange-ink)">MORNING CLOSING · ${D(c.date).toUpperCase()}</div><h2 style="margin-top:4px">${done ? 'Closed and locked ✓' : c.status === 'draft' ? 'Draft saved — finish and submit' : 'Not started yet'}</h2></div>${statusChip(c.status)}</div>
    <div class="grid g4" style="margin-top:12px">${kpi('Cash Sale', R(sale('cash')), '', { href: '#/closing/' + c.date })}${kpi('Card Sale', R(sale('card')), '', { href: '#/closing/' + c.date })}${kpi('UPI Sale', R(sale('upi')), '', { href: '#/closing/' + c.date })}${kpi('Total Sale', R(tot), 'Cash + Card + UPI', { href: '#/sales', cls: 'accent' })}</div>
    ${can('closing.variance') && diff !== undefined && diff !== null ? `<div class="row" style="margin-top:12px">${kpi(diff >= 0 ? 'Cash Acess' : 'Cash Short', R(Math.abs(diff)), 'Actual Cash − (Total Sale − cash expenses)', { href: '#/cash-acess', cls: diff >= 0 ? '' : '' })}${kpi('Actual Cash', R(c.actual_cash), 'counted', { href: '#/closing/' + c.date })}</div>` : ''}
    <div class="row" style="margin-top:12px">${can('closing.entry') && !done ? `<a class="btn primary big" href="#/closing/${c.date}">${c.status === 'draft' ? 'Resume closing' : 'Start Morning Closing'}</a>` : `<a class="btn" href="#/closing/${c.date}">View closing</a>`}<a class="btn" href="#/sales">Day-wise sales</a></div></div></section>`;
  }
  try { const ov = await api('overview'); if (stale(tk)) return; F.overview = ov;
    if (ov.checked) h += card(`Needs attention <span class="xs muted">· ${ov.all_clear} of ${ov.checked} checks clear</span>`, ov.items.length ? `<div class="list ovw">${ov.items.map(i => `<a class="li" href="${i.link}"><span class="av ${i.level}" aria-hidden="true">${i.level === 'bad' ? '!' : i.level === 'warn' ? '•' : 'i'}</span><div class="grow"><b>${esc(i.label)}</b>${i.note ? `<span class="xs muted">${esc(i.note)}</span>` : ''}</div>${i.amount ? `<span class="b">${R(i.amount)}</span>` : ''}${chip(String(i.count), i.level)}<span aria-hidden="true">›</span></a>`).join('')}</div>` : empty('All clear ✓', '<span class="sm">Nothing is waiting for you.</span>'), { cls: 'no-print' });
  } catch (e) { h += card('', `<div class="alert warn" role="alert">Could not load “Needs attention”: ${esc(e.message)} <button class="btn sm" data-a="reload">Try again</button></div>`); }
  const cards = [];
  if (d.mtd) cards.push(kpi('Sales this month', R(d.mtd.total), 'Cash ' + R(d.mtd.cash) + ' · Card ' + R(d.mtd.card) + ' · UPI ' + R(d.mtd.upi), { href: '#/sales' }));
  if (d.last_month) cards.push(kpi('Sales ' + monthLabel(d.last_month.month), R(d.last_month.totals.total), 'Expenses ' + R(d.last_month.totals.expenses), { href: '#/sales?from=' + d.last_month.month + '-01&to=' + addDays(addDays(d.last_month.month + '-01', 40).slice(0, 8) + '01', -1) }));
  if (d.mtd && can('expenses.view')) cards.push(kpi('Expenses this month', R(d.mtd.expenses), '', { href: '#/expenses?from=' + monthStart(today()) + '&to=' + today() }));
  if (d.today_expenses) cards.push(kpi("Today's expenses", R(d.today_expenses.total), d.today_expenses.count + ' entr' + (d.today_expenses.count === 1 ? 'y' : 'ies'), { href: '#/expenses' }));
  if (d.cash_in_hand !== undefined) cards.push(kpi('Cash in hand (Books)', R(d.cash_in_hand), 'as per cash book', { href: '#/cash', cls: 'dark' }));
  if (d.mtd && d.mtd.cash_acess !== undefined) cards.push(kpi('Cash Acess / Short (month)', `<span class="good">+${R(d.mtd.cash_acess)}</span> / <span class="bad">−${R(d.mtd.cash_short)}</span>`, 'operational, from closings', { href: '#/cash-acess' }));
  if (d.supplier_payable !== undefined) cards.push(kpi('Supplier payable', R(d.supplier_payable), d.supplier_overdue ? `<span class="bad">${R(d.supplier_overdue)} overdue</span>` : 'nothing overdue', { href: d.supplier_overdue ? '#/aging' : '#/suppliers', tag: d.supplier_overdue ? chip('Overdue', 'bad') : '' }));
  if (d.purchases_mtd) cards.push(kpi('Purchases this month', R(d.purchases_mtd.total), d.purchases_mtd.count + ' bills' + (d.goods_pending ? ' · ' + d.goods_pending + ' goods pending' : ''), { href: '#/purchases', tag: d.bills_review ? chip(d.bills_review + ' to approve', 'warn') : '' }));
  if (d.low_stock !== undefined) cards.push(kpi(d.stock_value !== undefined ? 'Stock value' : 'Low stock items', d.stock_value !== undefined ? R(d.stock_value) : String(d.low_stock), d.low_stock ? d.low_stock + ' item(s) low / out' : 'all above reorder level', { href: d.low_stock ? '#/items?low=1' : '#/stock', tag: d.low_stock ? chip('Low', 'warn') : '' }));
  if (d.staff_advance !== undefined) cards.push(kpi('Staff advances outstanding', R(d.staff_advance), d.staff_count + ' active staff', { href: '#/staff' }));
  if (d.payroll) { const p = d.payroll; cards.push(kpi('Salary ' + monthLabel(p.period), p.exists ? (p.progress.done + p.progress.excluded) + ' / ' + p.progress.total : 'Not started', p.exists ? 'status: ' + p.status + (p.payment_status ? ' · ' + p.payment_status : '') : 'tap to start', { href: '#/payroll/' + p.period })); }
  if (d.pending_approvals !== undefined) cards.push(kpi('Staff entries to approve', String(d.pending_approvals), 'advance / penalty / extra', { href: '#/staff-entries?status=pending', tag: d.pending_approvals ? chip('Action', 'warn') : '' }));
  if (d.closings_to_review !== undefined) cards.push(kpi('Closings to review', String(d.closings_to_review), 'classify Cash Acess / Short', { href: '#/cash-acess', tag: d.closings_to_review ? chip('Action', 'warn') : '' }));
  if (cards.length) h += `<div class="grid g4" style="margin-bottom:16px">${cards.join('')}</div>`;
  if (d.trend && d.trend.length) h += card('Sales — last 30 days', trendChart(d.trend), { act: '<span class="xs muted"><i class="lg" style="background:#E7661A"></i>Cash <i class="lg" style="background:#1B1B1B"></i>Card <i class="lg" style="background:#C9A27A"></i>UPI</span>' });
  if (d.exp30 && Object.keys(d.exp30).length) { const mx = Math.max(...Object.values(d.exp30)); h += card('Expenses — last 30 days · ' + R(d.exp30_total), `<div class="stack" style="gap:8px">${Object.entries(d.exp30).map(([k, v]) => `<a href="#/expenses?from=${addDays(today(), -29)}&to=${today()}&category=${encodeURIComponent(k)}" style="text-decoration:none;color:inherit"><div class="row between sm"><span>${esc(k)}</span><b class="num">${R(v)}</b></div><div class="prog" style="height:8px"><i style="width:${Math.max(2, Math.round(v * 100 / mx))}%"></i></div></a>`).join('')}</div>`); }
  h += `<div class="split">`;
  if (d.recent) h += card('Last 7 days', table(['Date', { t: 'Cash', c: 'n' }, { t: 'Card', c: 'n hide-sm' }, { t: 'UPI', c: 'n hide-sm' }, { t: 'Total', c: 'n' }, 'Status'], d.recent.map(r => `<tr><td><a href="#/closing/${r.date}">${D(r.date)}</a></td><td class="n">${R(r.cash)}</td><td class="n hide-sm">${R(r.card)}</td><td class="n hide-sm">${R(r.upi)}</td><td class="n b">${R(r.total)}</td><td>${statusChip(r.status)}</td></tr>`)), { act: '<a class="btn sm" href="#/sales">All days</a>' });
  h += card('Needs attention', d.assist.items.length ? assistList(d.assist) : empty('All clear ✓', '<span class="sm">No missing entries found.</span>'));
  h += '</div>';
  if (d.accounts) h += card('Account balances (Books)', `<div class="grid g4">${d.accounts.map(a => kpi(esc(a.account), R(a.balance), 'today +' + R(a.today_in) + ' / −' + R(a.today_out), { href: a.account === 'Cash' ? '#/cash' : '#/receipts?account=' + encodeURIComponent(a.account) })).join('')}</div>`);
  return h;
};

/* ============================== Morning Closing ============================== */
const CL = { s: null, step: 1, date: null, dirty: false };
F.pages.closing = async ([date], q, tk) => {
  title('Morning Closing');
  date = date || (F.me.pending_closings?.[0]?.date) || F.me.target_date;
  const s = await api('closing', { date }); if (stale(tk)) return;
  CL.s = s; CL.date = date;
  if (s.locked || !s.can_edit) { if (s.closing && can('closing.entry')) setTimeout(() => daybookList(s.date), 0); return closingSummary(s) + (s.closing && can('closing.entry') ? daybookCard(s.date) : ''); }
  CL.step = Math.max(1, Math.min(6, q.step ? Number(q.step) : (s.status === 'not_started' ? 1 : s.step)));
  return closingStep();
};
const STEPS = ['Date', 'Sales', 'Total Sale', 'Expenses', 'Actual Cash', 'Submit'];
function liveCalc(s, actual) {
  const cash = s.sales.cash ?? 0, card = s.sales.card ?? 0, upi = s.sales.upi ?? 0, total = cash + card + upi;
  const out = { total, op: null, acc: null, expected: null };
  if (actual !== null && actual !== undefined && !Number.isNaN(actual)) { out.op = actual - (total - s.cash_out); if (s.calc && s.calc.opening !== undefined) { out.expected = s.calc.opening + cash + s.other_cash_in - s.cash_out; out.acc = actual - out.expected; } }
  return out;
}
const diffBox = (v, sub) => v === null ? '' : `<div class="diff ${v > 0 ? 'acess' : v < 0 ? 'short' : 'bal'}" role="status"><div class="lb">${v > 0 ? 'Cash Acess' : v < 0 ? 'Cash Short' : 'Balanced'}</div><div class="am">${R(Math.abs(v), true)}</div><div class="sm" style="font-weight:600">${sub}</div></div>`;
function closingStep() {
  const s = CL.s, st = CL.step; title('Morning Closing · ' + D(s.date));
  const head = `<div class="wz"><div class="wz-h"><span class="k">Morning Closing · Step ${st} of 6 · ${STEPS[st - 1]}</span><span>${statusChip(s.status)} <span class="saved" id="saved"></span></span></div><div class="steps" aria-hidden="true">${STEPS.map((_, i) => `<i class="${i + 1 < st ? 'done' : i + 1 === st ? 'on' : ''}"></i>`).join('')}</div>`;
  let b = '';
  if (s.reopen_reason && s.status === 'reopened') b += `<div class="alert warn">This day was reopened by an admin: ${esc(s.reopen_reason)}. Check the figures and submit again.</div>`;
  if (st === 1) {
    const pend = F.me.pending_closings || [];
    b += card('Which business day are you closing?', `<p class="muted sm" style="margin-top:0">Today's business date is <b>${D(s.business_date)}</b>, so yesterday = <b>${D(s.target_date)}</b>.</p>
      <div class="grid g2"><div class="kpi accent"><div class="l">Closing for</div><div class="v slab">${D(s.date)}</div><div class="s">${s.date === s.target_date ? 'Yesterday' : 'Earlier day'}</div></div>
      <div>${can('closing.approve') ? field('Choose another open day', 'pick', { type: 'date', value: s.date, attrs: `max="${s.target_date}" id="pickdate"` }) : pend.length > 1 ? select('Other pending days', 'pick', pend.map(p => [p.date, D(p.date) + ' · ' + p.status]), s.date, { attrs: 'id="pickdate"' }) : '<p class="sm muted">You can close only yesterday. Earlier days can be completed by an admin.</p>'}</div></div>
      ${s.legacy_sales?.length ? `<div class="alert warn" style="margin-top:12px">Books already has ${s.legacy_sales.length} sale entr${s.legacy_sales.length > 1 ? 'ies' : 'y'} (${R(s.legacy_sales_total)}) for this day. Your figures will be held for admin review so nothing is counted twice.</div>` : ''}`);
  }
  if (st === 2) b += card('Yesterday\'s sale from Petpooja', `<p class="muted sm" style="margin-top:0">Type the three totals from the Petpooja day report. Enter <b>0</b> if there was none.</p><form id="salesf" class="stack" novalidate>
    ${moneyField('Cash Sale · नकद बिक्री', 'cash_sale', s.sales.cash, { xl: true, req: true })}${moneyField('Card Sale · कार्ड बिक्री', 'card_sale', s.sales.card, { xl: true, req: true })}${moneyField('UPI Sale · UPI बिक्री', 'upi_sale', s.sales.upi, { xl: true, req: true })}
    ${s.petpooja ? `<div class="alert ok" style="margin:0"><b>Petpooja upload received</b> (${esc(s.petpooja.source || 'sync')}, ${TS(s.petpooja.imported_at)}): Cash ${R(s.petpooja.cash)} · Card ${R(s.petpooja.card)} · UPI ${R(s.petpooja.upi)}${s.petpooja.other ? ' · Other ' + R(s.petpooja.other) : ''}. <button type="button" class="btn sm" data-a="cl-usepp" style="margin-top:6px">Use these figures</button></div>` : ''}
    <div class="kpi accent"><div class="l">Total Sale = Cash + Card + UPI</div><div class="v" id="livetotal">${R((s.sales.cash || 0) + (s.sales.card || 0) + (s.sales.upi || 0))}</div></div></form>`);
  if (st === 3) { const t = (s.sales.cash || 0) + (s.sales.card || 0) + (s.sales.upi || 0);
    b += card('Total Sale', `<div class="sum"><div><span>Cash Sale</span><span>${R(s.sales.cash, true)}</span></div><div><span>Card Sale</span><span>${R(s.sales.card, true)}</span></div><div><span>UPI Sale</span><span>${R(s.sales.upi, true)}</span></div><div class="big"><span>Total Sale</span><span>${R(t, true)}</span></div></div>
    ${s.petpooja ? (() => { const p = s.petpooja, d = [['Cash', (s.sales.cash || 0) - p.cash], ['Card', (s.sales.card || 0) - p.card], ['UPI', (s.sales.upi || 0) - p.upi - (p.other || 0)]].filter(x => Math.abs(x[1]) >= 100); return d.length ? `<div class="alert warn">Different from the Petpooja upload: ${d.map(x => x[0] + ' ' + (x[1] > 0 ? '+' : '−') + R(Math.abs(x[1]))).join(', ')}. Please re-check.</div>` : '<div class="alert ok">Matches the Petpooja upload ✓</div>'; })() : ''}<p class="sm muted">Card and UPI money goes to the bank, not the cash drawer. Only the Cash Sale is physically in the counter.</p>${t === 0 ? '<div class="alert warn">Total Sale is ₹0 — confirm the restaurant was closed this day.</div>' : ''}`); }
  if (st === 4) {
    const ex = s.expenses, po = s.payouts;
    b += card(`Expenses on ${D(s.date)}`, `${ex.length ? table(['Category', 'Paid from', { t: 'Amount', c: 'n' }, ''], ex.map(e => `<tr><td><b>${esc(e.category || 'Uncategorised')}</b><div class="xs muted">${esc(e.description || e.party || '')}</div></td><td>${esc(e.account)}</td><td class="n">${R(e.total, true)}</td><td>${s.can_edit && can('expenses.update') && e.source !== 'f360-staff' ? `<button class="btn sm" data-a="exp-edit" data-id="${e.id}">Edit</button>` : ''}</td></tr>`).concat([`<tr class="sub"><td>Total expenses</td><td></td><td class="n">${R(s.expenses_total, true)}</td><td></td></tr>`])) : '<div class="empty"><b>No expenses recorded for this day</b></div>'}
      ${po.length ? `<h3 style="margin:14px 0 6px">Other cash paid out</h3>${table(['Type', 'Details', { t: 'Amount', c: 'n' }], po.map(e => `<tr><td>${esc(e.label)}</td><td class="sm">${esc(e.party || e.category || '')} ${esc(e.description || '')}</td><td class="n">${R(-e.cash_effect, true)}</td></tr>`))}` : ''}
      <div class="row" style="margin:14px 0">${can('expenses.create') ? `<button class="btn primary" data-a="exp-add" data-date="${s.date}">＋ Add expense</button>` : ''}</div>
      <div class="stack"><label class="check ${s.expenses_confirmed === 'reviewed' ? 'on' : ''}"><input type="radio" name="expconf" value="reviewed" ${s.expenses_confirmed === 'reviewed' ? 'checked' : ''} ${!ex.length && !po.length ? 'disabled' : ''}><span>I have checked — these are all the expenses for ${D(s.date)}.</span></label>
      <label class="check ${s.expenses_confirmed === 'none' ? 'on' : ''}"><input type="radio" name="expconf" value="none" ${s.expenses_confirmed === 'none' ? 'checked' : ''} ${ex.length ? 'disabled' : ''}><span>No expenses this day (कोई खर्च नहीं).</span></label></div>`);
  }
  if (st === 5) {
    const lc = liveCalc(s, s.actual_cash);
    b += card('Count the Actual Cash', `<p class="muted sm" style="margin-top:0">Count the notes and coins physically in the counter now.</p><form id="cashf" class="stack" novalidate>${moneyField('Actual Cash (counted) · गिना हुआ नकद', 'actual_cash', s.actual_cash, { xl: true, req: true })}
      <details class="card flat" style="margin:0"><summary class="bd b" style="cursor:pointer">Count by notes (optional calculator) · नोट गिनें</summary><div class="bd" style="padding-top:0"><div class="grid g3 keep" id="denoms">${[500, 200, 100, 50, 20, 10].map(d => `<label class="f">₹${d} × <input inputmode="numeric" data-den="${d}" placeholder="0" aria-label="Number of ₹${d} notes"></label>`).join('')}<label class="f">Coins (₹ total)<input inputmode="decimal" data-den="coins" placeholder="0"></label></div><p class="sm muted" id="dentot" style="margin:8px 0 0">Total from notes: ₹0 — fills Actual Cash automatically.</p></div></details>
      ${s.calc && s.calc.opening !== undefined ? `<div class="sum"><div><span>Total Sale</span><span>${R(lc.total, true)}</span></div><div><span>− Cash expenses & cash payouts</span><span>${R(s.cash_out, true)}</span></div><div class="big"><span>Total Sale − cash expenses</span><span>${R(lc.total - s.cash_out, true)}</span></div></div>
      <div id="diffbox">${diffBox(lc.op, 'Actual Cash − (Total Sale − cash expenses)')}</div>
      <details class="card flat" style="margin:0"><summary class="bd b" style="cursor:pointer">Accounting cash check (drawer)</summary><div class="bd" style="padding-top:0"><div class="sum" id="accbox">${accRows(s, lc)}</div><p class="xs muted">Card & UPI are not in the drawer, so the line above (owner's method) and this accounting check can differ. Nothing is posted as income or loss until an admin reviews it.</p></div></details>` : '<p class="muted sm">The admin will see the cash difference after you submit.</p>'}
      <label class="f full" id="reasonbox">Reason for difference ${s.reason_required_above >= 0 ? '(required above ' + R(s.reason_required_above) + ')' : '(optional)'}<textarea name="diff_reason" placeholder="e.g. change given from personal cash, card machine settlement…">${esc(s.diff_reason || '')}</textarea></label></form>`);
  }
  if (st === 6) {
    const lc = liveCalc(s, s.actual_cash);
    b += card('Check and submit', `<div class="sum"><div><span>Business day</span><span>${D(s.date)}</span></div><div><span>Cash Sale</span><span>${R(s.sales.cash, true)}</span></div><div><span>Card Sale</span><span>${R(s.sales.card, true)}</span></div><div><span>UPI Sale</span><span>${R(s.sales.upi, true)}</span></div><div class="big"><span>Total Sale</span><span>${R(lc.total, true)}</span></div>
      <div><span>Expenses (${s.expenses.length})</span><span>${R(s.expenses_total, true)}</span></div><div><span>All cash paid out</span><span>${R(s.cash_out, true)}</span></div><div class="big"><span>Actual Cash</span><span>${R(s.actual_cash, true)}</span></div>
      ${s.calc && s.calc.opening !== undefined ? `<div class="big"><span>${lc.op > 0 ? 'Cash Acess' : lc.op < 0 ? 'Cash Short' : 'Difference'}</span><span class="${lc.op > 0 ? 'good' : lc.op < 0 ? 'bad' : ''}">${R(Math.abs(lc.op ?? 0), true)}</span></div>` : ''}</div>
      <div id="issues"></div>
      <div class="alert danger" style="margin-top:12px"><b>After submitting, this day is LOCKED.</b> You will not be able to change sales, expenses or cash for ${D(s.date)}. Corrections need an admin.</div>
      <label class="check"><input type="checkbox" id="confirm"><span>I confirm these figures are correct.</span></label>`);
  }
  const foot = `<div class="wz-foot">${st > 1 ? '<button class="btn" data-a="cl-prev">‹ Previous</button>' : '<a class="btn" href="#/">Add later</a>'}${st > 1 && st < 6 ? '<button class="btn" data-a="cl-draft">Save draft</button>' : ''}${st < 6 ? '<button class="btn primary" data-a="cl-next">Next ›</button>' : '<button class="btn primary big" data-a="cl-submit">Submit & lock</button>'}</div></div>`;
  setTimeout(bindClosingStep, 0);
  return head + b + foot;
}
function accRows(s, lc) { return `<div><span>Opening cash</span><span>${R(s.calc.opening, true)}</span></div><div><span>+ Cash Sale</span><span>${R(s.sales.cash ?? 0, true)}</span></div><div><span>+ Other cash received</span><span>${R(s.other_cash_in, true)}</span></div><div><span>− Cash paid out</span><span>${R(s.cash_out, true)}</span></div><div class="big"><span>Expected in drawer</span><span>${R(lc.expected, true)}</span></div><div><span>Accounting variance</span><span>${lc.acc === null ? '—' : (lc.acc > 0 ? '+' : '') + R(lc.acc, true)}</span></div>`; }
function bindClosingStep() {
  const s = CL.s;
  const sf = $('#salesf'); if (sf) sf.addEventListener('input', () => { const v = ['cash_sale', 'card_sale', 'upi_sale'].map(n => toPaise(sf[n].value) || 0); $('#livetotal').textContent = R(v.reduce((a, b) => a + (Number.isNaN(b) ? 0 : b), 0)); CL.dirty = true; });
  const dn = $('#denoms'); if (dn) dn.addEventListener('input', () => { let t = 0; $$('[data-den]', dn).forEach(i => { const v = i.value.trim(); if (!v) return; if (i.dataset.den === 'coins') { const p = toPaise(v); if (!Number.isNaN(p) && p !== null) t += p; } else if (/^\d{1,5}$/.test(v)) t += Number(v) * Number(i.dataset.den) * 100; }); $('#dentot').textContent = 'Total from notes: ' + R(t, true) + ' — copied into Actual Cash.'; const a = $('#cashf').actual_cash; a.value = rupeesStr(t); a.dispatchEvent(new Event('input', { bubbles: true })); });
  const cf = $('#cashf'); if (cf) cf.addEventListener('input', () => { const a = toPaise(cf.actual_cash.value); CL.dirty = true; if (!s.calc || s.calc.opening === undefined) return; const lc = liveCalc(s, Number.isNaN(a) ? null : a); $('#diffbox').innerHTML = diffBox(lc.op, 'Actual Cash − (Total Sale − cash expenses)'); $('#accbox').innerHTML = accRows(s, lc); });
  $$('input[name=expconf]').forEach(r => r.addEventListener('change', () => { $$('.check').forEach(c => c.classList.toggle('on', $('input', c).checked)); CL.dirty = true; }));
  const pk = $('#pickdate'); if (pk) pk.addEventListener('change', () => pk.value && go('#/closing/' + pk.value));
  if (CL.step === 6) closingIssues();
}
async function closingIssues() { const el = $('#issues'); if (!el) return; const s = CL.s; const lc = liveCalc(s, s.actual_cash); const out = [];
  ['cash', 'card', 'upi'].forEach(k => { if (s.sales[k] === null) out.push(['block', k.toUpperCase() + ' Sale is not entered.', 2]); });
  if (!s.expenses_confirmed) out.push(['block', 'Review expenses or confirm “No expenses”.', 4]); if (s.actual_cash === null) out.push(['block', 'Actual Cash is not entered.', 5]);
  if (s.calc && s.calc.opening !== undefined && s.reason_required_above >= 0 && lc.op !== null && Math.abs(lc.op) > s.reason_required_above && !(s.diff_reason || '').trim()) out.push(['block', 'Write a reason for the ' + (lc.op > 0 ? 'Cash Acess' : 'Cash Short') + '.', 5]);
  if (s.legacy_sales?.length) out.push(['review', 'Books already has sale entries for this day — your sales will be held for admin review.', 1]);
  el.innerHTML = out.map(([l, m, st]) => `<div class="assist-item ${l}" style="margin-top:10px"><b>${l === 'block' ? '⛔' : '⚠'}</b> ${esc(m)} <button class="btn sm" data-a="cl-goto" data-step="${st}">Fix</button></div>`).join('');
  const sb = $('[data-a=cl-submit]'); if (sb) sb.disabled = out.some(x => x[0] === 'block'); }
async function closingSave(nextStep) {
  const s = CL.s, body = { date: s.date, version: s.version, step: nextStep };
  if (CL.step === 2) { const f = $('#salesf'); if (!moneyCheck(f, ['cash_sale', 'card_sale', 'upi_sale'])) return false; for (const n of ['cash_sale', 'card_sale', 'upi_sale']) { if (f[n].value.trim() === '' && nextStep > 2) { toast('Enter all three sales (0 if none).', true); f[n].focus(); return false; } body[n] = f[n].value.trim(); } }
  if (CL.step === 4) { const r = $('input[name=expconf]:checked'); if (!r && nextStep > 4) { toast('Tick that you reviewed the expenses, or “No expenses”.', true); return false; } body.expenses_confirmed = r ? r.value : null; }
  if (CL.step === 5) { const f = $('#cashf'); if (!moneyCheck(f, ['actual_cash'])) return false; if (f.actual_cash.value.trim() === '' && nextStep > 5) { toast('Enter the Actual Cash you counted.', true); f.actual_cash.focus(); return false; } body.actual_cash = f.actual_cash.value.trim(); body.diff_reason = f.diff_reason.value.trim(); }
  if (CL.step === 1 && s.status !== 'not_started' && nextStep === 2 && !CL.dirty) { CL.step = nextStep; return true; }
  try { CL.s = await api('closing-save', {}, body); CL.dirty = false; CL.step = nextStep; return true; }
  catch (e) { if (e.status === 409) { toast(e.message, true); CL.s = await api('closing', { date: s.date }); return false; } throw e; }
}
F.A['cl-next'] = async b => { busy(b, true); try { if (await closingSave(CL.step + 1)) { $('#content').innerHTML = closingStep(); window.scrollTo(0, 0); } } finally { busy(b, false); } };
F.A['cl-prev'] = async () => { if (CL.dirty && !(await closingSave(CL.step - 1))) return; if (!CL.dirty) CL.step--; $('#content').innerHTML = closingStep(); window.scrollTo(0, 0); };
F.A['cl-draft'] = async b => { busy(b, true); try { if (await closingSave(CL.step)) { $('#content').innerHTML = closingStep(); setTimeout(() => { const s = $('#saved'); if (s) s.textContent = '✓ Draft saved on server'; }, 10); } } finally { busy(b, false); } };
F.A['cl-usepp'] = () => { const f = $('#salesf'), p = CL.s.petpooja; f.cash_sale.value = rupeesStr(p.cash); f.card_sale.value = rupeesStr(p.card); f.upi_sale.value = rupeesStr(p.upi + (p.other || 0)); f.dispatchEvent(new Event('input', { bubbles: true })); toast('Petpooja figures filled — check them against the day report.'); };
F.A['cl-goto'] = b => { CL.step = Number(b.dataset.step); $('#content').innerHTML = closingStep(); };
F.A['cl-submit'] = async b => {
  if (!$('#confirm').checked) { toast('Tick “I confirm these figures are correct”.', true); return; }
  busy(b, true);
  try { const r = await api('closing-finalize', {}, { date: CL.s.date, version: CL.s.version, confirm: true, client_key: CL.key || (CL.key = ikey()) }); CL.key = null; await refreshMe();
    $('#content').innerHTML = `<div class="wz">${card('', `<div style="text-align:center;padding:10px 0"><div style="font-size:54px" aria-hidden="true">✅</div><h1 style="margin:6px 0">${r.final ? 'Closing final' : 'Closing submitted'}</h1><p class="muted">${esc(r.message)}</p></div><div class="sum"><div><span>Cash Sale</span><span>${R(r.summary.cash, true)}</span></div><div><span>Card Sale</span><span>${R(r.summary.card, true)}</span></div><div><span>UPI Sale</span><span>${R(r.summary.upi, true)}</span></div><div class="big"><span>Total Sale</span><span>${R(r.summary.total_sale, true)}</span></div><div><span>Expenses</span><span>${R(r.summary.expenses, true)}</span></div><div class="big"><span>Actual Cash</span><span>${R(r.summary.actual_cash, true)}</span></div></div><div class="row" style="margin-top:14px"><a class="btn primary" href="#/">Go to dashboard</a><a class="btn" href="#/closing/${r.date}">View locked closing</a><a class="btn" target="_blank" rel="noopener" href="${waLink(closingText(r.date, r.summary))}">Share on WhatsApp</a></div>`)}</div>`;
    $('#content').insertAdjacentHTML('beforeend', daybookCard(CL.s.date)); daybookList(CL.s.date);
    renderShellCounts(); }
  catch (e) { busy(b, false); if (e.status === 409) { CL.key = null; toast(e.message, true); CL.s = await api('closing', { date: CL.s.date }); if (CL.s.locked) { $('#content').innerHTML = closingSummary(CL.s); } return; } throw e; }
};
function renderShellCounts() { const n = F.me.pending_closings?.length || 0; $$('[data-nav="#/closing"]').forEach(a => { $('.cnt,.dot', a)?.remove(); if (n) a.insertAdjacentHTML('beforeend', a.closest('.bottom') ? `<span class="dot">${n}</span>` : `<span class="cnt">${n}</span>`); }); }
function closingSummary(s) {
  title('Closing · ' + D(s.date)); const c = s.calc || {}; const seeVar = c.opening !== undefined;
  let h = `<div class="row between" style="margin-bottom:12px"><div><div class="xs b" style="letter-spacing:1.6px;color:var(--orange-ink)">MORNING CLOSING</div><h1>${D(s.date)}</h1></div><div class="row">${statusChip(s.status)}${s.sales_post_status === 'held' ? statusChip('held') : ''}</div></div>`;
  if (!s.locked && s.block_reason) h += `<div class="alert warn">${esc(s.block_reason)}</div>`;
  if (s.locked) h += `<div class="row no-print" style="margin-bottom:12px"><a class="btn sm" target="_blank" rel="noopener" href="${waLink(closingText(s.date, { cash: s.sales.cash, card: s.sales.card, upi: s.sales.upi, total_sale: s.sales.total, expenses: s.expenses_total, actual_cash: s.actual_cash }, c.operational_diff))}">Share on WhatsApp</a><button class="btn sm" data-a="print">Print</button></div>`;
  h += `<div class="split"><div>`;
  h += card('Sales', `<div class="grid g4">${kpi('Cash Sale', R(s.sales.cash))}${kpi('Card Sale', R(s.sales.card))}${kpi('UPI Sale', R(s.sales.upi))}${kpi('Total Sale', R(s.sales.total), '', { cls: 'accent' })}</div>${s.legacy_sales?.length ? `<p class="sm muted">Books also has ${s.legacy_sales.length} sale entr${s.legacy_sales.length > 1 ? 'ies' : 'y'} for this day (${R(s.legacy_sales_total)}).</p>` : ''}`);
  h += card(`Expenses (${s.expenses.length}) · ${R(s.expenses_total)}`, s.expenses.length ? table(['Category', 'Paid from', { t: 'Amount', c: 'n' }], s.expenses.map(e => `<tr><td>${esc(e.category || '—')}<div class="xs muted">${esc(e.description || '')}</div></td><td>${esc(e.account)}</td><td class="n">${R(e.total, true)}</td></tr>`)) : `<p class="muted">${s.expenses_confirmed === 'none' ? 'Confirmed: no expenses this day.' : 'No expenses recorded.'}</p>`);
  if (s.locked || s.actual_cash !== null) h += card('Cash', `<div class="sum"><div><span>Actual Cash (counted)</span><span>${R(s.actual_cash, true)}</span></div><div><span>Cash paid out</span><span>${R(s.cash_out, true)}</span></div>${seeVar ? `<div><span>Opening cash</span><span>${R(c.opening, true)}</span></div><div><span>Expected in drawer</span><span>${R(c.expected_cash, true)}</span></div>` : ''}</div>
    ${seeVar ? `<div class="grid g2" style="margin-top:12px">${diffBox(c.operational_diff, 'Operational: Actual − (Total Sale − cash expenses)')}<div class="diff bal"><div class="lb" style="font-size:16px">Accounting variance</div><div class="am" style="font-size:26px">${c.accounting_variance > 0 ? '+' : ''}${R(c.accounting_variance, true)}</div><div class="sm">Actual − expected drawer cash</div></div></div>` : ''}${s.diff_reason ? `<p class="sm"><b>Reason:</b> ${esc(s.diff_reason)}</p>` : ''}`);
  h += `</div><div>`;
  if (s.status === 'submitted' && s.can_review) h += card('Admin review', `<p class="sm muted" style="margin-top:0">Decide how the difference is recorded. Nothing has been posted as income or loss yet.</p><form data-form="cl-review" class="stack">
    <label class="check"><input type="radio" name="classification" value="no_posting" checked><span>Accept — <b>no posting</b> (management figure only)</span></label>
    <label class="check"><input type="radio" name="classification" value="post_accounting"><span>Post the <b>accounting variance</b> ${R(c.accounting_variance, true)} to Books as ${c.accounting_variance >= 0 ? 'Cash Acess (cash in)' : 'Cash Short (cash out)'} — makes the cash book match the count</span></label>
    <label class="check"><input type="radio" name="classification" value="post_operational"><span>Post the <b>operational difference</b> ${R(c.operational_diff, true)} as ${c.operational_diff >= 0 ? 'Cash Acess' : 'Cash Short'} (includes Card/UPI effect — use only if that is your policy)</span></label>
    ${s.sales_post_status === 'held' ? `<div class="alert warn">Sales are held: Books already had sale entries for this day.</div><label class="check"><input type="radio" name="held_sales" value="keep_existing" checked><span>Keep the existing Books sales (do not post closing sales)</span></label><label class="check"><input type="radio" name="held_sales" value="post"><span>Post the closing Cash/Card/UPI sales too</span></label>` : ''}
    ${textarea('Review note', 'note', '', { ph: 'Required when posting' })}<button class="btn primary" type="submit">Approve closing</button></form>`);
  if (s.status === 'approved') h += card('Review', `<p>${statusChip('approved')} ${esc({ no_posting: 'No posting', post_accounting: 'Accounting variance posted', post_operational: 'Operational difference posted' }[s.variance_class] || s.variance_class)}</p>${s.review_note ? `<p class="sm">${esc(s.review_note)}</p>` : ''}`);
  if (s.locked) h += card('Corrections', `${s.corrections.length ? s.corrections.map(c => `<div class="li"><div class="grow"><b>${esc(c.reason)}</b><span class="xs muted">${TS(c.requested_at)}</span></div>${statusChip(c.status === 'open' ? 'pending' : c.status)}</div>`).join('') : '<p class="muted sm">No correction requests.</p>'}
    <div class="row" style="margin-top:10px">${s.can_correction ? `<button class="btn" data-a="cl-correct">Request correction</button>` : ''}${s.can_reopen ? `<button class="btn danger" data-a="cl-reopen">Reopen day (admin)</button>` : ''}</div>`);
  if (!s.locked && s.can_edit) h += card('', `<a class="btn primary block" href="#/closing/${s.date}?step=1">Continue closing</a>`);
  if (s.events.length) h += card('History', s.events.map(e => `<div class="li"><div class="grow"><b>${esc(e.action.replace('_', ' '))}</b> <span class="xs muted">rev ${e.revision}</span><div class="xs muted">${esc(e.user || '')} · ${TS(e.at)}</div>${e.note ? `<div class="sm">${esc(e.note)}</div>` : ''}</div></div>`).join(''));
  h += '</div></div>';
  return h;
}
F.forms['cl-review'] = async (f, d) => { const r = await api('closing-review', {}, { date: CL.s.date, classification: d.classification, held_sales: d.held_sales, note: d.note }); CL.s = r; toast('Closing approved.'); $('#content').innerHTML = closingSummary(r); };
F.A['cl-correct'] = async () => { const why = await ask('Request correction', 'Explain what is wrong. The admin will reopen the day if needed.', { input: 'What needs correcting', required: true, ok: 'Send request' }); if (!why) return; const r = await api('correction-request', {}, { date: CL.s.date, reason: why }); toast(r.message); render(); };
F.A['cl-reopen'] = async () => { const why = await ask('Reopen ' + D(CL.s.date) + '?', 'Posted sales and any Cash Acess/Short entry of this revision will be cancelled (kept in history). The counter can then edit and submit again.', { input: 'Reason for reopening', required: true, danger: true, ok: 'Reopen day' }); if (!why) return; await api('closing-reopen', {}, { date: CL.s.date, reason: why }); await refreshMe(); toast('Day reopened.'); render(); };

/* ============================== Expense form (shared) ============================== */
let EXPCATS = null;
async function expenseForm(x = {}, after) {
  EXPCATS ??= await api('expense-categories');
  const groups = {}; EXPCATS.categories.forEach(c => (groups[c.group] ??= []).push(c.name));
  const catSel = `<label class="f">Category *<select name="category" required><option value="">Choose…</option>${Object.entries(groups).map(([g, l]) => `<optgroup label="${esc(g)}">${l.map(c => `<option ${c === x.category ? 'selected' : ''}>${esc(c)}</option>`).join('')}</optgroup>`).join('')}</select></label>`;
  const modeOf = a => ({ 'Cash': 'Cash', 'UPI / Paytm': 'UPI', 'Bank of India': 'Bank', 'Card': 'Card' }[a] || a || 'Cash');
  const chips = !x.id && EXPCATS.frequent?.length ? `<div class="full"><span class="xs muted b">Frequent</span><div class="row" style="gap:6px;margin-top:4px">${EXPCATS.frequent.map(c => `<button type="button" class="chip or" style="border:0;cursor:pointer;min-height:34px" data-a="exp-chip" data-c="${esc(c)}">${esc(c)}</button>`).join('')}</div></div>` : '';
  const m = modal({ title: x.id ? 'Edit expense' : 'Add expense', sub: 'Only for open days. Staff advance / salary / overtime are entered from Staff.', body: `<form data-form="exp" id="expf" novalidate><input type="hidden" name="id" value="${x.id || ''}"><div class="fg">
    ${chips}${field('Date', 'date', { type: 'date', value: x.date || today(), req: true, attrs: `max="${today()}"` })}${moneyField('Amount', 'amount', x.total ?? null, { req: true })}${catSel}
    ${select('Paid from', 'mode', [['Cash', 'Cash (counter)'], ['UPI', 'UPI'], ['Bank', 'Bank'], ['Card', 'Card']], modeOf(x.account), { req: true })}
    ${field('Paid to (shop / person)', 'party', { value: x.party || '' })}${field('Bill number', 'bill_no', { value: x.bill_no || '' })}${textarea('Description', 'description', x.description || '')}
    ${(x.files || []).length ? `<div class="full"><span class="xs muted b">Attached</span><div class="row" style="gap:8px;margin-top:4px">${x.files.map(fl => `<label class="chip" style="gap:6px"><a href="f360-api.php?route=file&id=${fl.id}" target="_blank" rel="noopener">${fl.mime === 'application/pdf' ? 'PDF' : 'Photo'} #${fl.id}</a><input type="checkbox" name="rm_${fl.id}" aria-label="Remove"> remove</label>`).join('')}</div></div>` : ''}
    <label class="f full">Bill photos / PDF (one or more)<input type="file" name="file" accept="image/*,application/pdf" multiple data-a-change="exp-prev"><span class="h">Take a photo or choose several. Photos are made smaller before upload. ${x.id ? 'New files are added to the ones above.' : 'Optional unless the owner set a limit.'}</span></label><div class="full row" id="expprev" style="gap:6px"></div></div><button type="submit" hidden></button></form>`,
    foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" data-submit data-a="exp-submit">${x.id ? 'Save changes' : 'Save expense'}</button>` });
  m._after = after;
}
F.A['exp-chip'] = b => { const sel = $('#expf [name=category]'); sel.value = b.dataset.c; $$('[data-a=exp-chip]').forEach(x => x.classList.toggle('dk', x === b)); $('#expf [name=amount]').focus(); };
F.A['exp-repeat'] = async b => { const r = await api('expenses', { id: b.dataset.id }); const x = r.rows[0]; if (!x) return; expenseForm({ ...x, id: null, date: today(), file_id: null }); };
F.A['exp-submit'] = () => $('#expf').requestSubmit();
F.forms.exp = async (f, d, e, dupOk = false) => {
  if (!moneyCheck(f, ['amount'])) return; if (!d.category) { toast('Choose a category.', true); return; }
  const files = []; for (const fl of [...(f.file?.files || [])].slice(0, 8)) files.push(await fileToUpload({ files: [fl] }));
  const files_remove = Object.keys(d).filter(k => k.startsWith('rm_') && d[k]).map(k => Number(k.slice(3)));
  const st = $('#expprev'); if (st && files.length) st.insertAdjacentHTML('beforeend', `<span class="xs muted">Uploading ${files.length} file(s)…</span>`);
  try { await api('expense-save', {}, { ...d, id: d.id || null, files, files_remove, confirm_duplicate: dupOk }); }
  catch (er) { if (er.status === 409 && er.data?.duplicate) { if (await ask(er.data.reason === 'same_receipt' ? 'Same bill photo again?' : 'Possible duplicate', esc(er.message), { ok: 'Yes, it is a different expense' })) return F.forms.exp(f, d, e, true); return; } throw er; }
  const m = f.closest('.modal'); const after = m._after; m._close(); toast('Expense saved.'); after ? after() : render();
};
F.A['exp-add'] = b => expenseForm({ date: b.dataset.date }, async () => { CL.s = await api('closing', { date: CL.s?.date || b.dataset.date }); if (parseHash().path.startsWith('/closing')) $('#content').innerHTML = closingStep(); else render(); });
F.A['exp-edit'] = async b => { const r = await api('expenses', { id: b.dataset.id }); const x = r.rows[0]; if (!x) return; expenseForm(x, async () => { if (parseHash().path.startsWith('/closing') && CL.s) { CL.s = await api('closing', { date: CL.s.date }); $('#content').innerHTML = closingStep(); } else render(); }); };
F.A['exp-void'] = async b => { const why = await ask('Cancel this expense?', 'It stays in history as cancelled.', { input: 'Reason', required: true, danger: true, ok: 'Cancel expense' }); if (!why) return; await api('expense-void', {}, { id: b.dataset.id, reason: why }); toast('Expense cancelled.'); render(); };
F.A['exp-review'] = async b => { await api('expense-review', {}, { id: b.dataset.id, status: b.dataset.st }); toast('Marked ' + b.dataset.st + '.'); render(); };

/* ============================== Expenses page ============================== */
F.pages.expenses = async (_, q, tk) => {
  title('Expenses'); const from = q.from || addDays(today(), -6), to = q.to || today();
  const r = await api('expenses', { from, to, category: q.category, account: q.account, review: q.review, q: q.q }); if (stale(tk)) return;
  EXPCATS ??= await api('expense-categories');
  setTimeout(() => assistant('expenses'), 50);
  const ex = fmt => exportUrl({ kind: 'expenses', format: fmt, from, to, category: q.category || '', account: q.account || '' });
  let h = `${can('expenses.create') ? `<div class="row no-print" style="margin-bottom:12px"><button class="btn primary big" data-a="exp-add" data-date="${today()}">＋ Add expense</button><span class="sm muted">${D(from)} – ${D(to)}</span></div>` : ''}` + filterBox(`<form data-form="exp-filter"><div class="bd" style="padding-top:0"><div class="fg" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">${field('From', 'from', { type: 'date', value: from })}${field('To', 'to', { type: 'date', value: to })}
    ${select('Category', 'category', EXPCATS.categories.map(c => c.name), q.category || '', { blank: 'All categories' })}${select('Paid from', 'account', EXPCATS.accounts, q.account || '', { blank: 'All' })}${can('expenses.review') ? select('Review', 'review', [['pending', 'Pending'], ['reviewed', 'Reviewed'], ['flagged', 'Flagged']], q.review || '', { blank: 'All' }) : ''}${field('Search', 'q', { value: q.q || '', ph: 'description, shop, bill' })}</div>
    <div class="row" style="margin-top:12px"><button class="btn dark" type="submit">Show</button>${can('reports.export') ? `<a class="btn sm" href="${ex('xlsx')}">Excel</a><a class="btn sm" href="${ex('pdf')}">PDF</a><a class="btn sm" href="${ex('csv')}">CSV</a>` : ''}</div></div></form>`);
  h += `<div class="grid g4" style="margin-bottom:14px">${kpi('Total', R(r.total), r.count + ' entries', { cls: 'accent' })}${Object.entries(r.by_account).slice(0, 3).map(([a, v]) => kpi(esc(a), R(v))).join('')}</div>`;
  h += `<div class="split"><div>${card('Entries', table(['Date', 'Category', { t: 'Amount', c: 'n' }, 'Paid from', 'Status', ''], r.rows.map(x => `<tr class="${x.voided ? 'void' : ''}"><td class="num">${D(x.date)}</td><td><b>${esc(x.category || '—')}</b><div class="xs muted">${esc([x.party, x.bill_no && 'Bill ' + x.bill_no, x.description].filter(Boolean).join(' · '))}</div><div class="xs muted">by ${esc(x.created_by_name || '—')}${x.staff_linked ? ' · staff/salary entry' : ''}</div></td><td class="n">${R(x.total, true)}</td><td>${esc(x.account)}</td>
    <td>${x.voided ? chip('Cancelled', 'bad') : statusChip(x.review_status)}${(x.files || []).map((fl, i) => ` <a class="xs" href="f360-api.php?route=file&id=${fl.id}" target="_blank" rel="noopener">bill${x.files.length > 1 ? ' ' + (i + 1) : ''}</a>`).join('')}</td>
    <td class="no-print" style="white-space:nowrap">${x.editable && can('expenses.update') ? `<button class="btn sm" data-a="exp-edit" data-id="${x.id}">Edit</button>` : x.voided ? '' : `<span class="xs muted" title="${esc(x.lock_reason || '')}" aria-label="Locked: ${esc(x.lock_reason || '')}">🔒</span>`}${x.editable && can('expenses.void') ? ` <button class="btn sm danger" data-a="exp-void" data-id="${x.id}">Cancel</button>` : ''}${can('expenses.create') && !x.voided && !x.staff_linked ? ` <button class="btn sm ghost" data-a="exp-repeat" data-id="${x.id}" title="Add the same expense for today">Repeat</button>` : ''}${can('expenses.review') && !x.voided ? ` <button class="btn sm" data-a="exp-review" data-id="${x.id}" data-st="reviewed" aria-label="Mark reviewed" title="Mark reviewed">✓</button> <button class="btn sm" data-a="exp-review" data-id="${x.id}" data-st="flagged" aria-label="Flag for follow-up" title="Flag">⚑</button>` : ''}</td></tr>`), { none: 'No expenses in this period', hint: can('expenses.create') ? 'Use “Add expense” above, or choose other dates.' : 'Choose other dates.' }))}</div>
    <div>${card('By category', Object.keys(r.by_category).length ? `<div class="sum">${Object.entries(r.by_category).map(([c, v]) => `<div><span>${esc(c)}</span><span>${R(v)}</span></div>`).join('')}</div>` : empty('No expenses'))}</div></div>`;
  return h;
};
F.forms['exp-filter'] = (f, d) => go('#/expenses?' + new URLSearchParams(Object.entries(d).filter(([, v]) => v)).toString());

/* ============================== Sales history & Cash Acess ============================== */
F.pages.sales = async (_, q, tk) => {
  title('Sales history'); const from = q.from || addDays(today(), -30), to = q.to || today();
  const r = await api('sales-history', { from, to }); if (stale(tk)) return; const v = can('closing.variance');
  const ex = fmt => exportUrl({ kind: 'sales', format: fmt, from, to });
  return `<form class="card no-print" data-form="range" data-to="#/sales"><div class="bd row">${field('From', 'from', { type: 'date', value: from })}${field('To', 'to', { type: 'date', value: to })}<button class="btn dark" type="submit">Show</button>${can('reports.export') ? `<a class="btn sm" href="${ex('xlsx')}">Excel</a><a class="btn sm" href="${ex('pdf')}">PDF</a>` : ''}<button class="btn sm" type="button" data-a="print">Print</button></div></form>
  <div class="grid g4" style="margin-bottom:14px">${kpi('Total Sale', R(r.totals.total), D(from) + ' – ' + D(to), { cls: 'accent' })}${kpi('Cash Sale', R(r.totals.cash))}${kpi('Card Sale', R(r.totals.card))}${kpi('UPI Sale', R(r.totals.upi))}</div>
  ${card('Day-wise sales & expenses', table(['Date', { t: 'Cash', c: 'n' }, { t: 'Card', c: 'n' }, { t: 'UPI', c: 'n' }, { t: 'Total Sale', c: 'n' }, { t: 'Expenses', c: 'n' }, ...(v ? [{ t: 'Cash Acess / Short', c: 'n' }] : []), 'Status'],
    r.days.map(d => `<tr><td><a href="#/closing/${d.date}">${D(d.date)}</a>${d.note ? `<div class="xs warn-t">${esc(d.note)}</div>` : ''}</td><td class="n">${R(d.cash)}</td><td class="n">${R(d.card)}</td><td class="n">${R(d.upi)}</td><td class="n b">${R(d.total)}</td><td class="n">${R(d.expenses)}</td>${v ? `<td class="n">${d.operational_diff !== undefined ? diffLabel(d.operational_diff) : '—'}</td>` : ''}<td>${statusChip(d.status)}</td></tr>`)
    .concat([`<tr class="tot"><td>Total</td><td class="n">${R(r.totals.cash)}</td><td class="n">${R(r.totals.card)}</td><td class="n">${R(r.totals.upi)}</td><td class="n">${R(r.totals.total)}</td><td class="n">${R(r.totals.expenses)}</td>${v ? `<td class="n">+${R(r.totals.cash_acess)} / −${R(r.totals.cash_short)}</td>` : ''}<td></td></tr>`])))}
  <p class="xs muted">Days before Finance 360 closings show the sales recorded in Books 360 (status “Books”). Closed days are view-only.</p>`;
};
F.forms.range = (f, d) => go(f.dataset.to + '?' + new URLSearchParams(Object.entries(d).filter(([, v]) => v)).toString());
F.pages.cashacess = async (_, q, tk) => {
  title('Cash Acess / Cash Short'); const from = q.from || monthStart(today()), to = q.to || today();
  const r = await api('sales-history', { from, to }); if (stale(tk)) return;
  const rows = r.days.filter(d => d.operational_diff !== undefined);
  return `<div class="alert"><b>Two figures, clearly separated.</b> <i>Cash Acess / Cash Short</i> = Actual Cash − (Total Sale − cash expenses), the owner's management method (it includes Card & UPI). <i>Accounting variance</i> = Actual Cash − expected drawer cash. Neither is income or loss until an admin approves a classification.</div>
  <form class="card no-print" data-form="range" data-to="#/cash-acess"><div class="bd row">${field('From', 'from', { type: 'date', value: from })}${field('To', 'to', { type: 'date', value: to })}<button class="btn dark" type="submit">Show</button></div></form>
  <div class="grid g4" style="margin-bottom:14px">${kpi('Cash Acess (total)', '<span class="good">' + R(r.totals.cash_acess) + '</span>', 'days with positive difference')}${kpi('Cash Short (total)', '<span class="bad">' + R(r.totals.cash_short) + '</span>', 'days with negative difference')}${kpi('Days closed', String(rows.length))}${kpi('Waiting for review', String(rows.filter(d => d.status === 'submitted').length), '', { cls: rows.some(d => d.status === 'submitted') ? 'accent' : '' })}</div>
  ${card('Closings', table(['Date', { t: 'Total Sale', c: 'n' }, { t: 'Actual Cash', c: 'n' }, { t: 'Cash Acess / Short', c: 'n' }, { t: 'Accounting variance', c: 'n' }, 'Status', ''], rows.map(d => `<tr><td>${D(d.date)}</td><td class="n">${R(d.total)}</td><td class="n">${R(d.actual_cash)}</td><td class="n">${diffLabel(d.operational_diff)}</td><td class="n">${d.accounting_variance > 0 ? '+' : ''}${R(d.accounting_variance)}</td><td>${statusChip(d.status)}</td><td><a class="btn sm" href="#/closing/${d.date}">${d.status === 'submitted' && can('closing.approve') ? 'Review' : 'Open'}</a></td></tr>`)))}`;
};

/* ============================== Entries hub (mobile "Entries" tab) ============================== */
F.pages.add = async () => {
  title('Add');
  const t = (href, ic, l, s, p) => navOk(p) ? `<a class="kpi" href="${href}"><div style="font-size:28px" aria-hidden="true">${ic}</div><div class="v" style="font-size:18px">${l}</div><div class="s">${s}</div></a>` : '';
  const btn = (a, ic, l, s, p, data = '') => navOk(p) ? `<button class="kpi" style="text-align:left;cursor:pointer;font:inherit" data-a="${a}" ${data}><div style="font-size:28px" aria-hidden="true">${ic}</div><div class="v" style="font-size:18px">${l}</div><div class="s">${s}</div></button>` : '';
  const g = (h, x) => x.trim() ? `<h2 class="sec">${h}</h2><div class="grid g3 keep" style="margin-bottom:14px">${x}</div>` : '';
  const html = g('Purchases & stock', t('#/purchase/new', '🧾', 'New purchase bill', 'with bill photo', 'purchase.create') + btn('sup-new', '⛟', 'New supplier', 'name, phone, GST', 'supplier.create') + btn('item-new', '▦', 'New item', 'with pack sizes', 'item.create') + t('#/consumption', '🍳', 'Stock used today', 'many items at once', 'stock.issue') + btn('smove', '±', 'Stock adjustment', 'issue · wastage · adjust', ['stock.issue', 'stock.adjust'], 'data-k="issue"') + btn('sup-pay', '₹', 'Pay supplier', 'cash · UPI · bank', 'supplier.pay'))
    + g('Daily', btn('exp-add', '↘', 'Expense', 'खर्च जोड़ें', 'expenses.create', `data-date="${today()}"`) + t('#/closing', '☀', 'Morning Closing', 'yesterday\'s sales & cash', 'closing.entry'))
    + g('Staff', ['advance', 'penalty', 'overtime', 'extra_now'].map(k => btn('stxn-new', { advance: '⇧', penalty: '⚠', overtime: '◷', extra_now: '★' }[k], { advance: 'Advance', penalty: 'Penalty', overtime: 'Overtime', extra_now: 'Extra / bonus' }[k], { advance: 'एडवांस', penalty: 'जुर्माना', overtime: 'ओवरटाइम', extra_now: 'paid now' }[k], 'stafftxn.create', `data-kind="${k}"`)).join(''))
    + g('History', t('#/purchases?status=drafts', '✎', 'Purchase drafts', 'resume', 'purchase.create') + t('#/expenses', '≡', 'Expense history', 'day-wise', 'expenses.view') + t('#/staff-entries', '✎', 'Staff entries', 'approve / settle', ['stafftxn.create', 'staff.ledger']));
  return html || card('', empty('No entry permissions', '<span class="sm">Ask the owner for access.</span>'));
};

function closingText(d, x, diff) { const has = diff !== undefined && diff !== null; return fillMsg('closing', { restaurant: F.me.business?.name || 'Khadak Singh Da Dhaba', date: D(d), cash: R(x.cash), card: R(x.card), upi: R(x.upi), total: R(x.total_sale), expenses: R(x.expenses), actual: R(x.actual_cash), diff_label: has ? (diff > 0 ? 'Cash Acess' : diff < 0 ? 'Cash Short' : 'Difference') : 'Difference', diff: has ? R(Math.abs(diff)) : '—' }); }

function trendChart(t) {
  const W = 720, H = 190, pad = 26, n = t.length, bw = (W - pad) / n, mx = Math.max(1, ...t.map(x => x.cash + x.card + x.upi)), y = v => (H - 22) * v / mx;
  const bars = t.map((x, i) => { const X = pad + i * bw + 1.5, w = Math.max(2, bw - 3); let top = H - 18; const seg = [['#E7661A', x.cash], ['#1B1B1B', x.card], ['#C9A27A', x.upi]].map(([c, v]) => { const h = y(v); top -= h; return h > 0 ? `<rect x="${X.toFixed(1)}" y="${top.toFixed(1)}" width="${w.toFixed(1)}" height="${h.toFixed(1)}" fill="${c}" rx="1.5"></rect>` : ''; }).join('');
    return `<g><title>${D(x.date)}: total ${R(x.cash + x.card + x.upi)} (cash ${R(x.cash)}, card ${R(x.card)}, UPI ${R(x.upi)})</title>${seg}${i % 5 === 0 || i === n - 1 ? `<text x="${(X + w / 2).toFixed(1)}" y="${H - 4}" font-size="10" text-anchor="middle" fill="#6B6259">${x.date.slice(8)}/${x.date.slice(5, 7)}</text>` : ''}</g>`; }).join('');
  const total = t.reduce((a, x) => a + x.cash + x.card + x.upi, 0), days = t.filter(x => x.cash + x.card + x.upi > 0).length;
  return `<svg viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="Daily sales for the last 30 days" style="display:block;width:100%;height:auto"><text x="2" y="12" font-size="10" fill="#6B6259">${R(mx)}</text><line x1="${pad}" y1="${H - 18}" x2="${W}" y2="${H - 18}" stroke="#EADFCF"></line>${bars}</svg><div class="row sm muted" style="margin-top:6px"><span>30-day total <b class="num" style="color:var(--ink)">${R(total)}</b></span><span>Average per trading day <b class="num" style="color:var(--ink)">${R(days ? Math.round(total / days) : 0)}</b></span></div>`;
}

F.A['exp-prev'] = el => { const box = $('#expprev'); if (!box) return; box.innerHTML = [...el.files].slice(0, 8).map(fl => fl.type.startsWith('image/') ? `<img src="${URL.createObjectURL(fl)}" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #eadfce">` : `<span class="chip">PDF · ${esc(fl.name)}</span>`).join('') + (el.files.length > 8 ? '<span class="xs bad">Only the first 8 files are used.</span>' : ''); };

/* V29: optional photo(s) of the hand-written daybook / closing register. Never required; a failed upload never touches the closing. */
function daybookCard(date) {
  return `<div class="wz">${card('Daybook photo (optional)', `<p class="muted">Add a photo of the hard-copy closing register for ${esc(date)} if you like. You can skip this.</p>
    <div id="dbk-list" class="muted">…</div><label class="f"><span>Photo</span><input type="file" id="dbk-file" accept="image/jpeg,image/png,image/webp" capture="environment"></label>
    <button class="btn primary" data-a="cl-photo" data-date="${esc(date)}">Upload photo</button>`)}</div>`;
}
async function daybookList(date) { try { const r = await api('closing-photos', { date }); const el = $('#dbk-list'); if (!el) return;
  el.innerHTML = r.photos.length ? r.photos.map(p => `<a href="f360-api.php?route=file&id=${p.file_id}" target="_blank" rel="noopener"><img src="f360-api.php?route=thumb&id=${p.file_id}" alt="Daybook photo" width="96" style="border-radius:8px;margin:4px"></a>`).join('') : 'No photo added.'; } catch (e) { } }
F.A['cl-photo'] = async b => { const inp = $('#dbk-file'); busy(b, true);
  try { const f = await fileToUpload(inp); if (!f) { toast('Choose a photo first.', true); return; } if (!/^image\//.test(inp.files[0].type)) { toast('Daybook photo must be an image.', true); return; }
    await api('closing-photo', {}, { date: b.dataset.date, file: f, client_key: ikey() }); inp.value = ''; toast('Photo saved.'); daybookList(b.dataset.date); }
  catch (e) { toast((e.message || 'Upload failed') + ' — your closing is safe; try the photo again.', true); } finally { busy(b, false); } };
