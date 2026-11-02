<?php
declare(strict_types=1);
// KSDD Books 360 — JSON API (same login / CSRF as the CRM). Needs read (view) / write (entries) / close (lock) / manage (owner settings).
require __DIR__.'/books360.php';
security_headers();
try {
    start_session();
    $method=$_SERVER['REQUEST_METHOD']; $r=(string)($_GET['r']??'');
    if(!preg_match('/^[a-z-]{2,30}$/',$r)) fail('Not found',404);
    $GET=['boot','daybook','month','parties','party','stock','item','txn','audit','export','lists'];
    if(!in_array($method,['GET','POST'],true)||($method==='GET')!==in_array($r,$GET,true)) fail('Method not allowed',405);
    if(!configured()||!installed()) fail('Complete install.php first.',503);
    $u=user(); legacy_guard($u,$method==='POST'); allowed($u,'read');
    if($method==='POST'){ check_csrf(); allowed($u,'write'); }
    $b=$method==='POST'?(function(){ if((int)($_SERVER['CONTENT_LENGTH']??0)>40000000) fail('Upload too large (max ~25 MB of files).',413); $j=json_decode(file_get_contents('php://input')?:'{}',true,64,JSON_THROW_ON_ERROR); if(!is_array($j)) fail('Invalid request.'); return $j; })():[];
    if($r==='setup'){ if(!can($u,'*')) fail('Only the owner can set up Books.',403); bk_migrate(); audit($u,'books.setup'); send(['ok'=>true]); }
    if($r==='boot'){ $ok=bk_installed(); send(['user'=>['name'=>$u['name'],'role'=>$u['role'],'owner'=>can($u,'*'),'close'=>can($u,'close')],'csrf'=>csrf(),'today'=>date('Y-m-d'),'installed'=>$ok,'restaurant'=>setting('restaurant','Khadak Singh Da Dhaba'),
        'types'=>array_map(fn($t)=>['label'=>$t[0],'dir'=>$t[1],'party'=>$t[2],'lines'=>$t[3]],BK_TYPES),'groups'=>BK_GROUPS,'accounts'=>$ok?bk_accounts():BK_DEFAULT_ACCOUNTS,'categories'=>$ok?bk_categories():[],'months'=>$ok?bk_months():[],
        'opening_cash'=>$ok?setting_int('bk_opening_cash',0):0,'cash_limit'=>$ok?setting_int('bk_cash_limit',20000000):0]); }
    bk_ready();
    $q=$_GET;
    switch($r){
    case 'lists': send(['parties'=>rows('SELECT id,name,kind FROM dd_bk_parties WHERE active=1 ORDER BY name'),'items'=>rows('SELECT i.id,i.name,i.unit,i.category,(SELECT l.rate FROM dd_bk_lines l JOIN dd_bk_txns t ON t.id=l.txn_id AND t.voided=0 AND t.type=\'purchase\' WHERE l.item_id=i.id ORDER BY t.date DESC,l.id DESC LIMIT 1) last_rate FROM dd_bk_items i WHERE i.active=1 ORDER BY i.name'),'categories'=>bk_categories(),'accounts'=>bk_accounts()]);
    case 'daybook': send(bk_daybook(valid_date($q['from']??''),valid_date($q['to']??''),(string)($q['account']??'Cash')));
    case 'month': send(bk_month_summary(valid_month($q['m']??'')));
    case 'parties': send(['parties'=>bk_party_balances()]);
    case 'party': send(bk_party_statement((int)($q['id']??0)));
    case 'stock': send(['items'=>bk_stock()]);
    case 'item': send(bk_item_card((int)($q['id']??0)));
    case 'txn': $t=bk_txn_rows('t.id=?',[(int)($q['id']??0)]); if(!$t) fail('Not found',404); send($t[0]);
    case 'audit': send(['issues'=>bk_audit(!empty($q['m'])?valid_month($q['m']):null)]);
    case 'export': bk_export((string)($q['what']??''),$q);
    }
    $files=function()use($b){ $o=[]; foreach((array)($b['files']??[]) as $f){ $d=base64_decode((string)($f['data']??''),true); if(!$d) fail('A file could not be read.'); $o[]=['name'=>txt($f['name']??'file',160),'data'=>$d]; } if(!$o) fail('Choose the Vyapar file(s).'); return $o; };
    $res=transaction(fn()=>idem('books:'.$r,$b,$u,function()use($r,$b,$u,$files){ // fix C13: retry-safe when the screen sends client_key
        switch($r){
        case 'txn-save': return bk_txn_save($b,$u);
        case 'txn-void': return bk_txn_void($b,$u);
        case 'party-save': return bk_party_save($b,$u);
        case 'party-merge': allowed($u,'close'); return bk_party_merge($b,$u);
        case 'item-save': return bk_item_save($b,$u);
        case 'item-merge': allowed($u,'close'); return bk_item_merge($b,$u);
        case 'reclassify': allowed($u,'close'); return bk_reclassify($b,$u);
        case 'move-save': return bk_move_save($b,$u);
        case 'month-lock': allowed($u,'close'); return bk_month_lock($b,$u,true);
        case 'month-unlock': return bk_month_lock($b,$u,false);
        case 'import-preview': allowed($u,'close'); return bk_vy_preview($files(),(array)($b['options']??[]));
        case 'import-confirm': allowed($u,'close'); return bk_vy_confirm($files(),(array)($b['options']??[]),$u);
        case 'settings': if(!can($u,'*')) fail('Owner only.',403);
            $acc=array_values(array_unique(array_filter(array_map(fn($s)=>txt($s,40),(array)($b['accounts']??[]))))); if(!in_array('Cash',$acc,true)) array_unshift($acc,'Cash'); set_setting('bk_accounts',json_encode($acc));
            $cats=[]; foreach((array)($b['categories']??[]) as $c=>$g){ $c=txt($c,80); if($c!==''&&in_array($g,BK_GROUPS,true)) $cats[$c]=$g; } if($cats) set_setting('bk_categories',json_encode($cats,JSON_UNESCAPED_UNICODE));
            if(isset($b['opening_cash'])){ $oc=bk_paise($b['opening_cash'],'Opening cash',true); if($oc!==setting_int('bk_opening_cash',0)&&($lk=bk_any_locked())) fail('Opening cash changes every past cash balance, and '.$lk.' is locked. Record a dated Cash adjustment in an open month instead.',409); set_setting('bk_opening_cash',(string)$oc); } if(isset($b['cash_limit'])) set_setting('bk_cash_limit',(string)bk_paise($b['cash_limit'],'Cash limit'));
            audit($u,'books.settings',['accounts'=>$acc]); return ['ok'=>true];
        default: fail('Not found',404);
        }
    }));
    send($res);
} catch(Throwable $e){ try{ if(configured()&&db()->inTransaction()) db()->rollBack(); }catch(Throwable $ignored){} error_response($e); }
