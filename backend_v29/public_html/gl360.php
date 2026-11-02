<?php
declare(strict_types=1);
// KSDD Restaurant ERP — double-entry General Ledger (ERP V1 §M07 / V6 §4).
// SHADOW MODE: every existing source event (daybook/ledger row, supplier bill, supplier credit, stock movement, approved payroll)
// is turned into ONE balanced journal by deterministic posting rules, idempotent on a unique source_key.
// The legacy daybook (dd_ledger) stays the operational record; the GL is rebuilt from it, never edited by hand except through
// balanced manual journals (owner/accountant) and reversals. Posted journals are immutable.
// Account mappings are owner/accountant editable starter defaults — they MUST be reviewed by the CA before formal statements.
require_once __DIR__.'/lib360.php';

const GL_ASSET_ACCOUNTS = ['Cash'=>'cash','Bank of India'=>'bank1','Other bank'=>'bank2','Paytm'=>'upi','Card'=>'card','Unallocated'=>'suspense'];
const GL_COA = [ // system_key => [code, name, hindi, type, normal]
 'cash'=>['1000','Cash Drawer','कैश दराज़','asset','D'],'bank1'=>['1100','Bank — Bank of India','बैंक','asset','D'],'bank2'=>['1101','Bank — Other bank','अन्य बैंक','asset','D'],
 'transit'=>['1150','Cash in Transit / Bank-to-cash','रास्ते में नकद','asset','D'],'upi'=>['1110','UPI / Paytm Clearing','UPI क्लियरिंग','asset','D'],'card'=>['1120','Card Clearing','कार्ड क्लियरिंग','asset','D'],
 'suspense'=>['1199','Suspense — needs classification','संदिग्ध','asset','D'],'ar'=>['1200','Customer Receivables','ग्राहक बकाया','asset','D'],'emp_adv'=>['1300','Employee Advances','स्टाफ एडवांस','asset','D'],
 'sup_adv'=>['1310','Supplier Advances','सप्लायर एडवांस','asset','D'],'inventory'=>['1400','Inventory — Food & Stores','स्टॉक','asset','D'],'deposits'=>['1500','Refundable Deposits (LPG etc.)','जमा राशि','asset','D'],'fixed'=>['1600','Fixed Assets','संपत्ति','asset','D'],
 'ap'=>['2000','Trade Payables (Suppliers)','सप्लायर देय','liability','C'],'sal_pay'=>['2100','Salary Payable','वेतन देय','liability','C'],'accrued'=>['2200','Accrued Expenses','देय खर्च','liability','C'],'tax_pay'=>['2300','Output Tax Payable (review)','कर देय','liability','C'],
 'capital'=>['3000','Owner Capital','मालिक पूंजी','equity','C'],'drawings'=>['3100','Owner Drawings','मालिक निकासी','equity','D'],'opening'=>['3900','Opening Balance Equity','प्रारंभिक शेष','equity','C'],
 'bk_ap'=>['2010','Payables — Books parties','बुक्स देय','liability','C'],
 'sales'=>['4000','Sales Revenue','बिक्री','revenue','C'],'rounding'=>['4950','Sales Rounding & Unexplained Residual (review)','राउंडिंग','revenue','C'],'other_inc'=>['4800','Other Income','अन्य आय','revenue','C'],'penalty_inc'=>['4810','Staff Penalties / Fines','जुर्माना','revenue','C'],'returns'=>['4900','Sales Returns & Refunds','रिफंड','revenue','D'],
 'cogs'=>['5000','Cost of Food Consumed','खाद्य लागत','expense','D'],'waste'=>['5010','Food Wastage','बर्बादी','expense','D'],'stock_var'=>['5020','Stock Count Variance','स्टॉक अंतर','expense','D'],
 'purch_misc'=>['5100','Purchases — non-stock / legacy bills','अन्य खरीद','expense','D'],'purch_charges'=>['5150','Purchase Charges, Tax & Rounding','खरीद शुल्क','expense','D'],'purch_credit'=>['5160','Purchase Returns & Supplier Credits','खरीद वापसी','expense','C'],
 'direct_food'=>['5200','Direct Cash Food Purchases','नकद खाद्य खरीद','expense','D'],
 'salary'=>['6000','Staff Salaries & Wages','वेतन','expense','D'],'utilities'=>['6200','Electricity, Water & Utilities','बिजली पानी','expense','D'],'rent'=>['6250','Rent','किराया','expense','D'],'repairs'=>['6300','Repairs & Maintenance','मरम्मत','expense','D'],
 'cleaning'=>['6350','Cleaning & Housekeeping','सफ़ाई','expense','D'],'transport'=>['6400','Transport & Delivery','भाड़ा','expense','D'],'fees'=>['6500','Payment Gateway / Merchant Charges','शुल्क','expense','D'],'incentive'=>['6600','Driver Incentives & Staff Tips','इंसेंटिव','expense','D'],
 'fuel'=>['6700','Cooking Fuel (LPG)','गैस','expense','D'],'other_exp'=>['6900','Other Operating Expenses','अन्य खर्च','expense','D'],
];
const GL_CATEGORY_DEFAULTS = ['Vegetables'=>'direct_food','Milk & dairy'=>'direct_food','Groceries'=>'direct_food','LPG / gas'=>'fuel','Cleaning'=>'cleaning','Maintenance'=>'repairs','Transport'=>'transport','Travel'=>'transport',
 'Staff tips'=>'incentive','Bus driver tip'=>'incentive','Taxi incentive'=>'incentive','Overtime wages'=>'salary','Utilities'=>'utilities','Electricity'=>'utilities','Rent'=>'rent','Petty cash'=>'other_exp','Payment fees'=>'fees','Payment fee tax'=>'fees','Owner drawing'=>'drawings','Other'=>'other_exp','Other expense'=>'other_exp'];
const GL_CASHIN_MAP = ['Owner contribution'=>'capital','Cash received from home'=>'capital','Customer credit collection'=>'ar','Bank to cash withdrawal'=>'transit','Cash transfer IN'=>'transit','Petty cash returned'=>'suspense','Other genuine receipt'=>'suspense'];

function gl_acct(string $key): int { static $c=[]; if(isset($c[$key])) return $c[$key]; $r=row('SELECT id FROM dd_gl_accounts WHERE system_key=?',$key); if(!$r) fail('GL account '.$key.' missing. Run the database update.',503); return $c[$key]=(int)$r['id']; }
function gl_cat(string $cat,string $fallback='other_exp'): int { $r=row('SELECT a.id FROM dd_gl_category_map m JOIN dd_gl_accounts a ON a.code=m.account_code WHERE m.category=?',$cat); return $r?(int)$r['id']:gl_acct(GL_CATEGORY_DEFAULTS[$cat]??$fallback); }
function gl_money_acct(?string $name): int { return gl_acct(GL_ASSET_ACCOUNTS[(string)$name]??'suspense'); }

/** Post one balanced journal. $lines = [[account_id, debit, credit, memo?, party_type?, party_id?]]. Idempotent on $key. */
function gl_post(string $key,string $date,string $type,?int $sid,string $memo,array $lines,?array $u,?int $reverses=null): ?int {
    if($x=row('SELECT id FROM dd_gl_journals WHERE source_key=?',$key)) return (int)$x['id'];
    foreach($lines as $l){ if(!is_int($l[1])||!is_int($l[2])||$l[1]<0||$l[2]<0||($l[1]&&$l[2])) fail('Invalid journal line in '.$key.'.'); }
    $lines=array_values(array_filter($lines,fn($l)=>$l[1]>0||$l[2]>0)); if(!$lines) return null;
    $d=0;$c=0; foreach($lines as $l){ if($l[1]<0||$l[2]<0||($l[1]&&$l[2])) fail('Invalid journal line in '.$key.'.'); $d+=$l[1]; $c+=$l[2]; }
    if($d!==$c) fail('Unbalanced journal '.$key.': debit ₹'.rupees($d).' ≠ credit ₹'.rupees($c).'. Not posted.');
    run('INSERT INTO dd_gl_journals(journal_no,business_date,period,source_type,source_id,source_key,memo,status,reverses_id,total,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)',seq('JV',$date),$date,substr($date,0,7),$type,$sid,$key,txt($memo,250),'posted',$reverses,$d,$u['id']??null);
    $jid=(int)db()->lastInsertId();
    foreach($lines as $l) run('INSERT INTO dd_gl_lines(journal_id,account_id,debit,credit,memo,party_type,party_id) VALUES(?,?,?,?,?,?,?)',$jid,$l[0],$l[1],$l[2],txt($l[3]??'',160)?:null,$l[4]??null,$l[5]??null);
    return $jid;
}
/** Reversal = new journal with swapped sides, dated in an open period; the original stays (immutable) and is marked reversed. */
function gl_reverse(int $jid,string $date,string $why,?array $u): ?int {
    $j=row('SELECT * FROM dd_gl_journals WHERE id=? FOR UPDATE',$jid); if(!$j) fail('Journal not found.'); if($j['status']==='reversed') return null;
    $lines=array_map(fn($l)=>[(int)$l['account_id'],(int)$l['credit'],(int)$l['debit'],'Reversal: '.($l['memo']??''),$l['party_type'],$l['party_id']?(int)$l['party_id']:null],rows('SELECT * FROM dd_gl_lines WHERE journal_id=?',$jid));
    $r=gl_post('rev:'.$j['source_key'],$date,'reversal',$jid,'Reversal of '.$j['journal_no'].': '.$why,$lines,$u,$jid); run("UPDATE dd_gl_journals SET status='reversed' WHERE id=?",$jid); return $r;
}
function gl_open_date(string $d): string { // a source dated in a hard-closed month posts on the first open day after it
    while(($m=row("SELECT status FROM dd_monthly_closures WHERE month=?",substr($d,0,7)))&&$m['status']==='hard') $d=(new DateTimeImmutable(substr($d,0,7).'-01'))->modify('+1 month')->format('Y-m-d');
    return $d;
}

/* ---------- Posting rules (one function per source type) ---------- */
function gl_rule_ledger(array $e): array {
    $a=(int)$e['amount']; $acct=gl_money_acct($e['account']); $cat=(string)$e['category']; $memo=$e['type'].' · '.$e['name'].($e['receipt_no']?' · '.$e['receipt_no']:'');
    switch($e['type']){
    case 'sale': return [[$acct,$a,0,$memo],[gl_acct('sales'),0,$a,$memo]];
    case 'refund': return [[gl_acct('returns'),$a,0,$memo],[$acct,0,$a,$memo]];
    case 'payment-in': $lbl=GL_CASHIN_MAP[$cat]??null; $cr=$lbl?gl_acct($lbl):gl_acct('suspense'); return [[$acct,$a,0,$memo],[$cr,0,$a,$memo]];
    case 'expense': return [[gl_cat($cat),$a,0,$memo],[$acct,0,$a,$memo]];
    case 'salary': return [[gl_acct('sal_pay'),$a,0,$memo,'employee',$e['employee_id']?(int)$e['employee_id']:null],[$acct,0,$a,$memo]];
    case 'advance': return [[gl_acct('emp_adv'),$a,0,$memo,'employee',$e['employee_id']?(int)$e['employee_id']:null],[$acct,0,$a,$memo]];
    case 'deposit': case 'withdrawal': case 'settlement': return [[gl_money_acct($e['to_account']),$a,0,$memo],[$acct,0,$a,$memo]];
    case 'payment-out':
        if(row('SELECT 1 FROM dd_supplier_credits WHERE ledger_id=? AND kind=\'advance\'',$e['id'])) return [[gl_acct('sup_adv'),$a,0,$memo],[$acct,0,$a,$memo]];
        $billed=(int)(row('SELECT COALESCE(SUM(amount),0) n FROM dd_bill_payments WHERE ledger_id=?',$e['id'])['n']);
        if($billed>0) return [[gl_acct('ap'),min($a,$billed),0,$memo,'supplier',null],[gl_acct('suspense'),max(0,$a-$billed),0,'Part not allocated to any bill'],[$acct,0,$a,$memo]];
        if($cat==='Supplier payment') return [[gl_acct('ap'),$a,0,$memo.' (legacy, no bill allocation)','supplier',null],[$acct,0,$a,$memo]];
        if(in_array($cat,['Owner drawing','Owner withdrawal'],true)) return [[gl_acct('drawings'),$a,0,$memo],[$acct,0,$a,$memo]];
        return [[gl_cat($cat,'incentive'),$a,0,$memo],[$acct,0,$a,$memo]];
    }
    return [[gl_acct('suspense'),$a,0,$memo],[$acct,0,$a,$memo]];
}
function gl_rule_bill(array $b): array {
    $amt=(int)$b['amount']; $memo='Supplier bill '.$b['reference']; $ap=[gl_acct('ap'),0,$amt,$memo,'supplier',(int)$b['supplier_id']];
    if($b['kind']==='opening') return [[gl_acct('opening'),$amt,0,'Opening payable '.$b['reference']],$ap];
    if($b['kind']==='debit') return [[gl_acct('purch_charges'),$amt,0,$memo],$ap];
    if($b['receipt_id']){ // inventory at the exact bill-line values; bill-level tax/charges/discount/rounding to 5150
        $inv=(int)(row("SELECT COALESCE(SUM(m.cost_value),0) n FROM dd_bill_lines bl JOIN dd_stock_moves m ON m.id=bl.stock_move_id WHERE bl.bill_id=?",$b['id'])['n']); $diff=$amt-$inv;
        return [[gl_acct('inventory'),$inv,0,$memo],$diff>0?[gl_acct('purch_charges'),$diff,0,'Bill charges/tax/rounding']:[gl_acct('purch_charges'),0,-$diff,'Bill discount/rounding'],$ap]; }
    return [[gl_acct('purch_misc'),$amt,0,$memo.' (no item lines)'],$ap];
}
function gl_rule_credit(array $c): ?array {
    $memo='Supplier '.$c['kind'].' #'.$c['id'].' '.($c['reference']??'');
    if($c['kind']==='advance'){ if(!$c['bill_id']) return null; $a=(int)$c['amount']; return [[gl_acct('ap'),$a,0,$memo.' applied to bill','supplier',(int)$c['supplier_id']],[gl_acct('sup_adv'),0,$a,$memo]]; }
    if(str_starts_with((string)$c['reason'],'Remainder of #')) return null; // split remainder of an already-posted credit
    // Fix C08: the credit note is booked ONCE at its original value = this row + every remainder split from it, at any depth.
    $a=(int)$c['amount']+gl_credit_remainders((int)$c['id']);
    return [[gl_acct('ap'),$a,0,$memo,'supplier',(int)$c['supplier_id']],[gl_acct('purch_credit'),0,$a,$memo]];
}
function gl_credit_remainders(int $id,int $depth=0): int { if($depth>50) fail('Supplier credit remainder chain is too deep (#'.$id.').'); $n=0;
    foreach(rows("SELECT id,amount FROM dd_supplier_credits WHERE reason LIKE ?",'Remainder of #'.$id.':%') as $r) $n+=(int)$r['amount']+gl_credit_remainders((int)$r['id'],$depth+1); return $n; }
function gl_rule_move(array $m): ?array {
    $v=(int)($m['cost_value']??0); if($v<=0) return null; $q=qm($m['quantity']); $memo=$m['kind'].' · item #'.$m['item_id'].' · '.($m['reference']?:$m['reason']); $inv=gl_acct('inventory');
    return match($m['kind']){
        'consume'=>[[gl_acct('cogs'),$v,0,$memo],[$inv,0,$v,$memo]],
        'wastage','expired','damaged'=>[[gl_acct('waste'),$v,0,$memo],[$inv,0,$v,$memo]],
        'return'=>[[gl_acct('purch_credit'),$v,0,$memo],[$inv,0,$v,$memo]],
        'opening'=>[[$inv,$v,0,$memo],[gl_acct('opening'),0,$v,$memo]],
        'adjust','legacy'=>$q>=0?[[$inv,$v,0,$memo],[gl_acct('stock_var'),0,$v,$memo]]:[[gl_acct('stock_var'),$v,0,$memo],[$inv,0,$v,$memo]],
        default=>null }; // 'in' is posted by the supplier bill (Dr Inventory / Cr AP); 'transfer' is internal
}
function gl_rule_payroll(array $p): array {
    $g=(int)$p['gross']; $ded=(int)$p['deductions']; $adv=(int)$p['advance']; $net=(int)$p['net']; $memo='Payroll '.$p['month'].' · employee #'.$p['employee_id']; $e=(int)$p['employee_id'];
    return [[gl_acct('salary'),$g,0,$memo,'employee',$e],[gl_acct('sal_pay'),0,$net,$memo,'employee',$e],[gl_acct('emp_adv'),0,$adv,'Advance recovered','employee',$e],[gl_acct('penalty_inc'),0,$ded,'Deductions']];
}

/** Bring the GL up to date with every source event. Safe to run any number of times (cron, button, before reports). */
function gl_sync(?array $u,int $limit=5000): array {
    $n=['ledger'=>0,'bills'=>0,'credits'=>0,'stock'=>0,'payroll'=>0,'reversed'=>0];
    foreach(rows("SELECT l.* FROM dd_ledger l LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('ledger:',l.id) WHERE j.id IS NULL AND l.voided=0 ORDER BY l.id LIMIT $limit") as $e){ gl_post('ledger:'.$e['id'],gl_open_date($e['date']),'ledger',(int)$e['id'],$e['name'],gl_rule_ledger($e),$u); $n['ledger']++; }
    foreach(rows("SELECT j.id FROM dd_gl_journals j JOIN dd_ledger l ON j.source_key=CONCAT('ledger:',l.id) WHERE l.voided=1 AND j.status='posted' LIMIT $limit") as $j){ gl_reverse((int)$j['id'],gl_open_date(business_date()),'daybook entry voided',$u); $n['reversed']++; }
    foreach(rows("SELECT b.* FROM dd_bills b LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('bill:',b.id) WHERE j.id IS NULL AND b.voided=0 ORDER BY b.id LIMIT $limit") as $b){ gl_post('bill:'.$b['id'],gl_open_date($b['date']),'bill',(int)$b['id'],'Bill '.$b['reference'],gl_rule_bill($b),$u); $n['bills']++; }
    foreach(rows("SELECT j.id FROM dd_gl_journals j JOIN dd_bills b ON j.source_key=CONCAT('bill:',b.id) WHERE b.voided=1 AND j.status='posted' LIMIT $limit") as $j){ gl_reverse((int)$j['id'],gl_open_date(business_date()),'supplier bill voided',$u); $n['reversed']++; }
    foreach(rows("SELECT c.* FROM dd_supplier_credits c LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('credit:',c.id) WHERE j.id IS NULL AND NOT (c.kind='advance' AND c.bill_id IS NULL) AND c.reason NOT LIKE 'Remainder of #%' ORDER BY c.id LIMIT $limit") as $c){ $r=gl_rule_credit($c); if($r!==null){
        $when=$c['kind']==='advance'?substr((string)($c['applied_at']??$c['created_at']),0,10):substr((string)$c['created_at'],0,10); // advance leaves 1310 on the day it is applied
        gl_post('credit:'.$c['id'],gl_open_date($when),'supplier-credit',(int)$c['id'],'Supplier credit',$r,$u); $n['credits']++; } }
    foreach(rows("SELECT c.* FROM dd_supplier_credits c LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('credit:',c.id) WHERE j.id IS NULL AND c.kind='advance' AND c.bill_id IS NOT NULL AND c.reason LIKE 'Remainder of #%' ORDER BY c.id LIMIT $limit") as $c){ $r=gl_rule_credit($c); if($r!==null){ gl_post('credit:'.$c['id'],gl_open_date(substr((string)($c['applied_at']??$c['created_at']),0,10)),'supplier-credit',(int)$c['id'],'Supplier advance applied',$r,$u); $n['credits']++; } }
    foreach(rows("SELECT m.* FROM dd_stock_moves m LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('move:',m.id) WHERE j.id IS NULL AND m.kind NOT IN ('in','transfer') AND m.cost_value>0 ORDER BY m.id LIMIT $limit") as $m){ gl_post('move:'.$m['id'],gl_open_date($m['date']),'stock',(int)$m['id'],'Stock '.$m['kind'],gl_rule_move($m)??[],$u); $n['stock']++; }
    foreach(rows("SELECT p.* FROM dd_payroll p LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('payroll:',p.id) WHERE j.id IS NULL AND p.approved=1 AND (p.gross<>0 OR p.net<>0 OR p.advance<>0 OR p.deductions<>0) ORDER BY p.id LIMIT $limit") as $p){ // ₹0 payroll (approved with a reason) has nothing to post
        // Fix C16: the accrual is dated at the END OF THE EARNED MONTH, never moved into "today" (future months cannot be approved; see payroll_approve).
        $eom=(new DateTimeImmutable($p['month'].'-01'))->format('Y-m-t');
        gl_post('payroll:'.$p['id'],gl_open_date($eom),'payroll',(int)$p['id'],'Payroll accrual',gl_rule_payroll($p),$u); $n['payroll']++; }
    $n['tax']=gl_sync_sales_docs($u,$limit);
    if(!function_exists('fin_sync')) require_once __DIR__.'/post360.php';
    if(function_exists('fin_sync')){ $f=fin_sync($u); $n['events']=$f['registered']; $n['reversed']+=$f['reversed']; }
    if(array_sum($n)) audit($u,'gl.sync',$n); return $n;
}
/** Fix C03: drain every source in bounded batches until nothing is left (or report what is left). */
function gl_sync_all(?array $u,int $batch=5000,int $maxRounds=200): array {
    $tot=[]; for($i=0;$i<$maxRounds;$i++){ $n=gl_sync($u,$batch); foreach($n as $k=>$v) $tot[$k]=($tot[$k]??0)+$v; if(!array_sum($n)) break; }
    return $tot+['rounds'=>$i+1];
}
/**
 * Fix C02: sales documents that carry a tax / rounding / unpaid split. Tender rows (dd_ledger 'sale') post Dr tender / Cr 4000 as before;
 * this adds ONE reclassification per document: Dr 4000 tax → Cr 2300 output tax, rounding → 4950, unpaid → Dr 1200 receivable / Cr 4000.
 * Superseded documents are reversed (never edited). Legacy Petpooja day rows split only from the owner-confirmed date 'tax_split_pos_from'.
 */
function gl_sync_sales_docs(?array $u,int $limit=5000): int { $n=0;
    try{ $cc=rows("SELECT c.* FROM dd_counter_closes c LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('cc:',c.id) WHERE j.id IS NULL AND c.status='posted' ORDER BY c.id LIMIT $limit"); }catch(PDOException $e){ if(($e->errorInfo[1]??0)===1146) return 0; throw $e; }
    foreach($cc as $c){ $l=gl_rule_sales_doc((int)$c['tax'],(int)$c['rounding'],(int)$c['unpaid'],'Counter '.$c['counter'].' '.$c['business_date'].' r'.$c['revision']);
        if($l&&gl_post('cc:'.$c['id'],gl_open_date($c['business_date']),'sales-doc',(int)$c['id'],'Sales split '.$c['counter'].' '.$c['business_date'],$l,$u)) $n++; }
    foreach(rows("SELECT j.id FROM dd_gl_journals j JOIN dd_counter_closes c ON j.source_key=CONCAT('cc:',c.id) WHERE c.status<>'posted' AND j.status='posted' LIMIT $limit") as $j){ gl_reverse((int)$j['id'],gl_open_date(business_date()),'counter close superseded / voided',$u); $n++; }
    $from=setting('tax_split_pos_from','');
    if($from!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){
        foreach(rows("SELECT p.* FROM dd_pos_days p LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('taxsplit:pos:',p.business_date,':r',p.revision) WHERE j.id IS NULL AND p.business_date>=? AND p.tax>0 AND p.status<>'conflict' ORDER BY p.business_date LIMIT $limit",$from) as $p){
            $l=gl_rule_sales_doc((int)$p['tax'],0,0,'Petpooja '.$p['business_date'].' r'.$p['revision']);
            if($l&&gl_post('taxsplit:pos:'.$p['business_date'].':r'.$p['revision'],gl_open_date($p['business_date']),'sales-doc',null,'Output tax split (Petpooja '.$p['business_date'].')',$l,$u)) $n++; }
        foreach(rows("SELECT j.id FROM dd_gl_journals j JOIN dd_pos_days p ON j.source_key LIKE CONCAT('taxsplit:pos:',p.business_date,':r%') AND j.source_key<>CONCAT('taxsplit:pos:',p.business_date,':r',p.revision) WHERE j.status='posted' LIMIT $limit") as $j){ gl_reverse((int)$j['id'],gl_open_date(business_date()),'Petpooja day revised',$u); $n++; }
    }
    return $n;
}
function gl_rule_sales_doc(int $tax,int $rounding,int $unpaid,string $memo): array { $s=gl_acct('sales'); $l=[];
    if($tax>0){ $l[]=[$s,$tax,0,'Output tax inside collections · '.$memo]; $l[]=[gl_acct('tax_pay'),0,$tax,'Output tax · '.$memo]; }
    if($rounding>0){ $l[]=[$s,$rounding,0,'Rounding inside collections · '.$memo]; $l[]=[gl_acct('rounding'),0,$rounding,'Rounding · '.$memo]; }
    if($rounding<0){ $l[]=[gl_acct('rounding'),-$rounding,0,'Rounding · '.$memo]; $l[]=[$s,0,-$rounding,'Rounding · '.$memo]; }
    if($unpaid>0){ $l[]=[gl_acct('ar'),$unpaid,0,'Unpaid bills (credit sales) · '.$memo]; $l[]=[$s,0,$unpaid,'Credit sales · '.$memo]; }
    return $l; }

/* ---------- Manual journals (opening balances, accruals, depreciation, corrections) ---------- */
function gl_manual(array $b,array $u): array {
    if(!can($u,'*')&&($u['role']??'')!=='accountant') fail('Only the owner or accountant can post manual journals.',403); // was: close+payroll, which also admitted managers
    $ck=client_key($b); if(!$ck) fail('Submission key missing.'); if($x=row('SELECT id,journal_no FROM dd_gl_journals WHERE source_key=?','manual:'.$ck)) return ['ok'=>true,'duplicate'=>true,'id'=>(int)$x['id'],'journal_no'=>$x['journal_no']];
    $d=valid_date($b['date']??business_date()); month_lock_check($d); $memo=txt($b['memo']??''); if(mb_strlen($memo)<5) fail('Write a clear narration (why this journal is needed).');
    $lines=[]; foreach(($b['lines']??[]) as $i=>$l){ $acc=row('SELECT id,active FROM dd_gl_accounts WHERE code=?',txt($l['account']??'',20)); if(!$acc||!$acc['active']) fail('Line '.($i+1).': unknown account code.');
        $dr=($l['debit']??'')===''?0:money($l['debit']); $cr=($l['credit']??'')===''?0:money($l['credit']); if($dr&&$cr) fail('Line '.($i+1).': use either debit or credit.'); if(!$dr&&!$cr) continue; $lines[]=[(int)$acc['id'],$dr,$cr,txt($l['memo']??'',160)]; }
    if(count($lines)<2) fail('A journal needs at least two lines.');
    $id=gl_post('manual:'.$ck,$d,'manual',null,$memo,$lines,$u); audit($u,'gl.manual',['id'=>$id,'memo'=>$memo,'lines'=>$lines]);
    return ['ok'=>true,'id'=>$id,'journal_no'=>row('SELECT journal_no FROM dd_gl_journals WHERE id=?',$id)['journal_no']];
}
function gl_manual_reverse(array $b,array $u): array {
    if(!can($u,'*')) fail('Only the owner can reverse journals.',403); $j=row('SELECT * FROM dd_gl_journals WHERE id=?',(int)($b['id']??0)); if(!$j) fail('Journal not found.');
    if($j['source_type']!=='manual') fail('System journals follow their source document. Correct the source (void the entry / reverse the voucher) instead.');
    $why=txt($b['reason']??''); if(mb_strlen($why)<5) fail('Write the reason for the reversal.'); $r=gl_reverse((int)$j['id'],gl_open_date(business_date()),$why,$u); audit($u,'gl.reverse',['id'=>$j['id'],'reversal'=>$r,'reason'=>$why]); return ['ok'=>true,'id'=>$r];
}

/* ---------- Reports ---------- */
function gl_balances(?string $from,string $to,?string $types=null): array {
    $w=['j.business_date<=?']; $a=[$to]; if($from){ $w[]='j.business_date>=?'; $a[]=$from; }
    $out=[]; foreach(rows('SELECT a.id,a.code,a.name,a.name_hi,a.type,a.normal,COALESCE(SUM(l.debit),0) dr,COALESCE(SUM(l.credit),0) cr FROM dd_gl_accounts a LEFT JOIN dd_gl_lines l ON l.account_id=a.id LEFT JOIN dd_gl_journals j ON j.id=l.journal_id AND '.implode(' AND ',$w).' WHERE (l.id IS NULL OR j.id IS NOT NULL) GROUP BY a.id,a.code,a.name,a.name_hi,a.type,a.normal ORDER BY a.code',...$a) as $r){
        $dr=(int)$r['dr']; $cr=(int)$r['cr']; if(!$dr&&!$cr) continue; $out[]=['code'=>$r['code'],'name'=>$r['name'],'name_hi'=>$r['name_hi'],'type'=>$r['type'],'debit'=>$dr,'credit'=>$cr,'balance'=>$r['normal']==='D'?$dr-$cr:$cr-$dr,'net_debit'=>$dr-$cr]; }
    return $out;
}
function gl_trial_balance(string $to): array { $b=gl_balances(null,$to); $d=0;$c=0; foreach($b as &$r){ $r['tb_debit']=max(0,$r['net_debit']); $r['tb_credit']=max(0,-$r['net_debit']); $d+=$r['tb_debit']; $c+=$r['tb_credit']; } unset($r);
    $bad=rows('SELECT j.journal_no,j.total,SUM(l.debit) d,SUM(l.credit) c FROM dd_gl_journals j JOIN dd_gl_lines l ON l.journal_id=j.id GROUP BY j.id,j.journal_no,j.total HAVING d<>c OR d<>j.total LIMIT 20');
    return ['as_of'=>$to,'rows'=>$b,'total_debit'=>$d,'total_credit'=>$c,'balanced'=>$d===$c&&!$bad,'unbalanced_journals'=>$bad]; }
function gl_statements(string $from,string $to): array {
    $pl=gl_balances($from,$to); $rev=0;$exp=0; $plRows=array_values(array_filter($pl,fn($r)=>in_array($r['type'],['revenue','expense'],true)));
    foreach($plRows as $r){ if($r['type']==='revenue') $rev+=$r['credit']-$r['debit']; else $exp+=$r['debit']-$r['credit']; }
    $bs=gl_balances(null,$to); $assets=0;$liab=0;$eq=0;$retained=0;
    foreach($bs as $r){ if($r['type']==='asset') $assets+=$r['net_debit']; elseif($r['type']==='liability') $liab-=$r['net_debit']; elseif($r['type']==='equity') $eq-=$r['net_debit']; else $retained-=$r['net_debit']; }
    $cashKeys=array_map(fn($k)=>GL_COA[$k][0],['cash','bank1','bank2','upi','card','transit']); $cash=fn(string $d)=>array_sum(array_map(fn($r)=>in_array($r['code'],$cashKeys,true)?$r['net_debit']:0,gl_balances(null,$d)));
    $open=$cash(next_date($from,-1)); $close=$cash($to);
    return ['from'=>$from,'to'=>$to,'pnl'=>['rows'=>$plRows,'revenue'=>$rev,'expenses'=>$exp,'profit'=>$rev-$exp],
      'balance_sheet'=>['rows'=>array_values(array_filter($bs,fn($r)=>in_array($r['type'],['asset','liability','equity'],true))),'assets'=>$assets,'liabilities'=>$liab,'equity'=>$eq,'retained_profit'=>$retained,'balanced'=>$assets===$liab+$eq+$retained],
      'cash_flow'=>['method'=>'Direct: change in cash, bank and clearing accounts','opening'=>$open,'closing'=>$close,'net_change'=>$close-$open]];
}
/**
 * Golden reconciliations: GL control accounts vs operational subledgers, BOTH as of the same cutoff date (fix C07).
 * Subledgers are rebuilt from dated events (bills, dated payments, credits when recorded, advances when applied, valued stock moves,
 * payroll accrued at month end). Differences are shown, never forced to zero; a balanced trial balance alone proves nothing more.
 */
function gl_reconcile(string $to): array {
    $bal=[]; foreach(gl_balances(null,$to) as $r) $bal[$r['code']]=$r['balance'];
    $N=fn(string $sql,...$a)=>(int)(row($sql,...$a)['n']??0);
    $end=$to.' 23:59:59';
    // A voided source counts until the day its reversal journal is dated (that is how the GL sees it).
    $live=fn(string $alias,string $kind)=>"($alias.voided=0 OR EXISTS(SELECT 1 FROM dd_gl_journals rj WHERE rj.source_key=CONCAT('rev:$kind:',$alias.id) AND rj.business_date>?))";
    // Trade payables (2000)
    $bills=$N("SELECT COALESCE(SUM(b.amount),0) n FROM dd_bills b WHERE b.date<=? AND ".$live('b','bill'),$to,$to);
    $paid=$N("SELECT COALESCE(SUM(p.amount),0) n FROM dd_bill_payments p JOIN dd_ledger l ON l.id=p.ledger_id WHERE l.date<=? AND ".$live('l','ledger'),$to,$to);
    $credits=$N("SELECT COALESCE(SUM(amount),0) n FROM dd_supplier_credits WHERE kind<>'advance' AND created_at<=?",$end); // credit notes/returns incl. every remainder row (fix C08)
    $advApplied=$N("SELECT COALESCE(SUM(amount),0) n FROM dd_supplier_credits WHERE kind='advance' AND bill_id IS NOT NULL AND COALESCE(applied_at,created_at)<=?",$end);
    $legacyAP=$N("SELECT COALESCE(SUM(l.amount),0) n FROM dd_ledger l WHERE l.type='payment-out' AND l.category='Supplier payment' AND l.date<=? AND ".$live('l','ledger')." AND NOT EXISTS(SELECT 1 FROM dd_bill_payments p WHERE p.ledger_id=l.id) AND NOT EXISTS(SELECT 1 FROM dd_supplier_credits c WHERE c.ledger_id=l.id)",$to,$to);
    $apSub=$bills-$paid-$credits-$advApplied;
    // Supplier advances (1310)
    $advPaid=$N("SELECT COALESCE(SUM(amount),0) n FROM dd_supplier_credits WHERE kind='advance' AND created_at<=?",$end);
    $advSub=$advPaid-$advApplied;
    // Inventory (1400): valued movements dated on/before the cutoff
    $invSub=$N("SELECT COALESCE(SUM(CASE WHEN kind IN ('in','opening') THEN cost_value WHEN kind IN ('consume','wastage','expired','damaged','return') THEN -cost_value WHEN kind IN ('adjust','legacy') THEN IF(quantity>=0,cost_value,-cost_value) ELSE 0 END),0) n FROM dd_stock_moves WHERE date<=? AND cost_value>0 AND kind<>'transfer'",$to);
    $invNow=$N('SELECT COALESCE(SUM(ROUND(quantity*cost)),0) n FROM dd_inventory');
    // Payroll accrued at month end (only months whose end is on/before the cutoff)
    $eomOk="LAST_DAY(STR_TO_DATE(CONCAT(month,'-01'),'%Y-%m-%d'))<=?";
    $empAdv=$N("SELECT COALESCE(SUM(l.amount),0) n FROM dd_ledger l WHERE l.type='advance' AND l.date<=? AND ".$live('l','ledger'),$to,$to)-$N("SELECT COALESCE(SUM(advance),0) n FROM dd_payroll WHERE approved=1 AND $eomOk",$to);
    $salPay=$N("SELECT COALESCE(SUM(net),0) n FROM dd_payroll WHERE approved=1 AND $eomOk",$to)-$N("SELECT COALESCE(SUM(l.amount),0) n FROM dd_ledger l WHERE l.type='salary' AND l.date<=? AND ".$live('l','ledger'),$to,$to);
    // Unified Books / Salary Register events posted to the GL (journal lines are the posted values of approved source documents)
    $evt=function(string $code) use ($to): int { try{ return (int)(row("SELECT COALESCE(SUM(gl.debit-gl.credit),0) n FROM dd_fin_events e JOIN dd_gl_journals j ON j.id=e.journal_id JOIN dd_gl_lines gl ON gl.journal_id=j.id JOIN dd_gl_accounts a ON a.id=gl.account_id WHERE a.code=? AND j.business_date<=? AND e.status IN ('posted','reversed','superseded')",$code,$to)['n']??0)
        +(int)(row("SELECT COALESCE(SUM(gl.debit-gl.credit),0) n FROM dd_fin_events e JOIN dd_gl_journals j ON j.reverses_id=e.journal_id JOIN dd_gl_lines gl ON gl.journal_id=j.id JOIN dd_gl_accounts a ON a.id=gl.account_id WHERE a.code=? AND j.business_date<=?",$code,$to)['n']??0); }catch(PDOException $e){ return 0; } };
    $empAdv+=$evt('1300'); $salPay-=$evt('2100');
    // Cash drawer (1000) = CRM daybook cash movements (+ unified Books/Salary cash events)
    $cashBook=0; foreach(rows("SELECT * FROM dd_ledger l WHERE l.date<=? AND (l.account='Cash' OR l.to_account='Cash') AND ".$live('l','ledger'),$to,$to) as $e){ if($e['to_account']==='Cash'&&in_array($e['type'],['deposit','withdrawal','settlement'],true)) $cashBook+=(int)$e['amount']; elseif($e['account']==='Cash'){ $m=movement($e); $cashBook+=$m['in']-$m['out']; if(in_array($e['type'],['deposit','withdrawal','settlement'],true)) $cashBook-=(int)$e['amount']; } }
    $cashBook+=$evt('1000');
    $mk=fn($name,$gl,$sub,$note)=>['check'=>$name,'gl'=>$gl,'subledger'=>$sub,'difference'=>$gl-$sub,'ok'=>$gl===$sub,'note'=>$note];
    $out=[
      $mk('Trade payables (2000) = bills to date − payments to date − credit notes recorded − advances applied',$bal['2000']??0,$apSub,'As of '.$to.'. Opening payables must be OPENING bills.'.($legacyAP?' Includes ₹'.rupees($legacyAP).' of legacy "Supplier payment" entries with no bill allocation (posted to 2000 in the GL, not in the bill subledger) — allocate them to bills.':'').' Credits applied before this version have no recorded application date; their recording date is used.'),
      $mk('Supplier advances (1310) = advances paid − advances applied, to date',$bal['1310']??0,$advSub,''),
      $mk('Employee advances (1300) = advances paid − recoveries in payroll accrued to date',$bal['1300']??0,$empAdv,'Payroll recoveries count from the end of the earned month.'),
      $mk('Salary payable (2100) = payroll accrued to date − salary paid to date',$bal['2100']??0,$salPay,'Earned month and payment date are kept separate: a salary paid early next month is payable at month end.'),
      $mk('Cash drawer (1000) = daybook cash movements to date',$bal['1000']??0,$cashBook,'Daybook opening cash that pre-dates the GL needs an opening journal (Dr 1000 / Cr 3900).'),
      $mk('Inventory (1400) = valued stock movements to date',$bal['1400']??0,$invSub,'Information only: on-hand × CURRENT average cost today is ₹'.rupees($invNow).' (an estimate, not a control).'),
    ];
    $noTax=0; try{ $noTax=$N("SELECT COUNT(*) n FROM dd_ledger l WHERE l.type='sale' AND l.voided=0 AND l.date<=? AND l.source NOT IN ('counter-close') AND NOT EXISTS(SELECT 1 FROM dd_pos_days p WHERE p.business_date=l.date AND p.business_date>=? AND p.tax>0)",$to,setting('tax_split_pos_from','9999-12-31')?:'9999-12-31'); }catch(PDOException $e){}
    if($noTax) $out[]=['check'=>'Sales rows without a documented tax basis','gl'=>$noTax,'subledger'=>0,'difference'=>$noTax,'ok'=>false,'note'=>$noTax.' sale row(s) were credited fully to 4000 because no tax/net split is recorded for them. Enter a counter close for new days; for Petpooja days confirm tax-inclusive collections and set tax_split_pos_from.'];
    return $out;
}
function gl_ledger_view(string $code,string $from,string $to): array {
    $a=row('SELECT * FROM dd_gl_accounts WHERE code=?',$code); if(!$a) fail('Account not found.',404);
    $open=(int)(row('SELECT COALESCE(SUM(l.debit-l.credit),0) n FROM dd_gl_lines l JOIN dd_gl_journals j ON j.id=l.journal_id WHERE l.account_id=? AND j.business_date<?',$a['id'],$from)['n']);
    $rows=rows('SELECT j.id jid,j.journal_no,j.business_date,j.source_type,j.source_id,j.memo jmemo,j.status,l.debit,l.credit,l.memo FROM dd_gl_lines l JOIN dd_gl_journals j ON j.id=l.journal_id WHERE l.account_id=? AND j.business_date BETWEEN ? AND ? ORDER BY j.business_date,j.id LIMIT 2000',$a['id'],$from,$to);
    $run=$open; foreach($rows as &$r){ $run+=(int)$r['debit']-(int)$r['credit']; $r['running']=$a['normal']==='D'?$run:-$run; } unset($r);
    return ['account'=>$a,'from'=>$from,'to'=>$to,'opening'=>$a['normal']==='D'?$open:-$open,'rows'=>$rows];
}
function gl_journal_view(int $id): array { $j=row('SELECT j.*,u.name by_name FROM dd_gl_journals j LEFT JOIN dd_users u ON u.id=j.created_by WHERE j.id=?',$id); if(!$j) fail('Journal not found.',404);
    return ['journal'=>$j,'lines'=>rows('SELECT l.*,a.code,a.name FROM dd_gl_lines l JOIN dd_gl_accounts a ON a.id=l.account_id WHERE l.journal_id=? ORDER BY l.id',$id)]; }
function gl_csv(array $tb): string { $o=[['Code','Account','Type','Debit','Credit']]; foreach($tb['rows'] as $r) $o[]=[$r['code'],$r['name'],$r['type'],rupees($r['tb_debit']),rupees($r['tb_credit'])]; $o[]=['','TOTAL','',rupees($tb['total_debit']),rupees($tb['total_credit'])];
    $s="\xEF\xBB\xBF"; foreach($o as $r) $s.=implode(',',array_map('csv_cell',$r))."\r\n"; return $s; }

function gl_get(string $route,array $u): ?array {
    if(!str_starts_with($route,'crm-gl')) return null;
    if(!can($u,'reports')&&!can($u,'*')) fail('Accounts are restricted to owner / accountant / manager.',403);
    $to=valid_date($_GET['to']??business_date()); $from=valid_date($_GET['from']??substr($to,0,8).'01');
    switch($route){
    case 'crm-gl': $tb=gl_trial_balance($to);
        if(($_GET['format']??'')==='csv'){ audit($u,'export.tb',['to'=>$to]); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="trial-balance-'.$to.'.csv"'); echo gl_csv($tb); exit; }
        return ['tb'=>$tb,'statements'=>gl_statements($from,$to),'reconcile'=>gl_reconcile($to),'pending'=>gl_pending(),'accounts'=>rows('SELECT code,name,name_hi,type,normal,system_key,active FROM dd_gl_accounts ORDER BY code'),
          'journals'=>rows("SELECT id,journal_no,business_date,source_type,memo,status,total FROM dd_gl_journals WHERE business_date BETWEEN ? AND ? ORDER BY id DESC LIMIT 200",$from,$to),'basis'=>'Shadow-mode double-entry GL generated from source documents. Starter chart of accounts — confirm mappings with your CA before filing.'];
    case 'crm-gl-ledger': return gl_ledger_view(txt($_GET['code']??'',20),$from,$to);
    case 'crm-gl-journal': return gl_journal_view((int)($_GET['id']??0));
    }
    return null;
}
/** Fix C03: every class of unposted / unreversed source (optionally up to a date). Hard close requires all of these to be zero. */
function gl_pending(?string $upto=null): array {
    $d=$upto?:'9999-12-31'; $N=fn(string $sql,...$a)=>(int)(row($sql,...$a)['n']??0);
    $o=['ledger'=>$N("SELECT COUNT(*) n FROM dd_ledger l LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('ledger:',l.id) WHERE j.id IS NULL AND l.voided=0 AND l.date<=?",$d),
      'ledger_reversals'=>$N("SELECT COUNT(*) n FROM dd_gl_journals j JOIN dd_ledger l ON j.source_key=CONCAT('ledger:',l.id) WHERE l.voided=1 AND j.status='posted'"),
      'bills'=>$N("SELECT COUNT(*) n FROM dd_bills b LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('bill:',b.id) WHERE j.id IS NULL AND b.voided=0 AND b.date<=?",$d),
      'bill_reversals'=>$N("SELECT COUNT(*) n FROM dd_gl_journals j JOIN dd_bills b ON j.source_key=CONCAT('bill:',b.id) WHERE b.voided=1 AND j.status='posted'"),
      'credits'=>$N("SELECT COUNT(*) n FROM dd_supplier_credits c LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('credit:',c.id) WHERE j.id IS NULL AND ((c.kind<>'advance' AND c.reason NOT LIKE 'Remainder of #%') OR (c.kind='advance' AND c.bill_id IS NOT NULL)) AND c.created_at<=?",$d.' 23:59:59'),
      'stock'=>$N("SELECT COUNT(*) n FROM dd_stock_moves m LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('move:',m.id) WHERE j.id IS NULL AND m.kind NOT IN ('in','transfer') AND m.cost_value>0 AND m.date<=?",$d),
      'payroll'=>$N("SELECT COUNT(*) n FROM dd_payroll p LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('payroll:',p.id) WHERE j.id IS NULL AND p.approved=1 AND (p.gross<>0 OR p.net<>0 OR p.advance<>0 OR p.deductions<>0) AND p.month<=?",substr($d,0,7)),
      'stock_unvalued'=>$N("SELECT COUNT(*) n FROM dd_stock_moves WHERE kind NOT IN ('in','transfer') AND (cost_value IS NULL OR cost_value=0) AND date<=?",$d)];
    try{ $o['sales_docs']=$N("SELECT COUNT(*) n FROM dd_counter_closes c LEFT JOIN dd_gl_journals j ON j.source_key=CONCAT('cc:',c.id) WHERE j.id IS NULL AND c.status='posted' AND (c.tax>0 OR c.rounding<>0 OR c.unpaid>0) AND c.business_date<=?",$d);
         $o['events_pending']=$N("SELECT COUNT(*) n FROM dd_fin_events WHERE status='pending' AND (business_date IS NULL OR business_date<=?)",$d); }catch(PDOException $e){ if(($e->errorInfo[1]??0)!==1146) throw $e; }
    return $o;
}
/** Pending items that block a hard close (stock_unvalued is a data-quality flag handled by the close report, not a posting gap). */
function gl_blocking(array $p): array { return array_filter($p,fn($v,$k)=>$v>0&&$k!=='stock_unvalued',ARRAY_FILTER_USE_BOTH); }
function gl_mutate(string $route,array $b,array $u): ?array {
    switch($route){
    case 'crm-gl-sync': if(!can($u,'reports')&&!can($u,'*')) fail('Not allowed.',403); return ['ok'=>true]+gl_sync_all($u);
    case 'crm-gl-manual': return gl_manual($b,$u);
    case 'crm-gl-reverse': return gl_manual_reverse($b,$u);
    case 'crm-gl-map': allowed($u,'manage'); $cat=name_required($b['category']??''); $code=txt($b['account']??'',20); if(!row("SELECT id FROM dd_gl_accounts WHERE code=? AND type IN ('expense','equity','asset')",$code)) fail('Choose an expense / asset / equity account.');
        run('INSERT INTO dd_gl_category_map(category,account_code,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE account_code=VALUES(account_code),updated_by=VALUES(updated_by)',$cat,$code,$u['id']); audit($u,'gl.map',['category'=>$cat,'account'=>$code]); return ['ok'=>true,'note'=>'Applies to entries posted from now on; posted journals are not rewritten.'];
    case 'crm-gl-account': allowed($u,'manage'); $code=txt($b['code']??'',20); if(!preg_match('/^\d{4,6}$/',$code)) fail('Account code must be 4–6 digits.'); $type=in_array($b['type']??'',['asset','liability','equity','revenue','expense'],true)?$b['type']:fail('Choose the account type.');
        $ex=row('SELECT * FROM dd_gl_accounts WHERE code=?',$code); $normal=in_array($type,['asset','expense'],true)?'D':'C';
        if($ex){ run('UPDATE dd_gl_accounts SET name=?,name_hi=?,active=? WHERE id=?',name_required($b['name']??''),txt($b['name_hi']??'',120)?:null,$ex['system_key']?1:(!empty($b['active'])?1:0),$ex['id']); }
        else run('INSERT INTO dd_gl_accounts(code,name,name_hi,type,normal,active) VALUES(?,?,?,?,?,1)',$code,name_required($b['name']??''),txt($b['name_hi']??'',120)?:null,$type,$normal);
        audit($u,'gl.account',['code'=>$code]); return ['ok'=>true];
    }
    return null;
}
