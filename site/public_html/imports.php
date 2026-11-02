<?php
declare(strict_types=1);
function parse_csv(string $s): array {
    $s=preg_replace('/^\xEF\xBB\xBF/','',$s);$rows=[];$line=[];$cell='';$quoted=false;$n=strlen($s);
    for($i=0;$i<$n;$i++){$c=$s[$i];if($c==='"'){if($quoted&&$i+1<$n&&$s[$i+1]==='"'){$cell.='"';$i++;}else $quoted=!$quoted;}elseif($c===','&&!$quoted){$line[]=$cell;$cell='';}elseif(($c==="\n"||$c==="\r")&&!$quoted){if($c==="\r"&&$i+1<$n&&$s[$i+1]==="\n")$i++;$line[]=$cell;if(count(array_filter($line,fn($x)=>trim($x)!=='')))$rows[]=$line;$line=[];$cell='';}else $cell.=$c;}
    if($quoted)fail('CSV contains an unclosed quote.');$line[]=$cell;if(count(array_filter($line,fn($x)=>trim($x)!=='')))$rows[]=$line;return $rows;
}
function import_rows(array $b,array $u): array {
    $source=$b['source']??'';if(!in_array($source,['petpooja','paytm','daybook'],true))fail('Choose an import source.');$csv=(string)($b['csv']??'');if(strlen($csv)>1800000)fail('CSV exceeds the import size limit.');$data=parse_csv($csv);if(count($data)<2||count($data)>5001)fail('CSV needs a header and 1–5,000 data rows.');$header=array_map(fn($x)=>strtolower(trim($x)),array_shift($data));if(count(array_unique($header))!==count($header))fail('CSV has duplicate column names.');
    $required=$source==='paytm'?['date','reference','amount','status','kind']:['date','reference','account','amount'];foreach($required as $h)if(!in_array($h,$header,true))fail('Missing CSV column: '.$h);if($source==='daybook'&&!in_array('type',$header,true))fail('Missing CSV column: type');
    $added=0;$skipped=0;$preview=[];$seen=[];$dry=!empty($b['preview']);
    // Acquire business-day locks in date order; the original row numbers remain in validation errors.
    $dated=[];foreach($data as $i=>$r){$pos=array_search('date',$header,true);try{$dt=valid_date($r[$pos]??'');}catch(Throwable $e){fail('Row '.($i+2).': '.$e->getMessage().' Import rolled back.');}$dated[$dt]=true;}
    $dates=array_keys($dated);sort($dates);foreach($dates as $dt){if(!$dry)lock_day($dt);elseif((row('SELECT status FROM dd_days WHERE date=?',$dt)['status']??'open')==='closed')fail('An imported date is closed. Reopen it first.');}
    foreach($data as $i=>$r){
        $get=function(string $k)use($r,$header):string{$pos=array_search($k,$header,true);return $pos===false?'':txt($r[$pos]??'');};
        try{
            $dt=valid_date($get('date'));$ref=txt($get('reference'),120);if($ref==='')fail('Reference is required.');$key=$source.'-'.$ref;
            $fingerprint=hash('sha256',json_encode($r));if(isset($seen[$key])){if($seen[$key]!==$fingerprint)fail('The same reference appears with different data in this CSV.');$skipped++;continue;}$seen[$key]=$fingerprint;
            if($source==='paytm'){
                $kind=$get('kind');$status=$get('status');if(!in_array($kind,['payment','refund','settlement'],true)||!in_array($status,['success','pending','failed'],true))fail('Use payment/refund/settlement and success/pending/failed.');$amount=money($get('amount'));if(!$amount)fail('Amount must be positive.');$fee=money($get('fee')?:0);if($fee>$amount)fail('Fee exceeds amount.');
                $existing=row('SELECT * FROM dd_paytm WHERE external_id=?',$ref);if($existing){if($existing['date']!==$dt||(int)$existing['amount']!==$amount||$existing['status']!==$status||$existing['kind']!==$kind||(int)$existing['fee']!==$fee)fail('Existing Paytm reference changed. Review this transaction; it was not overwritten.');$skipped++;continue;}
                $preview[]=['date'=>$dt,'reference'=>$ref,'name'=>'Paytm '.$kind,'account'=>'Paytm','amount'=>$amount/100,'status'=>$status];
                if(!$dry){$linked=null;if($status==='success'&&$kind==='settlement')$linked=add_entry(['date'=>$dt,'name'=>'Paytm settlement','type'=>'settlement','account'=>'Paytm','to_account'=>'Bank of India','amount'=>number_format($amount/100,2,'.',''),'reference'=>$ref,'category'=>'Settlement','source'=>'paytm','source_key'=>'paytm-settlement-'.$ref],$u);if($status==='success'&&$kind==='refund')$linked=add_entry(['date'=>$dt,'name'=>'Paytm refund','type'=>'refund','account'=>'Paytm','amount'=>number_format($amount/100,2,'.',''),'reference'=>$ref,'source'=>'paytm','source_key'=>'paytm-refund-'.$ref],$u);if($status==='success'&&$fee)add_entry(['date'=>$dt,'name'=>'Paytm fee','type'=>'expense','account'=>'Paytm','amount'=>number_format($fee/100,2,'.',''),'reference'=>$ref,'category'=>'Payment fees','source'=>'paytm','source_key'=>'paytm-fee-'.$ref],$u);run('INSERT INTO dd_paytm(external_id,date,reference,amount,status,kind,fee,ledger_id) VALUES(?,?,?,?,?,?,?,?)',$ref,$dt,txt($get('bill_reference'),120),$amount,$status,$kind,$fee,$linked);}
            }else{
                $e=['date'=>$dt,'name'=>$get('name')?:'Petpooja sale','type'=>$source==='petpooja'?'sale':$get('type'),'account'=>$get('account'),'to_account'=>$get('to_account'),'amount'=>$get('amount'),'category'=>$get('category')?:'General','reference'=>$ref,'description'=>$get('description'),'source'=>$source,'source_key'=>$key,'employee_id'=>$get('employee_id'),'payroll_month'=>$get('payroll_month')];$validated=validate_entry($e);
                if(in_array($e['type'],['salary','advance'],true))fail('Record staff payments through Staff payment, after employee mapping and payroll review.');
                $existing=row('SELECT * FROM dd_ledger WHERE source_key=?',$key);if($existing){if($existing['date']!==$dt||(int)$existing['amount']!==$validated['amount']||$existing['type']!==$validated['type']||$existing['account']!==$validated['account'])fail('An existing source reference has changed. Review it instead of importing a duplicate.');$skipped++;continue;}
                $preview[]=$e;if(!$dry)add_entry($e,$u);
            }$added++;
        }catch(Throwable $e){if($e instanceof PDOException)throw $e;fail('Row '.($i+2).': '.$e->getMessage().' Import rolled back.');}
    }
    if(!$dry)audit($u,'import',['source'=>$source,'added'=>$added,'skipped'=>$skipped]);return ['ok'=>true,'added'=>$added,'skipped'=>$skipped,'preview'=>$dry?array_slice($preview,0,50):null];
}
