<?php
declare(strict_types=1);
// KSDD Salary Register 360 — monthly salary sheet that replaces "KSDD Sallery 2026.xlsx".
// Same maths as the Excel sheet:  Per-day = Monthly ÷ Days-in-month · Earned = Per-day × (Present + Leave encash)
//   Net = Earned + Bonus + B/F − Advance − Fine/Debt · Balance = Net − Paid · Status: |Balance| ≤ tolerance → Clear.
// Improvements over the sheet: every advance / fine / payment is an itemised dated entry (no more =8000+1000),
// previous month balance is carried forward automatically (B/F), one employee ID across months, month lock, audit log.
// Money is integer paise. Nothing here touches the cash daybook (dd_ledger).
require_once __DIR__.'/lib.php';
require_once __DIR__.'/lib360.php';

const SAL_KINDS = ['advance','fine','bonus','payment'];
const SAL_MODES = ['Cash','Online','Bank'];
const SAL_SECTIONS = ['Manager','Waiter','UT Staff','Kitchen'];
const SAL_MIGRATION = 2; // Salary module schema version (stored in dd_settings.salary_schema)

/* ============================== schema ============================== */
function sal_migrate(): void {
    $t=[
     "dd_sal_months (month CHAR(7) PRIMARY KEY,days TINYINT NOT NULL,status VARCHAR(10) NOT NULL DEFAULT 'open',tolerance BIGINT NOT NULL DEFAULT 1000,carry_forward TINYINT NOT NULL DEFAULT 1,source VARCHAR(20) NOT NULL DEFAULT 'app',note TEXT NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,locked_by BIGINT UNSIGNED NULL,locked_at DATETIME NULL)",
     "dd_sal_rows (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,month CHAR(7) NOT NULL,employee_id BIGINT UNSIGNED NOT NULL,section VARCHAR(40) NOT NULL DEFAULT 'Waiter',department VARCHAR(80) NULL,sort INT NOT NULL DEFAULT 0,monthly BIGINT NOT NULL DEFAULT 0,present DECIMAL(6,2) NOT NULL DEFAULT 0,leave_days DECIMAL(6,2) NOT NULL DEFAULT 0,bf BIGINT NOT NULL DEFAULT 0,pay_mode VARCHAR(40) NULL,note VARCHAR(250) NULL,source_name VARCHAR(160) NULL,excel_row INT NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_sal_row(month,employee_id),INDEX(employee_id))",
     "dd_sal_entries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,row_id BIGINT UNSIGNED NOT NULL,month CHAR(7) NOT NULL,employee_id BIGINT UNSIGNED NOT NULL,kind VARCHAR(12) NOT NULL,amount BIGINT NOT NULL,date DATE NULL,mode VARCHAR(40) NULL,note VARCHAR(250) NULL,source VARCHAR(20) NOT NULL DEFAULT 'manual',voided TINYINT NOT NULL DEFAULT 0,void_reason VARCHAR(250) NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(row_id),INDEX(employee_id,kind),INDEX(month,kind))",
     "dd_sal_imports (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,file_name VARCHAR(160),file_sha256 CHAR(64) NOT NULL,months VARCHAR(200) NOT NULL,result TEXT NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    ];
    foreach($t as $sql) db()->exec('CREATE TABLE IF NOT EXISTS '.$sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    require_once __DIR__.'/migrations.php';
    foreach(['code'=>"VARCHAR(40) NULL",'department'=>"VARCHAR(80) NULL",'designation'=>"VARCHAR(80) NULL",'status'=>"VARCHAR(20) NOT NULL DEFAULT 'active'",'joined_on'=>'DATE NULL','left_on'=>'DATE NULL','created_by'=>'BIGINT UNSIGNED NULL',
             'section'=>"VARCHAR(40) NULL",'aliases'=>"VARCHAR(400) NULL",'phone'=>"VARCHAR(20) NULL",'pay_mode'=>"VARCHAR(40) NULL"] as $c=>$d) mig_add_column('dd_employees',$c,$d);
    run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('salary_sections',?)",json_encode(SAL_SECTIONS));
    run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('salary_modes',?)",json_encode(SAL_MODES));
    run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('salary_tolerance','1000')");
    foreach(['app_days'=>'DECIMAL(6,2) NULL','app_wage'=>'BIGINT NULL','app_penalty'=>'BIGINT NULL','app_advance'=>'BIGINT NULL','app_to_pay'=>'BIGINT NULL','app_ext_id'=>'VARCHAR(64) NULL'] as $c=>$d) mig_add_column('dd_sal_rows',$c,$d);
    foreach(['bank_name'=>'VARCHAR(80) NULL','ifsc'=>'VARCHAR(20) NULL','account_last4'=>'VARCHAR(4) NULL'] as $c=>$d) mig_add_column('dd_employees',$c,$d);
    db()->exec("CREATE TABLE IF NOT EXISTS dd_employee_identifiers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source VARCHAR(30) NOT NULL,external_id VARCHAR(64) NOT NULL,employee_id BIGINT UNSIGNED NOT NULL,verified_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_ext(source,external_id),INDEX(employee_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    set_setting('salary_schema',(string)SAL_MIGRATION);
}
function sal_ready(): void {
    try{ row('SELECT 1 FROM dd_sal_months LIMIT 1'); }
    catch(PDOException $e){ if(($e->errorInfo[1]??0)===1146) fail('Salary Register is not set up yet. Owner: open salary.php and press “Set up Salary Register”.',503); throw $e; }
}
function sal_installed(): bool { try{ row('SELECT 1 FROM dd_sal_months LIMIT 1'); return true; }catch(PDOException $e){ return false; } }

/* ============================== helpers ============================== */
function sal_sections(): array { $v=json_decode(setting('salary_sections',''),true); return is_array($v)&&$v?$v:SAL_SECTIONS; }
function sal_modes(): array { $v=json_decode(setting('salary_modes',''),true); return is_array($v)&&$v?$v:SAL_MODES; }
/** Signed rupees (string/number) → paise. */
function sal_paise(mixed $v,string $label='Amount',bool $signed=false): int {
    if(is_bool($v)||!is_scalar($v)) fail($label.' is not valid.');
    $s=trim(str_replace([',','₹',' '],'',(string)$v)); if($s===''||$s==='-') return 0;
    if(!preg_match($signed?'/^-?\d{1,9}(\.\d{1,2})?$/':'/^\d{1,9}(\.\d{1,2})?$/',$s)) fail($label.' must be a '.($signed?'':'positive ').'number with up to 2 decimals.');
    $neg=$s[0]==='-'; $s=ltrim($s,'-'); [$w,$f]=array_pad(explode('.',$s,2),2,''); $p=(int)$w*100+(int)str_pad($f,2,'0'); return $neg?-$p:$p;
}
function sal_days_val(mixed $v,string $label,int $max=31): string {
    $s=trim((string)$v); if($s==='') return '0';
    if(!is_numeric($s)||(float)$s<0||(float)$s>$max) fail($label.' must be between 0 and '.$max.'.');
    return number_format(round((float)$s,2),2,'.','');
}
function sal_hund(mixed $d): int { return (int)round((float)$d*100); }
function sal_month_days(string $m): int { return (int)(new DateTimeImmutable($m.'-01'))->format('t'); }
function sal_next(string $m): string { return (new DateTimeImmutable($m.'-01'))->modify('+1 month')->format('Y-m'); }
function sal_prev(string $m): string { return (new DateTimeImmutable($m.'-01'))->modify('-1 month')->format('Y-m'); }
function sal_key(string $n): string { $n=preg_replace('/\((new|नया)\)/iu','',$n); return preg_replace('/[^a-z0-9\x{0900}-\x{097F}]+/u','',mb_strtolower($n,'UTF-8')); }
function sal_month(string $m,bool $lock=false): array { $r=row('SELECT * FROM dd_sal_months WHERE month=?'.($lock?' FOR UPDATE':''),valid_month($m)); if(!$r) fail('Salary month '.$m.' has not been started.',404); return $r; }
function sal_open_month(string $m): array { $r=sal_month($m,true); if($r['status']==='locked') fail('Salary for '.$m.' is LOCKED. The owner must unlock it (with a reason) before changes.',409); return $r; }

/** Calculation identical to the Excel sheet, in paise. $sums = [advance,fine,bonus,payment]. */
function sal_calc(array $r,int $days,array $sums,int $tol): array {
    $monthly=(int)$r['monthly']; $wd=sal_hund($r['present'])+sal_hund($r['leave_days']);
    $earned=prorate_monthly($monthly,$wd,$days); // fix C11: same function as CRM payroll
    $perday=$days>0?intdiv($monthly+intdiv($days,2),$days):0;
    $net=$earned+(int)$sums['bonus']+(int)$r['bf']-(int)$sums['advance']-(int)$sums['fine'];
    $bal=$net-(int)$sums['payment'];
    $status=abs($bal)<=$tol?'Clear':($bal>0?'Pending':'Overpaid');
    return ['working'=>$wd/100,'perday'=>$perday,'earned'=>$earned,'advance'=>(int)$sums['advance'],'fine'=>(int)$sums['fine'],'bonus'=>(int)$sums['bonus'],'paid'=>(int)$sums['payment'],'net'=>$net,'balance'=>$bal,'status'=>$status];
}
function sal_sums(array $rowIds): array {
    $o=[]; if(!$rowIds) return $o;
    foreach(rows('SELECT row_id,kind,CAST(SUM(amount) AS SIGNED) n FROM dd_sal_entries WHERE voided=0 AND row_id IN ('.implode(',',array_map('intval',$rowIds)).') GROUP BY row_id,kind') as $x) $o[(int)$x['row_id']][$x['kind']]=(int)$x['n'];
    return $o;
}
function sal_zero(): array { return ['advance'=>0,'fine'=>0,'bonus'=>0,'payment'=>0]; }
/** Closing balance of one employee in one month (null if not on that sheet). */
function sal_closing(int $emp,string $m): ?int {
    $mo=row('SELECT * FROM dd_sal_months WHERE month=?',$m); if(!$mo) return null;
    $r=row('SELECT * FROM dd_sal_rows WHERE month=? AND employee_id=?',$m,$emp); if(!$r) return null;
    $s=(sal_sums([(int)$r['id']])[(int)$r['id']]??[])+sal_zero(); return sal_calc($r,(int)$mo['days'],$s,(int)$mo['tolerance'])['balance'];
}

/* ============================== month view ============================== */
function sal_month_view(string $m): array {
    $mo=sal_month($m); $days=(int)$mo['days']; $tol=(int)$mo['tolerance'];
    $rows=rows('SELECT r.*,e.name,e.code,e.status emp_status,e.salary master_salary FROM dd_sal_rows r JOIN dd_employees e ON e.id=r.employee_id WHERE r.month=? ORDER BY FIELD(r.section,'.implode(',',array_map(fn($s)=>db()->quote($s),sal_sections())).')=0,FIELD(r.section,'.implode(',',array_map(fn($s)=>db()->quote($s),sal_sections())).'),r.sort,e.name',$m);
    $ids=array_map(fn($r)=>(int)$r['id'],$rows); $sums=sal_sums($ids);
    $ents=[]; if($ids) foreach(rows('SELECT id,row_id,kind,amount,date,mode,note,source,created_at FROM dd_sal_entries WHERE voided=0 AND row_id IN ('.implode(',',$ids).') ORDER BY date,id') as $e){ $e['amount']=(int)$e['amount']; $ents[(int)$e['row_id']][]=$e; }
    $prev=sal_prev($m); $prevMo=row('SELECT * FROM dd_sal_months WHERE month=?',$prev);
    $prevBal=[]; if($prevMo){ $pr=rows('SELECT * FROM dd_sal_rows WHERE month=?',$prev); $ps=sal_sums(array_map(fn($r)=>(int)$r['id'],$pr)); foreach($pr as $r) $prevBal[(int)$r['employee_id']]=sal_calc($r,(int)$prevMo['days'],($ps[(int)$r['id']]??[])+sal_zero(),(int)$prevMo['tolerance'])['balance']; }
    $out=[]; $tot=['employees'=>0,'monthly'=>0,'earned'=>0,'advance'=>0,'fine'=>0,'bonus'=>0,'bf'=>0,'net'=>0,'paid'=>0,'balance'=>0,'pending'=>0,'overpaid'=>0,'leave_cost'=>0,'clear'=>0,'pending_n'=>0,'overpaid_n'=>0,'by_mode'=>[],'by_section'=>[]];
    foreach($rows as $r){ $id=(int)$r['id']; $c=sal_calc($r,$days,($sums[$id]??[])+sal_zero(),$tol);
        $o=['id'=>$id,'employee_id'=>(int)$r['employee_id'],'code'=>$r['code'],'name'=>$r['name'],'emp_status'=>$r['emp_status'],'section'=>$r['section'],'department'=>$r['department'],'monthly'=>(int)$r['monthly'],'master_salary'=>(int)$r['master_salary'],
            'present'=>(float)$r['present'],'leave_days'=>(float)$r['leave_days'],'bf'=>(int)$r['bf'],'pay_mode'=>$r['pay_mode'],'note'=>$r['note'],'app_days'=>isset($r['app_days'])&&$r['app_days']!==null?(float)$r['app_days']:null,'app_wage'=>isset($r['app_wage'])&&$r['app_wage']!==null?(int)$r['app_wage']:null,'app_penalty'=>isset($r['app_penalty'])&&$r['app_penalty']!==null?(int)$r['app_penalty']:null,'app_advance'=>isset($r['app_advance'])&&$r['app_advance']!==null?(int)$r['app_advance']:null,'source_name'=>$r['source_name'],'excel_row'=>$r['excel_row']===null?null:(int)$r['excel_row'],'entries'=>$ents[$id]??[],
            'prev_balance'=>$prevBal[(int)$r['employee_id']]??null]+$c;
        $o['issues']=sal_row_issues($o,$days,$m,$prevMo!==null); $out[]=$o;
        $tot['employees']++; foreach(['monthly','earned','advance','fine','bonus','bf','net','paid','balance'] as $k) $tot[$k]+=$o[$k];
        $tot['leave_cost']+=intdiv((int)$r['monthly']*sal_hund($r['leave_days'])+$days*50,$days*100);
        if($c['status']==='Pending'){ $tot['pending']+=$c['balance']; $tot['pending_n']++; } elseif($c['status']==='Overpaid'){ $tot['overpaid']+=$c['balance']; $tot['overpaid_n']++; } else $tot['clear']++;
        $md=$r['pay_mode']?:'(blank)'; $tot['by_mode'][$md]=($tot['by_mode'][$md]??0)+$c['paid'];
        $sec=&$tot['by_section'][$r['section']]; $sec??=['employees'=>0,'earned'=>0,'net'=>0,'paid'=>0,'balance'=>0]; $sec['employees']++; $sec['earned']+=$c['earned']; $sec['net']+=$c['net']; $sec['paid']+=$c['paid']; $sec['balance']+=$c['balance']; unset($sec);
    }
    $mo['days']=$days; $mo['tolerance']=$tol;
    return ['month'=>$mo,'rows'=>$out,'totals'=>$tot,'prev_exists'=>$prevMo!==null,'next_exists'=>(bool)row('SELECT 1 FROM dd_sal_months WHERE month=?',sal_next($m))];
}
function sal_row_issues(array $o,int $days,string $m,bool $prevExists): array {
    $i=[];
    if($o['present']>$days) $i[]=['high','Present days '.$o['present'].' are more than the '.$days.' days in this month'];
    if(fmod($o['present']*2,1)!==0.0) $i[]=['low','Present days '.$o['present'].' is not a whole/half day — typo?'];
    if($o['net']<0) $i[]=['high','Net salary is negative: advance/old balance is more than earnings'];
    if($o['status']==='Overpaid') $i[]=['high','Overpaid by ₹'.number_format(-$o['balance']/100,0)];
    if($o['paid']>0&&!$o['pay_mode']) $i[]=['medium','Payment mode is blank'];
    if($o['pay_mode']&&!in_array($o['pay_mode'],sal_modes(),true)) $i[]=['medium','Paid through “'.$o['pay_mode'].'” — keep a signed receipt from the staff'];
    if(!$o['department']) $i[]=['low','Department is blank'];
    if($o['leave_days']>0&&$o['present']<15) $i[]=['low','Leave encash given with only '.$o['present'].' present days'];
    if($o['present']==0&&$o['leave_days']==0&&($o['advance']||$o['paid'])) $i[]=['medium','0 days worked but advance/payment recorded'];
    if($prevExists&&$o['prev_balance']!==null&&abs($o['prev_balance']-$o['bf'])>100) $i[]=['high','Last month closing balance ₹'.number_format($o['prev_balance']/100,0).' but B/F here is ₹'.number_format($o['bf']/100,0).' — carry forward missing'];
    if($o['app_days']!==null&&abs($o['present']-$o['app_days'])>0.5) $i[]=['medium','Attendance app shows '.$o['app_days'].' paid days but sheet has '.$o['present'].' present'];
    if($o['app_wage']&&abs($o['app_wage']*$days-$o['monthly'])>$days*100) $i[]=['info','Attendance app daily wage ₹'.number_format($o['app_wage']/100,2).' (= ₹'.number_format($o['app_wage']*$days/100,0).'/month) differs from sheet salary ₹'.number_format($o['monthly']/100,0)];
    if($o['app_penalty']&&$o['app_penalty']!==$o['fine']) $i[]=['low','Attendance app penalty ₹'.number_format($o['app_penalty']/100,0).' vs sheet fine ₹'.number_format($o['fine']/100,0)];
    if($o['master_salary']&&$o['master_salary']!==$o['monthly']) $i[]=['info','Salary on sheet ₹'.number_format($o['monthly']/100,0).' differs from staff master ₹'.number_format($o['master_salary']/100,0)];
    return $i;
}
function sal_months_list(): array {
    $o=[]; foreach(rows('SELECT * FROM dd_sal_months ORDER BY month DESC') as $mo){ $v=sal_month_view($mo['month']); $t=$v['totals']; unset($t['by_mode']);
        $iss=['high'=>0,'medium'=>0,'low'=>0]; foreach($v['rows'] as $r) foreach($r['issues'] as $x) if(isset($iss[$x[0]])) $iss[$x[0]]++;
        $o[]=['month'=>$mo['month'],'days'=>(int)$mo['days'],'status'=>$mo['status'],'source'=>$mo['source'],'totals'=>$t,'issues'=>$iss,'by_mode'=>$v['totals']['by_mode']]; }
    return $o;
}

/* ============================== mutations ============================== */
function sal_row_lock(int $id): array { $r=row('SELECT * FROM dd_sal_rows WHERE id=? FOR UPDATE',$id); if(!$r) fail('Salary row not found.',404); sal_open_month($r['month']); return $r; }
function sal_row_save(array $b,array $u): array {
    $r=sal_row_lock((int)($b['id']??0)); $days=(int)sal_month($r['month'])['days']; $set=[]; $args=[];
    $f=['present'=>fn($v)=>sal_days_val($v,'Present days',$days+5),'leave_days'=>fn($v)=>sal_days_val($v,'Leave encash',10),'monthly'=>fn($v)=>sal_paise($v,'Monthly salary'),'bf'=>fn($v)=>sal_paise($v,'B/F balance',true),
        'section'=>fn($v)=>in_array($v,sal_sections(),true)?$v:fail('Choose a valid section.'),'department'=>fn($v)=>txt($v,80)?:null,'pay_mode'=>fn($v)=>txt($v,40)?:null,'note'=>fn($v)=>txt($v,250)?:null,'sort'=>fn($v)=>(int)$v];
    $old=[]; foreach($f as $k=>$fn) if(array_key_exists($k,$b)){ $set[]="`$k`=?"; $args[]=$fn($b[$k]); $old[$k]=$r[$k]; }
    if(!$set) fail('Nothing to save.');
    run('UPDATE dd_sal_rows SET '.implode(',',$set).',updated_by=? WHERE id=?',...[...$args,$u['id'],$r['id']]);
    audit($u,'salary.row.update',['row'=>(int)$r['id'],'month'=>$r['month'],'old'=>$old,'new'=>array_combine(array_keys($old),$args)]);
    return sal_row_out((int)$r['id']);
}
function sal_row_out(int $id): array { $r=row('SELECT month FROM dd_sal_rows WHERE id=?',$id); $v=sal_month_view($r['month']); foreach($v['rows'] as $x) if($x['id']===$id) return ['row'=>$x,'totals'=>$v['totals']]; fail('Row not found.',404); }
function sal_entry_add(array $b,array $u): array {
    $r=sal_row_lock((int)($b['row_id']??0)); $kind=(string)($b['kind']??''); if(!in_array($kind,SAL_KINDS,true)) fail('Choose advance, fine, bonus or payment.');
    $amt=sal_paise($b['amount']??'','Amount'); if($amt<=0) fail('Amount must be more than zero.');
    // Payment date ≠ earned month (fix C12): late salary is common, so allow up to 3 months after the sheet month; a date before the month needs it to be an advance.
    $d=!empty($b['date'])?valid_date($b['date']):null; $lim=(new DateTimeImmutable($r['month'].'-01'))->modify('+3 month')->format('Y-m');
    if($d&&substr($d,0,7)>$lim) fail('Payment date '.$d.' is more than 3 months after salary month '.$r['month'].'. Check the date.');
    if($d&&$kind==='payment'&&substr($d,0,7)<sal_prev($r['month'])) fail('A salary payment cannot be dated more than a month before the month it pays. Record it as an advance.');
    $mode=$kind==='payment'?(txt($b['mode']??'',40)?:($r['pay_mode']?:'Cash')):null;
    run('INSERT INTO dd_sal_entries(row_id,month,employee_id,kind,amount,date,mode,note,created_by) VALUES(?,?,?,?,?,?,?,?,?)',$r['id'],$r['month'],$r['employee_id'],$kind,$amt,$d,$mode,txt($b['note']??'',250)?:null,$u['id']);
    $eid=(int)db()->lastInsertId();
    if($kind==='payment'&&$mode&&$mode!==$r['pay_mode']) run('UPDATE dd_sal_rows SET pay_mode=? WHERE id=?',$mode,$r['id']);
    audit($u,'salary.entry.add',['id'=>$eid,'row'=>(int)$r['id'],'kind'=>$kind,'amount'=>$amt]);
    return sal_row_out((int)$r['id'])+['entry_id'=>$eid];
}
function sal_entry_void(array $b,array $u): array {
    $e=row('SELECT * FROM dd_sal_entries WHERE id=? FOR UPDATE',(int)($b['id']??0)); if(!$e||$e['voided']) fail('Entry not found.',404);
    sal_row_lock((int)$e['row_id']); $why=txt($b['reason']??'',250); if($why==='') fail('Write why this entry is being removed.');
    run('UPDATE dd_sal_entries SET voided=1,void_reason=? WHERE id=?',$why,$e['id']); audit($u,'salary.entry.void',['id'=>(int)$e['id'],'kind'=>$e['kind'],'amount'=>(int)$e['amount'],'reason'=>$why]);
    return sal_row_out((int)$e['row_id']);
}
function sal_pay_balance(array $b,array $u): array {
    $r=sal_row_lock((int)($b['row_id']??0)); $o=sal_row_out((int)$r['id'])['row']; if($o['balance']<=0) fail('Nothing is pending for '.$o['name'].'.');
    $amt=isset($b['amount'])&&$b['amount']!==''?sal_paise($b['amount']):$o['balance'];
    if(!empty($b['round'])) $amt=(int)(round($amt/100)*100);
    return sal_entry_add(['row_id'=>$r['id'],'kind'=>'payment','amount'=>number_format($amt/100,2,'.',''),'date'=>$b['date']??date('Y-m-d'),'mode'=>$b['mode']??null,'note'=>$b['note']??'Balance paid'],$u);
}
/** Start a salary month: copy staff from the latest month (or all active staff), carry forward closing balances. */
function sal_month_create(array $b,array $u): array {
    $m=valid_month($b['month']??''); if(row('SELECT 1 FROM dd_sal_months WHERE month=?',$m)) fail('Salary month '.$m.' already exists.',409);
    $days=(int)($b['days']??sal_month_days($m)); if($days<28||$days>31) fail('Days in month must be 28–31.');
    $carry=!empty($b['carry_forward']); $tol=sal_paise($b['tolerance']??(setting_int('salary_tolerance',1000)/100),'Tolerance');
    $from=row('SELECT month FROM dd_sal_months WHERE month<? ORDER BY month DESC LIMIT 1',$m)['month']??null;
    run('INSERT INTO dd_sal_months(month,days,tolerance,carry_forward,created_by) VALUES(?,?,?,?,?)',$m,$days,$tol,$carry?1:0,$u['id']);
    $added=0; $carried=0; $seen=[];
    if($from&&($b['copy']??'last')==='last'){
        $fv=sal_month_view($from);
        foreach($fv['rows'] as $r){ if($r['emp_status']==='left') continue; $bf=$carry?$r['balance']:0; if(abs($bf)<100) $bf=0;
            run('INSERT INTO dd_sal_rows(month,employee_id,section,department,sort,monthly,bf,pay_mode,updated_by) VALUES(?,?,?,?,?,?,?,?,?)',$m,$r['employee_id'],$r['section'],$r['department'],$added,$r['master_salary']?:$r['monthly'],$bf,$r['pay_mode'],$u['id']);
            $added++; $seen[$r['employee_id']]=1; if($bf) $carried++; }
    }
    foreach(rows("SELECT * FROM dd_employees WHERE status='active' AND active=1 ORDER BY name") as $e){ if(isset($seen[(int)$e['id']])) continue;
        if($from&&($b['copy']??'last')==='last'&&empty($b['include_new'])) continue;
        $bf=0; if($carry&&$from){ $cl=sal_closing((int)$e['id'],$from); if($cl!==null&&abs($cl)>=100) $bf=$cl; }
        run('INSERT INTO dd_sal_rows(month,employee_id,section,department,sort,monthly,bf,pay_mode,updated_by) VALUES(?,?,?,?,?,?,?,?,?)',$m,$e['id'],in_array($e['section'],sal_sections(),true)?$e['section']:sal_sections()[0],$e['department'],$added,(int)$e['salary'],$bf,$e['pay_mode'],$u['id']); $added++; if($bf) $carried++; }
    audit($u,'salary.month.create',['month'=>$m,'days'=>$days,'from'=>$from,'rows'=>$added,'carried'=>$carried]);
    return ['ok'=>true,'month'=>$m,'rows'=>$added,'carried'=>$carried];
}
/** Re-pull B/F from the previous month's closing balance for every row (after last month was corrected). */
function sal_refresh_bf(array $b,array $u): array {
    $m=valid_month($b['month']??''); sal_open_month($m); $prev=sal_prev($m); if(!row('SELECT 1 FROM dd_sal_months WHERE month=?',$prev)) fail('Previous month '.$prev.' is not in the register.');
    $n=0; foreach(rows('SELECT id,employee_id,bf FROM dd_sal_rows WHERE month=?',$m) as $r){ $cl=sal_closing((int)$r['employee_id'],$prev)??0; if(abs($cl)<100) $cl=0; if($cl!==(int)$r['bf']){ run('UPDATE dd_sal_rows SET bf=? WHERE id=?',$cl,$r['id']); $n++; } }
    audit($u,'salary.bf.refresh',['month'=>$m,'changed'=>$n]); return ['ok'=>true,'changed'=>$n];
}
function sal_row_add(array $b,array $u): array {
    $m=valid_month($b['month']??''); $mo=sal_open_month($m); $emp=(int)($b['employee_id']??0);
    if(!$emp){ $emp=sal_employee_save(['name'=>$b['name']??'','section'=>$b['section']??'','department'=>$b['department']??'','salary'=>$b['monthly']??'0','pay_mode'=>$b['pay_mode']??'Cash','joined_on'=>$b['joined_on']??'','confirm_duplicate'=>$b['confirm_duplicate']??false],$u)['id']; }
    $e=row('SELECT * FROM dd_employees WHERE id=?',$emp); if(!$e) fail('Employee not found.');
    if(row('SELECT 1 FROM dd_sal_rows WHERE month=? AND employee_id=?',$m,$emp)) fail($e['name'].' is already on the '.$m.' sheet.',409);
    $bf=0; $cl=sal_closing($emp,sal_prev($m)); if($mo['carry_forward']&&$cl!==null&&abs($cl)>=100) $bf=$cl;
    $sec=in_array($b['section']??'',sal_sections(),true)?$b['section']:(in_array($e['section'],sal_sections(),true)?$e['section']:sal_sections()[0]);
    run('INSERT INTO dd_sal_rows(month,employee_id,section,department,sort,monthly,bf,pay_mode,updated_by) VALUES(?,?,?,?,999,?,?,?,?)',$m,$emp,$sec,$e['department'],(int)$e['salary'],$bf,$e['pay_mode']?:'Cash',$u['id']);
    $id=(int)db()->lastInsertId(); audit($u,'salary.row.add',['month'=>$m,'employee'=>$emp]); return sal_row_out($id);
}
/** Full contents are kept before any delete (fix C18: deletions are reconstructible, not just a count in the audit log). */
function sal_archive(string $kind,string $ref,array $payload,array $u,string $reason): void { $j=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    try{ run('INSERT INTO dd_archive(kind,ref,payload,sha256,reason,created_by) VALUES(?,?,?,?,?,?)',$kind,$ref,$j,hash('sha256',$j),$reason,$u['id']); }catch(PDOException $e){ if(($e->errorInfo[1]??0)===1146) fail('Run the database update (migrate.php) before deleting salary records.',503); throw $e; } }
function sal_row_remove(array $b,array $u): array {
    $r=sal_row_lock((int)($b['id']??0)); if(row('SELECT 1 FROM dd_sal_entries WHERE row_id=? AND voided=0',$r['id'])) fail('This row has advance/fine/payment entries. Remove those first.');
    if(row("SELECT 1 FROM dd_sal_entries WHERE row_id=? AND kind IN ('advance','payment')",$r['id'])) fail('This row has (voided) payment history and is kept. Set present days to 0 instead of removing it.',409);
    sal_archive('salary-row-remove',(string)$r['id'],['row'=>$r,'entries'=>rows('SELECT * FROM dd_sal_entries WHERE row_id=?',$r['id'])],$u,'row removed');
    run('DELETE FROM dd_sal_rows WHERE id=?',$r['id']); audit($u,'salary.row.remove',['row'=>$r]); return ['ok'=>true];
}
function sal_month_lock(array $b,array $u,bool $lock): array {
    $m=valid_month($b['month']??''); $mo=sal_month($m,true); $zero=[];
    if($lock){ if($mo['status']==='locked') fail('Already locked.');
        // T24: staff with days worked but no wage are shown and need a written reason — a ₹0 salary is never approved silently.
        foreach(sal_month_view($m)['rows'] as $r) if(($r['present']+$r['leave_days'])>0&&$r['monthly']===0) $zero[]=$r['name'];
        if($zero&&mb_strlen(txt($b['zero_wage_reason']??''))<5) fail(count($zero).' staff have days worked but ₹0 monthly salary: '.implode(', ',array_slice($zero,0,12)).(count($zero)>12?'…':'').'. Enter their salary, or write why ₹0 is correct to lock.',409);
        run("UPDATE dd_sal_months SET status='locked',locked_by=?,locked_at=?".($zero?",note=CONCAT(COALESCE(note,''),?)":'')." WHERE month=?",...($zero?[$u['id'],now_sql(),' [Locked with ₹0 wage for '.count($zero).' staff: '.txt($b['zero_wage_reason'],200).']',$m]:[$u['id'],now_sql(),$m])); }
    else { if(!can($u,'*')) fail('Only the owner can unlock a salary month.',403); $why=txt($b['reason']??'',250); if(mb_strlen($why)<5) fail('Write the reason for unlocking.'); run("UPDATE dd_sal_months SET status='open' WHERE month=?",$m); }
    audit($u,$lock?'salary.month.lock':'salary.month.unlock',['month'=>$m,'reason'=>$b['reason']??null,'zero_wage'=>$zero]);
    require_once __DIR__.'/post360.php'; $f=fin_sync($u); // unified posting: accrual posted on lock / reversed on unlock (only when unification is on)
    return ['ok'=>true,'events'=>$f];
}
function sal_month_settings(array $b,array $u): array {
    $m=valid_month($b['month']??''); sal_open_month($m); $days=(int)($b['days']??0); if($days<28||$days>31) fail('Days must be 28–31.');
    run('UPDATE dd_sal_months SET days=?,tolerance=?,note=? WHERE month=?',$days,sal_paise($b['tolerance']??'10','Tolerance'),txt($b['note']??'',1000)?:null,$m);
    audit($u,'salary.month.settings',['month'=>$m,'days'=>$days]); return ['ok'=>true];
}
function sal_month_delete(array $b,array $u): array {
    if(!can($u,'*')) fail('Only the owner can delete a salary month.',403); $m=valid_month($b['month']??''); sal_open_month($m);
    if(($b['confirm']??'')!==$m) fail('Type the month ('.$m.') to confirm.');
    if(row('SELECT 1 FROM dd_sal_months WHERE month>?',$m)) fail('Delete later months first — their B/F depends on this month.');
    // Fix C18: a month that records money paid (advances / payments, even voided ones) is history and cannot be deleted. Void wrong entries instead.
    $money=(int)row("SELECT COUNT(*) n FROM dd_sal_entries WHERE month=? AND kind IN ('advance','payment')",$m)['n'];
    if($money) fail('Salary '.$m.' has '.$money.' advance/payment record(s). Months with payment history cannot be deleted — void wrong entries (with a reason) and lock the month instead.',409);
    try{ if(row("SELECT 1 FROM dd_fin_events WHERE source_system='salary' AND (source_table='dd_sal_months' AND source_id=? OR source_table='dd_sal_entries' AND source_id IN (SELECT id FROM dd_sal_entries WHERE month=?)) AND status IN ('posted','linked')",(int)str_replace('-','',$m),$m)) fail('Salary '.$m.' is posted to the accounts and cannot be deleted.',409); }catch(PDOException $e){ if(($e->errorInfo[1]??0)!==1146) throw $e; }
    $arch=['month'=>row('SELECT * FROM dd_sal_months WHERE month=?',$m),'rows'=>rows('SELECT * FROM dd_sal_rows WHERE month=?',$m),'entries'=>rows('SELECT * FROM dd_sal_entries WHERE month=?',$m)];
    $n=count($arch['entries']); sal_archive('salary-month-delete',$m,$arch,$u,'deleted by owner');
    run('DELETE FROM dd_sal_entries WHERE month=?',$m); run('DELETE FROM dd_sal_rows WHERE month=?',$m); run('DELETE FROM dd_sal_months WHERE month=?',$m);
    audit($u,'salary.month.delete',['month'=>$m,'entries'=>$n,'archived'=>true]); return ['ok'=>true];
}

/* ============================== employees ============================== */
function sal_employees(): array {
    $last=[]; foreach(rows('SELECT employee_id,MAX(month) m,COUNT(*) n FROM dd_sal_rows GROUP BY employee_id') as $x) $last[(int)$x['employee_id']]=$x;
    $out=[]; foreach(rows('SELECT * FROM dd_employees ORDER BY status=\'left\',name') as $e){ $id=(int)$e['id']; $l=$last[$id]??null;
        $out[]=['id'=>$id,'code'=>$e['code'],'name'=>$e['name'],'section'=>$e['section'],'department'=>$e['department'],'designation'=>$e['designation']??null,'salary'=>(int)$e['salary'],'status'=>$e['status']??'active','joined_on'=>$e['joined_on'],'left_on'=>$e['left_on'],'phone'=>$e['phone'],'aliases'=>$e['aliases'],'pay_mode'=>$e['pay_mode'],
          'months'=>$l?(int)$l['n']:0,'last_month'=>$l['m']??null,'last_balance'=>$l?sal_closing($id,$l['m']):null]; }
    return $out;
}
function sal_next_code(): string { $n=0; foreach(rows("SELECT code FROM dd_employees WHERE code LIKE 'KS%'") as $r) if(preg_match('/(\d+)$/',(string)$r['code'],$m)) $n=max($n,(int)$m[1]); return 'KS'.str_pad((string)($n+1),3,'0',STR_PAD_LEFT); }
function sal_employee_save(array $b,array $u): array {
    $id=(int)($b['id']??0); $name=name_required($b['name']??''); $status=in_array($b['status']??'active',['active','inactive','left'],true)?($b['status']??'active'):'active';
    $sec=in_array($b['section']??'',sal_sections(),true)?$b['section']:null;
    $v=['name'=>$name,'section'=>$sec,'department'=>txt($b['department']??'',80)?:null,'salary'=>sal_paise($b['salary']??'0','Monthly salary'),'status'=>$status,'active'=>$status==='active'?1:0,
        'joined_on'=>!empty($b['joined_on'])?valid_date($b['joined_on']):null,'left_on'=>$status==='left'&&!empty($b['left_on'])?valid_date($b['left_on']):null,'phone'=>txt($b['phone']??'',20)?:null,'aliases'=>txt($b['aliases']??'',400)?:null,'pay_mode'=>txt($b['pay_mode']??'',40)?:null,'job'=>txt($b['department']??'',100)?:null];
    if($id){ $old=row('SELECT * FROM dd_employees WHERE id=? FOR UPDATE',$id); if(!$old) fail('Employee not found.',404);
        $code=txt($b['code']??'',40)?:($old['code']?:sal_next_code()); if($code!==$old['code']&&row('SELECT id FROM dd_employees WHERE code=? AND id<>?',$code,$id)) fail('Code '.$code.' is already used.',409);
        $v['code']=$code; run('UPDATE dd_employees SET '.implode(',',array_map(fn($k)=>"`$k`=?",array_keys($v))).' WHERE id=?',...[...array_values($v),$id]); audit($u,'salary.employee.update',['id'=>$id,'old'=>$old,'new'=>$v]); }
    else { $k=sal_key($name); if(empty($b['confirm_duplicate'])) foreach(rows('SELECT id,name FROM dd_employees') as $e) if(sal_key($e['name'])===$k) fail('“'.$e['name'].'” already exists (#'.$e['id'].'). Tick “This is a different person” to add anyway.',409);
        $v['code']=txt($b['code']??'',40)?:sal_next_code(); $v['created_by']=$u['id'];
        run('INSERT INTO dd_employees('.implode(',',array_map(fn($k)=>"`$k`",array_keys($v))).') VALUES('.implode(',',array_fill(0,count($v),'?')).')',...array_values($v)); $id=(int)db()->lastInsertId(); audit($u,'salary.employee.create',['id'=>$id,'name'=>$name]); }
    return ['ok'=>true,'id'=>$id];
}
function sal_employee_card(int $id): array {
    $e=row('SELECT * FROM dd_employees WHERE id=?',$id); if(!$e) fail('Employee not found.',404); $hist=[];
    foreach(rows('SELECT month FROM dd_sal_rows WHERE employee_id=? ORDER BY month',$id) as $x){ $v=sal_month_view($x['month']); foreach($v['rows'] as $r) if($r['employee_id']===$id){ $r['days']=(int)$v['month']['days']; $hist[]=['month'=>$x['month']]+$r; } }
    return ['employee'=>$e,'history'=>$hist];
}
/** Latest balance of every employee (positive = restaurant owes staff, negative = staff owes restaurant). */
function sal_dues(): array {
    $latest=row('SELECT MAX(month) m FROM dd_sal_months')['m']??null; $out=[];
    foreach(rows('SELECT employee_id,MAX(month) m FROM dd_sal_rows GROUP BY employee_id') as $x){ $bal=sal_closing((int)$x['employee_id'],$x['m']); if($bal===null||abs($bal)<100) continue;
        $e=row('SELECT id,code,name,status,section FROM dd_employees WHERE id=?',$x['employee_id']); $r=row('SELECT id FROM dd_sal_rows WHERE month=? AND employee_id=?',$x['m'],$x['employee_id']);
        $out[]=['employee_id'=>(int)$e['id'],'code'=>$e['code'],'name'=>$e['name'],'status'=>$e['status'],'section'=>$e['section'],'month'=>$x['m'],'row_id'=>(int)$r['id'],'balance'=>$bal,'left_sheet'=>$x['m']!==$latest,'locked'=>row('SELECT status FROM dd_sal_months WHERE month=?',$x['m'])['status']==='locked']; }
    usort($out,fn($a,$b)=>$b['balance']<=>$a['balance']); return ['latest'=>$latest,'rows'=>$out];
}

/* ============================== Excel import (reads formulas so =8000+1000 becomes 2 entries) ============================== */
function sal_xlsx_cells(string $data): array {
    if(!class_exists('ZipArchive')) fail('PHP zip extension is missing on this server.',503);
    if(strlen($data)>8000000) fail('Excel file is larger than 8 MB.',413); if(substr($data,0,2)!=='PK') fail('Upload the .xlsx workbook (Excel 2007 or newer).');
    $tmp=tempnam(sys_get_temp_dir(),'sal'); file_put_contents($tmp,$data); $z=new ZipArchive(); if($z->open($tmp)!==true){ @unlink($tmp); fail('Excel file could not be opened.'); }
    try{
        $x=function(string $n)use($z): ?SimpleXMLElement { $s=$z->getFromName($n); if($s===false) return null; if(strlen($s)>40000000) fail('Workbook too large.'); $p=libxml_use_internal_errors(true); $r=simplexml_load_string($s,SimpleXMLElement::class,LIBXML_NONET|LIBXML_COMPACT); libxml_use_internal_errors($p); return $r?:null; };
        $wb=$x('xl/workbook.xml'); $rels=$x('xl/_rels/workbook.xml.rels'); if(!$wb||!$rels) fail('Not a valid Excel workbook.');
        $rm=[]; foreach($rels->Relationship as $r) $rm[(string)$r['Id']]=ltrim(preg_replace('#^/?xl/#','',(string)$r['Target']),'/');
        $ss=[]; if($s=$x('xl/sharedStrings.xml')) foreach($s->si as $si){ if(isset($si->t)) $ss[]=(string)$si->t; else { $t=''; foreach($si->r as $rr) $t.=(string)$rr->t; $ss[]=$t; } }
        $out=[];
        foreach($wb->sheets->sheet as $sh){ $rid=(string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id']; $sx=$x('xl/'.($rm[$rid]??'')); if(!$sx) continue; $cells=[];
            foreach($sx->sheetData->row as $row) foreach($row->c as $c){ $ref=(string)$c['r']; $t=(string)$c['t']; $v=isset($c->v)?(string)$c->v:null;
                if($t==='s'&&$v!==null) $v=$ss[(int)$v]??''; elseif($t==='inlineStr') $v=(string)($c->is->t??'');
                $f=isset($c->f)?trim((string)$c->f):null; $cells[$ref]=['v'=>$v,'f'=>$f!==''?$f:null]; }
            $out[(string)$sh['name']]=$cells; }
        return $out;
    } finally { $z->close(); @unlink($tmp); }
}
/** Value + itemised terms of a data cell (=8000+1000 → [8000,1000]). */
function sal_terms(?array $c): array {
    if(!$c||($c['v']===null&&$c['f']===null)) return [0.0,[]];
    if($c['f']!==null){ $b=str_replace(' ','',$c['f']); if(preg_match('/^[-+]?\d+(\.\d+)?([-+]\d+(\.\d+)?)*$/',$b)){ preg_match_all('/[-+]?\d+(?:\.\d+)?/',$b,$m); $t=array_map('floatval',$m[0]); return [array_sum($t),$t]; } }
    if($c['v']===null||$c['v']==='') return [0.0,[]]; if(!is_numeric($c['v'])) return [0.0,[]]; $f=(float)$c['v']; return [$f,$f!=0.0?[$f]:[]];
}
const SAL_MONTH_NAMES=['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
function sal_parse_workbook(string $data): array {
    $sheets=sal_xlsx_cells($data); $months=[];
    foreach($sheets as $sname=>$cells){
        $title=(string)($cells['B1']['v']??$sname); $mon=null; $yr=null;
        if(preg_match('/(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*[^0-9]*(20\d\d)?/i',$title.' '.$sname,$mm)){ $mon=SAL_MONTH_NAMES[strtolower($mm[1])]; $yr=$mm[2]??null; }
        if(!$mon) continue; if(!$yr&&preg_match('/(20\d\d)/',$title,$y)) $yr=$y[1]; $yr=$yr?:date('Y');
        $month=sprintf('%04d-%02d',$yr,$mon); $days=(int)round((float)($cells['O1']['v']??0)); if($days<28||$days>31) $days=sal_month_days($month);
        $maxRow=0; foreach(array_keys($cells) as $ref) if(preg_match('/(\d+)$/',$ref,$m)) $maxRow=max($maxRow,(int)$m[1]);
        $rows=[]; $sec=null; $g=fn(string $c,int $r)=>$cells[$c.$r]??null; $sv=fn(string $c,int $r)=>trim((string)($cells[$c.$r]['v']??''));
        for($r=1;$r<=$maxRow;$r++){
            $a=$sv('A',$r); $bcell=$g('B',$r); $b=trim((string)($bcell['v']??'')); $bf=$bcell['f']??null;
            if($a!==''&&!is_numeric($a)&&strtolower($a)!=='s.no'&&$b===''&&$bf===null){ $sec=sal_section_name($a); continue; }
            if($b===''||$bf!==null||in_array(strtolower($b),['employee name','name'],true)||$r<6) continue;
            [$monthly]=sal_terms($g('D',$r)); if($monthly<=0) continue;
            [$present]=sal_terms($g('E',$r)); [$leave]=sal_terms($g('F',$r)); [$adv,$advT]=sal_terms($g('J',$r)); [$debt,$debtT]=sal_terms($g('K',$r)); [$paid,$paidT]=sal_terms($g('N',$r));
            $rows[]=['excel_row'=>$r,'section'=>$sec?:'Waiter','name'=>preg_replace('/\s+/',' ',$b),'department'=>trim($sv('C',$r))?:null,'monthly'=>(int)round($monthly*100),'present'=>$present,'leave'=>$leave,
                     'advance_terms'=>array_map(fn($t)=>(int)round($t*100),$advT),'debt_terms'=>array_map(fn($t)=>(int)round($t*100),$debtT),'paid_terms'=>array_map(fn($t)=>(int)round($t*100),$paidT),'mode'=>trim($sv('M',$r))?:null];
        }
        if($rows){ if(isset($months[$month])) fail('Two sheets are for '.$month.'.'); $months[$month]=['month'=>$month,'sheet'=>$sname,'days'=>$days,'rows'=>$rows]; }
    }
    if(!$months) fail('No salary sheets found. Each sheet needs a title like “SALARY - JUNE 2026” in B1 and staff rows with Employee Name / Monthly Salary.');
    ksort($months);
    // Identity keys: a name used twice in one month is told apart by department (e.g. two staff with the same name).
    $dupe=[]; foreach($months as $m) { $c=array_count_values(array_map(fn($r)=>sal_key($r['name']),$m['rows'])); foreach($c as $k=>$n) if($n>1) $dupe[$k]=1; }
    foreach($months as &$m) foreach($m['rows'] as &$r){ $k=sal_key($r['name']); $r['key']=isset($dupe[$k])?$k.'|'.sal_key((string)$r['department']):$k; } unset($m,$r);
    return $months;
}
function sal_section_name(string $s): string { $k=strtolower(preg_replace('/\s+/',' ',trim($s))); foreach(sal_sections() as $x) if(strtolower($x)===$k) return $x; return ucwords($k); }
function sal_lev_ok(string $a,string $b): bool { if($a===''||$b==='') return false; if(strlen($a)>=4&&strlen($b)>=4&&levenshtein($a,$b)<=1) return true; [$s,$l]=strlen($a)<strlen($b)?[$a,$b]:[$b,$a]; return strlen($s)>=5&&str_starts_with($l,$s); }
/** Preview: people found, suggested same-person merges (only names that never appear in the same month), match to existing staff. */
function sal_import_preview(string $data): array {
    $months=sal_parse_workbook($data); $people=[];
    foreach($months as $m) foreach($m['rows'] as $r){ $p=&$people[$r['key']]; $p??=['key'=>$r['key'],'names'=>[],'months'=>[],'section'=>$r['section'],'department'=>$r['department'],'monthly'=>$r['monthly']]; if(!in_array($r['name'],$p['names'],true)) $p['names'][]=$r['name']; $p['months'][]=$m['month']; $p['section']=$r['section']; $p['department']=$r['department']?:$p['department']; $p['monthly']=$r['monthly']; unset($p); }
    $keys=array_keys($people); $merges=[];
    foreach($keys as $i=>$a) foreach(array_slice($keys,$i+1) as $b){ if(array_intersect($people[$a]['months'],$people[$b]['months'])) continue;
        $ka=explode('|',$a)[0]; $kb=explode('|',$b)[0]; if($ka===$kb||sal_lev_ok($ka,$kb)) $merges[]=[$a,$b,$people[$a]['names'][0].' ↔ '.$people[$b]['names'][0]]; }
    $emps=rows('SELECT id,code,name,aliases,status FROM dd_employees ORDER BY name');
    foreach($people as &$p){ $p['match']=null; foreach($emps as $e){ $ks=array_merge([sal_key($e['name'])],array_map('sal_key',array_filter(array_map('trim',explode(',',(string)$e['aliases']))))); if(array_intersect($ks,array_map('sal_key',$p['names']))){ $p['match']=(int)$e['id']; break; } } } unset($p);
    $sum=[]; foreach($months as $m){ $ex=row('SELECT status FROM dd_sal_months WHERE month=?',$m['month']); $sum[]=['month'=>$m['month'],'sheet'=>$m['sheet'],'days'=>$m['days'],'rows'=>count($m['rows']),'exists'=>$ex?$ex['status']:null,
        'monthly'=>array_sum(array_column($m['rows'],'monthly')),'paid'=>array_sum(array_map(fn($r)=>array_sum($r['paid_terms']),$m['rows'])),'advance'=>array_sum(array_map(fn($r)=>array_sum($r['advance_terms']),$m['rows']))]; }
    return ['months'=>$sum,'people'=>array_values($people),'merges'=>$merges,'employees'=>array_map(fn($e)=>['id'=>(int)$e['id'],'code'=>$e['code'],'name'=>$e['name'],'status'=>$e['status']],$emps)];
}
/**
 * Confirm: $groups = [[key,...]] people to treat as one employee; $map[firstKey] = employee id | 'new'; $only = months to import; $replace = overwrite open months.
 * Carry-forward detection: an advance term equal to minus last month's closing balance becomes B/F (exactly what the sheet did by hand).
 */
function sal_import_confirm(string $data,string $fname,array $b,array $u): array {
    $months=sal_parse_workbook($data); $only=array_values(array_filter((array)($b['months']??[]),'is_string')); $replace=!empty($b['replace']);
    $groups=(array)($b['groups']??[]); $map=(array)($b['map']??[]); $owner=[];
    foreach($groups as $g){ $g=array_values(array_filter((array)$g,'is_string')); if(!$g) continue; foreach($g as $k) $owner[$k]=$g[0]; }
    $people=[]; foreach($months as $m) foreach($m['rows'] as $r){ $gk=$owner[$r['key']]??$r['key']; $people[$gk][]=$r+['month'=>$m['month']]; }
    $empOf=[]; $created=0; $linked=0;
    foreach($people as $gk=>$rs){ $want=$map[$gk]??'new'; $last=end($rs);
        if($want!=='new'&&(int)$want>0){ if(!row('SELECT id FROM dd_employees WHERE id=?',(int)$want)) fail('Employee #'.$want.' not found.'); $empOf[$gk]=(int)$want; $linked++; }
        else { $names=array_values(array_unique(array_column($rs,'name'))); $nm=preg_replace('/\s*\((new)\)\s*/i','',end($names));
            $id=sal_employee_save(['name'=>$nm,'section'=>$last['section'],'department'=>$last['department'],'salary'=>number_format($last['monthly']/100,2,'.',''),'status'=>'active','aliases'=>implode(', ',array_diff($names,[$nm])),'pay_mode'=>in_array($last['mode'],sal_modes(),true)?$last['mode']:'Cash','confirm_duplicate'=>true],$u)['id'];
            $empOf[$gk]=$id; $created++; }
        $e=$empOf[$gk]; $names=array_values(array_unique(array_column($rs,'name'))); $cur=row('SELECT name,aliases FROM dd_employees WHERE id=?',$e); $al=array_filter(array_map('trim',explode(',',(string)$cur['aliases'])));
        foreach($names as $n) if(sal_key($n)!==sal_key($cur['name'])&&!in_array($n,$al,true)) $al[]=$n; run('UPDATE dd_employees SET aliases=? WHERE id=?',implode(', ',$al)?:null,$e);
    }
    $res=['months'=>[],'created'=>$created,'linked'=>$linked,'rows'=>0,'entries'=>0,'carried'=>0];
    foreach($months as $m){ $mm=$m['month']; if($only&&!in_array($mm,$only,true)) continue;
        $ex=row('SELECT * FROM dd_sal_months WHERE month=? FOR UPDATE',$mm);
        if($ex){ if($ex['status']==='locked') fail('Month '.$mm.' is locked. Unlock it before re-importing.',409); if(!$replace) fail('Month '.$mm.' already exists. Tick “Replace existing months” to overwrite it.',409);
            run('DELETE FROM dd_sal_entries WHERE month=?',$mm); run('DELETE FROM dd_sal_rows WHERE month=?',$mm); run('DELETE FROM dd_sal_months WHERE month=?',$mm); }
        run("INSERT INTO dd_sal_months(month,days,tolerance,carry_forward,source,note,created_by) VALUES(?,?,10000,1,'excel',?,?)",$mm,$m['days'],'Imported from '.txt($fname,120).' · sheet '.$m['sheet'].'. Tolerance ₹100 kept as in the Excel “Clear” rule.',$u['id']);
        $end=(new DateTimeImmutable($mm.'-01'))->format('Y-m-t'); $sort=0; $used=[];
        foreach($m['rows'] as $r){ $gk=$owner[$r['key']]??$r['key']; $e=$empOf[$gk]; if(isset($used[$e])) fail('In '.$mm.' two rows map to the same employee ('.$r['name'].'). Un-merge them.'); $used[$e]=1;
            $prev=sal_closing($e,sal_prev($mm)); $bf=0; $adv=$r['advance_terms'];
            if($prev!==null&&abs($prev)>=100) foreach($adv as $i=>$t) if(abs($t+$prev)<=150){ $bf=-$t; unset($adv[$i]); $res['carried']++; break; }
            run('INSERT INTO dd_sal_rows(month,employee_id,section,department,sort,monthly,present,leave_days,bf,pay_mode,source_name,excel_row,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
                $mm,$e,$r['section'],$r['department'],$sort++,$r['monthly'],number_format($r['present'],2,'.',''),number_format($r['leave'],2,'.',''),$bf,$r['mode'],$r['name'],$r['excel_row'],$u['id']);
            $rid=(int)db()->lastInsertId(); $res['rows']++;
            foreach([['advance',$adv],['fine',$r['debt_terms']],['payment',$r['paid_terms']]] as [$kind,$ts]) foreach($ts as $t){ if($t===0) continue;
                run("INSERT INTO dd_sal_entries(row_id,month,employee_id,kind,amount,date,mode,note,source,created_by) VALUES(?,?,?,?,?,?,?,?,'excel',?)",$rid,$mm,$e,$kind,$t,$end,$kind==='payment'?$r['mode']:null,'Excel '.$m['sheet'].' row '.$r['excel_row'].($t<0?' (negative in sheet)':''),$u['id']); $res['entries']++; }
        }
        $res['months'][]=$mm;
    }
    // Staff who are not on the latest sheet of this workbook have left: mark them so new months do not add them back.
    $latest=max(array_keys($months)); $res['marked_left']=0;
    if(in_array($latest,$res['months'],true)) foreach($people as $gk=>$rs){ $ms=array_column($rs,'month'); if(in_array($latest,$ms,true)) continue; $lm=max($ms);
        $n=run("UPDATE dd_employees SET status='left',active=0,left_on=COALESCE(left_on,?) WHERE id=? AND status='active'",(new DateTimeImmutable($lm.'-01'))->format('Y-m-t'),$empOf[$gk])->rowCount(); $res['marked_left']+=$n; }
    run('INSERT INTO dd_sal_imports(file_name,file_sha256,months,result,created_by) VALUES(?,?,?,?,?)',txt($fname,160),hash('sha256',$data),implode(',',$res['months']),json_encode($res),$u['id']);
    audit($u,'salary.import',$res); return ['ok'=>true]+$res;
}

/* ============================== Excel export (same layout as the old workbook, with live formulas) ============================== */
function sal_xlsx_export(string $m): array {
    $v=sal_month_view($m); $days=$v['month']['days']; $title='SALARY - '.strtoupper((new DateTimeImmutable($m.'-01'))->format('F Y'));
    $X=fn($s)=>htmlspecialchars((string)$s,ENT_XML1|ENT_QUOTES,'UTF-8'); $rowsXml=[]; $rn=0;
    $cell=function(string $ref,$val,int $style=0,?string $f=null)use($X){ $s=$style?' s="'.$style.'"':''; if($f!==null) return '<c r="'.$ref.'"'.$s.'><f>'.$X($f).'</f></c>'; if($val===null||$val==='') return ''; if(is_int($val)||is_float($val)) return '<c r="'.$ref.'"'.$s.'><v>'.$val.'</v></c>'; return '<c r="'.$ref.'" t="inlineStr"'.$s.'><is><t>'.$X($val).'</t></is></c>'; };
    $add=function(array $cells)use(&$rowsXml,&$rn){ $rn++; $rowsXml[]='<row r="'.$rn.'">'.implode('',array_map(fn($c)=>$c($rn),$cells)).'</row>'; return $rn; };
    $add([fn($r)=>$cell("A$r",$title,1),fn($r)=>$cell("M$r",'Total Days',1),fn($r)=>$cell("O$r",$days,1)]); $add([]);
    $heads=['S.No','Employee Code','Employee Name','Department','Monthly Salary','Present Days','Leave Encash','Working Days','Per Day Salary','Total Salary','B/F (prev. balance)','Bonus','Advance Salary','Debt / Fine','Net Salary','Payment Mode','Paid Amount','Balance Amount','Payment Status'];
    $cols=range('A','S'); $secRows=[];
    foreach(sal_sections() as $sec){ $rs=array_values(array_filter($v['rows'],fn($r)=>$r['section']===$sec)); if(!$rs) continue;
        $add([fn($r)=>$cell("A$r",strtoupper($sec),1)]); $add(array_map(fn($h,$c)=>fn($r)=>$cell($c.$r,$h,2),$heads,$cols)); $first=$rn+1;
        foreach($rs as $i=>$o){ $add([fn($r)=>$cell("A$r",$i+1),fn($r)=>$cell("B$r",$o['code']),fn($r)=>$cell("C$r",$o['name']),fn($r)=>$cell("D$r",$o['department']),fn($r)=>$cell("E$r",$o['monthly']/100),fn($r)=>$cell("F$r",$o['present']),fn($r)=>$cell("G$r",$o['leave_days']),
            fn($r)=>$cell("H$r",null,0,"F$r+G$r"),fn($r)=>$cell("I$r",null,3,"E$r/\$O\$1"),fn($r)=>$cell("J$r",null,3,"ROUND(I$r*H$r,2)"),fn($r)=>$cell("K$r",$o['bf']/100),fn($r)=>$cell("L$r",$o['bonus']/100),fn($r)=>$cell("M$r",$o['advance']/100),fn($r)=>$cell("N$r",$o['fine']/100),
            fn($r)=>$cell("O$r",null,3,"J$r+K$r+L$r-M$r-N$r"),fn($r)=>$cell("P$r",$o['pay_mode']),fn($r)=>$cell("Q$r",$o['paid']/100),fn($r)=>$cell("R$r",null,3,"O$r-Q$r"),fn($r)=>$cell("S$r",null,0,'IF(ABS(R'.$r.')<='.($v['month']['tolerance']/100).',"Clear",IF(R'.$r.'>0,"Pending","Overpaid"))')]); }
        $last=$rn; $secRows[]=$add([fn($r)=>$cell("C$r",'Total '.$sec,2)]+array_combine(range(4,17),array_map(fn($c)=>fn($r)=>in_array($c,['E','J','K','L','M','N','O','Q','R'],true)?$cell($c.$r,null,4,"SUM({$c}{$first}:{$c}{$last})"):'',range('E','R')))); $add([]);
    }
    $add([fn($r)=>$cell("C$r",'GRAND TOTAL',1)]+array_combine(range(4,17),array_map(fn($c)=>fn($r)=>in_array($c,['E','J','K','L','M','N','O','Q','R'],true)?$cell($c.$r,null,4,implode('+',array_map(fn($x)=>$c.$x,$secRows))):'',range('E','R'))));
    // Entries sheet: every advance / fine / bonus / payment with date
    $er=['<row r="1">'.$cell('A1','Employee',2).$cell('B1','Code',2).$cell('C1','Type',2).$cell('D1','Amount',2).$cell('E1','Date',2).$cell('F1','Mode',2).$cell('G1','Note',2).'</row>']; $i=1;
    foreach($v['rows'] as $o) foreach($o['entries'] as $e){ $i++; $er[]='<row r="'.$i.'">'.$cell("A$i",$o['name']).$cell("B$i",$o['code']).$cell("C$i",ucfirst($e['kind'])).$cell("D$i",$e['amount']/100).$cell("E$i",$e['date']).$cell("F$i",$e['mode']).$cell("G$i",$e['note']).'</row>'; }
    $sheet=fn($rows,$widths)=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.implode('',array_map(fn($w,$i)=>'<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>',$widths,array_keys($widths))).'</cols><sheetData>'.implode('',$rows).'</sheetData></worksheet>';
    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD9ECB8"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="5"><xf/><xf fontId="1" applyFont="1"/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="164" fontId="1" fillId="2" applyNumberFormat="1" applyFont="1" applyFill="1"/></cellXfs></styleSheet>';
    $tmp=tempnam(sys_get_temp_dir(),'sx'); $z=new ZipArchive(); $z->open($tmp,ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Salary '.$X($m).'" sheetId="1" r:id="rId1"/><sheet name="Entries" sheetId="2" r:id="rId2"/></sheets><calcPr fullCalcOnLoad="1"/></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml',$styles);
    $z->addFromString('xl/worksheets/sheet1.xml',$sheet($rowsXml,[6,9,22,12,12,9,9,9,10,12,12,9,12,10,12,11,12,12,12]));
    $z->addFromString('xl/worksheets/sheet2.xml',$sheet($er,[22,9,10,12,12,10,40]));
    $z->close(); $bin=file_get_contents($tmp); @unlink($tmp); return ['name'=>'KSDD-Salary-'.$m.'.xlsx','data'=>$bin];
}

/* ============================== Attendance-app import (payroll-*.xlsx: Employee ID, Paid Days, Daily Wage …) ============================== */
const SAL_DEPT_SECTION=['service'=>'Waiter','waiter'=>'Waiter','kitchen'=>'Kitchen','utstaff'=>'UT Staff','ut'=>'UT Staff','manager'=>'Manager','management'=>'Manager'];
function sal_clean_name(string $n): array { $st=null; if(preg_match('/\((left|inactive)\)/i',$n,$m)) $st=strtolower($m[1]); return [trim(preg_replace('/\s+/',' ',preg_replace('/\((left|inactive|new|old)\)/i','',$n))),$st]; }
function sal_tokens(string $n): array { return array_values(array_filter(preg_split('/\s+/',preg_replace('/[^a-z0-9 ]+/','',mb_strtolower($n,'UTF-8'))))); }
/** Score how likely $name (+ department/section hint) is employee $e. */
function sal_name_score(string $name,?string $sectionHint,array $e,bool $onSheet,string $extra=''): int {
    $best=0; $tx=sal_tokens($extra);
    foreach(array_merge([$e['name']],array_filter(array_map('trim',explode(',',(string)$e['aliases'])))) as $cand){
        $a=sal_key($name); $b=sal_key($cand); $ta=sal_tokens($name); $tb=sal_tokens($cand); if($a===''||$b==='') continue; $sc=0; $first=$ta&&$tb&&$ta[0]===$tb[0];
        $other=count(array_intersect(array_merge(array_slice($ta,1),$tx),array_slice($tb,1)))>0;
        if($a===$b) $sc=100;
        elseif($first&&$other) $sc=85;
        elseif(strlen($b)>=4&&(str_starts_with($a,$b)||str_starts_with($b,$a))) $sc=$first?80:72;
        elseif($first) $sc=62;
        elseif(strlen($a)>=8&&levenshtein($a,$b)<=2) $sc=70;
        elseif($ta&&$tb&&strlen($ta[0])>=5&&$ta[0][0]===$tb[0][0]&&levenshtein($ta[0],$tb[0])<=2) $sc=58;
        if($sc&&$tx&&count(array_intersect($tx,array_slice($tb,1)))) $sc+=15; // e.g. designation "Tandoor" ↔ a name ending in "Tandoor"
        $best=max($best,$sc); }
    if(!$best) return 0;
    if($sectionHint&&$e['section']&&$e['section']!==$sectionHint) $best-=30;
    if($sectionHint&&$e['section']===$sectionHint) $best+=12; if($onSheet) $best+=10; if(($e['status']??'active')==='left'&&!$onSheet) $best-=5;
    return $best;
}
function sal_att_parse(string $data): array {
    require_once __DIR__.'/xls360.php'; $book=sheet_read_any($data); $out=[]; $title='';
    foreach($book as $sname=>$rows){ $hi=null; foreach(array_slice($rows,0,10,true) as $i=>$r){ $n=array_map(fn($x)=>strtolower(trim((string)$x)),$r); if(in_array('employee id',$n,true)&&in_array('paid days',$n,true)){ $hi=$i; break; } }
        if($hi===null) continue; $title=$sname; $h=array_map(fn($x)=>strtolower(trim((string)$x)),$rows[$hi]); $g=fn($r,$k)=>trim((string)($r[array_search($k,$h,true)]??''));
        foreach(array_slice($rows,$hi+1) as $r){ $id=preg_replace('/\.0+$/','',$g($r,'employee id')); $nm=$g($r,'employee name'); if($id===''||$nm==='') continue; [$clean,$st]=sal_clean_name($nm);
            $num=fn($k)=>is_numeric($g($r,$k))?(float)$g($r,$k):0.0; $acct=preg_replace('/\D/','',$g($r,'bank account no'));
            $out[]=['ext_id'=>$id,'name'=>$clean,'raw_name'=>$nm,'status'=>$st,'department'=>$g($r,'department'),'designation'=>$g($r,'designation'),'full'=>$num('full day'),'half'=>$num('half day'),'paid_leave'=>$num('paid leave'),'off'=>$num('off days'),'paid_days'=>$num('paid days'),'unpaid'=>$num('unpaid days'),
              'wage'=>(int)round($num('daily wage')*100),'gross'=>(int)round($num('gross wages')*100),'overtime'=>(int)round($num('overtime')*100),'penalty'=>(int)round($num('penalities')*100),'extras'=>(int)round($num('extras')*100),'advance'=>(int)round($num('loan & advance')*100),'to_pay'=>(int)round($num('to pay')*100),
              'bank'=>$g($r,'bank name')?:null,'ifsc'=>strtoupper($g($r,'ifsc code'))?:null,'last4'=>$acct!==''?substr($acct,-4):null]; } }
    if(!$out) fail('No attendance rows found. The file needs “Employee ID”, “Employee Name” and “Paid Days” columns.');
    $mon=null; if(preg_match('/(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i',$title,$m)) $mon=SAL_MONTH_NAMES[strtolower($m[1])];
    // self-check of the app's own maths
    foreach($out as &$o){ $o['check']=[]; $pd=$o['full']+0.5*$o['half']+$o['paid_leave']+$o['off']; if(abs($pd-$o['paid_days'])>0.01) $o['check'][]='Paid days '.$o['paid_days'].' ≠ full + ½ half + leave + off = '.$pd;
        if($o['wage']&&abs((int)round($o['wage']*$o['paid_days'])-$o['gross'])>100) $o['check'][]='Gross ≠ daily wage × paid days';
        if(abs($o['gross']+$o['overtime']-$o['penalty']+$o['extras']-$o['advance']-$o['to_pay'])>100) $o['check'][]='To Pay does not add up'; } unset($o);
    return ['sheet'=>$title,'month_no'=>$mon,'rows'=>$out];
}
function sal_att_preview(string $data,?string $month): array {
    $p=sal_att_parse($data); if(!$month){ foreach(rows('SELECT month FROM dd_sal_months ORDER BY month DESC') as $m) if($p['month_no']&&(int)substr($m['month'],5,2)===$p['month_no']){ $month=$m['month']; break; } }
    if(!$month) fail('Choose the salary month for this attendance file.'); $mo=sal_month(valid_month($month));
    $emps=rows('SELECT id,code,name,aliases,section,department,status FROM dd_employees'); $sheet=[]; foreach(rows('SELECT * FROM dd_sal_rows WHERE month=?',$month) as $r) $sheet[(int)$r['employee_id']]=$r;
    $sums=sal_sums(array_map(fn($r)=>(int)$r['id'],array_values($sheet)));
    $out=[];
    foreach($p['rows'] as $r){ $hint=SAL_DEPT_SECTION[preg_replace('/[^a-z]/','',strtolower($r['department']))]??null; $known=row("SELECT employee_id FROM dd_employee_identifiers WHERE source='attendance_app' AND external_id=?",$r['ext_id']);
        $sc=[]; foreach($emps as $e){ $v=sal_name_score($r['name'],$hint,$e,isset($sheet[(int)$e['id']]),$r['designation']); if($v>=55) $sc[]=['id'=>(int)$e['id'],'name'=>$e['name'],'code'=>$e['code'],'section'=>$e['section'],'score'=>$v,'on_sheet'=>isset($sheet[(int)$e['id']])]; }
        usort($sc,fn($a,$b)=>$b['score']<=>$a['score']); $match=$known?(int)$known['employee_id']:null; $how=$known?'saved ID':null;
        if(!$match&&$sc&&$sc[0]['score']>=70&&(!isset($sc[1])||$sc[1]['score']<$sc[0]['score'])){ $match=$sc[0]['id']; $how='name'; }
        $sr=$match&&isset($sheet[$match])?$sheet[$match]:null; $cmp=null;
        if($sr){ $s0=($sums[(int)$sr['id']]??[])+sal_zero(); $cmp=['present'=>(float)$sr['present'],'leave'=>(float)$sr['leave_days'],'monthly'=>(int)$sr['monthly'],'fine'=>$s0['fine'],'advance'=>$s0['advance']]; }
        $out[]=$r+['section_hint'=>$hint,'match'=>$match,'how'=>$how,'candidates'=>array_slice($sc,0,5),'sheet'=>$cmp]; }
    return ['month'=>$month,'days'=>(int)$mo['days'],'status'=>$mo['status'],'sheet'=>$p['sheet'],'rows'=>$out,'employees'=>array_map(fn($e)=>['id'=>(int)$e['id'],'name'=>$e['name'],'code'=>$e['code'],'section'=>$e['section'],'on_sheet'=>isset($sheet[(int)$e['id']])],$emps)];
}
function sal_att_confirm(string $data,array $b,array $u): array {
    $month=valid_month($b['month']??''); $mo=sal_open_month($month); $p=sal_att_parse($data); $map=(array)($b['map']??[]); $upd=!empty($b['update_master']); $res=['linked'=>0,'rows_updated'=>0,'not_on_sheet'=>[],'skipped'=>0,'master_updated'=>0]; $used=[];
    foreach($p['rows'] as $r){ $e=$map[$r['ext_id']]??''; if($e===''||$e==='skip'){ $res['skipped']++; continue; } $e=(int)$e; $emp=row('SELECT * FROM dd_employees WHERE id=?',$e); if(!$emp) fail('Employee #'.$e.' not found.');
        if(isset($used[$e])) fail('Two app IDs ('.$used[$e].' and '.$r['ext_id'].') are mapped to '.$emp['name'].'.'); $used[$e]=$r['ext_id'];
        $bound=row("SELECT employee_id FROM dd_employee_identifiers WHERE source='attendance_app' AND external_id=?",$r['ext_id']);
        if($bound&&(int)$bound['employee_id']!==$e) run("UPDATE dd_employee_identifiers SET employee_id=?,verified_by=? WHERE source='attendance_app' AND external_id=?",$e,$u['id'],$r['ext_id']);
        if(!$bound){ run("DELETE FROM dd_employee_identifiers WHERE source='attendance_app' AND employee_id=?",$e); run("INSERT INTO dd_employee_identifiers(source,external_id,employee_id,verified_by) VALUES('attendance_app',?,?,?)",$r['ext_id'],$e,$u['id']); }
        $res['linked']++;
        if($upd){ $al=array_filter(array_map('trim',explode(',',(string)$emp['aliases']))); if(sal_key($r['name'])!==sal_key($emp['name'])&&!in_array($r['name'],$al,true)) $al[]=$r['name'];
            run('UPDATE dd_employees SET aliases=?,designation=COALESCE(NULLIF(?,\'\'),designation),bank_name=COALESCE(?,bank_name),ifsc=COALESCE(?,ifsc),account_last4=COALESCE(?,account_last4) WHERE id=?',implode(', ',$al)?:null,$r['designation'],$r['bank'],$r['ifsc'],$r['last4'],$e); $res['master_updated']++; }
        $row=row('SELECT id FROM dd_sal_rows WHERE month=? AND employee_id=?',$month,$e);
        if($row){ run('UPDATE dd_sal_rows SET app_days=?,app_wage=?,app_penalty=?,app_advance=?,app_to_pay=?,app_ext_id=? WHERE id=?',number_format($r['paid_days'],2,'.',''),$r['wage']?:null,$r['penalty'],$r['advance'],$r['to_pay'],$r['ext_id'],$row['id']); $res['rows_updated']++; }
        else $res['not_on_sheet'][]=$r['raw_name'].' → '.$emp['name']; }
    audit($u,'salary.attendance.import',['month'=>$month]+$res); return ['ok'=>true]+$res;
}
/** Copy attendance-app paid days into Present for chosen rows (only where different). */
function sal_att_apply(array $b,array $u): array { $m=valid_month($b['month']??''); sal_open_month($m); $ids=array_map('intval',(array)($b['row_ids']??[])); $n=0;
    foreach(rows('SELECT id,present,app_days FROM dd_sal_rows WHERE month=? AND app_days IS NOT NULL',$m) as $r){ if($ids&&!in_array((int)$r['id'],$ids,true)) continue; if(abs((float)$r['present']-(float)$r['app_days'])<0.01) continue;
        run('UPDATE dd_sal_rows SET present=app_days,updated_by=? WHERE id=?',$u['id'],$r['id']); audit($u,'salary.attendance.apply',['row'=>(int)$r['id'],'old'=>$r['present'],'new'=>$r['app_days']]); $n++; }
    return ['ok'=>true,'changed'=>$n]; }

/* ============================== Books (Vyapar) staff payments ↔ Salary Register ============================== */
function sal_books_ready(): bool { try{ row('SELECT 1 FROM dd_bk_parties LIMIT 1'); return true; }catch(PDOException $e){ return false; } }
function sal_staff_party_name(string $n): string { $n=preg_replace('/^staff\s*/i','',$n); $n=preg_replace('/\b(kitchen|service|ut|wiping|gaurd|guard|captain|management|pickup|tandoor|indain|indian|headchef|head chef|chinese|chinise|halwai|roti|mocktail|ksdd|floor|cashier|store)\b/i',' ',$n); return trim(preg_replace('/[\s\-]+/',' ',$n)); }
function sal_staff_links(): array {
    if(!sal_books_ready()) return ['ready'=>false,'parties'=>[]]; $emps=rows('SELECT id,code,name,aliases,section,status FROM dd_employees'); $o=[];
    foreach(rows("SELECT p.id,p.name,p.employee_id,COUNT(t.id) n,COALESCE(SUM(t.paid),0) paid,MIN(t.date) first,MAX(t.date) last FROM dd_bk_parties p LEFT JOIN dd_bk_txns t ON t.party_id=p.id AND t.voided=0 AND t.type IN ('payment_out','expense') WHERE p.kind='staff' GROUP BY p.id ORDER BY p.name") as $p){
        $clean=sal_staff_party_name($p['name']); $hint=null; foreach(['kitchen|tandoor|indain|indian|chinese|chinise|halwai|roti|pickup|headchef|mocktail'=>'Kitchen','service|captain|gaurd|guard'=>'Waiter','\but\b|wiping'=>'UT Staff','management'=>'Manager'] as $re=>$sec) if(preg_match('/'.$re.'/i',$p['name'])){ $hint=$sec; break; }
        $sc=[]; foreach($emps as $e){ $v=sal_name_score($clean,$hint,$e,false,$p['name']); if($v>=55) $sc[]=['id'=>(int)$e['id'],'name'=>$e['name'],'code'=>$e['code'],'score'=>$v]; } usort($sc,fn($a,$b)=>$b['score']<=>$a['score']);
        $sug=$sc&&$sc[0]['score']>=70&&(!isset($sc[1])||$sc[1]['score']<$sc[0]['score'])?$sc[0]['id']:null;
        $o[]=['party_id'=>(int)$p['id'],'party'=>$p['name'],'clean'=>$clean,'employee_id'=>$p['employee_id']?(int)$p['employee_id']:null,'suggest'=>$sug,'candidates'=>array_slice($sc,0,4),'n'=>(int)$p['n'],'paid'=>(int)$p['paid'],'first'=>$p['first'],'last'=>$p['last']]; }
    return ['ready'=>true,'parties'=>$o,'employees'=>array_map(fn($e)=>['id'=>(int)$e['id'],'name'=>$e['name'],'code'=>$e['code']],$emps)];
}
function sal_staff_link_save(array $b,array $u): array { if(!sal_books_ready()) fail('Books module is not installed.'); $n=0;
    foreach((array)($b['links']??[]) as $pid=>$eid){ $pid=(int)$pid; $eid=$eid===''||$eid===null?null:(int)$eid; if($eid&&!row('SELECT id FROM dd_employees WHERE id=?',$eid)) fail('Employee not found.');
        run("UPDATE dd_bk_parties SET employee_id=? WHERE id=? AND kind='staff'",$eid,$pid); $n++; }
    audit($u,'salary.books.link',['count'=>$n]); return ['ok'=>true,'saved'=>$n]; }
/** Fix C12: per employee per PAYMENT month — Books cash paid (by date) vs Salary Register advances + payments by their ACTUAL payment date.
 *  The earned month is shown separately; nothing assumes salary is paid "next month". Undated register entries are listed, not guessed. */
function sal_pay_check(): array {
    $o=[]; $bm=[]; $und=[];
    if(sal_books_ready()) foreach(rows("SELECT p.employee_id e,DATE_FORMAT(t.date,'%Y-%m') m,SUM(t.paid) n FROM dd_bk_txns t JOIN dd_bk_parties p ON p.id=t.party_id WHERE t.voided=0 AND t.type IN ('payment_out','expense') AND p.kind='staff' AND p.employee_id IS NOT NULL GROUP BY p.employee_id,m") as $r){ $o[(int)$r['e']]['books'][$r['m']]=(int)$r['n']; $bm[$r['m']]=1; }
    foreach(rows("SELECT employee_id e,DATE_FORMAT(date,'%Y-%m') m,month earned,kind,SUM(amount) n FROM dd_sal_entries WHERE voided=0 AND kind IN ('advance','payment') AND date IS NOT NULL GROUP BY employee_id,m,month,kind") as $r){ $e=(int)$r['e']; $o[$e]['reg'][$r['m']]=($o[$e]['reg'][$r['m']]??0)+(int)$r['n']; $o[$e]['earned'][$r['m']][$r['earned']]=($o[$e]['earned'][$r['m']][$r['earned']]??0)+(int)$r['n']; $bm[$r['m']]=1; }
    foreach(rows("SELECT s.id,s.employee_id,s.month,s.kind,s.amount,e.name FROM dd_sal_entries s JOIN dd_employees e ON e.id=s.employee_id WHERE s.voided=0 AND s.kind IN ('advance','payment') AND s.date IS NULL ORDER BY s.month,e.name LIMIT 300") as $r) $und[]=['entry_id'=>(int)$r['id'],'employee_id'=>(int)$r['employee_id'],'name'=>$r['name'],'earned_month'=>$r['month'],'kind'=>$r['kind'],'amount'=>(int)$r['amount']];
    ksort($bm); $months=array_keys($bm); $rows=[];
    foreach($o as $e=>$x){ $emp=row('SELECT name,code FROM dd_employees WHERE id=?',$e); if(!$emp) continue; $per=[]; $bt=0; $rt=0;
        foreach($months as $m){ $b=$x['books'][$m]??0; $g=$x['reg'][$m]??0; $per[$m]=['books'=>$b,'register'=>$g,'earned_months'=>$x['earned'][$m]??[]]; $bt+=$b; $rt+=$g; }
        if(!$bt&&!$rt) continue; $rows[]=['employee_id'=>$e,'name'=>$emp['name'],'code'=>$emp['code'],'months'=>$per,'books_total'=>$bt,'register_total'=>$rt,'diff'=>$bt-$rt]; }
    usort($rows,fn($a,$b)=>abs($b['diff'])<=>abs($a['diff']));
    $unl=sal_books_ready()?(int)(row("SELECT COALESCE(SUM(t.paid),0) n FROM dd_bk_txns t JOIN dd_bk_parties p ON p.id=t.party_id WHERE t.voided=0 AND t.type IN ('payment_out','expense') AND p.kind='staff' AND p.employee_id IS NULL")['n']):0;
    $gen=sal_books_ready()?(int)(row("SELECT COALESCE(SUM(t.paid),0) n FROM dd_bk_txns t LEFT JOIN dd_bk_parties p ON p.id=t.party_id WHERE t.voided=0 AND t.type='expense' AND (p.id IS NULL OR p.kind<>'staff') AND t.category REGEXP 'salar'")['n']):0;
    return ['months'=>$months,'rows'=>$rows,'undated'=>$und,'unlinked_paid'=>$unl,'salary_without_name'=>$gen,'books_ready'=>sal_books_ready(),'rule'=>'Money paid to staff is compared in the month it was actually PAID: Books payments by date vs Salary Register advances and payments by their payment date. The earned (sheet) month of each register payment is shown alongside. Undated register entries are listed separately — add the payment date when you can.'];
}
