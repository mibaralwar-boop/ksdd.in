<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
require_once __DIR__.'/platform/mt.php'; // V31: single-database tenancy (scoped views + MtPDO)
ini_set('display_errors','0');
ini_set('log_errors','1');
const ENTRY_TYPES = ['sale','expense','payment-in','payment-out','salary','advance','deposit','withdrawal','refund','settlement'];
const ACCOUNTS = ['Cash','Paytm','Bank of India','Card','Other bank','Unallocated'];
// CRM 360: deny-by-default permission map. Every route checks these server-side (see lib360.php for staff permissions).
const ROLES = [
 'owner'=>['*','read','write','manage','close','payroll'],
 'manager'=>['read','write','close','payroll','inbox','approve','cash.review','kitchen.view','store.view','purchase.view','purchase.order','vendor','reports','drivers','checklist','problem.report','self','kitchen.usage','voucher.request','stock.report'],
 'accountant'=>['read','write','close','payroll','inbox','approve','cash.review','vendor','reports','store.view','purchase.view','drivers','self','voucher.request','stock.report'],
 'cashier'=>['cash.entry','cash.count','cash.handover','cash.submit','voucher.pay','voucher.request','drivers','problem.report','self'],
 'chef'=>['kitchen.request','kitchen.usage','problem.report','self'],
 'storekeeper'=>['store.view','store.in','store.out','store.count','bill.submit','kitchen.verify','kitchen.usage','stock.report','problem.report','self'],
 'counter'=>['expense.submit','problem.report','self'],
 'purchase'=>['purchase.view','purchase.order','store.view','problem.report','self'],
 'housekeeping'=>['checklist','problem.report','self'],
 'employee'=>['problem.report','self'],
];
const ROLE_LABELS = ['owner'=>'Owner / Admin','manager'=>'Manager','accountant'=>'Accounts','cashier'=>'Cashier','chef'=>'Head Chef','storekeeper'=>'Storekeeper','counter'=>'Counter Staff','purchase'=>'Purchase Staff','housekeeping'=>'Housekeeping','employee'=>'Employee'];
function cfg(): array { static $c; if ($c !== null) return $c;
    // Test harness only: F360_CONFIG may point at a staging config, and only for the CLI and PHP's built-in dev server (never LiteSpeed/Apache).
    $alt = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? (string)getenv('F360_CONFIG') : '';
    if ($alt !== '' && is_file($alt)) { $c = require $alt; if (!preg_match('/(staging|test)/', (string)(!empty($c['single_db']) ? ($c['db_name'] ?? '') : ($c['platform']['db']['db_name'] ?? $c['db_name'] ?? '')))) { $c = []; fwrite(STDERR, "F360_CONFIG refused: database names must contain staging/test\n"); } return $c; }
    // V34: secrets live OUTSIDE the web root: …/domains/<domain>/ksdd_private/config.php (sibling of public_html).
    // public_html/config.php is still read for backward compatibility only (denied by .htaccess); move it out.
    // A web root nested inside another site (e.g. staging.ksdd.in in public_html/staging) names its private folder with
    // SetEnv F360_PRIVATE_DIR /home/…/staging_private in its .htaccess (CLI: export F360_PRIVATE_DIR=…).
    $env = (string)getenv('F360_PRIVATE_DIR');
    foreach ([$env !== '' ? rtrim($env, '/').'/config.php' : '', dirname(__DIR__).'/ksdd_private/config.php', __DIR__.'/config.php'] as $f) if ($f !== '' && is_file($f)) return $c = require $f;
    return $c = []; }
/** V34: ONE resolver for private storage (uploads, backups, exports, imports, logs, platform files, clocks): the
 *  …/ksdd_private folder outside the web root when it exists (or config 'private_dir'); legacy public_html/private otherwise. */
function private_root(): string {
    static $r; if ($r !== null) return $r;
    $c = cfg(); if (!empty($c['private_dir']) && is_dir((string)$c['private_dir'])) return $r = rtrim((string)$c['private_dir'], '/');
    $env = (string)getenv('F360_PRIVATE_DIR'); if ($env !== '' && is_dir($env)) return $r = rtrim($env, '/');
    return $r = is_dir(dirname(__DIR__).'/ksdd_private') ? dirname(__DIR__).'/ksdd_private' : __DIR__.'/private';
}
/** True when private storage is outside the document root (health checks warn otherwise). */
function private_outside_webroot(): bool { return !str_starts_with(realpath(private_root()) ?: private_root(), (realpath(__DIR__) ?: __DIR__) . '/'); }
function configured(): bool { $c=cfg(); if(!empty($c['platform']['enabled'])&&empty($c['single_db'])&&!empty($c['platform']['db']['db_name'])) return true; /* V29 layout; V31 uses the main db_* below */ return !empty($c['db_name']) && !str_starts_with($c['db_name'],'YOUR_') && !empty($c['db_user']) && !str_starts_with($c['db_user'],'YOUR_'); }
/** Typed-in text and passed-in values both get the database's own collation (avoids error 1267 on hosts whose connection default differs). */
function db_session_collation(PDO $p): void { $col=(string)$p->query('SELECT @@collation_database')->fetchColumn(); $p->exec(preg_match('/^utf8mb4_[a-z0-9_]+$/',$col) ? "SET NAMES utf8mb4 COLLATE $col" : "SET NAMES utf8mb4"); }
/* V29 multi-restaurant: when config.php has a 'platform' block, every request is routed to the ONE branch ledger database
   that the platform layer selected from the signed-in user's verified membership (platform/tenancy.php). There is no
   fallback to the default database in platform mode — an unresolved context is an error, never another tenant's data.
   Without a 'platform' block the app behaves exactly as before (single restaurant, config.php database). */
function platform_mode(): bool { return !empty(cfg()['platform']['enabled']); }
function db_target(): array {
    if (isset($GLOBALS['F360_TARGET'])) return $GLOBALS['F360_TARGET'];
    if (platform_mode()) {
        if (!function_exists('tenancy_resolve_session')) require_once __DIR__.'/platform/tenancy.php';
        return $GLOBALS['F360_TARGET'] = tenancy_resolve_session();
    }
    $c=cfg();
    // V31: a converted (single-database) install without the platform layer = the founding restaurant only (emergency single mode).
    if (mt_enabled()) { $sc=(array)($c['single_db_scope']??[]); if(empty($sc['tenant_id'])||empty($sc['branch_id'])) fail('single_db_scope (tenant_id, branch_id) is missing in config.php.',503);
        return ['db_host'=>$c['db_host']??'localhost','db_port'=>$c['db_port']??3306,'db_name'=>$c['db_name']??'','db_user'=>$c['db_user']??'','db_password'=>$c['db_password']??'','storage'=>(string)($sc['storage']??''),'tenant_id'=>(int)$sc['tenant_id'],'branch_id'=>(int)$sc['branch_id'],'branch_uid'=>(string)($sc['branch_uid']??'')]; }
    return ['db_host'=>$c['db_host']??'localhost','db_port'=>$c['db_port']??3306,'db_name'=>$c['db_name']??'','db_user'=>$c['db_user']??'','db_password'=>$c['db_password']??'','storage'=>'','tenant_id'=>null,'branch_id'=>null];
}
/** Switch the request/CLI to another branch ledger (cron, provisioning, consolidation). Clears every per-database cache. */
function db_switch(?array $target): void {
    $GLOBALS['F360_PDO']=null; $GLOBALS['F360_TARGET']=$target; $GLOBALS['F3_PERM_CACHE']=[]; $GLOBALS['F360_SETTINGS']=[];
    if ($target===null) unset($GLOBALS['F360_TARGET']);
}
function db_connect_target(array $t): PDO {
    $host=(string)($t['db_host']??'localhost');
    if (!preg_match('/^[a-zA-Z0-9.:-]+$/',$host) || !preg_match('/^[a-zA-Z0-9_]+$/',(string)($t['db_name']??''))) fail('Invalid database configuration.',503);
    $p = new MtPDO('mysql:host='.$host.';port='.(int)($t['db_port']??3306).';dbname='.$t['db_name'].';charset=utf8mb4',(string)$t['db_user'],(string)$t['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
    // V34: a brand-new connection starts with NO restaurant scope — clears any stale context row left behind by a crashed
    // process whose (recycled) connection id we now hold. Views return zero rows until mt_set_scope() binds a verified scope.
    if (mt_enabled()) { try { $p->rawExec('DELETE FROM '.MT_PREFIX.'ctx WHERE conn_id=CONNECTION_ID()'); } catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)!==1146) throw $e; } }
    return $p;
}
/** Private file storage for the CURRENT branch: private/ for the original single restaurant, private/tenants/<t>/<b>/ for every other branch. */
function storage_dir(string $sub): string {
    $pre=(string)(db_target()['storage']??''); if ($pre!=='' && !preg_match('#^tenants/[a-z0-9]{6,40}/[a-z0-9]{6,40}$#',$pre)) fail('Invalid storage scope.',503);
    $d=private_root().'/'.($pre!==''?$pre.'/':'').trim($sub,'/'); if(!is_dir($d)) @mkdir($d,0750,true); return $d;
}
function db(): PDO {
    if (!empty($GLOBALS['F360_PDO'])) return $GLOBALS['F360_PDO'];
    if (!configured()) fail('Database has not been configured.',503);
    $p=db_connect_target(db_target());
    // One text collation for the whole connection: the database's own default (all app tables use it — schema step 11).
    // Some hosts (e.g. MariaDB 11.x on Hostinger) give typed-in text one collation and passed-in values another → error 1267.
    db_session_collation($p);
    $p->exec("SET time_zone = '+00:00'");
    // Same SQL behaviour on every host: keep strict data checks, but not ONLY_FULL_GROUP_BY (MySQL 5.7+/8 default),
    // which rejects summary queries that MariaDB accepts and that the app is tested with (live error 1055 → "Database operation failed").
    $p->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $p->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    // V31: bind this connection to the verified restaurant + branch. Missing scope = refuse (never "all rows").
    if (mt_enabled()) { $t=db_target(); if((int)($t['tenant_id']??0)<=0||(int)($t['branch_id']??0)<=0) fail('No restaurant/branch selected.',401); mt_set_scope($p,(int)$t['tenant_id'],(int)$t['branch_id']); }
    return $GLOBALS['F360_PDO']=$p;
}
function run(string $sql, mixed ...$args): PDOStatement { $s=db()->prepare($sql);$s->execute($args);return $s; }
function rows(string $sql, mixed ...$args): array { return run($sql,...$args)->fetchAll(); }
function row(string $sql, mixed ...$args): ?array { $r=run($sql,...$args)->fetch();return $r===false?null:$r; }
function installed(): bool { try { return row("SELECT value FROM dd_settings WHERE `key`='installed'")!==null; } catch(PDOException $e){ if (($e->errorInfo[1]??0)===1146) return false; throw $e; } }
function fail(string $message,int $status=400): never { throw new RuntimeException($message,$status); }
function send(array $data,int $status=200): never { http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit; }
function security_headers(): void {
    header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');header('Cache-Control: no-store, private');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
}
function local_request(): bool { return in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true); }
function https_request(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'; }
function start_session(): void {
    if (session_status()===PHP_SESSION_ACTIVE) return;
    if (!https_request()&&!local_request()) fail('Open this dashboard using HTTPS. Enable SSL in Hostinger first.',403);
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.cookie_httponly','1');
    session_name('DHABA_OPS');
    $base=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/ops/index.php')),'/').'/';
    session_set_cookie_params(['lifetime'=>0,'path'=>$base,'secure'=>https_request(),'httponly'=>true,'samesite'=>'Strict']);
    session_start();if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
}
function csrf(): string { return $_SESSION['csrf']; }
function check_csrf(?string $token=null): void { $v=$token??($_SERVER['HTTP_X_CSRF_TOKEN']??'');if(!hash_equals(csrf(),$v))fail('Security token expired. Refresh and try again.',403); }
function user(): array {
    if(empty($_SESSION['user_id'])||($_SESSION['expires']??0)<time())fail('Please sign in',401);
    $u=row('SELECT * FROM dd_users WHERE id=?',(int)$_SESSION['user_id']);
    if(!$u||(int)$u['session_rev']!==(int)($_SESSION['rev']??0)||(int)($u['active']??1)!==1)fail('Please sign in',401);unset($u['password']);
    // V29: one write gate for every router (subscription read-only, support sessions). Runs after authentication.
    if(platform_mode()&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&empty($GLOBALS['F360_API_GATED'])){ require_once __DIR__.'/platform/tenancy.php'; platform_write_gate((string)($_GET['route']??$_GET['r']??'')); }
    return $u;
}
function can(array $u,string $p): bool { $r=ROLES[$u['role']??'']??[]; return in_array('*',$r,true)||in_array($p,$r,true); }
function allowed(array $u,string $p): void { if(!can($u,$p))fail('Your role cannot perform this action.',403); }
function audit(?array $u,string $action,array $detail=[]): void { run('INSERT INTO dd_audit(user_id,action,detail) VALUES(?,?,?)',$u['id']??null,$action,json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
function txt(mixed $v,int $max=1000): string { $s=trim(is_scalar($v)?(string)$v:'');return function_exists('mb_substr')?mb_substr($s,0,$max,'UTF-8'):substr($s,0,$max); }
function name_required(mixed $v): string { $s=txt($v,160);if($s==='')fail('Name is required.');return $s; }
function valid_date(mixed $v): string { $s=is_string($v)?$v:''; $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);if(!$d||$d->format('Y-m-d')!==$s)fail('Use a valid YYYY-MM-DD date.');return $s; }
function valid_month(mixed $v): string { $s=txt($v,7);if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$s))fail('Choose a valid payroll month.');return $s; }
function money(mixed $v): int {
    if(!is_scalar($v)||is_bool($v))fail('Enter a valid non-negative amount.');
    $s=trim((string)$v);if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/',$s))fail('Use a non-negative amount with at most two decimal places.');
    [$whole,$fraction]=array_pad(explode('.',$s,2),2,'');$n=(int)$whole*100+(int)str_pad($fraction,2,'0');if($n>100000000000)fail('Amount is too large.');return $n;
}
function lock_day(string $d): void { valid_date($d);month_lock_check($d);run('INSERT IGNORE INTO dd_days(date) VALUES(?)',$d);$r=row('SELECT status FROM dd_days WHERE date=? FOR UPDATE',$d);if($r['status']==='closed')fail('This day is closed. An owner must reopen it with a reason.'); }
/** Fix C15: one posting guard for every route that changes a day's money. A submitted/reviewed day is frozen until a manager sends it back (logged). */
function day_post_guard(string $d): array { lock_day($d); $day=row('SELECT * FROM dd_days WHERE date=? FOR UPDATE',$d); if(in_array($day['workflow']??'draft',['submitted','reviewed'],true)) fail('This day ('.$d.') has been submitted for closing. A manager must send it back (Return to draft, with a reason) before money entries change.',409); return $day; }
/** V6: a hard-closed accounting month rejects every new financial/stock posting dated inside it (corrections go into an open month as approved adjustments). */
function month_lock_check(string $d): void { try{ $m=row("SELECT status FROM dd_monthly_closures WHERE month=?".(db()->inTransaction()?' LOCK IN SHARE MODE':''),substr($d,0,7)); }catch(PDOException $e){ if(($e->errorInfo[1]??0)===1146) return; throw $e; } if($m&&$m['status']==='hard') fail('Month '.substr($d,0,7).' is hard-closed. Post a correction in an open month, or ask the owner to reopen the month with a reason.',409); }
function movement(array $e): array { if(in_array($e['type'],['sale','payment-in'],true))return ['in'=>(int)$e['amount'],'out'=>0];if(in_array($e['type'],['deposit','withdrawal','settlement'],true))return ['in'=>0,'out'=>0];return ['in'=>0,'out'=>(int)$e['amount']]; }
function summary(array $entries,int $opening=0): array {
    $s=array_fill_keys(['received','paid','net','cashIn','cashOut','sales','expenses','unallocated','paytm'],0);
    foreach($entries as $e){$m=movement($e);$a=(int)$e['amount'];$s['received']+=$m['in'];$s['paid']+=$m['out'];if($e['account']==='Unallocated')$s['unallocated']+=$a;if($e['account']==='Cash'){$s['cashIn']+=$m['in'];$s['cashOut']+=$m['out'];}if(in_array($e['type'],['deposit','withdrawal','settlement'],true)){if($e['account']==='Cash')$s['cashOut']+=$a;if($e['to_account']==='Cash')$s['cashIn']+=$a;}if($e['type']==='sale')$s['sales']+=$a;if(in_array($e['type'],['expense','salary'],true))$s['expenses']+=$a;if($e['account']==='Paytm'&&$e['type']==='sale')$s['paytm']+=$a;}
    $s['net']=$s['received']-$s['paid'];$s['expectedCash']=$opening+$s['cashIn']-$s['cashOut'];return $s;
}
function validate_entry(array $b): array {
    $type=txt($b['type']??'');$account=txt($b['account']??'');$to=txt($b['to_account']??'');
    if(!in_array($type,ENTRY_TYPES,true))fail('Choose a valid entry type.');if(!in_array($account,ACCOUNTS,true))fail('Choose an account.');
    $amount=money($b['amount']??null);if(!$amount)fail('Amount must be greater than zero.');
    if(in_array($type,['deposit','withdrawal','settlement'],true)&&(!in_array($to,ACCOUNTS,true)||$to===$account||$to==='Unallocated'||$account==='Unallocated'))fail('Transfers need two different allocated accounts.');
    return ['date'=>valid_date($b['date']??''),'name'=>name_required($b['name']??''),'type'=>$type,'account'=>$account,'to_account'=>$to?:null,'amount'=>$amount,'category'=>txt($b['category']??'General',80),'reference'=>txt($b['reference']??'',120),'description'=>txt($b['description']??''),'employee_id'=>!empty($b['employee_id'])?(int)$b['employee_id']:null,'payroll_month'=>!empty($b['payroll_month'])?valid_month($b['payroll_month']):null];
}
// $preauthorized=true only when an approved voucher (dd_vouchers) authorizes a payroll-type cash payment.
function add_entry(array $b,array $u,bool $preauthorized=false): int {
    $e=validate_entry($b);day_post_guard($e['date']);
    if(in_array($e['type'],['salary','advance'],true)&&!$preauthorized)allowed($u,'payroll');
    if($e['employee_id']){if(!row('SELECT id FROM dd_employees WHERE id=? FOR UPDATE',$e['employee_id']))fail('Employee not found.');}
    if(in_array($e['type'],['salary','advance'],true)&&(!$e['employee_id']||!$e['payroll_month']))fail('Salary / advance needs an employee and payroll month.');
    if($e['type']==='advance'&&row('SELECT id FROM dd_payroll WHERE employee_id=? AND month=? AND approved=1',$e['employee_id'],$e['payroll_month']))fail('Payroll is already approved. Record this advance against a later payroll month.');
    if($e['type']==='salary'){$p=row('SELECT * FROM dd_payroll WHERE employee_id=? AND month=? AND approved=1 FOR UPDATE',$e['employee_id'],$e['payroll_month']);if(!$p)fail('Approve payroll before recording salary.');$paid=(int)row("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE employee_id=? AND payroll_month=? AND type='salary' AND voided=0",$e['employee_id'],$e['payroll_month'])['n'];if($paid+$e['amount']>(int)$p['net'])fail('Payment exceeds the approved unpaid salary.');}
    run('INSERT INTO dd_ledger(date,name,type,account,to_account,amount,category,reference,description,employee_id,payroll_month,source,source_key,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',$e['date'],$e['name'],$e['type'],$e['account'],$e['to_account'],$e['amount'],$e['category'],$e['reference'],$e['description'],$e['employee_id'],$e['payroll_month'],txt($b['source']??'manual',40),$b['source_key']??null,$u['id']??null);
    $id=(int)db()->lastInsertId();audit($u,'ledger.create',['id'=>$id]+$e);return $id;
}
function require_json(): array { if((int)($_SERVER['CONTENT_LENGTH']??0)>2000000)fail('Request exceeds 2 MB.',413);$raw=file_get_contents('php://input',false,null,0,2000001);if(strlen($raw)>2000000)fail('Request exceeds 2 MB.',413);$b=json_decode($raw?:'{}',true,64,JSON_THROW_ON_ERROR);if(!is_array($b)||array_is_list($b)&&$b!==[])fail('Invalid request JSON.');return $b; }
/**
 * Fix C13: server-enforced replay protection. Same scope + client_key + payload returns the stored response (no second posting);
 * the same key with different data is refused. Runs inside the caller's transaction, so a failed request leaves no key behind.
 */
function idem(string $scope,array $b,?array $u,callable $fn): array {
    $k=txt($b['client_key']??'',64); if($k==='') return $fn();
    if(!preg_match('/^[A-Za-z0-9_:.-]{8,64}$/',$k)) fail('Invalid submission key.');
    $p=$b; unset($p['client_key']); $sha=hash('sha256',json_encode(idem_sort($p),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    try{ $ins=run('INSERT IGNORE INTO dd_request_keys(scope,client_key,payload_sha,user_id) VALUES(?,?,?,?)',txt($scope,40),$k,$sha,$u['id']??null)->rowCount(); }
    catch(PDOException $e){ if(($e->errorInfo[1]??0)===1146) return $fn(); throw $e; } // table appears with migration 6
    if($ins===0){ $r=row('SELECT * FROM dd_request_keys WHERE scope=? AND client_key=? FOR UPDATE',txt($scope,40),$k);
        if(!hash_equals((string)$r['payload_sha'],$sha)) fail('This submission was already saved with different details. Refresh the form before entering a new transaction.',409);
        if($r['response']===null) fail('This submission is still being processed. Refresh in a moment.',409);
        return ['duplicate'=>true]+(json_decode((string)$r['response'],true)?:['ok'=>true]); }
    $res=$fn(); run('UPDATE dd_request_keys SET response=? WHERE scope=? AND client_key=?',json_encode($res,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),txt($scope,40),$k); return $res;
}
function idem_sort(mixed $v): mixed { if(!is_array($v)) return is_scalar($v)||$v===null?(string)$v:''; if(!array_is_list($v)) ksort($v); return array_map('idem_sort',$v); }
function transaction(callable $fn): mixed { db()->beginTransaction();try{$v=$fn();db()->commit();return $v;}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;} }
function csv_cell(mixed $v): string { $s=(string)($v??'');if(preg_match('/^[=+@\-\t\r]/',$s))$s="'".$s;return '"'.str_replace('"','""',$s).'"'; }
function error_response(Throwable $e): never {
    if($e instanceof PDOException){$code=(int)($e->errorInfo[1]??0);if($code===1062)send(['error'=>'This reference already exists. No duplicate was added.'],409);if($code===1452)send(['error'=>'The linked record does not exist.'],400);if(in_array($code,[1205,1213],true))send(['error'=>'Another entry is being saved. Please try again.'],409);$ref=strtoupper(bin2hex(random_bytes(3)));$msg=preg_replace('/\s+/',' ',(string)($e->errorInfo[2]??$e->getMessage()));error_log('Dhaba Desk database error '.$ref.': '.$e->getCode().'/'.$code.' '.$msg);
        $ld=private_root().'/logs';if(is_dir($ld)||@mkdir($ld,0750,true)){@file_put_contents($ld.'/db-errors.log',gmdate('Y-m-d H:i:s').' ref '.$ref.' '.$e->getCode().'/'.$code.' '.mb_substr($msg,0,400).' | '.($_SERVER['REQUEST_METHOD']??'CLI').' '.mb_substr(preg_replace('/[^\w=&\/.?-]/','',(string)($_GET['route']??'')),0,60).PHP_EOL,FILE_APPEND|LOCK_EX);}
        send(['error'=>'Database operation failed (code '.$code.', ref '.$ref.'). Send this message to support; details are in the private logs/db-errors.log.'],503);}
    if($e->getCode()===402)send(['error'=>$e->getMessage(),'code'=>'SUBSCRIPTION_READ_ONLY'],402);
    $status=in_array($e->getCode(),[400,401,403,404,405,409,410,413,422,429,502,503],true)?$e->getCode():400;
    $message=$e instanceof JsonException?'Invalid request JSON.':($e instanceof RuntimeException?$e->getMessage():'The request could not be completed. Check the server error log.');
    if(!$e instanceof RuntimeException)error_log('Dhaba Desk request error: '.get_class($e));send(['error'=>$message],$status);
}

/* Finance 360 V17.1 — old modules archived. The old CRM / Books 360 / Salary Register / Store & Kitchen screens are closed for everyone except the
   owner, who can still open them READ-ONLY (Settings → Old records archive). All their data stays in the database and in Finance 360 reports.
   Owner can switch this off in Finance 360 Settings ("legacy_archived" = 0) for an emergency. */
function legacy_archived(): bool { try { return setting('legacy_archived', '1') !== '0'; } catch (Throwable $e) { return true; } }
function legacy_guard(array $u, bool $write): void {
    if (!legacy_archived()) return;
    if (($u['role'] ?? '') !== 'owner') fail('The old modules are closed. Use Finance 360 (open the main page).', 403);
    if ($write) fail('Old records are read-only. Make changes in Finance 360.', 403);
}
/** For the old page shells: anyone but the owner goes back to Finance 360. */
function legacy_page_guard(): void {
    if (!configured()) return; try { if (!legacy_archived()) return; start_session(); $u = user(); if (($u['role'] ?? '') === 'owner') return; } catch (Throwable $e) {}
    header('Location: index.php'); exit;
}
