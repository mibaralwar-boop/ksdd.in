"""Local fixture API tests. Use only the disposable PHP server created by the supplied test harness."""
import json, urllib.request, urllib.error
BASE='http://127.0.0.1:18828/f28-api.php?route='
passed=0

def call(route,body=None,token=None,scope=None,content='application/json',raw=None):
    headers={'Accept':'application/json'}
    if token:headers['Authorization']='Bearer '+token
    if scope:headers.update({'X-Tenant-ID':str(scope[0]),'X-Branch-ID':str(scope[1])})
    data=raw if raw is not None else json.dumps(body).encode() if body is not None else None
    if data is not None:headers['Content-Type']=content
    r=urllib.request.Request(BASE+'/v1/'+route,data=data,headers=headers)
    try:
        with urllib.request.urlopen(r,timeout=10) as response:return response.status,json.loads(response.read())
    except urllib.error.HTTPError as e:return e.code,json.loads(e.read())

def check(name,got,want):
    global passed
    if got!=want:raise AssertionError(f'{name}: expected {want}, got {got}')
    passed+=1;print('PASS '+name,flush=True)

check('Unknown route',call('not-found')[0],404)
check('Wrong HTTP method',call('register')[0],405)
check('Non-JSON content rejected',call('register',{},content='text/plain')[0],415)
check('Malformed JSON rejected',call('register',raw=b'{bad')[0],400)
check('JSON list is not an object',call('register',raw=b'[]')[0],400)
check('Oversized body rejected',call('register',raw=b'{'+b'x'*32769)[0],413)
body={'owner_name':'HTTP Fixture','email':'http@fixture.test','password':'FixturePassword!2468','restaurant_name':'HTTP Restaurant','address':'Fixture address','city':'Alwar','privacy_consent':True}
status,r=call('register',body);check('Atomic HTTP registration',status,201);session=r['data'];token=session['access_token'];scope=(session['tenant_id'],session['branch_id'])
check('Duplicate HTTP signup',call('register',body)[0],409)
check('Membership listing',call('memberships',token=token)[0],200)
status,r=call('context',token=token,scope=scope);check('Scoped context',status,200);check('Manual verification transparently pending',r['data']['verification'],'manual_pending')
check('Tenant ID cannot override membership',call('context',token=token,scope=(999999,scope[1]))[0],403)
check('Financial APIs fail closed',call('financial/status',token=token,scope=scope)[0],503)
check('Tenant owner cannot read platform metadata',call('platform/tenants',token=token)[0],403)
check('Empty JSON object is accepted for logout',call('logout',{},token=token)[0],200)
check('Logout invalidates token',call('memberships',token=token)[0],401)
check('Logout invalidates refresh family',call('refresh',{'refresh_token':session['refresh_token']})[0],401)
print(f'RESULT passed={passed} failed=0',flush=True)
