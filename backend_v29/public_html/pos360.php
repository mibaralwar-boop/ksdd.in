<?php
declare(strict_types=1);
// Petpooja daily sales summary import. Petpooja stays the source of truth for POS sales.
// Accepts a day-wise "Payment wise / Sales summary" CSV exported from the Petpooja dashboard (column names are matched by alias).
// One business date = one dd_pos_days row; ledger sales are posted once per mode with source_key pp-<date>-<mode>-r<revision>.
require_once __DIR__.'/imports.php';

const POS_ALIASES = [
  'date'=>['date','business date','bill date','invoice date','day'],
  'cash'=>['cash','cash sales','cash amount'],
  'upi'=>['upi','paytm','online','upi sales','wallet','phonepe','gpay'],
  'card'=>['card','card sales','credit card','debit card'],
  'other'=>['other','other payments','due payment','part payment'],
  'gross'=>['gross','gross sales','gross amount','my amount','sub total'],
  'net'=>['net','net sales','total','grand total','total sales'],
  'discount'=>['discount','discounts'],
  'refund'=>['refund','refunds'],
  'voids'=>['cancelled','void','voids','cancelled amount'],
  'tax'=>['tax','taxes','total tax','gst'],
  'bills'=>['bills','no of bills','orders','invoice count'],
];
const POS_ACCOUNTS = ['cash'=>'Cash','upi'=>'Paytm','card'=>'Card','other'=>'Other bank'];

function pos_parse(string $csv): array {
    $data=parse_csv($csv); if(count($data)<2||count($data)>400) fail('CSV needs a header row and 1–400 day rows.');
    $head=array_map(fn($h)=>strtolower(trim(preg_replace('/\s+/',' ',$h))),array_shift($data)); $map=[];
    foreach(POS_ALIASES as $k=>$names) foreach($head as $i=>$h) if(in_array($h,$names,true)&&!isset($map[$k])) $map[$k]=$i;
    foreach(['date','cash'] as $k) if(!isset($map[$k])) fail('Petpooja CSV is missing a "'.$k.'" column. Found: '.implode(', ',$head));
    $out=[];
    foreach($data as $n=>$r){
        $raw=trim($r[$map['date']]??''); $d=false; foreach(['Y-m-d','d-m-Y','d/m/Y'] as $fmt){ $candidate=DateTimeImmutable::createFromFormat('!'.$fmt,$raw); if($candidate&&$candidate->format($fmt)===$raw){ $d=$candidate; break; } }
        if(!$d) fail('Row '.($n+2).': date "'.$raw.'" not understood (use YYYY-MM-DD or DD-MM-YYYY).');
        $row=['date'=>$d->format('Y-m-d')];
        foreach(['cash','upi','card','other','gross','net','discount','refund','voids','tax'] as $k){ $v=isset($map[$k])?str_replace([',','₹',' '],'',(string)($r[$map[$k]]??'0')):'0'; if($v==='')$v='0'; try{$row[$k]=money($v);}catch(Throwable $e){fail('Row '.($n+2).' '.$k.': '.$e->getMessage());} }
        $row['bills']=isset($map['bills'])?(int)($r[$map['bills']]??0):null;
        if(isset($out[$row['date']])) fail('Date '.$row['date'].' appears twice in this file.');
        $out[$row['date']]=$row;
    }
    return $out;
}
/** Reconcile collected modes against declared net (when present) and against Paytm settlement data. */
function pos_check(array $r): array {
    $modes=$r['cash']+$r['upi']+$r['card']+$r['other']; $issues=[];
    if($r['net']&&$modes!==$r['net']) $issues[]='Payment modes total ₹'.rupees($modes).' but net sales ₹'.rupees($r['net']).'.';
    $paytm=(int)(row("SELECT COALESCE(SUM(amount),0) n FROM dd_paytm WHERE date=? AND kind='payment' AND status='success'",$r['date'])['n']??0);
    if($paytm&&$paytm!==$r['upi']) $issues[]='UPI sales ₹'.rupees($r['upi']).' differ from Paytm successful payments ₹'.rupees($paytm).'.';
    return $issues;
}
function pos_import(string $csv,?array $u,string $source,bool $preview,bool $replace=false): array {
    $days=pos_parse($csv); $hash=hash('sha256',$csv); $res=['ok'=>true,'imported'=>0,'skipped'=>0,'conflicts'=>0,'rows'=>[]];
    ksort($days);
    foreach($days as $d=>$r){
        $existing=row('SELECT * FROM dd_pos_days WHERE business_date=?'.($preview?'':' FOR UPDATE'),$d);
        $same=false; // Compare the normalized full sales document below, including tax.
        // Audit fix: "Other" that merely repeats a tender (the counter report repeats UPI in Other) is reference data, never extra sales.
        $note=[]; if($r['other']>0){ $without=$r['cash']+$r['upi']+$r['card']; if(($r['net']&&$without===$r['net'])||(!$r['net']&&$r['other']===$r['upi'])){ $note[]='Other ₹'.rupees($r['other']).' repeats another tender — kept as reference only, not posted as sales.'; $r['other']=0; } }
        $same=$existing&&array_reduce(['cash','upi','card','other','gross','net','discount','refund','voids','tax','bills'],fn($c,$k)=>$c&&(($existing[$k]===null&&$r[$k]===null)||($existing[$k]!==null&&$r[$k]!==null&&(int)$existing[$k]===$r[$k])),true);
        $issues=array_merge($note,pos_check($r)); $state=$existing?($same?'duplicate':'changed'):'new';
        $wf=row('SELECT workflow FROM dd_days WHERE date=?',$d)['workflow']??'draft';
        if(in_array($wf,['submitted','reviewed'],true)&&$state!=='duplicate') $state='day-submitted';
        try{ if($state!=='duplicate'&&row("SELECT id FROM dd_counter_closes WHERE business_date=? AND status='posted' LIMIT 1",$d)) $state='counter-close'; }catch(PDOException $e){ if(($e->errorInfo[1]??0)!==1146) throw $e; }
        $legacy=(int)row("SELECT COUNT(*) n FROM dd_ledger WHERE date=? AND voided=0 AND type='sale' AND source='petpooja'",$d)['n'];
        if($legacy&&$state==='new') $state='legacy-bills'; // bill-wise Petpooja rows already imported: never double count
        $res['rows'][]=['date'=>$d,'state'=>$state,'cash'=>$r['cash'],'upi'=>$r['upi'],'card'=>$r['card'],'other'=>$r['other'],'issues'=>$issues];
        if($preview) continue;
        if($state==='duplicate'){ $res['skipped']++; continue; }
        if($state==='day-submitted'||$state==='counter-close'){ $res['conflicts']++; inbox_raise('pos-conflict','sales','Petpooja summary for '.$d.' not posted: '.($state==='counter-close'?'a manual counter close already recorded this day\'s sales':'the day is submitted for closing — return it to draft first'),['date'=>$d,'priority'=>'high','dedupe'=>'pos-'.$state.'-'.$d.'-'.$hash]); continue; }
        if($state==='legacy-bills'){ $res['conflicts']++; inbox_raise('pos-conflict','sales','Petpooja summary for '.$d.' not posted: bill-wise Petpooja sales already exist',['date'=>$d,'priority'=>'high','dedupe'=>'pos-legacy-'.$d.'-'.$hash]); continue; }
        $day=row('SELECT status FROM dd_days WHERE date=?',$d);
        if($state==='changed'&&(!$replace||($day['status']??'')==='closed')){
            $res['conflicts']++; run("UPDATE dd_pos_days SET status='conflict',pending_payload=? WHERE business_date=?",json_encode($r),$d);
            inbox_raise('pos-conflict','sales','Petpooja re-import for '.$d.' has different amounts. Review before replacing.',['date'=>$d,'priority'=>'high','detail'=>'Existing cash ₹'.rupees((int)$existing['cash']).', new ₹'.rupees($r['cash']).'. '.(($day['status']??'')==='closed'?'Day is closed: reopen with a reason first.':''),'dedupe'=>'pos-change-'.$d.'-'.$hash]);
            continue;
        }
        lock_day($d); $rev=$existing?(int)$existing['revision']+1:1;
        if($existing){ // correction: void previous revision's sales (history kept) then post new revision
            foreach(rows("SELECT id FROM dd_ledger WHERE date=? AND source='petpooja-day' AND voided=0",$d) as $old){ run('UPDATE dd_ledger SET voided=1 WHERE id=?',$old['id']); audit($u,'ledger.void',['id'=>$old['id'],'reason'=>'Petpooja summary replaced by revision '.$rev]); }
        }
        $actor=$u??['id'=>null,'role'=>'owner'];
        foreach(POS_ACCOUNTS as $mode=>$acct) if($r[$mode]>0)
            add_entry(['date'=>$d,'name'=>'Petpooja '.strtoupper($mode).' sales','type'=>'sale','account'=>$acct,'amount'=>rupees($r[$mode]),'category'=>'POS sales','reference'=>'PP-'.$d,'description'=>'Imported from Petpooja ('.$source.')','source'=>'petpooja-day','source_key'=>'pp-'.$d.'-'.$mode.'-r'.$rev],$actor);
        $status=$issues?'unreconciled':'reconciled';
        run('REPLACE INTO dd_pos_days(business_date,revision,cash,upi,card,other,gross,net,discount,refund,voids,tax,bills,source,file_sha256,status,reconcile_note,pending_payload,imported_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?)',
            $d,$rev,$r['cash'],$r['upi'],$r['card'],$r['other'],$r['gross'],$r['net'],$r['discount'],$r['refund'],$r['voids'],$r['tax'],$r['bills'],$source,$hash,$status,implode(' ',$issues),$u['id']??null);
        if($issues) inbox_raise('pos-unreconciled','sales','Petpooja '.$d.' imported but not reconciled',['date'=>$d,'detail'=>implode(' ',$issues),'dedupe'=>'pos-rec-'.$d.'-r'.$rev]);
        $res['imported']++;
    }
    if(!$preview) audit($u,'pos.import',['source'=>$source,'imported'=>$res['imported'],'skipped'=>$res['skipped'],'conflicts'=>$res['conflicts']]);
    return $res;
}
