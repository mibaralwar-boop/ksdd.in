/* Finance 360 V19 — Category-wise salary wizard.
   Department cards (Management · UT · Kitchen · Waiter) → staff list (Pending / Draft / Completed) → Smart Salary Popup with only six inputs:
   Present, Absent, Half days, Paid leave, Advance recovery, Penalty. Every keystroke is autosaved to the server (never only in the browser);
   all money is calculated by the server with the actual calendar-day divisor. */
'use strict';
const SW = { p: null, v: null, dept: null, emp: null, line: null, saveT: null, saving: false, savedAt: null, failed: false, err: '', preview: null, mode: 'edit', rev: 0, ack: 0, vals: null, sel: [], conflict: null, final: false };
const ENTRY = { pending: ['Pending', 'warn', 'Start'], draft: ['Draft', 'info', 'Resume'], completed: ['Completed', 'ok', 'Review'], excluded: ['Excluded', '', 'View'] };
const entryChip = s => chip((ENTRY[s] || [s])[0], (ENTRY[s] || [, ''])[1]);
const apprLabel = v => ({ draft: 'Not submitted', review: 'Submitted for approval', approved: v.payment_status === 'paid' ? 'Paid' : v.payment_status === 'partially_paid' ? 'Partially paid' : 'Approved', locked: v.payment_status === 'paid' ? 'Paid · locked' : 'Approved · locked' }[v.status] || v.status);
const editableP = v => ['draft', 'review'].includes(v.status) && v.can?.prepare;

F.pages.swizgrid = ([p, dept], q, tk) => F.pages.swiz([p], { ...q, dept }, tk);
F.pages.swiz = async ([p, emp], q, tk) => {
  title('Salary · ' + monthLabel(p)); SW.p = p;
  let v = await api('payroll', { period: p }); if (stale(tk)) return;
  // Always bring the staff list up to date (new joiners, department changes). Idempotent on the server.
  if (can('payroll.prepare') && (!v.exists || ['draft', 'review'].includes(v.status))) {
    if (p >= (F.me.business_date || today()).slice(0, 7)) return card('', empty('Salary for ' + monthLabel(p) + ' can be prepared from 1 ' + monthLabel(addMonth(p, 1)), '', `<a class="btn" href="#/payroll">Back</a>`));
    try { v = await api('payroll-open', {}, { period: p }); } catch (e) { if (stale(tk)) return; if (e.status !== 409) throw e;
      const due = F.me.popups?.payroll?.period; return card('', empty('Salary for ' + monthLabel(p) + ' is not open yet', `<span class="sm">${esc(e.message)}</span>`, (due && due !== p ? `<a class="btn primary" href="#/payroll/${due}/wizard">Open ${monthLabel(due)}</a> ` : '') + '<a class="btn" href="#/payroll">All salary months</a>')); }
    if (stale(tk)) return; }
  if (!v.exists) return card('', empty('Salary sheet not started', '<span class="sm">Ask someone with “Prepare salary” permission to start it.</span>'));
  SW.v = v; SW.dept = q.dept ? Number(q.dept) : null;
  if (emp) { const l = v.lines.find(x => String(x.employee_id) === String(emp)); if (l) { SW.dept = l.dept_id; setTimeout(() => swOpen(l.employee_id), 30); } }
  return SW.dept ? swDept() : swHub();
};
function addMonth(p, n) { let [y, m] = p.split('-').map(Number); m += n; while (m > 12) { m -= 12; y++; } while (m < 1) { m += 12; y--; } return y + '-' + String(m).padStart(2, '0'); }
function swCounts(lines) { const c = { total: 0, pending: 0, draft: 0, completed: 0, excluded: 0 }; lines.forEach(l => { c.total++; c[l.entry_status] = (c[l.entry_status] || 0) + 1; }); return c; }
function swNotOnSheet(v) { const n = v.not_on_sheet || []; if (!n.length) return '';
  const nj = n.filter(x => x.fix === 'joining'); const bulk = nj.length > 1 && can('staff.manage') ? `<div class="bd row" style="border-bottom:1px solid var(--line-2)"><button class="btn primary" data-a="sw-join-all">Correct all ${nj.length} joining dates</button></div>` : '';
  return card(`⚠ ${n.length} staff not on this month's sheet`, bulk + `<div class="list">${n.map(x => `<div class="li"><div class="grow"><b>${esc(x.name)}</b><span class="sm muted">${esc(x.code || '')}${x.dept ? ' · ' + esc(x.dept) : ''} — ${esc(x.reason)}</span></div>
    ${x.fix === 'joining' && can('staff.manage') ? `<button class="btn sm primary" data-a="sw-join" data-id="${x.id}" data-n="${esc(x.name)}" data-d="${esc(x.joined_on || '')}">Correct joining date</button>` : x.fix === 'department' && can('staff.manage') ? `<a class="btn sm primary" href="#/staff/${x.id}">Set department</a>` : x.fix === 'refresh' && v.can?.prepare ? '<button class="btn sm" data-a="reload">Refresh</button>' : ''}</div>`).join('')}</div>
    <p class="bd xs muted" style="margin:0">Staff appear on a month only if they have a department & designation and their joining date is on or before the month's last day.</p>`, { cls: 'warn-card' }); }
function swHub() {
  const v = SW.v, p = v.period, deps = v.departments || DEPTS(), all = swCounts(v.lines.filter(l => l.dept_id)); const ed = editableP(v);
  const head = `<div class="row between" style="margin-bottom:10px"><div><div class="xs b" style="letter-spacing:1.6px;color:var(--orange-ink)">SALARY · ${v.days} CALENDAR DAYS · DAILY = BASIC ÷ ${v.days}</div><h1 style="margin:2px 0">${monthLabel(p)}</h1><div class="row" style="gap:6px">${chip(apprLabel(v), v.status === 'draft' ? 'warn' : 'ok')}</div></div>
    <div class="row"><a class="btn" href="#/payroll/${p}">Sheet overview</a><a class="btn" href="#/payroll/${p}/sheet">Master sheet</a></div></div>`;
  const prog = `<div class="card"><div class="bd"><div class="row between"><b>${all.completed + all.excluded} of ${all.total} staff completed</b><span class="sm muted">${all.draft} draft · ${all.pending} pending</span></div><div class="prog" style="margin-top:8px"><i style="width:${all.total ? Math.round((all.completed + all.excluded) * 100 / all.total) : 0}%"></i></div>
    ${ed && all.total && all.completed + all.excluded < all.total ? `<div class="row" style="margin-top:12px"><button class="btn primary big" data-a="sw-next" data-scope="all">▶ Next pending staff</button></div>` : all.total && all.completed + all.excluded === all.total ? `<div class="alert ok" style="margin:12px 0 0">Everyone is completed. ${v.status === 'draft' && v.can.prepare ? `<button class="btn sm dark" data-a="pay-submit" data-p="${p}">Send for approval</button>` : ''} <a class="btn sm" href="#/payroll/${p}/sheet">Master salary sheet</a></div>` : ''}</div></div>`;
  const cards = `<div class="grid g4 keep sw-depts">${deps.map(d => { const c = swCounts(v.lines.filter(l => l.dept_id === d.id)); const pct = c.total ? Math.round((c.completed + c.excluded) * 100 / c.total) : 0;
    return `<a class="kpi sw-dept" href="#/payroll/${p}/wizard?dept=${d.id}"><div class="l">${esc(d.name)}${d.name_hi ? ' · ' + esc(d.name_hi) : ''}</div><div class="v">${c.completed + c.excluded} / ${c.total}</div><div class="s">${c.total ? 'Completed' : 'No staff'}${c.draft ? ' · ' + c.draft + ' draft' : ''}</div><div class="prog" style="margin-top:8px"><i style="width:${pct}%"></i></div></a>`; }).join('')}</div>`;
  let none = '';
  if (!v.lines.length) none = card('', (v.not_on_sheet || []).length ? empty('Nobody is on the ' + monthLabel(p) + ' sheet yet', '<span class="sm">See the list below — usually the joining date or department needs correcting.</span>')
    : empty('No staff yet', '<span class="sm">Add your staff first: name, department, designation, basic salary and joining date.</span>', can('staff.manage') ? '<a class="btn primary" href="#/staff">＋ Add staff</a>' : ''));
  return head + prog + none + (v.lines.length ? cards + swNotOnSheet(v) : swNotOnSheet(v) + cards);
}
function swDept() {
  const v = SW.v, p = v.period, d = (v.departments || DEPTS()).find(x => x.id === SW.dept) || { name: '?' }, ls = v.lines.filter(l => l.dept_id === SW.dept), c = swCounts(ls), ed = editableP(v);
  const tabs = `<div class="tabs no-print">${(v.departments || DEPTS()).map(x => { const k = swCounts(v.lines.filter(l => l.dept_id === x.id)); return `<a href="#/payroll/${p}/wizard?dept=${x.id}" class="${x.id === SW.dept ? 'on' : ''}">${esc(x.name)} ${k.completed + k.excluded}/${k.total}</a>`; }).join('')}<a href="#/payroll/${p}/wizard">All departments</a></div>`;
  let rows = '', last = null;
  for (const l of ls) { if (l.designation !== last) { rows += `<div class="li sw-grp"><b class="xs">${esc((l.designation || '—').toUpperCase())}</b></div>`; last = l.designation; }
    const [lab, , btn] = ENTRY[l.entry_status] || ['', '', 'Open'];
    rows += `<div class="li"><span class="av">${esc(initials(l.name))}</span><div class="grow"><b>${esc(l.name)}</b><span class="sm muted">${esc(l.code || '')} · ${esc(l.designation || '')} · Basic ${R(l.monthly)}${l.eligible_days < v.days ? ' · ' + l.eligible_days + ' of ' + v.days + ' days' : ''}${l.saved_at ? ' · saved ' + TS(l.saved_at) : ''}</span></div>
      <div style="text-align:right">${entryChip(l.entry_status)}${l.entry_status === 'completed' ? `<div class="num b" style="margin-top:4px">${R(l.net_payable)}</div>` : ''}</div>
      <button class="btn sm ${l.entry_status === 'completed' ? '' : 'primary'}" data-a="sw-open" data-e="${l.employee_id}">${ed ? btn : 'View'}</button></div>`; }
  const head = `<div class="row between" style="margin-bottom:8px"><div><div class="xs b" style="letter-spacing:1.6px;color:var(--orange-ink)">${monthLabel(p).toUpperCase()} · ${v.days} DAYS</div><h1 style="margin:2px 0">${esc(d.name)} salary</h1></div>
    <div class="row">${ed && c.completed + c.excluded < c.total ? '<button class="btn primary" data-a="sw-next">▶ Next pending staff</button>' : ''}<a class="btn" href="#/payroll/${p}/sheet/${SW.dept}">${esc(d.name)} sheet</a></div></div>
    <div class="card"><div class="bd"><div class="row between"><b>${c.completed + c.excluded} / ${c.total} completed</b><span class="sm muted">${c.draft} draft · ${c.pending} pending</span></div><div class="prog" style="margin-top:8px"><i style="width:${c.total ? Math.round((c.completed + c.excluded) * 100 / c.total) : 0}%"></i></div></div></div>`;
  return tabs + head + card('', ls.length ? `<div class="list">${rows}</div>` : empty('No staff in ' + esc(d.name), can('staff.manage') ? '<span class="sm">Add staff with this department.</span>' : '', can('staff.manage') ? '<a class="btn primary" href="#/staff">＋ Add staff</a>' : '')) + swNotOnSheet(v);
}
F.A['sw-join'] = b => { modal({ title: 'Correct joining date', sub: esc(b.dataset.n), body: `<form data-form="swjoin" data-id="${b.dataset.id}" novalidate>${field('Real first working day', 'joined_on', { type: 'date', value: b.dataset.d, req: true, attrs: `max="${today()}"` })}<p class="xs muted">The person then appears on every salary month from this date. Recorded in the audit history.</p></form>`, foot: '<button class="btn" data-close>Cancel</button><button class="btn primary" data-submit data-a="modal-submit">Save</button>' }); };
F.A['sw-join-all'] = () => { const n = (SW.v.not_on_sheet || []).filter(x => x.fix === 'joining');
  modal({ title: 'Correct joining dates', sub: n.length + ' staff', wide: true, body: `<form data-form="swjoinall" novalidate>${field('Joining date for everyone ticked', 'joined_on', { type: 'date', value: SW.p + '-01', req: true, attrs: `max="${today()}"` })}<div class="stack" style="margin-top:10px">${n.map(x => `<label class="check on"><input type="checkbox" data-id="${x.id}" checked><span>${esc(x.name)} <span class="xs muted">${esc(x.code || '')} · now ${D(x.joined_on)}</span></span></label>`).join('')}</div><p class="xs muted">Untick anyone who really joined later. Each change is recorded in the audit history.</p></form>`,
    foot: '<button class="btn" data-close>Cancel</button><button class="btn primary" data-submit data-a="modal-submit">Save dates</button>' }); };
F.forms.swjoinall = async (f, d) => { const ids = $$('[data-id]', f).filter(c => c.checked).map(c => Number(c.dataset.id)); let n = 0; for (const id of ids) { await api('staff-joining-fix', {}, { id, joined_on: d.joined_on }); n++; } f.closest('.modal')._close(); toast(n + ' joining date(s) corrected.'); render(); };
F.forms.swjoin = async (f, d) => { await api('staff-joining-fix', {}, { id: Number(f.dataset.id), joined_on: d.joined_on }); f.closest('.modal')._close(); toast('Joining date corrected.'); render(); };
F.A['sw-open'] = b => swOpen(Number(b.dataset.e));
F.A['sw-next'] = b => { const n = swNextPending(b.dataset.scope === 'all' ? null : SW.dept, null); if (n) { if (SW.dept !== n.dept_id) { go(`#/payroll/${SW.p}/wizard/${n.employee_id}`); return; } swOpen(n.employee_id); } else toast('Everyone here is completed.'); };
function swOrder(dept) { const deps = (SW.v.departments || DEPTS()).map(d => d.id); return SW.v.lines.filter(l => l.dept_id && (!dept || l.dept_id === dept)).sort((a, b) => deps.indexOf(a.dept_id) - deps.indexOf(b.dept_id)); }
function swNextPending(dept, after, sameOnly) { const o = swOrder(dept), i = after ? o.findIndex(l => l.employee_id === after) : -1;
  return o.slice(i + 1).concat(o.slice(0, i + 1)).find(l => !['completed', 'excluded'].includes(l.entry_status) && l.employee_id !== after) || (dept && !sameOnly ? swNextPending(null, after) : null); }

/* ---------- Smart Salary Popup ----------
   V23 save engine (fixes audit P0-02). Every edit bumps SW.rev and copies the form into SW.vals (a snapshot that survives the
   dialog closing). Writes go through ONE serial queue; a draft write sends the snapshot of the moment it runs and, on success,
   acknowledges exactly that revision (SW.ack). Newer edits typed while a request was in flight stay dirty and are sent next.
   Final actions (Save & Next, Skip, Add later, Previous, Close, Next) wait for the queue, flush, and only move on after the
   server confirmed. On a network error / 422 / 409 the values stay on screen, the status says why, and nothing navigates.
   A 409 is never silently "reloaded": if the server row equals what we sent (our own write landed, the reply was lost) we adopt
   its version; otherwise the person chooses Keep mine / Use the saved version. */
const SWQ = { chain: Promise.resolve() };
const SW_KEYS = ['present', 'half_days', 'paid_leave', 'rest_days', 'advance_recovery'];
function swQ(fn) { const r = SWQ.chain.then(fn, fn); SWQ.chain = r.catch(() => {}); return r; }
const swDirty = () => SW.rev > SW.ack;
async function swOpen(emp) {
  if (!(await swLeave('open'))) return; swForceClose(); while (modalStack.length) closeTop();
  let L; try { L = await api('payroll-line', { period: SW.p, employee_id: emp }); } catch (e) { fail(e); return; }
  SW.emp = emp; SW.line = L; SW.savedAt = L.saved_at; SW.failed = false; SW.err = ''; SW.conflict = null; SW.final = false; SW.mode = L.entry_status === 'completed' || !editableP(SW.v) ? 'review' : 'edit';
  SW.sel = L.penalty_ids ?? L.context.penalty_items.map(x => x.id);
  SW.rev = 0; SW.ack = 0; SW.sentVals = null; SW.preview = null;
  const m = modal({ title: esc(L.name), sub: `${esc(L.code || '')} · ${esc(DEPTS().find(d => d.id === L.dept_id)?.name || '')} › ${esc(L.designation || '')}${L.joining_unverified ? ' · <span class="chip warn">Joining date not verified</span>' : ''}`, wide: true, body: '<div id="swb"></div>', foot: '<div id="swf" class="sw-foot"></div>',
    beforeClose: () => swLeave('close'), onClose: () => { if (SW.m === m) SW.m = null; F.leaveGuard = null; swRefreshList(); } });
  SW.m = m; m.classList.add('sw-modal'); swPaint(); SW.vals = swReadForm(); if (SW.mode === 'edit') swPreview();
  F.leaveGuard = () => swLeave('navigate');
}
function swForceClose() { clearTimeout(SW.saveT); if (SW.m) { const m = SW.m; SW.m = null; m._silent = true; if (m.isConnected) m._close(); } F.leaveGuard = null; }
async function swClose() { if (!(await swLeave('close'))) return false; swForceClose(); return true; }
function swPaint() {
  const L = SW.line, c = L.context, v = SW.v, ed = SW.mode === 'edit', elig = L.eligible_days, val = k => L[k] === null || L[k] === undefined ? '' : String(L[k]);
  const info = `<div class="sw-info"><div><span>Month</span><b>${monthLabel(SW.p)} · ${c.days} days</b></div><div><span>Basic / month</span><b>${R(L.monthly)}</b></div>${c.basis === 'daily' ? `<div><span>Contract</span><b>Daily wage ${R(L.monthly)}/day (not monthly)</b></div>` : `<div><span>Daily salary (÷ ${c.days})</span><b>${R(c.daily_rate, true)}</b></div>`}
    ${elig < c.days ? `<div><span>Days to account</span><b>${elig} (joined / left in month)</b></div>` : ''}${c.advance_given ? `<div><span>Advance given (total)</span><b>${R(c.advance_given)}</b></div><div><span>Recovered before</span><b>${R(c.advance_recovered)}</b></div>` : ''}<div><span>Advance remaining</span><b>${R(c.advance_outstanding)}</b></div><div><span>OT already paid (not added)</span><b>${R(c.ot_paid)}</b></div>${c.paid ? `<div><span>Salary already paid</span><b>${R(c.paid)}</b></div>` : ''}${c.carried_forward ? `<div><span>Previous balance</span><b>${R(c.carried_forward)}</b></div>` : ''}</div>`;
  const num = (k, lbl, hi) => `<label class="f sw-n"><span>${lbl} <span class="hi">${hi}</span></span><span class="sw-step"><button type="button" class="iconbtn" data-a="sw-step" data-k="${k}" data-d="-1" aria-label="${lbl} minus" ${ed ? '' : 'disabled'}>−</button><input name="${k}" inputmode="decimal" value="${esc(val(k))}" ${ed ? '' : 'readonly'} autocomplete="off" data-a-input="sw-in" aria-label="${lbl}"><button type="button" class="iconbtn" data-a="sw-step" data-k="${k}" data-d="1" aria-label="${lbl} plus" ${ed ? '' : 'disabled'}>＋</button></span></label>`;
  const pens = c.penalty_items.length ? c.penalty_items.map(x => `<label class="check ${SW.sel.includes(x.id) ? 'on' : ''}" style="padding:8px 10px"><input type="checkbox" data-pen="${x.id}" ${SW.sel.includes(x.id) ? 'checked' : ''} ${ed ? '' : 'disabled'} data-a-change="sw-pen"><span><b>${R(x.amount)}</b> · ${D(x.date)} · ${esc(x.reason || '')}</span></label>`).join('') : '<p class="sm muted" style="margin:0">No approved penalty for this month.</p>';
  const penTot = c.penalty_items.filter(x => SW.sel.includes(x.id)).reduce((a, x) => a + x.amount, 0);
  $('#swb').innerHTML = info + `<form id="swform" novalidate><div class="sw-grid">${num('present', 'Present days', 'उपस्थित')}${num('half_days', 'Half days (optional)', 'आधा दिन')}${num('paid_leave', 'Paid leave (optional)', 'सवेतन छुट्टी')}</div>
    <div class="sw-days" id="swdays"></div>${ed ? `<div class="row" style="gap:6px;margin:4px 0 10px"><button type="button" class="btn sm" data-a="sw-full">Full month present (${elig})</button></div>` : ''}
    <details ${Number(L.rest_days) ? 'open' : ''} style="margin-bottom:10px"><summary class="sm b" style="cursor:pointer;min-height:36px">Weekly rest / holiday days (${c.rest_paid ? 'paid' : 'unpaid'} — set in Settings)</summary>${num('rest_days', 'Rest / holiday days', 'साप्ताहिक छुट्टी')}</details>
    <div class="sw-grid">${moneyField('Advance recovery · एडवांस', 'advance_recovery', L.entry_status === 'pending' && L.advance_recovery === 0 ? null : L.advance_recovery, { help: `Outstanding ${R(c.advance_outstanding)}${c.installment ? ' · planned ' + R(c.installment) + '/month' : ''}. Blank = suggested.`, attrs: `${ed ? '' : 'readonly'} data-a-input="sw-in"` })}
    <div class="f"><span>Penalty · जुर्माना <b class="bad">${R(penTot)}</b></span><div class="stack" style="gap:6px">${pens}</div><span class="h">Only approved penalties. Untick to keep one for a later month. New penalties are added from Staff entries.</span></div></div></form>
    <div id="swres" class="sw-res"></div>${c.items.length ? `<details class="sm"><summary style="cursor:pointer;min-height:36px">Ledger entries this month (${c.items.length})</summary>${c.items.map(x => `<div class="li sm"><span class="grow">${D(x.date)} · ${esc(x.kind_label)} · ${esc(x.reason || '')}</span>${statusChip(x.status)}<b class="num">${R(x.amount)}</b></div>`).join('')}</details>` : ''}`;
  SW.vals = swReadForm(); swDays(); swResult(); swFoot();
}
function swReadForm() { const f = $('#swform'); if (!f) return SW.vals || {}; const g = k => f[k] ? f[k].value.trim() : ''; const o = {}; SW_KEYS.forEach(k => o[k] = g(k)); return o; }
/* swVals: ALWAYS the snapshot (never the DOM) so a closing dialog can still send what was typed. */
function swVals() { return { ...(SW.vals || {}) }; }
function swDays() { const el = $('#swdays'); if (!el) return; const x = swVals(), elig = SW.line.eligible_days; const n = k => Number(x[k] || 0); const filled = x.present !== '';
  const sum = n('present') + n('half_days') + n('paid_leave') + n('rest_days'); const absent = Math.round((elig - sum) * 100) / 100;
  el.className = 'sw-days ' + (!filled ? '' : absent < 0 ? 'bad' : 'ok');
  el.innerHTML = !filled ? `… Enter the present days. Absent days are worked out from the ${elig} days.`
    : absent < 0 ? `⚠ <b>${sum}</b> days entered — ${-absent} more than the <b>${elig}</b> days of this month`
    : `✓ Absent days (calculated) अनुपस्थित: <b>${absent}</b> <span class="xs">= ${elig} − ${sum}</span>`; }
function swResult() { const el = $('#swres'); if (!el) return; const c = SW.preview || SW.line.calc;
  if (!c) { el.innerHTML = '<p class="sm muted" style="margin:0">Salary appears here as soon as the present days are entered.</p>'; return; }
  el.innerHTML = `<div class="sum"><div><span>Daily salary</span><span>${R(c.daily_rate ?? Math.round(c.monthly / c.divisor), true)}</span></div><div><span>Paid days (present + paid leave + ½ half${c.rest_paid && c.rest_days ? ' + rest' : ''})</span><span>${c.payable_days}</span></div><div><span>Earned salary</span><span>${R(c.gross_base, true)}</span></div>
    ${c.additions ? `<div><span>+ Approved additions</span><span>${R(c.additions, true)}</span></div>` : ''}<div><span>− Advance recovery</span><span>${R(c.advance_recovery, true)}</span></div><div><span>− Penalty</span><span>${R(c.penalties, true)}</span></div>${c.other_deductions ? `<div><span>− Approved deductions</span><span>${R(c.other_deductions, true)}</span></div>` : ''}
    <div class="big"><span>Net salary</span><span>${R(c.net_payable, true)}</span></div>${c.paid ? `<div><span>Already paid</span><span>${R(c.paid, true)}</span></div>` : ''}${c.carried_forward ? `<div><span>+ Previous balance</span><span>${R(c.carried_forward, true)}</span></div>` : ''}<div><span>Salary due</span><span class="b">${R(c.balance, true)}</span></div>
    ${c.ot_paid_info ? `<div class="xs muted"><span>Overtime ${R(c.ot_paid_info)} was already paid — not added again.</span><span></span></div>` : ''}</div>`; }
function swStatusHtml() {
  if (SW.conflict) return '<span class="bad b">⚠ Changed on another device — choose below</span>';
  if (SW.saving) return '<span class="saving">Saving…</span>';
  if (SW.failed) return `<button class="btn sm danger" data-a="sw-retry">Save failed — Retry</button>${SW.err ? ` <span class="xs bad">${esc(SW.err)}</span>` : ''}`;
  if (swDirty()) return '<span class="xs muted">Unsaved changes…</span>';
  return SW.savedAt ? `<span class="saved">Saved ${TS(SW.savedAt)}</span>` : '<span class="xs muted">Not saved yet</span>'; }
function swFoot() { const el = $('#swf'); if (!el) return; const L = SW.line, ed = SW.mode === 'edit', o = swOrder(SW.dept), i = o.findIndex(l => l.employee_id === SW.emp), dis = SW.final ? 'disabled' : '';
  const conf = SW.conflict ? `<div class="alert warn" role="alert" style="margin:0 0 8px"><b>This salary row was saved on another device.</b><div class="sm">${SW.conflict.diff.map(d => `${esc(d.label)}: yours <b>${esc(d.mine || '—')}</b> · saved <b>${esc(d.theirs || '—')}</b>`).join('<br>') || 'Saved values are the same as yours.'}</div>
    <div class="row" style="margin-top:6px"><button class="btn sm primary" data-a="sw-keep-mine">Keep mine (save over it)</button><button class="btn sm" data-a="sw-use-theirs">Use the saved version</button></div></div>` : '';
  el.innerHTML = conf + `<div class="sw-status" role="status" aria-live="polite">${swStatusHtml()}${L.adjust_note ? ` <span class="xs muted">${esc(L.adjust_note)}</span>` : ''}</div><div class="sw-btns">
    ${i > 0 ? `<button class="btn" data-a="sw-prev" ${dis}>‹ Previous</button>` : ''}${ed ? `<button class="btn" data-a="sw-skip" ${dis}>Skip for now</button><button class="btn" data-a="sw-later" ${dis}>Add later</button><button class="btn" data-a="sw-draft" ${dis}>Save draft</button><button class="btn primary" data-a="sw-done" ${dis}>Save & Next ›</button>`
      : `${editableP(SW.v) ? '<button class="btn" data-a="sw-edit">✎ Edit</button>' : ''}<button class="btn primary" data-a="sw-next2" ${dis}>Next pending ›</button>`}</div>`; }
function swTouched() { SW.vals = swReadForm(); SW.rev++; swDays(); swFoot(); swQueue(); }
F.A['sw-in'] = () => { if (SW.final || SW.mode !== 'edit') return; swTouched(); };
F.A['sw-step'] = b => { if (SW.final) return; const f = $('#swform'), el = f[b.dataset.k]; const v = Math.max(0, Math.round((Number(el.value || 0) + Number(b.dataset.d)) * 2) / 2); el.value = String(v); swTouched(); };
F.A['sw-full'] = () => { if (SW.final) return; const f = $('#swform'); f.present.value = SW.line.eligible_days - Number(f.rest_days?.value || 0); f.half_days.value = ''; f.paid_leave.value = ''; swTouched(); };
F.A['sw-pen'] = el => { if (SW.final) { el.checked = !el.checked; return; } const id = Number(el.dataset.pen); SW.sel = el.checked ? [...new Set([...SW.sel, id])] : SW.sel.filter(x => x !== id); el.closest('.check').classList.toggle('on', el.checked); const c = SW.line.context; const t = c.penalty_items.filter(x => SW.sel.includes(x.id)).reduce((a, x) => a + x.amount, 0); const lab = el.closest('.f').querySelector('span b.bad'); if (lab) lab.textContent = R(t); swTouched(); };
F.A['sw-edit'] = () => { SW.mode = 'edit'; swPaint(); SW.vals = swReadForm(); swPreview(); };
function swQueue() { clearTimeout(SW.saveT); if (SW.conflict) return; SW.saveT = setTimeout(() => swQ(() => swWrite('draft')).catch(() => {}), 800); }
function swBodyFrom(vals, action) { return { period: SW.p, employee_id: SW.emp, action, version: SW.line.version, ...vals, penalty_ids: SW.sel }; }
const SW_LABELS = { present: 'Present', half_days: 'Half days', paid_leave: 'Paid leave', rest_days: 'Rest / holiday', advance_recovery: 'Advance recovery' };
function swRemoteVals(r) { const n = v => v === null || v === undefined ? '' : String(Number(v)); return { present: n(r.present), half_days: Number(r.half_days) ? n(r.half_days) : '', paid_leave: Number(r.paid_leave) ? n(r.paid_leave) : '', rest_days: Number(r.rest_days) ? n(r.rest_days) : '', advance_recovery: r.entry_status === 'pending' ? '' : rupeesStr(r.advance_recovery), pen: JSON.stringify([...(r.penalty_ids || [])].sort()) }; }
function swNorm(v) { const n = x => x === '' || x === null || x === undefined ? '' : String(Number(String(x).replace(/[,₹\s]/g, '')) || 0); return { present: v.present === '' ? '' : n(v.present), half_days: Number(v.half_days) ? n(v.half_days) : '', paid_leave: Number(v.paid_leave) ? n(v.paid_leave) : '', rest_days: Number(v.rest_days) ? n(v.rest_days) : '', advance_recovery: v.advance_recovery === '' ? '' : rupeesStr(toPaise(v.advance_recovery)) }; }
function swSame(mine, remote, sel) { const a = swNorm(mine), b = swRemoteVals(remote); return SW_KEYS.every(k => k === 'advance_recovery' && a[k] === '' ? true : a[k] === b[k]) && JSON.stringify([...sel].sort()) === b.pen; }
/** One server write (runs inside the serial queue). action = draft | save | skip | later. Returns the reply, or null when there was nothing to save. Throws on failure. */
async function swWrite(action) {
  if (!SW.line || SW.conflict) { if (SW.conflict) throw Object.assign(new Error('Resolve the conflict first.'), { status: 409 }); return null; }
  if (action === 'draft' && !swDirty()) return null;
  const sentRev = SW.rev, vals = swVals(), sel = [...SW.sel], emp = SW.emp;
  SW.saving = true; SW.failed = false; SW.err = ''; swFoot();
  try {
    const body = ['skip', 'later'].includes(action) ? { period: SW.p, employee_id: emp, action, version: SW.line.version } : swBodyFrom(vals, action);
    const r = await api('payroll-line', {}, body);
    if (SW.emp !== emp) return r; // dialog moved on (forced navigation) — the write itself is done
    SW.line = { ...SW.line, ...r.line, context: SW.line.context, calc: r.calc ?? SW.line.calc };
    SW.savedAt = r.saved_at || r.line?.saved_at || SW.savedAt; SW.ack = Math.max(SW.ack, sentRev); SW.sentVals = vals;
    if (r.calc) SW.preview = r.calc; swResult(); swUpdateList(r.line); return r;
  } catch (e) {
    if (SW.emp !== emp) throw e;
    if (e.status === 409 && /another device/.test(e.message)) {
      let remote = null; try { remote = await api('payroll-line', { period: SW.p, employee_id: emp }); } catch (x) { }
      if (remote && ['draft', 'save'].includes(action) && swSame(vals, remote, sel) && (action !== 'save' || remote.entry_status === 'completed')) { // our own earlier write landed; the reply was lost
        SW.line = { ...SW.line, version: remote.version, state: remote.state, entry_status: remote.entry_status, saved_at: remote.saved_at }; SW.ack = Math.max(SW.ack, sentRev); SW.savedAt = remote.saved_at; swUpdateList(remote); return { line: remote, calc: remote.calc, saved_at: remote.saved_at, adopted: true }; }
      if (remote) { const rv = swRemoteVals(remote), mv = swNorm(vals); SW.conflict = { remote, diff: SW_KEYS.filter(k => (mv[k] || '') !== (rv[k] || '')).map(k => ({ label: SW_LABELS[k], mine: mv[k], theirs: rv[k] })) }; }
      else SW.conflict = { remote: null, diff: [] };
      SW.failed = true; SW.err = 'Changed on another device'; throw e; }
    SW.failed = true; SW.err = e.status === 0 ? 'No internet — your entries are kept here' : e.status === 422 ? e.message : (e.message || 'Server error'); throw e;
  } finally { SW.saving = false; swFoot(); }
}
/** Save every edit that is not yet acknowledged. true = the server has everything shown on screen. */
async function swFlush() { clearTimeout(SW.saveT); for (let i = 0; i < 6 && swDirty(); i++) { try { await swQ(() => swWrite('draft')); } catch (e) { return false; } } return !swDirty(); }
/** Called before the dialog closes / another person opens / the page changes. Never leaves silently with unsaved edits. */
async function swLeave(why) {
  if (!SW.m || !SW.line) return true; if (SW.final && why !== 'final') return false;
  if (SW.mode !== 'edit' && !swDirty()) return true;
  if (await swFlush()) return true;
  if (SW.conflict) { toast('Choose “Keep mine” or “Use the saved version” first.', true); return false; }
  const stay = !(await ask('Changes are not saved', `${esc(SW.err || 'The server did not confirm the save.')}<br>Your entries for <b>${esc(SW.line.name)}</b> are still on screen. Stay and retry, or leave and lose these changes?`, { ok: 'Leave without saving', danger: true }));
  return !stay;
}
async function swPreview() { if (SW.mode !== 'edit') return; const x = swVals(); if (x.present === '') { SW.preview = null; swResult(); return; }
  try { const r = await api('payroll-line', {}, swBodyFrom(x, 'preview')); SW.preview = r.calc; } catch (e) { SW.preview = null; } swResult(); }
function swUpdateList(l) { if (!l || !SW.v) return; const i = SW.v.lines.findIndex(x => x.employee_id === l.employee_id); if (i >= 0) SW.v.lines[i] = { ...SW.v.lines[i], ...l }; }
function swRefreshList(note) { const c = $('#content'); if (c && SW.v && location.hash.includes('/wizard')) { c.innerHTML = (note || '') + (SW.dept ? swDept() : swHub()); labelTables(); } }
/** Final actions: lock the dialog, wait for the queue, write, and only move on after the server confirmed. */
async function swFinal(btn, fn) { if (SW.final) return; SW.final = true; clearTimeout(SW.saveT); $$('#swform input').forEach(i => i.readOnly = true); swFoot(); if (btn) busy(btn, true);
  try { return await fn(); } finally { SW.final = false; if (SW.m) { $$('#swform input').forEach(i => i.readOnly = SW.mode !== 'edit'); swFoot(); } } }
F.A['sw-retry'] = async () => { SW.failed = false; if (!(await swFlush()) && !SW.conflict) toast('Still not saved: ' + (SW.err || 'try again'), true); };
F.A['sw-draft'] = async b => { SW.rev++; const ok = await swFlush(); toast(ok ? 'Draft saved.' : 'Draft NOT saved: ' + (SW.err || 'try again'), !ok); };
F.A['sw-keep-mine'] = async () => { const c = SW.conflict; if (!c) return; if (c.remote) SW.line.version = c.remote.version; SW.conflict = null; SW.failed = false; SW.rev++; const ok = await swFlush(); toast(ok ? 'Your values were saved.' : 'Not saved: ' + (SW.err || 'try again'), !ok); };
F.A['sw-use-theirs'] = () => { SW.conflict = null; SW.rev = SW.ack; const e = SW.emp; swForceClose(); swOpen(e); };
F.A['sw-done'] = b => swFinal(b, async () => {
  if (!(await swFlush()) && SW.conflict) return; // drafts first (serial); a conflict must be resolved by the person
  let r; try { r = await swQ(() => swWrite('save')); } catch (e) { if (e.status !== 409) toast(e.status === 422 ? e.message : 'Not completed — ' + (SW.err || e.message) + '. Your entries are kept.', true); return; }
  if (!r) return; toast(SW.line.name + ' completed · net ' + R((r.calc || SW.line.calc)?.net_payable ?? SW.line.net_payable));
  swGoNext(true);
});
F.A['sw-skip'] = b => swMark('skip', b); F.A['sw-later'] = b => swMark('later', b);
function swMark(action, b) { return swFinal(b, async () => {
  if (!(await swFlush())) { toast('Not moved on: your changes are not saved yet (' + (SW.err || 'retry') + ').', true); return; }
  try { await swQ(() => swWrite(action)); } catch (e) { toast('Not moved on: ' + (SW.err || e.message), true); return; }
  toast(action === 'skip' ? 'Skipped — stays pending.' : 'Marked “add later”.'); swGoNext(false); }); }
F.A['sw-next2'] = b => swFinal(b, async () => { if (!(await swFlush())) return; swGoNext(false); });
F.A['sw-prev'] = b => swFinal(b, async () => { if (!(await swFlush())) { toast('Not moved: changes not saved (' + (SW.err || 'retry') + ').', true); return; } const o = swOrder(SW.dept), i = o.findIndex(l => l.employee_id === SW.emp); if (i > 0) { const e = o[i - 1].employee_id; swForceClose(); swOpen(e); } });
/** Next pending person in the SAME department (audit P1-01). When the department is finished, show its summary and offer the next department. */
function swGoNext(fromSave) {
  const n = swNextPending(SW.dept, SW.emp, true);
  if (n) { const e = n.employee_id; swForceClose(); swOpen(e); return; }
  swForceClose(); const d = (SW.v.departments || DEPTS()).find(x => x.id === SW.dept), ls = SW.v.lines.filter(l => l.dept_id === SW.dept && l.entry_status === 'completed');
  const nx = swNextPending(null, SW.emp); const nd = nx ? (SW.v.departments || DEPTS()).find(x => x.id === nx.dept_id) : null;
  const note = `<div class="alert ok" role="status"><b>${esc(d?.name || 'Department')} complete${fromSave ? '' : ' (others skipped / later)'}.</b> ${ls.length} staff · net ${R(ls.reduce((a, l) => a + (l.net_payable || 0), 0))}.
    ${nd ? `<a class="btn sm primary" href="#/payroll/${SW.p}/wizard?dept=${nd.id}">Continue with ${esc(nd.name)} ›</a>` : 'Everyone is completed — check the master salary sheet.'} <a class="btn sm" href="#/payroll/${SW.p}/sheet${SW.dept ? '/' + SW.dept : ''}">${esc(d?.name || '')} sheet</a></div>`;
  swRefreshList(note); window.scrollTo(0, 0); toast(nd ? (d?.name || 'Department') + ' done.' : 'Everyone is completed. Check the master salary sheet.');
}
document.addEventListener('input', e => { if (e.target.closest('#swform') && e.target.name !== 'advance_recovery') { clearTimeout(SW.prevT); SW.prevT = setTimeout(swPreview, 700); } });
window.addEventListener('beforeunload', e => { if (SW.m && (swDirty() || SW.saving)) { e.preventDefault(); e.returnValue = ''; } });
window.addEventListener('online', () => { if (SW.m && (SW.failed || swDirty()) && !SW.conflict) { SW.failed = false; swFlush(); } });
