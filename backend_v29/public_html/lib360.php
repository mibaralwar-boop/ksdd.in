<?php
declare(strict_types=1);
// KSDD CRM 360 core services. All money is integer paise; all quantities are DECIMAL(14,3) handled as strings in SQL.
require_once __DIR__.'/migrations.php';

const CRM_SCHEMA = 6;
const DENOMS = [50000,20000,10000,5000,2000,1000,500,200,100]; // ₹500 … ₹1 in paise; coins can also be entered as a lump sum
const CASH_IN_CATEGORIES = [
  'owner-contribution'=>'Owner contribution / मालिक से',
  'home-cash'=>'Cash received from home / घर से नकद',
  'credit-collection'=>'Customer credit collection / उधार वसूली',
  'petty-return'=>'Petty cash returned / बचा पैसा वापस',
  'bank-withdrawal'=>'Bank to cash withdrawal / बैंक से निकाला',
  'transfer-in'=>'Cash transfer IN / कैश ट्रांसफर',
  'other-receipt'=>'Other genuine receipt / अन्य प्राप्ति',
];
const CASH_OUT_CATEGORIES = [
  'Vegetables'=>'सब्ज़ी','Milk & dairy'=>'दूध / डेयरी','Groceries'=>'किराना','LPG / gas'=>'गैस','Cleaning'=>'सफ़ाई','Maintenance'=>'मरम्मत',
  'Transport'=>'भाड़ा','Staff tips'=>'स्टाफ टिप','Bus driver tip'=>'बस ड्राइवर टिप','Taxi incentive'=>'टैक्सी इंसेंटिव','Overtime wages'=>'ओवरटाइम',
  'Utilities'=>'बिजली/पानी/रिचार्ज','Petty cash'=>'छोटा खर्च','Other'=>'अन्य',
];
const VOUCHER_KINDS = ['advance','supplier','expense','driver','salary','other'];

function crm_ready(): void { if(schema_version()<CRM_SCHEMA) fail('CRM 360 database update is pending. Owner: open migrate.php once.',503); }
function setting(string $k,string $default=''): string { $r=row('SELECT value FROM dd_settings WHERE `key`=?',$k); return $r?(string)$r['value']:$default; }
function setting_int(string $k,int $default=0): int { return (int)setting($k,(string)$default); }
function set_setting(string $k,string $v): void { run('INSERT INTO dd_settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',$k,$v); }
function now_sql(): string { return gmdate('Y-m-d H:i:s'); }
/** Business date: entries before the cutoff hour (default 04:00 IST) belong to the previous day. */
function business_date(?int $ts=null): string { $ts??=time(); $cut=setting_int('business_day_cutoff_hour',4); return date('Y-m-d',$ts-$cut*3600); }
/**
 * Fix C11: ONE salary proration used by the CRM payroll and the Salary Register.
 * earned = round_half_up(monthly × paid_days ÷ denominator_days), computed in integer paise on hundredths of a day —
 * never by multiplying a rounded per-day rate (₹12,000 × 20 ÷ 31 = ₹7,741.94, not ₹387.10 × 20 = ₹7,742.00).
 */
function prorate_monthly(int $monthlyPaise,int $hundredthDays,int $denominatorDays): int { if($denominatorDays<=0||$hundredthDays<=0) return 0; $den=$denominatorDays*100; return intdiv($monthlyPaise*$hundredthDays+intdiv($den,2),$den); }
function days_in_month(string $m): int { return (int)(new DateTimeImmutable($m.'-01'))->format('t'); }
function next_date(string $d,int $n=1): string { return (new DateTimeImmutable($d))->modify(($n>=0?'+':'').$n.' day')->format('Y-m-d'); }
function ist_time(?string $utc): string { return $utc?date('H:i',strtotime($utc.' UTC')):''; }
function rupees(int $p): string { return number_format($p/100,2,'.',''); }
function qty(mixed $v,bool $allowZero=true): string {
    $s=trim(is_scalar($v)?(string)$v:''); if(!preg_match('/^\d{1,9}(\.\d{1,3})?$/',$s)) fail('Enter a valid quantity (up to 3 decimals).');
    if(!$allowZero&&(float)$s<=0) fail('Quantity must be greater than zero.'); return $s;
}
function seq(string $prefix,string $date): string {
    $name=$prefix.'-'.str_replace('-','',$date);
    run('INSERT IGNORE INTO dd_sequences(name,value) VALUES(?,0)',$name);
    row('SELECT value FROM dd_sequences WHERE name=? FOR UPDATE',$name);
    run('UPDATE dd_sequences SET value=value+1 WHERE name=?',$name);
    return $name.'-'.str_pad((string)row('SELECT value FROM dd_sequences WHERE name=?',$name)['value'],4,'0',STR_PAD_LEFT);
}
function client_key(array $b): ?string { $k=txt($b['client_key']??'',64); if($k==='')return null; if(!preg_match('/^[A-Za-z0-9_-]{8,64}$/',$k))fail('Invalid submission key.'); return $k; }
function users_by_perm(string $p): array { return array_values(array_filter(rows('SELECT id,name,role FROM dd_users WHERE active=1'),fn($u)=>can($u,$p))); }

/* ------------------------------ Operations inbox ------------------------------ */
function inbox_raise(string $type,string $module,string $title,array $o=[]): ?int {
    $key=$o['dedupe']??null;
    if($key&&($r=row('SELECT id FROM dd_inbox WHERE dedupe_key=?',$key))) return (int)$r['id'];
    run('INSERT INTO dd_inbox(type,module,priority,title,detail,amount,ref_table,ref_id,business_date,raised_by,assigned_to,due_at,dedupe_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
        $type,$module,$o['priority']??'normal',txt($title,250),txt($o['detail']??'',4000),$o['amount']??null,$o['ref_table']??null,$o['ref_id']??null,$o['date']??null,$o['by']??null,$o['assign']??null,$o['due']??null,$key);
    $id=(int)db()->lastInsertId(); run('INSERT INTO dd_inbox_events(inbox_id,user_id,action,note) VALUES(?,?,?,?)',$id,$o['by']??null,'created',txt($title,250)); return $id;
}
function inbox_close_for(string $table,int $id,string $status,?array $u,string $note=''): void {
    foreach(rows("SELECT id FROM dd_inbox WHERE ref_table=? AND ref_id=? AND status IN ('open','clarify')",$table,$id) as $i){
        run('UPDATE dd_inbox SET status=?,resolved_by=?,resolved_at=? WHERE id=?',$status,$u['id']??null,now_sql(),$i['id']);
        run('INSERT INTO dd_inbox_events(inbox_id,user_id,action,note) VALUES(?,?,?,?)',$i['id'],$u['id']??null,$status,$note);
    }
}
function approval_limit_ok(array $u,int $amount): void {
    if(can($u,'*')) return;
    $lim=setting_int('manager_approval_limit',500000);
    if($amount>$lim) fail('Amount exceeds your approval limit of ₹'.rupees($lim).'. Owner approval needed.',403);
}

/* ------------------------------ Files (proofs) ------------------------------ */
function store_upload(array $b,array $u): int {
    $data=base64_decode((string)($b['data']??''),true); if($data===false||$data==='') fail('Upload is empty.');
    if(strlen($data)>1500000) fail('File is larger than 1.5 MB. Take the photo again.',413);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($data);
    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'][$mime]??null; if(!$ext) fail('Only JPG, PNG, WEBP photos or PDF bills are allowed.');
    if($ext!=='pdf'&&@getimagesizefromstring($data)===false) fail('The image could not be read.');
    // Strip EXIF/GPS metadata from JPEGs by re-encoding (also neutralises polyglot payloads).
    if($ext==='jpg'&&function_exists('imagecreatefromstring')){ $im=@imagecreatefromstring($data); if($im===false) fail('The image could not be read.'); ob_start(); imagejpeg($im,null,85); $data=(string)ob_get_clean(); imagedestroy($im); }
    $dir=storage_dir('uploads').'/'.date('Y/m'); if(!is_dir($dir)&&!mkdir($dir,0750,true)) fail('Upload folder is not writable.',503);
    $name=bin2hex(random_bytes(16)).'.'.$ext; if(file_put_contents($dir.'/'.$name,$data)===false) fail('Could not save upload.',503);
    run('INSERT INTO dd_files(path,mime,size,sha256,original_name,uploaded_by) VALUES(?,?,?,?,?,?)',date('Y/m').'/'.$name,$mime,strlen($data),hash('sha256',$data),txt($b['name']??'',160),$u['id']);
    $id=(int)db()->lastInsertId(); audit($u,'file.upload',['id'=>$id,'mime'=>$mime,'size'=>strlen($data)]); return $id;
}
function check_file(?int $id,array $u): ?int { if(!$id)return null; $f=row('SELECT * FROM dd_files WHERE id=?',$id); if(!$f||((int)$f['uploaded_by']!==(int)$u['id']&&!can($u,'read')))fail('Proof file not found.'); return $id; }

/* ------------------------------ Cash engine ------------------------------ */
function day_get(string $d): array { run('INSERT IGNORE INTO dd_days(date) VALUES(?)',$d); return row('SELECT * FROM dd_days WHERE date=?',$d); }
function day_lock_open(string $d): array { return day_post_guard($d); }
/** Expected physical cash = opening + cash IN − cash OUT (UPI/card/unpaid bills never touch physical cash). */
function day_cash(string $d): array {
    $day=day_get($d); $entries=rows('SELECT * FROM dd_ledger WHERE date=? AND voided=0',$d);
    $s=summary($entries,(int)$day['opening']);
    $s['salesByAccount']=[]; $s['receipts']=0; $s['cashSales']=0; $s['cashOutOnly']=0; $s['nonCashPayments']=0; $s['operatingExpenses']=0;
    foreach($entries as $e){ $a=(int)$e['amount'];
        if($e['type']==='sale'){ $s['salesByAccount'][$e['account']]=($s['salesByAccount'][$e['account']]??0)+$a; if($e['account']==='Cash')$s['cashSales']+=$a; }
        if($e['type']==='payment-in'&&$e['account']==='Cash') $s['receipts']+=$a;
        if(movement($e)['out']>0){ if($e['account']==='Cash')$s['cashOutOnly']+=$a; else $s['nonCashPayments']+=$a; }
        if(in_array($e['type'],['expense'],true)) $s['operatingExpenses']+=$a;
    }
    return ['day'=>$day,'summary'=>$s,'entries'=>$entries];
}
/* ---- Fix C14: drawer-level cash. One drawer = exactly the old whole-day behaviour; several drawers = independent expected cash per drawer. ---- */
const MAIN_DRAWER = 'Main counter';
function drawer_name(?string $d): string { $d=trim((string)$d); return $d===''?MAIN_DRAWER:mb_substr($d,0,40); }
function drawer_tables(): bool { static $ok; return $ok??=(bool)row("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dd_drawer_transfers'"); }
/** Drawers that hold cash on $d: any cash entry, count, opening or transfer names it. */
function day_drawers(string $d): array {
    $n=[]; foreach(rows("SELECT DISTINCT COALESCE(NULLIF(drawer,''),?) d FROM dd_ledger WHERE date=? AND voided=0 AND (account='Cash' OR to_account='Cash')",MAIN_DRAWER,$d) as $r) $n[$r['d']]=1;
    foreach(rows('SELECT DISTINCT drawer d FROM dd_cash_counts WHERE date=?',$d) as $r) $n[drawer_name($r['d'])]=1;
    if(drawer_tables()){ foreach(rows('SELECT drawer d FROM dd_drawer_openings WHERE date=?',$d) as $r) $n[$r['d']]=1;
        foreach(rows('SELECT from_drawer a,to_drawer b FROM dd_drawer_transfers WHERE date=? AND voided=0',$d) as $r){ $n[$r['a']]=1; $n[$r['b']]=1; } }
    $n[MAIN_DRAWER]??=1; $k=array_keys($n); sort($k); return $k;
}
/** Expected cash in one drawer. Non-main drawers start from their own recorded opening; Main counter holds the rest of the day opening. */
function drawer_cash(string $d,string $drawer): array {
    $drawer=drawer_name($drawer); $day=day_get($d); $all=day_drawers($d);
    if(count($all)===1){ $s=day_cash($d)['summary']; return ['drawer'=>$drawer,'opening'=>(int)$day['opening'],'opening_source'=>'day','in'=>$s['cashIn'],'out'=>$s['cashOut'],'transfer_in'=>0,'transfer_out'=>0,'expected'=>$s['expectedCash'],'drawers'=>$all]; }
    $op=[]; if(drawer_tables()) foreach(rows('SELECT drawer,opening FROM dd_drawer_openings WHERE date=?',$d) as $r) $op[$r['drawer']]=(int)$r['opening'];
    if(isset($op[$drawer])){ $open=$op[$drawer]; $src='drawer'; } elseif($drawer===MAIN_DRAWER){ $open=(int)$day['opening']-array_sum(array_diff_key($op,[MAIN_DRAWER=>1])); $src='day opening − other drawers'; } else { $open=0; $src='not set'; }
    $entries=rows("SELECT * FROM dd_ledger WHERE date=? AND voided=0 AND COALESCE(NULLIF(drawer,''),?)=?",$d,MAIN_DRAWER,$drawer); $s=summary($entries,$open);
    $ti=0;$to=0; if(drawer_tables()){ $ti=(int)row('SELECT COALESCE(SUM(amount),0) n FROM dd_drawer_transfers WHERE date=? AND voided=0 AND to_drawer=?',$d,$drawer)['n']; $to=(int)row('SELECT COALESCE(SUM(amount),0) n FROM dd_drawer_transfers WHERE date=? AND voided=0 AND from_drawer=?',$d,$drawer)['n']; }
    return ['drawer'=>$drawer,'opening'=>$open,'opening_source'=>$src,'in'=>$s['cashIn'],'out'=>$s['cashOut'],'transfer_in'=>$ti,'transfer_out'=>$to,'expected'=>$s['expectedCash']+$ti-$to,'drawers'=>$all];
}
function drawer_summary(string $d): array { $o=[]; foreach(day_drawers($d) as $dr){ $c=drawer_cash($d,$dr); $last=row("SELECT total FROM dd_cash_counts WHERE date=? AND kind='closing' AND COALESCE(NULLIF(drawer,''),?)=? ORDER BY id DESC LIMIT 1",$d,MAIN_DRAWER,$dr); $c['counted']=$last?(int)$last['total']:null; $c['variance']=$last?(int)$last['total']-$c['expected']:null; $o[]=$c; } return $o; }
function parse_denoms(mixed $in): array {
    if(!is_array($in)) fail('Enter the cash count.'); $out=[]; $total=0;
    foreach(DENOMS as $d){ $n=filter_var($in[(string)$d]??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000]]); if($n===false)fail('Note counts must be whole numbers.'); if($n){$out[(string)$d]=$n;$total+=$n*$d;} }
    $coins=money($in['coins']??0); if($coins){$out['coins']=$coins;$total+=$coins;}
    return [$out,$total];
}
function save_count(string $d,string $kind,mixed $denoms,array $u): array {
    [$den,$total]=parse_denoms($denoms);
    run('INSERT INTO dd_cash_counts(date,kind,drawer,denominations,total,counted_by) VALUES(?,?,?,?,?,?)',$d,$kind,drawer_name($u['drawer']??null),json_encode($den),$total,$u['id']);
    $id=(int)db()->lastInsertId(); audit($u,'cash.count',['date'=>$d,'kind'=>$kind,'total'=>$total]); return ['id'=>$id,'total'=>$total];
}
/** Post a cash entry from the CRM forms. Idempotent on client_key (interrupted resubmits return the original). */
function post_cash(array $b,array $u,string $type): array {
    $ck=client_key($b); if($ck&&($x=row('SELECT id,receipt_no FROM dd_ledger WHERE source_key=?','crm-'.$ck))) return ['ok'=>true,'duplicate'=>true,'id'=>(int)$x['id'],'receipt_no'=>$x['receipt_no']];
    $d=valid_date($b['date']??business_date()); day_lock_open($d); $amount=money($b['amount']??null); if(!$amount)fail('Amount must be greater than zero.');
    $proof=check_file(isset($b['proof_file_id'])?(int)$b['proof_file_id']:null,$u);
    if($type==='payment-in'){
        $cat=(string)($b['category']??''); if(!isset(CASH_IN_CATEGORIES[$cat])) fail('Choose where the cash came from.');
        $name=txt($b['from']??'',160)?:CASH_IN_CATEGORIES[$cat]; $label=explode(' / ',CASH_IN_CATEGORIES[$cat])[0]; $prefix='RCPT';
    } else {
        $cat=(string)($b['category']??''); if(!isset(CASH_OUT_CATEGORIES[$cat])) fail('Choose a payment category.');
        $limit=setting_int('cash_out_direct_limit',100000);
        if($amount>$limit&&!can($u,'close')) fail('Cash OUT above ₹'.rupees($limit).' needs an approved voucher. Ask the manager to approve a payment request.',403);
        $name=txt($b['paid_to']??'',160); if($name==='')fail('Enter who was paid.'); $label=$cat; $prefix='PAY';
        if(empty($b['confirm_duplicate'])&&row("SELECT id FROM dd_ledger WHERE date=? AND amount=? AND category=? AND created_by=? AND voided=0 AND created_at>=? LIMIT 1",$d,$amount,$cat,$u['id'],gmdate('Y-m-d H:i:s',time()-900)))
            send(['ok'=>false,'duplicateWarning'=>true,'error'=>'You entered the same amount and category in the last 15 minutes. Is this a new payment?'],409);
    }
    $type2=$type==='payment-in'?'payment-in':'expense';
    if($type==='cash-out'&&in_array($cat,['Staff tips','Bus driver tip','Taxi incentive'],true)) $type2='payment-out';
    $id=add_entry(['date'=>$d,'name'=>$name,'type'=>$type2,'account'=>'Cash','amount'=>rupees($amount),'category'=>$label,'reference'=>txt($b['reference']??'',120),'description'=>txt($b['note']??''),'source'=>'crm-'.$u['role'],'source_key'=>$ck?'crm-'.$ck:null],$u);
    $rno=seq($prefix,$d);
    run('UPDATE dd_ledger SET receipt_no=?,drawer=?,payee=?,proof_file_id=? WHERE id=?',$rno,$u['drawer']??'Main counter',$type==='cash-out'?$name:null,$proof,$id);
    if($type==='cash-out'&&!$proof&&$amount>setting_int('proof_required_above',50000))
        inbox_raise('missing-proof','cash','Bill photo missing: '.$name.' ₹'.rupees($amount),['amount'=>$amount,'ref_table'=>'dd_ledger','ref_id'=>$id,'date'=>$d,'by'=>$u['id'],'dedupe'=>'proof-'.$id]);
    return ['ok'=>true,'id'=>$id,'receipt_no'=>$rno];
}
function day_submit(string $d,array $u,string $note): array {
    lock_day($d); $day=row('SELECT * FROM dd_days WHERE date=? FOR UPDATE',$d);
    if($day['workflow']!=='draft') fail('This day is already '.$day['workflow'].'.');
    if(!$day['opening_set']) fail('Opening cash is not set. Ask the manager to confirm opening cash.');
    // Fix C14: the day's counted cash is the latest CLOSING count of EVERY drawer, never just the last count of any drawer.
    $counted=0; $missing=[]; foreach(day_drawers($d) as $dr){ $x=row("SELECT total FROM dd_cash_counts WHERE date=? AND kind='closing' AND COALESCE(NULLIF(drawer,''),?)=? ORDER BY id DESC LIMIT 1",$d,MAIN_DRAWER,$dr); if(!$x){ if(count(day_drawers($d))>1&&drawer_cash($d,$dr)['expected']===0&&!row("SELECT 1 FROM dd_cash_counts WHERE date=? AND COALESCE(NULLIF(drawer,''),?)=?",$d,MAIN_DRAWER,$dr)) continue; $missing[]=$dr; } else $counted+=(int)$x['total']; }
    if($missing) fail(count(day_drawers($d))>1?'Closing count missing for: '.implode(', ',$missing).'. Each drawer is counted separately.':'Count the cash first (Count Cash).');
    $count=['total'=>$counted];
    $c=day_cash($d); if($c['summary']['unallocated']) fail('A mixed-payment entry must be allocated by the manager before closing.');
    $exp=$c['summary']['expectedCash']; $var=(int)$count['total']-$exp;
    if($var!==0&&trim($note)==='') fail('Cash does not match (difference ₹'.rupees($var).'). Write the reason.');
    run("UPDATE dd_days SET workflow='submitted',counted=?,expected=?,variance=?,variance_status=?,note=?,submitted_by=?,submitted_at=? WHERE date=?",(int)$count['total'],$exp,$var,$var?'open':'none',txt($note),$u['id'],now_sql(),$d);
    if($var) inbox_raise('cash-variance','cash',($var<0?'Cash shortage':'Cash excess').' ₹'.rupees(abs($var)).' on '.$d,['amount'=>abs($var),'priority'=>'high','ref_table'=>'dd_days','date'=>$d,'detail'=>'Cashier note: '.$note,'by'=>$u['id'],'dedupe'=>'variance-'.$d.'-'.$var.'-'.(int)(row('SELECT COUNT(*) n FROM dd_day_snapshots WHERE date=?',$d)['n'])]);
    inbox_raise('day-close','cash','Daybook '.$d.' submitted for closing',['ref_table'=>'dd_days','date'=>$d,'by'=>$u['id'],'priority'=>$var?'high':'normal']);
    audit($u,'day.submit',['date'=>$d,'counted'=>(int)$count['total'],'expected'=>$exp,'variance'=>$var]);
    return ['ok'=>true,'expected'=>$exp,'counted'=>(int)$count['total'],'variance'=>$var];
}
/** Close a business day: freeze a versioned snapshot, queue owner email once per version, carry forward opening cash. */
function close_day_core(string $d,array $u,int $counted,string $note): array {
    $day=row('SELECT * FROM dd_days WHERE date=? FOR UPDATE',$d); $c=day_cash($d); $exp=$c['summary']['expectedCash']; $var=$counted-$exp;
    if($var!==0&&$day['submitted_by']&&(int)$day['submitted_by']===(int)$u['id']) fail('You submitted this count. Another manager or the owner must approve the cash difference.',403);
    run("UPDATE dd_days SET counted=?,expected=?,variance=?,variance_status=?,status='closed',workflow='closed',note=?,closed_by=?,closed_at=?,reviewed_by=COALESCE(reviewed_by,?),reviewed_at=COALESCE(reviewed_at,?) WHERE date=?",
        $counted,$exp,$var,$var?'approved':'none',$note,$u['id'],now_sql(),$u['id'],now_sql(),$d);
    foreach(rows("SELECT id FROM dd_inbox WHERE ref_table='dd_days' AND business_date=? AND status IN ('open','clarify')",$d) as $i){ run("UPDATE dd_inbox SET status='resolved',resolved_by=?,resolved_at=? WHERE id=?",$u['id'],now_sql(),$i['id']); run('INSERT INTO dd_inbox_events(inbox_id,user_id,action,note) VALUES(?,?,?,?)',$i['id'],$u['id'],'resolved','Day closed'); }
    $snap=make_snapshot($d,$u);
    queue_daybook_email($snap['id'],$d,$snap['version'],$u);
    // Carry forward verified counted cash into the next day's opening, once, unless already set manually.
    $next=next_date($d); run('INSERT IGNORE INTO dd_days(date) VALUES(?)',$next); $n=row('SELECT * FROM dd_days WHERE date=? FOR UPDATE',$next);
    if($n['status']!=='closed'&&(!$n['opening_set']||$n['opening_source']==='carry')) run("UPDATE dd_days SET opening=?,opening_set=1,opening_source='carry' WHERE date=?",$counted,$next);
    elseif($n['opening_set']&&(int)$n['opening']!==$counted) inbox_raise('opening-mismatch','cash','Opening cash for '.$next.' differs from verified closing of '.$d,['ref_table'=>'dd_days','date'=>$next,'amount'=>abs((int)$n['opening']-$counted),'dedupe'=>'open-mismatch-'.$next.'-'.$counted]);
    audit($u,'day.close',['date'=>$d,'counted'=>$counted,'expected'=>$exp,'variance'=>$var,'note'=>$note,'snapshot'=>$snap['version']]);
    return ['ok'=>true,'snapshot'=>$snap];
}
function make_snapshot(string $d,array $u): array {
    $c=day_cash($d); $day=row('SELECT * FROM dd_days WHERE date=?',$d);
    $names=[]; foreach(rows('SELECT id,name FROM dd_users') as $x)$names[(int)$x['id']]=$x['name'];
    $entries=array_map(fn($e)=>['id'=>(int)$e['id'],'time'=>ist_time($e['created_at']),'type'=>$e['type'],'category'=>$e['category'],'name'=>$e['name'],'payee'=>$e['payee'],'account'=>$e['account'],'to'=>$e['to_account'],'amount'=>(int)$e['amount'],'receipt'=>$e['receipt_no'],'by'=>$names[(int)$e['created_by']]??'System','proof'=>$e['proof_file_id']?'Yes':'—','source'=>$e['source']],$c['entries']);
    $ver=(int)(row('SELECT MAX(version) v FROM dd_day_snapshots WHERE date=?',$d)['v']??0)+1;
    $reviewedOther=$day['submitted_by']&&(int)$day['submitted_by']!==(int)$day['closed_by'];
    $label=($ver>1?'CORRECTED v'.$ver.' · ':'').($reviewedOther?'CLOSED & VERIFIED':'CLOSED');
    $payload=['restaurant'=>setting('restaurant','Khadak Singh Da Dhaba'),'location'=>'Delhi–Mumbai Expressway, Pinan, Alwar, Rajasthan','date'=>$d,'version'=>$ver,'label'=>$label,
      'opening'=>(int)$day['opening'],'openingSource'=>$day['opening_source'],'summary'=>$c['summary'],'expected'=>(int)$day['expected'],'counted'=>(int)$day['counted'],'variance'=>(int)$day['variance'],'note'=>$day['note'],
      'pos'=>row('SELECT * FROM dd_pos_days WHERE business_date=?',$d),'entries'=>$entries,'drawers'=>drawer_summary($d),
      'submittedBy'=>$names[(int)$day['submitted_by']]??null,'closedBy'=>$names[(int)$day['closed_by']]??null,'closedAt'=>$day['closed_at'],
      'handovers'=>rows('SELECT h.*,a.name outgoing,b.name incoming FROM dd_handovers h JOIN dd_users a ON a.id=h.outgoing_user JOIN dd_users b ON b.id=h.incoming_user WHERE h.date=?',$d)];
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    run('INSERT INTO dd_day_snapshots(date,version,label,payload,sha256,created_by) VALUES(?,?,?,?,?,?)',$d,$ver,$label,$json,hash('sha256',$json),$u['id']??null);
    return ['id'=>(int)db()->lastInsertId(),'version'=>$ver,'label'=>$label];
}
function queue_daybook_email(int $snapId,string $d,int $ver,?array $u,string $suffix=''): ?int {
    $to=setting('owner_emails',''); $key='daybook-'.$d.'-v'.$ver.$suffix;
    if(row('SELECT id FROM dd_email_queue WHERE idem_key=?',$key)) return null; // exactly once per approved version
    $snap=row('SELECT label FROM dd_day_snapshots WHERE id=?',$snapId);
    $subject=strtr(setting('email_subject','KSDD Daybook {date} · {status}'),['{date}'=>$d,'{status}'=>$snap['label']??'CLOSED']);
    run('INSERT INTO dd_email_queue(idem_key,kind,snapshot_id,recipients,cc,subject,status,created_by) VALUES(?,?,?,?,?,?,?,?)',$key,'daybook',$snapId,$to,setting('email_cc',''),$subject,$to===''?'needs-config':'queued',$u['id']??null);
    return (int)db()->lastInsertId();
}

/* ------------------------------ Vouchers (authorized payments) ------------------------------ */
function voucher_create(array $b,array $u,array $extra=[]): array {
    $ck=client_key($b); if($ck&&($x=row('SELECT id,voucher_no FROM dd_vouchers WHERE client_key=?',$ck))) return ['ok'=>true,'duplicate'=>true,'id'=>(int)$x['id'],'voucher_no'=>$x['voucher_no']];
    $kind=(string)($extra['kind']??$b['kind']??''); if(!in_array($kind,VOUCHER_KINDS,true)) fail('Choose a payment type.');
    $amount=money($b['amount']??null); if(!$amount) fail('Amount must be greater than zero.');
    $d=valid_date($b['date']??business_date()); $payee=(string)($extra['payee']??$b['payee']??'');
    $emp=null;$month=null;
    if(in_array($kind,['advance','salary'],true)){ $emp=(int)($b['employee_id']??0); $e=row('SELECT * FROM dd_employees WHERE id=? AND active=1',$emp); if(!$e)fail('Choose an employee.'); $payee=$e['name']; $month=valid_month($b['payroll_month']??substr($d,0,7));
        if($kind==='advance'&&row('SELECT id FROM dd_payroll WHERE employee_id=? AND month=? AND approved=1',$emp,$month)) fail('Payroll for '.$month.' is already approved. Use next month.');
        if($kind==='salary'){ $p=row('SELECT * FROM dd_payroll WHERE employee_id=? AND month=? AND approved=1 FOR UPDATE',$emp,$month); if(!$p) fail('Salary approval pending for '.$month.'. Approve payroll first.');
            $paid=(int)row("SELECT COALESCE(SUM(amount),0) n FROM dd_ledger WHERE employee_id=? AND payroll_month=? AND type='salary' AND voided=0",$emp,$month)['n'];
            $pend=(int)row("SELECT COALESCE(SUM(amount),0) n FROM dd_vouchers WHERE kind='salary' AND employee_id=? AND payroll_month=? AND status IN ('requested','approved')",$emp,$month)['n'];
            if($amount>(int)$p['net']-$paid-$pend) fail('Only ₹'.rupees(max(0,(int)$p['net']-$paid-$pend)).' salary remains for '.$month.' (approved net ₹'.rupees((int)$p['net']).', paid ₹'.rupees($paid).', already requested ₹'.rupees($pend).').'); } }
    $bill=$extra['bill_id']??(isset($b['bill_id'])?(int)$b['bill_id']:null);
    $sid=null;$alloc=[];$adv=0;
    if($kind==='supplier'){ require_once __DIR__.'/supplier360.php';
        // Accepts: legacy single bill_id, explicit allocations [{bill_id,amount}], 'auto' (oldest due first), or is_advance=1.
        if($bill){ $bl=row('SELECT supplier_id FROM dd_bills WHERE id=?',(int)$bill); if(!$bl)fail('Choose the supplier bill.'); $sid=(int)$bl['supplier_id']; $req=[['bill_id'=>$bill,'amount'=>rupees($amount)]]; }
        else { $sid=(int)($b['supplier_id']??0); $req=$b['allocations']??'auto'; }
        $sup=row('SELECT * FROM dd_suppliers WHERE id=?',$sid); if(!$sup)fail('Choose the supplier.'); $payee=$sup['name']; $adv=!empty($b['is_advance'])?1:0;
        if($adv&&!can($u,'vendor'))fail('Only accounts/admin can request a supplier advance.',403);
        $alloc=supplier_allocations($sid,$amount,$adv?null:$req,(bool)$adv); if(count($alloc)===1)$bill=(int)array_key_first($alloc); else $bill=null; }
    $payee=name_required($payee); $no=seq('VCH',$d);
    run('INSERT INTO dd_vouchers(voucher_no,kind,date,payee,amount,category,description,employee_id,payroll_month,bill_id,driver_visit_id,proof_file_id,status,requested_by,client_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        $no,$kind,$d,$payee,$amount,txt($b['category']??$extra['category']??ucfirst($kind),80),txt($b['note']??''),$emp,$month,$bill,$extra['visit_id']??null,check_file(isset($b['proof_file_id'])?(int)$b['proof_file_id']:null,$u),'requested',$u['id'],$ck);
    $id=(int)db()->lastInsertId(); if($sid){ run('UPDATE dd_vouchers SET supplier_id=?,is_advance=? WHERE id=?',$sid,$adv,$id); foreach($alloc as $bid=>$a) run('INSERT INTO dd_voucher_allocations(voucher_id,bill_id,amount) VALUES(?,?,?)',$id,$bid,$a); }
    audit($u,'voucher.request',['id'=>$id,'kind'=>$kind,'amount'=>$amount,'allocations'=>$alloc,'advance'=>$adv]);
    if(!empty($extra['auto_approve'])) { run("UPDATE dd_vouchers SET status='approved',approved_at=? WHERE id=?",now_sql(),$id); audit(null,'voucher.auto-approve',['id'=>$id,'policy'=>$extra['auto_approve']]); }
    else inbox_raise('voucher','payments',ucfirst($kind).' request '.$no.': '.$payee.' ₹'.rupees($amount),['amount'=>$amount,'ref_table'=>'dd_vouchers','ref_id'=>$id,'date'=>$d,'by'=>$u['id'],'priority'=>$kind==='advance'?'high':'normal']);
    return ['ok'=>true,'id'=>$id,'voucher_no'=>$no];
}
function voucher_decide(int $id,bool $approve,array $u,string $note=''): void {
    $v=row('SELECT * FROM dd_vouchers WHERE id=? FOR UPDATE',$id); if(!$v||$v['status']!=='requested') fail('Pending payment request not found.');
    if((int)$v['requested_by']===(int)$u['id']&&!can($u,'*')) fail('You cannot approve your own request.',403);
    if($approve){ approval_limit_ok($u,(int)$v['amount']); if($v['kind']==='supplier'&&!can($u,'vendor'))fail('Vendor permission required.',403); if(in_array($v['kind'],['advance','salary'],true))allowed($u,'payroll'); }
    run('UPDATE dd_vouchers SET status=?,approved_by=?,approved_at=? WHERE id=?',$approve?'approved':'rejected',$u['id'],now_sql(),$id);
    if(!$approve&&!empty($v['obligation_id'])) run("UPDATE dd_recurring_obligations SET status='billed',voucher_id=NULL WHERE id=? AND voucher_id=?",$v['obligation_id'],$id);
    inbox_close_for('dd_vouchers',$id,$approve?'approved':'rejected',$u,$note); audit($u,'voucher.'.($approve?'approve':'reject'),['id'=>$id,'note'=>$note]);
}
/** Cashier disburses an approved voucher. Exactly one ledger row per voucher (UNIQUE ledger.voucher_id + vouchers.ledger_id). */
function voucher_pay(int $id,array $u,string $account='Cash'): array {
    $v=row('SELECT * FROM dd_vouchers WHERE id=? FOR UPDATE',$id); if(!$v) fail('Voucher not found.');
    if($v['status']==='paid') return ['ok'=>true,'duplicate'=>true,'ledger_id'=>(int)$v['ledger_id']];
    if($v['status']!=='approved') fail('This payment is not approved yet.');
    if(!in_array($account,['Cash','Paytm','Bank of India','Other bank'],true)) fail('Choose how it was paid.');
    $d=business_date(); day_lock_open($d);
    $type=match($v['kind']){'advance'=>'advance','salary'=>'salary','supplier'=>'payment-out','driver'=>'payment-out',default=>'expense'};
    $cat=match($v['kind']){'advance'=>'Staff advance','salary'=>'Salary','supplier'=>'Supplier payment','driver'=>$v['category'],default=>$v['category']};
    $lid=add_entry(['date'=>$d,'name'=>$v['payee'],'type'=>$type,'account'=>$account,'amount'=>rupees((int)$v['amount']),'category'=>$cat,'reference'=>$v['voucher_no'],'description'=>$v['description'],'employee_id'=>$v['employee_id'],'payroll_month'=>$v['payroll_month'],'source'=>'voucher','source_key'=>'voucher-'.$v['id']],$u,true);
    run('UPDATE dd_ledger SET voucher_id=?,payee=?,receipt_no=?,drawer=?,proof_file_id=? WHERE id=?',$v['id'],$v['payee'],seq('PAY',$d),$u['drawer']??'Main counter',$v['proof_file_id'],$lid);
    run("UPDATE dd_vouchers SET status='paid',paid_by=?,paid_at=?,ledger_id=? WHERE id=?",$u['id'],now_sql(),$lid,$id);
    if($v['kind']==='supplier'){ $al=rows('SELECT * FROM dd_voucher_allocations WHERE voucher_id=? ORDER BY bill_id',$v['id']);
        if(!$al&&$v['bill_id']&&!$v['is_advance']) $al=[['bill_id'=>$v['bill_id'],'amount'=>$v['amount']]]; // vouchers created before allocations existed
        foreach($al as $a) bill_allocate((int)$a['bill_id'],$lid,(int)$a['amount']);
        if($v['is_advance']) run('INSERT INTO dd_supplier_credits(supplier_id,bill_id,kind,amount,reference,reason,ledger_id,source_key,created_by) VALUES(?,NULL,?,?,?,?,?,?,?)',$v['supplier_id'],'advance',$v['amount'],$v['voucher_no'],'Advance paid to supplier',$lid,'adv-'.$v['id'],$u['id']); }
    if(!empty($v['obligation_id'])) run("UPDATE dd_recurring_obligations SET status='paid',paid_ledger_id=? WHERE id=? AND voucher_id=?",$lid,$v['obligation_id'],$v['id']);
    audit($u,'voucher.pay',['id'=>$id,'ledger_id'=>$lid,'account'=>$account]); return ['ok'=>true,'ledger_id'=>$lid];
}
function bill_allocate(int $billId,int $ledgerId,int $amount): void {
    $b=row('SELECT * FROM dd_bills WHERE id=? FOR UPDATE',$billId); $out=(int)$b['amount']-(int)$b['paid']-(int)($b['credited']??0);
    if($amount>$out) fail('Payment exceeds the outstanding amount of ₹'.rupees($out).'.');
    run('INSERT INTO dd_bill_payments(bill_id,ledger_id,amount) VALUES(?,?,?)',$billId,$ledgerId,$amount);
    $paid=(int)$b['paid']+$amount; run('UPDATE dd_bills SET paid=?,status=?,ledger_id=COALESCE(ledger_id,?) WHERE id=?',$paid,$paid+(int)($b['credited']??0)>=(int)$b['amount']?'paid':'partial',$ledgerId,$billId);
}

/* ------------------------------ Stock ledger by location ------------------------------ */
function loc_id(string $name): int { $r=row('SELECT id FROM dd_locations WHERE name=?',$name); if(!$r)fail('Location not found.'); return (int)$r['id']; }
function stock_at(int $item,int $loc,bool $lock=false): string { run('INSERT IGNORE INTO dd_stock_balances(item_id,location_id,quantity) VALUES(?,?,0)',$item,$loc); return (string)row('SELECT quantity FROM dd_stock_balances WHERE item_id=? AND location_id=?'.($lock?' FOR UPDATE':''),$item,$loc)['quantity']; }
/** One movement row; transfers move between locations without changing total stock or creating purchases. Negative stock is refused. */
function stock_move(array $m,array $u): int {
    $item=row('SELECT * FROM dd_inventory WHERE id=? FOR UPDATE',(int)$m['item_id']); if(!$item) fail('Item not found.');
    $q=$m['qty']; $from=$m['from']??null; $to=$m['to']??null; $kind=$m['kind'];
    if($from){ $have=stock_at((int)$item['id'],$from,true); if(bccomp_s($have,$q)<0) fail($item['name'].': only '.rtrim(rtrim($have,'0'),'.').' '.$item['unit'].' available at source. Stock cannot go below zero.'); run('UPDATE dd_stock_balances SET quantity=quantity-? WHERE item_id=? AND location_id=?',$q,$item['id'],$from); }
    if($to){ stock_at((int)$item['id'],$to,true); run('UPDATE dd_stock_balances SET quantity=quantity+? WHERE item_id=? AND location_id=?',$q,$item['id'],$to); }
    $delta=($to&&!$from)?$q:((!$to&&$from)?'-'.$q:'0');
    if($delta!=='0') run('UPDATE dd_inventory SET quantity=quantity+? WHERE id=?',$delta,$item['id']);
    month_lock_check((string)$m['date']);
    // V6: every non-transfer movement carries its value at the current weighted-average cost (receipts overwrite with the exact bill-line value).
    $cv=array_key_exists('cost_value',$m)?$m['cost_value']:($kind==='transfer'?null:intdiv(abs(qm($q))*(int)$item['cost']+500,1000));
    run('INSERT INTO dd_stock_moves(item_id,date,quantity,reason,kind,from_location,to_location,supplier_id,rate,reference,request_item_id,po_line_id,proof_file_id,created_by,created_at,cost_value) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        $item['id'],$m['date'],$delta==='0'?$q:$delta,txt($m['reason']??$kind),$kind,$from,$to,$m['supplier_id']??null,$m['rate']??null,txt($m['reference']??'',120),$m['request_item_id']??null,$m['po_line_id']??null,$m['proof']??null,$u['id'],now_sql(),$cv);
    return (int)db()->lastInsertId();
}
/** Exact decimal string (e.g. "-15.500") → integer milli-units. Never uses floats. */
function qm(mixed $v): int { $s=trim(is_scalar($v)?(string)$v:''); if($s==='')return 0; $neg=$s[0]==='-'; $s=ltrim($s,'-+'); if(!preg_match('/^\d*(\.\d*)?$/',$s))fail('Invalid quantity.'); [$w,$f]=array_pad(explode('.',$s,2),2,''); $n=(int)$w*1000+(int)substr(str_pad($f,3,'0'),0,3); return $neg?-$n:$n; }
function m3(int $m): string { $neg=$m<0; $m=abs($m); return ($neg?'-':'').intdiv($m,1000).'.'.str_pad((string)($m%1000),3,'0',STR_PAD_LEFT); }
/** Weighted-average cost after a receipt. $inMilli was already added to dd_inventory.quantity by stock_move(). */
function wavg_cost_in(int $item,int $inMilli,int $value,int $moveId): void {
    $it=row('SELECT quantity,cost FROM dd_inventory WHERE id=? FOR UPDATE',$item); $old=max(0,qm($it['quantity'])-$inMilli);
    $den=$old+$inMilli; $cost=$den>0?intdiv($old*(int)$it['cost']+$value*1000+intdiv($den,2),$den):(int)$it['cost'];
    run("UPDATE dd_inventory SET cost=?,cost_source='receipt' WHERE id=?",$cost,$item);
    run('UPDATE dd_stock_moves SET cost_value=? WHERE id=?',$value,$moveId);
}
function bccomp_s(string $a,string $b): int { $x=(int)round((float)$a*1000); $y=(int)round((float)$b*1000); return $x<=>$y; }
function qsub(string $a,string $b): string { return number_format(((int)round((float)$a*1000)-(int)round((float)$b*1000))/1000,3,'.',''); }
function qmin(string $a,string $b): string { return bccomp_s($a,$b)<=0?$a:$b; }
function qpos(string $a): string { return bccomp_s($a,'0')<0?'0.000':number_format((float)$a,3,'.',''); }
