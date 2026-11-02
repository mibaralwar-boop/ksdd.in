<?php
declare(strict_types=1);
// KSDD — unified financial posting (audit fix C01), manual counter close with tender reconciliation (C02, T21),
// UPI/card settlements with fees, tender/clearing reconciliation, and drawer openings/transfers (C14).
//
// Posting authority: the double-entry GL (dd_gl_*) is the ONE accounting record. CRM daybook rows keep posting automatically (as before).
// Books (dd_bk_txns) and Salary Register (dd_sal_entries / locked dd_sal_months) are registered as financial EVENTS from the owner-chosen
// date 'unify_from'. Each event is posted ONCE, or linked to the CRM record it duplicates, or excluded with a reason — never summed blindly.
// An edited source creates a new revision (old journal reversed, never edited); a voided source reverses its journal.
// Money = integer paise. Nothing here runs unless called by an authorised route; unify_from='' (default) leaves Books/Salary unposted.
require_once __DIR__.'/lib360.php';
require_once __DIR__.'/gl360.php';

const FIN_DUP_DAYS = 3;
const GL_ASSET_CODES = ['1000','1005','1100','1101','1110','1120','1150','3000'];

function fin_tables(): bool { static $ok; return $ok??=(bool)row("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dd_fin_events'"); }
function fin_has(string $t): bool { static $c=[]; return $c[$t]??=(bool)row('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',$t); }
function fin_unify_from(): ?string { $d=setting('unify_from',''); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)?$d:null; }
function fin_map(): array { $m=json_decode(setting('post_account_map','{}'),true); return is_array($m)?$m:[]; }
/** GL account id for a Books account / Salary payment mode. Unknown → Suspense (1199), shown in the posting preview. */
function fin_money_acct(string $system,?string $name): int { $code=fin_map()[$system.':'.(string)$name]??null; if($code){ $r=row('SELECT id FROM dd_gl_accounts WHERE code=? AND active=1',$code); if($r) return (int)$r['id']; } return gl_acct('suspense'); }
function fin_code(int $acctId): string { static $c=[]; return $c[$acctId]??=(string)(row('SELECT code FROM dd_gl_accounts WHERE id=?',$acctId)['code']??''); }
const BK_GROUP_GL = ['Staff'=>'salary','Food & kitchen'=>'direct_food','Utilities'=>'utilities','Rent & royalty'=>'rent','Repairs & maintenance'=>'repairs','Transport & fuel'=>'transport','Marketing & guests'=>'incentive','Office & misc'=>'other_exp','Old dues (not expense)'=>'bk_ap','Transfer (not expense)'=>'transit','Staff advance (not expense)'=>'emp_adv'];

/* ============================== event payloads (what is posted) ============================== */
function fin_books_payload(array $t): array {
    $tax=(int)(row('SELECT COALESCE(SUM(tax),0) n FROM dd_bk_lines WHERE txn_id=?',$t['id'])['n']??0);
    $p=row('SELECT name,kind FROM dd_bk_parties WHERE id=?',$t['party_id']);
    // Only money-relevant fields: renaming a party or editing a description does not create a new revision.
    return ['type'=>$t['type'],'date'=>$t['date'],'total'=>(int)$t['total'],'paid'=>(int)$t['paid'],'account'=>$t['account'],'to_account'=>$t['to_account'],'category'=>$t['category'],'tax'=>$tax,
        'party_id'=>$t['party_id']===null?null:(int)$t['party_id'],'party_kind'=>$p['kind']??null,'party'=>null];
}
function fin_salentry_payload(array $e): array { $n=row('SELECT name FROM dd_employees WHERE id=?',$e['employee_id']);
    return ['kind'=>$e['kind'],'date'=>$e['date'],'month'=>$e['month'],'amount'=>(int)$e['amount'],'mode'=>$e['mode']?:'Cash','employee_id'=>(int)$e['employee_id'],'employee'=>$n['name']??null,'note'=>$e['note']]; }
function fin_salmonth_payload(string $m): array { require_once __DIR__.'/salary360.php'; $v=sal_month_view($m); $rows=[];
    foreach($v['rows'] as $r) if($r['earned']||$r['bonus']||$r['advance']||$r['fine']) $rows[]=['employee_id'=>$r['employee_id'],'name'=>$r['name'],'earned'=>$r['earned'],'bonus'=>$r['bonus'],'advance'=>$r['advance'],'fine'=>$r['fine']];
    return ['month'=>$m,'date'=>(new DateTimeImmutable($m.'-01'))->format('Y-m-t'),'rows'=>$rows]; }
function fin_event_amount(string $sys,string $table,array $p): array { // [kind, date, amount, account, party, employee]
    if($table==='dd_bk_txns'){ $n=$p['party_id']?row('SELECT name FROM dd_bk_parties WHERE id=?',$p['party_id']):null; return [$p['type'],$p['date'],$p['paid']?:$p['total'],$p['account'],$n['name']??null,null]; }
    if($table==='dd_sal_entries') return ['salary-'.$p['kind'],$p['date'],$p['amount'],$p['mode'],$p['employee'],$p['employee_id']];
    $t=0; foreach($p['rows'] as $r) $t+=$r['earned']+$r['bonus']; return ['salary-accrual',$p['date'],$t,null,null,null];
}

/* ============================== posting rules ============================== */
function fin_rule(array $ev): array {
    $p=json_decode($ev['payload'],true); $memo=txt($ev['memo']??'',120);
    if($ev['source_table']==='dd_bk_txns'){
        $acct=fin_money_acct('books',$p['account']); $tot=$p['total']; $paid=$p['paid']; $due=$tot-$paid; $pt='bk_party'; $pid=$p['party_id'];
        $ap=($p['party_kind']==='staff')?gl_acct('sal_pay'):gl_acct('bk_ap');
        switch($p['type']){
        case 'sale': $tax=min($p['tax'],$tot); return [[$acct,$paid,0,$memo],[gl_acct('ar'),$due,0,'Credit sale',$pt,$pid],[gl_acct('sales'),0,$tot-$tax,$memo],[gl_acct('tax_pay'),0,$tax,'Output tax (Books line GST)']];
        case 'cash_access': return [[$acct,$paid,0,$memo],[gl_acct('suspense'),0,$paid,'Cash Acess — classify (not a sale)']];
        case 'payment_in': return [[$acct,$paid,0,$memo],[in_array($p['party_kind'],['supplier','both'],true)?gl_acct('bk_ap'):gl_acct('ar'),0,$paid,$memo.(in_array($p['party_kind'],['supplier','both'],true)?' (refund from supplier)':''),$pt,$pid]];
        case 'other_income': return [[$acct,$paid,0,$memo],[gl_acct('other_inc'),0,$paid,$memo]];
        case 'owner_in': return [[$acct,$paid,0,$memo],[gl_acct('capital'),0,$paid,$memo]];
        case 'add_cash': return [[$acct,$paid,0,$memo],[gl_acct('suspense'),0,$paid,'Cash adjustment + — needs evidence; not revenue']];
        case 'purchase_return': return [[$acct,$paid,0,$memo.' (refund)'],[gl_acct('bk_ap'),$due,0,$memo,$pt,$pid],[gl_acct('purch_credit'),0,$tot,$memo.' (purchase return / debit note)']];
        case 'purchase': return [[gl_acct('purch_misc'),$tot,0,$memo.' (Books purchase; periodic)'],[$acct,0,$paid,$memo],[gl_acct('bk_ap'),0,$due,$memo,$pt,$pid]];
        case 'expense': require_once __DIR__.'/books360.php'; $dr=gl_acct(BK_GROUP_GL[bk_group($p['category'])]??'suspense'); return [[$dr,$tot,0,$memo.' · '.($p['category']??'')],[$acct,0,$paid,$memo],[gl_acct('bk_ap'),0,$due,$memo,$pt,$pid]];
        case 'payment_out': return [[$ap,$paid,0,$memo,$pt,$pid],[$acct,0,$paid,$memo]];
        case 'owner_draw': return [[gl_acct('drawings'),$paid,0,$memo],[$acct,0,$paid,$memo]];
        case 'reduce_cash': return [[gl_acct('suspense'),$paid,0,'Cash adjustment − — needs evidence'],[$acct,0,$paid,$memo]];
        case 'transfer': return [[fin_money_acct('books',$p['to_account']),$paid,0,$memo],[$acct,0,$paid,$memo]];
        }
        fail('No posting rule for Books type '.$p['type'].'.');
    }
    if($ev['source_table']==='dd_sal_entries'){ $acct=fin_money_acct('salary',$p['mode']); $e=$p['employee_id'];
        return $p['kind']==='advance'?[[gl_acct('emp_adv'),$p['amount'],0,$memo,'employee',$e],[$acct,0,$p['amount'],$memo]]:[[gl_acct('sal_pay'),$p['amount'],0,$memo,'employee',$e],[$acct,0,$p['amount'],$memo]]; }
    // Salary Register month accrual (posted when the sheet is locked): expense = earned + bonus; advances and fines are typed credits.
    $l=[]; foreach($p['rows'] as $r){ $e=(int)$r['employee_id']; if(fin_payroll_crm_approved($e,$p['month'])) continue;
        $g=$r['earned']+$r['bonus']; $net=$g-$r['advance']-$r['fine'];
        $l[]=[gl_acct('salary'),$g,0,'Earned '.$p['month'].' · '.$r['name'],'employee',$e];
        $l[]=[gl_acct('emp_adv'),0,$r['advance'],'Advance recovered','employee',$e]; $l[]=[gl_acct('penalty_inc'),0,$r['fine'],'Fine / deduction','employee',$e];
        $l[]=$net>=0?[gl_acct('sal_pay'),0,$net,'Net payable','employee',$e]:[gl_acct('sal_pay'),-$net,0,'Advances exceed earnings (staff owes)','employee',$e]; }
    return $l;
}
function fin_payroll_crm_approved(int $emp,string $m): bool { return (bool)row('SELECT 1 FROM dd_payroll WHERE employee_id=? AND month=? AND approved=1',$emp,$m); }
/** Guard used by CRM payroll approval: an employee-month accrued from the Salary Register cannot be accrued again from CRM payroll. */
function fin_payroll_conflict(int $emp,string $m): ?string { if(!fin_tables()) return null;
    $ev=row("SELECT payload FROM dd_fin_events WHERE source_system='salary' AND source_table='dd_sal_months' AND source_id=? AND status='posted' ORDER BY source_rev DESC LIMIT 1",(int)str_replace('-','',$m));
    if(!$ev) return null; foreach(json_decode($ev['payload'],true)['rows'] as $r) if((int)$r['employee_id']===$emp) return 'Salary for '.$m.' was already accrued from the Salary Register (posted). Approving CRM payroll too would count the salary twice.';
    return null; }

/* ============================== registration & revisions ============================== */
function fin_sync(?array $u): array {
    $res=['registered'=>0,'reversed'=>0]; $from=fin_unify_from(); if(!$from||!fin_tables()) return $res;
    if(fin_has('dd_bk_txns')) foreach(rows('SELECT * FROM dd_bk_txns WHERE date>=? ORDER BY id',$from) as $t) fin_track('books','dd_bk_txns',(int)$t['id'],(int)$t['voided']?null:fin_books_payload($t),'Books #'.$t['id'].' '.$t['type'],$u,$res);
    if(fin_has('dd_sal_entries')){
        foreach(rows("SELECT * FROM dd_sal_entries WHERE kind IN ('advance','payment') AND (date>=? OR (date IS NULL AND month>=?)) ORDER BY id",$from,substr($from,0,7)) as $e) fin_track('salary','dd_sal_entries',(int)$e['id'],(int)$e['voided']?null:fin_salentry_payload($e),'Salary '.$e['kind'].' #'.$e['id'].' ('.$e['month'].')',$u,$res);
        foreach(rows('SELECT month,status FROM dd_sal_months WHERE month>=? ORDER BY month',substr($from,0,7)) as $m) fin_track('salary','dd_sal_months',(int)str_replace('-','',$m['month']),$m['status']==='locked'?fin_salmonth_payload($m['month']):null,'Salary accrual '.$m['month'],$u,$res);
    }
    return $res;
}
function fin_track(string $sys,string $table,int $sid,?array $payload,string $memo,?array $u,array &$res): void {
    $cur=row('SELECT * FROM dd_fin_events WHERE source_system=? AND source_table=? AND source_id=? ORDER BY source_rev DESC LIMIT 1 FOR UPDATE',$sys,$table,$sid);
    $live=$cur&&in_array($cur['status'],['pending','posted','linked','excluded'],true);
    if($payload===null){ if($live){ fin_retire($cur,'cancelled','source voided / unlocked',$u); $res['reversed']++; } return; }
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); $sha=hash('sha256',$json);
    if($live&&hash_equals($cur['payload_sha'],$sha)) return;
    if($live){ fin_retire($cur,'superseded','source edited',$u); $res['reversed']++; }
    [$kind,$date,$amt,$acct,$party,$emp]=fin_event_amount($sys,$table,$payload);
    run('INSERT INTO dd_fin_events(source_system,source_table,source_id,source_rev,kind,business_date,earned_period,amount,account,employee_id,party,memo,payload,payload_sha) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        $sys,$table,$sid,$cur?(int)$cur['source_rev']+1:1,$kind,$date,$payload['month']??null,(int)$amt,$acct,$emp,$party?txt($party,160):null,txt($memo,250),$json,$sha); $res['registered']++;
}
function fin_retire(array $ev,string $status,string $why,?array $u): void {
    if($ev['status']==='posted'&&$ev['journal_id']) gl_reverse((int)$ev['journal_id'],gl_open_date(business_date()),'Event '.$ev['id'].' '.$why,$u);
    run('UPDATE dd_fin_events SET status=? WHERE id=?',$ev['status']==='posted'?($status==='cancelled'?'reversed':'superseded'):$status,$ev['id']);
}

/* ============================== duplicates, decisions ============================== */
function fin_candidates(array $ev): array {
    if(!$ev['business_date']||$ev['source_table']==='dd_sal_months') return [];
    $p=json_decode($ev['payload'],true); $amt=(int)$ev['amount']; $from=next_date($ev['business_date'],-FIN_DUP_DAYS); $to=next_date($ev['business_date'],FIN_DUP_DAYS); $o=[];
    if($amt>0) foreach(rows("SELECT l.id,l.date,l.name,l.type,l.account,l.amount,l.category FROM dd_ledger l WHERE l.voided=0 AND l.amount=? AND l.date BETWEEN ? AND ? AND NOT EXISTS(SELECT 1 FROM dd_fin_events e WHERE e.ledger_id=l.id AND e.status='linked') ORDER BY ABS(DATEDIFF(l.date,?)),l.id LIMIT 8",$amt,$from,$to,$ev['business_date']) as $l) $o[]=['ref'=>'ledger','id'=>(int)$l['id'],'date'=>$l['date'],'text'=>'CRM daybook #'.$l['id'].' · '.$l['type'].' · '.$l['name'].' · '.$l['account'],'amount'=>(int)$l['amount']];
    if($amt>0) foreach(rows("SELECT id,business_date,memo,amount FROM dd_fin_events WHERE source_system<>? AND status IN ('pending','posted') AND amount=? AND business_date BETWEEN ? AND ? LIMIT 5",$ev['source_system'],$amt,$from,$to) as $x) $o[]=['ref'=>'event','id'=>(int)$x['id'],'date'=>$x['business_date'],'text'=>'Event #'.$x['id'].' · '.$x['memo'],'amount'=>(int)$x['amount']];
    if($ev['source_table']==='dd_bk_txns'&&($p['type']??'')==='purchase') foreach(rows('SELECT b.id,b.date,b.reference,b.amount,s.name FROM dd_bills b JOIN dd_suppliers s ON s.id=b.supplier_id WHERE b.voided=0 AND b.amount=? AND b.date BETWEEN ? AND ? LIMIT 5',(int)$p['total'],$from,$to) as $b) $o[]=['ref'=>'bill','id'=>(int)$b['id'],'date'=>$b['date'],'text'=>'CRM supplier bill '.$b['reference'].' · '.$b['name'],'amount'=>(int)$b['amount']];
    return $o;
}
function fin_preview(array $ev): array { try{ $l=fin_rule($ev); }catch(RuntimeException $e){ return ['error'=>$e->getMessage()]; } $o=[];
    foreach($l as $x) if($x[1]||$x[2]) $o[]=['code'=>fin_code($x[0]),'debit'=>$x[1],'credit'=>$x[2],'memo'=>$x[3]??'']; return $o; }
function fin_post_event(array $ev,?array $u,string $note=''): int {
    if(!$ev['business_date']) fail('Event #'.$ev['id'].' has no payment date. Enter the date the money actually moved.');
    $jid=gl_post('evt:'.$ev['id'],gl_open_date($ev['business_date']),'event',(int)$ev['id'],txt($ev['memo']??'event',250),fin_rule($ev),$u);
    run("UPDATE dd_fin_events SET status='posted',journal_id=?,decided_by=?,decided_at=?,decision_note=? WHERE id=?",$jid,$u['id']??null,now_sql(),txt($note,250)?:null,$ev['id']); return (int)$jid;
}
function fin_decide(array $b,array $u): array {
    allowed($u,'close'); $ev=row("SELECT * FROM dd_fin_events WHERE id=? AND status='pending' FOR UPDATE",(int)($b['id']??0)); if(!$ev) fail('Pending posting item not found (it may already be decided).',404);
    $act=(string)($b['act']??''); $note=txt($b['note']??'',250);
    if(!empty($b['date'])&&!$ev['business_date']){ $d=valid_date($b['date']); run('UPDATE dd_fin_events SET business_date=? WHERE id=?',$d,$ev['id']); $ev['business_date']=$d; }
    month_lock_check((string)($ev['business_date']?:business_date()));
    if($act==='post'){ $c=fin_candidates($ev); if($c&&(empty($b['force'])||mb_strlen($note)<5)) fail('Possible duplicate: '.implode(' | ',array_map(fn($x)=>$x['text'].' ₹'.rupees($x['amount']).' on '.$x['date'],$c)).'. Link it to that record, or post anyway with a reason.',409);
        $jid=fin_post_event($ev,$u,$note); audit($u,'fin.event.post',['id'=>(int)$ev['id'],'journal'=>$jid,'note'=>$note]); return ['ok'=>true,'journal_id'=>$jid]; }
    if($act==='link'){ $ref=(string)($b['ref']??'ledger'); $rid=(int)($b['ref_id']??0); if(mb_strlen($note)<3) $note='same as '.$ref.' #'.$rid;
        if($ref==='ledger'){ $l=row('SELECT * FROM dd_ledger WHERE id=? AND voided=0',$rid); if(!$l||(int)$l['amount']!==(int)$ev['amount']) fail('Choose an active CRM daybook entry with the same amount.'); if(row("SELECT id FROM dd_fin_events WHERE ledger_id=? AND status='linked'",$rid)) fail('That CRM entry is already linked to another item.',409);
            run("UPDATE dd_fin_events SET status='linked',ledger_id=?,decided_by=?,decided_at=?,decision_note=? WHERE id=?",$rid,$u['id'],now_sql(),$note,$ev['id']); }
        elseif($ref==='event'){ $x=row("SELECT * FROM dd_fin_events WHERE id=? AND status IN ('pending','posted') AND source_system<>?",$rid,$ev['source_system']); if(!$x) fail('Choose a posting item from the other module.');
            run("UPDATE dd_fin_events SET status='linked',linked_event_id=?,decided_by=?,decided_at=?,decision_note=? WHERE id=?",$rid,$u['id'],now_sql(),$note,$ev['id']); }
        elseif($ref==='bill'){ if(!row('SELECT id FROM dd_bills WHERE id=? AND voided=0',$rid)) fail('Supplier bill not found.'); run("UPDATE dd_fin_events SET status='linked',decided_by=?,decided_at=?,decision_note=? WHERE id=?",$u['id'],now_sql(),txt('CRM bill #'.$rid.' · '.$note,250),$ev['id']); }
        else fail('Choose what this duplicates.');
        audit($u,'fin.event.link',['id'=>(int)$ev['id'],'ref'=>$ref,'ref_id'=>$rid,'note'=>$note]); return ['ok'=>true]; }
    if($act==='exclude'){ if(mb_strlen($note)<5) fail('Write why this item is not posted (e.g. owner personal cash, test entry).'); run("UPDATE dd_fin_events SET status='excluded',decided_by=?,decided_at=?,decision_note=? WHERE id=?",$u['id'],now_sql(),$note,$ev['id']); audit($u,'fin.event.exclude',['id'=>(int)$ev['id'],'note'=>$note]); return ['ok'=>true]; }
    fail('Choose post, link or exclude.');
}
/** Post every pending item that has a date and NO possible duplicate. Items with candidates stay for a person to decide. */
function fin_post_clear(array $b,array $u): array { allowed($u,'close'); $n=0; $held=0; $lim=min(2000,max(1,(int)($b['limit']??500)));
    foreach(rows("SELECT * FROM dd_fin_events WHERE status='pending' ORDER BY business_date,id LIMIT $lim FOR UPDATE") as $ev){ if(!$ev['business_date']||fin_candidates($ev)){ $held++; continue; }
        try{ month_lock_check($ev['business_date']); }catch(RuntimeException $e){ $held++; continue; } fin_post_event($ev,$u,'bulk: no duplicate candidates'); $n++; }
    audit($u,'fin.event.bulk',['posted'=>$n,'held'=>$held]); return ['ok'=>true,'posted'=>$n,'held'=>$held]; }
function fin_events_view(array $q): array {
    $st=in_array($q['status']??'pending',['pending','posted','linked','excluded','reversed','superseded','cancelled','all'],true)?($q['status']??'pending'):'pending';
    $ev=rows('SELECT * FROM dd_fin_events'.($st==='all'?'':' WHERE status=?').' ORDER BY business_date IS NULL DESC,business_date,id LIMIT 300',...($st==='all'?[]:[$st]));
    foreach($ev as &$e){ $e['candidates']=$e['status']==='pending'?fin_candidates($e):[]; $e['preview']=fin_preview($e); unset($e['payload']); } unset($e);
    $sum=[]; foreach(rows('SELECT status,COUNT(*) n,COALESCE(SUM(amount),0) a FROM dd_fin_events GROUP BY status') as $r) $sum[$r['status']]=['n'=>(int)$r['n'],'amount'=>(int)$r['a']];
    return ['status'=>$st,'events'=>$ev,'summary'=>$sum,'unify_from'=>fin_unify_from(),'map'=>fin_map(),'tax_split_pos_from'=>setting('tax_split_pos_from',''),'payroll_monthly_proration'=>setting('payroll_monthly_proration','calendar'),
        'rule'=>'Books and Salary Register money on/after the unification date is posted to the accounts once. If the same payment is already in the CRM daybook, LINK it instead of posting it again.'];
}
function fin_settings(array $b,array $u): array { if(!can($u,'*')) fail('Owner only.',403); $ch=[];
    if(array_key_exists('unify_from',$b)){ $v=trim((string)$b['unify_from']); if($v!=='') valid_date($v); set_setting('unify_from',$v); $ch['unify_from']=$v; }
    if(array_key_exists('tax_split_pos_from',$b)){ $v=trim((string)$b['tax_split_pos_from']); if($v!=='') valid_date($v); set_setting('tax_split_pos_from',$v); $ch['tax_split_pos_from']=$v; }
    if(array_key_exists('payroll_monthly_proration',$b)){ $v=$b['payroll_monthly_proration']==='full'?'full':'calendar'; set_setting('payroll_monthly_proration',$v); $ch['payroll_monthly_proration']=$v; }
    if(isset($b['map'])&&is_array($b['map'])){ $m=[]; foreach($b['map'] as $k=>$code){ $k=txt($k,80); $code=txt($code,20); if(!preg_match('/^(books|salary):.{1,60}$/u',$k)) fail('Map keys look like books:Cash or salary:Online.'); if(!row('SELECT id FROM dd_gl_accounts WHERE code=? AND active=1',$code)) fail('Unknown GL account '.$code.'.'); $m[$k]=$code; } set_setting('post_account_map',json_encode($m,JSON_UNESCAPED_UNICODE)); $ch['map']=$m; }
    audit($u,'fin.settings',$ch); return ['ok'=>true]+$ch; }

/* ============================== manual counter close (daily sales / tenders) ============================== */
function cc_int(mixed $v,string $label): int { $s=trim((string)($v??'')); if($s==='') return 0; if(!preg_match('/^\d{1,7}$/',$s)) fail($label.' must be a whole number.'); return (int)$s; }
function cc_money(mixed $v,string $label,bool $signed=false): int { $s=trim(str_replace([',','₹',' '],'',(string)($v??''))); if($s===''||$s==='-') return 0; $neg=false; if($signed&&str_starts_with($s,'-')){ $neg=true; $s=substr($s,1); }
    try{ $p=money($s); }catch(RuntimeException $e){ fail($label.': '.$e->getMessage()); } return $neg?-$p:$p; }
function cc_save(array $b,array $u): array {
    allowed($u,'close');
    return idem('cc-save',$b,$u,function()use($b,$u){
    $d=valid_date($b['date']??''); $counter=name_required($b['counter']??''); $drawer=drawer_name($b['drawer']??null);
    $v=['orders_success'=>cc_int($b['orders_success']??0,'Successful orders'),'orders_cancelled'=>cc_int($b['orders_cancelled']??0,'Cancelled orders'),'orders_returned'=>cc_int($b['orders_returned']??0,'Return orders'),'orders_complimentary'=>cc_int($b['orders_complimentary']??0,'Complimentary orders'),
        'net'=>cc_money($b['net']??'','Net amount'),'discount'=>cc_money($b['discount']??'','Discount'),'tax'=>cc_money($b['tax']??'','Tax'),'refund'=>cc_money($b['refund']??'','Refunds'),'rounding'=>cc_money($b['rounding']??'','Rounding',true),
        'total'=>cc_money($b['total']??'','Total sales'),'unpaid'=>cc_money($b['unpaid']??'','Not paid'),'cash'=>cc_money($b['cash']??'','Cash'),'card'=>cc_money($b['card']??'','Card'),'upi'=>cc_money($b['upi']??'','UPI'),'other'=>cc_money($b['other']??'','Other')];
    if($v['total']<=0) fail('Enter Total sales (₹) from the report.');
    $add=!empty($b['other_additive']);
    $base=$v['cash']+$v['card']+$v['upi']+$v['unpaid']; $withOther=$base+$v['other'];
    if($add){ if($withOther!==$v['total']) fail('Cash ₹'.rupees($v['cash']).' + Card ₹'.rupees($v['card']).' + UPI ₹'.rupees($v['upi']).' + Other ₹'.rupees($v['other']).' + Not paid ₹'.rupees($v['unpaid']).' = ₹'.rupees($withOther).', but Total sales is ₹'.rupees($v['total']).'.'.($base===$v['total']?' Without Other they already equal Total — Other is a repeated figure (e.g. it repeats UPI), not an extra tender. Untick “Other is a separate tender”.':''));
        if(mb_strlen(txt($b['other_reason']??''))<5) fail('Say what the separate “Other” tender is (e.g. Zomato settlement).'); }
    elseif($base!==$v['total']) fail('Cash ₹'.rupees($v['cash']).' + Card ₹'.rupees($v['card']).' + UPI ₹'.rupees($v['upi']).' + Not paid ₹'.rupees($v['unpaid']).' = ₹'.rupees($base).', but Total sales is ₹'.rupees($v['total']).'.'.($v['other']&&$withOther===$v['total']?' Adding Other makes it match: if Other really is a separate tender, tick “Other is a separate tender” and say what it is.':' Check the figures against the report.'));
    $v['residual']=$v['total']-$v['net']-$v['tax']-$v['rounding'];
    day_post_guard($d);
    if(row("SELECT id FROM dd_ledger WHERE date=? AND voided=0 AND type='sale' AND source IN ('petpooja-day','petpooja')",$d)) fail('Petpooja sales for '.$d.' are already imported into the daybook. Entering a counter close as well would count the same sales twice. Void the import first, or use only one method per day.',409);
    $rev=1; $old=null;
    if(!empty($b['revises_id'])){ $old=row("SELECT * FROM dd_counter_closes WHERE id=? AND status='posted' FOR UPDATE",(int)$b['revises_id']); if(!$old) fail('The counter close being corrected was not found (or was already corrected).',404); if($old['business_date']!==$d||$old['counter']!==$counter) fail('A correction must keep the same date and counter. Void the old one and enter a new one instead.'); if(mb_strlen(txt($b['note']??''))<5) fail('Write what is being corrected.'); $rev=(int)$old['revision']+1; }
    elseif(row("SELECT id FROM dd_counter_closes WHERE business_date=? AND counter=? AND status='posted'",$d,$counter)) fail('A counter close for '.$counter.' on '.$d.' already exists. Open it and choose Correct.',409);
    run('INSERT INTO dd_counter_closes(business_date,counter,drawer,revision,status,orders_success,orders_cancelled,orders_returned,orders_complimentary,net,discount,tax,refund,rounding,total,unpaid,cash,card,upi,other,other_additive,residual,evidence_ref,note,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        $d,$counter,$drawer,$rev,'posted',$v['orders_success'],$v['orders_cancelled'],$v['orders_returned'],$v['orders_complimentary'],$v['net'],$v['discount'],$v['tax'],$v['refund'],$v['rounding'],$v['total'],$v['unpaid'],$v['cash'],$v['card'],$v['upi'],$v['other'],$add?1:0,$v['residual'],txt($b['evidence_ref']??'',120)?:null,txt(trim(($add?'Other tender: '.txt($b['other_reason']??'').'. ':'').txt($b['note']??'')),2000)?:null,$u['id']);
    $id=(int)db()->lastInsertId();
    if($old){ cc_void_rows((int)$old['id'],$u,'corrected by revision '.$rev); run("UPDATE dd_counter_closes SET status='superseded',superseded_by=? WHERE id=?",$id,$old['id']); }
    $tenders=['cash'=>'Cash','upi'=>'Paytm','card'=>'Card']; if($add) $tenders['other']='Other bank'; $rows=[];
    foreach($tenders as $k=>$acct) if($v[$k]>0){ $lid=add_entry(['date'=>$d,'name'=>$counter.' '.strtoupper($k).' sales','type'=>'sale','account'=>$acct,'amount'=>rupees($v[$k]),'category'=>'Counter sales','reference'=>'CC-'.$id,'description'=>'Counter close #'.$id.' r'.$rev.($v['residual']?' · unexplained residual ₹'.rupees($v['residual']):''),'source'=>'counter-close','source_key'=>'cc-'.$id.'-'.$k],$u);
        if($k==='cash') run('UPDATE dd_ledger SET drawer=? WHERE id=?',$drawer,$lid); $rows[$k]=$lid; }
    if($v['residual']) inbox_raise('sales-residual','sales','Counter '.$counter.' '.$d.': Total − Net − Tax − Rounding = ₹'.rupees($v['residual']).' (unexplained)',['date'=>$d,'amount'=>abs($v['residual']),'ref_table'=>'dd_counter_closes','ref_id'=>$id,'dedupe'=>'cc-res-'.$id,'detail'=>'Kept in revenue and flagged. Enter a supported rounding figure if the bill-level report shows one; do not change revenue or tax to force a match.']);
    audit($u,'counter.close',['id'=>$id,'date'=>$d,'counter'=>$counter,'revision'=>$rev,'total'=>$v['total'],'tenders'=>array_intersect_key($v,$tenders),'other_ref'=>$add?null:$v['other'],'residual'=>$v['residual'],'ledger'=>$rows]);
    return ['ok'=>true,'id'=>$id,'revision'=>$rev,'residual'=>$v['residual'],'ledger'=>$rows,'other_reference_only'=>!$add&&$v['other']>0];
    });
}
function cc_void_rows(int $id,array $u,string $why): void { foreach(rows("SELECT id FROM dd_ledger WHERE source='counter-close' AND source_key LIKE ? AND voided=0",'cc-'.$id.'-%') as $l){ run('UPDATE dd_ledger SET voided=1 WHERE id=?',$l['id']); audit($u,'ledger.void',['id'=>(int)$l['id'],'reason'=>'Counter close #'.$id.' '.$why]); } }
function cc_void(array $b,array $u): array { allowed($u,'close'); $c=row("SELECT * FROM dd_counter_closes WHERE id=? AND status='posted' FOR UPDATE",(int)($b['id']??0)); if(!$c) fail('Counter close not found.',404);
    $why=txt($b['reason']??'',250); if(mb_strlen($why)<5) fail('Write why this counter close is cancelled.'); day_post_guard($c['business_date']);
    cc_void_rows((int)$c['id'],$u,'voided: '.$why); run("UPDATE dd_counter_closes SET status='voided',note=CONCAT(COALESCE(note,''),?) WHERE id=?",' [Voided: '.$why.']',$c['id']); audit($u,'counter.void',['id'=>(int)$c['id'],'reason'=>$why]); return ['ok'=>true]; }
function cc_view(string $d): array {
    $rows=rows("SELECT c.*,u.name by_name FROM dd_counter_closes c LEFT JOIN dd_users u ON u.id=c.created_by WHERE c.business_date=? ORDER BY c.status='posted' DESC,c.counter,c.revision",$d);
    $t=array_fill_keys(['orders_success','orders_cancelled','orders_returned','orders_complimentary','net','discount','tax','refund','rounding','total','unpaid','cash','card','upi','other','residual'],0); $n=0;
    foreach($rows as $r) if($r['status']==='posted'){ $n++; foreach($t as $k=>$_) $t[$k]+=(int)$r[$k]; }
    $additive=(int)(row("SELECT COALESCE(SUM(other),0) n FROM dd_counter_closes WHERE business_date=? AND status='posted' AND other_additive=1",$d)['n']);
    $ledger=[]; foreach(rows("SELECT account,SUM(amount) n FROM dd_ledger WHERE date=? AND voided=0 AND type='sale' AND source='counter-close' GROUP BY account",$d) as $r) $ledger[$r['account']]=(int)$r['n'];
    $flags=[]; if($t['residual']) $flags[]='Total − Net − Tax − Rounding = ₹'.rupees($t['residual']).' across counters (kept in revenue, flagged as unexplained).';
    if($t['other']&&$t['other']!==$additive) $flags[]='Other ₹'.rupees($t['other']-$additive).' kept as reference only (not counted as a tender).';
    $pp=(int)(row("SELECT COUNT(*) n FROM dd_ledger WHERE date=? AND voided=0 AND type='sale' AND source IN ('petpooja-day','petpooja')",$d)['n']); if($pp) $flags[]='Petpooja import also posted sales for this day — choose one method.';
    return ['date'=>$d,'closes'=>$rows,'counters'=>$n,'totals'=>$t,'tender_total'=>$t['cash']+$t['card']+$t['upi']+$additive+$t['unpaid'],'ledger'=>$ledger,'flags'=>$flags,'drawers'=>day_drawers($d)];
}

/* ============================== UPI / card settlement (gross → fees → bank) ============================== */
function settle_save(array $b,array $u): array { allowed($u,'close');
    return idem('settle',$b,$u,function()use($b,$u){
    $d=valid_date($b['date']??''); $from=(string)($b['from']??''); $to=(string)($b['to']??''); if(!in_array($from,['Paytm','Card'],true)) fail('Settlement comes from UPI/Paytm or Card clearing.'); if(!in_array($to,['Bank of India','Other bank'],true)) fail('Choose the bank account that received the money.');
    $gross=cc_money($b['gross']??'','Gross settled'); $fee=cc_money($b['fee']??'','Fee'); $ftax=cc_money($b['fee_tax']??'','Tax on fee'); $net=$gross-$fee-$ftax;
    if($gross<=0||$net<=0) fail('Gross must be more than fees + tax on fees.'); $ref=txt($b['reference']??'',120); day_post_guard($d);
    $sid=add_entry(['date'=>$d,'name'=>$from.' settlement','type'=>'settlement','account'=>$from,'to_account'=>$to,'amount'=>rupees($net),'category'=>'Settlement','reference'=>$ref,'description'=>'Gross ₹'.rupees($gross).' − fee ₹'.rupees($fee).' − tax on fee ₹'.rupees($ftax).' = net ₹'.rupees($net)],$u);
    $ids=['settlement'=>$sid];
    if($fee) $ids['fee']=add_entry(['date'=>$d,'name'=>$from.' merchant fee','type'=>'expense','account'=>$from,'amount'=>rupees($fee),'category'=>'Payment fees','reference'=>$ref,'description'=>'Fee deducted from settlement #'.$sid],$u);
    if($ftax) $ids['fee_tax']=add_entry(['date'=>$d,'name'=>$from.' tax on fee','type'=>'expense','account'=>$from,'amount'=>rupees($ftax),'category'=>'Payment fee tax','reference'=>$ref,'description'=>'Tax on fee, settlement #'.$sid.' (CA: classify as input tax if claimable)'],$u);
    audit($u,'settlement.save',$ids+['gross'=>$gross,'fee'=>$fee,'fee_tax'=>$ftax,'net'=>$net]); return ['ok'=>true,'net'=>$net]+$ids; });
}
/** Tender reconciliation: what each tender collected, what was settled to the bank, and what is still in clearing. */
function tender_recon(string $from,string $to): array {
    valid_date($from); valid_date($to); if($to<$from) fail('End date is before start date.'); $acc=['Cash','Paytm','Card','Other bank'];
    $open=[]; foreach(['Paytm','Card'] as $a) $open[$a]=tender_clearing($a,next_date($from,-1));
    $days=[]; foreach(rows("SELECT date,account,type,to_account,SUM(amount) n FROM dd_ledger WHERE voided=0 AND date BETWEEN ? AND ? AND (type IN ('sale','refund','settlement','deposit','withdrawal') OR (type='expense' AND category IN ('Payment fees','Payment fee tax'))) GROUP BY date,account,type,to_account",$from,$to) as $r){
        $x=&$days[$r['date']]; $x??=['date'=>$r['date'],'sales'=>array_fill_keys($acc,0),'settled'=>['Paytm'=>0,'Card'=>0],'fees'=>['Paytm'=>0,'Card'=>0],'refunds'=>0]; $n=(int)$r['n'];
        if($r['type']==='sale'&&isset($x['sales'][$r['account']])) $x['sales'][$r['account']]+=$n; if($r['type']==='refund') $x['refunds']+=$n;
        if(in_array($r['type'],['settlement','deposit','withdrawal'],true)&&isset($x['settled'][$r['account']])) $x['settled'][$r['account']]+=$n;
        if($r['type']==='expense'&&isset($x['fees'][$r['account']])) $x['fees'][$r['account']]+=$n; unset($x); }
    foreach(fin_has('dd_counter_closes')?rows("SELECT business_date d,SUM(cash) cash,SUM(upi) upi,SUM(card) card,SUM(CASE WHEN other_additive=1 THEN other ELSE 0 END) oth,SUM(CASE WHEN other_additive=0 THEN other ELSE 0 END) oref,SUM(total) total,SUM(residual) res FROM dd_counter_closes WHERE status='posted' AND business_date BETWEEN ? AND ? GROUP BY business_date",$from,$to):[] as $c){
        $x=&$days[$c['d']]; $x??=['date'=>$c['d'],'sales'=>array_fill_keys($acc,0),'settled'=>['Paytm'=>0,'Card'=>0],'fees'=>['Paytm'=>0,'Card'=>0],'refunds'=>0];
        $x['counter']=['cash'=>(int)$c['cash'],'upi'=>(int)$c['upi'],'card'=>(int)$c['card'],'other_tender'=>(int)$c['oth'],'other_reference'=>(int)$c['oref'],'total'=>(int)$c['total'],'residual'=>(int)$c['res']];
        $x['counter_matches_ledger']=(int)$c['cash']===$x['sales']['Cash']+0&&(int)$c['upi']===$x['sales']['Paytm']&&(int)$c['card']===$x['sales']['Card']; unset($x); }
    ksort($days); $run=$open; foreach($days as &$x){ foreach(['Paytm','Card'] as $a){ $run[$a]+=$x['sales'][$a]-$x['settled'][$a]-$x['fees'][$a]; $x['clearing'][$a]=$run[$a]; } } unset($x);
    $bank=(int)(row("SELECT COUNT(*) n FROM dd_bank_lines WHERE status='unmatched' AND txn_date BETWEEN ? AND ?",$from,$to)['n']??0);
    return ['from'=>$from,'to'=>$to,'days'=>array_values($days),'clearing_open'=>$open,'clearing_close'=>$run,'bank_unmatched'=>$bank,
        'note'=>'Cash + Card + UPI (+ Other only when it is a separate tender) = sales. UPI/card sit in clearing until a settlement moves the net to the bank; fees are expenses. A settlement is a transfer, never a second sale. Settlement completeness needs the provider statement and bank statement — not verified here.'];
}
function tender_clearing(string $acct,string $upto): int { $n=0; foreach(rows("SELECT type,account,to_account,amount FROM dd_ledger WHERE voided=0 AND date<=? AND (account=? OR to_account=?)",$upto,$acct,$acct) as $e){ $m=movement($e); if($e['account']===$acct) $n+=$m['in']-$m['out']; if(in_array($e['type'],['deposit','withdrawal','settlement'],true)){ if($e['account']===$acct) $n-=(int)$e['amount']; if($e['to_account']===$acct) $n+=(int)$e['amount']; } } return $n; }

/* ============================== drawers ============================== */
function drawer_opening_save(array $b,array $u): array { allowed($u,'close'); $d=valid_date($b['date']??''); $dr=drawer_name($b['drawer']??null); day_post_guard($d); $amt=cc_money($b['opening']??'','Opening');
    run('INSERT INTO dd_drawer_openings(date,drawer,opening,set_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE opening=VALUES(opening),set_by=VALUES(set_by),set_at=CURRENT_TIMESTAMP',$d,$dr,$amt,$u['id']);
    $sum=(int)row('SELECT COALESCE(SUM(opening),0) n FROM dd_drawer_openings WHERE date=?',$d)['n']; $day=day_get($d); audit($u,'drawer.opening',['date'=>$d,'drawer'=>$dr,'opening'=>$amt]);
    return ['ok'=>true,'drawers'=>drawer_summary($d),'warning'=>row('SELECT 1 FROM dd_drawer_openings WHERE date=? AND drawer=?',$d,MAIN_DRAWER)&&$sum!==(int)$day['opening']?'Drawer openings total ₹'.rupees($sum).' but the day opening is ₹'.rupees((int)$day['opening']).'.':null]; }
function drawer_transfer_save(array $b,array $u): array { if(!can($u,'cash.handover')&&!can($u,'close')) fail('Your role cannot move cash between drawers.',403);
    return idem('drawer-transfer',$b,$u,function()use($b,$u){
    $d=business_date(); if(!empty($b['date'])&&can($u,'close')) $d=valid_date($b['date']); day_post_guard($d);
    $from=drawer_name($b['from_drawer']??($u['drawer']??null)); $to=drawer_name($b['to_drawer']??null); if($from===$to) fail('Choose two different drawers.');
    if(!can($u,'close')&&$from!==drawer_name($u['drawer']??null)) fail('You can only send cash from your own drawer ('.drawer_name($u['drawer']??null).').',403);
    $amt=cc_money($b['amount']??'','Amount'); if($amt<=0) fail('Enter the amount moved.'); $have=drawer_cash($d,$from)['expected']; if($amt>$have) fail($from.' should hold only ₹'.rupees($have).'. Count the drawer first.');
    run('INSERT INTO dd_drawer_transfers(date,from_drawer,to_drawer,amount,note,created_by) VALUES(?,?,?,?,?,?)',$d,$from,$to,$amt,txt($b['note']??'',250)?:null,$u['id']); $id=(int)db()->lastInsertId();
    audit($u,'drawer.transfer',['id'=>$id,'from'=>$from,'to'=>$to,'amount'=>$amt]); return ['ok'=>true,'id'=>$id,'drawers'=>drawer_summary($d)]; }); }
function drawer_transfer_ack(array $b,array $u): array { $t=row('SELECT * FROM dd_drawer_transfers WHERE id=? AND voided=0 AND acknowledged_by IS NULL FOR UPDATE',(int)($b['id']??0)); if(!$t) fail('Transfer not found.',404);
    if(!can($u,'close')&&drawer_name($u['drawer']??null)!==$t['to_drawer']) fail('Only the receiving drawer acknowledges.',403); if((int)$t['created_by']===(int)$u['id']) fail('The sender cannot acknowledge their own transfer.',403);
    run('UPDATE dd_drawer_transfers SET acknowledged_by=?,acknowledged_at=? WHERE id=?',$u['id'],now_sql(),$t['id']); audit($u,'drawer.transfer.ack',['id'=>(int)$t['id']]); return ['ok'=>true]; }

/* ============================== routes ============================== */
function post_get(string $route,array $u): ?array {
    switch($route){
    case 'crm-events': allowed($u,'close'); return fin_events_view($_GET);
    case 'crm-counter-close': if(!can($u,'close')&&!can($u,'cash.review')) fail('Not allowed.',403); return cc_view(valid_date($_GET['date']??business_date()));
    case 'crm-tender-recon': if(!can($u,'reports')&&!can($u,'close')) fail('Report access is restricted.',403); $to=valid_date($_GET['to']??business_date()); return tender_recon(valid_date($_GET['from']??substr($to,0,8).'01'),$to);
    case 'crm-drawers': if(!can($u,'cash.count')&&!can($u,'close')) fail('Not allowed.',403); $d=valid_date($_GET['date']??business_date());
        return ['date'=>$d,'drawers'=>drawer_summary($d),'transfers'=>drawer_tables()?rows('SELECT t.*,a.name by_name,b.name ack_name FROM dd_drawer_transfers t LEFT JOIN dd_users a ON a.id=t.created_by LEFT JOIN dd_users b ON b.id=t.acknowledged_by WHERE t.date=? ORDER BY t.id',$d):[],'known'=>array_values(array_unique(array_merge([MAIN_DRAWER],array_column(rows("SELECT DISTINCT drawer FROM dd_users WHERE drawer IS NOT NULL AND drawer<>''"),'drawer'))))];
    }
    return null;
}
function post_mutate(string $route,array $b,array $u): ?array {
    switch($route){
    case 'crm-event-decide': return fin_decide($b,$u);
    case 'crm-events-post-clear': return fin_post_clear($b,$u);
    case 'crm-events-sync': allowed($u,'close'); return ['ok'=>true]+fin_sync($u);
    case 'crm-unify-settings': return fin_settings($b,$u);
    case 'crm-counter-close-save': return cc_save($b,$u);
    case 'crm-counter-close-void': return cc_void($b,$u);
    case 'crm-settlement': return settle_save($b,$u);
    case 'crm-drawer-opening': return drawer_opening_save($b,$u);
    case 'crm-drawer-transfer': return drawer_transfer_save($b,$u);
    case 'crm-drawer-transfer-ack': return drawer_transfer_ack($b,$u);
    }
    return null;
}
