<?php
declare(strict_types=1);
// Hostinger cron entry (hPanel → Advanced → Cron Jobs), every 10 minutes:
//   /usr/bin/php /home/<user>/public_html/ops/cron.php
// CLI only. Each job writes a dd_jobs row; reminder jobs use an idempotency key per business date so repeats never duplicate.
if(PHP_SAPI!=='cli'){ http_response_code(404); exit; }
require __DIR__.'/lib.php'; require __DIR__.'/lib360.php'; require __DIR__.'/report360.php'; require __DIR__.'/supplier360.php'; require __DIR__.'/finance360.php';
$only=$argv[1]??null; $ran=[];
function job(string $name,?string $idem,callable $fn): void { global $ran;
    if($idem&&row('SELECT id FROM dd_jobs WHERE idem_key=?',$idem)) return;
    try{ run('INSERT INTO dd_jobs(job,idem_key,business_date,trigger_by,status,started_at) VALUES(?,?,?,?,?,?)',$name,$idem,business_date(),'cron','running',now_sql()); }catch(PDOException $e){ return; } // another run claimed it
    $id=(int)db()->lastInsertId();
    try{ $r=transaction($fn); run("UPDATE dd_jobs SET status='ok',processed=?,skipped=?,finished_at=? WHERE id=?",(int)($r['processed']??0),(int)($r['skipped']??0),now_sql(),$id); $ran[]=$name.':ok'; }
    catch(Throwable $e){ run("UPDATE dd_jobs SET status='failed',errors=?,finished_at=? WHERE id=?",txt($e->getMessage(),800),now_sql(),$id);
        inbox_raise('job-failed','automation','Automation job failed: '.$name,['priority'=>'high','detail'=>txt($e->getMessage(),800),'dedupe'=>'job-'.$name.'-'.business_date()]); $ran[]=$name.':failed'; }
}
try{
    crm_ready(); $d=business_date(); $hour=(int)date('G'); $min=(int)date('i');
    // Email queue: not idempotency-keyed (rows are claimed atomically inside email_process).
    if(!$only||$only==='email'){ $r=email_process(5); if($r['sent']||$r['failed']) run('INSERT INTO dd_jobs(job,trigger_by,status,processed,skipped,started_at,finished_at) VALUES(?,?,?,?,?,?,?)','email-send','cron',$r['failed']?'warning':'ok',$r['sent'],$r['failed'],now_sql(),now_sql()); $ran[]='email:'.json_encode($r); }
    // 05:15 — Petpooja check: alert if yesterday's sales were not received from the Windows PC sync.
    if(!$only&&$hour>=5&&$hour<12||$only==='pos-check') job('pos-missing-check','pos-check-'.$d,function()use($d){ $y=next_date($d,-1); $p=row('SELECT status FROM dd_pos_days WHERE business_date=?',$y);
        if(!$p) inbox_raise('pos-missing','sales','Petpooja sales for '.$y.' have not been imported',['priority'=>'high','date'=>$y,'dedupe'=>'pos-missing-'.$y]); return ['processed'=>$p?0:1]; });
    // 18:00 — chef reminder if tomorrow's requirement is missing.
    if(!$only&&$hour>=18||$only==='kitchen') job('kitchen-reminder','kitchen-'.$d,function()use($d){ $t=next_date($d); $n=0;
        foreach(rows("SELECT id,name FROM dd_locations WHERE kind='kitchen' AND active=1") as $l) if(!row('SELECT id FROM dd_kitchen_requests WHERE target_date=? AND location_id=?',$t,$l['id'])&&$l['name']==='Main Kitchen'){ inbox_raise('kitchen-missing','kitchen','Kitchen requirement for '.$t.' not submitted ('.$l['name'].')',['date'=>$t,'dedupe'=>'kmiss-'.$t.'-'.$l['id']]); $n++; }
        return ['processed'=>$n]; });
    // 03:30 (before cutoff) — closing check for the business day about to end.
    if(!$only&&$hour===3||$only==='closing') job('closing-check','closing-'.$d,function()use($d){ $day=row('SELECT workflow FROM dd_days WHERE date=?',$d);
        if(!$day||$day['workflow']==='draft') inbox_raise('day-not-submitted','cash','Daybook '.$d.' not yet submitted by cashier',['priority'=>'high','date'=>$d,'dedupe'=>'nosubmit-'.$d]);
        $p=(int)row("SELECT COUNT(*) n FROM dd_vouchers WHERE status='requested'")['n']; return ['processed'=>$p]; });
    // Low stock digest once per day.
    if(!$only&&$hour>=9||$only==='stock') job('low-stock','lowstock-'.$d,function()use($d){ $low=rows('SELECT name,quantity,unit FROM dd_inventory WHERE active=1 AND reorder>0 AND quantity<=reorder');
        if($low) inbox_raise('low-stock','store',count($low).' item(s) at or below reorder level',['date'=>$d,'detail'=>implode(', ',array_map(fn($x)=>$x['name'].' '.(float)$x['quantity'].' '.$x['unit'],$low)),'dedupe'=>'low-'.$d]); return ['processed'=>count($low)]; });
    // Daily supplier payment reminders → owner inbox only (never pays, never messages suppliers).
    if(!$only&&$hour>=8||$only==='supplier-dues') job('supplier-dues','sdues-'.$d,function()use($d){ $n=0; $t1=next_date($d);
        foreach(supplier_balances(null,false) as $s){ if($s['bill_outstanding']<=0) continue; $t=terms_of((int)$s['id']); $lead=(int)($t['reminder_days']??1);
            if($s['overdue']>0){ inbox_raise('supplier-overdue','vendor',$s['name'].': overdue ₹'.rupees($s['overdue']),['amount'=>$s['overdue'],'priority'=>'high','date'=>$d,'ref_table'=>'dd_suppliers','ref_id'=>(int)$s['id'],'dedupe'=>'sdue-'.$d.'-'.$s['id'].'-overdue']); $n++; }
            if($s['next_due']&&$s['next_due']>=$d&&$s['next_due']<=next_date($d,$lead)){ inbox_raise('supplier-due','vendor',$s['name'].': ₹'.rupees($s['next_due_amount']).' due '.($s['next_due']===$d?'today':($s['next_due']===$t1?'tomorrow':$s['next_due'])),['amount'=>$s['next_due_amount'],'date'=>$d,'ref_table'=>'dd_suppliers','ref_id'=>(int)$s['id'],'dedupe'=>'sdue-'.$d.'-'.$s['id'].'-due']); $n++; }
            if(!$t) { inbox_raise('supplier-terms-missing','vendor',$s['name'].': payment schedule not configured',['ref_table'=>'dd_suppliers','ref_id'=>(int)$s['id'],'dedupe'=>'sterms-'.$s['id']]); } }
        return ['processed'=>$n]; });
    // V6: recurring bills (draft expected obligations only — never a payable or payment), reminders, missing kitchen usage, approved-but-unpaid.
    $fj=fin_cron($d);
    if(!$only||$only==='recurring'){ job('recurring-generate','recgen-'.$d,$fj['recurring-generate']); if($hour>=8||$only) job('recurring-reminders','recrem-'.$d,$fj['recurring-reminders']); }
    if(!$only&&$hour>=10||$only==='usage') job('usage-missing','usemiss-'.$d,$fj['usage-missing']);
    if(!$only&&$hour>=11||$only==='vouchers') job('approved-unpaid','vunpaid-'.$d,$fj['approved-unpaid']);
    // ERP V1: keep the shadow general ledger current (idempotent; posts only new source events). Never closes periods.
    if(!$only||$only==='gl'){ require_once __DIR__.'/gl360.php'; job('gl-sync','gl-'.$d.'-'.$hour,fn()=>['processed'=>array_sum(gl_sync(null,2000))]); }
}catch(Throwable $e){ fwrite(STDERR,'KSDD cron error: '.$e->getMessage().PHP_EOL); exit(1); }
echo date('c').' '.implode(' ',$ran).PHP_EOL;
