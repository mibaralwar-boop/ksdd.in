<?php
declare(strict_types=1);
function positive_number(mixed $v,bool $zero=true): float { if(!is_numeric($v)||!is_finite((float)$v)||($zero?(float)$v<0:(float)$v<=0))fail('Enter a valid quantity.');return (float)$v; }
function locked_employee(int $id): array { $e=row('SELECT * FROM dd_employees WHERE id=? FOR UPDATE',$id);if(!$e)fail('Employee not found.');return $e; }
function existing(string $table,int $id): array { $r=row('SELECT * FROM dd_'.$table.' WHERE id=? FOR UPDATE',$id);if(!$r)fail('Record not found.');return $r; }
function mutate(string $route,array $b,array $u): array {
    $ok=['ok'=>true];$id=(int)($b['id']??0);
    switch($route){
    case 'entry': // V6: the generic desk entry cannot be used to book supplier, salary/advance or owner money as an expense (double-count risk).
        $t=(string)($b['type']??''); $txt=strtolower(($b['category']??'').' '.($b['name']??''));
        if(in_array($t,['expense','payment-out'],true)&&preg_match('/supplier|vendor/',$txt)) fail('Supplier payments must settle the supplier\'s bills: Control Centre → Supplier Dues → View / Pay. A generic expense would count the purchase twice.',409);
        if(in_array($t,['expense','payment-out'],true)&&preg_match('/\b(salary|salaries|advance|staff payment)\b/',$txt)) fail('Salary and staff advances must use Staff → payroll or the Cash OUT wizard (employee + month) so they link to payroll.',409);
        if(in_array($t,['expense','payment-out'],true)&&preg_match('/owner (draw|withdraw)|drawing/',$txt)&&!can($u,'manage')) fail('Owner drawings are owner-only and are not a restaurant expense.',403);
        return $ok+['id'=>add_entry($b,$u)];
    case 'void':
        $e=existing('ledger',$id);if($e['voided'])fail('Active entry not found.');day_post_guard($e['date']);
        if(txt($b['reason']??'')==='')fail('A correction reason is required.');
        if($u['role']==='cashier'&&((int)$e['created_by']!==(int)$u['id']||$e['source']!=='manual'))fail('A manager must correct this entry.',403);
        if(in_array($e['type'],['salary','advance'],true)){
            allowed($u,'payroll');locked_employee((int)$e['employee_id']);require_once __DIR__.'/finance360.php';
            if($e['type']==='advance'&&row('SELECT id FROM dd_payroll WHERE employee_id=? AND month=? AND approved=1',$e['employee_id'],$e['payroll_month']))fail('This advance is included in approved payroll. It cannot be voided.');
            if($e['type']==='advance'&&advance_outstanding((int)$e['employee_id'])<(int)$e['amount'])fail('Part of this advance has already been recovered through approved payroll. It cannot be voided.');
        }
        if($e['voucher_id']??null)fail('This entry is the payment of voucher #'.$e['voucher_id'].' (bills/payroll/recurring bills are linked to it). Ask the owner to reverse the voucher instead of voiding.');
        if(row('SELECT id FROM dd_bill_payments WHERE ledger_id=? LIMIT 1',$id))fail('This payment is allocated to supplier bills. Reverse the allocation first.');
        if(row('SELECT id FROM dd_supplier_credits WHERE ledger_id=? LIMIT 1',$id)||row('SELECT id FROM dd_bank_lines WHERE ledger_id=? LIMIT 1',$id))fail('This entry is linked to a supplier credit or a matched bank line. Unlink it first.');
        if($e['source']==='local-pos')fail('This payment belongs to a saved POS bill. Use a separate refund entry with its bill reference.');
        if(row('SELECT id FROM dd_bills WHERE ledger_id=?',$id)||row('SELECT id FROM dd_paytm WHERE ledger_id=?',$id))fail('This entry is linked to a supplier bill or Paytm transaction. Reconcile its source before correction.');
        run('UPDATE dd_ledger SET voided=1 WHERE id=?',$id);run('UPDATE dd_paytm SET matched_id=NULL WHERE matched_id=?',$id);audit($u,'ledger.void',['id'=>$id,'reason'=>txt($b['reason'])]);break;
    case 'allocate':
        allowed($u,'close');$e=existing('ledger',$id);if($e['voided']||$e['account']!=='Unallocated')fail('Unallocated entry not found.');day_post_guard($e['date']);
        $splits=$b['splits']??[];if(!is_array($splits)||count($splits)<1||count($splits)>10)fail('Provide 1–10 account splits.');$total=0;$accounts=[];
        foreach($splits as $x){$total+=money($x['amount']??null);if(($x['account']??'')==='Unallocated'||in_array($x['account']??'', $accounts,true))fail('Choose different allocated accounts.');$accounts[]=$x['account']??'';}
        if($total!==(int)$e['amount'])fail('Splits must equal the original amount.');
        foreach($splits as $x){$n=$e;$n['amount']=$x['amount'];$n['account']=$x['account'];$n['source']='allocation';$n['source_key']=null;$n['description']='Split from entry #'.$id.'. '.($e['description']??'');add_entry($n,$u);}
        run('UPDATE dd_ledger SET voided=1 WHERE id=?',$id);audit($u,'ledger.allocate',['id'=>$id,'splits'=>$splits]);break;
    case 'day':
        allowed($u,'close');$d=valid_date($b['date']??'');day_post_guard($d);run('UPDATE dd_days SET opening=?,opening_set=1,note=? WHERE date=?',money($b['opening']??null),txt($b['note']??''),$d);audit($u,'day.opening',['date'=>$d,'opening'=>money($b['opening']),'note'=>txt($b['note']??'')]);break;
    case 'close':
        allowed($u,'close');$d=valid_date($b['date']??'');lock_day($d);$day=row('SELECT * FROM dd_days WHERE date=?',$d);
        if(!$day['opening_set'])fail('Set opening cash before closing.');$entries=rows('SELECT * FROM dd_ledger WHERE date=? AND voided=0',$d);
        foreach($entries as $e)if($e['account']==='Unallocated')fail('Allocate all mixed-payment entries before closing.');
        $counted=money($b['counted']??null);$expected=summary($entries,(int)$day['opening'])['expectedCash'];$note=txt($b['note']??'');
        if($counted!==$expected&&$note==='')fail('Explain the cash difference before closing.');
        require_once __DIR__.'/lib360.php';close_day_core($d,$u,$counted,$note);break;
    case 'reopen':
        allowed($u,'manage');$d=valid_date($b['date']??'');if(!txt($b['reason']??''))fail('A reopening reason is required.');$day=row('SELECT * FROM dd_days WHERE date=? FOR UPDATE',$d);if(!$day||$day['status']!=='closed')fail('Closed day not found.');run("UPDATE dd_days SET status='open',workflow='draft',counted=NULL,expected=NULL,variance=NULL,variance_status='none',submitted_by=NULL,submitted_at=NULL,reviewed_by=NULL,reviewed_at=NULL WHERE date=?",$d);audit($u,'day.reopen',['date'=>$d,'reason'=>txt($b['reason'])]);break;
    case 'menu':
        allowed($u,'close');$price=money($b['price']??null);if(!$price)fail('Price must be positive.');run('INSERT INTO dd_menu(name,category,price) VALUES(?,?,?)',name_required($b['name']??''),txt($b['category']??'',80),$price);audit($u,'menu.create',['name'=>txt($b['name'])]);break;
    case 'order':
        $d=valid_date($b['date']??'');lock_day($d);if(!in_array($b['kind']??'', ['dine-in','takeaway','delivery'],true)||!is_array($b['items']??null)||!count($b['items'])||count($b['items'])>200)fail('Select an order type and 1–200 items.');
        $items=[];$subtotal=0;foreach($b['items'] as $x){$m=row('SELECT * FROM dd_menu WHERE id=? AND active=1',(int)($x['id']??0));$q=filter_var($x['quantity']??null,FILTER_VALIDATE_INT);if(!$m||!$q||$q<1||$q>999)fail('Invalid order item.');$items[]=['id'=>(int)$m['id'],'name'=>$m['name'],'quantity'=>$q,'price'=>(int)$m['price']];$subtotal+=(int)$m['price']*$q;}
        $discount=money($b['discount']??0);if($discount>$subtotal)fail('Discount exceeds subtotal.');$rate=positive_number($b['tax_rate']??0);if($rate>30)fail('Tax rate must be 0–30%.');$tax=(int)round(($subtotal-$discount)*$rate/100);$total=$subtotal-$discount+$tax;if($total<=0)fail('Order total must be positive.');
        $payments=$b['payments']??[];if(!is_array($payments)||count($payments)<1||count($payments)>10)fail('Provide 1–10 payment allocations.');$paid=0;foreach($payments as $x)$paid+=money($x['amount']??null);if($paid!==$total)fail('Payments must equal the order total.');
        $ref='POS-'.strtoupper(bin2hex(random_bytes(8)));foreach($payments as $i=>$x){if(($x['account']??'')==='Unallocated')fail('Choose an allocated payment account.');add_entry(['date'=>$d,'name'=>'POS '.$b['kind'].(!empty($b['table_no'])?' · Table '.txt($b['table_no'],20):''),'type'=>'sale','account'=>$x['account']??'','amount'=>$x['amount'],'category'=>'POS','reference'=>$ref,'source'=>'local-pos','source_key'=>$ref.'-'.$i],$u);}
        run('INSERT INTO dd_orders(date,kind,table_no,items,subtotal,discount,tax,total,reference) VALUES(?,?,?,?,?,?,?,?,?)',$d,$b['kind'],txt($b['table_no']??'',20),json_encode($items,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$subtotal,$discount,$tax,$total,$ref);audit($u,'order.create',['reference'=>$ref,'total'=>$total]);return $ok+['reference'=>$ref];
    case 'employee':
        allowed($u,'payroll');if(!in_array($b['pay_basis']??'', ['monthly','daily'],true))fail('Choose monthly or daily pay.');run('INSERT INTO dd_employees(name,job,salary,pay_basis) VALUES(?,?,?,?)',name_required($b['name']??''),txt($b['job']??'',100),money($b['salary']??null),$b['pay_basis']);audit($u,'employee.create',['id'=>(int)db()->lastInsertId(),'name'=>txt($b['name'])]);break;
    case 'attendance':
        allowed($u,'payroll');$d=valid_date($b['date']??'');$e=locked_employee((int)($b['employee_id']??0));if(!in_array($b['status']??'', ['present','half-day','absent','paid-leave'],true))fail('Invalid attendance status.');if(row('SELECT id FROM dd_payroll WHERE employee_id=? AND month=? AND approved=1',$e['id'],substr($d,0,7)))fail('Payroll is approved. Attendance is locked for this month.');run('INSERT INTO dd_attendance(employee_id,date,status) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)',$e['id'],$d,$b['status']);audit($u,'attendance.save',['employee_id'=>$e['id'],'date'=>$d,'status'=>$b['status']]);break;
    case 'payroll': // V6: advance recovery carries forward and is capped at advances actually paid (finance360.php)
        allowed($u,'payroll');require_once __DIR__.'/finance360.php';crm_ready();$month=valid_month($b['month']??'');$e=locked_employee((int)($b['employee_id']??0));payroll_calculate($e,$month,$b,$u);break;
    case 'payroll-approve':
        allowed($u,'payroll');require_once __DIR__.'/finance360.php';crm_ready();payroll_approve($id,$u,txt($b['note']??'',250));break;
    case 'inventory': // V6: opening stock is posted into Main Store through the location ledger, never only into the item total
        require_once __DIR__.'/lib360.php';crm_ready();$qty0=positive_number($b['quantity']??0);
        run('INSERT INTO dd_inventory(name,unit,quantity,reorder,cost,cost_source) VALUES(?,?,0,?,?,?)',name_required($b['name']??''),txt(name_required($b['unit']??''),40),positive_number($b['reorder']??0),money($b['cost']??0),money($b['cost']??0)>0?'manual':'none');$iid=(int)db()->lastInsertId();
        if($qty0>0)stock_move(['item_id'=>$iid,'qty'=>number_format($qty0,3,'.',''),'to'=>loc_id('Main Store'),'kind'=>'opening','date'=>business_date(),'reason'=>'Opening stock (item created)'],$u);
        audit($u,'inventory.create',['id'=>$iid,'name'=>txt($b['name'])]);break;
    case 'stock': // V6: legacy +/- adjustment now goes through Main Store balances and is flagged for owner review
        require_once __DIR__.'/lib360.php';crm_ready();$item=existing('inventory',$id);$d=valid_date($b['date']??'');$q=$b['quantity']??null;
        if(!is_numeric($q)||!is_finite((float)$q)||(float)$q===0.0||!txt($b['reason']??''))fail('Enter a stock movement and reason.');
        $abs=number_format(abs((float)$q),3,'.','');$store=loc_id('Main Store');
        $mid=stock_move(['item_id'=>$id,'qty'=>$abs,(float)$q>0?'to':'from'=>$store,'kind'=>'adjust','date'=>$d,'reason'=>'Desk adjustment: '.txt($b['reason'])],$u);
        inbox_raise('stock-adjust','store','Manual stock adjustment '.$item['name'].' '.((float)$q>0?'+':'−').$abs.' '.$item['unit'],['ref_table'=>'dd_stock_moves','ref_id'=>$mid,'date'=>$d,'by'=>$u['id'],'detail'=>txt($b['reason'])]);
        audit($u,'inventory.move',['id'=>$id,'quantity'=>$q,'reason'=>txt($b['reason']),'move'=>$mid]);break;
    case 'supplier':
        require_once __DIR__.'/supplier360.php';return $ok+['id'=>supplier_save($b,$u)]; // owner-only master with duplicate check
    case 'bill':
        month_lock_check(valid_date($b['date']??''));
        $amount=money($b['amount']??null);if(!$amount||!txt($b['reference']??''))fail('Provide a positive amount and invoice reference.');if(!row('SELECT id FROM dd_suppliers WHERE id=? AND active=1',(int)($b['supplier_id']??0)))fail('Supplier is disabled or missing.');$due=valid_date($b['due_date']??'');run('INSERT INTO dd_bills(supplier_id,reference,date,due_date,original_due_date,amount,created_by) VALUES(?,?,?,?,?,?,?)',(int)($b['supplier_id']??0),txt($b['reference'],120),valid_date($b['date']??''),$due,$due,$amount,$u['id']);audit($u,'bill.create',['id'=>(int)db()->lastInsertId()]);break;
    case 'bill-pay':
        // Legacy desk "Pay bill": pays the REMAINING balance once, as a liability settlement (not an expense), allocated to the bill.
        allowed($u,'close');require_once __DIR__.'/supplier360.php';$bill=existing('bills',$id);$left=bill_out($bill)-pending_alloc($id);if($bill['status']==='paid'||$left<=0)fail('This bill has no balance left to pay.');$supplier=row('SELECT name FROM dd_suppliers WHERE id=?',$bill['supplier_id']);$ledger=add_entry(['date'=>$b['date']??'','name'=>$supplier['name'],'type'=>'payment-out','account'=>$b['account']??'','amount'=>rupees($left),'category'=>'Supplier payment','reference'=>$bill['reference'],'source'=>'bill-pay','source_key'=>'billpay-'.$id.'-'.((int)$bill['paid']+(int)$bill['credited'])],$u);bill_allocate($id,$ledger,$left);break;
    case 'reservation':
        $d=valid_date($b['date']??'');$covers=filter_var($b['covers']??null,FILTER_VALIDATE_INT);if(!$covers||$covers<1||$covers>100||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',(string)($b['time']??'')))fail('Enter a valid time and 1–100 guests.');run('INSERT INTO dd_reservations(date,time,name,phone,covers,status,notes) VALUES(?,?,?,?,?,?,?)',$d,$b['time'],name_required($b['name']??''),txt($b['phone']??'',40),$covers,'booked',txt($b['notes']??''));audit($u,'reservation.create',['id'=>(int)db()->lastInsertId()]);break;
    case 'reservation-status':
        existing('reservations',$id);if(!in_array($b['status']??'', ['booked','seated','completed','cancelled','no-show'],true))fail('Invalid reservation status.');run('UPDATE dd_reservations SET status=? WHERE id=?',$b['status'],$id);audit($u,'reservation.status',['id'=>$id,'status'=>$b['status']]);break;
    case 'feedback':
        $rating=filter_var($b['rating']??null,FILTER_VALIDATE_INT);if(!$rating||$rating<1||$rating>5||!txt($b['comment']??''))fail('Enter 1–5 stars and feedback.');run('INSERT INTO dd_feedback(date,rating,comment) VALUES(?,?,?)',valid_date($b['date']??''),$rating,txt($b['comment']));audit($u,'feedback.create',['id'=>(int)db()->lastInsertId()]);break;
    case 'feedback-status':
        existing('feedback',$id);if(!in_array($b['status']??'', ['new','in progress','resolved'],true))fail('Invalid feedback status.');run('UPDATE dd_feedback SET status=?,response=? WHERE id=?',$b['status'],txt($b['response']??''),$id);audit($u,'feedback.update',['id'=>$id,'status'=>$b['status']]);break;
    case 'user':
        allowed($u,'manage');$email=strtolower(txt($b['email']??'',190));$password=(string)($b['password']??'');if(!isset(ROLES[$b['role']??''])||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12||strlen($password)>200)fail('Enter a valid role, email and password of 12–200 characters.');run('INSERT INTO dd_users(name,email,password,role) VALUES(?,?,?,?)',name_required($b['name']??''),$email,password_hash($password,PASSWORD_DEFAULT),$b['role']);audit($u,'user.create',['email'=>$email,'role'=>$b['role']]);break;
    case 'password':
        $current=existing('users',(int)$u['id']);$pw=(string)($b['password']??'');if(!password_verify((string)($b['old_password']??''),$current['password'])||strlen($pw)<12||strlen($pw)>200)fail('Check the current password; new password must have 12–200 characters.');run('UPDATE dd_users SET password=?,session_rev=session_rev+1 WHERE id=?',password_hash($pw,PASSWORD_DEFAULT),$u['id']);$_SESSION['rev']=(int)$current['session_rev']+1;session_regenerate_id(true);audit($u,'password.change');break;
    case 'settings':
        allowed($u,'manage');run("UPDATE dd_settings SET value=? WHERE `key`='restaurant'",name_required($b['restaurant']??''));audit($u,'settings.save');break;
    case 'staff-record':
        allowed($u,'payroll');$kind=$b['record_kind']??'';if(!in_array($kind,['joining','roster','leave','overtime','voucher','handover','acknowledgment'],true))fail('Invalid staff record type.');$d=valid_date($b['date']??'');$payload=$b['payload']??null;if(!is_array($payload)||array_is_list($payload)||strlen(json_encode($payload))>20000)fail('Invalid staff record.');
        if($kind==='roster'){$people=[];foreach(['section1a','section1b','section2','section3','section4','relief'] as $k){if(!empty($payload[$k])){locked_employee((int)$payload[$k]);$people[]=(string)$payload[$k];}}if(count(array_unique($people))!==count($people))fail('Assign each person to only one section in this shift.');}
        if($kind==='voucher'){foreach(['name','incident','staff_explanation','evidence'] as $k)if(!txt($payload[$k]??''))fail('Voucher needs a staff name, incident, evidence and staff explanation.');$payload['status']='draft - review required';$payload['voucher_no']='DV-'.str_replace('-','',$d).'-'.strtoupper(bin2hex(random_bytes(4)));}else $payload['status']='draft';
        if($kind==='leave'&&isset($payload['from_date'],$payload['to_date'])&&valid_date($payload['to_date'])<valid_date($payload['from_date']))fail('Leave end must not precede the start.');
        run('INSERT INTO dd_staff_records(kind,date,payload,created_by) VALUES(?,?,?,?)',$kind,$d,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$u['id']);$record=(int)db()->lastInsertId();audit($u,'staff.'.$kind.'.draft',['id'=>$record]);return $ok+['id'=>$record];
    case 'import': allowed($u,'close');require_once __DIR__.'/imports.php';return import_rows($b,$u);
    case 'match':
        allowed($u,'close');$p=existing('paytm',(int)($b['paytm_id']??0));$e=existing('ledger',(int)($b['ledger_id']??0));
        if($p['kind']!=='payment'||$p['status']!=='success'||$p['matched_id']||$e['voided']||$e['type']!=='sale'||$e['account']!=='Paytm'||$e['date']!==$p['date']||(int)$e['amount']!==(int)$p['amount'])fail('Match an unmatched successful payment to a Paytm sale with the same date and amount.');
        if(row('SELECT id FROM dd_paytm WHERE matched_id=?',$e['id']))fail('This sale is already matched.');run('UPDATE dd_paytm SET matched_id=? WHERE id=?',$e['id'],$p['id']);audit($u,'paytm.match',['paytm_id'=>$p['id'],'ledger_id'=>$e['id']]);break;
    default: fail('Unknown action',404);
    }
    return $ok;
}
