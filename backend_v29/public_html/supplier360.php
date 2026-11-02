<?php
declare(strict_types=1);
// KSDD CRM 360 — Supplier Bill + Stock IN, supplier balances, payment cycles, statements.
// Money: integer paise. Quantities: integer milli-units in PHP, DECIMAL(14,3) in SQL. No floats in financial decisions.
require_once __DIR__.'/lib360.php';

const HOLD_REASONS = ['mismatch'=>'Bill total does not match items','no-photo'=>'No bill photo','invoice-pending'=>'Goods received, invoice pending','damaged'=>'Damaged / wrong goods','duplicate-suspect'=>'Possible duplicate bill','other'=>'Other'];
const UNITS = ['kg','g','litre','ml','piece','packet','crate','cylinder','tin','box','bottle','dozen','bundle','tray','bag'];
const MAX_LINES = 100;

/* ---------- exact number helpers ---------- */
function milli(mixed $v,string $label='Quantity',bool $zero=false): int {
    $s=trim(is_scalar($v)?(string)$v:''); if(!preg_match('/^\d{1,8}(?:\.(\d{1,3}))?$/',$s)) fail($label.' must be a number with up to 3 decimals.');
    [$w,$f]=array_pad(explode('.',$s,2),2,''); $n=(int)$w*1000+(int)str_pad($f,3,'0'); if(!$zero&&$n<=0) fail($label.' must be greater than zero.'); return $n;
}
function m2s(int $m): string { $neg=$m<0; $m=abs($m); return ($neg?'-':'').intdiv($m,1000).'.'.str_pad((string)($m%1000),3,'0',STR_PAD_LEFT); }
function smoney(mixed $v): int { $s=trim(is_scalar($v)?(string)$v:''); if($s==='')return 0; $neg=str_starts_with($s,'-'); $p=money(ltrim($s,'-+')); return $neg?-$p:$p; }
function money0(mixed $v): int { return (is_scalar($v)&&trim((string)$v)!=='')?money($v):0; }
/** qty(milli) × rate(paise per unit) → paise, half-up. Bounds keep the product inside 64-bit. */
function line_gross(int $qm,int $rate): int { if($qm>100000000||$rate>10000000) fail('Quantity or rate is too large.'); return intdiv($qm*$rate+500,1000); }
function factor_micro(string $f): int { [$w,$d]=array_pad(explode('.',$f,2),2,''); return (int)$w*1000000+(int)str_pad(substr($d,0,6),6,'0'); }
function unit_norm(string $u): string { $u=strtolower(trim($u)); return ['kgs'=>'kg','gram'=>'g','grams'=>'g','gm'=>'g','l'=>'litre','ltr'=>'litre','liter'=>'litre','lit'=>'litre','pcs'=>'piece','pc'=>'piece','nos'=>'piece','pkt'=>'packet'][$u]??$u; }
function conversion(int $item,string $from,string $to): ?string {
    if($from===$to) return '1.000000';
    $r=row('SELECT factor FROM dd_unit_conversions WHERE item_id=? AND from_unit=? AND to_unit=?',$item,$from,$to)??row('SELECT factor FROM dd_unit_conversions WHERE item_id IS NULL AND from_unit=? AND to_unit=?',$from,$to);
    return $r?(string)$r['factor']:null;
}

/* ---------- payment terms & due dates ---------- */
function terms_of(int $sid): ?array { return row('SELECT * FROM dd_supplier_terms WHERE supplier_id=?',$sid); }
function terms_label(?array $t,int $creditDays=0): string {
    if(!$t) return $creditDays?'Invoice + '.$creditDays.' days (default)':'Not configured';
    return match($t['mode']){ 'cycle'=>'Every '.$t['cycle_days'].' days from '.$t['anchor_date'], 'invoice_days'=>'Invoice + '.$t['credit_days'].' days',
        'weekday'=>'Every '.['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][(int)$t['weekday']], 'monthday'=>'Monthly on day '.$t['day_of_month'], default=>'—' };
}
/**
 * Due date for one invoice. Two different ideas, never mixed:
 *  cycle        — settlement dates are anchor + k×cycle_days; an invoice is settled on the first settlement date AFTER its invoice date
 *                 (invoices dated on a settlement day roll to the next cycle; invoices before the anchor fall on the anchor).
 *  invoice_days — each invoice is due invoice_date + N days.
 *  weekday / monthday — next given weekday / day-of-month strictly after the invoice date (day clamped to month length).
 * Pure date arithmetic on Y-m-d strings: no timezone shifts.
 */
function compute_due(?array $t,string $inv,int $defaultDays=0): string {
    $d=new DateTimeImmutable($inv);
    if(!$t) return $d->modify('+'.max(0,$defaultDays).' day')->format('Y-m-d');
    switch($t['mode']){
        case 'invoice_days': return $d->modify('+'.max(0,(int)$t['credit_days']).' day')->format('Y-m-d');
        case 'cycle': $a=new DateTimeImmutable($t['anchor_date']); $c=max(1,(int)$t['cycle_days']);
            if($d<$a) return $a->format('Y-m-d'); $days=(int)$a->diff($d)->format('%a'); $k=intdiv($days,$c)+1; return $a->modify('+'.($k*$c).' day')->format('Y-m-d');
        case 'weekday': $w=(int)$t['weekday']; $cur=(int)$d->format('w'); $add=($w-$cur+7)%7; if($add===0)$add=7; return $d->modify('+'.$add.' day')->format('Y-m-d');
        case 'monthday': $dm=(int)$t['day_of_month']; $y=(int)$d->format('Y'); $m=(int)$d->format('n');
            for($i=0;$i<3;$i++){ $last=(int)(new DateTimeImmutable(sprintf('%04d-%02d-01',$y,$m)))->format('t'); $cand=sprintf('%04d-%02d-%02d',$y,$m,min($dm,$last)); if($cand>$inv) return $cand; $m++; if($m>12){$m=1;$y++;} }
    }
    return $inv;
}
function suggested_cycle(string $name,string $cat): ?int { $s=json_decode(setting('supplier_terms_suggestions','{}'),true)?:[]; $h=strtolower($name.' '.$cat); foreach($s as $k=>$v) if(str_contains($h,$k)) return (int)$v; return null; }

/* ---------- balances (one aggregate query, no N+1) ---------- */
function supplier_balances(?int $sid=null,bool $activeOnly=false): array {
    $today=business_date(); $w=[]; $a=[$today,$today];
    if($sid){$w[]='s.id=?';$a[]=$sid;} if($activeOnly)$w[]='s.active=1';
    $sql="SELECT s.id,s.name,s.category,s.active,s.phone,s.credit_days,
      COALESCE(b.outstanding,0) bill_outstanding,COALESCE(b.overdue,0) overdue,COALESCE(b.due_today,0) due_today,COALESCE(b.unpaid,0) unpaid_count,b.next_due,
      COALESCE(c.unallocated,0) credit,COALESCE(h.holds,0) holds,COALESCE(h.hold_amount,0) hold_amount,t.mode terms_mode,
      (SELECT MAX(date) FROM dd_bills x WHERE x.supplier_id=s.id AND x.voided=0 AND x.kind='invoice') last_purchase,
      (SELECT reference FROM dd_bills x WHERE x.supplier_id=s.id AND x.voided=0 AND x.kind='invoice' ORDER BY x.date DESC,x.id DESC LIMIT 1) last_invoice
     FROM dd_suppliers s
     LEFT JOIN (SELECT supplier_id,SUM(amount-paid-credited) outstanding,SUM(CASE WHEN due_date<? THEN amount-paid-credited ELSE 0 END) overdue,SUM(CASE WHEN due_date=? THEN amount-paid-credited ELSE 0 END) due_today,COUNT(*) unpaid,MIN(due_date) next_due
                FROM dd_bills WHERE voided=0 AND status<>'paid' GROUP BY supplier_id) b ON b.supplier_id=s.id
     LEFT JOIN (SELECT supplier_id,SUM(amount) unallocated FROM dd_supplier_credits WHERE bill_id IS NULL GROUP BY supplier_id) c ON c.supplier_id=s.id
     LEFT JOIN (SELECT supplier_id,COUNT(*) holds,SUM(invoice_total) hold_amount FROM dd_goods_receipts WHERE status IN ('hold','returned') GROUP BY supplier_id) h ON h.supplier_id=s.id
     LEFT JOIN dd_supplier_terms t ON t.supplier_id=s.id".($w?' WHERE '.implode(' AND ',$w):'').' ORDER BY s.name';
    $out=rows($sql,...$a);
    foreach($out as &$r){ foreach(['bill_outstanding','overdue','due_today','unpaid_count','credit','holds','hold_amount','credit_days'] as $k)$r[$k]=(int)$r[$k]; $r['balance']=$r['bill_outstanding']-$r['credit'];
        $r['next_due_amount']=$r['next_due']?(int)row("SELECT COALESCE(SUM(amount-paid-credited),0) n FROM dd_bills WHERE supplier_id=? AND voided=0 AND status<>'paid' AND due_date=?",$r['id'],$r['next_due'])['n']:0; }
    unset($r); return $out;
}
function bill_out(array $b): int { return (int)$b['amount']-(int)$b['paid']-(int)$b['credited']; }
function bill_status_sync(int $id): void { $b=row('SELECT * FROM dd_bills WHERE id=?',$id); $done=(int)$b['paid']+(int)$b['credited'];
    run('UPDATE dd_bills SET status=? WHERE id=?',$done>=(int)$b['amount']?'paid':($done>0?'partial':'unpaid'),$id); }

/* ---------- supplier master ---------- */
function supplier_save(array $b,array $u): int {
    allowed($u,'manage'); $id=(int)($b['id']??0); $name=name_required($b['name']??''); $key=supplier_key($name); $phone=preg_replace('/\D/','',(string)($b['phone']??''));
    if($key==='') fail('Enter a supplier name.');
    if(empty($b['confirm_duplicate'])){ $dup=row('SELECT id,name FROM dd_suppliers WHERE id<>? AND (name_key=?'.($phone!==''?' OR REPLACE(REPLACE(phone," ",""),"-","")=?':'').') LIMIT 1',...array_merge([$id,$key],$phone!==''?[$phone]:[]));
        if($dup) send(['ok'=>false,'duplicateWarning'=>true,'error'=>'A supplier "'.$dup['name'].'" with the same name or phone already exists. Edit that one instead of creating a duplicate.'],409); }
    $gst=strtoupper(txt($b['gstin']??'',20)); if($gst!==''&&!preg_match('/^[0-9]{2}[A-Z0-9]{13}$/',$gst)) fail('GSTIN must be 15 characters.');
    $vals=[$name,$key,txt($b['phone']??'',40),txt($b['category']??'',80),$gst?:null,txt($b['notes']??''),!empty($b['active'])||!$id?1:0,max(0,(int)($b['credit_days']??0)),now_sql()];
    if($id){ $old=row('SELECT * FROM dd_suppliers WHERE id=? FOR UPDATE',$id); if(!$old)fail('Supplier not found.');
        run('UPDATE dd_suppliers SET name=?,name_key=?,phone=?,category=?,gstin=?,notes=?,active=?,credit_days=?,updated_at=? WHERE id=?',...[...$vals,$id]); audit($u,'supplier.update',['id'=>$id,'before'=>array_intersect_key($old,array_flip(['name','phone','category','active'])),'after'=>['name'=>$name,'active'=>$vals[6]]]); }
    else { $open=money0($b['opening_payable']??''); run('INSERT INTO dd_suppliers(name,name_key,phone,category,gstin,notes,active,credit_days,updated_at,opening_payable) VALUES(?,?,?,?,?,?,?,?,?,?)',...[...$vals,$open]); $id=(int)db()->lastInsertId();
        if($open){ $cut=business_date(); run("INSERT INTO dd_bills(supplier_id,reference,date,due_date,original_due_date,amount,paid,status,kind,created_by) VALUES(?,?,?,?,?,?,0,'unpaid','opening',?)",$id,'OPENING-'.$id,$cut,$cut,$cut,$open,$u['id']); }
        audit($u,'supplier.create',['id'=>$id,'name'=>$name,'opening'=>$open]); }
    if(!empty($b['terms'])&&is_array($b['terms'])) terms_save($id,$b['terms'],$u);
    return $id;
}
function terms_save(int $sid,array $t,array $u): void {
    allowed($u,'manage'); $mode=(string)($t['mode']??''); if(!in_array($mode,['cycle','invoice_days','weekday','monthday','none'],true)) fail('Choose a payment schedule type.');
    if($mode==='none'){ run('DELETE FROM dd_supplier_terms WHERE supplier_id=?',$sid); audit($u,'supplier.terms',['supplier'=>$sid,'mode'=>'none']); return; }
    $cd=$mode==='cycle'?filter_var($t['cycle_days']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>90]]):null; if($mode==='cycle'&&!$cd) fail('Cycle days must be 1–90.');
    $anchor=$mode==='cycle'?valid_date($t['anchor_date']??''):null;
    $wd=$mode==='weekday'?filter_var($t['weekday']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>6]]):null; if($mode==='weekday'&&$wd===false) fail('Choose a weekday.');
    $dm=$mode==='monthday'?filter_var($t['day_of_month']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>31]]):null; if($mode==='monthday'&&!$dm) fail('Day of month must be 1–31.');
    $cr=$mode==='invoice_days'?filter_var($t['credit_days']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>180]]):null; if($mode==='invoice_days'&&$cr===false) fail('Credit days must be 0–180.');
    $rem=filter_var($t['reminder_days']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>15]]); if($rem===false)$rem=1;
    run('REPLACE INTO dd_supplier_terms(supplier_id,mode,cycle_days,anchor_date,weekday,day_of_month,credit_days,reminder_days,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',$sid,$mode,$cd,$anchor,$wd===null?null:$wd,$dm,$cr,$rem,$u['id'],now_sql());
    audit($u,'supplier.terms',['supplier'=>$sid]+$t);
    if(!empty($t['apply_to_open'])){ $nt=terms_of($sid); foreach(rows("SELECT * FROM dd_bills WHERE supplier_id=? AND voided=0 AND status<>'paid' AND kind='invoice' FOR UPDATE",$sid) as $bl){ $nd=compute_due($nt,$bl['date']); if($nd!==$bl['due_date']){ run('UPDATE dd_bills SET due_date=? WHERE id=?',$nd,$bl['id']); audit($u,'bill.due.recalc',['bill'=>$bl['id'],'from'=>$bl['due_date'],'to'=>$nd]); } } }
}

/* ---------- Goods IN validation (pure: no writes) ---------- */
function receipt_validate(array $b,array $u,bool $forReview=false): array {
    $sid=(int)($b['supplier_id']??0); $s=row('SELECT * FROM dd_suppliers WHERE id=?',$sid);
    if(!$s) fail('Select the supplier from the list. New suppliers are added by the owner.');
    if(!(int)$s['active']) fail('Supplier "'.$s['name'].'" is disabled. Ask the owner/admin before receiving goods.');
    $loc=(int)($b['location_id']??0)?:loc_id('Main Store'); $l=row('SELECT * FROM dd_locations WHERE id=? AND active=1',$loc); if(!$l) fail('Choose the store location.');
    if(!can($u,'write')&&$l['kind']!=='store') fail('Goods IN must go to a store location.');
    $ref=txt($b['invoice_ref']??'',120); $date=($b['invoice_date']??'')!==''?valid_date($b['invoice_date']):business_date();
    if($date>business_date()) fail('Invoice date cannot be in the future.');
    $lines=$b['lines']??null; if(!is_array($lines)||!$lines) fail('Add at least one item.'); if(count($lines)>MAX_LINES) fail('Maximum '.MAX_LINES.' items per bill. Split into two receipts.');
    $po=null; if(!empty($b['po_id'])){ $po=row("SELECT * FROM dd_purchase_orders WHERE id=? AND supplier_id=? AND status IN ('ordered','partial')",(int)$b['po_id'],$sid); if(!$po) fail('That purchase order is not open for this supplier.'); }
    $tol=setting_int('po_over_tolerance_pct',0); $pct=setting_int('rate_change_alert_pct',10);
    $out=[]; $seen=[]; $subtotal=0; $warnings=[];
    foreach(array_values($lines) as $i=>$ln){ $n=$i+1; $p='Row '.$n.': ';
        if(!is_array($ln)) fail($p.'invalid row.');
        $it=row('SELECT * FROM dd_inventory WHERE id=? AND active=1',(int)($ln['item_id']??0)); if(!$it) fail($p.'choose an item from the list.');
        $unit=unit_norm((string)($ln['unit']??$it['unit'])); $su=unit_norm($it['unit']);
        try{ $qm=milli($ln['qty']??null); $rate=money($ln['rate']??null); $disc=money0($ln['discount']??''); $tax=money0($ln['tax']??''); }catch(RuntimeException $e){ fail($p.$it['name'].' — '.$e->getMessage()); }
        if($rate<=0) fail($p.$it['name'].' — rate must be greater than zero.');
        $k=$it['id'].'|'.$unit; if(isset($seen[$k])) fail($p.$it['name'].' is already in row '.$seen[$k].'. Change the quantity there instead of adding it twice.'); $seen[$k]=$n;
        $f=conversion((int)$it['id'],$unit,$su); if($f===null) fail($p.'no conversion from '.$unit.' to '.$su.' is set for '.$it['name'].'. Use '.$su.' or ask the admin to add the conversion.');
        $gross=line_gross($qm,$rate); if($disc>$gross) fail($p.$it['name'].' — discount is more than the line amount.');
        $total=$gross-$disc+$tax; $stock=intdiv($qm*factor_micro($f)+500000,1000000); if($stock<=0) fail($p.$it['name'].' — quantity too small after unit conversion.');
        $exp=null; if(!empty($ln['expiry'])) $exp=valid_date($ln['expiry']);
        $pl=null; if($po){ $pl=row('SELECT * FROM dd_po_lines WHERE po_id=? AND item_id=?',$po['id'],$it['id']);
            if($pl){ $max=intdiv(milli((string)$pl['quantity'],'PO qty')*(100+$tol),100); $rec=milli((string)$pl['received_qty'],'Received',true);
                if($rec+$stock>$max) fail($p.$it['name'].' — PO '.$po['po_no'].' allows '.m2s($max-$rec).' '.$su.' more; you entered '.m2s($stock).'.'); } }
        $last=row('SELECT rate,purchase_unit FROM dd_bill_lines WHERE supplier_id=? AND item_id=? AND purchase_unit=? ORDER BY id DESC LIMIT 1',$sid,$it['id'],$unit);
        if($last&&(int)$last['rate']>0&&abs($rate-(int)$last['rate'])*100>(int)$last['rate']*$pct) $warnings[]=['row'=>$n,'item'=>$it['name'],'last'=>(int)$last['rate'],'now'=>$rate];
        $out[]=['item_id'=>(int)$it['id'],'item_name'=>$it['name'],'qty_milli'=>$qm,'unit'=>$unit,'factor'=>$f,'stock_milli'=>$stock,'stock_unit'=>$su,'rate'=>$rate,'gross'=>$gross,'discount'=>$disc,'tax'=>$tax,'total'=>$total,'batch'=>txt($ln['batch']??'',60)?:null,'expiry'=>$exp,'po_line_id'=>$pl?(int)$pl['id']:null];
        $subtotal+=$total;
    }
    $bd=money0($b['bill_discount']??''); $bt=money0($b['bill_tax']??''); $oc=money0($b['other_charges']??''); $rd=smoney($b['rounding']??'');
    $rmax=setting_int('invoice_rounding_max',100); if(abs($rd)>$rmax) fail('Rounding adjustment can be at most ₹'.rupees($rmax).'. A bigger difference must be corrected or held for review.');
    if($bd>$subtotal) fail('Bill discount is more than the items total.');
    $calc=$subtotal-$bd+$bt+$oc+$rd; $inv=money0($b['invoice_total']??'');
    // Photos: 1–5 images + optional PDF, each uploaded by this user (or an approver) and never used on another receipt.
    $files=array_values(array_unique(array_map('intval',(array)($b['file_ids']??[])))); if(count($files)>6) fail('Attach at most 5 photos and 1 PDF.');
    $pdfs=0; $hashDup=null;
    foreach($files as $fid){ $f=row('SELECT * FROM dd_files WHERE id=?',$fid); if(!$f) fail('Bill photo not found. Upload again.');
        if((int)$f['uploaded_by']!==(int)$u['id']&&!can($u,'vendor')) fail('That photo was uploaded by someone else. Take the photo again.',403);
        if(row('SELECT receipt_id FROM dd_receipt_files WHERE file_id=?'.(!empty($b['_receipt_id'])?' AND receipt_id<>'.(int)$b['_receipt_id']:''),$fid)) fail('This photo is already attached to another receipt.',409);
        if($f['mime']==='application/pdf') $pdfs++;
        $d2=row('SELECT r.receipt_no FROM dd_receipt_files rf JOIN dd_files f ON f.id=rf.file_id JOIN dd_goods_receipts r ON r.id=rf.receipt_id WHERE f.sha256=? AND r.status<>\'rejected\''.(!empty($b['_receipt_id'])?' AND r.id<>'.(int)$b['_receipt_id']:'').' LIMIT 1',$f['sha256']); if($d2)$hashDup=$d2['receipt_no']; }
    if($pdfs>1) fail('Attach only one PDF.');
    $issues=[];
    if($ref==='') $issues[]='invoice-pending';
    if(!$files) $issues[]='no-photo';
    if($inv<=0) $issues[]='invoice-pending';
    elseif($inv!==$calc) $issues[]='mismatch';
    if($hashDup) $issues[]='duplicate-suspect';
    if($ref!==''&&row('SELECT id FROM dd_bills WHERE supplier_id=? AND reference=?',$sid,$ref)) fail('Bill '.$ref.' from '.$s['name'].' is already recorded. Duplicate not added.',409);
    if($ref!==''&&row("SELECT id FROM dd_goods_receipts WHERE supplier_id=? AND invoice_ref=? AND status IN ('hold','returned','posted')".(!empty($b['_receipt_id'])?' AND id<>'.(int)$b['_receipt_id']:''),$sid,$ref)) fail('Bill '.$ref.' from '.$s['name'].' is already waiting for review.',409);
    $sameDay=$inv>0&&row('SELECT id FROM dd_bills WHERE supplier_id=? AND date=? AND amount=? AND voided=0',$sid,$date,$inv);
    return ['supplier'=>$s,'location'=>$l,'invoice_ref'=>$ref,'invoice_date'=>$date,'po'=>$po,'lines'=>$out,'subtotal'=>$subtotal,'bill_discount'=>$bd,'bill_tax'=>$bt,'other_charges'=>$oc,'rounding'=>$rd,
        'calculated'=>$calc,'invoice_total'=>$inv,'difference'=>$inv-$calc,'files'=>$files,'issues'=>array_values(array_unique($issues)),'hashDup'=>$hashDup,'sameDayAmount'=>(bool)$sameDay,'warnings'=>$warnings,'note'=>txt($b['note']??'')];
}
function totals_msg(array $v): string { return 'Calculated ₹'.rupees($v['calculated']).' / Invoice ₹'.rupees(max(0,$v['invoice_total'])).' / Difference ₹'.rupees($v['difference']); }

/* ---------- Goods IN posting: everything in the caller's single DB transaction ---------- */
function goods_receipt_submit(array $b,array $u): array {
    allowed($u,'store.in'); $ck=client_key($b); if(!$ck) fail('Missing submission key. Refresh the page.');
    if($x=row('SELECT * FROM dd_goods_receipts WHERE client_key=?',$ck)) return ['ok'=>true,'duplicate'=>true]+receipt_result($x);
    $v=receipt_validate($b,$u); $action=($b['action']??'post')==='hold'?'hold':'post';
    if($action==='post'){
        foreach($v['issues'] as $is){ $msg=match($is){'mismatch'=>'Bill total does not match the items. '.totals_msg($v).'. Correct the rows/charges or save as Hold for Review.','no-photo'=>'Take a photo of the bill first (or save as Hold for Review with a reason).','invoice-pending'=>'Enter the bill number and bill total (or save as "Invoice pending" hold).','duplicate-suspect'=>'This photo was already used on receipt '.$v['hashDup'].'. Save as Hold for Review if this is a different bill.',default=>'Needs review.'};
            send(['ok'=>false,'error'=>$msg,'issue'=>$is,'calculated'=>$v['calculated'],'invoice_total'=>$v['invoice_total'],'difference'=>$v['difference'],'canHold'=>true],422); }
        if($v['sameDayAmount']&&empty($b['confirm_duplicate'])) send(['ok'=>false,'duplicateWarning'=>true,'error'=>'A bill from '.$v['supplier']['name'].' with the same date and amount already exists. Is this a different bill?'],409);
    } else {
        $reason=(string)($b['hold_reason']??''); if(!isset(HOLD_REASONS[$reason])) $reason=$v['issues'][0]??'other';
        if($v['note']==='') fail('Write why this needs review.');
    }
    $no=seq('GRN',business_date());
    run('INSERT INTO dd_goods_receipts(receipt_no,supplier_id,location_id,invoice_ref,invoice_date,po_id,status,hold_reason,subtotal,bill_discount,bill_tax,other_charges,rounding,calculated_total,invoice_total,difference,payload,note,client_key,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        $no,$v['supplier']['id'],$v['location']['id'],$v['invoice_ref']?:null,$v['invoice_date'],$v['po']['id']??null,$action==='hold'?'hold':'posting',$action==='hold'?$reason:null,$v['subtotal'],$v['bill_discount'],$v['bill_tax'],$v['other_charges'],$v['rounding'],$v['calculated'],$v['invoice_total'],$v['difference'],
        json_encode(receipt_payload($b),JSON_UNESCAPED_UNICODE),$v['note'],$ck,$u['id']);
    $rid=(int)db()->lastInsertId(); foreach($v['files'] as $f) run('INSERT INTO dd_receipt_files(receipt_id,file_id) VALUES(?,?)',$rid,$f);
    if($action==='hold'){
        inbox_raise('goods-hold','store','Goods IN on hold: '.$v['supplier']['name'].' '.($v['invoice_ref']?:'(no bill no.)').' — '.HOLD_REASONS[$reason],['amount'=>abs($v['difference'])?:$v['calculated'],'ref_table'=>'dd_goods_receipts','ref_id'=>$rid,'by'=>$u['id'],'priority'=>'high','date'=>business_date(),'detail'=>totals_msg($v).'. Note: '.$v['note']]);
        audit($u,'goods.hold',['receipt'=>$rid,'reason'=>$reason,'calc'=>$v['calculated'],'invoice'=>$v['invoice_total']]);
        return ['ok'=>true,'status'=>'hold','receipt_no'=>$no,'receipt_id'=>$rid,'message'=>'Saved for review. Stock and supplier balance are NOT changed until the manager approves.'];
    }
    receipt_post($rid,$v,$u); return ['ok'=>true]+receipt_result(row('SELECT * FROM dd_goods_receipts WHERE id=?',$rid))+['warnings'=>$v['warnings']];
}
function receipt_payload(array $b): array { return array_intersect_key($b,array_flip(['supplier_id','location_id','invoice_ref','invoice_date','po_id','lines','bill_discount','bill_tax','other_charges','rounding','invoice_total','file_ids','note'])); }
function receipt_post(int $rid,array $v,array $u): void {
    $s=$v['supplier']; $due=compute_due(terms_of((int)$s['id']),$v['invoice_date'],(int)$s['credit_days']);
    run("INSERT INTO dd_bills(supplier_id,reference,date,due_date,original_due_date,amount,paid,status,proof_file_id,po_id,created_by,kind,receipt_id) VALUES(?,?,?,?,?,?,0,'unpaid',?,?,?,'invoice',?)",
        $s['id'],$v['invoice_ref'],$v['invoice_date'],$due,$due,$v['invoice_total'],$v['files'][0]??null,$v['po']['id']??null,$u['id'],$rid);
    $bill=(int)db()->lastInsertId(); $no=row('SELECT receipt_no FROM dd_goods_receipts WHERE id=?',$rid)['receipt_no'];
    foreach($v['lines'] as $ln){
        $mid=stock_move(['item_id'=>$ln['item_id'],'qty'=>m2s($ln['stock_milli']),'to'=>(int)$v['location']['id'],'kind'=>'in','date'=>$v['invoice_date'],'supplier_id'=>$s['id'],'rate'=>$ln['rate'],'reference'=>$no.' / '.$v['invoice_ref'],'reason'=>'Goods IN '.$no,'po_line_id'=>$ln['po_line_id'],'proof'=>$v['files'][0]??null],$u);
        run('INSERT INTO dd_bill_lines(bill_id,receipt_id,stock_move_id,po_line_id,item_id,item_name,purchase_qty,purchase_unit,conversion_factor,stock_qty,stock_unit,rate,gross,discount,tax,total,batch,expiry,supplier_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            $bill,$rid,$mid,$ln['po_line_id'],$ln['item_id'],$ln['item_name'],m2s($ln['qty_milli']),$ln['unit'],$ln['factor'],m2s($ln['stock_milli']),$ln['stock_unit'],$ln['rate'],$ln['gross'],$ln['discount'],$ln['tax'],$ln['total'],$ln['batch'],$ln['expiry'],$s['id'],$u['id']);
        wavg_cost_in((int)$ln['item_id'],(int)$ln['stock_milli'],(int)$ln['total'],$mid); // V6: weighted-average cost per stock unit; move valued at exact bill-line total
        if($ln['po_line_id']) run('UPDATE dd_po_lines SET received_qty=received_qty+? WHERE id=?',m2s($ln['stock_milli']),$ln['po_line_id']);
    }
    if($v['po']){ $po=(int)$v['po']['id']; $open=(int)row('SELECT COUNT(*) n FROM dd_po_lines WHERE po_id=? AND received_qty<quantity',$po)['n']; run('UPDATE dd_purchase_orders SET status=? WHERE id=?',$open?'partial':'received',$po);
        run("UPDATE dd_kitchen_request_items SET purchase_status='received' WHERE purchase_status='ordered' AND item_id IN (SELECT item_id FROM dd_po_lines WHERE po_id=? AND received_qty>=quantity)",$po); }
    run("UPDATE dd_goods_receipts SET status='posted',bill_id=? WHERE id=?",$bill,$rid);
    foreach($v['warnings'] as $w) inbox_raise('price-change','purchase','Rate change: '.$w['item'].' ₹'.rupees($w['last']).' → ₹'.rupees($w['now']).' ('.$s['name'].')',['ref_table'=>'dd_goods_receipts','ref_id'=>$rid,'dedupe'=>'rate-'.$rid.'-'.$w['row']]);
    audit($u,'goods.post',['receipt'=>$rid,'bill'=>$bill,'supplier'=>$s['id'],'amount'=>$v['invoice_total'],'lines'=>count($v['lines']),'due'=>$due]);
}
function receipt_result(array $r): array {
    $bal=supplier_balances((int)$r['supplier_id'])[0]??null; $bill=$r['bill_id']?row('SELECT id,due_date,amount FROM dd_bills WHERE id=?',$r['bill_id']):null;
    return ['status'=>$r['status'],'receipt_no'=>$r['receipt_no'],'receipt_id'=>(int)$r['id'],'bill_id'=>$bill?(int)$bill['id']:null,'bill_amount'=>$bill?(int)$bill['amount']:null,'due_date'=>$bill['due_date']??null,
        'supplier_balance'=>$bal['balance']??null,'next_due'=>$bal['next_due']??null,'lines'=>(int)row('SELECT COUNT(*) n FROM dd_bill_lines WHERE receipt_id=?',$r['id'])['n'],'message'=>$r['status']==='posted'?'Stock added. Bill recorded.':''];
}
/** Review a held receipt. Approval re-runs the SAME validation (totals must match). Only the owner may waive the photo, with a logged reason. */
function receipt_decide(array $b,array $u): array {
    allowed($u,'approve'); $r=row("SELECT * FROM dd_goods_receipts WHERE id=? AND status='hold' FOR UPDATE",(int)($b['id']??0)); if(!$r) fail('Held receipt not found.');
    if((int)$r['created_by']===(int)$u['id']&&!can($u,'*')) fail('You cannot approve your own receipt.',403);
    $dec=(string)($b['decision']??''); $note=txt($b['note']??''); if($note==='') fail('Write a review note.');
    if(in_array($dec,['reject','return','photo'],true)){
        $st=['reject'=>'rejected','return'=>'returned','photo'=>'returned'][$dec];
        run('UPDATE dd_goods_receipts SET status=?,review_note=?,reviewed_by=?,reviewed_at=? WHERE id=?',$st,($dec==='photo'?'Replacement photo requested: ':'').$note,$u['id'],now_sql(),$r['id']);
        if($st==='returned') run('DELETE FROM dd_receipt_files WHERE receipt_id=?',$r['id']); // photos free to re-use on the corrected submission
        inbox_close_for('dd_goods_receipts',(int)$r['id'],$st==='rejected'?'rejected':'resolved',$u,$note); audit($u,'goods.'.$dec,['receipt'=>$r['id'],'note'=>$note]); return ['ok'=>true,'status'=>$st];
    }
    if($dec!=='approve') fail('Choose approve, reject or return.');
    $p=json_decode($r['payload'],true)?:[]; foreach(['invoice_total','bill_discount','bill_tax','other_charges','rounding','invoice_ref','invoice_date'] as $k) if(array_key_exists($k,$b)&&$b[$k]!==null&&$b[$k]!=='') $p[$k]=$b[$k];
    $files=array_map('intval',array_column(rows('SELECT file_id FROM dd_receipt_files WHERE receipt_id=?',$r['id']),'file_id')); foreach((array)($b['file_ids']??[]) as $f) $files[]=(int)$f; $p['file_ids']=array_values(array_unique($files)); $p['_receipt_id']=$r['id'];
    $creator=row('SELECT * FROM dd_users WHERE id=?',$r['created_by']);
    $v=receipt_validate($p,can($u,'vendor')?$u:$creator,true);
    $issues=array_diff($v['issues'],!empty($b['accept_duplicate_photo'])?['duplicate-suspect']:[]);
    if(in_array('no-photo',$issues,true)&&can($u,'*')&&!empty($b['waive_photo'])){ $issues=array_diff($issues,['no-photo']); audit($u,'goods.photo.override',['receipt'=>$r['id'],'reason'=>$note]); }
    if($issues) fail('Cannot approve yet: '.implode(', ',array_map(fn($i)=>HOLD_REASONS[$i]??$i,$issues)).'. '.totals_msg($v).'. Correct the figures (e.g. rounding/charges) or reject/return.');
    foreach($v['files'] as $f) run('INSERT IGNORE INTO dd_receipt_files(receipt_id,file_id) VALUES(?,?)',$r['id'],$f);
    run('UPDATE dd_goods_receipts SET invoice_ref=?,invoice_date=?,subtotal=?,bill_discount=?,bill_tax=?,other_charges=?,rounding=?,calculated_total=?,invoice_total=?,difference=0,payload=?,review_note=?,reviewed_by=?,reviewed_at=? WHERE id=?',
        $v['invoice_ref'],$v['invoice_date'],$v['subtotal'],$v['bill_discount'],$v['bill_tax'],$v['other_charges'],$v['rounding'],$v['calculated'],$v['invoice_total'],json_encode(receipt_payload($p),JSON_UNESCAPED_UNICODE),$note,$u['id'],now_sql(),$r['id']);
    receipt_post((int)$r['id'],$v,$creator?:$u); inbox_close_for('dd_goods_receipts',(int)$r['id'],'approved',$u,$note); audit($u,'goods.approve',['receipt'=>$r['id']]);
    return ['ok'=>true]+receipt_result(row('SELECT * FROM dd_goods_receipts WHERE id=?',$r['id']));
}

/* ---------- payments, credits, statements ---------- */
/** Validate requested allocations against bill outstanding minus already-requested/approved allocations. Locks the bills. */
function supplier_allocations(int $sid,int $amount,mixed $alloc,bool $advance): array {
    $out=[]; $sum=0;
    if($advance){ if($alloc) fail('An advance is not allocated to bills.'); return []; }
    if($alloc==='auto'||$alloc===null||$alloc===[]){
        $left=$amount; foreach(rows("SELECT * FROM dd_bills WHERE supplier_id=? AND voided=0 AND status<>'paid' ORDER BY due_date,id FOR UPDATE",$sid) as $bl){ if($left<=0)break;
            $free=bill_out($bl)-pending_alloc((int)$bl['id']); if($free<=0)continue; $a=min($free,$left); $out[(int)$bl['id']]=$a; $left-=$a; }
        if($left>0) fail('Payment is ₹'.rupees($left).' more than this supplier\'s open bills. Reduce the amount or record it as a supplier advance.');
        return $out;
    }
    if(!is_array($alloc)) fail('Choose the bills to pay.');
    foreach($alloc as $x){ $bid=(int)($x['bill_id']??0); $a=money($x['amount']??null); if($a<=0)continue; if(isset($out[$bid])) fail('A bill is listed twice.');
        $bl=row('SELECT * FROM dd_bills WHERE id=? AND supplier_id=? AND voided=0 FOR UPDATE',$bid,$sid); if(!$bl) fail('Bill not found for this supplier.');
        $free=bill_out($bl)-pending_alloc($bid); if($a>$free) fail('Bill '.$bl['reference'].' has only ₹'.rupees(max(0,$free)).' left to pay.'); $out[$bid]=$a; $sum+=$a; }
    if(!$out) fail('Enter an amount for at least one bill.'); if($sum!==$amount) fail('Bill amounts (₹'.rupees($sum).') must add up to the payment (₹'.rupees($amount).').');
    return $out;
}
function pending_alloc(int $bill): int { return (int)row("SELECT COALESCE(SUM(a.amount),0) n FROM dd_voucher_allocations a JOIN dd_vouchers v ON v.id=a.voucher_id WHERE a.bill_id=? AND v.status IN ('requested','approved')",$bill)['n']; }
function supplier_credit(array $b,array $u): array {
    allowed($u,'vendor'); $ck=client_key($b); if($ck&&($x=row('SELECT id FROM dd_supplier_credits WHERE source_key=?','cr-'.$ck))) return ['ok'=>true,'duplicate'=>true,'id'=>(int)$x['id']];
    $sid=(int)($b['supplier_id']??0); if(!row('SELECT id FROM dd_suppliers WHERE id=?',$sid)) fail('Supplier not found.');
    $amt=money($b['amount']??null); if(!$amt) fail('Enter the credit amount.'); approval_limit_ok($u,$amt); $reason=txt($b['reason']??''); if($reason==='') fail('Write the reason (return, rate difference, damaged goods…).');
    $kind=in_array($b['kind']??'',['credit-note','return','debit'],true)?$b['kind']:'credit-note';
    if($kind==='debit'){ // extra charge from supplier = new payable line, recorded as a bill
        $ref=txt($b['reference']??'',120)?:('DEBIT-'.date('YmdHis')); $d=business_date(); $due=compute_due(terms_of($sid),$d);
        run("INSERT INTO dd_bills(supplier_id,reference,date,due_date,original_due_date,amount,paid,status,kind,created_by) VALUES(?,?,?,?,?,?,0,'unpaid','debit',?)",$sid,$ref,$d,$due,$due,$amt,$u['id']); audit($u,'supplier.debit',['supplier'=>$sid,'amount'=>$amt,'reason'=>$reason]); return ['ok'=>true,'bill_id'=>(int)db()->lastInsertId()]; }
    $bill=null; if(!empty($b['bill_id'])){ $bill=row('SELECT * FROM dd_bills WHERE id=? AND supplier_id=? AND voided=0 FOR UPDATE',(int)$b['bill_id'],$sid); if(!$bill) fail('Bill not found.');
        $free=bill_out($bill)-pending_alloc((int)$bill['id']); if($amt>$free) fail('Credit is more than the bill\'s open amount (₹'.rupees(max(0,$free)).'). Record the extra without a bill.'); }
    run('INSERT INTO dd_supplier_credits(supplier_id,bill_id,kind,amount,reference,reason,source_key,created_by) VALUES(?,?,?,?,?,?,?,?)',$sid,$bill['id']??null,$kind,$amt,txt($b['reference']??'',120),$reason,$ck?'cr-'.$ck:null,$u['id']);
    $id=(int)db()->lastInsertId(); if($bill){ supplier_credit_applied($id); run('UPDATE dd_bills SET credited=credited+? WHERE id=?',$amt,$bill['id']); bill_status_sync((int)$bill['id']); }
    audit($u,'supplier.credit',['id'=>$id,'supplier'=>$sid,'bill'=>$bill['id']??null,'amount'=>$amt,'reason'=>$reason]); return ['ok'=>true,'id'=>$id];
}
/** Record WHEN a credit/advance was applied (as-of AP reconciliation, fix C07). Silently skipped before migration 6. */
function supplier_credit_applied(int $id): void { if(mig_has_column('dd_supplier_credits','applied_at')) run('UPDATE dd_supplier_credits SET applied_at=? WHERE id=? AND applied_at IS NULL',now_sql(),$id); }
function supplier_apply_credit(array $b,array $u): array {
    allowed($u,'vendor'); $c=row('SELECT * FROM dd_supplier_credits WHERE id=? AND bill_id IS NULL FOR UPDATE',(int)($b['credit_id']??0)); if(!$c) fail('Unallocated credit/advance not found.');
    $bl=row('SELECT * FROM dd_bills WHERE id=? AND supplier_id=? AND voided=0 FOR UPDATE',(int)($b['bill_id']??0),$c['supplier_id']); if(!$bl) fail('Bill not found.');
    $use=min((int)$c['amount'],bill_out($bl)-pending_alloc((int)$bl['id'])); if($use<=0) fail('This bill has nothing left to pay.');
    if($use<(int)$c['amount']){ run('UPDATE dd_supplier_credits SET amount=? WHERE id=?',$use,$c['id']); run('INSERT INTO dd_supplier_credits(supplier_id,bill_id,kind,amount,reference,reason,ledger_id,created_by,created_at) VALUES(?,NULL,?,?,?,?,NULL,?,?)',$c['supplier_id'],$c['kind'],(int)$c['amount']-$use,$c['reference'],'Remainder of #'.$c['id'].': '.$c['reason'],$u['id'],$c['created_at']); }
    run('UPDATE dd_supplier_credits SET bill_id=? WHERE id=?',$bl['id'],$c['id']); supplier_credit_applied((int)$c['id']); run('UPDATE dd_bills SET credited=credited+? WHERE id=?',$use,$bl['id']); bill_status_sync((int)$bl['id']);
    audit($u,'supplier.credit.apply',['credit'=>$c['id'],'bill'=>$bl['id'],'amount'=>$use]); return ['ok'=>true,'applied'=>$use];
}
/** Statement: Opening + bills/debits − payments − credits = Closing. Each source counted once (bill payments via dd_bill_payments; advances via credits with a ledger link). */
function supplier_statement(int $sid,string $from,string $to): array {
    $s=row('SELECT id,name,phone,gstin,category FROM dd_suppliers WHERE id=?',$sid); if(!$s) fail('Supplier not found.',404);
    $ev=[];
    foreach(rows('SELECT id,date,reference,kind,amount,due_date,status,receipt_id FROM dd_bills WHERE supplier_id=? AND voided=0',$sid) as $b) $ev[]=['date'=>$b['date'],'type'=>$b['kind']==='opening'?'Opening balance':($b['kind']==='debit'?'Debit note':'Bill'),'ref'=>$b['reference'],'debit'=>(int)$b['amount'],'credit'=>0,'bill_id'=>(int)$b['id'],'due'=>$b['due_date']];
    foreach(rows('SELECT p.amount,l.date,l.reference,l.account,b.reference bill FROM dd_bill_payments p JOIN dd_bills b ON b.id=p.bill_id JOIN dd_ledger l ON l.id=p.ledger_id WHERE b.supplier_id=? AND l.voided=0',$sid) as $p) $ev[]=['date'=>$p['date'],'type'=>'Payment ('.$p['account'].')','ref'=>$p['reference'].' → '.$p['bill'],'debit'=>0,'credit'=>(int)$p['amount']];
    foreach(rows('SELECT c.*,l.date ldate,l.account FROM dd_supplier_credits c LEFT JOIN dd_ledger l ON l.id=c.ledger_id WHERE c.supplier_id=?',$sid) as $c) $ev[]=['date'=>$c['ldate']??substr((string)$c['created_at'],0,10),'type'=>$c['kind']==='advance'?'Advance paid ('.$c['account'].')':ucfirst(str_replace('-',' ',$c['kind'])),'ref'=>$c['reference']?:('#'.$c['id']),'debit'=>0,'credit'=>(int)$c['amount']];
    // Legacy payments recorded before partial-payment tracking existed are already inside dd_bill_payments (migration 2).
    usort($ev,fn($a,$b)=>[$a['date'],$a['credit']<=>0]<=>[$b['date'],$b['credit']<=>0]);
    $open=0; $rows=[]; $bal=0;
    foreach($ev as $e){ if($e['date']<$from){ $open+=$e['debit']-$e['credit']; continue; } if($e['date']>$to) continue; $rows[]=$e; }
    $bal=$open; foreach($rows as &$r){ $bal+=$r['debit']-$r['credit']; $r['balance']=$bal; } unset($r);
    $aging=['0-7'=>0,'8-15'=>0,'16-30'=>0,'31+'=>0]; $today=new DateTimeImmutable(business_date());
    foreach(rows("SELECT date,amount,paid,credited FROM dd_bills WHERE supplier_id=? AND voided=0 AND status<>'paid'",$sid) as $b){ $age=(int)(new DateTimeImmutable($b['date']))->diff($today)->format('%r%a'); $k=$age<=7?'0-7':($age<=15?'8-15':($age<=30?'16-30':'31+')); $aging[$k]+=bill_out($b); }
    return ['supplier'=>$s,'from'=>$from,'to'=>$to,'opening'=>$open,'rows'=>$rows,'closing'=>$bal,'purchases'=>array_sum(array_column(array_filter($rows,fn($r)=>$r['debit']>0),'debit')),'payments'=>array_sum(array_column($rows,'credit')),'aging'=>$aging,'balanceNow'=>supplier_balances($sid)[0]['balance']??0];
}
function statement_csv(array $st): string { $o="\xEF\xBB\xBF".implode(',',array_map('csv_cell',['Supplier',$st['supplier']['name'],'From',$st['from'],'To',$st['to']]))."\r\n".implode(',',array_map('csv_cell',['Date','Type','Reference','Bill / Debit','Payment / Credit','Balance']))."\r\n";
    $o.=implode(',',array_map('csv_cell',[$st['from'],'Opening','', '', '',rupees($st['opening'])]))."\r\n";
    foreach($st['rows'] as $r) $o.=implode(',',array_map('csv_cell',[$r['date'],$r['type'],$r['ref'],$r['debit']?rupees($r['debit']):'',$r['credit']?rupees($r['credit']):'',rupees($r['balance'])]))."\r\n";
    return $o.implode(',',array_map('csv_cell',[$st['to'],'Closing','','','',rupees($st['closing'])]))."\r\n"; }
function statement_pdf(array $st): string { require_once __DIR__.'/report360.php';
    // Reuse the paginated PDF writer by mapping statement rows to its snapshot shape.
    $entries=array_map(fn($r)=>['time'=>$r['date'],'type'=>$r['type'],'category'=>'','name'=>$r['ref'],'payee'=>null,'account'=>$r['debit']?'Bill':'Paid','to'=>null,'amount'=>$r['debit']?:$r['credit'],'by'=>'Bal '.number_format($r['balance']/100,0),'proof'=>'','source'=>''],$st['rows']);
    $s=['restaurant'=>setting('restaurant','Khadak Singh Da Dhaba').' - Supplier Statement: '.$st['supplier']['name'],'location'=>'Period '.$st['from'].' to '.$st['to'],'date'=>date('Y-m-d'),'version'=>1,'label'=>'STATEMENT','opening'=>$st['opening'],'expected'=>$st['closing'],'counted'=>$st['closing'],'variance'=>0,'note'=>'Aging: 0-7 '.rupees($st['aging']['0-7']).', 8-15 '.rupees($st['aging']['8-15']).', 16-30 '.rupees($st['aging']['16-30']).', 31+ '.rupees($st['aging']['31+']),'entries'=>$entries,'submittedBy'=>'','closedBy'=>'','closedAt'=>now_sql(),'sha256'=>hash('sha256',json_encode($st))];
    return report_pdf($s); }

/* ---------- dues dashboard ---------- */
function supplier_dues(): array {
    $t=business_date(); $bal=supplier_balances(); $d1=next_date($t); $d3=next_date($t,3); $d7=next_date($t,7); $d30=next_date($t,30);
    $sum=fn($sql,...$a)=>(int)row("SELECT COALESCE(SUM(amount-paid-credited),0) n FROM dd_bills WHERE voided=0 AND status<>'paid' AND $sql",...$a)['n'];
    $list=array_values(array_filter($bal,fn($s)=>$s['bill_outstanding']||$s['credit']||$s['holds']));
    usort($list,fn($a,$b)=>[$b['overdue']>0,$a['next_due']??'9999',$a['name']]<=>[$a['overdue']>0,$b['next_due']??'9999',$b['name']]);
    return ['today'=>$t,'cards'=>['outstanding'=>array_sum(array_column($bal,'balance')),'overdue'=>$sum('due_date<?',$t),'next7'=>$sum('due_date BETWEEN ? AND ?',$t,$d7),
        'holds'=>(int)row("SELECT COUNT(*) n FROM dd_goods_receipts WHERE status='hold'")['n'],'paidMonth'=>(int)row("SELECT COALESCE(SUM(p.amount),0) n FROM dd_bill_payments p JOIN dd_ledger l ON l.id=p.ledger_id WHERE l.voided=0 AND l.date>=?",substr($t,0,8).'01')['n'],
        'monthForecast'=>$sum('due_date BETWEEN ? AND ?',$t,(new DateTimeImmutable($t))->format('Y-m-t'))],
      'alerts'=>['dueToday'=>$sum('due_date=?',$t),'tomorrow'=>$sum('due_date=?',$d1),'next3'=>$sum('due_date BETWEEN ? AND ?',$d1,$d3),'overdue'=>$sum('due_date<?',$t),
        'unconfigured'=>count(array_filter($bal,fn($s)=>$s['bill_outstanding']>0&&!$s['terms_mode'])),'mismatch'=>(int)row("SELECT COUNT(*) n FROM dd_goods_receipts WHERE status='hold'")['n'],'supplierCredit'=>count(array_filter($bal,fn($s)=>$s['balance']<0))],
      'suppliers'=>$list,
      'calendar'=>rows("SELECT b.due_date,s.name supplier,b.supplier_id,SUM(b.amount-b.paid-b.credited) amount,COUNT(*) bills FROM dd_bills b JOIN dd_suppliers s ON s.id=b.supplier_id WHERE b.voided=0 AND b.status<>'paid' AND b.due_date<=? GROUP BY b.due_date,b.supplier_id,s.name ORDER BY b.due_date,s.name",$d30)];
}
function supplier_detail(int $sid,array $u): array {
    $fin=can($u,'vendor'); $b=supplier_balances($sid)[0]??null; if(!$b) fail('Supplier not found.',404); $t=terms_of($sid);
    if(!$fin) unset($b['phone']);
    $bills=rows('SELECT id,reference,date,due_date,original_due_date,amount,paid,credited,status,kind,receipt_id,proof_file_id FROM dd_bills WHERE supplier_id=? AND voided=0 ORDER BY (status=\'paid\'),due_date,id DESC LIMIT '.($fin?200:10),$sid);
    $ids=array_column($bills,'id'); $lines=[];
    if($ids&&$fin){ foreach(rows('SELECT bill_id,item_name,purchase_qty,purchase_unit,rate,total FROM dd_bill_lines WHERE bill_id IN ('.implode(',',array_map('intval',$ids)).') ORDER BY id') as $l) $lines[(int)$l['bill_id']][]=$l;
        $photos=[]; foreach(rows('SELECT r.bill_id,rf.file_id FROM dd_receipt_files rf JOIN dd_goods_receipts r ON r.id=rf.receipt_id WHERE r.bill_id IN ('.implode(',',array_map('intval',$ids)).')') as $p) $photos[(int)$p['bill_id']][]=(int)$p['file_id']; }
    foreach($bills as &$x){ $x['outstanding']=bill_out($x); if($fin){ $x['lines']=$lines[(int)$x['id']]??[]; $x['legacy']=!$x['lines']&&$x['kind']==='invoice'&&!$x['receipt_id']; $x['photos']=$photos[(int)$x['id']]??($x['proof_file_id']?[(int)$x['proof_file_id']]:[]); } } unset($x);
    $last=rows('SELECT l.item_id,l.item_name,l.purchase_unit,l.rate,l.created_at FROM dd_bill_lines l JOIN (SELECT item_id,purchase_unit,MAX(id) id FROM dd_bill_lines WHERE supplier_id=? GROUP BY item_id,purchase_unit) m ON m.id=l.id ORDER BY l.item_name',$sid);
    return ['supplier'=>$b,'terms'=>$t,'termsLabel'=>terms_label($t,(int)$b['credit_days']),'suggestedCycle'=>suggested_cycle($b['name'],(string)$b['category']),'bills'=>$bills,'lastPrices'=>$last,
        'openPos'=>rows("SELECT id,po_no,expected_date,status FROM dd_purchase_orders WHERE supplier_id=? AND status IN ('ordered','partial') ORDER BY id DESC",$sid),
        'credits'=>$fin?rows('SELECT id,kind,amount,reference,reason,bill_id,created_at FROM dd_supplier_credits WHERE supplier_id=? ORDER BY id DESC LIMIT 50',$sid):[]];
}
function purchase_report(string $from,string $to): array {
    return ['from'=>$from,'to'=>$to,
     'byItem'=>rows("SELECT l.item_id,l.item_name,l.stock_unit,SUM(l.stock_qty) qty,SUM(l.total) amount,COUNT(*) lines_n,MIN(l.rate) min_rate,MAX(l.rate) max_rate FROM dd_bill_lines l JOIN dd_bills b ON b.id=l.bill_id WHERE b.voided=0 AND b.date BETWEEN ? AND ? GROUP BY l.item_id,l.item_name,l.stock_unit ORDER BY amount DESC",$from,$to),
     'bySupplier'=>rows("SELECT s.name,COUNT(*) bills,SUM(b.amount) amount FROM dd_bills b JOIN dd_suppliers s ON s.id=b.supplier_id WHERE b.voided=0 AND b.kind='invoice' AND b.date BETWEEN ? AND ? GROUP BY s.id,s.name ORDER BY amount DESC",$from,$to),
     'pendingReview'=>rows("SELECT r.receipt_no,s.name supplier,r.invoice_ref,r.calculated_total,r.invoice_total,r.difference,r.hold_reason,r.created_at FROM dd_goods_receipts r JOIN dd_suppliers s ON s.id=r.supplier_id WHERE r.status='hold' ORDER BY r.id")];
}
/** Owner first-login reminder: computed live, so it disappears once resolved (no duplicates to dismiss). */
function yesterday_checklist(): array { $y=next_date(business_date(),-1); $day=row('SELECT status,workflow FROM dd_days WHERE date=?',$y);
    return ['date'=>$y,'items'=>array_values(array_filter([
      !row('SELECT 1 FROM dd_pos_days WHERE business_date=?',$y)?['key'=>'pos','text'=>'Petpooja sales for '.$y.' not imported']:null,
      (($day['status']??'open')!=='closed')?['key'=>'close','text'=>'Daybook '.$y.' not closed (status: '.($day['workflow']??'not started').')']:null,
      ($n=(int)row("SELECT COUNT(*) n FROM dd_bills b WHERE b.voided=0 AND b.kind='invoice' AND b.proof_file_id IS NULL AND b.date>=?",next_date($y,-30))['n'])?['key'=>'proof','text'=>$n.' supplier bill(s) without photo (last 30 days)']:null,
      ($o=(int)row("SELECT COALESCE(SUM(amount-paid-credited),0) n FROM dd_bills WHERE voided=0 AND status<>'paid' AND due_date<?",business_date())['n'])?['key'=>'overdue','text'=>'Overdue supplier payments ₹'.rupees($o)]:null,
      ($h=(int)row("SELECT COUNT(*) n FROM dd_goods_receipts WHERE status='hold'")['n'])?['key'=>'holds','text'=>$h.' Goods IN receipt(s) waiting for review']:null,
    ...(function_exists('fin_yesterday_items')?fin_yesterday_items($y):[]),
    ]))]; }
