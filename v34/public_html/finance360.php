<?php
declare(strict_types=1);
// KSDD CRM 360 — V6 services: kitchen daily usage & valuation, stock movement / monthly consumption reports,
// payroll Excel import with employee identity mapping, advance carry-forward, recurring monthly bills, budgets,
// bank statement matching, guided Cash OUT wizard, monthly report & close.
// Rules: money = integer paise; quantity = integer milli-units in PHP; one event = one source key; nothing here moves money
// except an explicitly confirmed payment through the existing voucher / ledger engine.
require_once __DIR__.'/lib360.php';
require_once __DIR__.'/supplier360.php';

const WASTE_KINDS = ['wastage','expired','damaged'];
const BANK_ACCOUNTS = ['Bank of India','Other bank','Paytm','Card'];
const PAYROLL_HEADERS = [ // canonical key => accepted header spellings (case/space-insensitive)
 'external_id'=>['employee id','emp id','employee code','emp code'],'name'=>['employee name','name'],'department'=>['department','dept'],'designation'=>['designation','job','role'],
 'full_day'=>['full day','full days'],'half_day'=>['half day','half days'],'off_days'=>['off days','off day','weekly off'],'paid_leave'=>['paid leave'],'paid_days'=>['paid days'],'unpaid_days'=>['unpaid days'],
 'daily_wage'=>['daily wage','daily rate'],'gross_wages'=>['gross wages','gross wage','basic'],'overtime'=>['overtime','ot','ot amount'],'penalties'=>['penalities','penalties','penalty'],'extras'=>['extras','extra','incentive'],
 'gross_earnings'=>['gross earnings','gross'],'loan_advance'=>['loan & advance','loan and advance','advance','loan/advance'],'arrears'=>['arrears'],'to_pay'=>['to pay','net pay','net payable'],'balance_arrears'=>['balance arrears'],
 'payment_method'=>['payment method'],'pf'=>['pf'],'uan'=>['uan'],'esic'=>['esic'],'bank_name'=>['bank name'],'ifsc'=>['ifsc code','ifsc'],'bank_account'=>['bank account no','account no','bank account number'],'bank_branch'=>['bank branch name','branch'],'account_type'=>['account type'],
];
const PAYROLL_MONEY = ['daily_wage','gross_wages','overtime','penalties','extras','gross_earnings','loan_advance','arrears','to_pay','balance_arrears'];
const PAYROLL_DAYS = ['full_day','half_day','off_days','paid_leave','paid_days','unpaid_days'];

function fin_ready(): void { crm_ready(); }
function month_bounds(string $m): array { valid_month($m); $from=$m.'-01'; $to=(new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d'); return [$from,$to]; }
function prev_month(string $m): string { return (new DateTimeImmutable($m.'-01'))->modify('-1 month')->format('Y-m'); }
function sum_int(string $sql,mixed ...$a): int { return (int)(row($sql,...$a)['n']??0); }
function jsonset(string $k,array $def=[]): array { $v=json_decode(setting($k,''),true); return is_array($v)?$v:$def; }

/* ============================== ADVANCES (carry-forward) ============================== */
/** Advances actually paid (ledger) that are scheduled for recovery up to $month, minus approved recoveries in OTHER months. */
function advance_recoverable(int $emp,string $month,?int $exceptPayrollId=null): int {
    $paid=sum_int("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE employee_id=? AND type='advance' AND voided=0 AND payroll_month<=?",$emp,$month);
    $rec=sum_int('SELECT COALESCE(SUM(advance),0) n FROM dd_payroll WHERE employee_id=? AND approved=1'.($exceptPayrollId?' AND id<>'.(int)$exceptPayrollId:'').' AND month<>?',$emp,$month);
    return max(0,$paid-$rec);
}
function advance_outstanding(int $emp): int {
    return max(0,sum_int("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE employee_id=? AND type='advance' AND voided=0",$emp)-sum_int('SELECT COALESCE(SUM(advance),0) n FROM dd_payroll WHERE employee_id=? AND approved=1',$emp));
}
function salary_paid(int $emp,string $month): int { return sum_int("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE employee_id=? AND payroll_month=? AND type='salary' AND voided=0",$emp,$month); }
function salary_pending(int $emp,string $month): int { return sum_int("SELECT COALESCE(SUM(amount),0) n FROM dd_vouchers WHERE kind='salary' AND employee_id=? AND payroll_month=? AND status IN ('requested','approved')",$emp,$month); }

/** Shared payroll calculation used by the manual screen (actions.php) — recovery carries forward and never exceeds what was really paid. */
function payroll_calculate(array $e,string $month,array $b,array $u): array {
    if(row('SELECT id FROM dd_payroll WHERE employee_id=? AND month=? AND approved=1',$e['id'],$month)) fail('Approved payroll cannot be recalculated. Use an adjustment in a later month.');
    $days=$b['paid_days']??''; [$base,$policy,$den]=payroll_base($e,$month,$days,txt($b['zero_pay_reason']??'',200));
    $ot=money($b['overtime']??0); $bonus=money($b['bonus']??0); $ded=money($b['deductions']??0); $gross=$base+$ot+$bonus;
    if($ded>$gross) fail('Deductions exceed earnings.');
    $recoverable=advance_recoverable((int)$e['id'],$month);
    $want=isset($b['advance_recovery'])&&$b['advance_recovery']!==''?money($b['advance_recovery']):null;
    if($want!==null&&$want>$recoverable) fail('Advance recovery cannot exceed ₹'.rupees($recoverable).' (advances actually paid and not yet recovered).');
    $adv=$want??min($recoverable,$gross-$ded); if($adv>$gross-$ded) fail('Advance recovery exceeds earnings after deductions. Recover less this month; the rest carries forward.');
    $net=$gross-$ded-$adv;
    run("INSERT INTO dd_payroll(employee_id,month,base,overtime,bonus,deductions,advance,gross,net,paid_days,source,calc_policy,calc_days) VALUES(?,?,?,?,?,?,?,?,?,?,'manual',?,?) ON DUPLICATE KEY UPDATE base=VALUES(base),overtime=VALUES(overtime),bonus=VALUES(bonus),deductions=VALUES(deductions),advance=VALUES(advance),gross=VALUES(gross),net=VALUES(net),paid_days=VALUES(paid_days),source='manual',calc_policy=VALUES(calc_policy),calc_days=VALUES(calc_days)",
        $e['id'],$month,$base,$ot,$bonus,$ded,$adv,$gross,$net,number_format((float)$days,2,'.',''),$policy,$den);
    audit($u,'payroll.calculate',['employee_id'=>$e['id'],'month'=>$month,'base'=>$base,'policy'=>$policy,'denominator'=>$den,'paid_days'=>$days,'net'=>$net,'recovery'=>$adv,'recoverable'=>$recoverable,'carry_forward'=>$recoverable-$adv]);
    return ['net'=>$net,'recovery'=>$adv,'carry_forward'=>$recoverable-$adv];
}
/** Base pay for one employee-month under the configured policy. Returns [base paise, policy, denominator days]. */
function payroll_base(array $e,string $month,mixed $days,string $zeroReason=''): array {
    if($days===''||$days===null) fail('Enter paid days (full days + ½ × half days + paid leave / paid offs). A blank is not zero.');
    if(!is_numeric($days)||(float)$days<0||(float)$days>31) fail('Paid days must be 0–31.');
    $h=(int)round((float)$days*100); if($h%50!==0) fail('Paid days must be in steps of 0.5.');
    if($e['pay_basis']==='daily') return [intdiv((int)$e['salary']*$h+50,100),'daily',null];
    $policy=setting('payroll_monthly_proration','calendar')==='full'?'full':'calendar';
    if($policy==='full') return [(int)$e['salary'],'full',null];
    $den=days_in_month($month); if($h>$den*100) fail('Paid days cannot exceed the '.$den.' days of '.$month.'.');
    if($h===0&&mb_strlen($zeroReason)<5) fail('0 paid days gives ₹0 salary. Write the reason (e.g. unpaid leave all month) to save it.');
    return [prorate_monthly((int)$e['salary'],$h,$den),'calendar',$den];
}
function payroll_approve(int $id,array $u,string $note=''): void {
    $p0=row('SELECT * FROM dd_payroll WHERE id=?',$id); if(!$p0) fail('Payroll not found.');
    row('SELECT id FROM dd_employees WHERE id=? FOR UPDATE',$p0['employee_id']); $p=row('SELECT * FROM dd_payroll WHERE id=? FOR UPDATE',$id);
    if($p['approved']) fail('Payroll is already approved.');
    // Fix C16: a future month cannot be finally approved (its expense would be accrued before it is earned).
    if($p['month']>substr(business_date(),0,7)) fail('Payroll for '.$p['month'].' is in the future. Approve it after the month has started and attendance is final.',409);
    // Zero-wage exceptions are visible and need a reason, never a silent approval (T24).
    if((float)($p['paid_days']??0)>0&&(int)$p['base']===0&&mb_strlen(trim($note))<5) fail('This employee has paid days but ₹0 base pay (wage not set?). Fix the wage, or write why ₹0 is correct to approve.',409);
    require_once __DIR__.'/post360.php'; if(function_exists('fin_payroll_conflict')&&($c=fin_payroll_conflict((int)$p['employee_id'],$p['month']))) fail($c,409);
    if((int)$p['advance']>advance_recoverable((int)$p['employee_id'],$p['month'],$id)) fail('Staff advances changed (an advance was voided or recovered elsewhere). Recalculate payroll before approving.');
    if((int)$p['net']<0) fail('Net salary is negative. Recalculate.');
    run('UPDATE dd_payroll SET approved=1,approved_by=?,approved_at=?'.(trim($note)!==''?',note=?':'').' WHERE id=?',...(trim($note)!==''?[$u['id'],now_sql(),txt($note,250),$id]:[$u['id'],now_sql(),$id])); audit($u,'payroll.approve',['id'=>$id,'net'=>(int)$p['net'],'recovery'=>(int)$p['advance'],'note'=>$note]);
}

/* ============================== STOCK: balances at a date ============================== */
/** Net movement at one location for moves dated in [$from,$to] (null = open-ended), per item, in milli-units. */
function loc_net(int $loc,?string $from,?string $to,?int $item=null): array {
    $w=['(to_location=? OR from_location=?)']; $a=[$loc,$loc,$loc,$loc];
    if($from){$w[]='date>=?';$a[]=$from;} if($to){$w[]='date<=?';$a[]=$to;} if($item){$w[]='item_id=?';$a[]=$item;}
    $o=[]; foreach(rows('SELECT item_id,SUM(CASE WHEN to_location=? THEN ABS(quantity) ELSE 0 END)-SUM(CASE WHEN from_location=? THEN ABS(quantity) ELSE 0 END) n FROM dd_stock_moves WHERE '.implode(' AND ',$w).' GROUP BY item_id',...$a) as $r) $o[(int)$r['item_id']]=qm($r['n']); return $o;
}
/** Whole-restaurant net movement (transfers are internal and excluded). */
function whole_net(?string $from,?string $to): array {
    $w=["kind<>'transfer'"]; $a=[]; if($from){$w[]='date>=?';$a[]=$from;} if($to){$w[]='date<=?';$a[]=$to;}
    $o=[]; foreach(rows('SELECT item_id,SUM(quantity) n FROM dd_stock_moves WHERE '.implode(' AND ',$w).' GROUP BY item_id',...$a) as $r) $o[(int)$r['item_id']]=qm($r['n']); return $o;
}
function loc_balances(int $loc): array { $o=[]; foreach(rows('SELECT item_id,quantity FROM dd_stock_balances WHERE location_id=?',$loc) as $r) $o[(int)$r['item_id']]=qm($r['quantity']); return $o; }

/**
 * Item-wise movement register for a period. $loc=null → whole restaurant (internal transfers net to zero and are excluded).
 * Columns separate Received, Transfer IN/OUT, Used (consumption), Waste, Supplier return and Count adjustment.
 */
function stock_movement(string $from,string $to,?int $loc=null): array {
    valid_date($from); valid_date($to); if($to<$from) fail('End date is before start date.');
    $items=[]; foreach(rows('SELECT id,name,name_hi,category,unit,cost,cost_source,track_daily,quantity,active FROM dd_inventory ORDER BY category,name') as $i) $items[(int)$i['id']]=$i;
    $cur=$loc?loc_balances($loc):array_map(fn($i)=>qm($i['quantity']),$items);
    $after=$loc?loc_net($loc,next_date($to),null):whole_net(next_date($to),null);
    $w=['date BETWEEN ? AND ?']; $a=[$from,$to]; if($loc){ $w[]='(to_location=? OR from_location=?)'; array_push($a,$loc,$loc); }
    $per=[];
    foreach(rows('SELECT item_id,kind,to_location,from_location,quantity,cost_value FROM dd_stock_moves WHERE '.implode(' AND ',$w),...$a) as $m){
        $id=(int)$m['item_id']; $q=abs(qm($m['quantity'])); $v=(int)($m['cost_value']??0); $k=$m['kind'];
        $p=&$per[$id]; $p??=['purchase'=>0,'transfer_in'=>0,'transfer_out'=>0,'used'=>0,'waste'=>0,'returned'=>0,'adjust'=>0,'purchase_value'=>0,'used_value'=>0,'waste_value'=>0,'returned_value'=>0,'adjust_value'=>0];
        if($loc){ $in=(int)$m['to_location']===$loc; $sign=$in?1:-1; } else { $sign=qm($m['quantity'])<0?-1:1; }
        if($k==='transfer'){ if(!$loc) { unset($p); continue; } if($in) $p['transfer_in']+=$q; else $p['transfer_out']+=$q; }
        elseif($k==='in'){ $p['purchase']+=$q; $p['purchase_value']+=$v; }
        elseif($k==='consume'){ $p['used']+=$q; $p['used_value']+=$v; }
        elseif(in_array($k,WASTE_KINDS,true)){ $p['waste']+=$q; $p['waste_value']+=$v; }
        elseif($k==='return'){ $p['returned']+=$q; $p['returned_value']+=$v; }
        else { $p['adjust']+=$sign*$q; $p['adjust_value']+=$sign*$v; } // adjust, opening, legacy
        unset($p);
    }
    $out=[]; $tot=['purchase_value'=>0,'used_value'=>0,'waste_value'=>0,'returned_value'=>0,'opening_value'=>0,'closing_value'=>0]; $unvalued=0;
    foreach($items as $id=>$i){
        $p=$per[$id]??null; $closing=($cur[$id]??0)-($after[$id]??0);
        $net=$p?($p['purchase']+$p['transfer_in']-$p['transfer_out']-$p['used']-$p['waste']-$p['returned']+$p['adjust']):0; $opening=$closing-$net;
        if(!$p&&$opening===0&&$closing===0) continue; if(!$i['active']&&!$p&&$closing===0) continue;
        $cost=(int)$i['cost']; $valued=$cost>0; if(!$valued&&($p['used']??0)+($p['waste']??0)+$closing>0) $unvalued++;
        $r=['item_id'=>$id,'name'=>$i['name'],'name_hi'=>$i['name_hi'],'category'=>$i['category'],'unit'=>$i['unit'],'track_daily'=>(int)$i['track_daily'],'unit_cost'=>$cost,'valued'=>$valued,
            'opening'=>m3($opening),'purchase'=>m3($p['purchase']??0),'transfer_in'=>m3($p['transfer_in']??0),'transfer_out'=>m3($p['transfer_out']??0),'used'=>m3($p['used']??0),'waste'=>m3($p['waste']??0),'returned'=>m3($p['returned']??0),'adjust'=>m3($p['adjust']??0),'closing'=>m3($closing),
            'purchase_value'=>$p['purchase_value']??0,'used_value'=>$p['used_value']??0,'waste_value'=>$p['waste_value']??0,'returned_value'=>$p['returned_value']??0,
            'opening_value'=>intdiv(max(0,$opening)*$cost+500,1000),'closing_value'=>intdiv(max(0,$closing)*$cost+500,1000),'negative'=>$closing<0||$opening<0];
        foreach($tot as $k=>$_) $tot[$k]+=$r[$k]; $out[]=$r;
    }
    return ['from'=>$from,'to'=>$to,'location_id'=>$loc,'scope'=>$loc?'location':'whole restaurant (internal transfers excluded)','rows'=>$out,'totals'=>$tot,'unvalued_items'=>$unvalued,
      'valuation_note'=>'Used/waste/purchase values are recorded at the moment of posting (weighted-average cost). Opening/closing values use the CURRENT average cost and are an estimate.'];
}
function consistency_check(): array {
    return rows("SELECT i.id,i.name,i.quantity total,COALESCE(SUM(b.quantity),0) by_location FROM dd_inventory i LEFT JOIN dd_stock_balances b ON b.item_id=i.id GROUP BY i.id,i.name,i.quantity HAVING ABS(total-by_location)>0.0005");
}

/* ============================== KITCHEN DAILY USAGE ============================== */
function usage_location(int $loc,array $u): array {
    $l=row('SELECT * FROM dd_locations WHERE id=? AND active=1',$loc); if(!$l) fail('Choose the kitchen / location.');
    if($l['kind']!=='kitchen'&&!can($u,'store.out')&&!can($u,'close')) fail('You can record usage only for a kitchen location.',403); return $l;
}
function usage_date(mixed $d,array $u): string {
    $d=valid_date($d??business_date()); $today=business_date(); if($d>$today) fail('Usage cannot be recorded for a future date.');
    $back=setting_int('usage_backdate_days',2); if($d<next_date($today,-$back)&&!can($u,'close')) fail('Only a manager can record usage older than '.$back.' day(s).',403);
    month_lock_check($d); return $d;
}
/** Screen data: per item opening, IN, transfers, used & waste so far, closing so far — for one kitchen and one business date. */
function usage_sheet(string $d,int $loc): array {
    $mv=stock_movement($d,$d,$loc); $byId=[]; foreach($mv['rows'] as $r) $byId[$r['item_id']]=$r;
    $items=rows('SELECT id,name,name_hi,category,unit,cost,track_daily FROM dd_inventory WHERE active=1 ORDER BY track_daily DESC,category,name');
    $lines=rows('SELECT l.*,c.consumption_no,c.mode,c.status cstatus FROM dd_stock_consumption_lines l JOIN dd_stock_consumptions c ON c.id=l.consumption_id WHERE l.business_date=? AND l.location_id=? ORDER BY l.id',$d,$loc);
    $done=[]; foreach($lines as $l) $done[(int)$l['item_id']]=true; $now=loc_balances($loc);
    $out=[]; foreach($items as $i){ $id=(int)$i['id']; $r=$byId[$id]??null; if(!$r&&!$i['track_daily']&&empty($now[$id])) continue;
        $out[]=['item_id'=>$id,'name'=>$i['name'],'name_hi'=>$i['name_hi'],'unit'=>$i['unit'],'category'=>$i['category'],'track_daily'=>(int)$i['track_daily'],'unit_cost'=>(int)$i['cost'],
          'opening'=>$r['opening']??m3($now[$id]??0),'received'=>$r['purchase']??'0.000','transfer_in'=>$r['transfer_in']??'0.000','transfer_out'=>$r['transfer_out']??'0.000','used'=>$r['used']??'0.000','waste'=>$r['waste']??'0.000','closing'=>$r['closing']??m3($now[$id]??0),
          'available_now'=>m3($now[$id]??0),'entered'=>isset($done[$id]),'status'=>isset($done[$id])?'entered':((int)$i['track_daily']?'missing':'optional')]; }
    return ['date'=>$d,'location_id'=>$loc,'items'=>$out,'entries'=>$lines,'missing'=>count(array_filter($out,fn($x)=>$x['status']==='missing'))];
}
/**
 * Post kitchen usage. Two deliberate modes, never mixed in one line:
 *  direct  — staff enters quantity USED (+ separate waste); posts immediately; optional closing count → variance goes to review, NOT consumption.
 *  closing — staff enters CLOSING COUNT (+ separate waste); system derives usage; the whole sheet waits for manager approval.
 * Transfers/requests are never consumption. Idempotent on client_key.
 */
function usage_submit(array $b,array $u): array {
    $ck=client_key($b); if(!$ck) fail('Submission key missing. Refresh and try again.');
    if($x=row('SELECT id,consumption_no,status FROM dd_stock_consumptions WHERE client_key=?',$ck)) return ['ok'=>true,'duplicate'=>true,'id'=>(int)$x['id'],'consumption_no'=>$x['consumption_no'],'status'=>$x['status']];
    $loc=(int)($b['location_id']??0); usage_location($loc,$u); $d=usage_date($b['date']??null,$u);
    $mode=($b['mode']??'direct')==='closing'?'closing':'direct'; $lines=$b['lines']??[]; if(!is_array($lines)||!$lines||count($lines)>200) fail('Add at least one item.');
    $no=seq('USE',$d); $status=$mode==='closing'?'review':'posted';
    run('INSERT INTO dd_stock_consumptions(consumption_no,business_date,location_id,mode,status,note,client_key,created_by) VALUES(?,?,?,?,?,?,?,?)',$no,$d,$loc,$mode,$status,txt($b['note']??''),$ck,$u['id']);
    $cid=(int)db()->lastInsertId(); $seen=[]; $res=['posted'=>0,'review'=>0,'variances'=>0,'used_value'=>0,'waste_value'=>0];
    $photoAbove=setting_int('usage_waste_photo_above',50000);
    foreach($lines as $n=>$l){
        $item=(int)($l['item_id']??0); $row='Row '.($n+1).': ';
        if(isset($seen[$item])) fail($row.'item listed twice. Put the total in one row.'); $seen[$item]=1;
        $it=row('SELECT * FROM dd_inventory WHERE id=? AND active=1 FOR UPDATE',$item); if(!$it) fail($row.'unknown item.');
        $avail=qm(stock_at($item,$loc,true)); $nm=$it['name'];
        $waste=($l['waste']??'')===''?0:milli($l['waste'],$nm.' waste',true); $reason=txt($l['waste_reason']??'',250);
        if($waste>0&&$reason==='') fail($row.$nm.': write why it was wasted.');
        if($waste>0&&intdiv($waste*(int)$it['cost']+500,1000)>$photoAbove&&empty($l['waste_photo_id'])&&!can($u,'close')) fail($row.$nm.': waste above ₹'.rupees($photoAbove).' needs a photo.');
        $noUse=txt($l['no_usage_reason']??'',250);
        if($mode==='direct'){
            $used=($l['used']??'')===''?null:milli($l['used'],$nm.' used',true);
            if($used===null&&$waste===0&&$noUse==='') fail($row.$nm.': enter quantity used, or mark “no usage” with a reason. A blank is not zero.');
            $used??=0; if($used===0&&$waste===0&&$noUse==='') fail($row.$nm.': quantity is 0 — mark “no usage” with a reason instead.');
            if($used+$waste>$avail) fail($row.$nm.': only '.m3($avail).' '.$it['unit'].' is at this location. Ask the store to issue (transfer) first — usage cannot make stock negative.');
            $um=$used?stock_move(['item_id'=>$item,'qty'=>m3($used),'from'=>$loc,'kind'=>'consume','date'=>$d,'reason'=>'Kitchen usage '.$no,'reference'=>$no],$u):null;
            $wm=$waste?stock_move(['item_id'=>$item,'qty'=>m3($waste),'from'=>$loc,'kind'=>'wastage','date'=>$d,'reason'=>'Waste '.$no.': '.$reason,'reference'=>$no,'proof'=>!empty($l['waste_photo_id'])?(int)$l['waste_photo_id']:null],$u):null;
            $uv=$um?(int)row('SELECT cost_value FROM dd_stock_moves WHERE id=?',$um)['cost_value']:0; $wv=$wm?(int)row('SELECT cost_value FROM dd_stock_moves WHERE id=?',$wm)['cost_value']:0;
            $counted=null;$expected=null;$var=null;$countId=null;
            if(isset($l['counted'])&&$l['counted']!==''){ $counted=milli($l['counted'],$nm.' closing count',true); $expected=$avail-$used-$waste; $var=$counted-$expected;
                if($var!==0){ run('INSERT INTO dd_stock_counts(date,item_id,location_id,system_qty,counted_qty,difference,reason,status,counted_by) VALUES(?,?,?,?,?,?,?,?,?)',$d,$item,$loc,m3($expected),m3($counted),m3($var),'Closing count after usage '.$no.'. '.txt($l['count_reason']??'',200),'pending',$u['id']);
                    $countId=(int)db()->lastInsertId(); $res['variances']++;
                    inbox_raise('stock-difference','store','Unexplained '.($var<0?'shortage':'excess').': '.$nm.' '.m3($var).' '.$it['unit'].' at closing ('.$d.')',['ref_table'=>'dd_stock_counts','ref_id'=>$countId,'date'=>$d,'by'=>$u['id'],'priority'=>'high','detail'=>'Expected '.m3($expected).', counted '.m3($counted).'. Not booked as consumption — approve to adjust stock or reject.']); } }
            run('INSERT INTO dd_stock_consumption_lines(consumption_id,business_date,location_id,item_id,available_qty,used_qty,waste_qty,waste_reason,counted_qty,expected_close,variance_qty,unit_cost,used_value,waste_value,used_move_id,waste_move_id,count_id,status,no_usage_reason) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                $cid,$d,$loc,$item,m3($avail),m3($used),m3($waste),$reason?:null,$counted===null?null:m3($counted),$expected===null?null:m3($expected),$var===null?null:m3($var),(int)$it['cost'],$uv,$wv,$um,$wm,$countId,($used||$waste)?'posted':'no-usage',$noUse?:null);
            $res['posted']++; $res['used_value']+=$uv; $res['waste_value']+=$wv;
        } else {
            if(!isset($l['counted'])||$l['counted']==='') fail($row.$nm.': enter the closing count (what is physically left).');
            $counted=milli($l['counted'],$nm.' closing count',true); if($counted+$waste>$avail) fail($row.$nm.': closing count + waste ('.m3($counted+$waste).') is more than available '.m3($avail).' '.$it['unit'].'. Check receipts/transfers or record a stock count instead.');
            $derived=$avail-$waste-$counted;
            run('INSERT INTO dd_stock_consumption_lines(consumption_id,business_date,location_id,item_id,available_qty,used_qty,waste_qty,waste_reason,counted_qty,expected_close,unit_cost,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                $cid,$d,$loc,$item,m3($avail),m3($derived),m3($waste),$reason?:null,m3($counted),m3($avail-$waste),(int)$it['cost'],'review');
            $res['review']++;
        }
    }
    if($mode==='closing') inbox_raise('usage-review','kitchen','Kitchen usage by closing count '.$no.' ('.$d.') needs approval',['ref_table'=>'dd_stock_consumptions','ref_id'=>$cid,'date'=>$d,'by'=>$u['id'],'dedupe'=>'usage-'.$cid,'detail'=>'Usage was calculated from closing counts. Approve only if no stock is missing for other reasons.']);
    audit($u,'usage.submit',['id'=>$cid,'no'=>$no,'mode'=>$mode,'date'=>$d,'location'=>$loc]+$res);
    return ['ok'=>true,'id'=>$cid,'consumption_no'=>$no,'status'=>$status]+$res;
}
function usage_decide(int $cid,bool $approve,array $u,string $note): array {
    $c=row("SELECT * FROM dd_stock_consumptions WHERE id=? AND status='review' FOR UPDATE",$cid); if(!$c) fail('Usage sheet waiting for approval not found.');
    if((int)$c['created_by']===(int)$u['id']&&!can($u,'*')) fail('You cannot approve your own usage sheet.',403);
    if(!can($u,'approve')&&!can($u,'*')) fail('Only a manager/owner can approve.',403); month_lock_check($c['business_date']);
    $vals=[0,0];
    if($approve){ foreach(rows("SELECT * FROM dd_stock_consumption_lines WHERE consumption_id=? AND status='review' FOR UPDATE",$cid) as $l){
        $used=qm($l['used_qty']); $waste=qm($l['waste_qty']); $have=qm(stock_at((int)$l['item_id'],(int)$l['location_id'],true));
        if($used+$waste>$have) fail('Stock changed since the count (item #'.$l['item_id'].' now '.m3($have).'). Ask the kitchen to recount.');
        $um=$used?stock_move(['item_id'=>(int)$l['item_id'],'qty'=>m3($used),'from'=>(int)$l['location_id'],'kind'=>'consume','date'=>$c['business_date'],'reason'=>'Kitchen usage (closing count) '.$c['consumption_no'],'reference'=>$c['consumption_no']],$u):null;
        $wm=$waste?stock_move(['item_id'=>(int)$l['item_id'],'qty'=>m3($waste),'from'=>(int)$l['location_id'],'kind'=>'wastage','date'=>$c['business_date'],'reason'=>'Waste '.$c['consumption_no'].': '.($l['waste_reason']??''),'reference'=>$c['consumption_no']],$u):null;
        $uv=$um?(int)row('SELECT cost_value FROM dd_stock_moves WHERE id=?',$um)['cost_value']:0; $wv=$wm?(int)row('SELECT cost_value FROM dd_stock_moves WHERE id=?',$wm)['cost_value']:0; $vals[0]+=$uv; $vals[1]+=$wv;
        run("UPDATE dd_stock_consumption_lines SET used_move_id=?,waste_move_id=?,used_value=?,waste_value=?,status=? WHERE id=?",$um,$wm,$uv,$wv,($used||$waste)?'posted':'no-usage',$l['id']); } }
    else run("UPDATE dd_stock_consumption_lines SET status='rejected' WHERE consumption_id=?",$cid);
    run('UPDATE dd_stock_consumptions SET status=?,decided_by=?,decided_at=?,decision_note=? WHERE id=?',$approve?'posted':'rejected',$u['id'],now_sql(),$note,$cid);
    inbox_close_for('dd_stock_consumptions',$cid,$approve?'approved':'rejected',$u,$note); audit($u,'usage.'.($approve?'approve':'reject'),['id'=>$cid,'used_value'=>$vals[0],'waste_value'=>$vals[1]]);
    return ['ok'=>true,'used_value'=>$vals[0],'waste_value'=>$vals[1]];
}
/** Monthly consumption: whole-restaurant movement + per-kitchen usage + purchased vs paid vs consumed. */
function consumption_month(string $month): array {
    [$from,$to]=month_bounds($month); $mv=stock_movement($from,$to,null);
    [$pf,$pt]=month_bounds(prev_month($month)); $prev=[]; foreach(stock_movement($pf,$pt,null)['rows'] as $r) $prev[$r['item_id']]=$r;
    $bought=[]; foreach(rows('SELECT bl.item_id,SUM(bl.stock_qty) q,SUM(bl.total) v,MIN(bl.rate) mn,MAX(bl.rate) mx FROM dd_bill_lines bl JOIN dd_bills b ON b.id=bl.bill_id WHERE b.voided=0 AND b.date BETWEEN ? AND ? GROUP BY bl.item_id',$from,$to) as $r) $bought[(int)$r['item_id']]=$r;
    $byLoc=[]; foreach(rows("SELECT m.item_id,l.name loc,SUM(ABS(m.quantity)) q,SUM(m.cost_value) v FROM dd_stock_moves m JOIN dd_locations l ON l.id=m.from_location WHERE m.kind='consume' AND m.date BETWEEN ? AND ? GROUP BY m.item_id,l.name",$from,$to) as $r) $byLoc[(int)$r['item_id']][]=['location'=>$r['loc'],'qty'=>$r['q'],'value'=>(int)$r['v']];
    $days=(int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable(min($to,business_date())))->days+1; $days=max(1,$days);
    foreach($mv['rows'] as &$r){ $id=$r['item_id']; $r['by_location']=$byLoc[$id]??[]; $r['avg_daily_use']=m3(intdiv(qm($r['used']),$days)); $r['prev_used']=$prev[$id]['used']??'0.000'; $r['prev_used_value']=$prev[$id]['used_value']??0;
        $r['bill_qty']=$bought[$id]['q']??'0.000'; $r['bill_value']=(int)($bought[$id]['v']??0); $r['waste_pct']=qm($r['used'])+qm($r['waste'])>0?round(qm($r['waste'])*100/(qm($r['used'])+qm($r['waste'])),1):null; }
    unset($r);
    $groups=[]; foreach($mv['rows'] as $r){ $g=$r['category']?:'Other'; $groups[$g]??=['group'=>$g,'purchased'=>0,'used_value'=>0,'waste_value'=>0,'closing_value'=>0]; $groups[$g]['purchased']+=$r['bill_value']; $groups[$g]['used_value']+=$r['used_value']; $groups[$g]['waste_value']+=$r['waste_value']; $groups[$g]['closing_value']+=$r['closing_value']; }
    $paid=rows("SELECT s.name,s.category,COALESCE(SUM(p.amount),0) paid FROM dd_bill_payments p JOIN dd_bills b ON b.id=p.bill_id JOIN dd_suppliers s ON s.id=b.supplier_id JOIN dd_ledger l ON l.id=p.ledger_id WHERE l.voided=0 AND l.date BETWEEN ? AND ? GROUP BY s.id,s.name,s.category ORDER BY paid DESC",$from,$to);
    $purch=rows("SELECT s.name,COALESCE(SUM(b.amount),0) purchased FROM dd_bills b JOIN dd_suppliers s ON s.id=b.supplier_id WHERE b.voided=0 AND b.kind='invoice' AND b.date BETWEEN ? AND ? GROUP BY s.id,s.name ORDER BY purchased DESC",$from,$to);
    $missing=usage_missing_days($from,min($to,next_date(business_date(),-1)));
    return ['month'=>$month,'days'=>$days,'movement'=>$mv,'groups'=>array_values($groups),'supplierPaid'=>$paid,'supplierPurchased'=>$purch,'missingUsage'=>$missing,
      'note'=>'Purchased ₹ (bills), Paid ₹ (supplier payments) and Consumed ₹ (kitchen usage) are different numbers and are never added together.'];
}
/** Days in range where a daily-tracked item had stock in a kitchen but no usage line (not even "no usage"). */
function usage_missing_days(string $from,string $to): array {
    if($to<$from) return []; $out=[]; $kitchens=rows("SELECT id,name FROM dd_locations WHERE kind='kitchen' AND active=1"); $tracked=rows('SELECT id,name FROM dd_inventory WHERE active=1 AND track_daily=1');
    if(!$tracked) return [];
    $have=[]; foreach(rows('SELECT DISTINCT business_date,location_id,item_id FROM dd_stock_consumption_lines WHERE business_date BETWEEN ? AND ? AND status<>\'rejected\'',$from,$to) as $r) $have[$r['business_date'].'|'.$r['location_id'].'|'.$r['item_id']]=1;
    foreach($kitchens as $k){ $bal=loc_balances((int)$k['id']); $after=loc_net((int)$k['id'],$from,null);
        // Only flag items that were ever stocked in this kitchen during/after the period
        foreach($tracked as $t){ $tid=(int)$t['id']; if(empty($bal[$tid])&&empty($after[$tid])&&!row('SELECT 1 FROM dd_stock_moves WHERE item_id=? AND (to_location=? OR from_location=?) LIMIT 1',$tid,$k['id'],$k['id'])) continue;
            for($d=$from;$d<=$to;$d=next_date($d)) if(!isset($have[$d.'|'.$k['id'].'|'.$tid])) $out[]=['date'=>$d,'location'=>$k['name'],'item'=>$t['name']]; } }
    return array_slice($out,0,500);
}

/* ============================== PAYROLL EXCEL IMPORT ============================== */
function norm_head(string $h): string { return strtolower(trim(preg_replace('/\s+/',' ',str_replace(["\xc2\xa0",'_'],' ',$h)))); }
function clean_emp_name(string $n): array { $st=null; if(preg_match('/\((left|inactive)\)/i',$n,$m)) $st=strtolower($m[1]); $c=trim(preg_replace('/\s+/',' ',preg_replace('/\((left|inactive)\)/i','',$n))); return [$c,$st]; }
function name_key(string $n): string { return strtolower(preg_replace('/[^a-z0-9\x{0900}-\x{097F}]+/u','',clean_emp_name($n)[0])); }
function sheet_money(string $v,string $label): int { $s=trim(str_replace([',','₹',' '],'',$v)); if($s===''||$s==='-') return 0; if(!preg_match('/^-?\d+(\.\d+)?$/',$s)) fail($label.' is not a number ("'.$v.'").');
    $neg=$s[0]==='-'; $s=ltrim($s,'-'); [$w,$f]=array_pad(explode('.',$s,2),2,''); $f=str_pad($f,3,'0'); $p=(int)$w*100+(int)substr($f,0,2)+((int)$f[2]>=5?1:0); if($p>10000000000) fail($label.' is too large.'); return $neg?-$p:$p; }
function sheet_days(string $v,string $label): string { $s=trim($v); if($s===''||$s==='-') return '0'; if(!is_numeric($s)||(float)$s<0||(float)$s>31) fail($label.' must be 0–31.'); return rtrim(rtrim(number_format((float)$s,2,'.',''),'0'),'.'); }

/** Parse an uploaded payroll workbook (xlsx or csv). Returns header map + normalized rows with row-level issues. Never writes payroll. */
function payroll_parse(string $data,string $fileName): array {
    $isX=substr($data,0,2)==='PK';
    if($isX){ require_once __DIR__.'/xlsx360.php'; $x=xlsx_read($data); $rows=$x['rows']; $sheet=$x['sheet']; }
    else { require_once __DIR__.'/imports.php'; if(!mb_check_encoding($data,'UTF-8')) fail('CSV must be UTF-8.'); $rows=parse_csv($data); $sheet='CSV'; }
    // Find the header row (first row containing "employee id" and "employee name" within the first 10 rows)
    $hi=null; foreach(array_slice($rows,0,10,true) as $i=>$r){ $n=array_map('norm_head',$r); if(in_array('employee id',$n,true)||in_array('emp id',$n,true)){ $hi=$i; break; } }
    if($hi===null) fail('Header row with “Employee ID” not found in the first 10 rows.');
    $head=array_map('norm_head',$rows[$hi]); $map=[]; $unknown=[];
    foreach($head as $ci=>$h){ if($h==='') continue; $hit=null; foreach(PAYROLL_HEADERS as $k=>$al) if(in_array($h,$al,true)){ $hit=$k; break; }
        if($hit===null){ $unknown[]=$h; continue; } if(isset($map[$hit])) fail('Column “'.$rows[$hi][$ci].'” appears twice.'); $map[$hit]=$ci; }
    foreach(['external_id','name','to_pay'] as $req) if(!isset($map[$req])) fail('Required column missing: '.PAYROLL_HEADERS[$req][0].'.');
    $out=[]; $seen=[];
    foreach(array_slice($rows,$hi+1,null,true) as $ri=>$r){
        $g=fn(string $k)=>isset($map[$k])?trim((string)($r[$map[$k]]??'')):'';
        if(trim(implode('',$r))==='') continue; $issues=[]; $mapped=[];
        $ext=preg_replace('/\.0+$/','',$g('external_id')); $name=$g('name');
        if($ext===''&&$name==='') continue; if(preg_match('/^total/i',$name)||preg_match('/^total/i',$ext)) continue;
        if($ext===''||!preg_match('/^[A-Za-z0-9\/_.-]{1,64}$/',$ext)) $issues[]='Invalid or missing Employee ID';
        if($name==='') $issues[]='Missing employee name';
        foreach(PAYROLL_MONEY as $k){ if(!isset($map[$k])) { $mapped[$k]=null; continue; } try{ $mapped[$k]=sheet_money($g($k),PAYROLL_HEADERS[$k][0]); }catch(RuntimeException $e){ $issues[]=$e->getMessage(); $mapped[$k]=null; } }
        foreach(PAYROLL_DAYS as $k){ if(!isset($map[$k])) { $mapped[$k]=null; continue; } try{ $mapped[$k]=sheet_days($g($k),PAYROLL_HEADERS[$k][0]); }catch(RuntimeException $e){ $issues[]=$e->getMessage(); $mapped[$k]=null; } }
        foreach(['gross_wages','overtime','extras','gross_earnings','to_pay','penalties','loan_advance'] as $k) if(($mapped[$k]??0)<0) $issues[]=PAYROLL_HEADERS[$k][0].' is negative';
        if(($mapped['to_pay']??0)>0||isset($map['gross_earnings'])){ $ge=$mapped['gross_earnings']??null; if($ge!==null&&$mapped['to_pay']!==null){ $calc=$ge-($mapped['loan_advance']??0)+($mapped['arrears']??0); if(abs($calc-$mapped['to_pay'])>setting_int('payroll_sheet_tolerance',100)) $issues[]='Sheet check: Gross Earnings − Loan & Advance + Arrears = ₹'.rupees($calc).' but To Pay = ₹'.rupees($mapped['to_pay']); } }
        $acct=$g('bank_account'); $mapped['bank_account_last4']=$acct!==''?substr(preg_replace('/\D/','',$acct),-4):null; // full number is never stored
        foreach(['payment_method','bank_name','ifsc','bank_branch','account_type'] as $k) $mapped[$k]=$g($k)!==''?txt($g($k),80):null;
        $mapped['pf_present']=$g('pf')!==''&&$g('pf')!=='-'; $mapped['uan_present']=$g('uan')!==''&&$g('uan')!=='-'; $mapped['esic_present']=$g('esic')!==''&&$g('esic')!=='-';
        if($ext!==''&&isset($seen[$ext])){ $issues[]='Duplicate Employee ID in this file (also row '.$seen[$ext].')'; } $seen[$ext]=$ri+1;
        [$clean,$st]=clean_emp_name($name);
        $out[]=['row_no'=>$ri+1,'external_id'=>$ext,'source_name'=>txt($name,160),'clean_name'=>txt($clean,160),'source_status'=>$st,'department'=>txt($g('department'),80)?:null,'designation'=>txt($g('designation'),80)?:null,'mapped'=>$mapped,'issues'=>$issues];
    }
    // Mark the earlier duplicate too
    $dups=array_count_values(array_column($out,'external_id')); foreach($out as &$o) if(($dups[$o['external_id']]??0)>1&&!preg_grep('/Duplicate Employee ID/',$o['issues'])) $o['issues'][]='Duplicate Employee ID in this file'; unset($o);
    if(!$out) fail('No employee rows found under the header.'); if(count($out)>1000) fail('More than 1,000 employee rows. Split the file.');
    return ['sheet'=>$sheet,'headers'=>array_keys($map),'unknown_headers'=>$unknown,'rows'=>$out];
}
function payroll_match(array $r): array {
    if($r['external_id']!==''&&($m=row("SELECT i.employee_id,e.name,e.status,e.active FROM dd_employee_identifiers i JOIN dd_employees e ON e.id=i.employee_id WHERE i.source='payroll_excel' AND i.external_id=?",$r['external_id'])))
        return ['kind'=>'mapped','employee_id'=>(int)$m['employee_id'],'candidates'=>[['id'=>(int)$m['employee_id'],'name'=>$m['name'],'status'=>$m['status']]]];
    $k=name_key($r['source_name']); $c=[]; if($k!=='') foreach(rows('SELECT id,name,status,department,designation,job FROM dd_employees') as $e) if(name_key($e['name'])===$k) $c[]=['id'=>(int)$e['id'],'name'=>$e['name'],'status'=>$e['status'],'job'=>$e['designation']?:$e['job']];
    return ['kind'=>count($c)===1?'name-suggest':(count($c)>1?'ambiguous':'none'),'employee_id'=>count($c)===1?$c[0]['id']:null,'candidates'=>$c];
}
function payroll_import_preview(array $b,array $u): array {
    allowed($u,'payroll'); $ck=client_key($b); if(!$ck) fail('Submission key missing.');
    if($x=row('SELECT id FROM dd_payroll_import_batches WHERE client_key=?',$ck)) return payroll_batch_view((int)$x['id']);
    $period=valid_month($b['period']??''); $data=base64_decode((string)($b['data']??''),true); if($data===false||$data==='') fail('Choose the payroll file.');
    $fname=txt($b['name']??'payroll',160); $p=payroll_parse($data,$fname); $sha=hash('sha256',$data);
    // Keep the original file private (never public URL). Bank numbers stay only inside this protected file.
    $dir=storage_dir('uploads/payroll'); if(!is_dir($dir)&&!mkdir($dir,0750,true)) fail('Upload folder is not writable.',503);
    $path='payroll/'.bin2hex(random_bytes(12)).(substr($data,0,2)==='PK'?'.xlsx':'.csv'); file_put_contents(storage_dir('uploads').'/'.$path,$data);
    $title=$p['sheet']; $warn=[]; if(preg_match('/(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*/i',$title,$mm)){ $mon=(int)date('n',strtotime('1 '.$mm[1].' 2000')); if($mon!==(int)substr($period,5,2)) $warn[]='Sheet title “'.$title.'” mentions '.ucfirst(strtolower($mm[1])).' but you chose '.$period.'. Check the period.'; }
    if(row("SELECT id FROM dd_payroll_import_batches WHERE file_sha256=? AND period=? AND status='confirmed'",$sha,$period)) $warn[]='This exact file was already imported for '.$period.'. Rows that are unchanged will be skipped.';
    run('INSERT INTO dd_payroll_import_batches(client_key,period,file_name,file_sha256,file_path,sheet_name,total_rows,created_by,result) VALUES(?,?,?,?,?,?,?,?,?)',$ck,$period,$fname,$sha,$path,txt($title,120),count($p['rows']),$u['id'],json_encode(['warnings'=>$warn,'unknown_headers'=>$p['unknown_headers'],'headers'=>$p['headers']],JSON_UNESCAPED_UNICODE));
    $bid=(int)db()->lastInsertId();
    foreach($p['rows'] as $r){ $m=payroll_match($r);
        run('INSERT INTO dd_payroll_import_rows(batch_id,row_no,external_id,source_name,clean_name,source_status,department,designation,mapped,row_hash,match_employee_id,match_kind,issues) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
            $bid,$r['row_no'],$r['external_id'],$r['source_name'],$r['clean_name'],$r['source_status'],$r['department'],$r['designation'],json_encode($r['mapped'],JSON_UNESCAPED_UNICODE),hash('sha256',json_encode([$r['external_id'],$r['mapped']])),$m['employee_id'],$m['kind'],$r['issues']?json_encode($r['issues'],JSON_UNESCAPED_UNICODE):null); }
    audit($u,'payroll.import.preview',['batch'=>$bid,'period'=>$period,'rows'=>count($p['rows']),'sha'=>$sha]);
    return payroll_batch_view($bid);
}
/** Row status shown before confirmation (computed from the database, not guessed). */
function payroll_row_state(array $r,string $period,?int $emp): array {
    $issues=$r['issues']?json_decode($r['issues'],true):[]; $m=json_decode($r['mapped'],true);
    if($issues&&array_filter($issues,fn($i)=>!str_starts_with($i,'Sheet check'))) return ['state'=>'invalid','text'=>'Fix the file: '.implode('; ',$issues)];
    if(!$emp) return ['state'=>'needs-match','text'=>$r['match_kind']==='ambiguous'?'Several employees have this name — choose one':($r['match_kind']==='name-suggest'?'Suggested by name — confirm or choose':'No match — link existing, create new, or skip')];
    $p=row('SELECT * FROM dd_payroll WHERE employee_id=? AND month=?',$emp,$period);
    if($p&&$p['approved']) return ['state'=>'protected','text'=>'Payroll '.$period.' already APPROVED'.(salary_paid($emp,$period)?' and (part) paid':'').' — protected; use an adjustment','payroll_id'=>(int)$p['id']];
    $calc=payroll_from_sheet($m,$emp,$period);
    if($p){ $same=(int)$p['base']===$calc['base']&&(int)$p['overtime']===$calc['overtime']&&(int)$p['bonus']===$calc['bonus']&&(int)$p['deductions']===$calc['deductions']&&(int)$p['advance']===$calc['advance'];
        return $same?['state'=>'already','text'=>'Already imported — no change','payroll_id'=>(int)$p['id']]:['state'=>'draft-diff','text'=>'Draft exists with different numbers (net ₹'.rupees((int)$p['net']).' → ₹'.rupees($calc['net']).'). Tick “Replace draft” to update.','payroll_id'=>(int)$p['id'],'calc'=>$calc]; }
    return ['state'=>'ready','text'=>'Import as DRAFT payroll (not approved, not paid)','calc'=>$calc];
}
/** Sheet → dd_payroll components. Advance recovery is capped to advances actually paid in the CRM (never double-deducted). */
function payroll_from_sheet(array $m,int $emp,string $period): array {
    $base=(int)($m['gross_wages']??0); $ot=(int)($m['overtime']??0); $bonus=(int)($m['extras']??0)+(int)($m['arrears']??0); $ded=(int)($m['penalties']??0);
    if($m['gross_wages']===null&&$m['gross_earnings']!==null){ $base=(int)$m['gross_earnings']-$ot-(int)($m['extras']??0)+$ded; }
    $gross=$base+$ot+$bonus; $sheetAdv=(int)($m['loan_advance']??0); $recoverable=advance_recoverable($emp,$period);
    $adv=min($sheetAdv,$recoverable,max(0,$gross-$ded)); $net=$gross-$ded-$adv;
    $note=[]; if($sheetAdv!==$adv) $note[]='Sheet deducts advance ₹'.rupees($sheetAdv).' but CRM has ₹'.rupees($recoverable).' paid & unrecovered — recovered ₹'.rupees($adv).'. Reconcile before approval.';
    if($m['to_pay']!==null&&$net!==(int)$m['to_pay']) $note[]='CRM net ₹'.rupees($net).' differs from sheet To Pay ₹'.rupees((int)$m['to_pay']).'.';
    return ['base'=>$base,'overtime'=>$ot,'bonus'=>$bonus,'deductions'=>$ded,'advance'=>$adv,'gross'=>$gross,'net'=>$net,'sheet_advance'=>$sheetAdv,'recoverable'=>$recoverable,'note'=>implode(' ',$note)];
}
function payroll_batch_view(int $bid): array {
    $b=row('SELECT * FROM dd_payroll_import_batches WHERE id=?',$bid); if(!$b) fail('Import not found.',404);
    $emps=rows('SELECT id,name,status,department,designation,job FROM dd_employees ORDER BY active DESC,name');
    $out=[]; $sum=['rows'=>0,'matched'=>0,'needs'=>0,'ready'=>0,'already'=>0,'protected'=>0,'invalid'=>0,'diff'=>0,'to_pay'=>0,'crm_net'=>0];
    foreach(rows('SELECT * FROM dd_payroll_import_rows WHERE batch_id=? ORDER BY row_no',$bid) as $r){
        $emp=$r['result_employee_id']?(int)$r['result_employee_id']:($r['match_employee_id']?(int)$r['match_employee_id']:null);
        $st=$b['status']==='confirmed'?['state'=>$r['status'],'text'=>$r['status']]:payroll_row_state($r,$b['period'],$r['match_kind']==='mapped'?$emp:null);
        $m=json_decode($r['mapped'],true); $sum['rows']++; $sum['to_pay']+=(int)($m['to_pay']??0); if(isset($st['calc'])) $sum['crm_net']+=$st['calc']['net'];
        $k=['needs-match'=>'needs','ready'=>'ready','already'=>'already','protected'=>'protected','invalid'=>'invalid','draft-diff'=>'diff'][$st['state']]??null; if($k) $sum[$k]++; if($r['match_kind']==='mapped') $sum['matched']++;
        unset($m['bank_account_last4']);
        $out[]=['id'=>(int)$r['id'],'row_no'=>(int)$r['row_no'],'external_id'=>$r['external_id'],'name'=>$r['source_name'],'clean_name'=>$r['clean_name'],'source_status'=>$r['source_status'],'department'=>$r['department'],'designation'=>$r['designation'],
          'to_pay'=>(int)($m['to_pay']??0),'gross_earnings'=>$m['gross_earnings'],'loan_advance'=>$m['loan_advance'],'paid_days'=>$m['paid_days'],'match_kind'=>$r['match_kind'],'match_employee_id'=>$r['match_employee_id']?(int)$r['match_employee_id']:null,
          'issues'=>$r['issues']?json_decode($r['issues'],true):[],'state'=>$st['state'],'state_text'=>$st['text'],'calc'=>$st['calc']??null,'action'=>$r['action'],'result_payroll_id'=>$r['result_payroll_id']?(int)$r['result_payroll_id']:null];
    }
    return ['batch'=>['id'=>(int)$b['id'],'period'=>$b['period'],'file_name'=>$b['file_name'],'sheet'=>$b['sheet_name'],'status'=>$b['status'],'created_at'=>$b['created_at'],'meta'=>json_decode((string)$b['result'],true)],'summary'=>$sum,'rows'=>$out,'employees'=>$emps,
      'notice'=>'This import creates or updates DRAFT payroll only. It never pays salary, never creates advances or Cash OUT, and never approves payroll.'];
}
/** Confirm reviewed rows. decisions[row_id] = {action: link|create|skip|replace, employee_id?, pay_basis?, salary?, name?} */
function payroll_import_confirm(array $b,array $u): array {
    allowed($u,'payroll'); $bid=(int)($b['batch_id']??0); $ck=client_key($b); if(!$ck) fail('Submission key missing.');
    $batch=row('SELECT * FROM dd_payroll_import_batches WHERE id=? FOR UPDATE',$bid); if(!$batch) fail('Import not found.',404);
    if($batch['status']==='confirmed'){ if($batch['confirm_key']===$ck) return ['ok'=>true,'duplicate'=>true]+json_decode((string)$batch['result'],true); fail('This import was already confirmed. Upload the file again to start a new review.',409); }
    $dec=$b['decisions']??[]; if(!is_array($dec)) fail('Invalid decisions.'); $period=$batch['period'];
    $res=['linked'=>0,'created'=>0,'imported'=>0,'replaced'=>0,'skipped'=>0,'already'=>0,'protected'=>0,'invalid'=>0,'needs_review'=>0]; $usedEmp=[];
    $rows=rows('SELECT * FROM dd_payroll_import_rows WHERE batch_id=? ORDER BY row_no FOR UPDATE',$bid);
    // Pass 1: resolve identities and detect two rows → one employee.
    $plan=[]; foreach($rows as $r){ $d=$dec[$r['id']]??$dec[(string)$r['id']]??null; $act=is_array($d)?(string)($d['action']??''):'';
        $issues=$r['issues']?json_decode($r['issues'],true):[]; if($issues&&array_filter($issues,fn($i)=>!str_starts_with($i,'Sheet check'))){ $plan[$r['id']]=['status'=>'invalid']; continue; }
        if($act==='skip'||$act===''){ if($act===''&&$r['match_kind']!=='mapped'){ $plan[$r['id']]=['status'=>'needs-review']; continue; } if($act==='skip'){ $plan[$r['id']]=['status'=>'skipped']; continue; } }
        $emp=null;
        if($r['match_kind']==='mapped'&&($act===''||$act==='link'||$act==='replace')) $emp=(int)$r['match_employee_id'];
        if($act==='link'||$act==='replace'){ $want=(int)($d['employee_id']??$emp??0); if(!$want||!row('SELECT id FROM dd_employees WHERE id=?',$want)) fail('Row '.$r['row_no'].': choose the existing employee.');
            $bound=row("SELECT employee_id FROM dd_employee_identifiers WHERE source='payroll_excel' AND external_id=?",$r['external_id']);
            if($bound&&(int)$bound['employee_id']!==$want) fail('Row '.$r['row_no'].': Employee ID '.$r['external_id'].' is already linked to another CRM employee (#'.$bound['employee_id'].'). Resolve the mapping conflict first.',409);
            $other=row("SELECT external_id FROM dd_employee_identifiers WHERE source='payroll_excel' AND employee_id=? AND external_id<>?",$want,$r['external_id']);
            if($other) fail('Row '.$r['row_no'].': this CRM employee is already linked to payroll ID '.$other['external_id'].'. Two payroll IDs cannot point to one person.',409);
            $emp=$want; }
        if($act==='create'){ if(!can($u,'*')&&!can($u,'manage')) fail('Only the owner can create new employees from an import.',403); $emp=-1; }
        if($emp>0){ if(isset($usedEmp[$emp])) fail('Rows '.$usedEmp[$emp].' and '.$r['row_no'].' point to the same employee for '.$period.'. Resolve before importing.',409); $usedEmp[$emp]=$r['row_no']; }
        $plan[$r['id']]=['emp'=>$emp,'act'=>$act?:'link','d'=>$d];
    }
    // Pass 2: write (all inside the caller's transaction).
    foreach($rows as $r){ $p=$plan[$r['id']];
        if(isset($p['status'])){ $k=['invalid'=>'invalid','needs-review'=>'needs_review','skipped'=>'skipped'][$p['status']]; $res[$k]++; run('UPDATE dd_payroll_import_rows SET status=?,action=? WHERE id=?',$p['status'],$p['status']==='skipped'?'skip':null,$r['id']); continue; }
        $emp=$p['emp']; $m=json_decode($r['mapped'],true);
        if($emp===-1){ $d=$p['d']; $nm=name_required($d['name']??$r['clean_name']); $basis=in_array($d['pay_basis']??'',['monthly','daily'],true)?$d['pay_basis']:'monthly'; $sal=isset($d['salary'])&&$d['salary']!==''?money($d['salary']):(int)($basis==='daily'?($m['daily_wage']??0):($m['gross_wages']??0));
            $status=$r['source_status']==='left'?'left':($r['source_status']==='inactive'?'inactive':'active');
            run('INSERT INTO dd_employees(name,job,salary,pay_basis,active,department,designation,status,created_by) VALUES(?,?,?,?,?,?,?,?,?)',$nm,$r['designation'],$sal,$basis,$status==='active'?1:0,$r['department'],$r['designation'],$status,$u['id']);
            $emp=(int)db()->lastInsertId(); $res['created']++; audit($u,'employee.create.import',['id'=>$emp,'batch'=>$bid,'row'=>$r['row_no'],'external_id'=>$r['external_id']]); }
        if(!row("SELECT id FROM dd_employee_identifiers WHERE source='payroll_excel' AND external_id=?",$r['external_id'])){ run("INSERT INTO dd_employee_identifiers(source,external_id,employee_id,verified_by) VALUES('payroll_excel',?,?,?)",$r['external_id'],$emp,$u['id']); $res['linked']++; }
        row('SELECT id FROM dd_employees WHERE id=? FOR UPDATE',$emp);
        $st=payroll_row_state($r,$period,$emp);
        if($st['state']==='protected'){ $res['protected']++; run("UPDATE dd_payroll_import_rows SET status='protected',result_employee_id=?,result_payroll_id=? WHERE id=?",$emp,$st['payroll_id'],$r['id']); continue; }
        if($st['state']==='already'){ $res['already']++; run("UPDATE dd_payroll_import_rows SET status='already',result_employee_id=?,result_payroll_id=? WHERE id=?",$emp,$st['payroll_id'],$r['id']); continue; }
        if($st['state']==='draft-diff'&&$p['act']!=='replace'){ $res['needs_review']++; run("UPDATE dd_payroll_import_rows SET status='needs-review',result_employee_id=? WHERE id=?",$emp,$r['id']); continue; }
        $c=payroll_from_sheet($m,$emp,$period); $pd=$m['paid_days']!==null?number_format((float)$m['paid_days'],2,'.',''):null;
        if($st['state']==='draft-diff'){ $old=row('SELECT * FROM dd_payroll WHERE id=?',$st['payroll_id']);
            run("UPDATE dd_payroll SET base=?,overtime=?,bonus=?,deductions=?,advance=?,gross=?,net=?,paid_days=?,source='import',note=? WHERE id=? AND approved=0",$c['base'],$c['overtime'],$c['bonus'],$c['deductions'],$c['advance'],$c['gross'],$c['net'],$pd,txt($c['note'],250),$st['payroll_id']);
            $pid=$st['payroll_id']; $res['replaced']++; audit($u,'payroll.import.replace',['payroll_id'=>$pid,'old'=>$old,'new'=>$c,'batch'=>$bid]); }
        else { run("INSERT INTO dd_payroll(employee_id,month,base,overtime,bonus,deductions,advance,gross,net,approved,paid_days,source,note) VALUES(?,?,?,?,?,?,?,?,?,0,?,'import',?)",$emp,$period,$c['base'],$c['overtime'],$c['bonus'],$c['deductions'],$c['advance'],$c['gross'],$c['net'],$pd,txt($c['note'],250)); $pid=(int)db()->lastInsertId(); $res['imported']++; }
        unset($m['bank_account_last4']);
        run('INSERT INTO dd_payroll_details(payroll_id,batch_id,import_row_id,external_id,source,sheet_to_pay,sheet_advance,crm_recoverable,reconcile_note) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE batch_id=VALUES(batch_id),import_row_id=VALUES(import_row_id),source=VALUES(source),sheet_to_pay=VALUES(sheet_to_pay),sheet_advance=VALUES(sheet_advance),crm_recoverable=VALUES(crm_recoverable),reconcile_note=VALUES(reconcile_note),updated_at=CURRENT_TIMESTAMP',
            $pid,$bid,$r['id'],$r['external_id'],json_encode($m,JSON_UNESCAPED_UNICODE),$m['to_pay'],$c['sheet_advance'],$c['recoverable'],$c['note']?:null);
        run("UPDATE dd_payroll_import_rows SET status='imported',action=?,result_employee_id=?,result_payroll_id=? WHERE id=?",$p['act'],$emp,$pid,$r['id']);
    }
    run("UPDATE dd_payroll_import_batches SET status='confirmed',confirm_key=?,confirmed_by=?,confirmed_at=?,result=? WHERE id=?",$ck,$u['id'],now_sql(),json_encode($res),$bid);
    audit($u,'payroll.import.confirm',['batch'=>$bid,'period'=>$period]+$res);
    return ['ok'=>true]+$res;
}

/* ============================== EMPLOYEES ============================== */
function employees_list(string $month): array {
    $ids=[]; foreach(rows("SELECT employee_id,GROUP_CONCAT(external_id) ext FROM dd_employee_identifiers GROUP BY employee_id") as $r) $ids[(int)$r['employee_id']]=$r['ext'];
    $out=[]; foreach(rows('SELECT * FROM dd_employees ORDER BY active DESC,name') as $e){ $id=(int)$e['id']; $p=row('SELECT id,net,approved,source,advance FROM dd_payroll WHERE employee_id=? AND month=?',$id,$month); $paid=salary_paid($id,$month);
        $out[]=['id'=>$id,'code'=>$e['code'],'name'=>$e['name'],'job'=>$e['job'],'department'=>$e['department'],'designation'=>$e['designation'],'status'=>$e['status'],'active'=>(int)$e['active'],'pay_basis'=>$e['pay_basis'],'salary'=>(int)$e['salary'],
          'external_ids'=>$ids[$id]??null,'advance_outstanding'=>advance_outstanding($id),'payroll'=>$p?['id'=>(int)$p['id'],'net'=>(int)$p['net'],'approved'=>(int)$p['approved'],'source'=>$p['source'],'recovery'=>(int)$p['advance']]:null,'paid'=>$paid,'balance'=>$p&&$p['approved']?(int)$p['net']-$paid:null]; }
    return $out;
}
function employee_save(array $b,array $u): int {
    allowed($u,'payroll'); $id=(int)($b['id']??0); $status=in_array($b['status']??'active',['active','inactive','left'],true)?$b['status']:'active';
    $basis=in_array($b['pay_basis']??'monthly',['monthly','daily'],true)?$b['pay_basis']:'monthly';
    $v=[name_required($b['name']??''),txt($b['job']??'',100),money($b['salary']??0),$basis,$status==='active'?1:0,txt($b['code']??'',40)?:null,txt($b['department']??'',80)?:null,txt($b['designation']??'',80)?:null,$status,!empty($b['joined_on'])?valid_date($b['joined_on']):null,$status==='left'&&!empty($b['left_on'])?valid_date($b['left_on']):null];
    if($id){ $old=row('SELECT * FROM dd_employees WHERE id=? FOR UPDATE',$id); if(!$old) fail('Employee not found.');
        run('UPDATE dd_employees SET name=?,job=?,salary=?,pay_basis=?,active=?,code=?,department=?,designation=?,status=?,joined_on=?,left_on=? WHERE id=?',...[...$v,$id]); audit($u,'employee.update',['id'=>$id,'old'=>$old,'new'=>$v]); }
    else { $dupe=array_filter(rows('SELECT id,name FROM dd_employees'),fn($e)=>name_key($e['name'])===name_key($v[0])); if($dupe&&empty($b['confirm_duplicate'])) fail('An employee with this name already exists (#'.implode(', #',array_column($dupe,'id')).'). Tick “different person” to add anyway.',409);
        run('INSERT INTO dd_employees(name,job,salary,pay_basis,active,code,department,designation,status,joined_on,left_on,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',...[...$v,$u['id']]); $id=(int)db()->lastInsertId(); audit($u,'employee.create',['id'=>$id,'name'=>$v[0]]); }
    if(isset($b['external_id'])&&trim((string)$b['external_id'])!==''){ $ext=txt($b['external_id'],64); $bound=row("SELECT employee_id FROM dd_employee_identifiers WHERE source='payroll_excel' AND external_id=?",$ext);
        if($bound&&(int)$bound['employee_id']!==$id) fail('Payroll ID '.$ext.' already belongs to employee #'.$bound['employee_id'].'.',409);
        if(!$bound){ if(row("SELECT id FROM dd_employee_identifiers WHERE source='payroll_excel' AND employee_id=?",$id)) fail('This employee already has a payroll ID. Remove it first (owner).',409); run("INSERT INTO dd_employee_identifiers(source,external_id,employee_id,verified_by) VALUES('payroll_excel',?,?,?)",$ext,$id,$u['id']); } }
    return $id;
}
function payslip(int $pid,array $u): array {
    $p=row('SELECT p.*,e.name,e.designation,e.department,e.job FROM dd_payroll p JOIN dd_employees e ON e.id=p.employee_id WHERE p.id=?',$pid); if(!$p) fail('Payslip not found.',404);
    if(!can($u,'payroll')&&(int)($u['employee_id']??0)!==(int)$p['employee_id']) fail('Payslip not found.',404); // no other employee's slip, no ID probing
    $paid=salary_paid((int)$p['employee_id'],$p['month']); $d=row('SELECT sheet_to_pay,sheet_advance,reconcile_note FROM dd_payroll_details WHERE payroll_id=?',$pid);
    return ['restaurant'=>setting('restaurant','Khadak Singh Da Dhaba'),'employee'=>$p['name'],'designation'=>$p['designation']?:$p['job'],'month'=>$p['month'],'paid_days'=>$p['paid_days'],'base'=>(int)$p['base'],'overtime'=>(int)$p['overtime'],'bonus'=>(int)$p['bonus'],'deductions'=>(int)$p['deductions'],
      'advance_recovery'=>(int)$p['advance'],'gross'=>(int)$p['gross'],'net'=>(int)$p['net'],'approved'=>(int)$p['approved'],'paid'=>$paid,'due'=>$p['approved']?(int)$p['net']-$paid:null,'advance_outstanding_after'=>advance_outstanding((int)$p['employee_id']),'note'=>$p['note']];
}

/* ============================== GUIDED CASH OUT WIZARD ============================== */
function wizard_context(array $u): array {
    if(!can($u,'voucher.request')&&!can($u,'cash.entry')&&!can($u,'*')) fail('Not allowed.',403);
    $d=business_date(); $pay=can($u,'payroll');
    $emps=array_map(fn($e)=>['id'=>(int)$e['id'],'name'=>$e['name'],'job'=>$e['designation']?:$e['job']]+($pay?['advance_outstanding'=>advance_outstanding((int)$e['id'])]:[]),rows("SELECT id,name,job,designation FROM dd_employees WHERE active=1 ORDER BY name"));
    $sal=[]; foreach(rows('SELECT p.employee_id,p.month,p.net FROM dd_payroll p JOIN dd_employees e ON e.id=p.employee_id WHERE p.approved=1 ORDER BY p.month DESC LIMIT 400') as $p){ $rem=(int)$p['net']-salary_paid((int)$p['employee_id'],$p['month'])-salary_pending((int)$p['employee_id'],$p['month']); if($rem>0) $sal[]=['employee_id'=>(int)$p['employee_id'],'month'=>$p['month'],'remaining'=>$rem]+($pay?['net'=>(int)$p['net']]:[]); }
    $sups=array_map(fn($s)=>['id'=>(int)$s['id'],'name'=>$s['name'],'balance'=>$s['balance'],'overdue'=>$s['overdue'],'unpaid'=>$s['unpaid_count']],supplier_balances(null,true));
    $obl=rows("SELECT o.id,o.period,o.actual_amount,o.expected_amount,o.due_date,o.bill_ref,o.status,t.name,t.category,t.payee FROM dd_recurring_obligations o JOIN dd_recurring_templates t ON t.id=o.template_id WHERE o.status='billed' ORDER BY o.due_date");
    $c=day_cash($d)['summary'];
    return ['date'=>$d,'employees'=>$emps,'salaries'=>$sal,'suppliers'=>$sups,'obligations'=>$obl,'expected_cash'=>$c['expectedCash'],'direct_limit'=>setting_int('cash_out_direct_limit',100000),'proof_above'=>setting_int('proof_required_above',50000),
      'advance_limit'=>setting_int('advance_request_limit',0),'categories'=>CASH_OUT_CATEGORIES,'can'=>['pay_now'=>can($u,'cash.entry'),'owner'=>can($u,'*'),'payroll'=>$pay,'request'=>can($u,'voucher.request')||can($u,'*')]];
}
function wizard_bills(int $sid,array $u): array {
    if(!can($u,'voucher.request')&&!can($u,'vendor')&&!can($u,'*')) fail('Not allowed.',403);
    $s=row('SELECT id,name FROM dd_suppliers WHERE id=? AND active=1',$sid); if(!$s) fail('Supplier not found.');
    $bills=[]; foreach(rows("SELECT id,reference,date,due_date,amount,paid,credited,status,kind FROM dd_bills WHERE supplier_id=? AND voided=0 AND status<>'paid' ORDER BY due_date,id",$sid) as $b){ $pend=pending_alloc((int)$b['id']); $out=(int)$b['amount']-(int)$b['paid']-(int)$b['credited'];
        $bills[]=['id'=>(int)$b['id'],'reference'=>$b['reference'],'date'=>$b['date'],'due_date'=>$b['due_date'],'amount'=>(int)$b['amount'],'outstanding'=>$out,'requested'=>$pend,'available'=>max(0,$out-$pend),'kind'=>$b['kind']]; }
    return ['supplier'=>$s,'bills'=>$bills,'as_of'=>gmdate('c')];
}
/** One entry point for every guided purpose. Requests create vouchers only (no money moves). Direct pay only for petty cash within limit. */
function wizard_submit(array $b,array $u): array {
    $purpose=(string)($b['purpose']??''); $ck=client_key($b);
    if(!$ck) fail('Submission key missing. Refresh and try again.');
    $req=fn()=>can($u,'voucher.request')||can($u,'*');
    switch($purpose){
    case 'advance': if(!$req()) fail('Your role cannot request advances.',403);
        $lim=setting_int('advance_request_limit',0); $amt=money($b['amount']??null); if($lim&&$amt>$lim&&!can($u,'*')) fail('Advance above ₹'.rupees($lim).' needs the owner.',403);
        if(!txt($b['note']??'')) fail('Write the reason for the advance.');
        return voucher_create(['client_key'=>$ck,'kind'=>'advance','employee_id'=>$b['employee_id']??0,'payroll_month'=>$b['payroll_month']??substr(business_date(),0,7),'amount'=>$b['amount']??null,'note'=>$b['note']??'','proof_file_id'=>$b['proof_file_id']??null],$u)+['next'=>'Waiting for approval. Cash does not change until the cashier pays.'];
    case 'salary': if(!$req()) fail('Your role cannot request salary payments.',403);
        return voucher_create(['client_key'=>$ck,'kind'=>'salary','employee_id'=>$b['employee_id']??0,'payroll_month'=>$b['payroll_month']??'','amount'=>$b['amount']??null,'note'=>$b['note']??'','proof_file_id'=>$b['proof_file_id']??null],$u)+['next'=>'Waiting for approval. Payroll stays unpaid until the cashier records the actual payment.'];
    case 'supplier': if(!$req()&&!can($u,'vendor')&&!can($u,'bill.submit')) fail('Your role cannot request supplier payments.',403);
        $al=$b['allocations']??[]; if(!is_array($al)||!$al) fail('Tick at least one bill. Supplier payments always settle specific bills.');
        $sum=0; $clean=[]; foreach($al as $a){ $p=money($a['amount']??null); if(!$p) continue; $clean[]=['bill_id'=>(int)($a['bill_id']??0),'amount'=>rupees($p)]; $sum+=$p; } if(!$clean) fail('Enter the amount for the ticked bills.');
        return voucher_create(['client_key'=>$ck,'kind'=>'supplier','supplier_id'=>$b['supplier_id']??0,'allocations'=>$clean,'amount'=>rupees($sum),'note'=>$b['note']??'','proof_file_id'=>$b['proof_file_id']??null],$u)+['next'=>'Requested. Bills stay unpaid until approval AND actual payment.'];
    case 'recurring': if(!$req()) fail('Not allowed.',403); return recurring_request((int)($b['obligation_id']??0),$u,$ck);
    case 'petty': // small daily cash expense. Direct pay only within limit for cash-drawer roles; otherwise request.
        $cat=(string)($b['category']??''); if(!isset(CASH_OUT_CATEGORIES[$cat])) fail('Choose what it was for.');
        if(preg_match('/salary|advance|supplier|owner/i',$cat.' '.($b['paid_to']??''))) fail('Salary, advance, supplier and owner payments have their own steps. Go back and choose that type.');
        $amt=money($b['amount']??null); if(!$amt) fail('Amount must be greater than zero.');
        if(($b['mode']??'')==='pay_now'){ allowed($u,'cash.entry'); if(empty($b['handed_over'])) fail('Confirm that the cash was actually handed over.');
            return post_cash(['client_key'=>$ck,'amount'=>$b['amount'],'category'=>$cat,'paid_to'=>$b['paid_to']??'','note'=>$b['note']??'','reference'=>$b['reference']??'','proof_file_id'=>$b['proof_file_id']??null,'confirm_duplicate'=>$b['confirm_duplicate']??null],$u,'cash-out')+['next'=>'Paid from cash drawer.']; }
        if(!$req()&&!can($u,'expense.submit')) fail('Not allowed.',403);
        return voucher_create(['client_key'=>$ck,'kind'=>'expense','amount'=>$b['amount'],'category'=>$cat,'payee'=>$b['paid_to']??'','note'=>$b['note']??'','proof_file_id'=>$b['proof_file_id']??null],$u)+['next'=>'Sent for approval.'];
    case 'other': if(!$req()&&!can($u,'expense.submit')) fail('Not allowed.',403); $why=txt($b['note']??''); if(mb_strlen($why)<8) fail('Write a clear reason (at least 8 characters) for “Other”.');
        if(preg_match('/\b(salary|advance|supplier|vendor|owner draw|drawing)\b/i',$why.' '.($b['paid_to']??''))) fail('This looks like a salary / advance / supplier / owner payment. Use that type so it links to the right record.');
        return voucher_create(['client_key'=>$ck,'kind'=>'other','amount'=>$b['amount']??null,'category'=>txt($b['category']??'Other',80)?:'Other','payee'=>$b['paid_to']??'','note'=>$why,'proof_file_id'=>$b['proof_file_id']??null],$u)+['next'=>'Sent for owner review.'];
    case 'owner-draw': case 'transfer': allowed($u,'manage');
        if($x=row('SELECT id,receipt_no FROM dd_ledger WHERE source_key=?','wiz-'.$ck)) return ['ok'=>true,'duplicate'=>true,'id'=>(int)$x['id']];
        $acct=(string)($b['account']??'Cash'); $amt=money($b['amount']??null); if(!$amt) fail('Amount must be greater than zero.'); $d=business_date(); if($acct==='Cash') day_lock_open($d);
        if($purpose==='owner-draw'){ $id=add_entry(['date'=>$d,'name'=>txt($b['paid_to']??'Owner',160)?:'Owner','type'=>'payment-out','account'=>$acct,'amount'=>rupees($amt),'category'=>'Owner drawing','reference'=>txt($b['reference']??'',120),'description'=>txt($b['note']??''),'source'=>'wizard','source_key'=>'wiz-'.$ck],$u); }
        else { $to=(string)($b['to_account']??''); $type=$acct==='Cash'?'deposit':($to==='Cash'?'withdrawal':'deposit');
            $id=add_entry(['date'=>$d,'name'=>'Transfer '.$acct.' → '.$to,'type'=>$type,'account'=>$acct,'to_account'=>$to,'amount'=>rupees($amt),'category'=>'Transfer','reference'=>txt($b['reference']??'',120),'description'=>txt($b['note']??''),'source'=>'wizard','source_key'=>'wiz-'.$ck],$u); }
        run('UPDATE dd_ledger SET receipt_no=?,proof_file_id=? WHERE id=?',seq('PAY',$d),check_file(isset($b['proof_file_id'])?(int)$b['proof_file_id']:null,$u),$id);
        return ['ok'=>true,'id'=>$id,'next'=>$purpose==='owner-draw'?'Recorded as owner drawing (not a restaurant expense).':'Two-sided transfer recorded (not an expense or sale).'];
    }
    fail('Choose what the payment is for.');
}

/* ============================== RECURRING MONTHLY BILLS ============================== */
function recurring_due_date(array $t,string $period): string { [$f,$l]=month_bounds($period); $day=min(max(1,(int)$t['due_day']),(int)substr($l,8,2)); return $period.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT); }
function recurring_applies(array $t,string $period): bool {
    if(!$t['active']||$period<$t['start_month']||($t['end_month']&&$period>$t['end_month'])) return false;
    $diff=((int)substr($period,0,4)-(int)substr($t['start_month'],0,4))*12+((int)substr($period,5,2)-(int)substr($t['start_month'],5,2));
    return match($t['frequency']){ 'quarterly'=>$diff%3===0, 'yearly'=>$diff%12===0, default=>true };
}
/** Creates the expected obligation once per (template, period). Never creates a payable or a payment. */
function recurring_generate(string $period,?array $u): int {
    valid_month($period); $n=0;
    foreach(rows('SELECT * FROM dd_recurring_templates WHERE active=1') as $t){ if(!recurring_applies($t,$period)) continue;
        $exp=$t['amount_mode']==='variable'?(int)(row('SELECT actual_amount n FROM dd_recurring_obligations WHERE template_id=? AND actual_amount IS NOT NULL ORDER BY period DESC LIMIT 1',$t['id'])['n']??$t['amount']):(int)$t['amount'];
        $st=db()->prepare("INSERT IGNORE INTO dd_recurring_obligations(template_id,period,expected_amount,due_date,status) VALUES(?,?,?,?,'expected')"); $st->execute([$t['id'],$period,$exp,recurring_due_date($t,$period)]); $n+=$st->rowCount(); }
    if($n) audit($u,'recurring.generate',['period'=>$period,'created'=>$n]); return $n;
}
function recurring_save(array $b,array $u): int {
    if(!can($u,'approve')&&!can($u,'*')) fail('Only owner/manager/accounts can edit recurring bills.',403); $id=(int)($b['id']??0);
    $mode=in_array($b['amount_mode']??'',['fixed','variable','estimate'],true)?$b['amount_mode']:'variable'; $freq=in_array($b['frequency']??'',['monthly','quarterly','yearly'],true)?$b['frequency']:'monthly';
    $due=filter_var($b['due_day']??10,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>31]]); if($due===false) fail('Due day must be 1–31.');
    $cat=name_required($b['category']??''); if(preg_match('/salary|advance|supplier payment/i',$cat)) fail('Salary, advances and supplier bills have their own modules.');
    $v=[name_required($b['name']??''),$cat,name_required($b['payee']??''),!empty($b['supplier_id'])?(int)$b['supplier_id']:null,$mode,money($b['amount']??0),$freq,$due,valid_month($b['start_month']??substr(business_date(),0,7)),!empty($b['end_month'])?valid_month($b['end_month']):null,max(0,(int)($b['reminder_days']??3)),!empty($b['proof_required'])?1:0,txt($b['contract_ref']??'',120)?:null,isset($b['active'])&&!$b['active']?0:1];
    // Effective-dated by design: editing a template never rewrites obligations already created (history keeps the old numbers).
    if($id){ $old=row('SELECT * FROM dd_recurring_templates WHERE id=? FOR UPDATE',$id); if(!$old) fail('Template not found.'); run('UPDATE dd_recurring_templates SET name=?,category=?,payee=?,supplier_id=?,amount_mode=?,amount=?,frequency=?,due_day=?,start_month=?,end_month=?,reminder_days=?,proof_required=?,contract_ref=?,active=? WHERE id=?',...[...$v,$id]); audit($u,'recurring.update',['id'=>$id,'old'=>$old]); }
    else { run('INSERT INTO dd_recurring_templates(name,category,payee,supplier_id,amount_mode,amount,frequency,due_day,start_month,end_month,reminder_days,proof_required,contract_ref,active,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',...[...$v,$u['id']]); $id=(int)db()->lastInsertId(); audit($u,'recurring.create',['id'=>$id]); }
    return $id;
}
function recurring_bill(array $b,array $u): void {
    if(!can($u,'approve')&&!can($u,'vendor')&&!can($u,'*')) fail('Not allowed.',403);
    $o=row('SELECT o.*,t.proof_required,t.name FROM dd_recurring_obligations o JOIN dd_recurring_templates t ON t.id=o.template_id WHERE o.id=? FOR UPDATE',(int)($b['obligation_id']??0)); if(!$o) fail('Expected bill not found.');
    if(!in_array($o['status'],['expected','billed'],true)) fail('This bill is already '.$o['status'].'.');
    $amt=money($b['actual_amount']??null); if(!$amt) fail('Enter the actual bill amount.'); $ref=txt($b['bill_ref']??'',120); if($ref==='') fail('Enter the bill / invoice number (or the receipt number).');
    if($x=row('SELECT o.period FROM dd_recurring_obligations o WHERE o.template_id=? AND o.bill_ref=? AND o.id<>?',$o['template_id'],$ref,$o['id'])) fail('Bill number '.$ref.' is already recorded for '.$x['period'].'. Duplicate not added.',409);
    $proof=check_file(isset($b['proof_file_id'])&&$b['proof_file_id']!==''?(int)$b['proof_file_id']:null,$u); if($o['proof_required']&&!$proof&&!$o['proof_file_id']) fail('Attach the bill photo.');
    run("UPDATE dd_recurring_obligations SET actual_amount=?,bill_ref=?,bill_date=?,due_date=?,proof_file_id=COALESCE(?,proof_file_id),status='billed',note=? WHERE id=?",$amt,$ref,!empty($b['bill_date'])?valid_date($b['bill_date']):business_date(),!empty($b['due_date'])?valid_date($b['due_date']):$o['due_date'],$proof,txt($b['note']??''),$o['id']);
    audit($u,'recurring.bill',['id'=>$o['id'],'amount'=>$amt,'ref'=>$ref]);
}
function recurring_request(int $oid,array $u,?string $ck=null): array {
    $o=row('SELECT o.*,t.name,t.category,t.payee FROM dd_recurring_obligations o JOIN dd_recurring_templates t ON t.id=o.template_id WHERE o.id=? FOR UPDATE',$oid); if(!$o) fail('Bill not found.');
    if($o['status']==='requested'&&$o['voucher_id']) return ['ok'=>true,'duplicate'=>true,'id'=>(int)$o['voucher_id']];
    if($o['status']!=='billed') fail($o['status']==='expected'?'Enter the actual bill amount and number first.':'This bill is already '.$o['status'].'.');
    $v=voucher_create(['client_key'=>$ck??('obl'.$oid.'x'.bin2hex(random_bytes(4))),'kind'=>'expense','amount'=>rupees((int)$o['actual_amount']),'category'=>$o['category'],'payee'=>$o['payee'],'note'=>$o['name'].' '.$o['period'].' bill '.$o['bill_ref'],'proof_file_id'=>$o['proof_file_id']],$u);
    run('UPDATE dd_vouchers SET obligation_id=? WHERE id=?',$oid,$v['id']); run("UPDATE dd_recurring_obligations SET status='requested',voucher_id=? WHERE id=?",$v['id'],$oid);
    return $v+['next'=>'Payment requested. It is paid only when the cashier records the actual payment.'];
}
function recurring_view(string $month): array {
    $t=rows('SELECT t.*,s.name supplier FROM dd_recurring_templates t LEFT JOIN dd_suppliers s ON s.id=t.supplier_id ORDER BY t.active DESC,t.name');
    $o=rows("SELECT o.*,t.name,t.category,t.payee,v.status voucher_status,v.voucher_no FROM dd_recurring_obligations o JOIN dd_recurring_templates t ON t.id=o.template_id LEFT JOIN dd_vouchers v ON v.id=o.voucher_id WHERE o.period BETWEEN ? AND ? OR o.status IN ('expected','billed','requested') ORDER BY o.due_date",prev_month(prev_month($month)),$month);
    return ['month'=>$month,'templates'=>$t,'obligations'=>$o,'today'=>business_date()];
}

/* ============================== BANK STATEMENT MATCHING ============================== */
function bank_date(string $v): string {
    $v=trim($v); foreach(['!Y-m-d','!d/m/Y','!d-m-Y','!d.m.Y','!d/m/y','!d-m-y','!d-M-Y','!d M Y','!d-M-y','!d M y','!j/n/Y','!Y/m/d'] as $f){ $d=DateTimeImmutable::createFromFormat($f,$v); if($d&&$d->format(ltrim($f,'!'))===$v) return $d->format('Y-m-d'); if($d&&!DateTimeImmutable::getLastErrors()) return $d->format('Y-m-d'); }
    if(is_numeric($v)&&(float)$v>30000&&(float)$v<80000) return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int)$v.' day')->format('Y-m-d'); // Excel serial date
    fail('Unrecognised date “'.$v.'”. Use DD/MM/YYYY or YYYY-MM-DD.');
}
function bank_amount(string $v): int { $s=trim(str_replace([',','₹',' ','INR'],'',$v)); if($s===''||$s==='-') return 0; $neg=false; if(preg_match('/(dr|cr)$/i',$s,$m)){ $neg=strtolower($m[1])==='dr'; $s=substr($s,0,-2); } if(str_starts_with($s,'(')&&str_ends_with($s,')')){ $neg=true; $s=trim($s,'()'); } if(str_starts_with($s,'-')){ $neg=!$neg; $s=substr($s,1); } $p=money($s); return $neg?-$p:$p; }
function bank_import(array $b,array $u): array {
    allowed($u,'close'); require_once __DIR__.'/imports.php'; $acct=(string)($b['account']??''); if(!in_array($acct,BANK_ACCOUNTS,true)) fail('Choose the bank / payment account.');
    $csv=(string)($b['csv']??''); if(strlen($csv)>2000000) fail('Statement is larger than 2 MB.'); $data=parse_csv($csv); if(count($data)<2) fail('Statement needs a header row and at least one line.');
    $head=array_map('norm_head',array_shift($data)); $map=$b['mapping']??[]; $col=function(string $k,array $al)use($head,$map){ if(isset($map[$k])&&$map[$k]!=='') { $i=array_search(norm_head((string)$map[$k]),$head,true); if($i===false) fail('Column “'.$map[$k].'” not in the file.'); return $i; } foreach($al as $a){ $i=array_search($a,$head,true); if($i!==false) return $i; } return null; };
    $c=['date'=>$col('date',['date','txn date','transaction date','value date','tran date']),'desc'=>$col('description',['description','narration','particulars','remarks','details']),'ref'=>$col('reference',['reference','ref no','chq/ref no','cheque no','utr','ref no./cheque no.','transaction id']),
        'debit'=>$col('debit',['debit','withdrawal','withdrawal amt','withdrawals','dr']),'credit'=>$col('credit',['credit','deposit','deposit amt','deposits','cr']),'amount'=>$col('amount',['amount','amt']),'balance'=>$col('balance',['balance','closing balance'])];
    if($c['date']===null||$c['desc']===null||($c['amount']===null&&($c['debit']===null||$c['credit']===null))) fail('Map the Date, Description and either Amount or Debit+Credit columns.');
    $lines=[]; $occ=[];
    foreach($data as $i=>$r){ $g=fn($k)=>$c[$k]===null?'':trim((string)($r[$c[$k]]??'')); if($g('date')===''&&$g('desc')==='') continue;
        try{ $dt=bank_date($g('date')); if($c['amount']!==null){ $a=bank_amount($g('amount')); $dr=$a<0?-$a:0; $cr=$a>0?$a:0; } else { $dr=abs(bank_amount($g('debit'))); $cr=abs(bank_amount($g('credit'))); } }
        catch(RuntimeException $e){ fail('Row '.($i+2).': '.$e->getMessage()); }
        if(!$dr&&!$cr) continue; if($dr&&$cr) fail('Row '.($i+2).': a line cannot be both debit and credit.');
        $desc=txt($g('desc'),250); $ref=txt($g('ref'),120); $base=$acct.'|'.$dt.'|'.($cr-$dr).'|'.strtolower($ref).'|'.strtolower(preg_replace('/\s+/',' ',$desc));
        $occ[$base]=($occ[$base]??0)+1; // identical lines inside one statement stay distinct; re-importing the same statement yields the same fingerprints
        $lines[]=['date'=>$dt,'desc'=>$desc,'ref'=>$ref?:null,'debit'=>$dr,'credit'=>$cr,'balance'=>$c['balance']!==null&&$g('balance')!==''?bank_amount($g('balance')):null,'fp'=>hash('sha256',$base.'|#'.$occ[$base])]; }
    $new=array_values(array_filter($lines,fn($l)=>!row('SELECT id FROM dd_bank_lines WHERE fingerprint=?',$l['fp'])));
    if(!empty($b['preview'])) return ['ok'=>true,'preview'=>true,'total'=>count($lines),'new'=>count($new),'duplicates'=>count($lines)-count($new),'sample'=>array_slice($new,0,30)];
    run('INSERT INTO dd_bank_imports(account,file_sha256,added,skipped,created_by) VALUES(?,?,?,?,?)',$acct,hash('sha256',$csv),count($new),count($lines)-count($new),$u['id']); $iid=(int)db()->lastInsertId();
    foreach($new as $l) run('INSERT INTO dd_bank_lines(import_id,account,txn_date,description,reference,debit,credit,balance,fingerprint) VALUES(?,?,?,?,?,?,?,?,?)',$iid,$acct,$l['date'],$l['desc'],$l['ref'],$l['debit'],$l['credit'],$l['balance'],$l['fp']);
    audit($u,'bank.import',['id'=>$iid,'account'=>$acct,'added'=>count($new)]); $auto=bank_automatch($acct,$u);
    return ['ok'=>true,'added'=>count($new),'duplicates'=>count($lines)-count($new),'auto_matched'=>$auto];
}
/** Ledger rows on $acct with the same direction and amount; excludes rows already matched. Inflow = credit on the statement. */
function bank_candidates(array $l,int $days=3): array {
    $amt=$l['credit']?:$l['debit']; $in=(int)$l['credit']>0; $acct=$l['account'];
    $sql=$in?"SELECT * FROM dd_ledger WHERE voided=0 AND amount=? AND date BETWEEN ? AND ? AND ((account=? AND type IN ('sale','payment-in')) OR (to_account=? AND type IN ('deposit','withdrawal','settlement')))"
            :"SELECT * FROM dd_ledger WHERE voided=0 AND amount=? AND date BETWEEN ? AND ? AND account=? AND type NOT IN ('sale','payment-in') AND (to_account IS NULL OR to_account<>?)";
    $c=rows($sql.' AND id NOT IN (SELECT ledger_id FROM dd_bank_lines WHERE ledger_id IS NOT NULL) ORDER BY ABS(DATEDIFF(date,?)),id LIMIT 10',$amt,next_date($l['txn_date'],-$days),next_date($l['txn_date'],$days),$acct,$acct,$l['txn_date']);
    if($l['reference']) usort($c,fn($a,$b)=>(int)(stripos((string)$b['reference'],(string)$l['reference'])!==false)-(int)(stripos((string)$a['reference'],(string)$l['reference'])!==false));
    return $c;
}
/** Auto-match only when exactly one candidate exists AND that candidate fits exactly one statement line (no guessing). */
function bank_automatch(string $acct,array $u): int {
    $n=0; $days=setting_int('bank_match_days',3); $claimed=[];
    foreach(rows("SELECT * FROM dd_bank_lines WHERE account=? AND status='unmatched' ORDER BY txn_date",$acct) as $l){ $c=bank_candidates($l,$days); if(count($c)!==1) continue; $lid=(int)$c[0]['id'];
        if(isset($claimed[$lid])) { $claimed[$lid]=-1; continue; } $claimed[$lid]=(int)$l['id']; }
    foreach($claimed as $lid=>$line){ if($line<0) continue; run("UPDATE dd_bank_lines SET ledger_id=?,status='matched',matched_by=?,matched_at=?,note='auto: unique amount/date match' WHERE id=? AND status='unmatched'",$lid,$u['id'],now_sql(),$line); $n++; }
    return $n;
}
function bank_view(string $acct,string $status): array {
    if(!in_array($acct,BANK_ACCOUNTS,true)) $acct='Bank of India'; $st=in_array($status,['unmatched','matched','ignored','all'],true)?$status:'unmatched';
    $lines=rows('SELECT b.*,l.date ledger_date,l.name ledger_name,l.type ledger_type,l.receipt_no FROM dd_bank_lines b LEFT JOIN dd_ledger l ON l.id=b.ledger_id WHERE b.account=?'.($st==='all'?'':' AND b.status=?').' ORDER BY b.txn_date DESC,b.id DESC LIMIT 300',...($st==='all'?[$acct]:[$acct,$st]));
    $days=setting_int('bank_match_days',3); foreach($lines as &$l) if($l['status']==='unmatched') $l['candidates']=array_map(fn($c)=>['id'=>(int)$c['id'],'date'=>$c['date'],'name'=>$c['name'],'type'=>$c['type'],'amount'=>(int)$c['amount'],'reference'=>$c['reference']],bank_candidates($l,$days)); unset($l);
    $unmatchedLedger=rows("SELECT id,date,name,type,amount,reference FROM dd_ledger WHERE voided=0 AND (account=? OR to_account=?) AND date>=? AND id NOT IN (SELECT ledger_id FROM dd_bank_lines WHERE ledger_id IS NOT NULL) ORDER BY date DESC LIMIT 100",$acct,$acct,next_date(business_date(),-45));
    $sum=row("SELECT SUM(status='unmatched') unmatched,SUM(status='matched') matched,SUM(status='ignored') ignored FROM dd_bank_lines WHERE account=?",$acct);
    return ['account'=>$acct,'status'=>$st,'accounts'=>BANK_ACCOUNTS,'lines'=>$lines,'crm_unmatched'=>$unmatchedLedger,'summary'=>array_map('intval',$sum?:[])];
}
function bank_match(array $b,array $u): void {
    allowed($u,'close'); $l=row('SELECT * FROM dd_bank_lines WHERE id=? FOR UPDATE',(int)($b['line_id']??0)); if(!$l) fail('Statement line not found.');
    $act=(string)($b['act']??'match');
    if($act==='unmatch'){ run("UPDATE dd_bank_lines SET ledger_id=NULL,status='unmatched',note=?,matched_by=?,matched_at=? WHERE id=?",txt($b['note']??'unmatched',250),$u['id'],now_sql(),$l['id']); audit($u,'bank.unmatch',['line'=>$l['id']]); return; }
    if($act==='ignore'){ if($l['ledger_id']!==null) fail('Unmatch this bank line before ignoring it.',409); $n=txt($b['note']??'',250); if($n==='') fail('Write why this line needs no CRM entry (e.g. bank charge already booked, opening balance).'); run("UPDATE dd_bank_lines SET status='ignored',note=?,matched_by=?,matched_at=? WHERE id=?",$n,$u['id'],now_sql(),$l['id']); audit($u,'bank.ignore',['line'=>$l['id'],'note'=>$n]); return; }
    if($l['status']!=='unmatched') fail('This line is already '.$l['status'].'.');
    $lid=(int)($b['ledger_id']??0); $ok=array_filter(bank_candidates($l,30),fn($c)=>(int)$c['id']===$lid); if(!$ok) fail('That CRM entry does not match this line (account, direction and amount must be the same, within 30 days, and not already matched).');
    run("UPDATE dd_bank_lines SET ledger_id=?,status='matched',matched_by=?,matched_at=?,note=? WHERE id=?",$lid,$u['id'],now_sql(),txt($b['note']??'manual match',250),$l['id']); audit($u,'bank.match',['line'=>$l['id'],'ledger'=>$lid]);
}

/* ============================== BUDGETS ============================== */
function budget_save(array $b,array $u): void { allowed($u,'manage'); $m=valid_month($b['month']??''); $lines=$b['lines']??[]; if(!is_array($lines)) fail('Invalid budget.');
    foreach($lines as $cat=>$amt){ $cat=txt($cat,80); if($cat==='') continue; if($amt===''||$amt===null){ run('DELETE FROM dd_budgets WHERE month=? AND category=?',$m,$cat); continue; } run('INSERT INTO dd_budgets(month,category,amount,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP',$m,$cat,money($amt),$u['id']); }
    audit($u,'budget.save',['month'=>$m,'lines'=>$lines]); }

/* ============================== MONTHLY REPORT, P&L AND CLOSE ============================== */
function monthly_report(string $month): array {
    [$from,$to]=month_bounds($month); $today=business_date(); $end=min($to,$today); $nonOp=jsonset('non_operating_categories'); $food=jsonset('direct_food_categories');
    $L=fn(string $sql,...$a)=>sum_int($sql,...$a);
    // Sales (Petpooja imports post one ledger sale per payment mode; manual sales are separate rows) and coverage
    $sales=rows("SELECT account,SUM(amount) n FROM dd_ledger WHERE voided=0 AND type='sale' AND date BETWEEN ? AND ? GROUP BY account",$from,$to);
    $revenue=array_sum(array_map(fn($r)=>(int)$r['n'],$sales)); $refunds=$L("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE voided=0 AND type='refund' AND date BETWEEN ? AND ?",$from,$to);
    $pos=row('SELECT COUNT(*) days,COALESCE(SUM(gross),0) gross,COALESCE(SUM(net),0) net,COALESCE(SUM(discount),0) discount,COALESCE(SUM(tax),0) tax,COALESCE(SUM(cash),0) cash,COALESCE(SUM(upi),0) upi,COALESCE(SUM(card),0) card,COALESCE(SUM(other),0) other FROM dd_pos_days WHERE business_date BETWEEN ? AND ?',$from,$to);
    $daysElapsed=$end<$from?0:(int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($end))->days+1;
    $posMissing=[]; for($d=$from;$d<=$end&&$d<$today;$d=next_date($d)) if(!row('SELECT 1 FROM dd_pos_days WHERE business_date=?',$d)) $posMissing[]=$d;
    $unclosed=[]; for($d=$from;$d<=$end&&$d<$today;$d=next_date($d)){ $s=row('SELECT status FROM dd_days WHERE date=?',$d); if(($s['status']??'open')!=='closed') $unclosed[]=$d; }
    // Stock & COGS
    $mv=stock_movement($from,$to,null); $cogsUsed=$mv['totals']['used_value']; $cogsWaste=$mv['totals']['waste_value'];
    // Expenses from the cash/bank ledger, classified
    $exp=[]; foreach(rows("SELECT category,SUM(amount) n FROM dd_ledger WHERE voided=0 AND type='expense' AND date BETWEEN ? AND ? GROUP BY category ORDER BY n DESC",$from,$to) as $r) $exp[$r['category']?:'Other']=(int)$r['n'];
    $directFood=0; $opex=[]; foreach($exp as $cat=>$n){ if(in_array($cat,$food,true)) $directFood+=$n; elseif(!in_array($cat,$nonOp,true)) $opex[$cat]=$n; }
    $payOut=[]; foreach(rows("SELECT category,SUM(amount) n FROM dd_ledger WHERE voided=0 AND type='payment-out' AND date BETWEEN ? AND ? GROUP BY category",$from,$to) as $r) $payOut[$r['category']?:'Other']=(int)$r['n'];
    $incentives=0; foreach($payOut as $cat=>$n) if(!in_array($cat,$nonOp,true)&&!in_array($cat,['Supplier payment'],true)) { $opex[$cat]=($opex[$cat]??0)+$n; $incentives+=$n; }
    // Payroll (accrual = approved payroll gross for the month; cash = salary paid)
    $pr=row('SELECT COUNT(*) n,COALESCE(SUM(gross),0) gross,COALESCE(SUM(net),0) net,COALESCE(SUM(advance),0) recovered,COALESCE(SUM(approved=1),0) approved,COALESCE(SUM(CASE WHEN approved=1 THEN gross ELSE 0 END),0) approved_gross FROM dd_payroll WHERE month=?',$month);
    $activeEmp=$L('SELECT COUNT(*) n FROM dd_employees WHERE active=1'); $salPaid=$L("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE voided=0 AND type='salary' AND payroll_month=?",$month);
    $advIssued=$L("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE voided=0 AND type='advance' AND date BETWEEN ? AND ?",$from,$to);
    $advOutstanding=max(0,$L("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE voided=0 AND type='advance'")-$L('SELECT COALESCE(SUM(advance),0) n FROM dd_payroll WHERE approved=1'));
    // Purchases vs paid
    $purchased=$L("SELECT COALESCE(SUM(amount),0) n FROM dd_bills WHERE voided=0 AND kind='invoice' AND date BETWEEN ? AND ?",$from,$to);
    $supPaid=$L("SELECT COALESCE(SUM(p.amount),0) n FROM dd_bill_payments p JOIN dd_ledger l ON l.id=p.ledger_id WHERE l.voided=0 AND l.date BETWEEN ? AND ?",$from,$to);
    $ap=row("SELECT COALESCE(SUM(amount-paid-credited),0) total,COALESCE(SUM(CASE WHEN DATEDIFF(?,due_date) BETWEEN 0 AND 7 THEN amount-paid-credited ELSE 0 END),0) a0,COALESCE(SUM(CASE WHEN DATEDIFF(?,due_date) BETWEEN 8 AND 15 THEN amount-paid-credited ELSE 0 END),0) a8,COALESCE(SUM(CASE WHEN DATEDIFF(?,due_date) BETWEEN 16 AND 30 THEN amount-paid-credited ELSE 0 END),0) a16,COALESCE(SUM(CASE WHEN DATEDIFF(?,due_date)>30 THEN amount-paid-credited ELSE 0 END),0) a31,COALESCE(SUM(CASE WHEN due_date>? THEN amount-paid-credited ELSE 0 END),0) notdue FROM dd_bills WHERE voided=0 AND status<>'paid'",$today,$today,$today,$today,$today);
    // Recurring
    $rec=rows("SELECT o.*,t.name,t.category FROM dd_recurring_obligations o JOIN dd_recurring_templates t ON t.id=o.template_id WHERE o.period=? ORDER BY o.due_date",$month);
    $recAccrued=0; foreach($rec as $o) if(in_array($o['status'],['billed','requested','paid'],true)&&!in_array($o['category'],$nonOp,true)) $recAccrued+=(int)$o['actual_amount'];
    $recPaidInLedger=$L("SELECT COALESCE(SUM(l.amount),0) n FROM dd_recurring_obligations o JOIN dd_ledger l ON l.id=o.paid_ledger_id WHERE o.period=? AND l.voided=0 AND l.date BETWEEN ? AND ?",$month,$from,$to);
    $recUnpaidAccrual=$recAccrued-$recPaidInLedger; // billed/requested in this period but cash not yet out in this period → accrual
    // Cash & bank by account
    $acc=[]; foreach(rows('SELECT * FROM dd_ledger WHERE voided=0 AND date BETWEEN ? AND ?',$from,$to) as $e){ $m=movement($e); $a=$e['account']; $acc[$a]??=['in'=>0,'out'=>0]; $acc[$a]['in']+=$m['in']; $acc[$a]['out']+=$m['out'];
        if(in_array($e['type'],['deposit','withdrawal','settlement'],true)){ $acc[$a]['out']+=(int)$e['amount']; $t=(string)$e['to_account']; $acc[$t]??=['in'=>0,'out'=>0]; $acc[$t]['in']+=(int)$e['amount']; } }
    $bankUnmatched=$L("SELECT COUNT(*) n FROM dd_bank_lines WHERE status='unmatched' AND txn_date BETWEEN ? AND ?",$from,$to);
    $variance=row("SELECT COALESCE(SUM(variance),0) v,COUNT(*) n FROM dd_days WHERE date BETWEEN ? AND ? AND variance IS NOT NULL AND variance<>0",$from,$to);
    // Budgets
    $budgets=[]; foreach(rows('SELECT category,amount FROM dd_budgets WHERE month=?',$month) as $r) $budgets[$r['category']]=(int)$r['amount'];
    $actualByCat=$opex; $actualByCat['Payroll']=(int)$pr['approved_gross']; $actualByCat['Food cost (consumed)']=$cogsUsed+$cogsWaste;
    foreach($rec as $o) if($o['status']!=='paid'&&in_array($o['status'],['billed','requested'],true)) $actualByCat[$o['category']]=($actualByCat[$o['category']]??0)+(int)$o['actual_amount'];
    $bva=[]; foreach(array_unique(array_merge(array_keys($budgets),array_keys($actualByCat))) as $cat){ $bud=$budgets[$cat]??null; $act=$actualByCat[$cat]??0; $bva[]=['category'=>$cat,'budget'=>$bud,'actual'=>$act,'variance'=>$bud===null?null:$bud-$act,'status'=>$bud===null?'no budget':($act>$bud?'over':'ok')]; }
    // P&L (policy-defined, accrual-leaning). Payroll uses approved gross; unapproved months are flagged, not guessed.
    $opexTotal=array_sum($opex)+$recUnpaidAccrual; $cogs=$cogsUsed+$cogsWaste+$directFood; $netRevenue=$revenue-$refunds;
    $gp=$netRevenue-$cogs; $op=$gp-(int)$pr['approved_gross']-$opexTotal;
    // Data-quality flags
    $flags=[];
    if($posMissing) $flags[]=['key'=>'pos','severity'=>'high','text'=>count($posMissing).' day(s) without Petpooja import: '.implode(', ',array_slice($posMissing,0,10))];
    if($unclosed) $flags[]=['key'=>'days','severity'=>'high','text'=>count($unclosed).' daybook day(s) not closed: '.implode(', ',array_slice($unclosed,0,10))];
    if($mv['unvalued_items']) $flags[]=['key'=>'valuation','severity'=>'high','text'=>$mv['unvalued_items'].' stock item(s) have no verified cost — consumption value is understated'];
    $miss=usage_missing_days($from,min($end,next_date($today,-1))); if($miss) $flags[]=['key'=>'usage','severity'=>'high','text'=>count($miss).' kitchen item-day(s) with no usage entry (daily-tracked items)'];
    if((int)$pr['approved']<$activeEmp) $flags[]=['key'=>'payroll','severity'=>'high','text'=>'Payroll approved for '.(int)$pr['approved'].' of '.$activeEmp.' active employees'];
    $exp_=array_filter($rec,fn($o)=>$o['status']==='expected'); if($exp_) $flags[]=['key'=>'recurring','severity'=>'medium','text'=>count($exp_).' recurring bill(s) not yet received: '.implode(', ',array_column($exp_,'name'))];
    if($bankUnmatched) $flags[]=['key'=>'bank','severity'=>'medium','text'=>$bankUnmatched.' bank statement line(s) unmatched'];
    $holds=$L("SELECT COUNT(*) n FROM dd_goods_receipts WHERE status IN ('hold','returned') AND created_at<=?",$to.' 23:59:59'); if($holds) $flags[]=['key'=>'holds','severity'=>'medium','text'=>$holds.' supplier bill(s) on hold — purchases may be understated'];
    $pendUsage=$L("SELECT COUNT(*) n FROM dd_stock_consumptions WHERE status='review' AND business_date BETWEEN ? AND ?",$from,$to); if($pendUsage) $flags[]=['key'=>'usage-review','severity'=>'medium','text'=>$pendUsage.' usage sheet(s) waiting for approval'];
    $cons=consistency_check(); if($cons) $flags[]=['key'=>'stock-consistency','severity'=>'medium','text'=>count($cons).' item(s) whose location balances do not add up to the item total (legacy entries) — review in Stock'];
    if($end<$to) $flags[]=['key'=>'period-open','severity'=>'info','text'=>'Month in progress — figures up to '.$end];
    $quality=array_filter($flags,fn($f)=>$f['severity']==='high')?'incomplete':($flags&&array_filter($flags,fn($f)=>$f['severity']==='medium')?'review':'verified');
    $close=row('SELECT month,status,quality,closed_at,note,reopen_reason FROM dd_monthly_closures WHERE month=?',$month);
    return ['month'=>$month,'from'=>$from,'to'=>$to,'generated_at'=>gmdate('c'),'basis'=>'Management accounts: accrual-leaning (approved payroll, consumption-based food cost, billed recurring costs). Not a statutory / CA-certified statement.',
      'quality'=>$quality,'flags'=>$flags,'close'=>$close,
      'sales'=>['by_account'=>$sales,'revenue'=>$revenue,'refunds'=>$refunds,'net'=>$netRevenue,'pos'=>$pos,'pos_days'=>(int)$pos['days'],'days_elapsed'=>$daysElapsed],
      'stock'=>['purchase_value'=>$mv['totals']['purchase_value'],'used_value'=>$cogsUsed,'waste_value'=>$cogsWaste,'opening_value'=>$mv['totals']['opening_value'],'closing_value'=>$mv['totals']['closing_value'],'unvalued_items'=>$mv['unvalued_items'],'food_cost_pct'=>$netRevenue>0?round(($cogsUsed+$cogsWaste+$directFood)*100/$netRevenue,1):null],
      'purchases'=>['billed'=>$purchased,'paid'=>$supPaid,'ap_total'=>(int)$ap['total'],'aging_basis'=>'CURRENT open bills as of '.$today.' (not as of month end)','aging'=>['not_due'=>(int)$ap['notdue'],'0-7'=>(int)$ap['a0'],'8-15'=>(int)$ap['a8'],'16-30'=>(int)$ap['a16'],'31+'=>(int)$ap['a31']]],
      'expenses'=>['operating'=>$opex,'direct_food_cash'=>$directFood,'non_operating'=>array_intersect_key($exp+$payOut,array_flip($nonOp)),'incentives'=>$incentives,'recurring_accrued_unpaid'=>$recUnpaidAccrual],
      'payroll'=>['rows'=>(int)$pr['n'],'approved'=>(int)$pr['approved'],'active_employees'=>$activeEmp,'gross_all'=>(int)$pr['gross'],'approved_gross'=>(int)$pr['approved_gross'],'net'=>(int)$pr['net'],'recovered_advances'=>(int)$pr['recovered'],'salary_paid'=>$salPaid,'salary_payable'=>max(0,$L('SELECT COALESCE(SUM(net),0) n FROM dd_payroll WHERE month=? AND approved=1',$month)-$salPaid),'advances_issued'=>$advIssued,'advances_outstanding'=>$advOutstanding],
      'recurring'=>$rec,'cash'=>['by_account'=>$acc,'variance_total'=>(int)$variance['v'],'variance_days'=>(int)$variance['n'],'unclosed_days'=>$unclosed,'bank_unmatched'=>$bankUnmatched],
      'budget'=>$bva,
      'pnl'=>['revenue'=>$revenue,'refunds'=>$refunds,'net_revenue'=>$netRevenue,'cogs_consumed'=>$cogsUsed,'cogs_waste'=>$cogsWaste,'cogs_direct_cash_food'=>$directFood,'cogs'=>$cogs,'gross_profit'=>$gp,'payroll'=>(int)$pr['approved_gross'],'operating_expenses'=>$opexTotal,'operating_profit'=>$op,
          'label'=>$quality==='verified'?'Operating profit':'Operating profit — ESTIMATE ('.$quality.')'],
      'cashflow'=>['cash_in'=>array_sum(array_column($acc,'in')),'cash_out'=>array_sum(array_column($acc,'out'))],
      'exceptions'=>['pos_missing'=>$posMissing,'unclosed_days'=>$unclosed,'usage_missing'=>array_slice($miss,0,100),'stock_consistency'=>$cons]];
}
function monthly_csv(array $r): string {
    $o=[['KSDD monthly report',$r['month'],'Quality: '.$r['quality'],'Generated (UTC) '.$r['generated_at']],[$r['basis']],[]];
    $p=$r['pnl']; foreach([['Revenue (sales)',$p['revenue']],['Refunds',-$p['refunds']],['Net revenue',$p['net_revenue']],['Food consumed (stock)',-$p['cogs_consumed']],['Food wastage',-$p['cogs_waste']],['Direct cash food purchases',-$p['cogs_direct_cash_food']],['Gross profit',$p['gross_profit']],['Payroll (approved gross)',-$p['payroll']],['Operating expenses',-$p['operating_expenses']],[$p['label'],$p['operating_profit']]] as $x) $o[]=[$x[0],rupees($x[1])];
    $o[]=[]; $o[]=['Operating expense category','Amount']; foreach($r['expenses']['operating'] as $k=>$v) $o[]=[$k,rupees($v)];
    $o[]=[]; $o[]=['Data quality flags']; foreach($r['flags'] as $f) $o[]=[$f['severity'],$f['text']];
    $o[]=[]; $o[]=['Budget category','Budget','Actual','Variance','Status']; foreach($r['budget'] as $b) $o[]=[$b['category'],$b['budget']===null?'':rupees($b['budget']),rupees($b['actual']),$b['variance']===null?'':rupees($b['variance']),$b['status']];
    $out="\xEF\xBB\xBF"; foreach($o as $row) $out.=implode(',',array_map('csv_cell',$row))."\r\n"; return $out;
}
function movement_csv(array $mv): string {
    $o=[['Item','Hindi','Unit','Opening','Received','Transfer IN','Transfer OUT','Used','Waste','Supplier return','Count adj.','Closing','Received ₹','Used ₹','Waste ₹','Closing ₹ (est.)','Unit cost ₹']];
    foreach($mv['rows'] as $r) $o[]=[$r['name'],$r['name_hi'],$r['unit'],$r['opening'],$r['purchase'],$r['transfer_in'],$r['transfer_out'],$r['used'],$r['waste'],$r['returned'],$r['adjust'],$r['closing'],rupees($r['purchase_value']),rupees($r['used_value']),rupees($r['waste_value']),rupees($r['closing_value']),$r['valued']?rupees($r['unit_cost']):'NOT VALUED'];
    $out="\xEF\xBB\xBF"; foreach($o as $row) $out.=implode(',',array_map('csv_cell',$row))."\r\n"; return $out;
}
function month_close(array $b,array $u): array {
    allowed($u,'manage'); $m=valid_month($b['month']??''); $lvl=($b['level']??'soft')==='hard'?'hard':'soft'; $note=txt($b['note']??'');
    if($m>=substr(business_date(),0,7)) fail('You can close a month only after it has ended.');
    // Lock the period record FIRST (creating it if absent) so postings that check the period wait for this decision.
    run("INSERT IGNORE INTO dd_monthly_closures(month,status,quality) VALUES(?,'open','pending')",$m);
    $c=row('SELECT * FROM dd_monthly_closures WHERE month=? FOR UPDATE',$m);
    // Fix C04: explicit transitions open→soft→hard. hard→anything only through the owner's reasoned reopen.
    if($c['status']==='hard') fail('Month '.$m.' is hard-closed. Only the owner can reopen it (with a reason); a close cannot downgrade it.',409);
    if($lvl==='hard'&&$c['status']!=='soft') fail('Soft-close the month first and review the owner pack.',409);
    require_once __DIR__.'/gl360.php'; $sync=gl_sync_all($u); // Fix C03: drain ALL sources in batches, not one 5,000-row pass
    $r=monthly_report($m); [$from,$to]=month_bounds($m);
    $pend=gl_pending($to); $r['gl_pending']=$pend; $r['gl_sync']=$sync;
    if($lvl==='hard'){
        $block=gl_blocking($pend);
        if($block) fail('Hard close refused — the GL is not complete for '.$m.': '.implode(', ',array_map(fn($k,$v)=>$v.' '.str_replace('_',' ',$k),array_keys($block),$block)).'. Post, review or reverse these first (Accounts → Update ledger; Posting review). A balanced but incomplete ledger cannot be certified.',409);
        $tb=gl_trial_balance($to); if(!$tb['balanced']) fail('Trial balance does not balance — hard close refused. Investigate the unbalanced journals first.',409); $r['trial_balance']=['debit'=>$tb['total_debit'],'credit'=>$tb['total_credit']];
        $rec=gl_reconcile($to); $r['reconciliation']=$rec; $bad=array_values(array_filter($rec,fn($x)=>!$x['ok']));
        if($bad&&empty($b['accept_reconciliation'])) fail('Control accounts do not reconcile as of '.$to.': '.implode(' · ',array_map(fn($x)=>$x['check'].' (difference ₹'.rupees((int)$x['difference']).')',$bad)).'. Resolve them, or tick “accept listed reconciliation differences” and write why.',409);
        if($bad&&mb_strlen($note)<10) fail('Write why the month is hard-closed with reconciliation differences (kept with the snapshot).');
        if($r['quality']==='incomplete'&&empty($b['accept_incomplete'])) fail('Data is incomplete: '.implode(' · ',array_column(array_filter($r['flags'],fn($f)=>$f['severity']==='high'),'text')).'. Fix these, or tick “close with known gaps” and write why.');
        if($r['quality']==='incomplete'&&mb_strlen($note)<10) fail('Write why the month is being hard-closed with known gaps.'); }
    $json=json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    run('UPDATE dd_monthly_closures SET status=?,quality=?,flags=?,report=?,report_sha256=?,note=?,closed_by=?,closed_at=? WHERE month=?',
        $lvl,$r['quality'],json_encode($r['flags'],JSON_UNESCAPED_UNICODE),$json,hash('sha256',$json),$note,$u['id'],now_sql(),$m);
    audit($u,'month.close',['month'=>$m,'level'=>$lvl,'from'=>$c['status'],'quality'=>$r['quality'],'sha'=>hash('sha256',$json),'pending'=>$pend]); return ['ok'=>true,'month'=>$m,'status'=>$lvl,'quality'=>$r['quality'],'pending'=>$pend];
}
function month_reopen(array $b,array $u): array { if(!can($u,'*')) fail('Only the owner can reopen a closed month.',403); $m=valid_month($b['month']??''); $why=txt($b['reason']??''); if(mb_strlen($why)<10) fail('Write the reason for reopening (kept in the audit log).');
    $c=row('SELECT * FROM dd_monthly_closures WHERE month=? FOR UPDATE',$m); if(!$c||$c['status']==='open') fail('Month is not closed.');
    // The closed snapshot is preserved in the archive before the period opens again (the closure row is reused for the next close).
    if($c['report']){ try{ run('INSERT INTO dd_archive(kind,ref,payload,sha256,reason,created_by) VALUES(?,?,?,?,?,?)','month-close-snapshot',$m.':'.$c['status'],(string)$c['report'],(string)$c['report_sha256'],txt($why,250),$u['id']); }catch(PDOException $e){ if(($e->errorInfo[1]??0)!==1146) throw $e; } }
    run("UPDATE dd_monthly_closures SET status='open',reopened_by=?,reopen_reason=? WHERE month=?",$u['id'],$why,$m); audit($u,'month.reopen',['month'=>$m,'reason'=>$why,'previous'=>$c['status'],'previous_sha'=>$c['report_sha256']]); return ['ok'=>true]; }

/* ============================== OWNER: yesterday guard, cron helpers ============================== */
function fin_yesterday_items(string $y): array {
    $o=[]; try{
        $kitchen=row("SELECT id FROM dd_locations WHERE name='Main Kitchen'"); if($kitchen){ $miss=array_filter(usage_missing_days($y,$y),fn($x)=>$x['location']==='Main Kitchen'); if($miss) $o[]=['key'=>'usage','text'=>'Kitchen usage not entered for '.$y.': '.implode(', ',array_unique(array_column($miss,'item')))]; }
        if($n=sum_int("SELECT COUNT(*) n FROM dd_vouchers WHERE status='approved' AND approved_at<?",gmdate('Y-m-d H:i:s',time()-86400))) $o[]=['key'=>'vouchers','text'=>$n.' approved payment(s) not yet paid for over a day (they are NOT paid)'];
        if($n=sum_int("SELECT COUNT(*) n FROM dd_recurring_obligations WHERE status IN ('expected','billed') AND due_date<?",business_date())) $o[]=['key'=>'recurring','text'=>$n.' recurring bill(s) overdue (rent / electricity / internet …)'];
        if($n=sum_int("SELECT COUNT(*) n FROM dd_stock_consumptions WHERE status='review'")) $o[]=['key'=>'usage-review','text'=>$n.' kitchen usage sheet(s) waiting for approval'];
    }catch(PDOException $e){ if(($e->errorInfo[1]??0)!==1146) throw $e; }
    return $o;
}
function fin_cron(string $d): array { // called from cron.php inside job() wrappers
    return [
     'recurring-generate'=>function()use($d){ $m=substr($d,0,7); $n=recurring_generate($m,null); if((int)substr($d,8,2)>=20) $n+=recurring_generate((new DateTimeImmutable($m.'-01'))->modify('+1 month')->format('Y-m'),null); return ['processed'=>$n]; },
     'recurring-reminders'=>function()use($d){ $n=0; foreach(rows("SELECT o.*,t.name,t.reminder_days FROM dd_recurring_obligations o JOIN dd_recurring_templates t ON t.id=o.template_id WHERE o.status IN ('expected','billed','requested')") as $o){
            if($o['due_date']<$d){ inbox_raise('recurring-overdue','expenses',$o['name'].' '.$o['period'].' is overdue ('.$o['status'].')',['priority'=>'high','date'=>$d,'amount'=>(int)($o['actual_amount']??$o['expected_amount']),'ref_table'=>'dd_recurring_obligations','ref_id'=>(int)$o['id'],'dedupe'=>'recur-over-'.$o['id'].'-'.$d]); $n++; }
            elseif($o['due_date']<=next_date($d,(int)$o['reminder_days'])){ inbox_raise('recurring-due','expenses',$o['name'].' '.$o['period'].' due '.$o['due_date'].($o['status']==='expected'?' — bill not received yet':''),['date'=>$d,'amount'=>(int)($o['actual_amount']??$o['expected_amount']),'ref_table'=>'dd_recurring_obligations','ref_id'=>(int)$o['id'],'dedupe'=>'recur-due-'.$o['id']]); $n++; } }
         return ['processed'=>$n]; },
     'usage-missing'=>function()use($d){ $y=next_date($d,-1); $miss=usage_missing_days($y,$y); if($miss) inbox_raise('usage-missing','kitchen','Kitchen usage missing for '.$y.': '.count($miss).' item(s)',['date'=>$y,'priority'=>'high','detail'=>implode(', ',array_map(fn($x)=>$x['item'].' @ '.$x['location'],array_slice($miss,0,30))),'dedupe'=>'usage-miss-'.$y]); return ['processed'=>count($miss)]; },
     'approved-unpaid'=>function()use($d){ $v=rows("SELECT voucher_no,payee,amount FROM dd_vouchers WHERE status='approved' AND approved_at<?",gmdate('Y-m-d H:i:s',time()-2*86400)); if($v) inbox_raise('voucher-unpaid','payments',count($v).' approved payment(s) still unpaid after 2 days',['date'=>$d,'detail'=>implode(', ',array_map(fn($x)=>$x['voucher_no'].' '.$x['payee'].' ₹'.rupees((int)$x['amount']),array_slice($v,0,20))),'dedupe'=>'vunpaid-'.$d]); return ['processed'=>count($v)]; },
    ];
}

/* ============================== ROUTER ============================== */
function fin_get(string $route,array $u): ?array {
    switch($route){
    case 'crm-usage': if(!can($u,'kitchen.usage')&&!can($u,'store.view')&&!can($u,'*')) fail('Not allowed.',403); $loc=(int)($_GET['location_id']??loc_id('Main Kitchen')); return usage_sheet(valid_date($_GET['date']??business_date()),$loc);
    case 'crm-usage-sheet': if(!can($u,'approve')&&!can($u,'*')) fail('Not allowed.',403); $c=row('SELECT c.*,l.name location,u.name by_name FROM dd_stock_consumptions c JOIN dd_locations l ON l.id=c.location_id JOIN dd_users u ON u.id=c.created_by WHERE c.id=?',(int)($_GET['id']??0)); if(!$c) fail('Not found.',404);
        return ['sheet'=>$c,'lines'=>rows('SELECT l.*,i.name,i.unit FROM dd_stock_consumption_lines l JOIN dd_inventory i ON i.id=l.item_id WHERE consumption_id=? ORDER BY l.id',$c['id'])];
    case 'crm-stock-movement': if(!can($u,'stock.report')&&!can($u,'reports')&&!can($u,'*')) fail('Report access is restricted.',403);
        $from=valid_date($_GET['from']??business_date()); $to=valid_date($_GET['to']??$from); $loc=(int)($_GET['location_id']??0)?:null; $mv=stock_movement($from,$to,$loc);
        if(($_GET['format']??'')==='csv'){ audit($u,'export.stock',['from'=>$from,'to'=>$to,'loc'=>$loc]); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="stock-movement-'.$from.'-'.$to.'.csv"'); echo movement_csv($mv); exit; }
        return $mv+['locations'=>rows('SELECT id,name,kind FROM dd_locations WHERE active=1 ORDER BY id'),'consistency'=>consistency_check()];
    case 'crm-consumption-month': if(!can($u,'reports')&&!can($u,'stock.report')&&!can($u,'*')) fail('Report access is restricted.',403); return consumption_month(valid_month($_GET['month']??substr(business_date(),0,7)));
    case 'crm-monthly': if(!can($u,'reports')&&!can($u,'*')) fail('Report access is restricted.',403); $m=valid_month($_GET['month']??substr(business_date(),0,7));
        if(($_GET['format']??'')==='csv'){ $r=monthly_report($m); audit($u,'export.monthly',['month'=>$m]); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="ksdd-month-'.$m.'.csv"'); echo monthly_csv($r); exit; }
        if(($_GET['snapshot']??'')==='1'){ $c=row('SELECT report FROM dd_monthly_closures WHERE month=?',$m); if(!$c||!$c['report']) fail('No closed snapshot for this month.',404); return json_decode($c['report'],true)+['snapshot'=>true]; }
        return monthly_report($m);
    case 'crm-employees': allowed($u,'payroll'); return ['employees'=>employees_list(valid_month($_GET['month']??substr(business_date(),0,7))),'batches'=>rows('SELECT b.id,b.period,b.file_name,b.status,b.total_rows,b.result,b.created_at,b.confirmed_at,u.name by_name FROM dd_payroll_import_batches b JOIN dd_users u ON u.id=b.created_by ORDER BY b.id DESC LIMIT 30')];
    case 'crm-payroll-batch': allowed($u,'payroll'); return payroll_batch_view((int)($_GET['id']??0));
    case 'crm-payslip': return payslip((int)($_GET['id']??0),$u);
    case 'crm-wizard': return wizard_context($u);
    case 'crm-wizard-bills': return wizard_bills((int)($_GET['supplier_id']??0),$u);
    case 'crm-recurring': if(!can($u,'approve')&&!can($u,'vendor')&&!can($u,'*')) fail('Not allowed.',403); return recurring_view(valid_month($_GET['month']??substr(business_date(),0,7)));
    case 'crm-bank': allowed($u,'close'); return bank_view((string)($_GET['account']??'Bank of India'),(string)($_GET['status']??'unmatched'));
    }
    return null;
}
function fin_mutate(string $route,array $b,array $u): ?array {
    $ok=['ok'=>true];
    switch($route){
    case 'crm-usage-post': if(!can($u,'kitchen.usage')&&!can($u,'*')) fail('Your role cannot record kitchen usage.',403); return usage_submit($b,$u);
    case 'crm-usage-decide': return usage_decide((int)($b['id']??0),($b['decision']??'')==='approve',$u,txt($b['note']??''));
    case 'crm-payroll-import-preview': return payroll_import_preview($b,$u);
    case 'crm-payroll-import-confirm': return payroll_import_confirm($b,$u);
    case 'crm-employee-save': return $ok+['id'=>employee_save($b,$u)];
    case 'crm-payroll-calc': allowed($u,'payroll'); $e=row('SELECT * FROM dd_employees WHERE id=? FOR UPDATE',(int)($b['employee_id']??0)); if(!$e) fail('Employee not found.'); return $ok+payroll_calculate($e,valid_month($b['month']??''),$b,$u);
    case 'crm-payroll-approve': allowed($u,'payroll'); payroll_approve((int)($b['id']??0),$u,txt($b['note']??'',250)); return $ok;
    case 'crm-wizard-submit': return wizard_submit($b,$u);
    case 'crm-recurring-save': return $ok+['id'=>recurring_save($b,$u)];
    case 'crm-recurring-generate': if(!can($u,'approve')&&!can($u,'*')) fail('Not allowed.',403); return $ok+['created'=>recurring_generate(valid_month($b['month']??''),$u)];
    case 'crm-recurring-bill': recurring_bill($b,$u); return $ok;
    case 'crm-recurring-request': if(!can($u,'approve')&&!can($u,'vendor')&&!can($u,'voucher.request')&&!can($u,'*')) fail('Not allowed.',403); return recurring_request((int)($b['obligation_id']??0),$u,client_key($b));
    case 'crm-recurring-skip': if(!can($u,'approve')&&!can($u,'*')) fail('Not allowed.',403); $o=row("SELECT * FROM dd_recurring_obligations WHERE id=? AND status IN ('expected','billed') FOR UPDATE",(int)($b['obligation_id']??0)); if(!$o) fail('Open bill not found.'); $why=txt($b['reason']??''); if($why==='') fail('Write why this month is skipped.');
        run("UPDATE dd_recurring_obligations SET status='skipped',note=? WHERE id=?",$why,$o['id']); audit($u,'recurring.skip',['id'=>$o['id'],'reason'=>$why]); return $ok;
    case 'crm-budget-save': budget_save($b,$u); return $ok;
    case 'crm-bank-import': return bank_import($b,$u);
    case 'crm-bank-match': bank_match($b,$u); return $ok;
    case 'crm-month-close': return month_close($b,$u);
    case 'crm-month-reopen': return month_reopen($b,$u);
    }
    return null;
}
