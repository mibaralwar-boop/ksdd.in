<?php
declare(strict_types=1);
// KSDD CRM 360 routes (crm-*). Called from api.php after authentication + CSRF. Every route checks permissions server-side.
require_once __DIR__.'/lib360.php';
require_once __DIR__.'/supplier360.php';
require_once __DIR__.'/finance360.php';
require_once __DIR__.'/gl360.php';
require_once __DIR__.'/post360.php';

function crm_dispatch(string $route,string $method,array $b,array $u): never {
    if($route==='file'){ $f=row('SELECT * FROM dd_files WHERE id=?',(int)($_GET['id']??0)); if(!$f||((int)$f['uploaded_by']!==(int)$u['id']&&!can($u,'read')&&!can($u,'store.view')))fail('File not found.',404);
        header('Content-Type: '.$f['mime']); header('Content-Disposition: inline'); readfile(storage_dir('uploads').'/'.$f['path']); exit; }
    if($route==='crm-migrate'){ allowed($u,'manage'); send(['ok'=>true,'applied'=>migrate_all($u),'schema'=>schema_version()]); }
    crm_ready();
    if($method==='GET'){
        if($route==='crm-report'){ $d=valid_date($_GET['date']??''); if(!can($u,'reports')&&!can($u,'cash.review'))fail('Report access is restricted.',403);
            require_once __DIR__.'/report360.php'; $s=snap_load($d,isset($_GET['v'])?(int)$_GET['v']:null); $fmt=$_GET['format']??'html'; audit($u,'report.view',['date'=>$d,'v'=>$s['version'],'format'=>$fmt]);
            if($fmt==='pdf'){header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="daybook-'.$d.'-v'.$s['version'].'.pdf"');echo report_pdf($s);exit;}
            if($fmt==='png'){$png=report_png($s);header('Content-Type: image/png');header('Content-Disposition: inline; filename="daybook-'.$d.'-v'.$s['version'].'.png"');echo $png;exit;}
            header('Content-Type: text/html; charset=utf-8'); header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'"); echo report_html($s); exit; }
        if($route==='crm-home') send(crm_home($u));
        if($route==='crm-admin'){ allowed($u,'inbox'); send(crm_admin($u)); }
        if($route==='crm-suppliers'){ if(!can($u,'store.in')&&!can($u,'vendor')&&!can($u,'purchase.view'))fail('Not allowed.',403);
            $fin=can($u,'vendor'); $recent=array_map('intval',array_column(rows('SELECT supplier_id,MAX(id) m FROM dd_goods_receipts WHERE created_by=? GROUP BY supplier_id ORDER BY m DESC LIMIT 5',$u['id']),'supplier_id'));
            send(['suppliers'=>array_map(fn($x)=>['id'=>(int)$x['id'],'name'=>$x['name'],'category'=>$x['category'],'active'=>(int)$x['active'],'balance'=>$x['balance'],'overdue'=>$x['overdue'],'next_due'=>$x['next_due'],'unpaid'=>$x['unpaid_count'],'recent'=>in_array((int)$x['id'],$recent,true)]+($fin?['phone'=>$x['phone']]:[]),supplier_balances(null,!$fin)),'units'=>UNITS]); }
        if($route==='crm-supplier'){ if(!can($u,'store.in')&&!can($u,'vendor')&&!can($u,'purchase.view'))fail('Not allowed.',403); send(supplier_detail((int)($_GET['id']??0),$u)); }
        if($route==='crm-supplier-dues'){ allowed($u,'vendor'); send(supplier_dues()); }
        if($route==='crm-supplier-statement'){ allowed($u,'vendor'); $from=valid_date($_GET['from']??substr(business_date(),0,8).'01'); $to=valid_date($_GET['to']??business_date()); $st=supplier_statement((int)($_GET['id']??0),$from,$to);
            $f=$_GET['format']??'json'; if($f==='csv'){ header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="supplier-statement-'.$st['supplier']['id'].'-'.$from.'-'.$to.'.csv"'); echo statement_csv($st); exit; }
            if($f==='pdf'){ header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="supplier-statement-'.$st['supplier']['id'].'.pdf"'); echo statement_pdf($st); exit; } send($st); }
        if($route==='crm-receipts'){ $all=can($u,'vendor'); if(!$all)allowed($u,'store.in');
            send(['receipts'=>rows('SELECT r.id,r.receipt_no,r.status,r.hold_reason,r.invoice_ref,r.invoice_date,r.calculated_total,r.invoice_total,r.difference,r.review_note,r.created_at,s.name supplier FROM dd_goods_receipts r JOIN dd_suppliers s ON s.id=r.supplier_id'.($all?'':' WHERE r.created_by='.(int)$u['id']).' ORDER BY r.id DESC LIMIT 50')]); }
        if($route==='crm-receipt'){ $r=row('SELECT r.*,s.name supplier,l.name location FROM dd_goods_receipts r JOIN dd_suppliers s ON s.id=r.supplier_id JOIN dd_locations l ON l.id=r.location_id WHERE r.id=?',(int)($_GET['id']??0));
            if(!$r||((int)$r['created_by']!==(int)$u['id']&&!can($u,'vendor')&&!can($u,'approve')))fail('Receipt not found.',404);
            $r['payload']=json_decode($r['payload'],true); $r['files']=array_map('intval',array_column(rows('SELECT file_id FROM dd_receipt_files WHERE receipt_id=?',$r['id']),'file_id'));
            $r['lines']=rows('SELECT item_name,purchase_qty,purchase_unit,stock_qty,stock_unit,rate,gross,discount,tax,total,stock_move_id FROM dd_bill_lines WHERE receipt_id=? ORDER BY id',$r['id']); send(['receipt'=>$r]); }
        if($route==='crm-purchase-report'){ allowed($u,'vendor'); send(purchase_report(valid_date($_GET['from']??substr(business_date(),0,8).'01'),valid_date($_GET['to']??business_date()))); }
        if($route==='crm-items'){ if(!can($u,'kitchen.request')&&!can($u,'kitchen.verify')&&!can($u,'store.view')&&!can($u,'purchase.view')&&!can($u,'write')) fail('Item access is restricted.',403); send(['items'=>crm_items(),'locations'=>rows('SELECT * FROM dd_locations WHERE active=1 ORDER BY id')]); }
        if($route==='crm-kitchen-last'){ allowed($u,'kitchen.request'); $loc=(int)($_GET['location_id']??loc_id('Main Kitchen'));
            $r=row('SELECT * FROM dd_kitchen_requests WHERE location_id=? AND target_date<? ORDER BY target_date DESC LIMIT 1',$loc,valid_date($_GET['before']??next_date(business_date())));
            send(['request'=>$r,'items'=>$r?rows('SELECT item_id,required_qty,priority,note FROM dd_kitchen_request_items WHERE request_id=?',$r['id']):[]]); }
        if(($r=fin_get($route,$u)??gl_get($route,$u)??post_get($route,$u))!==null) send($r);
        fail('Not found',404);
    }
    $r=transaction(fn()=>fin_mutate($route,$b,$u)??gl_mutate($route,$b,$u)??post_mutate($route,$b,$u)??crm_mutate($route,$b,$u)); send($r);
}

function crm_items(): array { return rows('SELECT id,name,name_hi,category,unit,aliases,quantity,reorder,safety_stock,cost,track_daily FROM dd_inventory WHERE active=1 ORDER BY category,name'); }
function mine(array $u,int $limit=30): array { return rows('SELECT id,date,name,type,account,amount,category,receipt_no,payee,voided,created_at FROM dd_ledger WHERE created_by=? ORDER BY id DESC LIMIT '.$limit,$u['id']); }

function crm_home(array $u): array {
    $d=business_date(); $p=ROLES[$u['role']]??[];
    $o=['user'=>['id'=>(int)$u['id'],'name'=>$u['name'],'role'=>$u['role'],'label'=>ROLE_LABELS[$u['role']]??$u['role'],'drawer'=>$u['drawer']??'Main counter','employee_id'=>$u['employee_id']?(int)$u['employee_id']:null],'perms'=>$p,'businessDate'=>$d,'tomorrow'=>next_date($d)];
    if(can($u,'cash.entry')){
        $c=day_cash($d); $s=$c['summary']; $dc=drawer_cash($d,drawer_name($u['drawer']??null)); $multi=count($dc['drawers'])>1;
        $o['cash']=['day'=>['date'=>$d,'opening'=>$multi?$dc['opening']:(int)$c['day']['opening'],'openingSet'=>(bool)$c['day']['opening_set'],'workflow'=>$c['day']['workflow'],'variance'=>$c['day']['variance']],
            'drawer'=>$dc,'cashIn'=>$multi?$dc['in']:$s['cashIn'],'cashOut'=>$multi?$dc['out']:$s['cashOut'],'expected'=>$multi?$dc['expected']:$s['expectedCash'],'dayExpected'=>$s['expectedCash'],'cashSales'=>$s['cashSales'],'receipts'=>$s['receipts'],
            'lastCount'=>row("SELECT id,kind,total,created_at FROM dd_cash_counts WHERE date=? ORDER BY id DESC LIMIT 1",$d),
            'inCategories'=>CASH_IN_CATEGORIES,'outCategories'=>CASH_OUT_CATEGORIES,'denoms'=>DENOMS,'directLimit'=>setting_int('cash_out_direct_limit',100000),
            'vouchers'=>rows("SELECT id,voucher_no,kind,payee,amount,category,date FROM dd_vouchers WHERE status='approved' ORDER BY id"),
            'staff'=>rows("SELECT id,name FROM dd_users WHERE active=1 AND id<>? AND role IN ('cashier','manager','owner','accountant') ORDER BY name",$u['id']),
            'handoversToAck'=>rows("SELECT h.id,h.counted,h.variance,a.name outgoing FROM dd_handovers h JOIN dd_users a ON a.id=h.outgoing_user WHERE h.incoming_user=? AND h.status='pending'",$u['id']),
            'today'=>array_map(fn($e)=>['time'=>ist_time($e['created_at']),'name'=>$e['name'],'type'=>$e['type'],'account'=>$e['account'],'amount'=>(int)$e['amount'],'receipt'=>$e['receipt_no'],'category'=>$e['category']],array_values(array_filter($c['entries'],fn($e)=>$e['account']==='Cash'||$e['to_account']==='Cash')))];
        $o['cash']['taxiIncentive']=setting_int('taxi_incentive',5000);
    }
    if(can($u,'kitchen.request')||can($u,'kitchen.verify')||can($u,'store.view')||can($u,'purchase.view')){ $o['items']=crm_items(); $o['locations']=rows('SELECT * FROM dd_locations WHERE active=1 ORDER BY id'); }
    if(can($u,'kitchen.request')){
        $o['kitchen']=['requests'=>rows('SELECT r.*,l.name location FROM dd_kitchen_requests r JOIN dd_locations l ON l.id=r.location_id WHERE r.created_by=? OR r.target_date>=? ORDER BY target_date DESC LIMIT 10',$u['id'],$d)];
        foreach($o['kitchen']['requests'] as &$r) $r['lines']=rows('SELECT ri.*,i.name,i.name_hi,i.unit FROM dd_kitchen_request_items ri JOIN dd_inventory i ON i.id=ri.item_id WHERE request_id=?',$r['id']); unset($r);
    }
    if(can($u,'store.view')){
        $o['store']=['balances'=>rows('SELECT b.item_id,b.location_id,b.quantity FROM dd_stock_balances b WHERE b.quantity<>0'),
          'toVerify'=>rows("SELECT r.*,l.name location FROM dd_kitchen_requests r JOIN dd_locations l ON l.id=r.location_id WHERE r.status='submitted' ORDER BY target_date"),
          'suppliers'=>rows('SELECT id,name FROM dd_suppliers WHERE active=1 ORDER BY name'),
          'pos'=>rows("SELECT p.id,p.po_no,p.status,p.expected_date,s.name supplier FROM dd_purchase_orders p JOIN dd_suppliers s ON s.id=p.supplier_id WHERE p.status IN ('ordered','partial') ORDER BY p.id DESC"),
          'low'=>rows('SELECT id,name,name_hi,unit,quantity,reorder FROM dd_inventory WHERE active=1 AND reorder>0 AND quantity<=reorder')];
        foreach($o['store']['toVerify'] as &$r) $r['lines']=rows('SELECT ri.*,i.name,i.name_hi,i.unit FROM dd_kitchen_request_items ri JOIN dd_inventory i ON i.id=ri.item_id WHERE request_id=?',$r['id']); unset($r);
        foreach($o['store']['pos'] as &$po) $po['lines']=rows('SELECT pl.*,i.name,i.unit FROM dd_po_lines pl JOIN dd_inventory i ON i.id=pl.item_id WHERE po_id=?',$po['id']); unset($po);
    }
    if(can($u,'purchase.view')) $o['purchase']=['needed'=>purchase_needed(),'orders'=>rows("SELECT p.*,s.name supplier FROM dd_purchase_orders p JOIN dd_suppliers s ON s.id=p.supplier_id ORDER BY p.id DESC LIMIT 30"),'suppliers'=>rows('SELECT id,name FROM dd_suppliers WHERE active=1 ORDER BY name')];
    if(can($u,'expense.submit')||can($u,'bill.submit')) $o['myRequests']=rows('SELECT id,voucher_no,kind,payee,amount,category,status,date FROM dd_vouchers WHERE requested_by=? ORDER BY id DESC LIMIT 30',$u['id']);
    if(can($u,'expense.submit')) $o['outCategories']=CASH_OUT_CATEGORIES;
    if(can($u,'checklist')){ $tpl=can($u,'close')?['opening','closing']:['housekeeping']; $o['checklists']=[];
        foreach($tpl as $t){ $items=json_decode(setting('checklist_'.$t,'[]'),true)?:[]; $run=row('SELECT * FROM dd_checklist_runs WHERE date=? AND template=?',$d,$t); $o['checklists'][]=['template'=>$t,'items'=>$items,'done'=>$run?json_decode($run['done'],true):[]]; } }
    if(!empty($u['employee_id'])){ $e=(int)$u['employee_id'];
        $o['self']=['payroll'=>rows('SELECT month,base,overtime,bonus,deductions,advance,gross,net,approved FROM dd_payroll WHERE employee_id=? ORDER BY month DESC LIMIT 12',$e),
          'advances'=>rows("SELECT date,amount,payroll_month FROM dd_ledger WHERE employee_id=? AND type='advance' AND voided=0 ORDER BY date DESC LIMIT 24",$e),
          'paid'=>rows("SELECT date,amount,payroll_month FROM dd_ledger WHERE employee_id=? AND type='salary' AND voided=0 ORDER BY date DESC LIMIT 24",$e),
          'requests'=>rows("SELECT voucher_no,amount,status,date FROM dd_vouchers WHERE employee_id=? AND kind='advance' ORDER BY id DESC LIMIT 10",$e)]; }
    $o['mine']=mine($u,15);
    return $o;
}
function purchase_needed(): array {
    return rows("SELECT ri.id,ri.item_id,i.name,i.name_hi,i.unit,ri.to_purchase qty,r.target_date,l.name location FROM dd_kitchen_request_items ri JOIN dd_kitchen_requests r ON r.id=ri.request_id JOIN dd_inventory i ON i.id=ri.item_id JOIN dd_locations l ON l.id=r.location_id WHERE ri.purchase_status='needed' ORDER BY r.target_date,i.name");
}
function crm_admin(array $u): array {
    $d=valid_date($_GET['date']??business_date()); $c=day_cash($d); $s=$c['summary'];
    $sd=supplier_dues(); $dues=row("SELECT COALESCE(SUM(amount-paid),0) due,COALESCE(SUM(CASE WHEN due_date<? THEN amount-paid ELSE 0 END),0) overdue FROM dd_bills WHERE status<>'paid'",$d);
    return ['date'=>$d,'drawers'=>drawer_summary($d),
     'overview'=>['opening'=>(int)$c['day']['opening'],'openingSource'=>$c['day']['opening_source'],'sales'=>$s['sales'],'byAccount'=>$s['salesByAccount'],'cashIn'=>$s['cashIn'],'cashOut'=>$s['cashOut'],'receipts'=>$s['receipts'],'expected'=>$s['expectedCash'],
        'counted'=>$c['day']['counted']!==null?(int)$c['day']['counted']:null,'variance'=>$c['day']['variance']!==null?(int)$c['day']['variance']:null,'workflow'=>$c['day']['workflow'],'status'=>$c['day']['status'],'expenses'=>$s['expenses'],'operatingExpenses'=>$s['operatingExpenses'],
        'supplierPaid'=>(int)row("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE date=? AND voided=0 AND category='Supplier payment'",$d)['n'],
        'advances'=>(int)row("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE date=? AND voided=0 AND type='advance'",$d)['n'],
        'vendorDue'=>$sd['cards']['outstanding'],'vendorOverdue'=>$sd['cards']['overdue'],'supplierCards'=>$sd['cards'],'supplierAlerts'=>$sd['alerts'],'pendingVouchers'=>(int)row("SELECT COUNT(*) n FROM dd_vouchers WHERE status='requested'")['n'],
        'kitchenPending'=>(int)row("SELECT COUNT(*) n FROM dd_kitchen_requests WHERE status='submitted'")['n'],'purchaseNeeded'=>count(purchase_needed()),
        'lowStock'=>(int)row('SELECT COUNT(*) n FROM dd_inventory WHERE active=1 AND reorder>0 AND quantity<=reorder')['n'],
        'openVariances'=>rows("SELECT date,variance,variance_status,note FROM dd_days WHERE variance IS NOT NULL AND variance<>0 AND variance_status IN ('open','explained') ORDER BY date DESC LIMIT 20")],
     'inbox'=>rows("SELECT i.*,u.name raised_name,a.name assigned_name FROM dd_inbox i LEFT JOIN dd_users u ON u.id=i.raised_by LEFT JOIN dd_users a ON a.id=i.assigned_to WHERE i.status IN ('open','clarify') ORDER BY FIELD(i.priority,'high','normal','low'),i.id DESC LIMIT 200"),
     'pos'=>['last'=>row("SELECT * FROM dd_pos_days ORDER BY imported_at DESC LIMIT 1"),'recent'=>rows('SELECT business_date,revision,cash,upi,card,other,net,status,reconcile_note,source,imported_at FROM dd_pos_days ORDER BY business_date DESC LIMIT 14')],
     'emails'=>rows('SELECT id,idem_key,subject,status,attempts,last_error,smtp_reply,sent_at,created_at FROM dd_email_queue ORDER BY id DESC LIMIT 20'),
     'jobs'=>rows('SELECT * FROM dd_jobs ORDER BY id DESC LIMIT 20'),
     'snapshots'=>rows('SELECT id,date,version,label,created_at FROM dd_day_snapshots ORDER BY date DESC,version DESC LIMIT 60'),
     'vouchers'=>rows("SELECT v.*,u.name requested_name FROM dd_vouchers v JOIN dd_users u ON u.id=v.requested_by ORDER BY v.id DESC LIMIT 60"),
     'bills'=>rows("SELECT b.*,s.name supplier,(b.amount-b.paid) outstanding FROM dd_bills b JOIN dd_suppliers s ON s.id=b.supplier_id ORDER BY (b.status='paid'),b.due_date LIMIT 100"),
     'kitchen'=>rows("SELECT r.id,r.target_date,r.status,l.name location,u.name chef,(SELECT COUNT(*) FROM dd_kitchen_request_items WHERE request_id=r.id) line_count FROM dd_kitchen_requests r JOIN dd_locations l ON l.id=r.location_id JOIN dd_users u ON u.id=r.created_by ORDER BY r.target_date DESC LIMIT 20"),
     'purchaseNeeded'=>purchase_needed(),'yesterday'=>yesterday_checklist(),'supplierDues'=>$sd,
     'drivers'=>rows('SELECT v.*,vc.status voucher_status FROM dd_driver_visits v LEFT JOIN dd_vouchers vc ON vc.id=v.voucher_id ORDER BY v.id DESC LIMIT 40'),
     'users'=>can($u,'manage')?rows('SELECT id,name,email,role,active,employee_id,drawer FROM dd_users ORDER BY name'):[],
     'employees'=>can($u,'payroll')?rows('SELECT id,name,job FROM dd_employees WHERE active=1 ORDER BY name'):[],
     'settings'=>can($u,'manage')?array_column(rows("SELECT `key`,value FROM dd_settings WHERE `key` NOT IN ('installed')"),'value','key'):[],
     'roles'=>ROLE_LABELS,'smtpConfigured'=>!empty(cfg()['smtp']['host']),'syncConfigured'=>!empty(cfg()['sync_token_sha256'])];
}

function crm_mutate(string $route,array $b,array $u): array {
    $ok=['ok'=>true];
    switch($route){
    case 'crm-upload': return $ok+['file_id'=>store_upload($b,$u)];
    // ---- Cashier ----
    case 'crm-cash-in': allowed($u,'cash.entry'); return post_cash($b,$u,'payment-in');
    case 'crm-cash-out': allowed($u,'cash.entry'); return post_cash($b,$u,'cash-out');
    case 'crm-count': allowed($u,'cash.count'); $d=business_date(); day_get($d); $kind=in_array($b['kind']??'',['closing','spot'],true)?$b['kind']:'spot';
        $c=save_count($d,$kind,$b['denoms']??null,$u); $dc=drawer_cash($d,drawer_name($u['drawer']??null)); $exp=$dc['expected']; return $ok+$c+['drawer'=>$dc['drawer'],'expected'=>$exp,'difference'=>$c['total']-$exp];
    case 'crm-handover': allowed($u,'cash.handover'); $d=business_date(); $in=(int)($b['incoming_user_id']??0);
        $to=row('SELECT * FROM dd_users WHERE id=? AND active=1',$in); if(!$to||$in===(int)$u['id']||!can($to,'cash.handover'))fail('Choose the incoming cashier.');
        $c=save_count($d,'handover',$b['denoms']??null,$u); $exp=drawer_cash($d,drawer_name($u['drawer']??null))['expected']; $var=$c['total']-$exp;
        if($var!==0&&!txt($b['note']??''))fail('Cash differs by ₹'.rupees($var).'. Write the reason.');
        run('INSERT INTO dd_handovers(date,outgoing_user,incoming_user,count_id,expected,counted,variance,note,out_ack_at) VALUES(?,?,?,?,?,?,?,?,?)',$d,$u['id'],$in,$c['id'],$exp,$c['total'],$var,txt($b['note']??''),now_sql());
        $hid=(int)db()->lastInsertId(); if($var) inbox_raise('handover-variance','cash','Handover difference ₹'.rupees($var).' ('.$u['name'].' → '.$to['name'].')',['amount'=>abs($var),'priority'=>'high','ref_table'=>'dd_handovers','ref_id'=>$hid,'date'=>$d,'by'=>$u['id']]);
        audit($u,'cash.handover',['id'=>$hid,'variance'=>$var]); return $ok+['id'=>$hid,'variance'=>$var];
    case 'crm-handover-ack': allowed($u,'cash.handover'); $h=row("SELECT * FROM dd_handovers WHERE id=? AND incoming_user=? AND status='pending' FOR UPDATE",(int)($b['id']??0),$u['id']); if(!$h)fail('Handover not found.');
        run("UPDATE dd_handovers SET status='acknowledged',in_ack_at=? WHERE id=?",now_sql(),$h['id']); audit($u,'cash.handover.ack',['id'=>$h['id']]); return $ok;
    case 'crm-day-submit': allowed($u,'cash.submit'); return day_submit(business_date(),$u,txt($b['note']??''));
    // ---- Manager / owner day control ----
    case 'crm-day-return': allowed($u,'cash.review'); $d=valid_date($b['date']??''); $day=row('SELECT * FROM dd_days WHERE date=? FOR UPDATE',$d);
        if(!$day||!in_array($day['workflow'],['submitted','reviewed'],true))fail('Submitted day not found.'); if(!txt($b['reason']??''))fail('Give a reason.');
        run("UPDATE dd_days SET workflow='draft' WHERE date=?",$d); audit($u,'day.return',['date'=>$d,'reason'=>txt($b['reason'])]); return $ok;
    case 'crm-day-close': allowed($u,'close'); $d=valid_date($b['date']??''); lock_day($d); $day=row('SELECT * FROM dd_days WHERE date=? FOR UPDATE',$d);
        if(!$day['opening_set'])fail('Set opening cash before closing.');
        if(!in_array($day['workflow'],['submitted','reviewed'],true)&&!can($u,'*'))fail('The cashier must submit the closing count first.');
        if(rows("SELECT id FROM dd_ledger WHERE date=? AND voided=0 AND account='Unallocated'",$d))fail('Allocate all mixed-payment entries before closing.');
        $counted=$day['counted']!==null&&in_array($day['workflow'],['submitted','reviewed'],true)?(int)$day['counted']:money($b['counted']??null);
        $note=txt($b['note']??'')?:(string)$day['note']; $exp=day_cash($d)['summary']['expectedCash'];
        if($counted!==$exp&&trim($note)==='')fail('Explain the cash difference before closing.');
        return close_day_core($d,$u,$counted,$note);
    case 'crm-opening': allowed($u,'close'); $d=valid_date($b['date']??''); day_post_guard($d); run("UPDATE dd_days SET opening=?,opening_set=1,opening_source='manual' WHERE date=?",money($b['opening']??null),$d); audit($u,'day.opening',['date'=>$d,'opening'=>money($b['opening'])]); return $ok;
    // ---- Vouchers ----
    case 'crm-voucher': $kind=(string)($b['kind']??'');
        if($kind==='expense') allowed($u,'expense.submit'); elseif($kind==='supplier'){ if(!can($u,'bill.submit')&&!can($u,'vendor'))fail('Your role cannot request supplier payments.',403); }
        elseif($kind==='advance'){ if(!can($u,'payroll')){ if(empty($u['employee_id'])||(int)($b['employee_id']??0)!==(int)$u['employee_id'])fail('You can request an advance only for yourself.',403); } }
        else allowed($u,'approve');
        return voucher_create($b,$u);
    case 'crm-voucher-decide': allowed($u,'approve'); voucher_decide((int)($b['id']??0),($b['decision']??'')==='approve',$u,txt($b['note']??'')); return $ok;
    case 'crm-voucher-pay': allowed($u,'voucher.pay'); return voucher_pay((int)($b['id']??0),$u,(string)($b['account']??'Cash'));
    // ---- Inbox ----
    case 'crm-inbox-act': allowed($u,'inbox'); $i=row('SELECT * FROM dd_inbox WHERE id=? FOR UPDATE',(int)($b['id']??0)); if(!$i)fail('Inbox item not found.');
        $act=(string)($b['act']??''); $note=txt($b['note']??'');
        if(!in_array($act,['approve','reject','clarify','assign','resolve','comment','escalate'],true))fail('Unknown action.');
        if(in_array($act,['reject','clarify','resolve'],true)&&$note==='')fail('Write a short note.');
        if($i['ref_table']==='dd_vouchers'&&in_array($act,['approve','reject'],true)){ voucher_decide((int)$i['ref_id'],$act==='approve',$u,$note); return $ok; }
        if($i['ref_table']==='dd_stock_counts'&&in_array($act,['approve','reject'],true)){ stock_count_decide((int)$i['ref_id'],$act==='approve',$u,$note); return $ok; }
        if($i['ref_table']==='dd_stock_consumptions'&&in_array($act,['approve','reject'],true)){ usage_decide((int)$i['ref_id'],$act==='approve',$u,$note?:'Approved'); return $ok; }
        if($i['type']==='cash-variance'&&$act==='approve'){ $day=row('SELECT * FROM dd_days WHERE date=? FOR UPDATE',$i['business_date']); if((int)$day['submitted_by']===(int)$u['id'])fail('You cannot approve your own cash difference.',403); if($note==='')fail('Write why the difference is accepted.'); run("UPDATE dd_days SET variance_status='explained' WHERE date=?",$i['business_date']); }
        $status=match($act){'approve'=>'approved','reject'=>'rejected','clarify'=>'clarify','resolve'=>'resolved',default=>$i['status']};
        $assign=$act==='assign'?(int)($b['user_id']??0):$i['assigned_to']; if($act==='assign'&&!row('SELECT id FROM dd_users WHERE id=?',$assign))fail('Choose a person.');
        $prio=$act==='escalate'?'high':$i['priority'];
        run('UPDATE dd_inbox SET status=?,assigned_to=?,priority=?,resolved_by=?,resolved_at=? WHERE id=?',$status,$assign,$prio,in_array($status,['approved','rejected','resolved'],true)?$u['id']:null,in_array($status,['approved','rejected','resolved'],true)?now_sql():null,$i['id']);
        run('INSERT INTO dd_inbox_events(inbox_id,user_id,action,note) VALUES(?,?,?,?)',$i['id'],$u['id'],$act,$note); return $ok;
    case 'crm-inbox-history': allowed($u,'inbox'); return $ok+['events'=>rows('SELECT e.*,u.name FROM dd_inbox_events e LEFT JOIN dd_users u ON u.id=e.user_id WHERE inbox_id=? ORDER BY e.id',(int)($b['id']??0))];
    // ---- Kitchen ----
    case 'crm-kitchen-request': allowed($u,'kitchen.request'); $d=valid_date($b['target_date']??next_date(business_date())); $loc=(int)($b['location_id']??loc_id('Main Kitchen'));
        if(!row("SELECT id FROM dd_locations WHERE id=? AND kind='kitchen'",$loc))fail('Choose a kitchen.'); $lines=$b['items']??[]; if(!is_array($lines)||!$lines||count($lines)>300)fail('Add at least one item.');
        $ex=row('SELECT * FROM dd_kitchen_requests WHERE target_date=? AND location_id=? FOR UPDATE',$d,$loc);
        if($ex&&$ex['status']!=='submitted')fail('The store has already processed this list. Add urgent items as a new note to the storekeeper.');
        if($ex){ run('DELETE FROM dd_kitchen_request_items WHERE request_id=?',$ex['id']); run('UPDATE dd_kitchen_requests SET note=?,submitted_at=? WHERE id=?',txt($b['note']??''),now_sql(),$ex['id']); $rid=(int)$ex['id']; }
        else { run('INSERT INTO dd_kitchen_requests(target_date,location_id,note,created_by) VALUES(?,?,?,?)',$d,$loc,txt($b['note']??''),$u['id']); $rid=(int)db()->lastInsertId(); }
        $seen=[]; foreach($lines as $l){ $item=(int)($l['item_id']??0); if(isset($seen[$item]))fail('An item is listed twice.'); $seen[$item]=1; if(!row('SELECT id FROM dd_inventory WHERE id=? AND active=1',$item))fail('Unknown item.');
            $req=qty($l['required']??null,false); $av=qty(($l['available']??'')===''?'0':$l['available']); $short=qpos(qsub($req,$av));
            run('INSERT INTO dd_kitchen_request_items(request_id,item_id,required_qty,kitchen_available,shortage_qty,priority,note) VALUES(?,?,?,?,?,?,?)',$rid,$item,$req,$av,$short,($l['priority']??'')==='urgent'?'urgent':'normal',txt($l['note']??'',250)); }
        inbox_raise('kitchen-request','kitchen','Kitchen requirement for '.$d.' submitted',['ref_table'=>'dd_kitchen_requests','ref_id'=>$rid,'date'=>$d,'by'=>$u['id'],'dedupe'=>'kreq-'.$rid]);
        audit($u,'kitchen.request',['id'=>$rid,'lines'=>count($lines)]); return $ok+['id'=>$rid];
    case 'crm-kitchen-verify': allowed($u,'kitchen.verify'); $r=row("SELECT * FROM dd_kitchen_requests WHERE id=? AND status='submitted' FOR UPDATE",(int)($b['request_id']??0)); if(!$r)fail('Pending kitchen list not found.');
        $store=loc_id('Main Store'); $over=[]; foreach(($b['issue']??[]) as $k=>$v) $over[(int)$k]=qty($v);
        $needBuy=0; foreach(rows('SELECT * FROM dd_kitchen_request_items WHERE request_id=? FOR UPDATE',$r['id']) as $l){
            $avail=qpos(stock_at((int)$l['item_id'],$store,true)); $issue=qmin($l['shortage_qty'],$avail); if(isset($over[(int)$l['id']])){ $issue=$over[(int)$l['id']]; if(bccomp_s($issue,$avail)>0||bccomp_s($issue,$l['shortage_qty'])>0)fail('Issue quantity cannot exceed store stock or the shortage.'); }
            if(bccomp_s($issue,'0')>0) stock_move(['item_id'=>$l['item_id'],'qty'=>$issue,'from'=>$store,'to'=>(int)$r['location_id'],'kind'=>'transfer','date'=>business_date(),'reason'=>'Kitchen request #'.$r['id'],'request_item_id'=>$l['id']],$u);
            $buy=qpos(qsub($l['shortage_qty'],$issue)); if(bccomp_s($buy,'0')>0)$needBuy++;
            run('UPDATE dd_kitchen_request_items SET store_available=?,to_issue=?,issued_qty=?,to_purchase=?,purchase_status=? WHERE id=?',$avail,$issue,$issue,$buy,bccomp_s($buy,'0')>0?'needed':'none',$l['id']); }
        run('UPDATE dd_kitchen_requests SET status=?,verified_by=?,verified_at=? WHERE id=?',$needBuy?'partial':'issued',$u['id'],now_sql(),$r['id']);
        inbox_close_for('dd_kitchen_requests',(int)$r['id'],'resolved',$u,'Verified by store');
        if($needBuy) inbox_raise('purchase-needed','purchase',$needBuy.' item(s) to buy for '.$r['target_date'],['ref_table'=>'dd_kitchen_requests','ref_id'=>(int)$r['id'],'date'=>$r['target_date'],'by'=>$u['id'],'dedupe'=>'buy-'.$r['id']]);
        audit($u,'kitchen.verify',['id'=>$r['id'],'toBuy'=>$needBuy]); return $ok+['toBuy'=>$needBuy];
    case 'crm-kitchen-ack': allowed($u,'kitchen.request'); $r=row("SELECT * FROM dd_kitchen_requests WHERE id=? AND status IN ('issued','partial') FOR UPDATE",(int)($b['request_id']??0)); if(!$r)fail('Issued list not found.');
        foreach(($b['received']??[]) as $k=>$v) run('UPDATE dd_kitchen_request_items SET received_qty=? WHERE id=? AND request_id=?',qty($v),(int)$k,$r['id']);
        run('UPDATE dd_kitchen_requests SET acknowledged_by=? WHERE id=?',$u['id'],$r['id']);
        foreach(rows('SELECT ri.*,i.name FROM dd_kitchen_request_items ri JOIN dd_inventory i ON i.id=ri.item_id WHERE request_id=? AND received_qty IS NOT NULL AND received_qty<>issued_qty',$r['id']) as $m) inbox_raise('kitchen-receipt-diff','store','Kitchen received '.$m['received_qty'].' of '.$m['issued_qty'].' '.$m['name'],['ref_table'=>'dd_kitchen_requests','ref_id'=>(int)$r['id'],'dedupe'=>'kack-'.$m['id']]);
        audit($u,'kitchen.ack',['id'=>$r['id']]); return $ok;
    // ---- Store ----
    case 'crm-goods-receipt': return goods_receipt_submit($b,$u);
    case 'crm-goods-in': // legacy field names mapped onto the strict Goods IN (total match, photo, item lines, single transaction)
        return goods_receipt_submit(['client_key'=>$b['client_key']??('legacy'.bin2hex(random_bytes(10))),'supplier_id'=>$b['supplier_id']??0,'location_id'=>$b['location_id']??null,'po_id'=>$b['po_id']??null,'invoice_ref'=>$b['bill_reference']??$b['invoice_ref']??'','invoice_date'=>$b['date']??'',
            'invoice_total'=>$b['bill_amount']??$b['invoice_total']??'','file_ids'=>!empty($b['proof_file_id'])?[(int)$b['proof_file_id']]:($b['file_ids']??[]),'lines'=>$b['lines']??[],'action'=>$b['action']??'post','note'=>$b['note']??'','hold_reason'=>$b['hold_reason']??'','confirm_duplicate'=>$b['confirm_duplicate']??null],$u);
    case 'crm-receipt-decide': return receipt_decide($b,$u);
    case 'crm-supplier-save': return $ok+['id'=>supplier_save($b,$u)];
    case 'crm-supplier-terms': terms_save((int)($b['supplier_id']??0),$b,$u); return $ok;
    case 'crm-supplier-credit': return supplier_credit($b,$u);
    case 'crm-supplier-apply-credit': return supplier_apply_credit($b,$u);
    case 'crm-bill-reschedule': allowed($u,'manage'); $bl=row('SELECT * FROM dd_bills WHERE id=? AND voided=0 FOR UPDATE',(int)($b['bill_id']??0)); if(!$bl)fail('Bill not found.'); $nd=valid_date($b['due_date']??''); $why=txt($b['reason']??''); if($why==='')fail('Write the reason for rescheduling.');
        run('UPDATE dd_bills SET due_date=? WHERE id=?',$nd,$bl['id']); audit($u,'bill.reschedule',['bill'=>$bl['id'],'from'=>$bl['due_date'],'to'=>$nd,'original'=>$bl['original_due_date'],'reason'=>$why]); return $ok;
    case 'crm-goods-out': allowed($u,'store.out'); $from=(int)($b['from_location_id']??loc_id('Main Store')); $to=(int)($b['to_location_id']??0); if($from===$to||!row('SELECT id FROM dd_locations WHERE id=?',$to))fail('Choose where the goods went.');
        foreach(($b['lines']??[]) as $l) stock_move(['item_id'=>(int)$l['item_id'],'qty'=>qty($l['qty']??null,false),'from'=>$from,'to'=>$to,'kind'=>'transfer','date'=>business_date(),'reason'=>'Issued to '.txt($b['received_by']??'kitchen',80)],$u);
        audit($u,'store.goods-out',['from'=>$from,'to'=>$to]); return $ok;
    case 'crm-wastage': allowed($u,'store.out'); $reason=txt($b['reason']??''); if(!in_array($b['kind']??'',['wastage','expired','damaged','return'],true)||$reason==='')fail('Choose the type and write the reason.');
        stock_move(['item_id'=>(int)($b['item_id']??0),'qty'=>qty($b['qty']??null,false),'from'=>(int)($b['location_id']??loc_id('Main Store')),'kind'=>$b['kind'],'date'=>business_date(),'reason'=>$reason,'supplier_id'=>!empty($b['supplier_id'])?(int)$b['supplier_id']:null],$u); return $ok;
    case 'crm-stock-count': allowed($u,'store.count'); $loc=(int)($b['location_id']??loc_id('Main Store')); $d=business_date(); $res=['matched'=>0,'review'=>0];
        foreach(($b['lines']??[]) as $l){ $item=(int)($l['item_id']??0); $c=qty($l['counted']??null); $sys=stock_at($item,$loc,true); $diff=qsub($c,$sys); $zero=bccomp_s($diff,'0')===0;
            if(!$zero&&!txt($l['reason']??''))fail('Write a reason for each difference.');
            run('INSERT INTO dd_stock_counts(date,item_id,location_id,system_qty,counted_qty,difference,reason,status,counted_by) VALUES(?,?,?,?,?,?,?,?,?)',$d,$item,$loc,$sys,$c,$diff,txt($l['reason']??''),$zero?'matched':'pending',$u['id']); $cid=(int)db()->lastInsertId();
            if($zero){$res['matched']++;continue;} $res['review']++; $nm=row('SELECT name,unit FROM dd_inventory WHERE id=?',$item);
            inbox_raise('stock-difference','store','Stock difference: '.$nm['name'].' '.$diff.' '.$nm['unit'],['ref_table'=>'dd_stock_counts','ref_id'=>$cid,'date'=>$d,'by'=>$u['id'],'detail'=>'System '.$sys.', counted '.$c.'. Reason: '.txt($l['reason']??'')]); }
        return $ok+$res;
    case 'crm-item': if(!can($u,'write'))fail('Only Admin/Manager can edit the item master.',403); $id=(int)($b['id']??0);
        $vals=[name_required($b['name']??''),txt($b['name_hi']??'',160),txt($b['category']??'Other',80),txt(name_required($b['unit']??''),40),txt($b['aliases']??'',400),qty(($b['reorder']??'')===''?'0':$b['reorder']),qty(($b['safety_stock']??'')===''?'0':$b['safety_stock'])];
        if($id){ $old=row('SELECT unit,quantity FROM dd_inventory WHERE id=? FOR UPDATE',$id); if(!$old)fail('Item not found.'); if($old['unit']!==$vals[3]&&qm($old['quantity'])!==0)fail('Unit cannot change while stock exists (would mix units). Create a new item or zero the stock first.');
            run('UPDATE dd_inventory SET name=?,name_hi=?,category=?,unit=?,aliases=?,reorder=?,safety_stock=? WHERE id=?',...[...$vals,$id]); } else { run('INSERT INTO dd_inventory(name,name_hi,category,unit,aliases,reorder,safety_stock,quantity) VALUES(?,?,?,?,?,?,?,0)',...$vals); $id=(int)db()->lastInsertId(); }
        if(array_key_exists('track_daily',$b)) run('UPDATE dd_inventory SET track_daily=? WHERE id=?',!empty($b['track_daily'])?1:0,$id);
        if(isset($b['cost'])&&$b['cost']!==''){ allowed($u,'manage'); run("UPDATE dd_inventory SET cost=?,cost_source='manual' WHERE id=?",money($b['cost']),$id); audit($u,'item.cost',['id'=>$id,'cost'=>money($b['cost'])]); } // owner-verified opening cost per stock unit
        audit($u,'item.save',['id'=>$id]); return $ok+['id'=>$id];
    // ---- Purchasing & vendors ----
    case 'crm-po': allowed($u,'purchase.order'); $sup=(int)($b['supplier_id']??0); if(!row('SELECT id FROM dd_suppliers WHERE id=?',$sup))fail('Choose the supplier.'); $lines=$b['lines']??[]; if(!is_array($lines)||!$lines)fail('Add items.');
        $no=seq('PO',business_date()); run('INSERT INTO dd_purchase_orders(po_no,supplier_id,expected_date,note,created_by) VALUES(?,?,?,?,?)',$no,$sup,!empty($b['expected_date'])?valid_date($b['expected_date']):null,txt($b['note']??''),$u['id']); $po=(int)db()->lastInsertId();
        foreach($lines as $l){ run('INSERT INTO dd_po_lines(po_id,item_id,quantity,rate) VALUES(?,?,?,?)',$po,(int)$l['item_id'],qty($l['qty']??null,false),money(($l['rate']??'')===''?'0':$l['rate'])); }
        foreach(($b['request_item_ids']??[]) as $ri) run("UPDATE dd_kitchen_request_items SET purchase_status='ordered' WHERE id=? AND purchase_status='needed'",(int)$ri);
        audit($u,'purchase.order',['id'=>$po,'no'=>$no]); return $ok+['id'=>$po,'po_no'=>$no];
    case 'crm-bill': allowed($u,'vendor'); if(empty($b['proof_file_id']))fail('Attach the bill photo. For goods, use Supplier Bill + Stock IN instead.'); if(!row('SELECT id FROM dd_suppliers WHERE id=? AND active=1',(int)($b['supplier_id']??0)))fail('Supplier is disabled or missing.');
        $id=bill_register((int)($b['supplier_id']??0),txt($b['reference']??'',120),valid_date($b['date']??business_date()),money($b['amount']??null),check_file(isset($b['proof_file_id'])?(int)$b['proof_file_id']:null,$u),null,$u,!empty($b['confirm_duplicate']),!empty($b['due_date'])?valid_date($b['due_date']):null);
        return $ok+['id'=>$id];
    case 'crm-supplier': return $ok+['id'=>supplier_save($b,$u)];
    // ---- Drivers ----
    case 'crm-driver-visit': allowed($u,'drivers'); $kind=($b['kind']??'')==='bus'?'bus':'taxi'; $veh=strtoupper(preg_replace('/[^A-Za-z0-9]/','',(string)($b['vehicle_no']??''))); if(strlen($veh)<6)fail('Enter the vehicle number.');
        $phone=preg_replace('/\D/','',(string)($b['phone']??'')); if($kind==='taxi'&&!preg_match('/^[6-9]\d{9}$/',$phone))fail('Enter a valid 10-digit mobile number.');
        $amount=$kind==='taxi'?setting_int('taxi_incentive',5000):money($b['amount']??null); if(!$amount)fail('Enter the tip amount.');
        if($kind==='taxi'&&empty($b['yellow_plate']))fail('Taxi incentive is only for yellow-plate commercial taxis.');
        $d=business_date(); run('INSERT INTO dd_driver_visits(kind,driver_name,phone,vehicle_no,yellow_plate,visit_date,trip_ref,passengers,amount,free_food,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)',$kind,name_required($b['driver_name']??''),$phone,$veh,!empty($b['yellow_plate'])?1:0,$d,txt($b['trip_ref']??'',60),($b['passengers']??'')!==''?(int)$b['passengers']:null,$amount,!empty($b['free_food'])?1:0,$u['id']);
        $vid=(int)db()->lastInsertId(); $auto=$amount<=setting_int('driver_auto_approve_limit',20000)?'driver_auto_approve_limit':null;
        $v=voucher_create(['amount'=>rupees($amount),'date'=>$d,'note'=>$veh.' '.txt($b['trip_ref']??'')],$u,['kind'=>'driver','payee'=>txt($b['driver_name'],120).' ('.$veh.')','category'=>$kind==='bus'?'Bus driver tip':'Taxi incentive','visit_id'=>$vid,'auto_approve'=>$auto]);
        run('UPDATE dd_driver_visits SET voucher_id=? WHERE id=?',$v['id'],$vid);
        $paid=null; if($auto&&!empty($b['pay_now'])&&can($u,'voucher.pay')) $paid=voucher_pay((int)$v['id'],$u);
        return $ok+['visit_id'=>$vid,'voucher'=>$v,'paid'=>$paid,'needsApproval'=>!$auto];
    // ---- Housekeeping / problems ----
    case 'crm-checklist': allowed($u,'checklist'); $t=(string)($b['template']??''); if(!in_array($t,['housekeeping','opening','closing'],true))fail('Unknown checklist.'); if($t!=='housekeeping')allowed($u,'close');
        $items=json_decode(setting('checklist_'.$t,'[]'),true)?:[]; $done=array_values(array_intersect($items,(array)($b['done']??[])));
        run('INSERT INTO dd_checklist_runs(date,template,items,done,completed_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE done=VALUES(done),completed_by=VALUES(completed_by),updated_at=CURRENT_TIMESTAMP',business_date(),$t,json_encode($items,JSON_UNESCAPED_UNICODE),json_encode($done,JSON_UNESCAPED_UNICODE),$u['id']);
        return $ok+['done'=>count($done),'total'=>count($items)];
    case 'crm-problem': allowed($u,'problem.report'); $cat=in_array($b['category']??'',['maintenance','food-safety','housekeeping','customer','other'],true)?$b['category']:'other'; $t=name_required($b['title']??'');
        $id=inbox_raise('problem-'.$cat,$cat==='food-safety'?'food-safety':'operations',$t,['detail'=>txt($b['detail']??'').(!empty($b['proof_file_id'])?' [photo #'.(int)$b['proof_file_id'].']':''),'priority'=>($b['urgent']??'')?'high':'normal','by'=>$u['id'],'date'=>business_date()]);
        check_file(isset($b['proof_file_id'])?(int)$b['proof_file_id']:null,$u); return $ok+['id'=>$id];
    // ---- Petpooja ----
    case 'crm-pos-import': allowed($u,'close'); require_once __DIR__.'/pos360.php'; return pos_import((string)($b['csv']??''),$u,'dashboard-upload',!empty($b['preview']),!empty($b['replace'])&&can($u,'manage'));
    // ---- Admin ----
    case 'crm-settings': allowed($u,'manage'); $allowed=['owner_emails','email_cc','email_subject','cash_out_direct_limit','proof_required_above','manager_approval_limit','taxi_incentive','driver_auto_approve_limit','business_day_cutoff_hour','checklist_housekeeping','checklist_opening','checklist_closing'];
        foreach($allowed as $k) if(array_key_exists($k,$b)){ $v=(string)$b[$k];
            if(in_array($k,['cash_out_direct_limit','proof_required_above','manager_approval_limit','taxi_incentive','driver_auto_approve_limit'],true)) $v=(string)money($v);
            if($k==='business_day_cutoff_hour'){ $h=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>8]]); if($h===false)fail('Cutoff hour must be 0–8.'); $v=(string)$h; }
            if(in_array($k,['owner_emails','email_cc'],true)) foreach(array_filter(array_map('trim',explode(',',$v))) as $e) if(!filter_var($e,FILTER_VALIDATE_EMAIL))fail('Invalid email: '.$e);
            if(str_starts_with($k,'checklist_')){ $lines=array_values(array_filter(array_map('trim',explode("\n",$v)))); $v=json_encode($lines,JSON_UNESCAPED_UNICODE); }
            set_setting($k,txt($v,4000)); }
        audit($u,'settings.policies',array_intersect_key($b,array_flip($allowed))); return $ok;
    case 'crm-user-update': allowed($u,'manage'); $t=row('SELECT * FROM dd_users WHERE id=? FOR UPDATE',(int)($b['id']??0)); if(!$t)fail('User not found.'); if(!isset(ROLES[$b['role']??'']))fail('Choose a role.');
        if((int)$t['id']===(int)$u['id']&&($b['role']!=='owner'||empty($b['active'])))fail('You cannot remove your own owner access.');
        $emp=!empty($b['employee_id'])?(int)$b['employee_id']:null; if($emp&&!row('SELECT id FROM dd_employees WHERE id=?',$emp))fail('Employee not found.');
        run('UPDATE dd_users SET role=?,active=?,employee_id=?,drawer=?,session_rev=session_rev+? WHERE id=?',$b['role'],!empty($b['active'])?1:0,$emp,txt($b['drawer']??'',40)?:null,($b['role']!==$t['role']||empty($b['active']))?1:0,$t['id']);
        audit($u,'user.update',['id'=>$t['id'],'role'=>$b['role'],'active'=>!empty($b['active'])]); return $ok;
    case 'crm-email-resend': allowed($u,'manage'); $sn=row('SELECT * FROM dd_day_snapshots WHERE id=?',(int)($b['snapshot_id']??0)); if(!$sn)fail('Report not found.');
        $n=(int)row('SELECT COUNT(*) n FROM dd_email_queue WHERE snapshot_id=?',$sn['id'])['n']; $id=queue_daybook_email((int)$sn['id'],$sn['date'],(int)$sn['version'],$u,'-resend'.$n); audit($u,'email.resend',['snapshot'=>$sn['id']]); return $ok+['queued'=>$id];
    case 'crm-email-run': allowed($u,'manage'); require_once __DIR__.'/report360.php'; return $ok+email_process(5);
    }
    fail('Unknown action',404);
}

function bill_register(int $sup,string $ref,string $d,int $amount,?int $proof,?int $po,array $u,bool $confirm,?string $due=null): int {
    month_lock_check($d);
    $s=row('SELECT * FROM dd_suppliers WHERE id=?',$sup); if(!$s)fail('Choose the supplier.'); if($ref===''||!$amount)fail('Enter the bill number and amount.');
    if(row('SELECT id FROM dd_bills WHERE supplier_id=? AND reference=?',$sup,$ref)) fail('This bill number is already recorded for '.$s['name'].'. Duplicate not added.',409);
    if(!$confirm&&row('SELECT id FROM dd_bills WHERE supplier_id=? AND amount=? AND date=?',$sup,$amount,$d)) send(['ok'=>false,'duplicateWarning'=>true,'error'=>'A bill from '.$s['name'].' with the same amount and date already exists. Is this a different bill?'],409);
    $due??=next_date($d,(int)$s['credit_days']);
    run("INSERT INTO dd_bills(supplier_id,reference,date,due_date,amount,paid,status,proof_file_id,po_id,created_by) VALUES(?,?,?,?,?,0,'unpaid',?,?,?)",$sup,$ref,$d,$due,$amount,$proof,$po,$u['id']);
    $id=(int)db()->lastInsertId(); audit($u,'bill.create',['id'=>$id,'amount'=>$amount]);
    if($po){ $exp=(int)row('SELECT COALESCE(SUM(ROUND(quantity*rate)),0) n FROM dd_po_lines WHERE po_id=?',$po)['n']; if($exp&&$exp!==$amount) inbox_raise('three-way-mismatch','purchase','Bill '.$ref.' ₹'.rupees($amount).' ≠ PO value ₹'.rupees($exp),['amount'=>abs($amount-$exp),'ref_table'=>'dd_bills','ref_id'=>$id,'dedupe'=>'3way-'.$id]); }
    if(!$proof) inbox_raise('missing-proof','vendor','Bill photo missing: '.$s['name'].' '.$ref,['ref_table'=>'dd_bills','ref_id'=>$id,'amount'=>$amount,'dedupe'=>'billproof-'.$id]);
    return $id;
}
function stock_count_decide(int $id,bool $approve,array $u,string $note): void {
    $c=row("SELECT * FROM dd_stock_counts WHERE id=? AND status='pending' FOR UPDATE",$id); if(!$c)fail('Pending stock count not found.');
    if((int)$c['counted_by']===(int)$u['id']&&!can($u,'*'))fail('You cannot approve your own stock count.',403);
    $mid=null; if($approve){ $cur=stock_at((int)$c['item_id'],(int)$c['location_id'],true); $diff=qsub($c['counted_qty'],$cur);
        if(bccomp_s($diff,'0')>0) $mid=stock_move(['item_id'=>$c['item_id'],'qty'=>$diff,'to'=>(int)$c['location_id'],'kind'=>'adjust','date'=>$c['date'],'reason'=>'Approved count #'.$id.': '.$note],$u);
        elseif(bccomp_s($diff,'0')<0) $mid=stock_move(['item_id'=>$c['item_id'],'qty'=>ltrim($diff,'-'),'from'=>(int)$c['location_id'],'kind'=>'adjust','date'=>$c['date'],'reason'=>'Approved count #'.$id.': '.$note],$u); }
    run('UPDATE dd_stock_counts SET status=?,approved_by=?,move_id=? WHERE id=?',$approve?'approved':'rejected',$u['id'],$mid,$id);
    inbox_close_for('dd_stock_counts',$id,$approve?'approved':'rejected',$u,$note); audit($u,'stock.count.'.($approve?'approve':'reject'),['id'=>$id,'move'=>$mid]);
}
