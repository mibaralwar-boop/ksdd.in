<?php
declare(strict_types=1);
// Daybook closing report renderers (HTML / PDF / PNG) and the SMTP email queue. Pure PHP: no Composer, works on Hostinger shared hosting.

function snap_load(string $d,?int $ver=null): array {
    $r=$ver?row('SELECT * FROM dd_day_snapshots WHERE date=? AND version=?',$d,$ver):row('SELECT * FROM dd_day_snapshots WHERE date=? ORDER BY version DESC LIMIT 1',$d);
    if(!$r) fail('No closing report exists for this date yet. Close the day first.',404);
    $p=json_decode($r['payload'],true,64,JSON_THROW_ON_ERROR); $p['sha256']=$r['sha256']; $p['snapshot_id']=(int)$r['id']; return $p;
}
function rs(int $p): string { return ($p<0?'-':'').'Rs '.number_format(abs($p)/100,2); }
function rep_groups(): array { return ['Kitchen purchases'=>['Vegetables','Milk & dairy','Groceries','LPG / gas'],'Staff payments'=>['Staff advance','Salary','Overtime wages','Salary - previous month'],'Staff tips'=>['Staff tips','Staff Tips'],'Bus / taxi tips'=>['Bus driver tip','Taxi incentive','Bus tip / monthly','Taxi gift and card'],'Supplier settlements'=>['Supplier payment'],'Maintenance'=>['Maintenance','Cleaning']]; }
/** Totals derived only from the frozen snapshot entries, so image, PDF and email always agree with the ledger at close time. */
function rep_totals(array $s): array {
    $t=['cashOut'=>0,'bankOut'=>0,'opex'=>0,'otherCashIn'=>0,'cashSales'=>0,'upi'=>0,'card'=>0,'other'=>0];
    foreach($s['entries'] as $e){ $out=!in_array($e['type'],['sale','payment-in','deposit','withdrawal','settlement'],true);
        if($e['type']==='sale'){ if($e['account']==='Cash')$t['cashSales']+=$e['amount']; elseif($e['account']==='Paytm')$t['upi']+=$e['amount']; elseif($e['account']==='Card')$t['card']+=$e['amount']; else $t['other']+=$e['amount']; }
        if($e['type']==='payment-in'&&$e['account']==='Cash')$t['otherCashIn']+=$e['amount'];
        if($e['type']==='withdrawal'&&$e['to']==='Cash')$t['otherCashIn']+=$e['amount'];
        if($out){ if($e['account']==='Cash')$t['cashOut']+=$e['amount']; else $t['bankOut']+=$e['amount']; }
        if($e['type']==='deposit'&&$e['account']==='Cash')$t['cashOut']+=$e['amount'];
        if($e['type']==='expense')$t['opex']+=$e['amount'];
    }
    $t['totalSales']=$t['cashSales']+$t['upi']+$t['card']+$t['other']; $t['available']=$s['opening']+$t['cashSales']+$t['otherCashIn']; return $t;
}
function rep_lines(array $s): array { $t=rep_totals($s); return [
    ['Opening balance',$s['opening']],['Cash sales',$t['cashSales']],['UPI sales',$t['upi']],['Card sales',$t['card']],['Other sales',$t['other']],['Total sales',$t['totalSales']],
    ['Other cash IN',$t['otherCashIn']],['Total physical cash available',$t['available']],['Total Cash OUT',$t['cashOut']],['Total Bank/UPI payments',$t['bankOut']],['Operating expenses',$t['opex']],
    ['Expected closing cash',$s['expected']],['Actual counted cash',$s['counted']],[($s['variance']<0?'Shortage':'Excess / (shortage)'),$s['variance']],['Final handover balance',$s['counted']]]; }
function h360(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

function report_html(array $s,bool $standalone=true): string {
    $rows=''; foreach($s['entries'] as $i=>$e) $rows.='<tr><td>'.($i+1).'</td><td>'.h360($e['time']).'</td><td>'.h360($e['type']).' · '.h360($e['category']).'</td><td>'.h360($e['name']).'</td><td>'.h360($e['payee']??'').'</td><td>'.h360($e['account'].($e['to']?' → '.$e['to']:'')).'</td><td class="r">'.h360(rs($e['amount'])).'</td><td>'.h360($e['by']).'</td><td>'.h360($e['proof']).'</td></tr>';
    $sum=''; foreach(rep_lines($s) as [$k,$v]) $sum.='<tr><td>'.h360($k).'</td><td class="r"><b>'.h360(rs($v)).'</b></td></tr>';
    $css='body{font:13px/1.45 system-ui,Segoe UI,Arial,sans-serif;color:#1d2a22;margin:24px}h1{margin:0;font-size:22px}.hd{display:flex;justify-content:space-between;border-bottom:3px solid #143c32;padding-bottom:10px;margin-bottom:14px}.st{background:#143c32;color:#fff;padding:4px 10px;border-radius:6px;font-weight:700}.grid{display:grid;grid-template-columns:300px 1fr;gap:18px}table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #e2e6dd;padding:5px 6px;text-align:left;vertical-align:top}th{background:#f2f4ef;font-size:11px;text-transform:uppercase}.r{text-align:right}.ft{margin-top:16px;font-size:11px;color:#55624f}@media print{body{margin:8mm}.grid{grid-template-columns:260px 1fr}}@media(max-width:800px){.grid{grid-template-columns:1fr}}';
    $body='<div class="hd"><div><h1>'.h360($s['restaurant']).'</h1><div>'.h360($s['location']).'</div><div><b>Daily Daybook</b> · Business date '.h360($s['date']).'</div></div><div style="text-align:right"><span class="st">'.h360($s['label']).'</span><div>Report v'.(int)$s['version'].'</div><div>Closed '.h360(date('d M Y H:i',strtotime($s['closedAt'].' UTC'))).' IST</div></div></div>'
      .'<div class="grid"><div><h3>Financial summary</h3><table>'.$sum.'</table></div><div><h3>Daily transactions ('.count($s['entries']).')</h3><table><thead><tr><th>#</th><th>Time</th><th>Category</th><th>Particulars</th><th>Paid to</th><th>Method</th><th class="r">Amount</th><th>By</th><th>Proof</th></tr></thead><tbody>'.$rows.'</tbody></table></div></div>'
      .'<div class="ft">Submitted by: '.h360($s['submittedBy']??'—').' · Closed/approved by: '.h360($s['closedBy']??'—').' · Difference reason: '.h360($s['note']?:'—').' · Audit ref: '.h360(substr($s['sha256']??'',0,16)).'</div>';
    return $standalone?'<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Daybook '.h360($s['date']).'</title><style>'.$css.'</style></head><body>'.$body.'</body></html>':$body;
}

/* ---------- Minimal PDF writer (A4 landscape, Helvetica, paginated; never omits entries) ---------- */
function pdf_txt(string $s): string { $s=trim(preg_replace('/\s*\/?\s*[\x{0900}-\x{097F}]+[\x{0900}-\x{097F}\s\/]*/u',' ',$s)); $s=strtr($s,['₹'=>'Rs ','→'=>'->','·'=>'-','–'=>'-','—'=>'-']); $s=@iconv('UTF-8','windows-1252//TRANSLIT//IGNORE',$s)?:preg_replace('/[^\x20-\x7E]/','?',$s); return strtr($s,['\\'=>'\\\\','('=>'\\(',')'=>'\\)']); }
function report_pdf(array $s): string {
    $W=842;$H=595;$pages=[];$ops='';$y=0;
    $txt=function(float $x,float $yy,string $t,int $size=9,bool $bold=false)use(&$ops){ $ops.='BT /'.($bold?'F2':'F1').' '.$size.' Tf '.round($x,1).' '.round($yy,1).' Td ('.pdf_txt($t).') Tj ET'."\n"; };
    $line=function(float $x1,float $y1,float $x2)use(&$ops){ $ops.='0.85 G '.$x1.' '.$y1.' m '.$x2.' '.$y1.' l S 0 G'."\n"; };
    $newPage=function()use(&$ops,&$pages,&$y,$H,$s,$txt,$W){ if($ops!=='')$pages[]=$ops; $ops=''; $y=$H-36;
        $txt(30,$y,$s['restaurant'].' - Daily Daybook',14,true); $txt($W-260,$y,$s['label'].'  v'.$s['version'],10,true); $y-=14; $txt(30,$y,$s['location'].'   Business date: '.$s['date'],9); $y-=18; };
    $newPage();
    $sy=$y; $txt(30,$y,'FINANCIAL SUMMARY',10,true); $y-=14;
    foreach(rep_lines($s) as [$k,$v]){ $txt(30,$y,$k,9); $txt(190,$y,rs($v),9,true); $line(30,$y-3,270); $y-=14; }
    $y-=6; $txt(30,$y,'Difference reason: '.mb_substr((string)($s['note']?:'-'),0,45),8); $y-=12; $txt(30,$y,'Submitted: '.($s['submittedBy']??'-').'  Closed: '.($s['closedBy']??'-'),8); $y-=12; $txt(30,$y,'Audit ref: '.substr($s['sha256']??'',0,16),8);
    $cols=[[290,'#'],[310,'Time'],[345,'Category'],[465,'Particulars / paid to'],[655,'Method'],[715,'Amount'],[780,'By']];
    $y=$sy; $header=function()use(&$y,$cols,$txt,$line){ foreach($cols as [$x,$t])$txt($x,$y,$t,8,true); $line(290,$y-3,812); $y-=13; };
    $txt(290,$y,'DAILY TRANSACTIONS ('.count($s['entries']).')',10,true); $y-=14; $header();
    foreach($s['entries'] as $i=>$e){
        if($y<40){ $newPage(); $txt(290,$y,'DAILY TRANSACTIONS (continued)',10,true); $y-=14; $header(); }
        $part=$e['name'].($e['payee']&&$e['payee']!==$e['name']?' / '.$e['payee']:'');
        foreach([[290,(string)($i+1)],[310,$e['time']],[345,mb_substr($e['type'].' '.$e['category'],0,24)],[465,mb_substr($part,0,38)],[655,mb_substr($e['account'],0,12)],[715,rs($e['amount'])],[780,mb_substr($e['by'],0,10)]] as [$x,$t]) $txt($x,$y,(string)$t,8);
        $line(290,$y-3,812); $y-=12;
    }
    $pages[]=$ops;
    $objs=[]; $objs[1]='<< /Type /Catalog /Pages 2 0 R >>'; $objs[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'; $objs[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $kids=[]; $n=5; $total=count($pages);
    foreach($pages as $pi=>$c){ $c.='BT /F1 7 Tf 30 18 Td (Page '.($pi+1).' of '.$total.' - generated from closing snapshot v'.$s['version'].') Tj ET'; $objs[$n]='<< /Length '.strlen($c).' >>'."\nstream\n".$c."\nendstream"; $objs[$n+1]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$W.' '.$H.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$n.' 0 R >>'; $kids[]=($n+1).' 0 R'; $n+=2; }
    $objs[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.$total.' >>'; ksort($objs);
    $out="%PDF-1.4\n"; $xref=[]; foreach($objs as $i=>$o){ $xref[$i]=strlen($out); $out.=$i." 0 obj\n".$o."\nendobj\n"; }
    $x=strlen($out); $out.="xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n"; foreach($objs as $i=>$o)$out.=sprintf("%010d 00000 n \n",$xref[$i]);
    return $out.'trailer << /Size '.(count($objs)+1).' /Root 1 0 R >>'."\nstartxref\n".$x."\n%%EOF";
}

/* ---------- Closing image (PNG via GD). Summary + first transactions; the PDF carries every entry. ---------- */
function report_png(array $s): string {
    if(!function_exists('imagecreatetruecolor')) fail('GD image extension is not enabled on this server; download the PDF instead.',503);
    $font=(is_file(private_root().'/fonts/DejaVuSans.ttf')?private_root():__DIR__.'/private').'/fonts/DejaVuSans.ttf'; $bold=(is_file(private_root().'/fonts/DejaVuSans-Bold.ttf')?private_root():__DIR__.'/private').'/fonts/DejaVuSans-Bold.ttf'; $ttf=is_file($font)&&function_exists('imagettftext');
    $maxRows=34; $W=1600; $H=1000; $im=imagecreatetruecolor($W,$H);
    $bg=imagecolorallocate($im,250,250,246); $ink=imagecolorallocate($im,29,42,34); $green=imagecolorallocate($im,20,60,50); $muted=imagecolorallocate($im,110,120,105); $rule=imagecolorallocate($im,222,227,216); $white=imagecolorallocate($im,255,255,255); $red=imagecolorallocate($im,170,40,30);
    imagefilledrectangle($im,0,0,$W,$H,$bg); imagefilledrectangle($im,0,0,$W,92,$green);
    $t=function(int $x,int $y,string $str,int $size=15,$c=null,bool $b=false)use($im,$ttf,$font,$bold,$ink){ $c??=$ink; if($ttf)imagettftext($im,$size,0,$x,$y,$c,$b&&is_file($bold)?$bold:$font,$str); else imagestring($im,$size>16?5:3,$x,$y-12,preg_replace('/[^\x20-\x7E]/','',strtr($str,['₹'=>'Rs '])),$c); };
    $m=fn(int $p)=>($p<0?'−':'').($ttf?'₹':'Rs ').number_format(abs($p)/100,2);
    $tt=$t; $t=function(int $x,int $y,string $str,int $size=15,$c=null,bool $b=false)use($tt){ $str=trim(preg_replace('/\s*\/?\s*[\x{0900}-\x{097F}]+[\x{0900}-\x{097F}\s\/]*/u',' ',$str)); $tt($x,$y,$str,$size,$c,$b); }; // bundled font has no Devanagari; English label kept
    $t(36,44,$s['restaurant'],26,$white,true); $t(36,76,$s['location'].'  ·  Daily Daybook  ·  '.$s['date'],14,$white);
    $t($W-470,44,$s['label'],18,$white,true); $t($W-470,76,'Report v'.$s['version'].'  ·  closed '.date('d M Y H:i',strtotime($s['closedAt'].' UTC')).' IST',12,$white);
    $y=135; $t(36,$y,'FINANCIAL SUMMARY',15,$green,true); $y+=30;
    foreach(rep_lines($s) as [$k,$v]){ $hi=in_array($k,['Expected closing cash','Actual counted cash','Total sales'],true); $t(36,$y,$k,14,$k==='Shortage'?$red:$ink,$hi); $t(360,$y,$m($v),14,$v<0?$red:$ink,true); imageline($im,36,$y+9,540,$y+9,$rule); $y+=34; }
    $x0=580; $y=135; $n=count($s['entries']); $t($x0,$y,'DAILY TRANSACTIONS ('.$n.')',15,$green,true); $y+=30;
    foreach([[0,'#'],[40,'Time'],[110,'Category'],[330,'Particulars'],[620,'Method'],[760,'Amount'],[900,'By']] as [$dx,$h]) $t($x0+$dx,$y,$h,12,$muted,true); $y+=24;
    foreach(array_slice($s['entries'],0,$maxRows) as $i=>$e){ foreach([[0,(string)($i+1)],[40,$e['time']],[110,mb_substr($e['category']?:$e['type'],0,22)],[330,mb_substr($e['name'],0,30)],[620,mb_substr($e['account'],0,13)],[760,$m($e['amount'])],[900,mb_substr($e['by'],0,10)]] as [$dx,$v]) $t($x0+$dx,$y,(string)$v,12); imageline($im,$x0,$y+7,$W-36,$y+7,$rule); $y+=22; }
    if($n>$maxRows) $t($x0,$y+8,'+ '.($n-$maxRows).' more entries — see the attached PDF for every transaction.',13,$red,true);
    $t(36,$H-28,'Submitted: '.($s['submittedBy']??'—').'   Closed/approved: '.($s['closedBy']??'—').'   Reason: '.mb_substr((string)($s['note']?:'—'),0,70).'   Audit: '.substr($s['sha256']??'',0,16),12,$muted);
    ob_start(); imagepng($im,null,6); imagedestroy($im); return (string)ob_get_clean();
}

/* ---------- SMTP client (SSL 465 or STARTTLS 587) ---------- */
function smtp_send(array $cfg,array $to,array $cc,string $subject,string $html,array $attachments): string {
    $host=(string)($cfg['host']??''); if($host==='') throw new RuntimeException('SMTP is not configured in config.php.');
    $port=(int)($cfg['port']??465); $secure=$cfg['secure']??($port===465?'ssl':'tls');
    $fp=@stream_socket_client(($secure==='ssl'?'ssl://':'tcp://').$host.':'.$port,$errno,$err,20); if(!$fp) throw new RuntimeException('SMTP connect failed: '.$err);
    stream_set_timeout($fp,30);
    $read=function()use($fp){ $r=''; while(($l=fgets($fp,515))!==false){ $r.=$l; if(isset($l[3])&&$l[3]===' ')break; } return $r; };
    $cmd=function(string $c,array $ok)use($fp,$read){ if($c!=='')fwrite($fp,$c."\r\n"); $r=$read(); if(!in_array((int)substr($r,0,3),$ok,true)) throw new RuntimeException('SMTP error: '.trim(substr($r,0,200))); return $r; };
    $cmd('',[220]); $ehlo='EHLO '.(gethostname()?:'localhost'); $cmd($ehlo,[250]);
    if($secure==='tls'){ $cmd('STARTTLS',[220]); if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) throw new RuntimeException('STARTTLS failed'); $cmd($ehlo,[250]); }
    $cmd('AUTH LOGIN',[334]); $cmd(base64_encode((string)$cfg['user']),[334]); $cmd(base64_encode((string)$cfg['password']),[235]);
    $from=(string)($cfg['from']??$cfg['user']); $cmd('MAIL FROM:<'.$from.'>',[250]);
    foreach(array_merge($to,$cc) as $r) $cmd('RCPT TO:<'.$r.'>',[250,251]);
    $cmd('DATA',[354]); $b='=_ksdd_'.bin2hex(random_bytes(8));
    $msg='From: '.mb_encode_mimeheader((string)($cfg['from_name']??'KSDD CRM')).' <'.$from.">\r\nTo: ".implode(', ',$to)."\r\n".($cc?'Cc: '.implode(', ',$cc)."\r\n":'').'Subject: '.mb_encode_mimeheader($subject)."\r\nDate: ".date('r')."\r\nMessage-ID: <".bin2hex(random_bytes(10)).'@'.substr(strrchr($from,'@')?:'@ksdd.in',1).">\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n";
    $msg.="--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html))."\r\n";
    foreach($attachments as [$name,$mime,$data]) $msg.="--$b\r\nContent-Type: $mime; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($data))."\r\n";
    $msg.="--$b--\r\n"; $msg=preg_replace('/^\./m','..',$msg);
    $r=$cmd($msg."\r\n.",[250]); try{$cmd('QUIT',[221]);}catch(Throwable $e){} fclose($fp); return trim(substr($r,0,240));
}
/** Process queued emails. Each row is claimed atomically so overlapping cron runs never send twice. */
function email_process(int $limit=5,?callable $sender=null): array {
    $sender??=fn(...$a)=>smtp_send(cfg()['smtp']??[],...$a); $done=['sent'=>0,'failed'=>0];
    foreach(rows("SELECT id FROM dd_email_queue WHERE status IN ('queued','failed') AND next_attempt_at<=? ORDER BY id LIMIT ".(int)$limit,now_sql()) as $q){
        $claimed=run("UPDATE dd_email_queue SET status='sending',attempts=attempts+1 WHERE id=? AND status IN ('queued','failed')",$q['id'])->rowCount(); if(!$claimed) continue;
        $e=row('SELECT * FROM dd_email_queue WHERE id=?',$q['id']);
        try{
            $snapRow=row('SELECT * FROM dd_day_snapshots WHERE id=?',$e['snapshot_id']); $s=snap_load($snapRow['date'],(int)$snapRow['version']);
            $t=rep_totals($s); $intro='<p>Daybook for <b>'.h360($s['date']).'</b> is <b>'.h360($s['label']).'</b>. Total sales '.h360(rs($t['totalSales'])).', expected cash '.h360(rs($s['expected'])).', counted '.h360(rs($s['counted'])).', difference '.h360(rs($s['variance'])).'.</p>';
            $att=[['daybook-'.$s['date'].'-v'.$s['version'].'.pdf','application/pdf',report_pdf($s)]];
            try{ $att[]=['daybook-'.$s['date'].'-v'.$s['version'].'.png','image/png',report_png($s)]; }catch(Throwable $ignored){}
            $split=fn($x)=>array_values(array_filter(array_map('trim',explode(',',(string)$x)),fn($a)=>filter_var($a,FILTER_VALIDATE_EMAIL)));
            $to=$split($e['recipients']); if(!$to) throw new RuntimeException('No valid owner email configured.');
            $reply=$sender($to,$split($e['cc']),$e['subject'],$intro.report_html($s,false),$att);
            run("UPDATE dd_email_queue SET status='accepted',sent_at=?,smtp_reply=?,last_error=NULL WHERE id=?",now_sql(),txt($reply,250),$e['id']); $done['sent']++;
        }catch(Throwable $ex){
            $att=(int)$e['attempts']; $dead=$att>=5; $msg=txt($ex->getMessage(),500);
            run('UPDATE dd_email_queue SET status=?,last_error=?,next_attempt_at=? WHERE id=?',$dead?'dead':'failed',$msg,gmdate('Y-m-d H:i:s',time()+min(3600,60*2**$att)),$e['id']); $done['failed']++;
            inbox_raise('email-failed','reports','Daybook email failed ('.$e['idem_key'].')',['detail'=>$msg,'priority'=>$dead?'high':'normal','ref_table'=>'dd_email_queue','ref_id'=>(int)$e['id'],'dedupe'=>'email-fail-'.$e['id'].($dead?'-dead':'')]);
        }
    }
    return $done;
}
