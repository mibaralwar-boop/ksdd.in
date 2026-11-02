'use strict';
let tokens = null;
const element = id => document.getElementById(id);
const status = message => { element('status').textContent = message; };
const show = id => { for (const item of ['login','mfa','workspace']) element(item).hidden = item !== id; };
async function request(route, body, auth = true) {
  const headers = {'Accept':'application/json'};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (auth) {
    if (!tokens) throw new Error('Sign in again.');
    headers.Authorization = `Bearer ${tokens.access_token}`;
  }
  const response = await fetch(`f28-api.php?route=${encodeURIComponent('/v1/' + route)}`, {
    method: body === undefined ? 'GET' : 'POST', headers,
    body: body === undefined ? undefined : JSON.stringify(body), credentials:'omit',cache:'no-store',redirect:'error'
  });
  let result;
  try { result = await response.json(); } catch (_) { throw new Error('Server response could not be read.'); }
  if (!response.ok) {
    if (response.status === 401) { tokens = null; show('login'); }
    if (result.error?.code === 'stepup_required') show('mfa');
    throw new Error(result.error?.message || 'Request failed.');
  }
  return result.data;
}
async function action(button, work) {
  if (button.disabled) return;
  button.disabled = true; status('Working…');
  try { await work(); } catch (error) { status(error.message); }
  finally { button.disabled = false; }
}
element('login-form').addEventListener('submit', event => {
  event.preventDefault(); action(event.submitter, async () => {
    tokens = await request('login', {email:element('email').value.trim(),password:element('password').value}, false);
    element('password').value = ''; show('mfa'); status('Enter your authenticator code.');
  });
});
element('mfa-form').addEventListener('submit', event => {
  event.preventDefault(); action(event.submitter, async () => {
    await request('platform/step-up',{code:element('code').value}); element('code').value=''; await load();
  });
});
async function load() {
  const data = await request('platform/tenants');
  const container = element('tenants');container.replaceChildren();
  for (const tenant of data.tenants) {
    const card = document.createElement('article');card.className='tenant';
    const title = document.createElement('h3');title.textContent=`${tenant.name} · #${tenant.id}`;card.append(title);
    const detail = document.createElement('p');detail.textContent=`${tenant.city} · ${tenant.state} · trial ends ${tenant.trial_ends_at} UTC · branches ${tenant.branch_limit}`;card.append(detail);
    const form = document.createElement('form');
    const days=document.createElement('select');days.setAttribute('aria-label','Trial extension days');
    for(const count of [7,10,30]){const option=document.createElement('option');option.value=count;option.textContent=`${count} days`;days.append(option);}
    const reason=document.createElement('input');reason.placeholder='Reason / evidence reference';reason.setAttribute('aria-label','Reason');reason.maxLength=500;reason.required=true;
    const extend=document.createElement('button');extend.type='submit';extend.textContent='Extend trial';
    const suspend=document.createElement('button');suspend.type='button';suspend.textContent='Suspend';
    form.append(days,reason,extend,suspend);card.append(form);
    form.addEventListener('submit',event=>{event.preventDefault();action(extend,async()=>{await request('platform/trial',{tenant_id:Number(tenant.id),days:Number(days.value),reason:reason.value});await load();});});
    suspend.addEventListener('click',()=>{
      if(!reason.reportValidity()||!window.confirm(`Suspend ${tenant.name}? This blocks tenant access.`))return;
      action(suspend,async()=>{await request('platform/suspend',{tenant_id:Number(tenant.id),reason:reason.value});await load();});
    });
    container.append(card);
  }
  show('workspace');status(`${data.tenants.length} restaurants shown; limit ${data.limit}. MFA expires after five minutes.`);
}
element('refresh').addEventListener('click',event=>action(event.currentTarget,load));
element('logout').addEventListener('click',event=>action(event.currentTarget,async()=>{
  try { await request('logout',{}); } finally { tokens=null;element('tenants').replaceChildren();show('login');status('Local session cleared.'); }
}));
