<?php
declare(strict_types=1);
// One-time CRM 360 database update. Owner-only in the browser; also runnable from SSH: php migrate.php
require __DIR__.'/lib.php'; require __DIR__.'/migrations.php';
if(PHP_SAPI==='cli'){ $a=migrate_all(null); echo 'Schema version '.schema_version().'. Applied: '.($a?implode(',',$a):'nothing (already current)').PHP_EOL; exit(0); }
security_headers(); $msg='';$err='';
try{
    start_session(); $u=user(); allowed($u,'manage');
    if($_SERVER['REQUEST_METHOD']==='POST'){ check_csrf((string)($_POST['csrf']??'')); $a=migrate_all($u); $msg=$a?'Applied migration(s): '.implode(', ',$a).'. Existing records were preserved.':'Database is already up to date.'; }
}catch(Throwable $e){ $err=$e instanceof RuntimeException?$e->getMessage():'Migration failed. Check the PHP error log. Nothing was deleted.'; if(!$e instanceof RuntimeException)error_log('KSDD migrate: '.$e->getMessage()); }
$h=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>KSDD database update</title><link rel="stylesheet" href="assets/style.css"></head><body style="padding:30px"><div class="card" style="max-width:560px;margin:auto;padding:26px">
<h1>CRM 360 database update</h1><p>Current schema version: <b><?= isset($u)?$h(schema_version()):'—' ?></b>. This adds new tables and columns only. It never deletes or rewrites existing records. Take a database backup first (Settings → Download backup).</p>
<?php if($msg): ?><div class="notification"><?= $h($msg) ?></div><p><a href="index.php">Open the CRM</a></p><?php endif; ?>
<?php if($err): ?><div class="form-error"><?= $h($err) ?></div><?php if(str_contains($err,'sign in')): ?><p><a href="index.php">Sign in as owner first</a></p><?php endif; endif; ?>
<?php if(isset($u)&&!$msg): ?><form method="post"><input type="hidden" name="csrf" value="<?= $h(csrf()) ?>"><button class="primary">Run database update</button></form><?php endif; ?>
</div></body></html>
