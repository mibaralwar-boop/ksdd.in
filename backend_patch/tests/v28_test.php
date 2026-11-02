<?php
declare(strict_types=1);
// Disposable local fixture only. Never reads the production config.
require_once dirname(__DIR__).'/f28/Service.php';
use Finance360\V28\Service;use Finance360\V28\Policy;use Finance360\V28\ApiError;
$p=new PDO('mysql:host=127.0.0.1;port=33070;dbname=finance360_test_v28;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if($p->query('SELECT DATABASE()')->fetchColumn()!=='finance360_test_v28')exit('Not the explicit disposable test schema');
$before=[];foreach(['dd_users','dd_employees','dd_bk_txns','dd_payroll','f3_closings','f3_pay_lines'] as $table){try{$before[$table]=hash('sha256',json_encode($p->query("SELECT * FROM $table ORDER BY 1")->fetchAll()));}catch(PDOException $e){}}
$p->exec('SET FOREIGN_KEY_CHECKS=0');foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t)if(str_starts_with($t,'f28_'))$p->exec('DROP TABLE `'.$t.'`');$p->exec('SET FOREIGN_KEY_CHECKS=1');
$sql=preg_replace('/^--.*$/m','',file_get_contents(dirname(__DIR__).'/f28/schema.sql'));foreach(explode(';',$sql) as $s)if(trim($s)!=='')$p->exec($s);
$now=new DateTimeImmutable('2026-11-02 00:30:00',new DateTimeZone('UTC'));$svc=new Service($p,fn()=>$now);
$passed=0;$failed=0;
function eq(mixed $got,mixed $want):void{if($got!==$want)throw new RuntimeException('Expected '.json_encode($want).', got '.json_encode($got));}
function err(callable $f,string $code):void{try{$f();}catch(ApiError $e){eq($e->error,$code);return;}throw new RuntimeException('Expected '.$code);}
function test(string $name,callable $f):void{global $passed,$failed;try{$f();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}
$input=fn($email,$name)=>['owner_name'=>'Fixture Owner','email'=>$email,'password'=>'FixturePassword!2468','restaurant_name'=>$name,'address'=>'Fixture address','city'=>'Alwar','branch_name'=>'Main','privacy_consent'=>true];
$a=$svc->register($input('a@fixture.test','Restaurant A'));$b=$svc->register($input('b@fixture.test','Restaurant B'));$aa=$svc->authenticate($a['access_token']);$ba=$svc->authenticate($b['access_token']);
$ctx=fn()=>$svc->context($aa,$a['tenant_id'],$a['branch_id']);
test('Default trial has 30 calendar days from authoritative timestamp',fn()=>eq($ctx()['subscription']['trial_ends_at'],'2026-12-02T00:30:00Z'));
test('7, 10 and 30 day admin policy grants',function()use($now){foreach([7,10,30] as $days)eq(Policy::trialEnd($now,$days)->getTimestamp()-$now->getTimestamp(),$days*86400);err(fn()=>Policy::trialEnd($now,20),'invalid_trial');});
test('Unconfigured provider never claims verification',fn()=>eq($ctx()['verification'],'manual_pending'));
test('Unverified account cannot mutate tenant metadata',fn()=>err(fn()=>$svc->branding($aa,$a['tenant_id'],$a['branch_id'],['name'=>'Denied','language'=>'en']),'permission_denied'));
test('No unverified/unfinished financial entitlement is advertised',function()use($ctx){eq(array_intersect($ctx()['permissions'],Policy::FINANCIAL),[]);eq($ctx()['financial_scope_state'],'blocked');});
test('Tenant A token cannot select tenant B',fn()=>err(fn()=>$svc->context($aa,$b['tenant_id'],$b['branch_id']),'scope_denied'));
test('Branch B cannot be paired with tenant A',fn()=>err(fn()=>$svc->context($aa,$a['tenant_id'],$b['branch_id']),'scope_denied'));
test('Membership directory contains only assigned tenant',function()use($svc,$aa,$a){$r=$svc->memberships($aa);eq(count($r['memberships']),1);eq((int)$r['memberships'][0]['tenant_id'],$a['tenant_id']);});
test('SQL-like IDs rejected before queries',fn()=>err(fn()=>$svc->context($aa,'1 OR 1=1',$a['branch_id']),'invalid_id'));
test('Duplicate email registration atomically rolls back',function()use($svc,$input,$p){$count=$p->query('SELECT COUNT(*) FROM f28_tenants')->fetchColumn();err(fn()=>$svc->register($input('a@fixture.test','Duplicate')),'registration_conflict');eq($p->query('SELECT COUNT(*) FROM f28_tenants')->fetchColumn(),$count);});
test('Privacy consent required before account creation',function()use($svc,$input){$i=$input('c@fixture.test','C');$i['privacy_consent']=false;err(fn()=>$svc->register($i),'consent_required');});
test('Password stored as a hash; tokens stored as hashes',function()use($p,$a){$hash=$p->query("SELECT password_hash FROM f28_accounts WHERE email='a@fixture.test'")->fetchColumn();eq(password_verify('FixturePassword!2468',$hash),true);$q=$p->prepare('SELECT COUNT(*) FROM f28_tokens WHERE token_hash=?');$q->execute([$a['access_token']]);eq((int)$q->fetchColumn(),0);});
$svc->verifyAccount((int)$aa['id'],'fixture verification evidence');$aa=$svc->authenticate($a['access_token']);
test('Verified owner can update only its restaurant branding',function()use($svc,$aa,$a,$ba,$b){$svc->branding($aa,$a['tenant_id'],$a['branch_id'],['name'=>'A Updated','language'=>'hi']);eq($svc->context($aa,$a['tenant_id'],$a['branch_id'])['restaurant_name'],'A Updated');eq($svc->context($ba,$b['tenant_id'],$b['branch_id'])['restaurant_name'],'Restaurant B');});
test('Branch cap enforced on server',fn()=>err(fn()=>$svc->createBranch($aa,$a['tenant_id'],$a['branch_id'],['name'=>'Second','address'=>'Address']),'branch_limit'));
$p->prepare('UPDATE f28_subscriptions SET branch_limit=2 WHERE tenant_id=?')->execute([$a['tenant_id']]);
test('Branch creation grants creator explicit branch membership',function()use($svc,$aa,$a){$r=$svc->createBranch($aa,$a['tenant_id'],$a['branch_id'],['name'=>'Second','address'=>'Address']);eq($svc->context($aa,$a['tenant_id'],$r['id'])['branch_name'],'Second');eq(count($svc->branches($aa,$a['tenant_id'],$a['branch_id'])['branches']),2);});
test('Explicit deny overrides owner role immediately',function()use($p,$svc,$aa,$a){$p->prepare("UPDATE f28_memberships SET denies_json='[\"tenant.branding\"]',revision=revision+1 WHERE tenant_id=? AND account_id=?")->execute([$a['tenant_id'],$aa['id']]);err(fn()=>$svc->branding($aa,$a['tenant_id'],$a['branch_id'],['name'=>'Denied','language'=>'en']),'permission_denied');});
test('Expiry boundary blocks mutation but preserves authorized reads',function()use($p,$svc,$aa,$a,$now){$p->prepare('UPDATE f28_subscriptions SET trial_ends_at=? WHERE tenant_id=?')->execute([$now->format('Y-m-d H:i:s'),$a['tenant_id']]);$c=$svc->context($aa,$a['tenant_id'],$a['branch_id']);eq($c['subscription']['state'],'expired');eq(in_array('tenant.view',$c['permissions'],true),true);eq(in_array('branches.create',$c['permissions'],true),false);});
test('Suspended subscription has no module permissions',function()use($p,$svc,$aa,$a){$p->prepare("UPDATE f28_subscriptions SET state='suspended' WHERE tenant_id=?")->execute([$a['tenant_id']]);eq($svc->context($aa,$a['tenant_id'],$a['branch_id'])['permissions'],[]);});
test('Cancelled paid subscription never returns active',fn()=>eq(Policy::state(['state'=>'cancelled','paid_until'=>'2099-01-01'],$now),'cancelled'));
test('Financial endpoints fail closed',fn()=>err(fn()=>$svc->financialUnavailable(),'financial_migration_pending'));
test('Refresh rotation invalidates old access token',function()use($svc,$b){$r=$svc->refresh(['refresh_token'=>$b['refresh_token']]);err(fn()=>$svc->authenticate($b['access_token']),'unauthenticated');eq(isset($svc->authenticate($r['access_token'])['id']),true);$GLOBALS['rotated']=$r;});
test('Refresh replay revokes entire token family',function()use($svc,$b){err(fn()=>$svc->refresh(['refresh_token'=>$b['refresh_token']]),'unauthenticated');err(fn()=>$svc->authenticate($GLOBALS['rotated']['access_token']),'unauthenticated');});
test('Logout revokes server session',function()use($svc){$r=$svc->login(['email'=>'b@fixture.test','password'=>'FixturePassword!2468']);$u=$svc->authenticate($r['access_token']);$svc->logout($u);err(fn()=>$svc->authenticate($r['access_token']),'unauthenticated');});
test('User revocation is checked on existing token',function()use($p,$svc,$aa,$a){$p->prepare('UPDATE f28_memberships SET active=0 WHERE tenant_id=? AND account_id=?')->execute([$a['tenant_id'],$aa['id']]);err(fn()=>$svc->context($aa,$a['tenant_id'],$a['branch_id']),'scope_denied');});
test('Account deletion request is idempotent and does not delete finance',function()use($svc,$aa,$p){$svc->deleteRequest($aa);$svc->deleteRequest($aa);eq((int)$p->query('SELECT COUNT(*) FROM f28_deletion_requests')->fetchColumn(),1);});
test('Failed login attempts are rate limited',function()use($svc){for($i=0;$i<10;$i++)err(fn()=>$svc->login(['email'=>'unknown@fixture.test','password'=>'wrong']),'invalid_credentials');err(fn()=>$svc->login(['email'=>'unknown@fixture.test','password'=>'wrong']),'rate_limited');});
test('Legacy financial rows unchanged by SaaS metadata and auth tests',function()use($before,$p){if(!$before)throw new RuntimeException('Baseline fixture tables are missing');foreach($before as $table=>$hash)eq(hash('sha256',json_encode($p->query("SELECT * FROM $table ORDER BY 1")->fetchAll())),$hash);});
require_once dirname(__DIR__).'/f28/Platform.php';
$platform=new Finance360\V28\Platform($p,str_repeat('test-only-key',4));
$totpSecret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
test('TOTP matches RFC6238 SHA1 vector truncated to six digits',fn()=>eq(Finance360\V28\Platform::code($totpSecret,1),'287082'));
test('Tenant owner role does not imply platform administrator',fn()=>err(fn()=>$platform->tenants($ba),'stepup_required'));
$p->prepare('INSERT INTO f28_platform_admins(account_id,totp_secret_enc) VALUES(?,?)')->execute([$aa['id'],$platform->seal($totpSecret)]);
test('Platform membership still requires MFA step-up',fn()=>err(fn()=>$platform->tenants($aa),'stepup_required'));
$otp=Finance360\V28\Platform::code($totpSecret,intdiv(time(),30));
test('MFA unlocks short-lived platform metadata administration',function()use($platform,$aa,$otp){eq($platform->stepup($aa,['code'=>$otp])['verified'],true);eq(count($platform->tenants($aa)['tenants']),2);});
test('Replayed authenticator code is refused',fn()=>err(fn()=>$platform->stepup($aa,['code'=>$otp]),'mfa_invalid'));
test('Admin trial extension is audited and scoped to target tenant',function()use($platform,$aa,$b,$p){$r=$platform->trial($aa,['tenant_id'=>$b['tenant_id'],'days'=>7,'reason'=>'Approved fixture extension']);eq(isset($r['trial_ends_at']),true);eq((int)$p->query("SELECT COUNT(*) FROM f28_audit WHERE action='platform.trial_extended'")->fetchColumn(),1);});
test('Admin cannot silently extend a suspended account',fn()=>err(fn()=>$platform->trial($aa,['tenant_id'=>$a['tenant_id'],'days'=>30,'reason'=>'Fixture']),'invalid_transition'));
test('MFA administrator may suspend target with audit reason',fn()=>eq($platform->suspend($aa,['tenant_id'=>$b['tenant_id'],'reason'=>'Fixture suspension'])['state'],'suspended'));
test('Platform revocation invalidates existing MFA step-up',function()use($p,$platform,$aa){$p->prepare('UPDATE f28_platform_admins SET active=0 WHERE account_id=?')->execute([$aa['id']]);err(fn()=>$platform->tenants($aa),'stepup_required');});
echo "RESULT passed=$passed failed=$failed\n";exit($failed?1:0);
