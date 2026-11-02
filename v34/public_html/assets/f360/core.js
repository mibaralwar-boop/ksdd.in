/* Khadak Singh Da Dhaba — Finance 360 V17 · core: API, router, layout, components, popups, smart assistant.
   All data comes from f360-api.php; the browser never decides permissions (server enforces every route). */
'use strict';
const F = { me: null, csrf: '', pages: {}, A: {}, forms: {}, cur: null, navOpen: false };
const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const can = p => !!F.me && F.me.perms.includes(p);
const canAny = (...p) => p.some(can);
/* ---------- money & dates (paise integers; Indian grouping; Asia/Kolkata business dates from server) ---------- */
function inrGroup(n) { const s = String(n); if (s.length <= 3) return s; const h = s.slice(0, -3), t = s.slice(-3); return h.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + t; }
function R(p, dec) { if (p === null || p === undefined || p === '') return '—'; p = Number(p); const neg = p < 0; p = Math.abs(p); const r = Math.floor(p / 100), ps = p % 100; return (neg ? '−' : '') + '₹' + inrGroup(r) + ((dec || ps) ? '.' + String(ps).padStart(2, '0') : ''); }
const rupeesStr = p => p === null || p === undefined ? '' : (Number(p) / 100).toFixed(2).replace(/\.00$/, '');
function toPaise(v) { v = String(v ?? '').replace(/[,₹\s]/g, ''); if (v === '') return null; if (!/^\d{1,10}(\.\d{1,2})?$/.test(v)) return NaN; const [w, f = ''] = v.split('.'); return Number(w) * 100 + Number((f + '00').slice(0, 2)); }
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
function D(d) { if (!d) return '—'; const [y, m, x] = d.slice(0, 10).split('-').map(Number); const dt = new Date(Date.UTC(y, m - 1, x)); return DAYS[dt.getUTCDay()] + ' ' + x + ' ' + MONTHS[m - 1] + (y !== Number((F.me?.business_date || '').slice(0, 4)) ? ' ' + y : ''); }
function TS(s) { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T') + (/[zZ+]/.test(s) ? '' : 'Z')); return isNaN(d) ? esc(s) : d.toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) + ' IST'; }
function addDays(d, n) { const [y, m, x] = d.split('-').map(Number); const dt = new Date(Date.UTC(y, m - 1, x + n)); return dt.toISOString().slice(0, 10); }
function monthLabel(p) { const [y, m] = p.split('-').map(Number); return ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][m - 1] + ' ' + y; }
function prevMonth(p) { let [y, m] = p.split('-').map(Number); m--; if (!m) { m = 12; y--; } return y + '-' + String(m).padStart(2, '0'); }
const today = () => F.me.business_date, monthStart = d => d.slice(0, 8) + '01';
const ikey = () => 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
const initials = n => String(n || '?').replace(/^DEMO\s+/, '').split(/\s+/).map(x => x[0]).slice(0, 2).join('').toUpperCase();
/* ---------- API ---------- */
async function api(route, params = {}, body) {
  const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString();
  const opt = body === undefined ? { credentials: 'same-origin' } : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': F.csrf }, body: JSON.stringify(body) };
  let r; try { r = await fetch('f360-api.php?route=' + route + (qs ? '&' + qs : ''), opt); } catch (e) { const er = new Error('No internet connection. Your entries are not lost — try again when online.'); er.status = 0; throw er; }
  let j = {}; try { j = await r.json(); } catch (e) { j = { error: 'Server returned an unexpected response (' + r.status + ').' }; }
  if (r.status === 401) { F.me = null; showLogin(j.error); throw Object.assign(new Error(j.error || 'Please sign in'), { status: 401 }); }
  if (!r.ok) throw Object.assign(new Error(j.error || 'Request failed'), { status: r.status, data: j });
  return j;
}
const exportUrl = (params) => 'f360-api.php?route=export&' + new URLSearchParams(params).toString();
/* ---------- toast / modal ---------- */
let toastT; function toast(m, err) { const t = $('#toast'); t.textContent = m; t.className = 'toast' + (err ? ' err' : ''); t.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => t.hidden = true, err ? 6500 : 3200); }
const fail = e => { if (e && e.status !== 401) toast(e.message || String(e), true); };
let modalStack = [];
function modal({ title, sub = '', body = '', foot = '', wide = false, locked = false, onClose, beforeClose } = {}) {
  const prev = document.activeElement; const m = document.createElement('div'); m.className = 'modal'; m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true');
  const id = 'dlg' + Date.now(); m.setAttribute('aria-labelledby', id + 't');
  m.innerHTML = `<div class="dlg${wide ? ' wide' : ''}"><div class="dh"><div class="grow"><h2 id="${id}t">${title}</h2>${sub ? `<p>${sub}</p>` : ''}</div>${locked ? '' : '<button class="iconbtn" data-close aria-label="Close">✕</button>'}</div><div class="db">${body}</div>${foot ? `<div class="df">${foot}</div>` : ''}</div>`;
  document.body.appendChild(m); modalStack.push(m);
  const close = (v) => { m.remove(); modalStack = modalStack.filter(x => x !== m); if (prev && prev.focus) prev.focus(); onClose && onClose(v); };
  m._close = close;
  /* V23: a dialog may refuse to close (e.g. the salary popup while its edits are not saved on the server). m._close() still forces it. */
  let closing = false; const tryClose = async v => { if (closing) return; closing = true; try { if (beforeClose && !(await beforeClose(v))) return; close(v); } finally { closing = false; } };
  m._tryClose = tryClose;
  m.addEventListener('click', e => { if (e.target.closest('[data-close]') || (e.target === m && !locked)) tryClose(); });
  m.addEventListener('keydown', e => { if (e.key === 'Escape' && !locked) { e.stopPropagation(); tryClose(); } if (e.key === 'Tab') { const f = $$('button,input,select,textarea,a[href],[tabindex]:not([tabindex="-1"])', m).filter(x => !x.disabled && x.offsetParent !== null); if (!f.length) return; const a = f[0], z = f[f.length - 1]; if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); } } });
  setTimeout(() => { const f = $('input:not([type=hidden]),select,textarea', $('.db', m)) || $('.df .btn.primary', m) || $('button', m); f && f.focus(); }, 30);
  return m;
}
const closeTop = v => modalStack.length && modalStack[modalStack.length - 1]._close(v);
function ask(title, text, { ok = 'Confirm', danger = false, input = null, placeholder = '', required = false } = {}) {
  return new Promise(res => {
    const m = modal({ title, body: `<p style="margin-top:0">${text}</p>${input ? `<label class="f">${esc(input)}<textarea id="askin" placeholder="${esc(placeholder)}"></textarea></label>` : ''}`, foot: `<button class="btn" data-close>Cancel</button><button class="btn ${danger ? 'danger fill' : 'primary'}" id="askok">${esc(ok)}</button>`, onClose: v => res(v ?? false) });
    $('#askok', m).onclick = () => { const v = input ? $('#askin', m).value.trim() : true; if (input && required && !v) { $('#askin', m).focus(); toast('Please write ' + input.toLowerCase(), true); return; } m._close(v || (input ? '' : true)); };
  });
}
/* ---------- small renderers ---------- */
const card = (title, body, { act = '', cls = '', id = '' } = {}) => `<section class="card ${cls}"${id ? ` id="${id}"` : ''}>${title ? `<div class="hd"><h2>${title}</h2>${act}</div>` : ''}<div class="bd">${body}</div></section>`;
const kpi = (l, v, s = '', { href = '', cls = '', tag = '' } = {}) => `<${href ? `a href="${href}"` : 'div'} class="kpi ${cls}">${tag ? `<span class="tag">${tag}</span>` : ''}<div class="l">${l}</div><div class="v">${v}</div>${s ? `<div class="s">${s}</div>` : ''}</${href ? 'a' : 'div'}>`;
const filterBox = (inner, label = 'Filters & export') => `<details class="card no-print filters" ${window.innerWidth > 900 ? 'open' : ''}><summary class="bd b" style="cursor:pointer;min-height:44px">⚙ ${label}</summary>${inner}</details>`;
const waLink = (text, phone = '') => 'https://wa.me/' + String(phone).replace(/\D/g, '').replace(/^(\d{10})$/, '91$1') + '?text=' + encodeURIComponent(text);
const chip = (t, c = '') => `<span class="chip ${c}">${esc(t)}</span>`;
const empty = (t, s = '', act = '') => `<div class="empty"><b>${t}</b>${s}${act ? `<div style="margin-top:12px">${act}</div>` : ''}</div>`;
const skel = (n = 4) => `<div class="loading-page" aria-busy="true" aria-label="Loading">${'<div class="sk" style="height:84px;border-radius:14px"></div>'.repeat(1)}${'<div class="sk"></div>'.repeat(n)}</div>`;
function table(head, rows, { cls = '', none = 'Nothing here yet', hint = 'No records for this selection. Try a wider date range.' } = {}) { if (!rows.length) return empty(none, `<span class="sm">${hint}</span>`); return `<div class="tw"><table class="t ${cls}"><thead><tr>${head.map(h => typeof h === 'string' ? `<th>${h}</th>` : `<th class="${h.c || ''}">${h.t}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`; }
const field = (label, name, { type = 'text', value = '', req = false, help = '', ph = '', attrs = '', cls = '' } = {}) => `<label class="f ${cls}">${label}${req ? ' *' : ''}<input name="${name}" type="${type}" value="${esc(value)}" ${req ? 'required' : ''} placeholder="${esc(ph)}" ${attrs}>${help ? `<span class="h">${help}</span>` : ''}</label>`;
const moneyField = (label, name, value, { req = false, help = '', xl = false, attrs = '', cls = '' } = {}) => `<label class="f ${cls}">${label}${req ? ' *' : ''}<span class="money${xl ? ' xl' : ''}"><input name="${name}" inputmode="decimal" autocomplete="off" value="${value === null || value === undefined ? '' : esc(rupeesStr(value))}" ${req ? 'required' : ''} ${attrs} aria-label="${esc(label)} in rupees"></span>${help ? `<span class="h">${help}</span>` : ''}</label>`;
const select = (label, name, opts, value = '', { req = false, help = '', attrs = '', cls = '', blank = '' } = {}) => `<label class="f ${cls}">${label}${req ? ' *' : ''}<select name="${name}" ${req ? 'required' : ''} ${attrs}>${blank ? `<option value="">${esc(blank)}</option>` : ''}${opts.map(o => { const [v, t, g] = Array.isArray(o) ? o : [o, o]; return `<option value="${esc(v)}" ${String(v) === String(value) ? 'selected' : ''}>${esc(t)}</option>`; }).join('')}</select>${help ? `<span class="h">${help}</span>` : ''}</label>`;
const textarea = (label, name, value = '', { req = false, help = '', ph = '' } = {}) => `<label class="f full">${label}${req ? ' *' : ''}<textarea name="${name}" ${req ? 'required' : ''} placeholder="${esc(ph)}">${esc(value)}</textarea>${help ? `<span class="h">${help}</span>` : ''}</label>`;
function formData(form) { const o = {}; for (const el of form.elements) { if (!el.name || el.disabled) continue; if (el.type === 'checkbox') o[el.name] = el.checked; else if (el.type === 'radio') { if (el.checked) o[el.name] = el.value; } else if (el.type !== 'file') o[el.name] = el.value.trim(); } return o; }
function moneyCheck(form, names) { for (const n of names) { const el = form.elements[n]; if (!el) continue; const p = toPaise(el.value); const wrap = el.closest('.f'); wrap.classList.toggle('err', Number.isNaN(p)); $('.em', wrap)?.remove(); if (Number.isNaN(p)) { wrap.insertAdjacentHTML('beforeend', '<span class="em" role="alert">Enter an amount like 1250 or 1250.50</span>'); el.focus(); return false; } } return true; }
async function fileToUpload(input) { const f = input?.files?.[0]; if (!f) return null; if (!/^image\/|application\/pdf/.test(f.type)) throw new Error('Choose a photo or PDF.');
  if (f.type.startsWith('image/') && f.size > 600000) { const img = await new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = URL.createObjectURL(f); }); const s = Math.min(1, 1600 / Math.max(img.width, img.height)); const c = document.createElement('canvas'); c.width = Math.round(img.width * s); c.height = Math.round(img.height * s); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); const d = c.toDataURL('image/jpeg', .82); return { name: f.name, data: d.split(',')[1] }; }
  if (f.size > 1500000) throw new Error('File is larger than 1.5 MB.'); const d = await new Promise((ok, no) => { const r = new FileReader(); r.onload = () => ok(r.result); r.onerror = no; r.readAsDataURL(f); }); return { name: f.name, data: String(d).split(',')[1] }; }
function busy(btn, on) { if (!btn) return; if (on) { btn.dataset.t = btn.innerHTML; btn.disabled = true; btn.innerHTML = 'Saving…'; } else { btn.disabled = false; if (btn.dataset.t) btn.innerHTML = btn.dataset.t; } }
const statusChip = s => ({ draft: chip('Draft', 'warn'), not_started: chip('Not started', 'bad'), submitted: chip('Locked · review', 'info'), approved: chip('Final · locked', 'ok'), reopened: chip('Reopened', 'warn'), legacy: chip('Books', ''), pending: chip('Pending', 'warn'), paid: chip('Paid', 'ok'), unpaid: chip('Unpaid', 'bad'), rejected: chip('Rejected', 'bad'), reversed: chip('Reversed', ''), review: chip('Ready for review', 'info'), locked: chip('Locked', 'dk'), done: chip('Done', 'ok'), skipped: chip('Skipped', 'warn'), later: chip('Add later', 'warn'), excluded: chip('Excluded', ''), partially_paid: chip('Partly paid', 'warn'), reviewed: chip('Reviewed', 'ok'), flagged: chip('Flagged', 'bad'), held: chip('Sales held', 'bad') }[s] || chip(s || '—'));
const diffLabel = v => v === null || v === undefined ? '—' : v > 0 ? `<span class="good b">Cash Acess ${R(v)}</span>` : v < 0 ? `<span class="bad b">Cash Short ${R(-v)}</span>` : '<span class="b">Balanced</span>';
/* ---------- navigation ---------- */
const NAV = [
  ['Home', [['#/', '⌂', 'Dashboard', 'dashboard.view']]],
  ['Daily', [['#/closing', '☀', 'Daily Cash Closing', 'closing.view'], ['#/sales', '₹', 'Sales', 'sales.view'], ['#/cash-acess', '±', 'Cash Acess / Short', ['closing.approve', 'reports.view']], ['#/expenses', '↘', 'Expenses', 'expenses.view'], ['#/handover', '⇆', 'Cash Handover', ['handover.send', 'handover.receive']]]],
  ['Purchases & Stock', [['#/purchases', '🧾', 'Purchases', ['purchase.view', 'purchase.create']], ['#/suppliers', '⛟', 'Suppliers', ['suppliers.view', 'purchase.create']], ['#/stock', '▤', 'Stock', ['stock.view', 'item.view']], ['#/count', '☑', 'Stock count', ['stock.count', 'stock.count_approve']]]],
  ['Documents & planning', [['#/docs', '📥', 'Document inbox', 'docs.inbox'], ['#/reorder', '↻', 'Reorder planner', 'stock.view']]],
  ['Accounts', [['#/cash', '▣', 'Cashbook', 'cash.view'], ['#/receipts', '⇄', 'Bank / UPI / Card', 'accounts.view'], ['#/accounts', '≡', 'Accounts & ledgers', 'accounts.view'], ['#/aging', '⏱', 'Supplier outstanding', 'supplier.outstanding']]],
  ['Staff & Salary', [['#/staff', '☺', 'Staff Management', 'staff.view'], ['#/staff-entries', '✎', 'Advances / Penalties / Overtime', ['stafftxn.create', 'staff.ledger']], ['#/payroll', '◷', 'Salary Management', ['payroll.view', 'payroll.prepare']], ['#/pay-acks', '✔', 'Salary acknowledgements', ['payroll.ack', 'payroll.approve']]]],
  ['Reports', [['#/reports', '▥', 'Reports', 'reports.view'], ['#/purchase-register', '▦', 'Purchase register', 'purchase.report'], ['#/valuation', '₹', 'Stock valuation', 'stock.valuation'], ['#/stock-moves', '⇅', 'Stock movements', 'stock.view']]],
  ['Admin', [['#/exceptions', '⚑', 'Error resolution', 'exceptions.manage'], ['#/month-end', '☑', 'Month-end checklist', ['reports.view', 'accounts.view']], ['#/notifications', '🔔', 'Notifications', 'notifications.view'], ['#/users', '⚿', 'Users & Permissions', 'users.manage'], ['#/settings', '⚙', 'Settings', 'settings.manage'], ['#/audit', '⧉', 'Audit history', 'audit.view']]],
];
const navOk = p => typeof p === 'function' ? p() : Array.isArray(p) ? canAny(...p) : can(p); // V31: a function = extra condition (e.g. multi-branch only)
/* V29: restaurant branding — the signed-in restaurant's own logo/name; the original restaurant keeps its bundled logo. */
function brandSrc() { const p = F.me?.platform; if (p?.platform) return p.tenant.logo || 'assets/f360-mark.svg'; return 'assets/brand/logo-mark-96.png'; }
function brandName() { const p = F.me?.platform; return p?.platform ? p.tenant.name : (F.me?.business?.name || 'Khadak Singh Da Dhaba'); }
const ADD_PERMS = ['expenses.create', 'expenses.today', 'purchase.create', 'stock.issue', 'stock.receive', 'stafftxn.create', 'handover.send', 'docs.inbox', 'cash.transfer', 'supplier.pay', 'payroll.pay'];
function bottomNav() {
  const it = [['#/', '⌂', 'Home', 'dashboard.view'], ['#/closing', '☀', 'Closing', 'closing.view'], ['#/add', '＋', 'Add', null], ['#/purchases', '🧾', 'Purchases', ['purchase.view', 'purchase.create']], ['#/more', '☰', 'More', null]];
  const shown = it.filter(x => x[3] !== null && navOk(x[3])).map(x => x[0]);
  const more = NAV.some(([, items]) => items.some(i => navOk(i[3]) && !shown.includes(i[0]) && i[0] !== '#/notifications')); // "More" only when there is something else to open
  return it.filter(x => x[0] === '#/add' ? ADD_PERMS.some(p => can(p)) : x[0] === '#/more' ? more : navOk(x[3])).map(x => `<a href="${x[0]}" data-nav="${x[0]}"><span class="ic" aria-hidden="true">${x[1]}</span>${x[2]}${x[0] === '#/closing' && F.me.pending_closings?.length ? `<span class="dot">${F.me.pending_closings.length}</span>` : ''}</a>`).join('');
}
function renderShell() {
  const u = F.me.user;
  document.body.innerHTML = `<a href="#content" class="skip">Skip to content</a><div class="shell">
  <aside class="side" id="side" aria-label="Main menu"><a class="brand" href="#/"><img class="brand-mark" src="${esc(brandSrc())}" alt=""><span class="brand-txt"><b>${esc(brandName())}</b><small>FINANCE 360</small></span></a>
  <nav class="nav" id="nav">${NAV.map(([g, items]) => { const v = items.filter(i => navOk(i[3])); return v.length ? `<div class="grp">${g}</div>` + v.map(i => `<a href="${i[0]}" data-nav="${i[0]}"><span class="ic" aria-hidden="true">${i[1]}</span>${i[2]}${i[0] === '#/closing' && F.me.pending_closings?.length ? `<span class="cnt">${F.me.pending_closings.length}</span>` : ''}${i[0] === '#/notifications' && F.me.notifications ? `<span class="cnt">${F.me.notifications}</span>` : ''}</a>`).join('') : ''; }).join('')}
  
  </nav><div class="who"><b>${esc(u.name)}</b>${esc(u.template_label)} · <a href="#/me" style="color:#FFC896">Account</a></div></aside>
  <div class="scrim" id="scrim"></div>
  <div class="main"><header class="top"><button class="iconbtn show-sm" data-a="menu" aria-label="Open menu" aria-controls="side">☰</button><img class="mlogo" src="${esc(brandSrc())}" alt="${esc(brandName())}"><div class="t"><small>Finance 360</small><b id="ptitle">Dashboard</b></div><form class="gsearch" role="search" data-gsearch><input type="search" name="q" placeholder="Search staff, bills, items, ref no…" aria-label="Search everything" autocomplete="off"></form><button class="iconbtn show-sm" data-a="gsearch-open" aria-label="Search">⌕</button>
  ${can('notifications.view') ? `<a class="iconbtn" href="#/notifications" aria-label="Notifications">🔔${F.me.notifications ? `<span class="dot">${F.me.notifications}</span>` : ''}</a>` : ''}<a class="iconbtn hide-sm" href="#/me" aria-label="My account">☺</a></header>
  <main class="content" id="content" tabindex="-1"></main></div></div>
  <nav class="bottom" aria-label="Quick navigation">${bottomNav()}</nav><div id="toast" class="toast" role="status" aria-live="polite" hidden></div>`;
}
function setNavActive(path) { const base = '#/' + (path.split('/')[1] || ''); $$('[data-nav]').forEach(a => { const on = a.dataset.nav === base || (base === '#/' && a.dataset.nav === '#/'); a.classList.toggle('on', on); if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); }); }
function title(t) { const el = $('#ptitle'); if (el) el.textContent = t; document.title = t + ' · ' + (F.me?.platform?.platform ? brandName() + ' · ' : 'KSDD ') + 'Finance 360'; }
/* ---------- router ---------- */
const ROUTES = [];
function route(re, page, perm) { ROUTES.push({ re, page, perm }); }
function parseHash() { const h = location.hash.replace(/^#/, '') || '/'; const [p, qs] = h.split('?'); return { path: p, q: Object.fromEntries(new URLSearchParams(qs || '')) }; }
function go(h) { if (location.hash === h) render(); else location.hash = h; }
/* V23: a page can register F.leaveGuard (async → true/false) so in-app navigation never throws away unsaved server work. */
F.lastHash = location.hash;
async function render() {
  if (!F.me) return;
  if (F.leaveGuard && location.hash !== F.lastHash) { const g = F.leaveGuard; const ok = await g(); if (!ok) { history.replaceState(null, '', F.lastHash || '#/'); return; } if (F.leaveGuard === g) F.leaveGuard = null; }
  F.lastHash = location.hash;
  closeMenu(); while (modalStack.length) closeTop();
  const { path, q } = parseHash();
  // V29 §7: a user without the dashboard goes straight to their first permitted screen (e.g. Cash Counter → Closing), never to "No access".
  if (path === '/' && !navOk('dashboard.view')) { const first = NAV.flatMap(([, items]) => items).find(i => i[0] !== '#/' && navOk(i[3])); if (first) { history.replaceState(null, '', first[0]); return render(); } }
  setNavActive('#' + path);
  const c = $('#content'); c.innerHTML = skel(); const token = Symbol(); F.cur = token;
  for (const r of ROUTES) { const m = path.match(r.re); if (!m) continue;
    if (r.perm && !navOk(r.perm)) { title('No access'); c.innerHTML = card('', empty('You do not have access to this page', '<span class="sm">Ask the owner to give you permission in Users & permissions.</span>', '<a class="btn" href="#/">Go to dashboard</a>')); return; }
    try { const html = await F.pages[r.page](m.slice(1), q, token); if (F.cur !== token) return; if (typeof html === 'string') c.innerHTML = html; c.focus({ preventScroll: true }); window.scrollTo(0, 0); }
    catch (e) { if (F.cur !== token || e.status === 401) return; c.innerHTML = card('', `<div class="alert danger" role="alert"><b>Could not load this page.</b><br>${esc(e.message)}</div><button class="btn" data-a="reload">Try again</button>`); }
    maybePopups(); return; }
  title('Not found'); c.innerHTML = card('', empty('Page not found', '', '<a class="btn" href="#/">Go to dashboard</a>'));
}
const stale = t => F.cur !== t;
/* Phone layout: label every table cell with its column header so CSS can show rows as stacked cards. */
function labelTables(root = document) { $$('table.t', root).forEach(t => { if (t.dataset.lab === '1') return; const h = $$('thead th', t).map(th => th.textContent.trim()); $$('tbody tr', t).forEach(tr => { let i = 0; [...tr.children].forEach(td => { if (!td.hasAttribute('data-label')) { td.setAttribute('data-label', td.colSpan > 1 ? '' : (h[i] || '')); if (td.childNodes.length > 1 && !td.classList.contains('no-print')) { const w = document.createElement('div'); w.className = 'cv'; while (td.firstChild) w.appendChild(td.firstChild); td.appendChild(w); } } i += td.colSpan || 1; }); }); t.dataset.lab = '1'; }); }
new MutationObserver(() => { const c = document.getElementById('content'); if (c) { $$('table.t', c).forEach(t => t.dataset.lab = ''); labelTables(c); } }).observe(document.documentElement, { childList: true, subtree: true });
/* ---------- events ---------- */
document.addEventListener('click', e => { const a = e.target.closest('[data-a]'); if (!a) return; const fn = F.A[a.dataset.a]; if (fn) { e.preventDefault(); Promise.resolve(fn(a, e)).catch(fail); } });
document.addEventListener('submit', e => { const f = e.target.closest('form[data-form]'); if (!f) return; e.preventDefault(); const fn = F.forms[f.dataset.form]; if (fn) { const btn = $('button[type=submit]', f) || $('[data-submit]', f.closest('.dlg') || f); busy(btn, true); Promise.resolve(fn(f, formData(f), e)).catch(fail).finally(() => busy(btn, false)); } });
F.A.reload = () => render();
F.A.menu = () => { $('#side').classList.add('open'); $('#scrim').classList.add('open'); F.navOpen = true; $('#side a')?.focus(); };
function closeMenu() { $('#side')?.classList.remove('open'); $('#scrim')?.classList.remove('open'); }
document.addEventListener('click', e => { if (e.target.id === 'scrim') closeMenu(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('#side.open')) closeMenu(); });
F.A.back = () => history.back();
F.A.print = () => window.print();
F.A.go = a => go(a.dataset.href);
F.A.logout = async () => { try { await fetch('api.php?route=logout', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': F.csrf }, body: '{}' }); } catch (e) { } sessionStorage.clear(); location.reload(); };
/* ---------- login ---------- */
async function showLogin(msg) {
  if (document.body.dataset.platform === '1') { location.href = 'signin.php'; return; } // V29: one sign-in page for all restaurants
  document.body.innerHTML = `<main class="login"><form class="box" id="loginform" novalidate><img src="assets/brand/logo-full.png" alt="Khadak Singh Da Dhaba"><h1>Finance 360</h1><p class="c">Sign in · साइन इन करें</p>
  ${msg && msg !== 'Please sign in' ? `<div class="alert danger" role="alert">${esc(msg)}</div>` : ''}<div class="stack">${field('Email', 'email', { type: 'email', req: true, attrs: 'autocomplete="username"' })}${field('Password', 'password', { type: 'password', req: true, attrs: 'autocomplete="current-password"' })}
  <button class="btn primary big block" type="submit">Sign in</button><p class="xs muted" style="margin:0;text-align:center">Forgot password? Ask the owner to reset it in Users & permissions. Owner locked out: use <b>reset-owner.php</b> as described in the install guide.</p></div></form></main><div id="toast" class="toast" role="status" aria-live="polite" hidden></div>`;
  $('#loginform').addEventListener('submit', async e => { e.preventDefault(); const b = $('button', e.target); busy(b, true);
    try { const st = await (await fetch('api.php?route=status', { credentials: 'same-origin' })).json(); if (st.setup) { location.href = 'install.php'; return; }
      const r = await fetch('api.php?route=login', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': st.csrf }, body: JSON.stringify({ email: e.target.email.value.trim(), password: e.target.password.value }) });
      const j = await r.json(); if (!r.ok) throw new Error(j.error || 'Sign-in failed'); sessionStorage.clear(); await boot();
    } catch (er) { toast(er.message, true); busy(b, false); } });
  setTimeout(() => $('#loginform input')?.focus(), 30);
}
/* ---------- popups (Morning Closing / Salary) — once per session per key; "Add later" snoozes on the server ---------- */
function maybePopups() {
  const p = F.me?.popups || {}; if (modalStack.length) return; const path = parseHash().path;
  if (p.closing && !p.closing.snoozed && !sessionStorage.getItem('pop:' + p.closing.key) && !path.startsWith('/closing')) {
    sessionStorage.setItem('pop:' + p.closing.key, '1'); const c = p.closing;
    const m = modal({ title: 'Morning Closing pending', locked: false, body: `<div class="popup-hero"><img src="${esc(brandSrc())}" alt=""><div><b style="font-size:18px">${D(c.date)} — yesterday's closing</b><div class="muted sm">${c.status === 'draft' ? 'A draft is saved — resume from step ' + c.step + '.' : 'Not started yet.'}</div></div></div>
      <ol class="sm" style="margin:0;padding-left:20px;line-height:1.9"><li>Cash, Card &amp; UPI sale (from Petpooja)</li><li>Total Sale is calculated</li><li>Review the day's expenses</li><li>Count Actual Cash</li><li>See Cash Acess / Cash Short</li><li>Submit &amp; lock</li></ol>`,
      foot: `<button class="btn" id="pl">Add later</button><button class="btn primary big" id="ps">${c.status === 'draft' ? 'Resume closing' : 'Start closing'}</button>` });
    $('#ps', m).onclick = () => { m._close(); go('#/closing/' + c.date); };
    $('#pl', m).onclick = async () => { m._close(); try { await api('snooze', {}, { key: c.key }); p.closing.snoozed = true; toast('Reminder hidden for now. The pending banner stays until the day is closed.'); } catch (e) { fail(e); } };
    return;
  }
  if (p.payroll && !p.payroll.snoozed && !sessionStorage.getItem('pop:' + p.payroll.key) && !path.startsWith('/payroll')) {
    sessionStorage.setItem('pop:' + p.payroll.key, '1'); const s = p.payroll; const pr = s.progress;
    const started = s.status !== 'not_started' && !!pr && (pr.touched > 0 || pr.done > 0); const left = pr ? Math.max(0, (pr.total || 0) - (pr.done || 0)) : null;
    const m = modal({ title: 'Salary sheet reminder', body: `<div class="popup-hero"><img src="${esc(brandSrc())}" alt=""><div><b style="font-size:18px" lang="hi">${esc(s.label)} की Salary Sheet ${started ? 'अभी पूरी करनी है।' : 'अभी तैयार करनी है।'}</b><div class="muted sm">${esc(s.label)} salary sheet ${started ? `is in progress (${esc(s.status)})${pr ? ` · ${pr.done || 0} of ${pr.total} staff done${left ? ' · ' + left + ' pending' : ''}` : ''}` : (pr && pr.total ? pr.total + ' staff to enter.' : 'has not been started.')}</div></div></div><p class="sm muted" style="margin:0">Go section by section: Management → UT → Kitchen → Waiter. Each entry is saved on the server — you can stop and continue on any device.</p>`,
      foot: `<button class="btn" id="yl">Add later</button>${started ? `<button class="btn primary" id="yc">Continue pending salary</button>` : `<button class="btn primary" id="ym">Make salary sheet</button>`}` });
    const goWiz = () => { m._close(); go('#/payroll/' + s.period + '/wizard'); };
    if ($('#ym', m)) $('#ym', m).onclick = goWiz; if ($('#yc', m)) $('#yc', m).onclick = goWiz;
    $('#yl', m).onclick = async () => { m._close(); try { await api('snooze', {}, { key: s.key }); p.payroll.snoozed = true; toast('Salary sheet stays pending — reminded again later.'); } catch (e) { fail(e); } };
  }
}
function pendingBanner() {
  const out = []; const pc = F.me.pending_closings || [];
  if (pc.length && can('closing.entry')) { const t = pc[0]; out.push(`<div class="banner" role="status"><span style="font-size:24px" aria-hidden="true">☀</span><div class="grow"><b>Morning Closing pending</b> for ${pc.map(x => D(x.date)).slice(0, 3).join(', ')}${pc.length > 3 ? ' +' + (pc.length - 3) : ''}. Enter Cash/Card/UPI sale and Actual Cash.</div><a class="btn primary" href="#/closing/${t.date}">${t.status === 'draft' ? 'Resume' : 'Start'} closing</a></div>`); }
  const p = F.me.popups?.payroll; if (p) out.push(`<div class="banner" role="status"><span style="font-size:24px" aria-hidden="true">◷</span><div class="grow"><b lang="hi">${esc(p.label)} की Salary Sheet अभी तैयार करनी है।</b> <span class="sm">Status: ${p.status === 'not_started' ? 'not started' : esc(p.status)}${p.progress && p.progress.total ? ` · ${p.progress.done} of ${p.progress.total} done` : ''}.</span></div><a class="btn primary" href="#/payroll/${p.period}/wizard">${p.status === 'not_started' ? 'Make salary sheet' : 'Continue pending salary'}</a></div>`);
  return out.join('');
}
/* ---------- Smart missing-entry assistant ---------- */
async function assistant(section, { force = false } = {}) {
  let r; try { r = await api('assist', { section }); } catch (e) { return null; }
  const items = r.items || []; if (!items.length) return r;
  const sig = section + ':' + items.map(i => i.code + i.msg).join('|').length + ':' + items.length;
  if (!force && sessionStorage.getItem('as:' + sig)) return r; sessionStorage.setItem('as:' + sig, '1');
  let i = 0; const m = modal({ title: 'Smart check · ' + section, sub: `${r.blocking ? `<b class="bad">${r.blocking} must be fixed</b> · ` : ''}${r.reviews} to review. Closing this does not mark anything as done.`, body: '<div id="asb"></div>', foot: '<button class="btn" id="asp">Previous</button><button class="btn" id="asl">Add later</button><button class="btn" id="asn">Next</button><button class="btn primary" id="asf">Fill now</button>' });
  const show = () => { const it = items[i]; $('#asb', m).innerHTML = `<div class="xs muted" style="margin-bottom:6px">${i + 1} of ${items.length}</div><div class="assist-item ${it.level}"><b>${it.level === 'block' ? '⛔ Must fix' : '⚠ Review'}</b><div style="margin-top:4px">${esc(it.msg)}</div></div>${items.length > 1 ? `<details><summary class="sm">See all ${items.length}</summary>${items.map(x => `<div class="assist-item ${x.level} sm">${esc(x.msg)}</div>`).join('')}</details>` : ''}`;
    $('#asp', m).disabled = i === 0; $('#asn', m).disabled = i === items.length - 1; $('#asf', m).disabled = !it.route; };
  $('#asp', m).onclick = () => { i--; show(); }; $('#asn', m).onclick = () => { i++; show(); }; $('#asl', m).onclick = () => m._close(); $('#asf', m).onclick = () => { const h = items[i].route; m._close(); go(h); };
  show(); return r;
}
const assistList = r => !r || !r.items?.length ? '' : `<div class="stack" style="gap:8px">${r.items.slice(0, 8).map(i => `<a class="assist-item ${i.level}" style="display:block;text-decoration:none;color:inherit" href="${i.route || '#/'}"><b>${i.level === 'block' ? '⛔' : '⚠'}</b> ${esc(i.msg)}</a>`).join('')}</div>`;
/* ---------- boot ---------- */
async function boot() {
  let me; try { const r = await fetch('f360-api.php?route=me', { credentials: 'same-origin' }); me = await r.json(); if (r.status === 401 || me.auth === false) return showLogin(); if (!r.ok) { document.body.innerHTML = `<main class="login"><div class="box"><img src="assets/brand/logo-full.png" alt="Khadak Singh Da Dhaba"><div class="alert danger">${esc(me.error || 'Server error')}</div>${/update is pending/.test(me.error || '') ? '<a class="btn primary block" href="migrate-f360.php">Open Finance 360 upgrade</a>' : ''}</div></main>`; return; } }
  catch (e) { document.body.innerHTML = '<main class="login"><div class="box"><img src="assets/brand/logo-full.png" alt="Khadak Singh Da Dhaba"><div class="alert danger">Cannot reach the server. Check the internet connection and reload.</div></div></main>'; return; }
  F.me = me; F.csrf = me.csrf;
  if (!me.perms.length) { document.body.innerHTML = `<main class="login"><div class="box" style="text-align:center"><img src="assets/brand/logo-full.png" alt="Khadak Singh Da Dhaba" width="200"><h1>Hello ${esc(me.user.name)}</h1><p class="muted">Your account has no Finance 360 access yet. Ask the owner to give you a role in <b>Users & Permissions</b>.</p><button class="btn primary block" data-a="logout">Sign out</button></div></main>`; return; }
  renderShell(); platformBar(me.platform); window.onhashchange = render; render();
}
/* V29: restaurant / branch / subscription bar (multi-restaurant mode only). Links go to server pages that re-check membership. */
function platformBar(p) {
  if (!p || !p.platform) return; const s = p.subscription || {}; const n = (p.memberships || []).reduce((a, t) => a + (t.branches || []).length, 0);
  const owner = (p.memberships || []).some(t => t.id === p.tenant.id && ['owner', 'admin'].includes(t.role));
  const warn = s.read_only ? `<b>Read-only:</b> ${esc(s.label)}. You can view and export; new entries need an active plan.` : (s.state === 'trialing' && s.days_left <= 7 ? `Free trial: ${s.days_left} day(s) left (ends ${esc(s.period_end_ist)}).` : (s.state === 'grace' ? 'Payment due — renew to avoid read-only mode.' : (s.renewal_warning ? `Subscription renewal pending: ${s.days_left} day(s) left (ends ${esc(s.period_end_ist)}).` : '')));
  document.body.insertAdjacentHTML('afterbegin', `<div class="f29bar" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:6px 12px;background:#191919;color:#fff;font-size:14px">
   ${p.tenant.logo ? `<img src="${esc(p.tenant.logo)}" alt="" width="24" height="24" style="border-radius:5px;background:#fff">` : ''}<b>${esc(p.tenant.name)}</b><span style="opacity:.8">· ${esc(p.branch.name)}</span>
   ${p.support_session ? '<span style="background:#b3261e;padding:1px 8px;border-radius:9px">Support view (read-only)</span>' : ''}<span style="flex:1"></span>
   ${n > 1 ? '<a href="choose.php?stay=1" style="color:#f3c9a4">Switch</a>' : ''}${owner ? '<a href="owner.php" style="color:#f3c9a4">Owner portal</a>' : ''}</div>
   ${warn ? `<div role="status" style="padding:8px 12px;background:${s.read_only ? '#fdecea' : '#fff3e6'};border-bottom:1px solid #f3c9a4">${warn}${owner ? ' <a href="owner.php?p=billing">Billing</a>' : ''}</div>` : ''}`);
}
async function refreshMe() { try { const r = await api('me'); F.me = r; F.csrf = r.csrf; } catch (e) { } }
document.addEventListener('DOMContentLoaded', boot);
if ('serviceWorker' in navigator && (location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(location.hostname))) window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => { }));
window.addEventListener('offline', () => toast('You are offline. Entries cannot be saved until the connection is back.', true));
window.addEventListener('online', () => toast('Back online.'));
