/* Finance 360 — one-time Active Employee Master import (Owner only). Upload → read-only preview → decisions → apply →
   Salary Wizard check → rollback. Nothing is written to staff records until "Apply import". */
'use strict';
route(/^\/staff-import$/, 'staffimport', 'staff.manage');
const EI_ACT = { create: ['Create', 'info'], update: ['Update', 'warn'], unchanged: ['Unchanged', 'ok'], inactive: ['Inactive', ''], review: ['Needs review', 'bad'] };
const eiRs = p => p == null ? '—' : '₹' + Math.round(p / 100).toLocaleString('en-IN');
F.pages.staffimport = async (_, q, tk) => {
  title('Staff import (one-time)');
  if (F.me.user.role !== 'owner') return card('', empty('Owner only', '<span class="sm">The employee master import can change many staff records, so only the Owner can run it.</span>'));
  if (!q.batch) { const r = await api('emp-import'); if (stale(tk)) return; return eiStart(r); }
  const r = await api('emp-import', { batch: q.batch }); if (stale(tk)) return; F.ei = r; return eiPreview(r, q.f || (r.counts.review ? 'review' : 'all'));
};
function eiStart(r) {
  const i = r.inspect;
  return `<div class="alert" role="status"><b>One-time import of your active staff from the September 2026 salary sheet.</b> The sheet decides who is active, their department (Management / UT / Kitchen / Waiter — Guard is in Waiter), designation and monthly salary. The HR employee CSV adds codes, mobiles and bank details; the bank letter is only used to double-check bank details. <b>Reading the files changes nothing.</b></div>`
    + card('One-click import file', `<form data-form="ei-up1" class="fg" novalidate>
      <p class="sm full" style="margin:0">One prepared Excel file (<b>KSDD_OneClick_Import</b>): sheet <b>Staff</b> (name + HR code, section, designation, monthly salary) and sheets <b>History 2026-06 … 2026-09</b> (old months, kept as a record only). Staff are matched by <b>HR code + name</b>, never by name alone. Reading it changes nothing; you still see the full preview before applying.</p>
      <label class="f full">One-click import file (.xlsx) *<input type="file" name="oneclick" accept=".xlsx" required></label>
      <div class="full"><button class="btn primary" type="submit" style="white-space:normal;max-width:100%">Read file and show preview</button></div></form>`)
    + card('Or: 1 · Choose the original files', `<form data-form="ei-up" class="fg" novalidate>
      <label class="f full">September salary workbook (.xlsx) *<input type="file" name="salary" accept=".xlsx" required></label>
      <label class="f full">HR employee list (.csv) *<input type="file" name="csv" accept=".csv,text/csv" required></label>
      <label class="f full">Bank transfer letter (.xlsx, optional)<input type="file" name="bank" accept=".xlsx"></label>
      <div class="full"><button class="btn primary" type="submit" style="white-space:normal;max-width:100%">Show preview (no changes)</button></div></form>`)
    + card('Database check (read-only)', `<div class="list">${[['Database schema ready', i.schema_ok, i.schema_ok ? 'v' + i.f3_schema : 'Missing: ' + i.missing.join(', ')], ['Staff records now', true, String(i.employees_total)], ['Bank-detail encryption key (pii_key)', i.pii_key, i.pii_key ? 'configured' : 'missing — bank details will be skipped'],
      ['Approved salary months', true, i.periods_closed.map(x => x.period + ' ' + x.status).join(', ') || 'none']].map(([l, ok, v]) => `<div class="li"><span class="av">${ok ? '✓' : '!'}</span><div class="grow"><b>${esc(l)}</b><span class="xs muted">${esc(v)}</span></div></div>`).join('')}</div>`)
    + (r.batches.length ? card('Earlier imports', table(['Batch', 'Status', 'Read', 'Applied', ''], r.batches.map(b => `<tr><td><code>${esc(b.batch_id)}</code></td><td>${chip(b.status, b.status === 'applied' ? 'ok' : b.status === 'rolled_back' ? 'warn' : 'info')}</td><td class="xs">${TS(b.created_at)}</td><td class="xs">${b.applied_at ? TS(b.applied_at) : '—'}</td><td><a class="btn sm" href="#/staff-import?batch=${esc(b.batch_id)}">Open</a></td></tr>`))) : '');
}
F.forms['ei-up'] = async f => {
  const read = async inp => { const fl = inp.files[0]; if (!fl) return null; if (fl.size > 1400000) throw new Error(fl.name + ' is larger than 1.4 MB.'); const d = await new Promise((ok, no) => { const r = new FileReader(); r.onload = () => ok(r.result); r.onerror = no; r.readAsDataURL(fl); }); return { name: fl.name, data: String(d).split(',')[1] }; };
  const body = { salary: await read(f.salary), csv: await read(f.csv), bank: await read(f.bank) }; if (!body.salary || !body.csv) return toast('Choose the salary workbook and the employee CSV.');
  const r = await api('emp-import-upload', {}, body); if (r.existing) toast('These exact files were read before — opening that preview.'); go('#/staff-import?batch=' + r.batch);
};
F.forms['ei-up1'] = async f => {
  const fl = f.oneclick.files[0]; if (!fl) return toast('Choose the one-click import file.'); if (fl.size > 1400000) return toast(fl.name + ' is larger than 1.4 MB.');
  const d = await new Promise((ok, no) => { const r = new FileReader(); r.onload = () => ok(r.result); r.onerror = no; r.readAsDataURL(fl); });
  const r = await api('emp-import-upload', {}, { oneclick: { name: fl.name, data: String(d).split(',')[1] } }); if (r.existing) toast('This exact file was read before — opening that preview.'); go('#/staff-import?batch=' + r.batch);
};
function eiHistCard(r, applied) {
  if (!r.history || !r.history.length) return '';
  return card('Old months — record only', `<p class="sm" style="margin:0 0 8px">These sheets are copied into <b>Salary history</b> for viewing. <b>No salary, advance, payment, carry-forward or cash-book entry is made from them.</b> Rows are linked to a staff member only by HR code.</p>`
    + table(['Month', { t: 'Rows', c: 'n' }, { t: 'Linked by code', c: 'n' }, { t: 'Monthly salary', c: 'n' }, { t: 'Total salary', c: 'n' }, { t: 'Advance', c: 'n' }, { t: 'Paid', c: 'n' }, 'Already kept'], r.history.map(h => `<tr><td>${monthLabel(h.period)}</td><td class="n">${h.rows}</td><td class="n">${h.linked}</td><td class="n">${eiRs(h.monthly)}</td><td class="n">${eiRs(h.total)}</td><td class="n">${eiRs(h.advance)}</td><td class="n">${eiRs(h.paid)}</td><td class="xs">${h.exists ? h.exists + ' rows (this copy replaces them; undo brings them back)' : '—'}</td></tr>`))
    + (r.history.some(h => h.not_linked.length) ? `<details style="margin-top:8px"><summary class="sm b">Rows kept with the name only (no matching HR code)</summary><p class="xs muted">${r.history.filter(h => h.not_linked.length).map(h => '<b>' + monthLabel(h.period) + ':</b> ' + h.not_linked.map(esc).join(', ')).join('<br>')}</p></details>` : '')
    + (applied ? `<p class="sm" style="margin:8px 0 0"><a class="btn sm" href="#/salary-history">Open Salary history</a></p>` : ''));
}
function eiPreview(r, flt) {
  const c = r.counts, b = r.batch, ch = r.check, applied = b.status === 'applied';
  const chips = Object.entries(EI_ACT).map(([k, [l, cl]]) => `<a href="#/staff-import?batch=${b.id}&f=${k}" class="chip ${cl} ${flt === k ? 'dk' : ''}" style="text-decoration:none;min-height:34px">${l} ${c[k] ?? 0}</a>`).join(' ') + ` <a href="#/staff-import?batch=${b.id}&f=all" class="chip ${flt === 'all' ? 'dk' : ''}" style="text-decoration:none;min-height:34px">All</a>`;
  let h = `<div class="row between" style="margin-bottom:8px"><div><b>Batch <code>${esc(b.id)}</code></b> ${chip(b.status.replace('_', ' '), applied ? 'ok' : b.status === 'rolled_back' ? 'warn' : 'info')}<div class="xs muted">${b.files.map(f => esc(f.kind + ': ' + f.name + ' · sha ' + f.sha256.slice(0, 10))).join(' · ')}</div></div><div class="row"><button class="btn sm" data-a="ei-csv">Download preview (CSV)</button><a class="btn sm ghost" href="#/staff-import">Other files</a></div></div>`;
  h += card(ch.one_click ? 'Staff sheet of the one-click file' : 'Checks on the September sheet', `<p class="sm" style="margin:0 0 8px">${ch.one_click ? `Staff rows: <b>${ch.rows}</b> · monthly salary total <b>₹${ch.row_salary.toLocaleString('en-IN')}</b>` : `Headline "Total Employee": <b>${ch.headline_count}</b> · rows actually on the sheet: <b>${ch.rows}</b>${ch.headline_count !== ch.rows ? ` <span class="bad b">(difference explained below)</span>` : ''}`}</p>`
    + table(['Section', { t: 'Rows', c: 'n' }, { t: 'Sheet count', c: 'n' }, { t: 'Salary of rows', c: 'n' }, { t: 'Sheet total', c: 'n' }], Object.entries(ch.sections).map(([k, s]) => `<tr class="${s.rows !== s.sheet_count ? 'warn' : ''}"><td>${esc(k)}</td><td class="n">${s.rows}</td><td class="n">${s.sheet_count}</td><td class="n">₹${s.salary.toLocaleString('en-IN')}</td><td class="n">₹${(s.sheet_salary || 0).toLocaleString('en-IN')}</td></tr>`))
    + (ch.duplicate_serials.length ? `<p class="sm" style="margin:8px 0 0">Same serial number used twice: <b>${ch.duplicate_serials.map(esc).join('; ')}</b>. The sheet's count uses the last serial number, so it shows one less; the salary totals include every row.</p>` : ''));
  h += eiHistCard(r, applied);
  h += `<div class="row wrap" style="gap:6px;margin:12px 0">${chips}</div>`;
  const rows = r.rows.filter(x => flt === 'all' || x.action === flt); const ina = r.inactive.filter(x => flt === 'all' || x.action === flt || (flt === 'unchanged' && x.action === 'unchanged'));
  h += rows.map(x => eiRow(x, r, applied)).join('') + (ina.length ? card('Existing staff not on the September sheet', `<div class="list">${ina.map(x => `<div class="li"><div class="grow"><b>#${x.employee_id} ${esc(x.name)}</b><span class="xs muted">${esc(x.code || '')} · ${esc(x.dept)} · joined ${esc(x.joined_on || '—')}</span><span class="xs ${x.action === 'review' ? 'bad' : 'muted'}">${esc(x.reason)}</span></div>${chip(EI_ACT[x.action][0], EI_ACT[x.action][1])}${!applied && x.action !== 'inactive' || (!applied && x.action === 'inactive') ? `<div class="act"><button class="btn sm ghost" data-a="ei-dec" data-k="inactive:${x.key}" data-v="keep">Keep active</button><button class="btn sm ghost" data-a="ei-dec" data-k="inactive:${x.key}" data-v="inactive">Make inactive</button></div>` : ''}</div>`).join('')}</div><p class="xs muted" style="margin:8px 0 0">Inactive = left off salary sheets from now on. No leaving date is set; all history stays. You can make a person active again from Staff at any time.</p>`) : '');
  if (!rows.length && !ina.length) h += card('', empty('Nothing in this list'));
  if (r.csv_only.length && flt === 'all') h += card(`CSV people not on the September sheet (${r.csv_only.length}) — not created`, `<p class="xs muted" style="margin:0">${r.csv_only.map(x => esc('CSV ' + x.code + ' ' + x.name + ' · ' + x.dept + ' · ' + x.status)).join('<br>')}</p>`);
  if (r.bank_only.length && flt === 'all') h += card(`Bank-letter rows that match nobody (${r.bank_only.length}) — ignored`, `<p class="xs muted" style="margin:0">${r.bank_only.map(x => esc(x.holder + ' · ' + x.account + ' · ' + x.desig)).join('<br>')}</p>`);
  // apply / result
  if (applied) { const w = r.wizard || {}; h += card('Imported', `<p class="sm">Applied ${TS(b.applied_at)}. ${b.summary ? `Created ${b.summary.create}, updated ${b.summary.update}, unchanged ${b.summary.unchanged}, made inactive ${b.summary.inactive}.${b.summary.history_rows ? ` Old-month rows kept as a record: ${b.summary.history_rows} (${b.summary.history_linked} linked by HR code).` : ''}` : ''}</p>
      ${b.before ? table(['Measure', { t: 'Before', c: 'n' }, { t: 'After', c: 'n' }], Object.keys(b.after).map(k => `<tr><td>${esc(k.replace(/_/g, ' '))}</td><td class="n">${b.before[k] ?? 0}</td><td class="n">${b.after[k]}</td></tr>`)) : ''}
      <p class="sm"><b>Salary Wizard (${esc(w.period || '')}):</b> ${w.ok} imported staff will appear in the right department.${(w.problems || []).length ? ` <span class="bad">Problems: ${w.problems.map(esc).join('; ')}</span>` : ' No problems.'} <a href="#/payroll/${esc(w.period || '')}/wizard">Open the Salary Wizard</a></p>
      <details><summary class="b">Undo this import</summary><form data-form="ei-rb" class="stack" novalidate>${field('Why are you undoing it?', 'reason', { req: true })}<p class="xs muted">Restores every changed staff record exactly as it was before, removes the staff this import created (or sets them inactive if salary entries already exist for them), and removes the added salary rates and links, and removes the old-month copies this import added (an earlier copy of those months comes back). Nothing else is touched.</p><button class="btn danger" type="submit">Undo import</button></form></details>`); }
  else h += card('3 · Apply', c.review ? `<p class="sm bad" style="margin:0">${c.review} item(s) still need your decision (see “Needs review”). Nothing can be applied until every one is answered.</p>`
    : `<form data-form="ei-apply" class="stack" novalidate><p class="sm" style="margin:0">Ready: create <b>${c.create}</b>, update <b>${c.update}</b>, make inactive <b>${c.inactive}</b>, unchanged <b>${c.unchanged}</b>. New salary rates start <b>${esc(r.rate_from)}</b> (approved salary months are never changed). Salary entries, payments, advances, penalties and the cash book are not touched.</p>
      <label class="check"><input type="checkbox" name="backup_ok"><span>I have a backup of the database (needed only if no backup passphrase is set — then the app takes an encrypted backup itself first)</span></label>
      ${field('Type IMPORT to confirm', 'confirm', { req: true, attrs: 'autocomplete="off" style="text-transform:uppercase"' })}<button class="btn primary big" type="submit">Apply import</button></form>`);
  return h;
}
function eiRow(x, r, applied) {
  const cands = (x.candidates || []).map(cd => [cd.code, 'CSV ' + cd.code + ' — ' + (r.csv_choices[cd.code] || '') + ' (score ' + cd.score + ')']);
  const allCsv = Object.entries(r.csv_choices).map(([k, v]) => [k, 'CSV ' + k + ' — ' + v]);
  const ctl = applied ? '' : x.review.map(rv => { const k = rv.type + ':' + x.key;
    if (rv.type === 'source') return `<div class="ei-q"><b class="bad">${esc(rv.text)}</b><div class="row wrap" style="gap:6px;margin-top:6px"><select data-k="src:${x.key}" aria-label="Choose CSV person">${[...cands, ['', '──────'], ...allCsv].map(([v, l]) => `<option value="${esc(v)}" ${v === (x.csv?.code || '') ? 'selected' : ''} ${v === '' ? 'disabled' : ''}>${esc(l)}</option>`).join('')}<option value="new">Not in the CSV — create new</option></select><button class="btn sm primary" data-a="ei-dec-sel" data-k="src:${x.key}">Confirm</button></div></div>`;
    if (rv.type === 'status') return `<div class="ei-q"><b class="bad">${esc(rv.text)}</b><div class="row" style="margin-top:6px"><button class="btn sm primary" data-a="ei-dec" data-k="${k}" data-v="active">Yes, make active</button></div><p class="xs muted" style="margin:4px 0 0">If this person has since left, import them and then use Staff → Exit with the real date.</p></div>`;
    if (rv.type === 'existing') return `<div class="ei-q"><b class="bad">${esc(rv.text)}</b><div class="row wrap" style="gap:6px;margin-top:6px"><select data-k="emp:${x.key}" aria-label="Existing staff">${r.staff_choices.map(s => `<option value="${s.id}">${esc(s.label)}</option>`).join('')}<option value="new" selected>None of these — create new</option></select><button class="btn sm primary" data-a="ei-dec-sel" data-k="emp:${x.key}">Confirm</button></div></div>`;
    if (rv.type === 'joining') return `<div class="ei-q"><b class="bad">${esc(rv.text)}</b><div class="row wrap" style="gap:6px;margin-top:6px"><input type="date" data-k="joining:${x.key}" max="2026-09-30" aria-label="Real joining date"><button class="btn sm primary" data-a="ei-dec-sel" data-k="joining:${x.key}">Use this date</button><button class="btn sm" data-a="ei-dec" data-k="${k}" data-v="blank">Unknown — leave blank</button></div></div>`;
    if (rv.type === 'bank') return `<div class="ei-q"><b class="bad">${esc(rv.text)}</b><div class="row" style="gap:6px;margin-top:6px"><button class="btn sm" data-a="ei-dec" data-k="${k}" data-v="keep_csv">Use the CSV account</button><button class="btn sm" data-a="ei-dec" data-k="${k}" data-v="skip">Do not import bank details</button></div></div>`;
    return `<div class="ei-q bad">${esc(rv.text)}</div>`; }).join('');
  const offer = !applied && x.bank_offer && !x.bank ? `<div class="xs" style="margin-top:4px">Bank letter offers ${esc(x.bank_offer.account)} (holder “${esc(x.bank_offer.holder)}”) <button class="btn sm ghost" data-a="ei-dec" data-k="bank:${x.key}" data-v="use_letter">Use it</button></div>` : '';
  return `<section class="card ei-row"><div class="bd"><div class="row between" style="align-items:flex-start"><div class="grow"><b style="font-size:16px">${esc(x.sept_name)}</b> <span class="xs muted">${esc(x.section)} · S.No ${x.sno} · row ${x.sheet_row}${x.present != null ? ' · ' + x.present + ' days' : ''}</span>
      <div class="sm">${esc(x.dept)} · ${esc(x.desig)} · <b>${eiRs(x.monthly)}</b>/month</div>
      <div class="xs muted">${x.csv ? `CSV ${esc(x.csv.code)} ${esc(x.csv.name)}${x.csv.display ? ' (' + esc(x.csv.display) + ')' : ''} · ${esc(x.csv.mobile)} · ${esc(x.csv.status)}` : 'No CSV record'} · ${esc(x.source_tier)}: ${esc(x.source_reason)}</div>
      <div class="xs muted">${x.employee_id ? `Existing staff #${x.employee_id} (${esc(x.match_how)})` : 'New staff record'}${x.code ? ' · code ' + esc(x.code) : ''} · joined ${esc(x.joined_on || x.joined_note)}${x.bank ? ' · bank ' + esc(x.bank.bank + ' ' + x.bank.account) : ''}</div>
      ${x.changes.length ? `<div class="xs">Changes: ${x.changes.map(esc).join('; ')}</div>` : ''}${x.issues.length ? `<div class="xs warnt">${x.issues.map(esc).join('<br>')}</div>` : ''}${offer}</div>${chip(EI_ACT[x.action][0], EI_ACT[x.action][1])}</div>${ctl}</div></section>`;
}
const eiDecide = async (k, v) => { await api('emp-import-decide', {}, { batch: F.ei.batch.id, decisions: { [k]: v } }); const y = window.scrollY; await render(); window.scrollTo(0, y); toast('Saved.'); };
F.A['ei-dec'] = b => eiDecide(b.dataset.k, b.dataset.v);
F.A['ei-dec-sel'] = b => { const el = $(`[data-k="${b.dataset.k}"]:not(button)`); if (!el || !el.value) return toast('Choose first.'); return eiDecide(b.dataset.k, el.value); };
F.forms['ei-apply'] = async (f, d) => { if (String(d.confirm).toUpperCase() !== 'IMPORT') return toast('Type IMPORT to confirm.');
  if (!await ask('Apply the staff import?', 'Staff records will be created / updated / made inactive exactly as shown. You can undo it afterwards from this page.', { ok: 'Apply import' })) return;
  const r = await api('emp-import-apply', {}, { batch: F.ei.batch.id, confirm: 'IMPORT', backup_ok: d.backup_ok ? 1 : 0 }); toast(r.already ? r.note : 'Import applied.' + (r.backup ? ' Backup ' + r.backup + ' taken first.' : '')); render(); };
F.forms['ei-rb'] = async (f, d) => { if (!await ask('Undo the import?', 'Staff records go back to how they were before this import.', { ok: 'Undo', danger: true })) return;
  const r = await api('emp-import-rollback', {}, { batch: F.ei.batch.id, reason: d.reason }); toast('Import undone.' + (r.note ? ' ' + r.note : '')); render(); };
F.A['ei-csv'] = () => { const r = F.ei; const q = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
  const lines = [['Section', 'S.No', 'September name', 'Department', 'Designation', 'Monthly', 'CSV code', 'CSV name', 'Mobile (masked)', 'Match', 'Existing staff', 'Action', 'Changes', 'Needs decision', 'Notes'].map(q).join(',')]
    .concat(r.rows.map(x => [x.section, x.sno, x.sept_name, x.dept, x.desig, (x.monthly || 0) / 100, x.csv?.code, x.csv?.name, x.csv?.mobile, x.source_tier + ': ' + x.source_reason, x.employee_id, x.action, x.changes.join('; '), x.review.map(v => v.text).join('; '), x.issues.join('; ')].map(q).join(',')))
    .concat(r.inactive.map(x => ['(existing)', '', x.name, x.dept, '', '', '', '', '', '', x.employee_id, x.action, '', x.action === 'review' ? x.reason : '', x.reason].map(q).join(',')));
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv' })); a.download = 'staff-import-' + r.batch.id + '.csv'; a.click(); };

/* ============================== Salary history (old months, record only) ============================== */
route(/^\/salary-history$/, 'salhist', 'payroll.view');
F.pages.salhist = async (_, q, tk) => {
  title('Salary history (old sheets)'); const r = await api('salary-history', q.employee ? { employee: q.employee } : (q.period ? { period: q.period } : {})); if (stale(tk)) return;
  let h = `<div class="alert" role="note"><b>Record only.</b> ${esc(r.note)}</div>`;
  if (!r.months.length && !r.rows.length) return h + card('', empty('No old salary sheets kept yet', '<span class="sm">They are added by the one-click staff import (Staff → Import staff list).</span>'));
  h += `<div class="tabs">${r.months.map(m => `<a href="#/salary-history?period=${m.period}" class="${!r.employee && m.period === r.period ? 'on' : ''}">${monthLabel(m.period)}</a>`).join('')}${r.employee ? `<a class="on" href="#/salary-history?employee=${r.employee.id}">${esc(r.employee.name)}</a>` : ''}</div>`;
  const m = r.months.find(x => x.period === r.period);
  if (m && !r.employee) h += `<div class="grid g4" style="margin-bottom:12px">${kpi('Staff rows', String(m.n), m.linked + ' linked to staff by HR code')}${kpi('Monthly salary', R(m.monthly))}${kpi('Total salary', R(m.total), 'advance ' + R(m.advance) + ' · debt ' + R(m.debt))}${kpi('Paid', R(m.paid), 'balance ' + R(m.balance))}</div>`;
  const hd = ['Name', 'Section', 'Designation', { t: 'Monthly', c: 'n' }, { t: 'Present', c: 'n' }, { t: 'Total salary', c: 'n' }, { t: 'Advance', c: 'n' }, { t: 'Debt', c: 'n' }, { t: 'Net', c: 'n' }, { t: 'Paid', c: 'n' }, { t: 'Balance', c: 'n' }, 'Mode / status'];
  if (r.employee) hd.unshift('Month');
  h += card(r.employee ? 'Old salary sheets · ' + esc(r.employee.name) : monthLabel(r.period), r.rows.length ? table(hd, r.rows.map(x => `<tr>${r.employee ? `<td>${monthLabel(x.period)}</td>` : ''}<td>${x.employee_id ? `<a href="#/staff/${x.employee_id}">${esc(x.name)}</a>` : esc(x.name)}<div class="xs muted">${x.hr_code ? 'HR ' + esc(x.hr_code) : 'no code'}${x.employee_id ? '' : ' · not linked'}</div></td><td>${esc(x.section || '')}</td><td>${esc(x.designation || '')}</td><td class="n">${R(x.monthly)}</td><td class="n">${x.present ?? '—'}</td><td class="n">${R(x.total_salary)}</td><td class="n">${R(x.advance)}</td><td class="n">${R(x.debt)}</td><td class="n">${R(x.net_salary)}</td><td class="n">${R(x.paid)}</td><td class="n b">${R(x.balance)}</td><td class="xs">${esc([x.pay_mode, x.pay_status].filter(Boolean).join(' · '))}</td></tr>`)) : empty('No old sheet rows for this staff member'));
  return h;
};
