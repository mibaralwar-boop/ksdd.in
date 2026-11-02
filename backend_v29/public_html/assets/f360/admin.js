/* Finance 360 — Notifications & corrections, Users & permissions, Settings, Audit history, My account, More menu. */
'use strict';
route(/^\/notifications$/, 'notifications', 'notifications.view');
route(/^\/users$/, 'users', 'users.manage');
route(/^\/settings$/, 'settings', 'settings.manage');
route(/^\/audit$/, 'audit', 'audit.view');
route(/^\/me$/, 'me', null);
route(/^\/more$/, 'more', null);

F.pages.notifications = async (_, q, tk) => {
  title('Notifications'); const r = await api('notifications', { all: q.all ? 1 : '' }); if (stale(tk)) return;
  const link = n => { const [k, v] = String(n.ref || '').split(':'); return { closing: '#/closing/' + v, payroll: '#/payroll/' + v, stafftxn: '#/staff-entries?status=pending' }[k] || '#/'; };
  let h = `<div class="tabs"><a href="#/notifications" class="${q.all ? '' : 'on'}">Open</a><a href="#/notifications?all=1" class="${q.all ? 'on' : ''}">All</a></div>`;
  h += card('Reminders & approvals', r.items.length ? `<div class="list">${r.items.map(n => `<div class="li"><span class="av" aria-hidden="true">${{ 'closing-due': '☀', 'closing-review': '±', 'payroll-due': '◷', 'payroll-review': '✓', 'staff-approval': '✎', 'ot-unpaid': '◷', correction: '⚑', 'closing-overdue': '!' }[n.kind] || '•'}</span><div class="grow"><b>${esc(n.title)}</b><span class="xs muted">${esc(n.body || '')}</span><span class="xs muted">${TS(n.created_at)} ${n.status === 'done' ? '· done' : n.snoozed ? '· snoozed' : ''}</span></div><a class="btn sm primary" href="${link(n)}">Open</a>${n.status === 'open' && !n.snoozed ? ` <button class="btn sm" data-a="snooze" data-k="${esc(n.dedupe_key)}">Later</button>` : ''}</div>`).join('')}</div>` : empty('No open reminders ✓'));
  if (r.corrections.length) h += card('Correction requests', table(['Day', 'Requested by', 'Reason', 'Status', ''], r.corrections.map(c => `<tr><td><a href="#/closing/${c.business_date}">${D(c.business_date)}</a></td><td>${esc(c.requested_by_name || '')}<div class="xs muted">${TS(c.requested_at)}</div></td><td class="sm">${esc(c.reason)}${c.decision_note ? `<div class="xs muted">→ ${esc(c.decision_note)}</div>` : ''}</td><td>${statusChip(c.status === 'open' ? 'pending' : c.status)}</td><td>${c.status === 'open' ? `<button class="btn sm ok" data-a="corr" data-id="${c.id}" data-ok="1">Reopen day</button> <button class="btn sm" data-a="corr" data-id="${c.id}" data-ok="0">Reject</button>` : ''}</td></tr>`)));
  return h;
};
F.A.snooze = async b => { await api('snooze', {}, { key: b.dataset.k }); toast('Hidden for now.'); render(); };
F.A.corr = async b => { const ok = b.dataset.ok === '1'; const note = await ask(ok ? 'Reopen the day?' : 'Reject request?', ok ? 'The locked day will be reopened so it can be corrected. Postings of the old revision are cancelled and kept in history.' : 'The counter will see that the request was rejected.', { input: ok ? 'Admin note (optional)' : 'Reason', required: !ok, danger: ok, ok: ok ? 'Reopen' : 'Reject' }); if (note === false) return; await api('correction-decide', {}, { id: b.dataset.id, approve: ok, note }); await refreshMe(); toast('Done.'); render(); };

/* ---------- Users & permissions ---------- */
let UD = null;
F.pages.users = async (_, q, tk) => {
  title('Users & permissions'); UD = await api('users'); if (stale(tk)) return; const tab = q.tab || 'users';
  let h = `<div class="tabs"><a href="#/users" class="${tab === 'users' ? 'on' : ''}">Users</a><a href="#/users?tab=matrix" class="${tab === 'matrix' ? 'on' : ''}">Permission templates</a></div>`;
  if (tab === 'users') h += `<div class="row" style="margin-bottom:12px"><button class="btn primary" data-a="user-edit">＋ Add user</button></div>` + card('Users', table(['Name', 'Sign-in email', 'Access', 'Status', ''], UD.users.map(u => `<tr><td><b>${esc(u.name)}</b><div class="xs muted">base role: ${esc(u.legacy_role_label)}</div></td><td>${esc(u.email)}</td><td>${esc(UD.templates.find(t => t.key === u.template)?.label || u.template)}${Object.keys(u.overrides).length ? ' ' + chip(Object.keys(u.overrides).length + ' custom', 'or') : ''}<div class="xs muted">${u.perms.length} permissions</div></td><td>${u.active ? chip('Active', 'ok') : chip('Disabled', 'bad')}<div class="xs muted">${u.last_login ? 'last sign-in ' + TS(u.last_login) : 'never signed in'}</div></td><td><button class="btn sm" data-a="user-edit" data-id="${u.id}">Edit</button> <button class="btn sm" data-a="user-pw" data-id="${u.id}">Password</button></td></tr>`)));
  if (tab === 'matrix') { const groups = [...new Set(UD.perms.map(p => p.group))];
    h += card('What each template can do', `<div class="tw"><table class="t nostack"><thead><tr><th>Permission</th>${UD.templates.map(t => `<th class="n">${esc(t.label)}</th>`).join('')}</tr></thead><tbody>${groups.map(g => `<tr class="dept"><td colspan="${UD.templates.length + 1}">${esc(g)}</td></tr>` + UD.perms.filter(p => p.group === g).map(p => `<tr><td>${esc(p.label)} <span class="xs muted">${p.key}</span></td>${UD.templates.map(t => `<td class="n">${t.perms.includes(p.key) ? '✓' : ''}</td>`).join('')}</tr>`).join('')).join('')}</tbody></table></div><p class="xs muted">Each user starts from a template; the owner can switch any single permission on or off per user. Every permission is checked by the server on every request.</p>`); }
  return h;
};
F.A['user-edit'] = b => {
  const u = b.dataset.id ? UD.users.find(x => x.id == b.dataset.id) : { active: 1, role: 'counter', template: 'counter', overrides: {}, perms: UD.templates.find(t => t.key === 'counter').perms };
  const groups = [...new Set(UD.perms.map(p => p.group))];
  const m = modal({ title: u.id ? 'Edit ' + esc(u.name) : 'Add user', wide: true, body: `<form data-form="user" id="usf" novalidate><input type="hidden" name="id" value="${u.id || ''}"><div class="fg">${field('Name', 'name', { value: u.name || '', req: true })}${field('Email (sign-in)', 'email', { type: 'email', value: u.email || '', req: true })}
    ${u.id ? '' : field('Password', 'password', { type: 'password', req: true, help: '12 characters or more', attrs: 'autocomplete="new-password"' })}${select('Base role', 'role', Object.entries(UD.legacy_roles), u.role, { help: 'Job title. Finance 360 access comes from the template below.' })}
    ${select('Finance 360 template', 'template', UD.templates.map(t => [t.key, t.label]), u.template, { attrs: 'id="ustpl"' })}<label class="check"><input type="checkbox" name="active" ${u.active ? 'checked' : ''}><span>Account active</span></label>${F.me.user.role === 'owner' ? moneyField('Max single supplier payment', 'pay_max', u.pay_max || null, { help: 'Empty / 0 = no limit. Above this a user needs “Approve supplier payments”.' }) : ''}</div>
    <h3 style="margin:16px 0 6px">Permissions</h3><p class="xs muted" style="margin:0 0 8px">Ticked = allowed. Changes from the template are saved as custom permissions for this user. Owners always have every permission.</p>
    <div id="upm" class="grid g2">${groups.map(g => `<fieldset class="card flat" style="margin:0;padding:10px 12px"><legend class="b sm">${esc(g)}</legend>${UD.perms.filter(p => p.group === g).map(p => `<label class="row sm" style="gap:8px;min-height:36px"><input type="checkbox" data-perm="${p.key}" ${u.perms.includes(p.key) ? 'checked' : ''} style="width:20px;height:20px;accent-color:var(--brand-orange)"><span>${esc(p.label)}</span></label>`).join('')}</fieldset>`).join('')}</div><button hidden type="submit"></button></form>`,
    foot: '<button class="btn" data-close>Cancel</button><button class="btn primary" data-a="us-go" data-submit>Save user</button>' });
  $('#ustpl', m).addEventListener('change', e => { const t = UD.templates.find(x => x.key === e.target.value); $$('[data-perm]', m).forEach(c => c.checked = t.perms.includes(c.dataset.perm)); });
};
F.A['us-go'] = () => $('#usf').requestSubmit();
F.forms.user = async (f, d) => { if (!moneyCheck(f, ['pay_max'])) return; const ov = {}; $$('[data-perm]', f).forEach(c => ov[c.dataset.perm] = c.checked); await api('user-save', {}, { ...d, active: f.active.checked, overrides: ov }); f.closest('.modal')._close(); toast('User saved. Their next request uses the new permissions.'); render(); };
F.A['user-pw'] = async b => { const pw = await ask('Set a new password', 'The user will be signed out on all devices.', { input: 'New password (12+ characters)', required: true, ok: 'Set password' }); if (!pw) return; await api('user-password', {}, { id: b.dataset.id, password: pw }); toast('Password changed.'); };

/* ---------- Settings ---------- */
const SET_GROUPS = [
  ['Daily closing', ['business_day_cutoff_hour', 'f3_popup_hour', 'f3_closing_start', 'f3_counter_backlog_days', 'f3_snooze_minutes', 'f3_diff_reason_above', 'f3_opening_mode', 'f3_expense_proof_above']],
  ['Accounts mapping', ['f3_acct_cash', 'f3_acct_card', 'f3_acct_upi', 'f3_acct_bank', 'unify_from']],
  ['Purchases & stock', ['f3_purchase_approval_above', 'f3_require_utr', 'f3_tax_in_cost', 'f3_negative_stock']],
  ['Salary & staff', ['f3_payroll_start', 'f3_payroll_separate_approver', 'f3_payroll_reminder_hour', 'f3_rest_days_paid', 'f3_max_deduction_pct', 'f3_advance_limit_pct']],
  ['Old modules', ['legacy_archived']]];
F.settingsGeneral = async (q, tk) => {
  const r = await api('settings'); if (stale(tk)) return;
  const inp = s => s.type === 'enum' ? select(s.label, s.key, s.options.map(o => [o, { books: 'Books cash balance before the day', zero: 'Drawer starts at ₹0 each day', calendar: 'Calendar days (28/29/30/31)', fixed30: 'Always 30 days', '0': 'No', '1': 'Yes', '0.5': '½ day' }[o] || o]), s.value)
    : s.type === 'account' ? select(s.label, s.key, r.accounts, s.value) : s.type === 'date' || s.type === 'date_or_blank' ? field(s.label, s.key, { type: 'date', value: s.value }) : s.type === 'month' ? field(s.label, s.key, { type: 'month', value: s.value })
    : s.type === 'list' ? field(s.label, s.key, { value: s.value }) : s.type.startsWith('money') ? moneyField(s.label, s.key, null, { attrs: `value="${esc(s.value)}"` }).replace('value="" ', '') : field(s.label, s.key, { type: 'number', value: s.value, attrs: `min="${s.min ?? ''}" max="${s.max ?? ''}"` });
  return `<div class="alert sm">Time zone: <b>${esc(r.timezone)}</b> · server time now <b>${esc(r.now)}</b> · bank-detail encryption key ${r.pii_key_configured ? chip('configured', 'ok') : chip('missing', 'bad')}</div>
  <form data-form="settings" class="card"><div class="bd">${SET_GROUPS.map(([g, keys]) => { const it = r.settings.filter(x => keys.includes(x.key)); return it.length ? `<h3 class="setg">${g}</h3><div class="fg">${it.map(inp).join('')}</div>` : ''; }).join('')}${(o => o.length ? `<h3 class="setg">Other</h3><div class="fg">${o.map(inp).join('')}</div>` : '')(r.settings.filter(x => !SET_GROUPS.some(([, k]) => k.includes(x.key))))}<div class="row" style="margin-top:14px"><button class="btn primary" type="submit">Save settings</button></div></div></form>
`;
};
F.forms.settings = async (f, d) => { const r = await api('settings-save', {}, { values: d }); toast(r.changed.length ? 'Saved: ' + r.changed.length + ' change(s).' : 'Nothing changed.'); await refreshMe(); render(); };

/* ---------- Audit ---------- */
F.pages.audit = async (_, q, tk) => {
  title('Audit history'); const r = await api('audit', q); if (stale(tk)) return; const page = Number(q.page || 0);
  const nav = p => '#/audit?' + new URLSearchParams({ ...q, page: p }).toString();
  return `<form class="card no-print" data-form="range" data-to="#/audit"><div class="bd row">${field('Action contains', 'action', { value: q.action || '', ph: 'closing, payroll, user…' })}${select('User', 'user', r.users.map(u => [u.id, u.name]), q.user || '', { blank: 'Everyone' })}${field('From', 'from', { type: 'date', value: q.from || '' })}${field('To', 'to', { type: 'date', value: q.to || '' })}<button class="btn dark" type="submit">Filter</button></div></form>
  ${card('Every change, approval and export (newest first)', table(['When (IST)', 'Who', 'Action', 'Details'], r.rows.map(a => `<tr><td class="num sm">${TS(a.at)}</td><td>${esc(a.user || 'system')}</td><td><b>${esc(a.action)}</b></td><td class="xs" style="max-width:520px;word-break:break-word">${esc(typeof a.detail === 'string' ? a.detail : JSON.stringify(a.detail)).slice(0, 400)}</td></tr>`)))}
  <div class="row">${page > 0 ? `<a class="btn" href="${nav(page - 1)}">‹ Newer</a>` : ''}${r.rows.length === 100 ? `<a class="btn" href="${nav(page + 1)}">Older ›</a>` : ''}</div>`;
};

/* ---------- My account & More ---------- */
F.pages.me = async () => {
  title('My account'); const u = F.me.user;
  return `<div class="split">${card('Account', `<div class="sum"><div><span>Name</span><span>${esc(u.name)}</span></div><div><span>Email</span><span>${esc(u.email)}</span></div><div><span>Access</span><span>${esc(u.template_label)}</span></div><div><span>Base role</span><span>${esc(u.role_label)}</span></div></div><div class="row" style="margin-top:14px"><button class="btn danger" data-a="logout">Sign out</button></div>`)}
  ${card('Change password', `<form data-form="mypw" class="stack">${field('Current password', 'current', { type: 'password', req: true, attrs: 'autocomplete="current-password"' })}${field('New password', 'password', { type: 'password', req: true, help: '12 characters or more', attrs: 'autocomplete="new-password"' })}${field('Repeat new password', 'password2', { type: 'password', req: true, attrs: 'autocomplete="new-password"' })}<button class="btn primary" type="submit">Change password</button></form><p class="xs muted">Changing the password signs you out on other devices.</p>`)}</div>
  ${card('What I can do', `<div class="row">${F.me.perms.map(p => chip(p, 'or')).join(' ')}</div>`)}`;
};
F.forms.mypw = async (f, d) => { if (d.password !== d.password2) { toast('The two new passwords are different.', true); return; } await api('user-password', {}, { current: d.current, password: d.password }); f.reset(); toast('Password changed.'); };
F.pages.more = async () => {
  title('More'); const items = NAV.flatMap(([g, it]) => it.filter(i => navOk(i[3])).map(i => [g, ...i]));
  let last = ''; let h = '';
  for (const [g, href, ic, l] of items) { if (g !== last) { h += `<div class="li" style="background:#FFF6EA;min-height:36px"><b class="xs" style="letter-spacing:1.4px;color:var(--orange-ink)">${esc(g.toUpperCase())}</b></div>`; last = g; } h += `<a class="li" href="${href}"><span class="av" aria-hidden="true">${ic}</span><span class="grow"><b>${esc(l)}</b></span>›</a>`; }
  h += `<a class="li" href="#/me"><span class="av">☺</span><span class="grow"><b>My account</b><span class="xs muted">${esc(F.me.user.name)}</span></span>›</a><button class="li" data-a="logout" style="border:0;background:none;width:100%;text-align:left;cursor:pointer;font:inherit"><span class="av">⏻</span><span class="grow"><b>Sign out</b></span></button>`;
  return card('', `<div class="list">${h}</div>`);
};
