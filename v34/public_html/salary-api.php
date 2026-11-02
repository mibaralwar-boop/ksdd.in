<?php
declare(strict_types=1);
// KSDD Salary Register 360 — JSON API. Same login/session/CSRF as the CRM (lib.php). Needs the "payroll" permission.
require __DIR__.'/salary360.php';
security_headers();
try {
    start_session();
    $method=$_SERVER['REQUEST_METHOD']; $r=(string)($_GET['r']??'');
    if(!preg_match('/^[a-z-]{2,30}$/',$r)) fail('Not found',404);
    $GET=['boot','month','employees','employee','dues','export','audit','staff-links','pay-check'];
    if(!in_array($method,['GET','POST'],true)||($method==='GET')!==in_array($r,$GET,true)) fail('Method not allowed',405);
    if(!configured()||!installed()) fail('Complete install.php first.',503);
    $u=user(); legacy_guard($u,$method==='POST'); allowed($u,'payroll');
    if($method==='POST') check_csrf();
    $b=$method==='POST'?(function(){ if((int)($_SERVER['CONTENT_LENGTH']??0)>12000000) fail('Request too large.',413); $j=json_decode(file_get_contents('php://input')?:'{}',true,64,JSON_THROW_ON_ERROR); if(!is_array($j)) fail('Invalid request.'); return $j; })():[];

    if($r==='setup'){ if(!can($u,'*')) fail('Only the owner can set up the Salary Register.',403); sal_migrate(); audit($u,'salary.setup'); send(['ok'=>true]); }
    if($r==='boot'){
        $ok=sal_installed();
        send(['user'=>['name'=>$u['name'],'role'=>$u['role'],'owner'=>can($u,'*')],'csrf'=>csrf(),'restaurant'=>setting('restaurant','Khadak Singh Da Dhaba'),'today'=>date('Y-m-d'),'installed'=>$ok,
              'sections'=>$ok?sal_sections():SAL_SECTIONS,'modes'=>$ok?sal_modes():SAL_MODES,'months'=>$ok?sal_months_list():[]]);
    }
    sal_ready();
    switch($r){
    case 'month': send(sal_month_view(valid_month($_GET['m']??'')));
    case 'staff-links': send(sal_staff_links());
    case 'pay-check': send(sal_pay_check());
    case 'employees': send(['employees'=>sal_employees()]);
    case 'employee': send(sal_employee_card((int)($_GET['id']??0)));
    case 'dues': send(sal_dues());
    case 'audit': $o=[]; foreach(rows('SELECT month FROM dd_sal_months ORDER BY month') as $mo){ $v=sal_month_view($mo['month']); foreach($v['rows'] as $x) foreach($x['issues'] as $i) $o[]=['month'=>$mo['month'],'severity'=>$i[0],'text'=>$i[1],'name'=>$x['name'],'row_id'=>$x['id'],'section'=>$x['section']]; }
        $d=sal_dues(); foreach($d['rows'] as $x) if($x['left_sheet']) $o[]=['month'=>$x['month'],'severity'=>'high','text'=>'Not on the latest sheet but closing balance ₹'.number_format($x['balance']/100,0).($x['balance']>0?' is still owed to this staff':' is still owed by this staff').' — settle it','name'=>$x['name'],'row_id'=>$x['row_id'],'section'=>$x['section']];
        send(['issues'=>$o]);
    case 'export': $m=valid_month($_GET['m']??''); sal_month($m); $x=sal_xlsx_export($m); audit($u,'salary.export',['month'=>$m]);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="'.$x['name'].'"'); header('Content-Length: '.strlen($x['data'])); echo $x['data']; exit;
    }
    $res=transaction(fn()=>idem('salary:'.$r,$b,$u,function()use($r,$b,$u){ // fix C13: retry-safe when the screen sends client_key
        switch($r){
        case 'row-save': return sal_row_save($b,$u);
        case 'row-add': return sal_row_add($b,$u);
        case 'row-remove': return sal_row_remove($b,$u);
        case 'entry-add': return sal_entry_add($b,$u);
        case 'entry-void': return sal_entry_void($b,$u);
        case 'pay-balance': return sal_pay_balance($b,$u);
        case 'pay-all': $m=valid_month($b['month']??''); sal_open_month($m); $n=0; $sum=0; $ids=array_map('intval',(array)($b['row_ids']??[]));
            foreach(sal_month_view($m)['rows'] as $x){ if($x['balance']<=0||($ids&&!in_array($x['id'],$ids,true))) continue; sal_pay_balance(['row_id'=>$x['id'],'mode'=>$b['mode']??($x['pay_mode']?:'Cash'),'date'=>$b['date']??date('Y-m-d'),'round'=>!empty($b['round']),'note'=>'Bulk balance payment'],$u); $n++; $sum+=$x['balance']; }
            return ['ok'=>true,'paid_rows'=>$n,'amount'=>$sum];
        case 'month-create': return sal_month_create($b,$u);
        case 'month-settings': return sal_month_settings($b,$u);
        case 'month-lock': return sal_month_lock($b,$u,true);
        case 'month-unlock': return sal_month_lock($b,$u,false);
        case 'month-delete': return sal_month_delete($b,$u);
        case 'refresh-bf': return sal_refresh_bf($b,$u);
        case 'employee-save': return sal_employee_save($b,$u);
        case 'import-preview': $d=base64_decode((string)($b['data']??''),true); if(!$d) fail('Choose the Excel file.'); return sal_import_preview($d);
        case 'import-confirm': $d=base64_decode((string)($b['data']??''),true); if(!$d) fail('Choose the Excel file.'); return sal_import_confirm($d,(string)($b['name']??'salary.xlsx'),$b,$u);
        case 'att-preview': $d=base64_decode((string)($b['data']??''),true); if(!$d) fail('Choose the attendance file.'); return sal_att_preview($d,!empty($b['month'])?valid_month($b['month']):null);
        case 'att-confirm': $d=base64_decode((string)($b['data']??''),true); if(!$d) fail('Choose the attendance file.'); return sal_att_confirm($d,$b,$u);
        case 'att-apply': return sal_att_apply($b,$u);
        case 'staff-link-save': return sal_staff_link_save($b,$u);
        case 'settings': if(!can($u,'*')) fail('Owner only.',403);
            $sec=array_values(array_unique(array_filter(array_map(fn($s)=>txt($s,40),(array)($b['sections']??[]))))); $mod=array_values(array_unique(array_filter(array_map(fn($s)=>txt($s,40),(array)($b['modes']??[])))));
            if(count($sec)<1||count($mod)<1) fail('Keep at least one section and one payment mode.');
            set_setting('salary_sections',json_encode($sec)); set_setting('salary_modes',json_encode($mod)); set_setting('salary_tolerance',(string)sal_paise($b['tolerance']??'10','Tolerance')); audit($u,'salary.settings',['sections'=>$sec,'modes'=>$mod]); return ['ok'=>true];
        default: fail('Not found',404);
        }
    }));
    send($res);
} catch(Throwable $e){ try{ if(configured()&&db()->inTransaction()) db()->rollBack(); }catch(Throwable $ignored){} error_response($e); }
