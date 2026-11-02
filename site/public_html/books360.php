<?php
declare(strict_types=1);
// KSDD Books 360 — replaces the Vyapar daybook: cash book, sales, Cash Access, purchases with items, expenses,
// supplier/customer ledgers, bank & owner accounts, stock with consumption, month lock, live audit checks,
// and a re-runnable Vyapar import (.xls straight from Vyapar or .xlsx). Money = integer paise. Quantities = DECIMAL(14,3).
// Separate tables (dd_bk_*): the CRM cash daybook (dd_ledger) is not touched.
require_once __DIR__.'/lib.php';
require_once __DIR__.'/lib360.php';

const BK_TYPES = [ // code => [label, direction: in|out|transfer, needs party?, has lines?]
 'sale'=>['Sale','in',false,true],'cash_access'=>['Cash Acess (not a sale)','in',false,false],'payment_in'=>['Payment received','in',true,false],'other_income'=>['Other income','in',false,false],
 'owner_in'=>['Owner money in (capital)','in',false,false],'add_cash'=>['Cash adjustment +','in',false,false],
 'purchase'=>['Purchase','out',true,true],'expense'=>['Expense','out',false,false],'payment_out'=>['Payment to party','out',true,false],
 'purchase_return'=>['Purchase return / debit note','in',true,false],'owner_draw'=>['Owner drawing','out',false,false],'reduce_cash'=>['Cash adjustment −','out',false,false],'transfer'=>['Transfer (deposit / withdraw)','transfer',false,false],
];
const BK_SCHEMA = 3; // v2: line discount + GST, staff party → salary employee link · v3: non-destructive merges (merged_into, orig_item_id)
const BK_DEFAULT_ACCOUNTS = ['Cash','Bank of India','UPI / Paytm','Card','Owner (Safik Home)'];
const BK_GROUPS = ['Staff','Food & kitchen','Utilities','Rent & royalty','Repairs & maintenance','Transport & fuel','Marketing & guests','Office & misc','Old dues (not expense)','Transfer (not expense)','Staff advance (not expense)'];
const BK_CATEGORY_DEFAULTS = [
 'Salary - Current Month'=>'Staff','Salary - Previous Month'=>'Staff','Salary -'=>'Staff','Other Staff Sallery'=>'Staff','Overtime Wages'=>'Staff','Staff Tip'=>'Staff','Staff Transport & Other'=>'Staff','Medical Expenses'=>'Staff',
 'Grocery'=>'Food & kitchen','Vegetable'=>'Food & kitchen','Kitchen Itam'=>'Food & kitchen','Cleaning Material'=>'Food & kitchen','Lakdi Gutkha'=>'Food & kitchen',
 'Electricity Bill'=>'Utilities','Recharge'=>'Utilities','Royalti Fees Khadak Singh'=>'Rent & royalty','Parth (Gupta Ji)'=>'Rent & royalty','Repair And Maintenance'=>'Repairs & maintenance','Decoration'=>'Repairs & maintenance',
 'Fuel & Vehicle Expenses'=>'Transport & fuel','Bus Tip Or Monthly'=>'Marketing & guests','Taxi Gift And Card'=>'Marketing & guests','Card Dairy Pen'=>'Office & misc','Manzil Restaurant'=>'Office & misc',
 'Old Balance Of Party'=>'Old dues (not expense)','Cash Withdrawal / Bank Transfer'=>'Transfer (not expense)',
];

/* ============================== schema ============================== */
function bk_migrate(): void {
    $t=[
     "dd_bk_parties (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,name_key VARCHAR(160) NOT NULL UNIQUE,kind VARCHAR(12) NOT NULL DEFAULT 'supplier',phone VARCHAR(20) NULL,gstin VARCHAR(20) NULL,opening BIGINT NOT NULL DEFAULT 0,credit_days INT NOT NULL DEFAULT 0,employee_id BIGINT UNSIGNED NULL,aliases VARCHAR(400) NULL,note VARCHAR(250) NULL,active TINYINT NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
     "dd_bk_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,name_key VARCHAR(160) NOT NULL UNIQUE,unit VARCHAR(20) NULL,category VARCHAR(80) NULL,code VARCHAR(40) NULL,is_stock TINYINT NOT NULL DEFAULT 1,reorder DECIMAL(14,3) NOT NULL DEFAULT 0,opening_qty DECIMAL(14,3) NOT NULL DEFAULT 0,std_rate BIGINT NULL,active TINYINT NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
     "dd_bk_txns (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,date DATE NOT NULL,type VARCHAR(20) NOT NULL,party_id BIGINT UNSIGNED NULL,category VARCHAR(80) NULL,total BIGINT NOT NULL DEFAULT 0,paid BIGINT NOT NULL DEFAULT 0,account VARCHAR(40) NOT NULL DEFAULT 'Cash',to_account VARCHAR(40) NULL,bill_no VARCHAR(60) NULL,description VARCHAR(500) NULL,source VARCHAR(20) NOT NULL DEFAULT 'app',import_key VARCHAR(190) NULL UNIQUE,voided TINYINT NOT NULL DEFAULT 0,void_reason VARCHAR(250) NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(date),INDEX(party_id),INDEX(type,date))",
     "dd_bk_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,txn_id BIGINT UNSIGNED NOT NULL,item_id BIGINT UNSIGNED NOT NULL,qty DECIMAL(14,3) NOT NULL,unit VARCHAR(20) NULL,rate BIGINT NOT NULL DEFAULT 0,amount BIGINT NOT NULL DEFAULT 0,INDEX(txn_id),INDEX(item_id))",
     "dd_bk_moves (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id BIGINT UNSIGNED NOT NULL,date DATE NOT NULL,qty DECIMAL(14,3) NOT NULL,kind VARCHAR(12) NOT NULL,note VARCHAR(250) NULL,source VARCHAR(20) NOT NULL DEFAULT 'app',import_key VARCHAR(190) NULL UNIQUE,voided TINYINT NOT NULL DEFAULT 0,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(item_id,date),INDEX(date))",
     "dd_bk_months (month CHAR(7) PRIMARY KEY,status VARCHAR(10) NOT NULL DEFAULT 'open',counted_cash BIGINT NULL,note TEXT NULL,locked_by BIGINT UNSIGNED NULL,locked_at DATETIME NULL)",
     "dd_bk_imports (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,file_name VARCHAR(160),file_sha256 CHAR(64) NOT NULL,kinds VARCHAR(120),result TEXT NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    ];
    foreach($t as $sql) db()->exec('CREATE TABLE IF NOT EXISTS '.$sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('bk_accounts',?)",json_encode(BK_DEFAULT_ACCOUNTS));
    run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('bk_categories',?)",json_encode(BK_CATEGORY_DEFAULTS,JSON_UNESCAPED_UNICODE));
    run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('bk_opening_cash','0'),('bk_start_date','2026-04-01'),('bk_cash_limit','20000000')");
    require_once __DIR__.'/migrations.php';
    mig_add_column('dd_bk_lines','discount','BIGINT NOT NULL DEFAULT 0'); mig_add_column('dd_bk_lines','tax','BIGINT NOT NULL DEFAULT 0');
    // v3 (audit C05): merges keep the source record and the original item of every line / movement.
    mig_add_column('dd_bk_items','merged_into','BIGINT UNSIGNED NULL'); mig_add_column('dd_bk_parties','merged_into','BIGINT UNSIGNED NULL');
    mig_add_column('dd_bk_lines','orig_item_id','BIGINT UNSIGNED NULL'); mig_add_column('dd_bk_moves','orig_item_id','BIGINT UNSIGNED NULL');
    set_setting('books_schema',(string)BK_SCHEMA);
}
function bk_installed(): bool { try{ row('SELECT 1 FROM dd_bk_txns LIMIT 1'); return true; }catch(PDOException $e){ return false; } }
function bk_ready(): void { if(!bk_installed()) fail('Books are not set up yet. Owner: open books.php and press “Set up Books”.',503);
    if(setting_int('books_schema',1)<BK_SCHEMA) fail('Books database update is pending. Owner: open migrate.php once (adds columns only; nothing is deleted).',503); }

/* ============================== helpers ============================== */
function bk_accounts(): array { $v=json_decode(setting('bk_accounts',''),true); return is_array($v)&&$v?$v:BK_DEFAULT_ACCOUNTS; }
function bk_categories(): array { $v=json_decode(setting('bk_categories',''),true); return is_array($v)?$v:BK_CATEGORY_DEFAULTS; }
function bk_group(?string $cat): string { if($cat===null||$cat==='') return 'Uncategorised'; return bk_categories()[$cat]??'Uncategorised'; }
function bk_r(int $p): string { $n=(string)(int)round(abs($p)/100); $l=strlen($n); if($l>3){ $h=substr($n,0,$l-3); $n=preg_replace('/\B(?=(\d{2})+(?!\d))/',',',$h).','.substr($n,-3); } return ($p<0?'−':'').'₹'.$n; }
/** Change many entries of one type to another (e.g. Vyapar cash adjustments that were really Petpooja Cash Access). */
function bk_reclassify(array $b,array $u): array { $from=(string)($b['from']??''); $to=(string)($b['to']??''); $ok=['add_cash'=>['cash_access','owner_in','sale','other_income'],'sale'=>['cash_access'],'reduce_cash'=>['owner_draw','expense']];
    if(!in_array($to,$ok[$from]??[],true)) fail('This change of type is not allowed.'); $ids=array_values(array_filter(array_map('intval',(array)($b['ids']??[])))); if(!$ids||count($ids)>500) fail('Choose 1–500 entries.'); $n=0;
    foreach($ids as $id){ $t=row('SELECT * FROM dd_bk_txns WHERE id=? FOR UPDATE',$id); if(!$t||$t['voided']||$t['type']!==$from) continue; bk_check_open($t['date']); run('UPDATE dd_bk_txns SET type=?,party_id=IF(?=\'sale\',party_id,NULL),description=? WHERE id=?',$to,$to,txt(trim(str_replace('(no reason given)','',(string)$t['description']).' · reclassified from '.BK_TYPES[$from][0].' by '.$u['name']),500),$id); $n++; }
    audit($u,'books.reclassify',['from'=>$from,'to'=>$to,'ids'=>$ids,'changed'=>$n]); return ['ok'=>true,'changed'=>$n]; }
function bk_key(string $n): string { return preg_replace('/[^a-z0-9\x{0900}-\x{097F}]+/u','',mb_strtolower($n,'UTF-8')); }
function bk_paise(mixed $v,string $label='Amount',bool $signed=false): int {
    if(is_bool($v)||!is_scalar($v)) fail($label.' is not valid.'); $s=trim(str_replace([',','₹',' '],'',(string)$v)); if($s===''||$s==='-') return 0;
    if(!preg_match($signed?'/^-?\d{1,10}(\.\d+)?$/':'/^\d{1,10}(\.\d+)?$/',$s)) fail($label.' must be a number.');
    return (int)round((float)$s*100);
}
function bk_qty(mixed $v,string $label='Quantity',bool $signed=true): string { $s=trim((string)$v); if($s==='') return '0.000'; if(!is_numeric($s)||(!$signed&&(float)$s<0)||abs((float)$s)>1e9) fail($label.' is not valid.'); return number_format((float)$s,3,'.',''); }
function bk_ddmmyyyy(string $s): ?string { $s=trim($s); if(preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#',$s,$m)) return sprintf('%04d-%02d-%02d',$m[3],$m[2],$m[1]); if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)) return $s; if(is_numeric($s)&&(float)$s>20000&&(float)$s<80000) return gmdate('Y-m-d',(int)(((float)$s-25569)*86400)); return null; }
function bk_month_status(string $d): string { $r=row('SELECT status FROM dd_bk_months WHERE month=?',substr($d,0,7)); return $r['status']??'open'; }
function bk_check_open(string $d): void { if(bk_month_status($d)==='locked') fail('Books for '.substr($d,0,7).' are LOCKED. The owner must unlock the month first.',409); month_lock_check($d); }
/** Fix C05: values that feed EVERY historical balance (opening qty/cash/party balance) cannot change once any month is locked. */
function bk_any_locked(): ?string { $r=row("SELECT month FROM dd_bk_months WHERE status='locked' ORDER BY month LIMIT 1"); if($r) return $r['month']; $h=null; try{ $h=row("SELECT month FROM dd_monthly_closures WHERE status='hard' ORDER BY month LIMIT 1"); }catch(PDOException $e){} return $h['month']??null; }
function bk_unit(?string $u): ?string { $u=trim((string)$u); return ($u===''||in_array(strtolower($u),['null','-','none','n/a'],true))?null:mb_substr($u,0,20); }
function bk_follow(string $table,int $id): int { for($i=0;$i<20;$i++){ $r=row("SELECT merged_into FROM $table WHERE id=?",$id); if(!$r||!($r['merged_into']??null)) return $id; $id=(int)$r['merged_into']; } return $id; }
/** Cash/bank effect of one transaction on one account. */
function bk_effect(array $t,string $acct): int {
    $dir=BK_TYPES[$t['type']][1]??'in'; $p=(int)$t['paid'];
    if($dir==='transfer') return ($t['to_account']===$acct?$p:0)-($t['account']===$acct?$p:0);
    if($t['account']!==$acct) return 0; return $dir==='in'?$p:-$p;
}
function bk_party_id(?string $name,string $kind='supplier'): ?int {
    $name=trim(preg_replace('/\s+/',' ',(string)$name)); if($name==='') return null; $k=bk_key($name); if($k==='') return null;
    $r=row('SELECT id FROM dd_bk_parties WHERE name_key=?',$k); if($r) return bk_follow('dd_bk_parties',(int)$r['id']);
    foreach(rows('SELECT id,aliases FROM dd_bk_parties WHERE aliases IS NOT NULL') as $p) foreach(explode(',',(string)$p['aliases']) as $a) if(bk_key($a)===$k) return bk_follow('dd_bk_parties',(int)$p['id']);
    if(preg_match('/^staff\b/i',$name)) $kind='staff';
    run('INSERT INTO dd_bk_parties(name,name_key,kind) VALUES(?,?,?)',txt($name,160),$k,$kind); return (int)db()->lastInsertId();
}
function bk_item_id(string $name,?string $unit=null,?string $cat=null,?string $code=null): int {
    $name=trim(preg_replace('/\s+/',' ',$name)); $k=bk_key($name); if($k==='') fail('Item name is required.'); $unit=bk_unit($unit);
    $r=row('SELECT id,unit,category FROM dd_bk_items WHERE name_key=?',$k); if($r&&($m=bk_follow('dd_bk_items',(int)$r['id']))!==(int)$r['id']) $r=row('SELECT id,unit,category FROM dd_bk_items WHERE id=?',$m);
    if($r){ if(!$r['unit']&&$unit) run('UPDATE dd_bk_items SET unit=? WHERE id=?',txt($unit,20),$r['id']); if(!$r['category']&&$cat) run('UPDATE dd_bk_items SET category=? WHERE id=?',txt($cat,80),$r['id']); return (int)$r['id']; }
    run('INSERT INTO dd_bk_items(name,name_key,unit,category,code) VALUES(?,?,?,?,?)',txt($name,160),$k,$unit?txt($unit,20):null,$cat?txt($cat,80):null,$code?txt($code,40):null); return (int)db()->lastInsertId();
}

/* ============================== cash book ============================== */
function bk_opening_before(string $date,string $acct='Cash'): int {
    $o=$acct==='Cash'?setting_int('bk_opening_cash',0):0; $n=$o;
    foreach(rows("SELECT type,paid,account,to_account FROM dd_bk_txns WHERE voided=0 AND date<? AND (account=? OR to_account=?)",$date,$acct,$acct) as $t) $n+=bk_effect($t,$acct);
    return $n;
}
function bk_txn_rows(string $where,array $args,string $order='t.date,t.id'): array {
    $r=rows("SELECT t.*,p.name party,p.kind party_kind FROM dd_bk_txns t LEFT JOIN dd_bk_parties p ON p.id=t.party_id WHERE $where ORDER BY $order",...$args);
    $ids=array_map(fn($x)=>(int)$x['id'],$r); $lines=[];
    if($ids) foreach(rows('SELECT l.*,i.name item FROM dd_bk_lines l JOIN dd_bk_items i ON i.id=l.item_id WHERE l.txn_id IN ('.implode(',',$ids).') ORDER BY l.id') as $l){ $lines[(int)$l['txn_id']][]=['item_id'=>(int)$l['item_id'],'item'=>$l['item'],'qty'=>(float)$l['qty'],'unit'=>$l['unit'],'rate'=>(int)$l['rate'],'discount'=>(int)($l['discount']??0),'tax'=>(int)($l['tax']??0),'amount'=>(int)$l['amount']]; }
    foreach($r as &$x){ foreach(['id','total','paid','voided','party_id'] as $k) $x[$k]=$x[$k]===null?null:(int)$x[$k]; $x['lines']=$lines[$x['id']]??[]; $x['group']=$x['type']==='expense'?bk_group($x['category']):null; } unset($x);
    return $r;
}
function bk_daybook(string $from,string $to,string $acct='Cash'): array {
    $open=bk_opening_before($from,$acct); $tx=bk_txn_rows('t.date BETWEEN ? AND ?',[$from,$to]); $bal=$open; $days=[];
    foreach($tx as &$t){ $e=$t['voided']?0:bk_effect($t,$acct); $bal+=$e; $t['cash']=$e; $t['running']=$bal;
        $d=&$days[$t['date']]; $d??=['date'=>$t['date'],'in'=>0,'out'=>0,'sale'=>0,'cash_access'=>0,'expense'=>0,'purchase'=>0,'n'=>0]; $d['n']++;
        if(!$t['voided']){ if($e>0) $d['in']+=$e; else $d['out']-=$e; if(isset($d[$t['type']])) $d[$t['type']]+=$t['total']; $d['close']=$bal; } unset($d); }
    unset($t); $run=$open; ksort($days); foreach($days as &$d){ $d['open']=$run; $d['close']=$run+$d['in']-$d['out']; $run=$d['close']; } unset($d);
    return ['account'=>$acct,'from'=>$from,'to'=>$to,'opening'=>$open,'closing'=>$bal,'txns'=>$tx,'days'=>array_values($days)];
}
function bk_txn_save(array $b,array $u): array {
    $id=(int)($b['id']??0); $type=(string)($b['type']??''); if(!isset(BK_TYPES[$type])) fail('Choose a valid entry type.');
    $date=valid_date($b['date']??''); bk_check_open($date);
    $cut=setting('f3_stock_cutover',''); if($cut!==''&&$date>=$cut&&in_array($type,['purchase','purchase_return'],true)) fail('Purchases are now entered in Finance 360 → Purchases (one stock ledger, from '.$cut.').',409);
    $old=null; if($id){ $old=row('SELECT * FROM dd_bk_txns WHERE id=? FOR UPDATE',$id); if(!$old||$old['voided']) fail('Entry not found.',404); bk_check_open($old['date']); }
    $accts=bk_accounts(); $acct=txt($b['account']??'Cash',40)?:'Cash'; if(!in_array($acct,$accts,true)) fail('Choose a valid account.');
    $to=null; if($type==='transfer'){ $to=txt($b['to_account']??'',40); if(!in_array($to,$accts,true)||$to===$acct) fail('Transfer needs two different accounts.'); }
    $partyName=trim((string)($b['party']??'')); $pid=$partyName!==''?bk_party_id($partyName,in_array($type,['sale','payment_in'],true)?'customer':'supplier'):null;
    if(BK_TYPES[$type][2]&&!$pid) fail('Choose the party (supplier / customer) for this entry.');
    $cat=txt($b['category']??'',80)?:null; if($type==='expense'&&!$cat) fail('Choose an expense category.');
    $lines=[]; $sum=0; if(BK_TYPES[$type][3]&&is_array($b['lines']??null)) foreach($b['lines'] as $i=>$l){ if(trim((string)($l['item']??''))==='') continue;
        $q=bk_qty($l['qty']??'0','Line '.($i+1).' quantity',false); $rate=bk_paise($l['rate']??'0','Line '.($i+1).' rate'); $disc=bk_paise($l['discount']??'0','Line '.($i+1).' discount'); $tax=bk_paise($l['tax']??'0','Line '.($i+1).' GST'); $calc=(int)round((float)$q*$rate)-$disc+$tax; $amt=isset($l['amount'])&&$l['amount']!==''?bk_paise($l['amount'],'Line '.($i+1).' amount'):$calc;
        if(abs($amt-$calc)>100&&$rate>0) fail('Line '.($i+1).': amount ₹'.rupees($amt).' does not match qty × rate − discount + GST = ₹'.rupees($calc).'.');
        $lines[]=['item_id'=>bk_item_id((string)$l['item'],bk_unit(txt($l['unit']??'',20)),$type==='purchase'?$cat:null),'qty'=>$q,'unit'=>bk_unit(txt($l['unit']??'',20)),'rate'=>$rate,'discount'=>$disc,'tax'=>$tax,'amount'=>$amt]; $sum+=$amt; }
    $total=$lines?$sum:bk_paise($b['total']??'0','Total'); if($total<=0&&!in_array($type,['purchase','sale'],true)) $total=bk_paise($b['paid']??'0','Amount');
    $paid=in_array($type,['purchase','sale','expense'],true)?bk_paise($b['paid']??($b['total']??'0'),'Paid amount'):$total;
    if($paid>$total&&in_array($type,['purchase','sale','expense'],true)) fail('Paid cannot be more than the bill total.');
    if($total<=0) fail('Amount must be more than zero.');
    if(in_array($type,['add_cash','reduce_cash','owner_draw','owner_in'],true)&&trim((string)($b['description']??''))==='') fail('Write the reason for this cash adjustment / owner entry.');
    $v=[$date,$type,$pid,$cat,$total,$paid,$acct,$to,txt($b['bill_no']??'',60)?:null,txt($b['description']??'',500)?:null];
    if($id){ run('UPDATE dd_bk_txns SET date=?,type=?,party_id=?,category=?,total=?,paid=?,account=?,to_account=?,bill_no=?,description=? WHERE id=?',...[...$v,$id]); run('DELETE FROM dd_bk_lines WHERE txn_id=?',$id); audit($u,'books.txn.update',['id'=>$id,'old'=>$old,'new'=>$v]); }
    else { run('INSERT INTO dd_bk_txns(date,type,party_id,category,total,paid,account,to_account,bill_no,description,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)',...[...$v,$u['id']]); $id=(int)db()->lastInsertId(); audit($u,'books.txn.create',['id'=>$id,'type'=>$type,'total'=>$total]); }
    foreach($lines as $l) run('INSERT INTO dd_bk_lines(txn_id,item_id,qty,unit,rate,discount,tax,amount) VALUES(?,?,?,?,?,?,?,?)',$id,$l['item_id'],$l['qty'],$l['unit'],$l['rate'],$l['discount'],$l['tax'],$l['amount']);
    return ['ok'=>true,'id'=>$id,'txn'=>bk_txn_rows('t.id=?',[$id])[0]];
}
function bk_txn_void(array $b,array $u): array { $t=row('SELECT * FROM dd_bk_txns WHERE id=? FOR UPDATE',(int)($b['id']??0)); if(!$t||$t['voided']) fail('Entry not found.',404); bk_check_open($t['date']);
    $why=txt($b['reason']??'',250); if($why==='') fail('Write why this entry is cancelled.'); run('UPDATE dd_bk_txns SET voided=1,void_reason=? WHERE id=?',$why,$t['id']); audit($u,'books.txn.void',['id'=>(int)$t['id'],'reason'=>$why]); return ['ok'=>true]; }

/* ============================== parties ============================== */
function bk_party_balances(?string $upto=null): array {
    $w=$upto?' AND t.date<=?':''; $a=$upto?[$upto]:[]; $o=[];
    foreach(rows("SELECT p.*,
      COALESCE(SUM(CASE WHEN t.type IN ('purchase','expense') THEN t.total-t.paid END),0) credit_buys,
      COALESCE(SUM(CASE WHEN t.type='purchase' THEN t.total END),0) purchases,
      COALESCE(SUM(CASE WHEN t.type='payment_out' THEN t.paid END),0) paid_out,
      COALESCE(SUM(CASE WHEN t.type IN ('purchase','expense') THEN t.paid END),0) paid_with_bill,
      COALESCE(SUM(CASE WHEN t.type='sale' THEN t.total-t.paid END),0) credit_sales,
      COALESCE(SUM(CASE WHEN t.type='sale' THEN t.total END),0) sales,
      COALESCE(SUM(CASE WHEN t.type='payment_in' THEN t.paid END),0) paid_in,
      COALESCE(SUM(CASE WHEN t.type='purchase_return' THEN t.total-t.paid END),0) return_credit, COALESCE(SUM(CASE WHEN t.type='purchase_return' THEN t.total END),0) returns,
      MAX(t.date) last_date, MAX(CASE WHEN t.type='payment_out' THEN t.date END) last_paid, MIN(CASE WHEN t.type='purchase' AND t.total>t.paid THEN t.date END) oldest_credit, COUNT(t.id) n
      FROM dd_bk_parties p LEFT JOIN dd_bk_txns t ON t.party_id=p.id AND t.voided=0$w GROUP BY p.id ORDER BY p.name",...$a) as $p){
        foreach(['credit_buys','purchases','paid_out','paid_with_bill','credit_sales','sales','paid_in','opening','n','return_credit','returns'] as $k) $p[$k]=(int)$p[$k];
        $p['payable']=$p['opening']+$p['credit_buys']-$p['paid_out']-$p['return_credit']; $p['receivable']=$p['credit_sales']-$p['paid_in']; $p['balance']=$p['payable']-$p['receivable']; $p['id']=(int)$p['id']; $o[]=$p; }
    return $o;
}
function bk_party_save(array $b,array $u): array {
    $id=(int)($b['id']??0); $name=name_required($b['name']??''); $k=bk_key($name); $kind=in_array($b['kind']??'',['supplier','customer','staff','other'],true)?$b['kind']:'supplier';
    $dupe=row('SELECT id FROM dd_bk_parties WHERE name_key=? AND id<>?',$k,$id); if($dupe) fail('A party with this name already exists.',409);
    $v=[$name,$k,$kind,txt($b['phone']??'',20)?:null,strtoupper(txt($b['gstin']??'',20))?:null,bk_paise($b['opening']??'0','Opening balance',true),max(0,(int)($b['credit_days']??0)),txt($b['aliases']??'',400)?:null,txt($b['note']??'',250)?:null,empty($b['inactive'])?1:0];
    if($id){ $old=row('SELECT opening FROM dd_bk_parties WHERE id=? FOR UPDATE',$id); if(!$old) fail('Party not found.',404);
        if((int)$old['opening']!==$v[5]&&($lk=bk_any_locked())) fail('Opening balance changes every past statement, and '.$lk.' is locked. Record the correction as a dated entry (e.g. Payment to party / Old Balance Of Party) in an open month instead.',409); }
    if($id){ run('UPDATE dd_bk_parties SET name=?,name_key=?,kind=?,phone=?,gstin=?,opening=?,credit_days=?,aliases=?,note=?,active=? WHERE id=?',...[...$v,$id]); }
    else { run('INSERT INTO dd_bk_parties(name,name_key,kind,phone,gstin,opening,credit_days,aliases,note,active) VALUES(?,?,?,?,?,?,?,?,?,?)',...$v); $id=(int)db()->lastInsertId(); }
    audit($u,'books.party.save',['id'=>$id,'name'=>$name]); return ['ok'=>true,'id'=>$id];
}
/** Merge party $from into $into (fixes the same supplier typed two ways). */
function bk_party_merge(array $b,array $u): array { $f=(int)($b['from']??0); $i=(int)($b['into']??0); if(!$f||!$i||$f===$i) fail('Choose two different parties.');
    $a=row('SELECT * FROM dd_bk_parties WHERE id=?',$f); $c=row('SELECT * FROM dd_bk_parties WHERE id=?',$i); if(!$a||!$c) fail('Party not found.');
    foreach(rows('SELECT DISTINCT date FROM dd_bk_txns WHERE party_id=?',$f) as $d) bk_check_open($d['date']);
    if((int)$a['opening']!==0&&($lk=bk_any_locked())) fail('The party being merged has an opening balance and '.$lk.' is locked; moving it would change locked statements. Settle it with a dated entry first.',409);
    if($a['merged_into']??null) fail('That party was already merged.',409);
    $ids=array_map('intval',array_column(rows('SELECT id FROM dd_bk_txns WHERE party_id=?',$f),'id'));
    run('UPDATE dd_bk_txns SET party_id=? WHERE party_id=?',$i,$f); $al=array_filter(array_map('trim',explode(',',(string)$c['aliases']))); $al[]=$a['name'];
    run('UPDATE dd_bk_parties SET aliases=?,opening=opening+? WHERE id=?',implode(', ',array_unique($al)),(int)$a['opening'],$i);
    run('UPDATE dd_bk_parties SET active=0,merged_into=?,opening=0,note=? WHERE id=?',$i,txt('Merged into #'.$i.' (opening ₹'.rupees((int)$a['opening']).' moved) '.($a['note']??''),250),$f); // kept, not deleted
    audit($u,'books.party.merge',['from'=>$a,'into'=>$i,'txn_ids'=>$ids]); return ['ok'=>true]; }
/** Fix C05: item merge is non-destructive, unit-checked and period-checked. Each line/movement remembers its original item. */
function bk_item_merge(array $b,array $u): array { $f=(int)($b['from']??0); $i=(int)($b['into']??0); if(!$f||!$i||$f===$i) fail('Choose two different items.');
    $a=row('SELECT * FROM dd_bk_items WHERE id=? FOR UPDATE',$f); $c=row('SELECT * FROM dd_bk_items WHERE id=? FOR UPDATE',$i); if(!$a||!$c) fail('Item not found.',404); if($a['merged_into']??null) fail('That item was already merged.',409);
    $ua=bk_unit($a['unit']); $uc=bk_unit($c['unit']);
    foreach(rows("SELECT DISTINCT unit FROM dd_bk_lines WHERE item_id=? AND unit IS NOT NULL AND unit<>''",$f) as $x){ $ux=bk_unit($x['unit']); if($ux&&$uc&&strcasecmp($ux,$uc)!==0) fail('Cannot merge: “'.$a['name'].'” has lines in '.$ux.' but “'.$c['name'].'” is counted in '.$uc.'. Quantities in different units cannot be added. Correct the unit on those bills, or keep the items separate.',409); }
    if($ua&&$uc&&strcasecmp($ua,$uc)!==0) fail('Cannot merge: units differ ('.$ua.' vs '.$uc.'). Quantities in different units cannot be added without an item-specific conversion.',409);
    if((float)$a['opening_qty']!=0.0&&($lk=bk_any_locked())) fail('“'.$a['name'].'” has an opening quantity and '.$lk.' is locked; merging would change locked stock. Post a dated opening movement instead.',409);
    foreach(rows('SELECT DISTINCT t.date FROM dd_bk_lines l JOIN dd_bk_txns t ON t.id=l.txn_id WHERE l.item_id=? UNION SELECT DISTINCT date FROM dd_bk_moves WHERE item_id=?',$f,$f) as $d) bk_check_open($d['date']);
    run('UPDATE dd_bk_lines SET orig_item_id=COALESCE(orig_item_id,item_id),item_id=? WHERE item_id=?',$i,$f); run('UPDATE dd_bk_moves SET orig_item_id=COALESCE(orig_item_id,item_id),item_id=?,import_key=import_key WHERE item_id=?',$i,$f);
    run('UPDATE dd_bk_items SET opening_qty=opening_qty+? WHERE id=?',$a['opening_qty'],$i); if(!$uc&&$ua) run('UPDATE dd_bk_items SET unit=? WHERE id=?',$ua,$i);
    run('UPDATE dd_bk_items SET active=0,merged_into=?,opening_qty=0 WHERE id=?',$i,$f); // kept (history, name lookup follows merged_into)
    audit($u,'books.item.merge',['from'=>$a,'into'=>$i]); return ['ok'=>true]; }
function bk_party_statement(int $id): array { $p=null; foreach(bk_party_balances() as $x) if($x['id']===$id) $p=$x; if(!$p) fail('Party not found.',404);
    $tx=bk_txn_rows('t.party_id=? AND t.voided=0',[$id]); $bal=$p['opening'];
    foreach($tx as &$t){ $d=match($t['type']){ 'purchase','expense'=>$t['total']-$t['paid'], 'payment_out'=>-$t['paid'], 'sale'=>-($t['total']-$t['paid']), 'payment_in'=>$t['paid'], 'purchase_return'=>-($t['total']-$t['paid']), default=>0 }; $bal+=$d; $t['effect']=$d; $t['balance']=$bal; } unset($t);
    return ['party'=>$p,'txns'=>$tx]; }

/* ============================== stock ============================== */
function bk_stock(?string $upto=null): array {
    $up=$upto??'9999-12-31'; $o=[];
    $in=[];  foreach(rows("SELECT l.item_id,SUM(l.qty) q,SUM(l.amount) a FROM dd_bk_lines l JOIN dd_bk_txns t ON t.id=l.txn_id AND t.voided=0 AND t.type='purchase' AND t.date<=? GROUP BY l.item_id",$up) as $r) $in[(int)$r['item_id']]=[(float)$r['q'],(int)$r['a']];
    $sold=[]; foreach(rows("SELECT l.item_id,SUM(l.qty) q FROM dd_bk_lines l JOIN dd_bk_txns t ON t.id=l.txn_id AND t.voided=0 AND t.type='sale' AND t.date<=? GROUP BY l.item_id",$up) as $r) $sold[(int)$r['item_id']]=(float)$r['q'];
    $mv=[];  foreach(rows("SELECT item_id,kind,SUM(qty) q,MAX(date) last FROM dd_bk_moves WHERE voided=0 AND date<=? GROUP BY item_id,kind",$up) as $r){ $mv[(int)$r['item_id']][$r['kind']]=(float)$r['q']; $mv[(int)$r['item_id']]['_last']=max($mv[(int)$r['item_id']]['_last']??'',$r['last']); }
    $last=[]; foreach(rows("SELECT l.item_id,IF(l.qty>0,ROUND(l.amount/l.qty),l.rate) rate,t.date,p.name party FROM dd_bk_lines l JOIN dd_bk_txns t ON t.id=l.txn_id AND t.voided=0 AND t.type='purchase' LEFT JOIN dd_bk_parties p ON p.id=t.party_id WHERE t.date<=? ORDER BY t.date,l.id",$up) as $r){ $i=(int)$r['item_id']; $x=&$last[$i]; $x??=['n'=>0,'min'=>PHP_INT_MAX,'max'=>0,'sum'=>0]; $x['rate']=(int)$r['rate']; $x['date']=$r['date']; $x['party']=$r['party']; $x['n']++; $x['min']=min($x['min'],(int)$r['rate']); $x['max']=max($x['max'],(int)$r['rate']); $x['sum']+=(int)$r['rate']; unset($x); }
    foreach(rows('SELECT * FROM dd_bk_items WHERE merged_into IS NULL ORDER BY category IS NULL,category,name') as $it){ $i=(int)$it['id']; $m=$mv[$i]??[];
        $q_in=$in[$i][0]??0.0; $used=-(($m['consume']??0)+($m['wastage']??0)); $adj=($m['adjust']??0)+($m['count']??0); $open=(float)$it['opening_qty']+($m['opening']??0);
        $close=round($open+$q_in-($sold[$i]??0)+($m['consume']??0)+($m['wastage']??0)+$adj,3); $l=$last[$i]??null;
        $o[]=['id'=>$i,'name'=>$it['name'],'unit'=>$it['unit'],'category'=>$it['category'],'code'=>$it['code'],'is_stock'=>(int)$it['is_stock'],'reorder'=>(float)$it['reorder'],'std_rate'=>$it['std_rate']===null?null:(int)$it['std_rate'],'active'=>(int)$it['active'],
          'opening'=>$open,'in'=>$q_in,'in_value'=>$in[$i][1]??0,'sold'=>$sold[$i]??0.0,'used'=>$used,'adjust'=>$adj,'closing'=>$close,'last_rate'=>$l['rate']??null,'last_date'=>$l['date']??null,'last_party'=>$l['party']??null,
          'min_rate'=>$l?$l['min']:null,'max_rate'=>$l['max']??null,'avg_rate'=>$l?intdiv($l['sum'],$l['n']):null,'bills'=>$l['n']??0,'value'=>$l?(int)round(max(0,$close)*$l['rate']):0,'last_move'=>$m['_last']??null]; }
    return $o;
}
function bk_move_save(array $b,array $u): array { $d=valid_date($b['date']??''); bk_check_open($d); $cut=setting('f3_stock_cutover',''); if($cut!==''&&$d>=$cut) fail('Stock is now maintained in Finance 360 → Items & Stock (from '.$cut.').',409); $kind=in_array($b['kind']??'',['consume','wastage','adjust','count','opening'],true)?$b['kind']:fail('Choose a movement type.');
    $n=0; foreach((array)($b['items']??[]) as $x){ $iid=(int)($x['item_id']??0); if(!$iid||trim((string)($x['qty']??''))==='') continue; if(!row('SELECT id FROM dd_bk_items WHERE id=?',$iid)) fail('Item not found.');
        row('SELECT id FROM dd_bk_items WHERE id=? FOR UPDATE',$iid); // serialise concurrent counts of the same item (no double delta)
        $q=(float)bk_qty($x['qty']); if($kind==='count'){ $cur=0.0; foreach(bk_stock($d) as $s) if($s['id']===$iid) $cur=$s['closing']; $q=round($q-$cur,3); if(abs($q)<0.0005) continue; }
        elseif(in_array($kind,['consume','wastage'],true)) $q=-abs($q);
        run('INSERT INTO dd_bk_moves(item_id,date,qty,kind,note,created_by) VALUES(?,?,?,?,?,?)',$iid,$d,number_format($q,3,'.',''),$kind,txt($x['note']??($b['note']??''),250)?:null,$u['id']); $n++; }
    if(!$n) fail('Enter at least one quantity.'); audit($u,'books.stock.move',['kind'=>$kind,'date'=>$d,'lines'=>$n]); return ['ok'=>true,'saved'=>$n]; }
function bk_item_save(array $b,array $u): array { $id=(int)($b['id']??0); $name=name_required($b['name']??''); $k=bk_key($name); if(row('SELECT id FROM dd_bk_items WHERE name_key=? AND id<>?',$k,$id)) fail('An item with this name exists. Use “Merge”.',409);
    $v=[$name,$k,txt($b['unit']??'',20)?:null,txt($b['category']??'',80)?:null,txt($b['code']??'',40)?:null,empty($b['is_stock'])?0:1,bk_qty($b['reorder']??'0'),bk_qty($b['opening_qty']??'0'),($b['std_rate']??'')===''?null:bk_paise($b['std_rate'],'Standard rate'),empty($b['inactive'])?1:0];
    $v[2]=bk_unit($v[2]);
    if($id){ $old=row('SELECT opening_qty,unit FROM dd_bk_items WHERE id=? FOR UPDATE',$id); if(!$old) fail('Item not found.',404);
        if(bk_qty($old['opening_qty'])!==$v[7]&&($lk=bk_any_locked())) fail('Opening quantity feeds every past stock balance, and '.$lk.' is locked. Record a dated “opening” or “adjust” movement in an open month instead.',409); }
    if($id) run('UPDATE dd_bk_items SET name=?,name_key=?,unit=?,category=?,code=?,is_stock=?,reorder=?,opening_qty=?,std_rate=?,active=? WHERE id=?',...[...$v,$id]); else { run('INSERT INTO dd_bk_items(name,name_key,unit,category,code,is_stock,reorder,opening_qty,std_rate,active) VALUES(?,?,?,?,?,?,?,?,?,?)',...$v); $id=(int)db()->lastInsertId(); }
    audit($u,'books.item.save',['id'=>$id]); return ['ok'=>true,'id'=>$id]; }
function bk_item_card(int $id): array { $it=row('SELECT * FROM dd_bk_items WHERE id=?',$id); if(!$it) fail('Item not found.',404);
    $lines=rows("SELECT t.date,t.type,t.id txn_id,p.name party,l.qty,l.unit,l.rate,l.amount FROM dd_bk_lines l JOIN dd_bk_txns t ON t.id=l.txn_id AND t.voided=0 LEFT JOIN dd_bk_parties p ON p.id=t.party_id WHERE l.item_id=? ORDER BY t.date,l.id",$id);
    $moves=rows('SELECT id,date,qty,kind,note,source FROM dd_bk_moves WHERE item_id=? AND voided=0 ORDER BY date,id',$id); return ['item'=>$it,'lines'=>$lines,'moves'=>$moves]; }

/* ============================== month summary / P&L ============================== */
function bk_month_summary(string $m): array {
    valid_month($m); $from=$m.'-01'; $to=(new DateTimeImmutable($from))->format('Y-m-t'); $s=['month'=>$m,'from'=>$from,'to'=>$to];
    $by=[]; foreach(rows('SELECT type,COUNT(*) n,SUM(total) total,SUM(paid) paid FROM dd_bk_txns WHERE voided=0 AND date BETWEEN ? AND ? GROUP BY type',$from,$to) as $r) $by[$r['type']]=['n'=>(int)$r['n'],'total'=>(int)$r['total'],'paid'=>(int)$r['paid']];
    $s['by_type']=$by; $g=fn($t,$k='total')=>$by[$t][$k]??0;
    $cats=[]; $groups=[]; foreach(rows("SELECT category,SUM(total) n,COUNT(*) c FROM dd_bk_txns WHERE voided=0 AND type='expense' AND date BETWEEN ? AND ? GROUP BY category ORDER BY n DESC",$from,$to) as $r){ $gr=bk_group($r['category']); $cats[]=['category'=>$r['category']?:'(none)','group'=>$gr,'amount'=>(int)$r['n'],'n'=>(int)$r['c']]; $groups[$gr]=($groups[$gr]??0)+(int)$r['n']; }
    arsort($groups); $s['expense_categories']=$cats; $s['expense_groups']=$groups;
    $nonExp=0; foreach($groups as $gn=>$gv) if(str_contains((string)$gn,'(not expense)')) $nonExp+=$gv; $staffBooks=$groups['Staff']??0; /* V16: any '(not expense)' group (e.g. recoverable staff advances) stays out of P&L */
    $staffPay=(int)(row("SELECT COALESCE(SUM(t.paid),0) n FROM dd_bk_txns t JOIN dd_bk_parties p ON p.id=t.party_id AND p.kind='staff' WHERE t.voided=0 AND t.type='payment_out' AND t.date BETWEEN ? AND ?",$from,$to)['n']);
    $sal=null; try{ $sm=row('SELECT * FROM dd_sal_months WHERE month=?',$m); if($sm){ require_once __DIR__.'/salary360.php'; $v=sal_month_view($m)['totals']; $sal=['earned'=>$v['earned'],'bonus'=>$v['bonus'],'net'=>$v['net'],'paid'=>$v['paid'],'advance'=>$v['advance'],'employees'=>$v['employees']];
        $sal['cash_out_in_month']=(int)(row("SELECT COALESCE(SUM(amount),0) n FROM dd_sal_entries WHERE voided=0 AND kind IN ('advance','payment') AND date BETWEEN ? AND ?",$from,$to)['n']); } }catch(PDOException $e){}
    $sales=$g('sale'); $purch=$g('purchase')-$g('purchase_return'); $otherExp=array_sum($groups)-$nonExp-$staffBooks; $staffCost=$sal?$sal['earned']+$sal['bonus']:$staffBooks+$staffPay; // fix C17: bonuses are staff cost
    $s['pl']=['basis'=>'Cash & purchases view: purchases are counted when BOUGHT, not when consumed, so this is not accrual profit. Stock bought but not yet used is still an asset. Use Accounts → P&L (GL) for consumption-based profit.','sales'=>$sales,'other_income'=>$g('other_income'),'purchases'=>$purch,'food_cost_pct'=>$sales?round($purch*100/$sales,1):null,'food_cost_basis'=>'purchases ÷ sales (not consumption)','staff_cost'=>$staffCost,'staff_cost_source'=>$sal?'Salary Register (earned + bonus)':'Books (staff expenses + staff payments)','other_expenses'=>$otherExp,
       'profit'=>$sales+$g('other_income')-$purch-$staffCost-$otherExp,'not_in_pl'=>['cash_access'=>$g('cash_access'),'owner_in'=>$g('owner_in'),'owner_draw'=>$g('owner_draw'),'old_dues_paid'=>$groups['Old dues (not expense)']??0,'transfers'=>$g('transfer','paid')]];
    $s['staff_recon']=['books_staff_expense'=>$staffBooks,'books_staff_payments'=>$staffPay,'register'=>$sal];
    $s['cash']=['opening'=>bk_opening_before($from),'closing'=>bk_opening_before((new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'))];
    $s['accounts']=[]; foreach(bk_accounts() as $a){ $s['accounts'][]=['account'=>$a,'opening'=>bk_opening_before($from,$a),'closing'=>bk_opening_before((new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'),$a)]; }
    $s['daily']=rows("SELECT date,SUM(CASE WHEN type='sale' THEN total ELSE 0 END) sale,SUM(CASE WHEN type='cash_access' THEN total ELSE 0 END) cash_access,SUM(CASE WHEN type='expense' THEN total ELSE 0 END) expense,SUM(CASE WHEN type='purchase' THEN total ELSE 0 END) purchase FROM dd_bk_txns WHERE voided=0 AND date BETWEEN ? AND ? GROUP BY date ORDER BY date",$from,$to);
    foreach($s['daily'] as &$d) foreach(['sale','cash_access','expense','purchase'] as $k) $d[$k]=(int)$d[$k]; unset($d);
    $mo=row('SELECT * FROM dd_bk_months WHERE month=?',$m); $s['status']=$mo['status']??'open'; $s['counted_cash']=$mo&&$mo['counted_cash']!==null?(int)$mo['counted_cash']:null; $s['note']=$mo['note']??null;
    return $s;
}
function bk_months(): array { $o=[]; foreach(rows("SELECT DATE_FORMAT(date,'%Y-%m') m,COUNT(*) n,MIN(date) a,MAX(date) b,COUNT(DISTINCT date) days FROM dd_bk_txns WHERE voided=0 GROUP BY m ORDER BY m DESC") as $r) $o[]=['month'=>$r['m'],'n'=>(int)$r['n'],'days'=>(int)$r['days'],'first'=>$r['a'],'last'=>$r['b'],'status'=>bk_month_status($r['m'].'-01')]; return $o; }
function bk_month_lock(array $b,array $u,bool $lock): array { $m=valid_month($b['month']??''); run('INSERT IGNORE INTO dd_bk_months(month) VALUES(?)',$m); $r=row('SELECT * FROM dd_bk_months WHERE month=? FOR UPDATE',$m);
    if($lock){ if($r['status']==='locked') fail('Already locked.'); $cc=isset($b['counted_cash'])&&$b['counted_cash']!==''?bk_paise($b['counted_cash'],'Counted cash'):null; run("UPDATE dd_bk_months SET status='locked',counted_cash=?,note=?,locked_by=?,locked_at=? WHERE month=?",$cc,txt($b['note']??'',1000)?:null,$u['id'],now_sql(),$m); }
    else { if(!can($u,'*')) fail('Only the owner can unlock a month.',403); if(mb_strlen(txt($b['reason']??''))<5) fail('Write the reason for unlocking.'); run("UPDATE dd_bk_months SET status='open' WHERE month=?",$m); }
    audit($u,$lock?'books.month.lock':'books.month.unlock',['month'=>$m,'reason'=>$b['reason']??null]); return ['ok'=>true]; }

/* ============================== live audit checks ============================== */
function bk_audit(?string $m=null): array {
    $w='t.voided=0'; $a=[]; if($m){ valid_month($m); $w.=' AND t.date BETWEEN ? AND ?'; $a=[$m.'-01',(new DateTimeImmutable($m.'-01'))->format('Y-m-t')]; }
    $I=[]; $add=function(string $sev,string $cat,string $text,?string $date=null,?int $amount=null,?int $txn=null,string $fix='')use(&$I){ $I[]=compact('sev','cat','text','date','amount','txn','fix')+['ttype'=>null]; };
    $tx=bk_txn_rows($w,$a); if(!$tx) return [];
    $first=$tx[0]['date']; $last=end($tx)['date'];
    // 1. sale days missing
    $saleDays=[]; foreach($tx as $t) if($t['type']==='sale'&&(!$t['party_id']||in_array(bk_key((string)$t['party']),['petpoojasale','petpooja'],true))) $saleDays[$t['date']]=($saleDays[$t['date']]??0)+$t['total'];
    $d=new DateTimeImmutable($first); $end=new DateTimeImmutable(min($last,date('Y-m-d'))); $miss=[]; while($d<=$end){ $k=$d->format('Y-m-d'); if(!isset($saleDays[$k])) $miss[]=$k; $d=$d->modify('+1 day'); }
    if($miss) $add('high','Missing sales',count($miss).' day(s) have no Petpooja/daily sale entry: '.implode(', ',array_slice(array_map(fn($x)=>substr($x,8,2).'/'.substr($x,5,2),$miss),0,15)).(count($miss)>15?'…':''),null,null,null,'Enter each day\'s Petpooja sale (split Cash / UPI / Card).');
    // 2. lump sale
    if(count($saleDays)>3){ $vals=array_values($saleDays); sort($vals); $med=$vals[intdiv(count($vals),2)]; foreach($saleDays as $k=>$v) if($med>0&&$v>5*$med) $add('high','Lump-sum sale','Sale of '.bk_r($v).' on '.$k.' is more than 5× a normal day — a whole period entered at once?',$k,$v,null,'Split into daily entries.'); }
    // 3. negative cash
    $db=bk_daybook($first,$last); $negDays=array_filter($db['days'],fn($x)=>$x['close']<0); if($negDays){ $lo=min(array_column($negDays,'close')); $add('high','Negative cash',count($negDays).' day(s) close with NEGATIVE cash in hand (lowest '.bk_r($lo).'). Cash cannot be negative — an income entry is missing or late.',null,$lo,null,'Find the missing cash receipt; enter sales daily.'); }
    $limit=setting_int('bk_cash_limit',20000000); $hi=array_filter($db['days'],fn($x)=>$x['close']>$limit); if(count($hi)>3) $add('low','High cash in hand',count($hi).' day(s) with more than '.bk_r($limit).' cash in drawer — deposit to bank more often.');
    foreach($tx as $t){ $tid=$t['id']; $amt=$t['total'];
        if(in_array($t['type'],['add_cash','reduce_cash'],true)&&(!$t['description']||str_contains((string)$t['description'],'no reason given'))) $add('high','Unexplained adjustment',BK_TYPES[$t['type']][0].' of '.bk_r($amt).' without a reason.',$t['date'],$amt,$tid,'Every adjustment needs a reason, or use the correct type (e.g. Cash Acess).');
        if($t['type']==='sale'&&preg_match('/cash\s*acc?ess|cashacess/i',(string)$t['party'])) $add('high','Cash Acess booked as sale','“'.$t['party'].'” '.bk_r($amt).' is booked as SALE; Cash Acess is not revenue.',$t['date'],$amt,$tid,'Change type to Cash Acess.');
        if($t['type']==='purchase'&&!$t['lines']) $add($amt?'medium':'high','Purchase without items','Purchase from '.$t['party'].' has no item lines'.($amt?' ('.bk_r($amt).')':' and value ₹0').'.',$t['date'],$amt,$tid,'Add items or bill total.');
        if($t['type']==='expense'&&!$t['party_id']&&$amt>=500000) $add('medium','Expense without payee',bk_r($amt).' '.$t['category'].' has no payee.',$t['date'],$amt,$tid,'Add who was paid.');
        if($t['type']==='expense'&&bk_group($t['category'])==='Uncategorised') $add('medium','Unknown category','Expense category “'.($t['category']?:'—').'” is not in the category list.',$t['date'],$amt,$tid,'Map it to a group in Settings.');
        if($t['type']==='expense'&&in_array(bk_group($t['category']),['Old dues (not expense)','Transfer (not expense)'],true)) $add('medium','Not an expense','“'.$t['category'].'” '.bk_r($amt).' is a '.(bk_group($t['category'])==='Transfer (not expense)'?'transfer':'payment of old supplier dues').', not a cost.',$t['date'],$amt,$tid,'Use Payment to party / Transfer.');
        if(in_array($t['type'],['payment_out','expense'],true)&&$t['party_kind']==='staff') $add('low','Staff paid from Books',bk_r($t['paid']).' to '.$t['party'].' — make sure the same advance/salary is in the Salary Register.',$t['date'],$t['paid'],$tid,'Pay staff from the Salary Register.');
        if($t['type']==='expense'&&$t['total']<100) $add('low','Tiny/test entry',$t['category'].' '.bk_r($amt),$t['date'],$amt,$tid,'Cancel test entries.');
        foreach($t['lines'] as $l) if($l['rate']>0&&abs((int)round($l['qty']*$l['rate'])-$l['discount']+$l['tax']-$l['amount'])>100) $add('high','Calculation error',$l['item'].' '.$l['qty'].' × '.bk_r($l['rate']).($l['discount']?' − disc '.bk_r($l['discount']):'').' = '.bk_r((int)round($l['qty']*$l['rate'])-$l['discount']+$l['tax']).' but line says '.bk_r($l['amount']).' ('.($t['party']?:'').').',$t['date'],$l['amount'],$tid,'Correct quantity, rate or amount.');
        if($t['type']==='purchase'&&$t['lines']&&!$t['bill_no']&&$amt>=1000000) $add('low','No bill number','Purchase '.bk_r($amt).' from '.$t['party'].' has no supplier bill number.',$t['date'],$amt,$tid,'Enter the bill no. to match supplier statements.');
    }
    // 5. cash > 10,000 per payee per day (Income-tax 40A(3) awareness)
    $cp=[]; foreach($tx as $t) if(in_array($t['type'],['expense','purchase','payment_out'],true)&&$t['account']==='Cash'&&$t['paid']>0){ $k=$t['date'].'|'.($t['party']?:$t['category']); $cp[$k]=($cp[$k]??0)+$t['paid']; }
    $big=array_filter($cp,fn($v)=>$v>1000000); if($big) $add('medium','Cash over ₹10,000',count($big).' payee-days paid more than ₹10,000 in cash (total '.bk_r(array_sum($big)).'). Income-tax 40A(3) may disallow such cash expenses — confirm with your CA.',null,array_sum($big),null,'Pay large amounts by bank / UPI.');
    // 6. duplicates
    $seen=[]; foreach($tx as $t){ $k=$t['date'].'|'.$t['type'].'|'.$t['party_id'].'|'.$t['category'].'|'.$t['total']; if(isset($seen[$k])&&$t['total']>0) $add('medium','Possible duplicate',BK_TYPES[$t['type']][0].' '.bk_r($t['total']).' '.($t['party']?:$t['category']).' appears twice on the same day.',$t['date'],$t['total'],$t['id'],'Cancel one if it is a double entry.'); $seen[$k]=1; }
    // 7. supplier dues ageing
    foreach(bk_party_balances() as $p) if($p['kind']==='supplier'&&$p['payable']>500000&&$p['oldest_credit']&&(strtotime('today')-strtotime($p['oldest_credit']))>45*86400&&(!$p['last_paid']||(strtotime('today')-strtotime($p['last_paid']))>45*86400)) $add('high','Supplier unpaid',$p['name'].' — '.bk_r($p['payable']).' due, oldest unpaid bill '.$p['oldest_credit'].', '.($p['last_paid']?'last payment '.$p['last_paid']:'NO payment ever recorded').'.',null,$p['payable'],null,'Get supplier statement; record payments (bank payments too).');
    // 8. price variance
    foreach(bk_stock() as $s){ if($s['bills']>=3&&$s['min_rate']>0&&$s['max_rate']>1.5*$s['min_rate']) $add('medium','Rate variance',$s['name'].': rate from '.bk_r($s['min_rate']).' to '.bk_r($s['max_rate']).' per '.($s['unit']?:'unit').' — wrong unit or overcharge.',null,null,null,'Fix unit; set standard rate in Stock.');
        if($s['is_stock']&&$s['closing']<-0.001) $add('medium','Negative stock',$s['name'].' closing '.$s['closing'].' '.($s['unit']?:'').' — consumption/sale more than purchases.',null,null,null,'Mark menu items as non-stock or enter opening stock.'); }
    $st=bk_stock(); $never=array_filter($st,fn($s)=>$s['is_stock']&&$s['in']>0&&$s['used']==0&&$s['sold']==0&&$s['adjust']==0); if(count($never)>10) $add('high','No kitchen usage',count($never).' stock items were purchased but never issued/consumed or counted — closing stock is not real.',null,null,null,'Do a monthly stock count (Stock → Count) for every item.');
    // 9. staff reconciliation per month
    if($m){ $s=bk_month_summary($m); $r=$s['staff_recon']; if($r['register']&&($r['books_staff_expense']+$r['books_staff_payments'])>0){ $diff=$r['books_staff_expense']+$r['books_staff_payments']-$r['register']['cash_out_in_month']; if(abs($diff)>500000) $add('medium','Salary not matching','Staff money in Books '.bk_r($r['books_staff_expense']+$r['books_staff_payments']).' vs Salary Register advances+payments dated this month '.bk_r($r['register']['cash_out_in_month']).' (difference '.bk_r($diff).').',null,$diff,null,'Pay salary/advances only via the Salary Register.'); }
        $mo=$s['status']; if($mo!=='locked'&&strtotime($s['to'])+10*86400<time()) $add('low','Month not locked',$m.' ended more than 10 days ago and is not locked.',null,null,null,'Count cash and lock the month.'); }
    // Group repeated per-entry findings (e.g. 50 purchases without items) into one line with the list behind it.
    $byCat=[]; foreach($I as $x) $byCat[$x['cat']][]=$x; $out=[];
    foreach($byCat as $cat=>$xs){ if(count($xs)<=4){ foreach($xs as $x) $out[]=$x+['list'=>[]]; continue; }
        $tot=array_sum(array_map(fn($x)=>(int)$x['amount'],$xs)); $sev=$xs[0]['sev']; foreach($xs as $x) if($x['sev']==='high') $sev='high';
        $out[]=['sev'=>$sev,'cat'=>$cat,'text'=>count($xs).' entries — e.g. '.implode(' · ',array_map(fn($x)=>($x['date']?substr($x['date'],8,2).'/'.substr($x['date'],5,2).' ':'').mb_substr($x['text'],0,70),array_slice($xs,0,2))),'date'=>null,'amount'=>$tot,'txn'=>null,'fix'=>$xs[0]['fix'],
          'list'=>array_map(fn($x)=>['date'=>$x['date'],'text'=>$x['text'],'amount'=>$x['amount'],'txn'=>$x['txn']],array_slice($xs,0,500))]; }
    usort($out,fn($x,$y)=>array_search($x['sev'],['high','medium','low'])<=>array_search($y['sev'],['high','medium','low']));
    return $out;
}

/* ============================== import verification ============================== */
/** After import: every source row must be in the books (nothing missing, nothing twice) and money must match per Vyapar type. */
function bk_vy_verify(array $P,array $T): array {
    $keys=array_column($T,'key'); $found=[]; foreach(array_chunk($keys,500) as $ch) foreach(rows('SELECT import_key FROM dd_bk_txns WHERE import_key IN ('.implode(',',array_fill(0,count($ch),'?')).')',...$ch) as $x) $found[$x['import_key']]=1;
    $missing=array_values(array_filter($T,fn($t)=>!isset($found[$t['key']]))); $dupKeys=count($keys)-count(array_unique($keys));
    $src=[]; foreach($P['cash'] as $c){ $x=&$src[$c['vtype']]; $x??=['rows'=>0,'in'=>0,'out'=>0]; $x['rows']++; $x['in']+=$c['in']; $x['out']+=$c['out']; unset($x); }
    $cashSrc=0; foreach($P['cash'] as $c) $cashSrc+=$c['in']-$c['out']; $cashBooks=0; foreach($T as $t) $cashBooks+=bk_effect(['type'=>$t['type'],'paid'=>$t['paid'],'account'=>$t['account'],'to_account'=>$t['to']],'Cash');
    $lineSrc=array_sum(array_column($P['lines'],'amount')); $lineBooks=0; foreach($T as $t) $lineBooks+=array_sum(array_column($t['lines'],'amount'));
    $calcBad=0; foreach($P['lines'] as $l) if(abs((int)round($l['qty']*$l['rate']*100)-$l['discount']+$l['tax']-$l['amount'])>100) $calcBad++;
    $expSrc=array_sum(array_column($P['expenses'],'total')); $expBooks=array_sum(array_map(fn($t)=>$t['type']==='expense'?$t['total']:0,$T));
    $chk=[
     ['Vyapar cash-flow rows read',count($P['cash']),null,true],
     ['Entries planned (purchases of the same supplier on the same day merged; expenses matched to the Expense Report)',count($T),null,true],
     ['Entries missing in Books after import',count($missing),0,count($missing)===0],
     ['Duplicate import keys',$dupKeys,0,$dupKeys===0],
     ['Cash in hand: Vyapar net movement vs Books',bk_r($cashSrc),bk_r($cashBooks),$cashSrc===$cashBooks],
     ['Item lines: Vyapar amount vs Books',bk_r($lineSrc),bk_r($lineBooks),$lineSrc===$lineBooks||!$P['lines']],
     ['Item lines where qty × rate − discount + GST ≠ amount',$calcBad,0,$calcBad===0],
     ['Expense Report total vs Books expenses',bk_r($expSrc),bk_r($expBooks),!$P['expenses']||$expSrc<=$expBooks],
    ];
    return ['checks'=>$chk,'by_type'=>$src,'missing'=>array_slice(array_map(fn($t)=>$t['date'].' '.$t['type'].' '.$t['party'].' '.bk_r($t['total']),$missing),0,50),'ok'=>!array_filter($chk,fn($c)=>!$c[3])];
}

/* ============================== Vyapar import ============================== */
function bk_find_header(array $rows,array $must): ?int { foreach(array_slice($rows,0,15,true) as $i=>$r){ $n=array_map(fn($x)=>strtolower(trim((string)$x)),$r); $ok=true; foreach($must as $h) if(!in_array($h,$n,true)) { $ok=false; break; } if($ok) return $i; } return null; }
function bk_table(array $rows,int $hi): array { $h=array_map(fn($x)=>strtolower(trim((string)$x)),$rows[$hi]); $o=[]; foreach(array_slice($rows,$hi+1) as $r){ if(!array_filter($r,fn($x)=>trim((string)$x)!=='')) continue; $x=[]; foreach($h as $i=>$k) if($k!=='') $x[$k]=trim((string)($r[$i]??'')); $o[]=$x; } return $o; }
function bk_num(string $s): float { $s=str_replace([',','₹',' '],'',$s); if(preg_match('/^(-?\d+(\.\d+)?)/',$s,$m)) return (float)$m[1]; return 0.0; }
const BK_VY_TYPE=['sale'=>'sale','add cash'=>'add_cash','reduce cash'=>'reduce_cash','deposited'=>'transfer','withdrawn'=>'transfer','expense'=>'expense','other income'=>'other_income','payment-in'=>'payment_in','payment-out'=>'payment_out','purchase'=>'purchase'];
/** Parse any number of Vyapar export files into normalised records. */
function bk_vy_parse(array $files): array {
    $P=['cash'=>[],'lines'=>[],'expenses'=>[],'stock'=>[],'kinds'=>[],'warnings'=>[],'generated'=>null];
    foreach($files as $f){ require_once __DIR__.'/xls360.php'; $book=sheet_read_any($f['data']);
        foreach($book as $sname=>$rows){ if(!$rows) continue;
            foreach(array_slice($rows,0,3) as $r) if(preg_match('/Generated on (\w+ \d+,\s*\d{4})/i',implode(' ',$r),$mm)){ $g=date('Y-m-d',strtotime(str_replace(',',', ',$mm[1]))); $P['generated']=max($P['generated']??'',$g); }
            if(($h=bk_find_header($rows,['txn type','money in','money out']))!==null){ $P['kinds'][]='Cash Flow'; $occ=[];
                foreach(bk_table($rows,$h) as $r){ $tt=strtolower($r['txn type']??''); if($tt===''||$tt==='total') continue; $d=bk_ddmmyyyy($r['date']??''); if(!$d) continue; if(!isset(BK_VY_TYPE[$tt])){ $P['warnings'][]='Unknown Vyapar type “'.$r['txn type'].'” on '.$r['date'].' skipped.'; continue; }
                    $x=['date'=>$d,'vtype'=>$tt,'party'=>$r['party name']??'','category'=>$r['category']??'','in'=>(int)round(bk_num($r['money in']??'')*100),'out'=>(int)round(bk_num($r['money out']??'')*100),'desc'=>$r['description']??''];
                    $k=implode('|',[$d,$tt,bk_key($x['party']),bk_key($x['category']),$x['in'],$x['out']]); $occ[$k]=($occ[$k]??0)+1; $x['occ']=$occ[$k]; $P['cash'][]=$x; } continue; }
            if(($h=bk_find_header($rows,['item name','quantity','price/unit','amount']))!==null){ $P['kinds'][]=$sname;
                foreach(bk_table($rows,$h) as $r){ $d=bk_ddmmyyyy($r['date']??''); if(!$d||($r['item name']??'')==='') continue; $P['lines'][]=['date'=>$d,'party'=>$r['party name']??'','category'=>$r['expense category']??($r['category']??''),'bill_no'=>$r['invoice no.']??'','item'=>$r['item name'],'code'=>$r['item code']??'','qty'=>bk_num($r['quantity']??'0'),'unit'=>$r['unit']??'','rate'=>bk_num($r['price/unit']??'0'),'discount'=>(int)round(bk_num($r['discount']??'0')*100),'tax'=>(int)round(bk_num($r['gst']??'0')*100),'amount'=>(int)round(bk_num($r['amount']??'0')*100)]; } continue; }
            if(($h=bk_find_header($rows,['payment status','total amount','paid amount']))!==null){ $P['kinds'][]='Expense Report'; $occ=[];
                foreach(bk_table($rows,$h) as $r){ $d=bk_ddmmyyyy($r['date']??''); if(!$d) continue; $x=['date'=>$d,'party'=>$r['party name']??'','category'=>$r['category']??'','total'=>(int)round(bk_num($r['total amount']??'')*100),'paid'=>(int)round(bk_num($r['paid amount']??'')*100),'ptype'=>$r['payment type']??'Cash','desc'=>$r['description']??'','gstin'=>$r["party's gstin no."]??''];
                    $k=implode('|',[$d,bk_key($x['category']),bk_key($x['party'])]); $occ[$k]=($occ[$k]??0)+1; $x['occ']=$occ[$k]; $P['expenses'][]=$x; } continue; }
            if(($h=bk_find_header($rows,['item name','opening quantity','quantity in','quantity out']))!==null){ $P['kinds'][]='Stock Detail';
                foreach(bk_table($rows,$h) as $r){ if(($r['item name']??'')===''||strtolower($r['item name'])==='total') continue; $P['stock'][]=['item'=>$r['item name'],'opening'=>bk_num($r['opening quantity']??'0'),'in'=>bk_num($r['quantity in']??'0'),'out'=>bk_num($r['quantity out']??'0'),'closing'=>bk_num($r['closing quantity']??'0')]; } continue; }
        } }
    if(!$P['kinds']) fail('No Vyapar report found. Upload Cash Flow Report, Expense Transaction Report and/or Stock Detail Report (.xls or .xlsx).');
    return $P;
}
/** Build the list of transactions to create (pure — used by preview and confirm). */
function bk_vy_plan(array $P,array $opt): array {
    $T=[]; $acctMap=function(string $s): string { $s=trim($s); if($s===''||strcasecmp($s,'cash')===0) return 'Cash'; if(stripos($s,'safik')!==false||stripos($s,'home')!==false) return 'Owner (Safik Home)'; if(stripos($s,'cheque')!==false) return 'Bank of India'; foreach(bk_accounts() as $a) if(strcasecmp($a,$s)===0) return $a; return $s; };
    $lines=[]; foreach($P['lines'] as $l) $lines[$l['date'].'|'.bk_key($l['party'])][]=$l;
    // Cash truth: the Cash Flow report shows what really left the drawer. Vyapar marks party expenses (e.g. salary to a staff party) “Paid in Cash”
    // even when the cash went out later as Payment-out — use the cash-flow amount as “paid” so nothing is counted twice.
    $cfOut=[]; foreach($P['cash'] as $c) if($c['vtype']==='expense') $cfOut[$c['date'].'|'.bk_key($c['category']).'|'.bk_key($c['party'])][]=$c['out'];
    $expKeys=[]; foreach($P['expenses'] as $e){ $ek=$e['date'].'|'.bk_key($e['category']).'|'.bk_key($e['party']); $k='vy|exp|'.$ek.'|'.$e['occ']; $expKeys[$k]=1; $acct=$acctMap($e['ptype']); $paid=$e['paid']; $note=$e['desc'];
        if($acct==='Cash'&&$P['cash']&&isset($cfOut[$ek])){ $i=array_search($paid,$cfOut[$ek],true); if($i===false){ $i=array_key_first($cfOut[$ek]); } if($i!==null){ $cash=$cfOut[$ek][$i]; unset($cfOut[$ek][$i]); if($cash!==$paid){ $note=trim($note.' [Vyapar expense report says paid '.rupees($paid).' but cash book shows '.rupees($cash).' — balance settled by Payment-out]'); $paid=min($cash,max($e['total'],$e['paid'])); } } }
        $T[]=['key'=>$k,'date'=>$e['date'],'type'=>'expense','party'=>$e['party'],'category'=>$e['category']?:'Uncategorised','total'=>max($e['total'],$e['paid']),'paid'=>$paid,'account'=>$acct,'to'=>null,'desc'=>$note,'lines'=>[],'src'=>'vyapar-exp'];
        if($e['total']<100) $T[count($T)-1]['desc']=trim($e['desc'].' [Vyapar test/odd entry]'); }
    $purG=[]; $eo=[];
    foreach($P['cash'] as $c){ $vt=$c['vtype']; $type=BK_VY_TYPE[$vt]; $party=$c['party']; $cat=$c['category']; $amt=max($c['in'],$c['out']);
        $base=['date'=>$c['date'],'party'=>$party,'category'=>$cat?:null,'desc'=>$c['desc'],'lines'=>[],'src'=>'vyapar-cf','to'=>null,'account'=>'Cash'];
        if($vt==='expense'){ $ek=$c['date'].'|'.bk_key($cat).'|'.bk_key($party); $eo[$ek]=($eo[$ek]??0)+1; $k='vy|exp|'.$ek.'|'.$eo[$ek]; if(isset($expKeys[$k])) continue; $T[]=array_merge($base,['key'=>$k,'type'=>'expense','category'=>$cat?:'Uncategorised','total'=>$c['out'],'paid'=>$c['out']]); continue; }
        if($vt==='purchase'){ $g=$c['date'].'|'.bk_key($party); $purG[$g]??=array_merge($base,['key'=>'vy|pur|'.$g,'type'=>'purchase','total'=>0,'paid'=>0,'n'=>0]); $purG[$g]['paid']+=$c['out']; $purG[$g]['n']++; continue; }
        $k='vy|cf|'.implode('|',[$c['date'],$vt,bk_key($party),bk_key($cat),$c['in'],$c['out'],$c['occ']]);
        if($vt==='sale'){ $pk=bk_key($party); if(!empty($opt['cash_access'])&&in_array($pk,['cashacess','cashaccess'],true)){ $T[]=array_merge($base,['key'=>$k,'type'=>'cash_access','party'=>'','total'=>$amt,'paid'=>$amt,'desc'=>trim('Petpooja Cash Access (Vyapar booked it as Sale) '.$c['desc'])]); continue; }
            if($pk==='otherincome'){ $T[]=array_merge($base,['key'=>$k,'type'=>'other_income','party'=>'','total'=>$amt,'paid'=>$amt]); continue; }
            $ls=$lines[$c['date'].'|'.$pk]??[]; $tot=$ls?array_sum(array_column($ls,'amount')):$amt; $T[]=array_merge($base,['key'=>$k,'type'=>'sale','party'=>in_array($pk,['petpoojasale'],true)?'Petpooja Sale':$party,'total'=>max($tot,$amt),'paid'=>$amt,'lines'=>$ls]); unset($lines[$c['date'].'|'.$pk]); continue; }
        if($vt==='deposited'){ $T[]=array_merge($base,['key'=>$k,'type'=>'transfer','account'=>'Cash','to'=>$cat?$acctMap($cat):(bk_accounts()[1]??'Bank of India'),'total'=>$amt,'paid'=>$amt,'desc'=>trim('Cash deposited to bank '.$c['desc'])]); continue; }
        if($vt==='withdrawn'){ $from=$acctMap($cat?:'Bank of India'); if(!empty($opt['owner_home'])&&$from==='Owner (Safik Home)'){ $T[]=array_merge($base,['key'=>$k,'type'=>'owner_in','party'=>'','category'=>null,'total'=>$amt,'paid'=>$amt,'desc'=>trim('Owner/home money brought in (Vyapar: Withdrawn from '.$cat.') '.$c['desc'])]); continue; }
            $T[]=array_merge($base,['key'=>$k,'type'=>'transfer','account'=>$from,'to'=>'Cash','total'=>$amt,'paid'=>$amt,'desc'=>trim('Withdrawn from '.$cat.' '.$c['desc'])]); continue; }
        if($vt==='add cash'||$vt==='reduce cash'){ $T[]=array_merge($base,['key'=>$k,'type'=>$type,'party'=>'','total'=>$amt,'paid'=>$amt,'desc'=>trim('Vyapar '.($cat?:'cash adjustment').' (no reason given) '.$c['desc'])]); continue; }
        $T[]=array_merge($base,['key'=>$k,'type'=>$type,'total'=>$amt,'paid'=>$amt]);
    }
    foreach($purG as $g=>$p){ $ls=$lines[$g]??[]; unset($lines[$g]); $p['lines']=$ls; $p['total']=$ls?array_sum(array_column($ls,'amount')):$p['paid']; if($p['paid']>$p['total']) $p['total']=$p['paid'];
        if(!$ls) $p['desc']=trim($p['desc'].' [Vyapar: no items, value unknown]'); if($p['n']>1) $p['desc']=trim($p['desc'].' ['.$p['n'].' Vyapar entries same day merged]'); unset($p['n']); $T[]=$p; }
    // item lines with no matching purchase/sale in the cash flow (e.g. only Item Details uploaded)
    foreach($lines as $g=>$ls){ [$d]=explode('|',$g); $T[]=['key'=>'vy|pur|'.$g,'date'=>$d,'type'=>'purchase','party'=>$ls[0]['party'],'category'=>null,'total'=>array_sum(array_column($ls,'amount')),'paid'=>0,'account'=>'Cash','to'=>null,'desc'=>'[from item list only]','lines'=>$ls,'src'=>'vyapar-items']; }
    usort($T,fn($a,$b)=>[$a['date'],$a['key']]<=>[$b['date'],$b['key']]);
    return $T;
}
function bk_vy_preview(array $files,array $opt): array {
    $P=bk_vy_parse($files); $T=bk_vy_plan($P,$opt); $exist=[]; foreach(array_chunk(array_column($T,'key'),500) as $ch) foreach(rows('SELECT import_key,source FROM dd_bk_txns WHERE import_key IN ('.implode(',',array_fill(0,count($ch),'?')).')',...$ch) as $r) $exist[$r['import_key']]=$r['source'];
    $sum=[]; $new=0; $locked=0; $dates=[];
    foreach($T as $t){ $s=&$sum[$t['type']]; $s??=['n'=>0,'new'=>0,'total'=>0,'paid'=>0]; $s['n']++; $s['total']+=$t['total']; $s['paid']+=$t['paid']; $isNew=!isset($exist[$t['key']]); if($isNew){ $s['new']++; $new++; } if(bk_month_status($t['date'])==='locked') $locked++; $dates[]=$t['date']; unset($s); }
    $cash=0; foreach($T as $t) $cash+=bk_effect(['type'=>$t['type'],'paid'=>$t['paid'],'account'=>$t['account'],'to_account'=>$t['to']],'Cash');
    $parties=array_values(array_unique(array_filter(array_map(fn($t)=>trim((string)$t['party']),$T)))); $newParties=array_values(array_filter($parties,fn($p)=>!row('SELECT id FROM dd_bk_parties WHERE name_key=?',bk_key($p))));
    $items=array_values(array_unique(array_map(fn($l)=>$l['item'],$P['lines']))); $newItems=array_values(array_filter($items,fn($i)=>!row('SELECT id FROM dd_bk_items WHERE name_key=?',bk_key($i))));
    $cats=array_values(array_unique(array_filter(array_map(fn($t)=>$t['type']==='expense'?$t['category']:null,$T)))); $unmapped=array_values(array_filter($cats,fn($c)=>bk_group($c)==='Uncategorised'));
    return ['kinds'=>array_values(array_unique($P['kinds'])),'warnings'=>$P['warnings'],'generated'=>$P['generated'],'from'=>$dates?min($dates):null,'to'=>$dates?max($dates):null,'by_type'=>$sum,'total'=>count($T),'new'=>$new,'locked'=>$locked,
      'cash_effect'=>$cash,'parties'=>count($parties),'new_parties'=>$newParties,'items'=>count($items),'new_items'=>$newItems,'stock_rows'=>count($P['stock']),'unmapped_categories'=>$unmapped,'categories'=>$cats,'groups'=>BK_GROUPS];
}
function bk_vy_confirm(array $files,array $opt,array $u): array {
    $P=bk_vy_parse($files); $T=bk_vy_plan($P,$opt); $r=['created'=>0,'updated'=>0,'skipped'=>0,'locked'=>0,'lines'=>0,'stock_items'=>0,'stock_moves'=>0];
    $cats=bk_categories(); foreach((array)($opt['category_groups']??[]) as $c=>$g) if(in_array($g,BK_GROUPS,true)) $cats[(string)$c]=$g; set_setting('bk_categories',json_encode($cats,JSON_UNESCAPED_UNICODE));
    $acc=bk_accounts(); foreach($T as $t){ foreach([$t['account'],$t['to']] as $a) if($a&&!in_array($a,$acc,true)) $acc[]=$a; } set_setting('bk_accounts',json_encode(array_values($acc)));
    foreach($T as $t){ if(bk_month_status($t['date'])==='locked'){ $r['locked']++; continue; }
        $ex=row('SELECT id,source FROM dd_bk_txns WHERE import_key=?',$t['key']);
        if($ex&&$t['lines']){ $old=rows('SELECT id,discount,tax FROM dd_bk_lines WHERE txn_id=? ORDER BY id',$ex['id']); if(count($old)===count($t['lines'])) foreach(array_values($t['lines']) as $i=>$l) if(((int)$old[$i]['discount']!==$l['discount'])||((int)$old[$i]['tax']!==$l['tax'])){ run('UPDATE dd_bk_lines SET discount=?,tax=? WHERE id=?',$l['discount'],$l['tax'],$old[$i]['id']); $r['lines_repaired']=($r['lines_repaired']??0)+1; } }
        if($ex){ if($ex['source']==='vyapar-cf'&&$t['src']==='vyapar-exp'){ run('UPDATE dd_bk_txns SET total=?,paid=?,account=?,source=? WHERE id=?',$t['total'],$t['paid'],$t['account'],$t['src'],$ex['id']); $r['updated']++; } else $r['skipped']++; continue; }
        $pid=trim((string)$t['party'])!==''?bk_party_id($t['party'],in_array($t['type'],['sale','payment_in'],true)?'customer':'supplier'):null;
        run('INSERT INTO dd_bk_txns(date,type,party_id,category,total,paid,account,to_account,bill_no,description,source,import_key,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',$t['date'],$t['type'],$pid,$t['category']?txt($t['category'],80):null,$t['total'],$t['paid'],$t['account'],$t['to'],($t['lines'][0]['bill_no']??'')?:null,txt($t['desc']??'',500)?:null,$t['src'],$t['key'],$u['id']);
        $id=(int)db()->lastInsertId(); $r['created']++;
        foreach($t['lines'] as $l){ $iid=bk_item_id($l['item'],$l['unit']?:null,$l['category']?:null,$l['code']?:null); run('INSERT INTO dd_bk_lines(txn_id,item_id,qty,unit,rate,discount,tax,amount) VALUES(?,?,?,?,?,?,?,?)',$id,$iid,number_format($l['qty'],3,'.',''),$l['unit']?:null,(int)round($l['rate']*100),$l['discount'],$l['tax'],$l['amount']); $r['lines']++; }
    }
    // Stock Detail: items not seen in lines + Vyapar "Quantity Out" (no detail available) as one movement per item (re-import replaces it)
    $asOf=$P['generated']??date('Y-m-d');
    foreach($P['stock'] as $s){ $iid=bk_item_id($s['item']); $r['stock_items']++;
        if($s['opening']>0) run('UPDATE dd_bk_items SET opening_qty=? WHERE id=? AND opening_qty=0',number_format($s['opening'],3,'.',''),$iid);
        $sold=(float)(row("SELECT COALESCE(SUM(l.qty),0) q FROM dd_bk_lines l JOIN dd_bk_txns t ON t.id=l.txn_id AND t.voided=0 AND t.type='sale' WHERE l.item_id=?",$iid)['q']); $out=round($s['out']-$sold,3);
        if($s['in']==0&&$s['out']>0&&!row("SELECT 1 FROM dd_bk_lines l JOIN dd_bk_txns t ON t.id=l.txn_id AND t.type='purchase' WHERE l.item_id=?",$iid)) run('UPDATE dd_bk_items SET is_stock=0 WHERE id=?',$iid);
        $key='vy|stockout|'.$iid; $ex=row('SELECT id,date FROM dd_bk_moves WHERE import_key=?',$key);
        if(abs($out)<0.0005){ if($ex&&bk_month_status($ex['date'])!=='locked') run('UPDATE dd_bk_moves SET voided=1 WHERE id=?',$ex['id']); continue; }
        if(bk_month_status($asOf)==='locked') continue;
        if($ex) run('UPDATE dd_bk_moves SET qty=?,date=?,voided=0 WHERE id=?',number_format(-$out,3,'.',''),$asOf,$ex['id']);
        else run("INSERT INTO dd_bk_moves(item_id,date,qty,kind,note,source,import_key,created_by) VALUES(?,?,?,'consume',?,'vyapar',?,?)",$iid,$asOf,number_format(-$out,3,'.',''),'Vyapar stock report “Quantity Out” total up to '.$asOf.' (no daily detail in Vyapar)',$key,$u['id']);
        $r['stock_moves']++; }
    $r['verify']=bk_vy_verify($P,$T);
    foreach($files as $f) run('INSERT INTO dd_bk_imports(file_name,file_sha256,kinds,result,created_by) VALUES(?,?,?,?,?)',txt($f['name'],160),hash('sha256',$f['data']),implode(',',array_unique($P['kinds'])),json_encode($r),$u['id']);
    audit($u,'books.import.vyapar',$r); return ['ok'=>true]+$r;
}

/* ============================== exports ============================== */
function bk_csv(array $head,array $rows,string $name): never {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$name.'"'); echo "\xEF\xBB\xBF".implode(',',array_map('csv_cell',$head))."\r\n"; foreach($rows as $r) echo implode(',',array_map('csv_cell',$r))."\r\n"; exit;
}
function bk_export(string $what,array $q): never {
    $r2=fn($p)=>number_format($p/100,2,'.','');
    if($what==='daybook'){ $from=valid_date($q['from']??''); $to=valid_date($q['to']??''); $acct=(string)($q['account']??'Cash'); $d=bk_daybook($from,$to,$acct);
        bk_csv(['Date','Type','Party','Category','Bill no','Total','Paid','Account','To account',$acct.' in/out','Running '.$acct,'Description','Cancelled'],array_map(fn($t)=>[$t['date'],BK_TYPES[$t['type']][0],$t['party'],$t['category'],$t['bill_no'],$r2($t['total']),$r2($t['paid']),$t['account'],$t['to_account'],$r2($t['cash']),$r2($t['running']),$t['description'],$t['voided']?'Yes':''],$d['txns']),'KSDD-daybook-'.$from.'-to-'.$to.'.csv'); }
    if($what==='parties'){ bk_csv(['Party','Type','Opening','Purchases','Paid','We owe (payable)','Sales','Received','They owe (receivable)','Last payment','Oldest unpaid bill'],array_map(fn($p)=>[$p['name'],$p['kind'],$r2($p['opening']),$r2($p['purchases']),$r2($p['paid_out']+$p['paid_with_bill']),$r2($p['payable']),$r2($p['sales']),$r2($p['paid_in']),$r2($p['receivable']),$p['last_paid'],$p['oldest_credit']],bk_party_balances()),'KSDD-parties.csv'); }
    if($what==='stock'){ bk_csv(['Item','Category','Unit','Opening','In','Sold','Used','Adjust','Closing','Last rate','Min rate','Max rate','Value'],array_map(fn($s)=>[$s['name'],$s['category'],$s['unit'],$s['opening'],$s['in'],$s['sold'],$s['used'],$s['adjust'],$s['closing'],$s['last_rate']===null?'':$r2($s['last_rate']),$s['min_rate']===null?'':$r2($s['min_rate']),$s['max_rate']===null?'':$r2($s['max_rate']),$r2($s['value'])],bk_stock()),'KSDD-stock.csv'); }
    if($what==='expenses'){ $m=valid_month($q['month']??''); $s=bk_month_summary($m); bk_csv(['Group','Category','Entries','Amount'],array_map(fn($c)=>[$c['group'],$c['category'],$c['n'],$r2($c['amount'])],$s['expense_categories']),'KSDD-expenses-'.$m.'.csv'); }
    fail('Unknown export.',404);
}
