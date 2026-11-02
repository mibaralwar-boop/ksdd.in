<?php
declare(strict_types=1);
// Petpooja upload endpoint for the restaurant Windows PC (Power Automate Desktop / PowerShell).
// POST text/csv body with header "Authorization: Bearer <token>". config.php holds only the SHA-256 of the token.
require __DIR__.'/lib.php'; require __DIR__.'/lib360.php'; require __DIR__.'/pos360.php';
security_headers();
try{
    if($_SERVER['REQUEST_METHOD']!=='POST') fail('POST only',405);
    if(!https_request()&&!local_request()) fail('HTTPS required',403);
    $want=(string)(cfg()['sync_token_sha256']??''); $got=preg_match('/^Bearer\s+(\S{32,200})$/',(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''),$m)?hash('sha256',$m[1]):'';
    if(strlen($want)!==64||!hash_equals($want,$got)) fail('Unauthorized',401);
    // V29: in multi-restaurant mode this legacy Petpooja upload serves exactly ONE configured branch (the original restaurant).
    if(platform_mode()){ require_once __DIR__.'/platform/tenancy.php'; db_switch(tenancy_target_by_branch_uid((string)(cfg()['sync_branch_uid']??''))); }
    crm_ready(); if((int)($_SERVER['CONTENT_LENGTH']??0)>1000000) fail('File too large',413);
    $csv=(string)file_get_contents('php://input',false,null,0,1000001);
    $key='pos-sync-'.hash('sha256',$csv);
    $res=transaction(function()use($csv,$key){
        if($j=row('SELECT * FROM dd_jobs WHERE idem_key=?',$key)) return ['ok'=>true,'duplicateFile'=>true,'job'=>(int)$j['id']];
        run('INSERT INTO dd_jobs(job,idem_key,trigger_by,status,started_at) VALUES(?,?,?,?,?)','petpooja-sync',$key,'windows-pc','running',now_sql()); $jid=(int)db()->lastInsertId();
        $r=pos_import($csv,null,'windows-sync',false,false);
        run('UPDATE dd_jobs SET status=?,processed=?,skipped=?,finished_at=? WHERE id=?',$r['conflicts']?'warning':'ok',$r['imported'],$r['skipped']+$r['conflicts'],now_sql(),$jid);
        return $r+['job'=>$jid];
    });
    send($res);
}catch(Throwable $e){
    try{ if(configured()&&db()->inTransaction())db()->rollBack(); if(configured()&&schema_version()>=2&&($e->getCode()!==401)){ run('INSERT INTO dd_jobs(job,trigger_by,status,errors,started_at,finished_at) VALUES(?,?,?,?,?,?)','petpooja-sync','windows-pc','failed',txt($e->getMessage(),500),now_sql(),now_sql()); inbox_raise('pos-sync-failed','sales','Petpooja sync failed',['priority'=>'high','detail'=>txt($e->getMessage(),500),'dedupe'=>'possync-'.gmdate('YmdH')]); } }catch(Throwable $ignored){}
    error_response($e);
}
