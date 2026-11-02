<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
security_headers();
try {
    start_session();
    $method=$_SERVER['REQUEST_METHOD'];$route=(string)($_GET['route']??'');
    if(!preg_match('/^[a-z-]+$/',$route))fail('Not found',404);
    $get=['status','me','data','staff-records','reference','export','backup','file','crm-home','crm-admin','crm-report','crm-kitchen-last','crm-items','crm-suppliers','crm-supplier','crm-supplier-dues','crm-supplier-statement','crm-receipts','crm-receipt','crm-purchase-report','crm-usage','crm-usage-sheet','crm-stock-movement','crm-consumption-month','crm-monthly','crm-employees','crm-payroll-batch','crm-payslip','crm-wizard','crm-wizard-bills','crm-recurring','crm-bank','backup-files','crm-gl','crm-gl-ledger','crm-gl-journal','crm-events','crm-counter-close','crm-tender-recon','crm-drawers'];
    if(($method==='GET'&&!in_array($route,$get,true))||($method==='POST'&&in_array($route,$get,true))||!in_array($method,['GET','POST'],true))fail('Method not allowed',405);
    if($method==='POST')check_csrf();
    if($route==='status')send(['setup'=>!configured()||!installed(),'demo'=>false,'today'=>date('Y-m-d'),'csrf'=>csrf()]);
    if(!configured()||!installed())fail('Complete install.php before signing in.',503);
    $b=$method==='POST'?require_json():[];
    if($route==='login'){
        if(platform_mode())fail('Sign in on the Finance 360 sign-in page (signin.php).',403); // V29: ledger passwords are never used in multi-restaurant mode
        $key=hash('sha256',($_SERVER['REMOTE_ADDR']??'unknown').'|'.strtolower(txt($b['email']??'',190)));
        $ip=hash('sha256',($_SERVER['REMOTE_ADDR']??'unknown').'|all-logins');
        transaction(function()use($key,$ip){
            foreach([$ip,$key] as $k){run('INSERT IGNORE INTO dd_login_attempts(id,attempts,expires) VALUES(?,0,?)',$k,time()+900);$a=row('SELECT * FROM dd_login_attempts WHERE id=? FOR UPDATE',$k);if((int)$a['expires']<time()){run('UPDATE dd_login_attempts SET attempts=0,expires=? WHERE id=?',time()+900,$k);$a['attempts']=0;}if((int)$a['attempts']>=($k===$ip?30:10))fail('Too many attempts. Try again in 15 minutes.',429);run('UPDATE dd_login_attempts SET attempts=attempts+1 WHERE id=?',$k);}
        });
        $u=row('SELECT * FROM dd_users WHERE email=?',strtolower(txt($b['email']??'',190)));
        if(!$u||!password_verify((string)($b['password']??''),$u['password'])||(int)($u['active']??1)!==1)fail('Email or password is incorrect.',401);
        if(password_needs_rehash($u['password'],PASSWORD_DEFAULT))run('UPDATE dd_users SET password=? WHERE id=?',password_hash((string)$b['password'],PASSWORD_DEFAULT),$u['id']);
        run('DELETE FROM dd_login_attempts WHERE id=?',$key);run('DELETE FROM dd_login_attempts WHERE expires<?',time());
        session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];$_SESSION['rev']=(int)$u['session_rev'];$_SESSION['expires']=time()+28800;$_SESSION['csrf']=bin2hex(random_bytes(24));audit($u,'login');send(['ok'=>true]);
    }
    $u=user();
    if(!in_array($route,['me','logout'],true)) legacy_guard($u,$method==='POST');
    if(str_starts_with($route,'crm-')||$route==='file'){require __DIR__.'/api360.php';crm_dispatch($route,$method,$b,$u);}
    // Legacy full-CRM routes need the broad read permission (cashier/kitchen/store staff use crm-* routes only).
    if(!in_array($route,['me','logout'],true))allowed($u,'read');
    if($route==='me')send(['user'=>['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']],'csrf'=>csrf(),'demo'=>false,'restaurant'=>row("SELECT value FROM dd_settings WHERE `key`='restaurant'")['value'],'today'=>date('Y-m-d'),'types'=>ENTRY_TYPES,'accounts'=>ACCOUNTS,'perms'=>ROLES[$u['role']]??[],'roleLabel'=>ROLE_LABELS[$u['role']]??$u['role'],'schema'=>(int)(row("SELECT value FROM dd_settings WHERE `key`='schema_version'")['value']??1)]);
    if($route==='logout'){$_SESSION=[];session_destroy();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>session_get_cookie_params()['path'],'httponly'=>true,'secure'=>https_request(),'samesite'=>'Strict']);send(['ok'=>true]);}
    if($route==='reference'){
        allowed($u,'payroll');$files=['plan'=>'WORKING PLAN.pdf','voucher'=>'Khadak_Singh_Debit_Voucher_and_Register.pdf','rules'=>'Khadak_Singh_Staff_Rules_Improved.pdf','joining'=>'Khadak_Singh_Da_Dhaba_Staff_Joining_Rules.pdf','roster'=>'Monthly_Waiter_Roster_Improved.pdf','plan-preview'=>'floor-plan.png'];$key=(string)($_GET['file']??'');if(!isset($files[$key]))fail('Reference not found',404);header('Content-Type: '.($key==='plan-preview'?'image/png':'application/pdf'));header('Content-Disposition: inline');$rf=storage_dir('reference-documents').'/'.$files[$key];if(!is_file($rf))fail('Reference not found',404);readfile($rf);exit;
    }
    if($route==='staff-records'){allowed($u,'payroll');$records=rows('SELECT * FROM dd_staff_records ORDER BY id DESC LIMIT 2000');foreach($records as &$r)$r['payload']=json_decode($r['payload'],true,64,JSON_THROW_ON_ERROR);unset($r);send(['records'=>$records]);}
    $d=valid_date($_GET['date']??date('Y-m-d'));$month=valid_month($_GET['month']??substr($d,0,7));
    if($route==='data'){
        // A single snapshot keeps totals, day status, and the entry list consistent.
        $data=transaction(function()use($d,$month,$u){
            $entries=rows('SELECT * FROM dd_ledger WHERE date=? ORDER BY id DESC',$d);$day=row('SELECT * FROM dd_days WHERE date=?',$d)??['date'=>$d,'opening'=>0,'opening_set'=>0,'counted'=>null,'status'=>'open','note'=>''];$sensitive=can($u,'payroll');
            $payroll=$sensitive?rows("SELECT p.*,e.name,e.pay_basis,COALESCE((SELECT SUM(amount) FROM dd_ledger l WHERE l.employee_id=p.employee_id AND l.payroll_month=p.month AND l.type='salary' AND l.voided=0),0) paid FROM dd_payroll p JOIN dd_employees e ON e.id=p.employee_id WHERE month=?",$month):[];
            return ['day'=>$day,'summary'=>summary(array_values(array_filter($entries,fn($x)=>!$x['voided'])),(int)$day['opening']),'ledger'=>$entries,'menu'=>rows('SELECT * FROM dd_menu WHERE active=1'),'orders'=>rows('SELECT * FROM dd_orders WHERE date=? ORDER BY id DESC',$d),'employees'=>$sensitive?rows('SELECT * FROM dd_employees'):[],'attendance'=>$sensitive?rows('SELECT * FROM dd_attendance WHERE date=?',$d):[],'payroll'=>$payroll,'inventory'=>array_map(function($i){$i['quantity']=(float)$i['quantity'];$i['reorder']=(float)$i['reorder'];return $i;},rows('SELECT * FROM dd_inventory')),'suppliers'=>rows('SELECT * FROM dd_suppliers'),'bills'=>rows('SELECT b.*,s.name supplier FROM dd_bills b JOIN dd_suppliers s ON s.id=b.supplier_id ORDER BY b.id DESC'),'reservations'=>rows('SELECT * FROM dd_reservations WHERE date=? ORDER BY time',$d),'feedback'=>rows('SELECT * FROM dd_feedback ORDER BY id DESC LIMIT 200'),'paytm'=>rows('SELECT * FROM dd_paytm WHERE date=? ORDER BY id DESC',$d),'trend'=>rows("SELECT date,CAST(SUM(CASE WHEN type='sale' THEN amount ELSE 0 END) AS SIGNED) sales,CAST(SUM(CASE WHEN type IN ('expense','salary') THEN amount ELSE 0 END) AS SIGNED) expenses FROM dd_ledger WHERE voided=0 AND date BETWEEN DATE_SUB(?,INTERVAL 6 DAY) AND ? GROUP BY date ORDER BY date",$d,$d),'audit'=>$u['role']==='owner'?rows('SELECT a.*,u.name FROM dd_audit a LEFT JOIN dd_users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100'):[],'users'=>$u['role']==='owner'?rows('SELECT id,name,email,role FROM dd_users'):[]];
        });send($data);
    }
    if($route==='export'){
        $out=[['Date','Name','Type','Payment Type','Money In','Money Out','Reference','Description','Voided']];
        foreach(rows('SELECT * FROM dd_ledger WHERE date=? ORDER BY id',$d) as $e){$m=movement($e);$out[]=[$e['date'],$e['name'],$e['type'],$e['account'].($e['to_account']?' → '.$e['to_account']:''),number_format($m['in']/100,2,'.',''),number_format($m['out']/100,2,'.',''),$e['reference'],$e['description'],$e['voided']?'Yes':'No'];}
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="daybook-'.$d.'.csv"');echo "\xEF\xBB\xBF";foreach($out as $r)echo implode(',',array_map('csv_cell',$r))."\r\n";exit;
    }
    if($route==='backup'){
        // V6: dumps EVERY dd_* table (CRM 360 tables included) with its live CREATE TABLE, streamed row by row. Login throttling rows are skipped.
        allowed($u,'manage');audit($u,'backup.download');set_time_limit(300);
        $tables=array_values(array_filter(array_map(fn($r)=>array_values($r)[0],rows("SHOW TABLES LIKE 'dd\\_%'")),fn($t)=>$t!=='dd_login_attempts'));
        header('Content-Type: application/sql; charset=utf-8');header('Content-Disposition: attachment; filename="ksdd-full-backup-'.date('Y-m-d-His').'.sql"');
        db()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');db()->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT'); // one point-in-time view of all tables
        echo "-- KSDD CRM 360 FULL logical backup ".date('c')."\n-- Tables: ".count($tables)." (all dd_* tables). Private uploads (bill photos, payroll files) are in the separate 'Download private files' archive.\n-- Restore into an EMPTY database only. Never overwrite a live database.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n";
        foreach($tables as $t){ $ddl=mt_enabled()?mt_portable_create(db(),$t):array_values(row('SHOW CREATE TABLE `'.$t.'`'))[1]; echo "\nDROP TABLE IF EXISTS `$t`;\n".$ddl.";\n"; $n=0; /* V31: portable DDL, scoped rows */
            $st=db()->query('SELECT * FROM `'.$t.'`'); while($r=$st->fetch()){ echo 'INSERT INTO `'.$t.'` (`'.implode('`,`',array_keys($r)).'`) VALUES ('.implode(',',array_map(fn($v)=>$v===null?'NULL':db()->quote((string)$v),array_values($r))).");\n"; if(++$n%500===0)flush(); } $st->closeCursor(); }
        db()->exec('COMMIT');echo "COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n-- END OF BACKUP (".count($tables)." tables)\n";exit;
    }
    if($route==='backup-files'){
        allowed($u,'manage');if(!class_exists('ZipArchive'))fail('PHP zip extension is not available. Download private/uploads with Hostinger File Manager.',503);audit($u,'backup.files');set_time_limit(300);
        $tmp=tempnam(sys_get_temp_dir(),'ksf');$z=new ZipArchive();$z->open($tmp,ZipArchive::OVERWRITE);$base=storage_dir('uploads');$n=0;
        if(is_dir($base))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile()&&$f->getFilename()!=='.htaccess'){$z->addFile($f->getPathname(),substr($f->getPathname(),strlen($base)+1));$n++;}}
        $z->addFromString('MANIFEST.txt','KSDD private uploads '.date('c').' · files: '.$n."\nRestore into private/uploads/ (keep the .htaccess deny file).\n");$z->close();
        header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="ksdd-private-files-'.date('Y-m-d').'.zip"');header('Content-Length: '.filesize($tmp));readfile($tmp);@unlink($tmp);exit;
    }
    if($method!=='POST')fail('Not found',404);
    allowed($u,'write');require __DIR__.'/actions.php';
    $result=transaction(fn()=>mutate($route,$b,$u));send($result);
}catch(Throwable $e){try{if(configured()&&db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}error_response($e);}
