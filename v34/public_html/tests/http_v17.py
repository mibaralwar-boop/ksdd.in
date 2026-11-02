#!/usr/bin/env python3
"""Finance 360 V17 — HTTP tests for Purchases / Suppliers / Stock against a STAGING server (never production).
Usage: BASE=http://127.0.0.1:8360 python3 tests/http_v17.py   (run after tests/run_tests.php has rebuilt staging)"""
import json, os, sys, base64, threading, http.cookiejar, urllib.request, urllib.error, uuid

BASE = os.environ.get('BASE', 'http://127.0.0.1:8360'); P = F = 0

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class S:
    def __init__(self, email=None, pw=None):
        self.cj = http.cookiejar.CookieJar(); self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj)); self.csrf = ''
        if email:
            st = self.raw('GET', BASE + '/api.php?route=status')[1]; c = json.loads(st)['csrf']
            self.raw('POST', BASE + '/api.php?route=login', {'email': email, 'password': pw}, c)
            self.csrf = json.loads(self.raw('GET', BASE + '/f360-api.php?route=me')[1]).get('csrf', '')
    def raw(self, m, url, body=None, csrf=None):
        rq = urllib.request.Request(url, method=m, data=None if body is None else json.dumps(body).encode(), headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf if csrf is not None else self.csrf})
        try:
            r = self.op.open(rq, timeout=60); return r.status, r.read(), r.headers.get('Content-Type', '')
        except urllib.error.HTTPError as e: return e.code, e.read(), e.headers.get('Content-Type', '')
    def get(self, route): s, b, _ = self.raw('GET', BASE + '/f360-api.php?route=' + route); return s, (json.loads(b) if b[:1] in (b'{', b'[') else b)
    def post(self, route, body): s, b, _ = self.raw('POST', BASE + '/f360-api.php?route=' + route, body); return s, json.loads(b) if b[:1] == b'{' else b

def check(name, want, got, extra=''):
    global P, F
    if got == want: P += 1; print(f'  PASS {name} ({got})')
    else: F += 1; print(f'  FAIL {name} expected {want} got {got} {extra}')

own = S('owner@staging.test', 'OwnerPass#2026'); ctr = S('counter@staging.test', 'StagingPass#2026'); store = S('store@staging.test', 'StagingPass#2026'); anon = S()
print('== V17 routes: who can reach what ==')
for r in ['suppliers', 'items', 'bills', 'stock', 'aging', 'purchase-register', 'stock-moves', 'stock-counts', 'units', 'stock-valuation']:
    check(f'owner GET {r}', 200, own.get(r)[0])
for r in ['suppliers', 'items', 'bills', 'stock', 'aging', 'purchase-register', 'stock-moves', 'stock-valuation', 'item&id=1', 'bill&id=1']:
    check(f'counter GET {r} → 403', 403, ctr.get(r)[0])
for r in ['bill-save', 'bill-submit', 'bill-approve', 'bill-receive', 'bill-reverse', 'supplier-save', 'supplier-pay', 'supplier-refund', 'return-save', 'item-save', 'stock-move', 'count-start', 'count-approve']:
    check(f'counter POST {r} → 403', 403, ctr.post(r, {'id': 1, 'client_key': 'k' + uuid.uuid4().hex[:12]})[0])
check('anonymous GET bills → 401', 401, anon.get('bills')[0])
for r in ['items', 'bills', 'suppliers', 'stock']: check(f'store clerk GET {r}', 200, store.get(r)[0])
check('store clerk GET stock-valuation → 403 (no cost access)', 403, store.get('stock-valuation')[0])
check('store clerk POST supplier-pay → 403', 403, store.post('supplier-pay', {'client_key': 'k' + uuid.uuid4().hex[:12], 'supplier_id': 1, 'amount': '1'})[0])
s, it = store.get('items'); check('store clerk sees no stock value fields', False, any('value' in i for i in it.get('items', [])))

print('== Bill via API, photo access, upload validation ==')
uid = uuid.uuid4().hex[:6]
s, sup = own.post('supplier-save', {'name': f'DEMO HTTP Supplier {uid}', 'phone': ''}); check('create supplier', 200, s); sid = sup.get('id')
s, item = own.post('item-save', {'name': f'DEMO HTTP Paneer {uid}', 'unit': 'kg', 'category': 'Dairy'}); check('create item', 200, s); iid = item.get('id')
png = base64.b64encode(open(os.path.join(os.path.dirname(__file__), '..', 'assets', 'brand', 'icon-192.png'), 'rb').read()).decode()
s, d = own.post('bill-save', {'supplier_id': sid, 'invoice_no': 'H-' + uid, 'invoice_date': '2026-11-01', 'lines': [{'item_id': iid, 'unit': 'kg', 'qty': '10', 'rate': '300'}], 'files_add': [{'data': png, 'name': 'bill.png', 'kind': 'bill'}]})
check('save draft with bill photo', 200, s); bid = d.get('id'); fid = (d.get('files') or [{}])[0].get('file_id')
check('bad upload (text as photo) → 400', 400, own.post('bill-save', {'id': bid, 'version': d['bill']['version'], 'supplier_id': sid, 'lines': [], 'files_add': [{'data': base64.b64encode(b'<?php system($_GET[1]);').decode(), 'name': 'x.php'}]})[0])
check('oversize upload → 413', 413, own.post('bill-attach', {'id': bid, 'files_add': [{'data': base64.b64encode(b'\xff' * 1600000).decode(), 'name': 'big.jpg'}]})[0])
check('stale draft version → 409', 409, own.post('bill-save', {'id': bid, 'version': 999, 'supplier_id': sid, 'lines': []})[0])
st, body, ct = own.raw('GET', f'{BASE}/f360-api.php?route=thumb&id={fid}'); check('owner thumbnail → 200 image/jpeg', (200, 'image/jpeg'), (st, ct.split(';')[0]))
check('counter opens bill photo → 403', 403, ctr.raw('GET', f'{BASE}/f360-api.php?route=file&id={fid}')[0])
check('counter thumbnail → 403', 403, ctr.raw('GET', f'{BASE}/f360-api.php?route=thumb&id={fid}')[0])
check('anonymous photo → 401', 401, anon.raw('GET', f'{BASE}/f360-api.php?route=file&id={fid}')[0])
check('private upload folder not web-served', True, own.raw('GET', f'{BASE}/private/uploads/')[0] in (403, 404))
s, r = own.post('bill-submit', {'id': bid, 'client_key': 'sub-' + uid + 'aaaa'}); check('post bill (no receive)', 'posted', r.get('status'))
s, b = own.get(f'bill&id={bid}'); lid = b['lines'][0]['id']

print('== Concurrency ==')
res = []
def recv(i):
    res.append(own.post('bill-receive', {'id': bid, 'client_key': f'rcv-{uid}-{i:04d}', 'lines': [{'line_id': lid, 'qty': '4'}]})[0])
th = [threading.Thread(target=recv, args=(i,)) for i in range(6)]; [t.start() for t in th]; [t.join() for t in th]
check('6 parallel receipts of 4 kg on a 10 kg line → exactly 2 succeed', 2, res.count(200), str(res))
s, b = own.get(f'bill&id={bid}'); check('received quantity never exceeds ordered (8 of 10)', 8.0, b['lines'][0]['received_base'])
s, stk = own.get(f'item&id={iid}'); check('stock = 8 kg', 8.0, stk['item']['qty'])
res2 = []; key = 'pay-' + uid + '-same'
def pay(): res2.append(own.post('supplier-pay', {'client_key': key, 'supplier_id': sid, 'amount': '500', 'mode': 'Cash', 'allocations': [{'bill_id': bid, 'amount': '500'}], 'date': '2026-11-01'}))
th = [threading.Thread(target=pay) for _ in range(5)]; [t.start() for t in th]; [t.join() for t in th]
s, b = own.get(f'bill&id={bid}'); check('5 parallel submits of one payment → paid once (₹500)', 50000, b['bill']['paid'], str([x[0] for x in res2]))
res3 = []
def pay2(i): res3.append(own.post('supplier-pay', {'client_key': f'pay-{uid}-{i:03d}', 'supplier_id': sid, 'amount': '2000', 'mode': 'Cash', 'allocations': [{'bill_id': bid, 'amount': '2000'}], 'date': '2026-11-01'})[0])
th = [threading.Thread(target=pay2, args=(i,)) for i in range(4)]; [t.start() for t in th]; [t.join() for t in th]
s, b = own.get(f'bill&id={bid}'); check('4 parallel ₹2,000 payments on ₹2,500 due → bill never over-allocated', True, b['bill']['paid'] <= b['bill']['grand_total'], f"paid {b['bill']['paid']} {res3}")

print('== Old modules archived (V17.1) ==')
chef = S('chef@staging.test', 'StagingPass#2026')
def raw(sess, path, m='GET', body=None): return sess.raw(m, BASE + '/' + path, body)[0]
for path in ['books-api.php?r=boot', 'books-api.php?r=daybook', 'salary-api.php?r=boot', 'api.php?route=data', 'api.php?route=crm-home']:
    check(f'counter GET {path} → 403', 403, raw(ctr, path))
    check(f'store clerk GET {path} → 403', 403, raw(store, path))
for path in ['books-api.php?r=boot', 'salary-api.php?r=boot', 'api.php?route=data']: check(f'owner (archive) GET {path} → 200', 200, raw(own, path))
check('owner POST to old Books → 403 read-only', 403, raw(own, 'books-api.php?r=txn-save', 'POST', {'type': 'expense'}))
check('owner POST to old CRM → 403 read-only', 403, raw(own, 'api.php?route=crm-expense', 'POST', {}))
check('owner POST to old Salary → 403 read-only', 403, raw(own, 'salary-api.php?r=entry-save', 'POST', {}))
for page in ['legacy.php', 'books.php', 'salary.php']:
    rq = urllib.request.Request(BASE + '/' + page); op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(ctr.cj), NoRedirect())
    try: st = op.open(rq).status
    except urllib.error.HTTPError as e: st = e.code
    check(f'counter opens {page} → redirected to Finance 360 (302)', 302, st)
    check(f'owner opens {page} → 200 archive', 200, raw(own, page))
    rq = urllib.request.Request(BASE + '/' + page); op = urllib.request.build_opener(NoRedirect())
    try: st = op.open(rq).status
    except urllib.error.HTTPError as e: st = e.code
    check(f'anonymous {page} → 302', 302, st)
check('Finance 360 login still works for everyone (api.php status)', 200, raw(anon, 'api.php?route=status'))
s2, me = chef.get('me'); check('chef (kitchen) now gets Store clerk access', True, 'purchase.create' in me.get('perms', []))
check('owner me shows archive link', True, own.get('me')[1].get('archive'))
check('counter me has no archive link', False, ctr.get('me')[1].get('archive'))
check('owner Update ledger → 200', 200, own.post('gl-sync', {})[0]); check('owner ledger items to decide → 200', 200, own.get('gl-pending')[0])
check('counter Update ledger → 403', 403, ctr.post('gl-sync', {})[0])

print('== V17 exports (PDF / XLSX / CSV) ==')
for k in [f'supplier-statement&id={sid}', 'purchase-register&from=2026-11-01&to=2026-11-01', 'aging', 'stock-valuation&to=2026-11-01', 'stock-moves&from=2026-10-01&to=2026-11-01']:
    st, body, ct = own.raw('GET', f'{BASE}/f360-api.php?route=export&kind={k}&format=pdf'); check(f'owner export {k.split("&")[0]} PDF', (200, True), (st, body[:5] == b'%PDF-'))
    st, body, ct = own.raw('GET', f'{BASE}/f360-api.php?route=export&kind={k}&format=xlsx'); check(f'owner export {k.split("&")[0]} XLSX', (200, True), (st, body[:2] == b'PK'))
    check(f'counter export {k.split("&")[0]} → 403', 403, ctr.raw('GET', f'{BASE}/f360-api.php?route=export&kind={k}&format=csv')[0])
print('== Admin control centre (V17.2) ==')
check('owner GET control', 200, own.get('control')[0]); check('counter GET control → 403', 403, ctr.get('control')[0]); check('store clerk GET control → 403', 403, store.get('control')[0])
check('counter POST control-save → 403', 403, ctr.post('control-save', {'section': 'business', 'name': 'Hack'})[0])
mgr = S('manager@staging.test', 'StagingPass#2026')
check('manager (not owner) cannot switch modules → 403', 403, mgr.post('control-save', {'section': 'modules', 'modules': {'purchases': 0}})[0])
check('manager (not owner) cannot create roles → 403', 403, mgr.post('control-save', {'section': 'role_save', 'label': 'Hack role', 'perms': ['users.manage']})[0])
check('unknown section → 404', 404, own.post('control-save', {'section': 'drop-tables'})[0])
s9, me9 = own.get('me'); check('me carries business / packs / messages / modules', True, all(k in me9 for k in ['business', 'packs', 'messages', 'modules', 'cost_centres']))
print('== Closed day ==')
s, cl = own.get('pending-closings')
check('cash payment on a closed day → 409', 409, own.post('supplier-pay', {'client_key': 'pay-' + uid + '-closed', 'supplier_id': sid, 'amount': '1', 'mode': 'Cash', 'date': '2026-10-31', 'allocate': 'none'})[0])
print(f'\nRESULT: {P} passed, {F} failed'); sys.exit(1 if F else 0)
