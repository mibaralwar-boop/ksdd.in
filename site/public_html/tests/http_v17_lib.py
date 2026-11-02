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


def result():
    print(f"\nRESULT: {P} passed, {F} failed"); sys.exit(1 if F else 0)
