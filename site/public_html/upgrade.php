<?php
declare(strict_types=1);
// KSDD — one-click Database Upgrade (owner only). Applies every pending SQL update for CRM 360, Salary Register and Books 360
// from the browser: no phpMyAdmin / SSH needed. All steps only ADD tables/columns/settings; nothing is deleted or rewritten.
require __DIR__.'/lib.php'; require __DIR__.'/migrations.php'; require __DIR__.'/lib360.php'; require __DIR__.'/salary360.php'; require __DIR__.'/books360.php';
if(PHP_SAPI==='cli'){ $a=migrate_all(null); sal_migrate(); bk_migrate(); echo 'CRM schema '.schema_version().' (applied: '.($a?implode(',',$a):'none').'), Salary '.setting('salary_schema','-').', Books '.setting('books_schema','-').PHP_EOL; exit(0); }
security_headers(); $msg=''; $err=''; $log=[];
function up_status(): array {
    $tables=[]; foreach(rows("SELECT TABLE_NAME t,TABLE_ROWS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'dd\\_%'") as $r) $tables[$r['t']]=(int)$r['n'];
    $crm=schema_version(); $sal=(int)setting('salary_schema','0'); if(!$sal&&isset($tables['dd_sal_months'])) $sal=1; $bk=(int)setting('books_schema','0'); if(!$bk&&isset($tables['dd_bk_txns'])) $bk=1;
    $mods=[['CRM 360 core (daybook, staff, stock, suppliers, GL)',$crm,max(array_keys(migrations())),'migrate_all'],['Salary Register',$sal,SAL_MIGRATION,'sal_migrate'],['Books 360 (Vyapar replacement)',$bk,BK_SCHEMA,'bk_migrate']];
    $env=[['PHP version',PHP_VERSION,PHP_VERSION_ID>=80200],['pdo_mysql',extension_loaded('pdo_mysql')?'yes':'missing',extension_loaded('pdo_mysql')],['zip (Excel .xlsx import/export)',class_exists('ZipArchive')?'yes':'missing',class_exists('ZipArchive')],['mbstring',extension_loaded('mbstring')?'yes':'missing',extension_loaded('mbstring')],['Uploads folder writable',is_writable(private_root().'/uploads')?'yes':'no',is_writable(private_root().'/uploads')],
          ['Database',(string)row('SELECT VERSION() v')['v'],true],['Time zone (PHP)',date_default_timezone_get().' '.date('d M Y H:i'),true],['HTTPS',https_request()?'yes':'no',https_request()||local_request()]];
    return [$mods,$tables,$env];
}
try{
    start_session(); $u=user(); if(!can($u,'*')) fail('Only the owner can run database upgrades.',403);
    if($_SERVER['REQUEST_METHOD']==='POST'){ check_csrf((string)($_POST['csrf']??'')); set_time_limit(300);
        $a=migrate_all($u); $log[]='CRM 360: '.($a?'applied version(s) '.implode(', ',$a):'already up to date (v'.schema_version().')');
        $s0=setting('salary_schema','0'); sal_migrate(); $log[]='Salary Register: '.($s0===(string)SAL_MIGRATION?'already up to date':'installed / updated to v'.SAL_MIGRATION);
        $b0=setting('books_schema','0'); bk_migrate(); $log[]='Books 360: '.($b0===(string)BK_SCHEMA?'already up to date':'installed / updated to v'.BK_SCHEMA);
        audit($u,'system.upgrade',['log'=>$log]); $msg='Database upgrade finished. Existing records were preserved.'; }
    [$mods,$tables,$env]=up_status();
}catch(Throwable $e){ $err=$e instanceof RuntimeException?$e->getMessage():'Upgrade failed — nothing was deleted. Check the PHP error log.'; if(!$e instanceof RuntimeException) error_log('KSDD upgrade: '.$e->getMessage()); }
$h=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Database upgrade · KSDD</title><link rel="stylesheet" href="assets/salary.css?v=2"></head><body>
<header class="top"><div class="brand"><i>⟳</i><div><b>Database upgrade</b><small>KHADAK SINGH DA DHABA</small></div></div><div class="sp"></div><a href="index.php">CRM</a><a href="salary.php">Salary</a><a href="books.php">Books</a></header>
<main class="wrap" style="max-width:980px">
<?php if($err): ?><div class="note w"><?= $h($err) ?><?php if(str_contains($err,'sign in')): ?> — <a href="index.php">sign in as owner</a><?php endif; ?></div><?php endif; ?>
<?php if($msg): ?><div class="note"><b><?= $h($msg) ?></b><ul><?php foreach($log as $l) echo '<li>'.$h($l).'</li>'; ?></ul></div><?php endif; ?>
<?php if(isset($mods)): $pending=array_filter($mods,fn($m)=>$m[1]<$m[2]); ?>
<div class="card"><div class="card-h"><h2>Modules</h2><?= $pending?'<span class="st Pending">'.count($pending).' update(s) pending</span>':'<span class="st Clear">All up to date</span>' ?></div><div class="tw"><table><thead><tr><th>Module</th><th class="n">Installed</th><th class="n">Latest</th><th>Status</th></tr></thead><tbody>
<?php foreach($mods as $m): ?><tr><td><?= $h($m[0]) ?></td><td class="n"><?= $m[1]?'v'.$h($m[1]):'—' ?></td><td class="n">v<?= $h($m[2]) ?></td><td><?= $m[1]>=$m[2]?'<span class="st Clear">Up to date</span>':'<span class="st Pending">'.($m[1]?'Update available':'Not installed').'</span>' ?></td></tr><?php endforeach; ?>
</tbody></table></div><div class="card-b"><p class="mut sm-t" style="line-height:1.7">Step 1 — <a href="api.php?route=backup"><b>Download full database backup (.sql)</b></a> and <a href="api.php?route=backup-files">private files</a>. Step 2 — press the button. Upgrades only <b>add</b> tables, columns and default settings; your data is never deleted. Every run is written to the audit log.</p>
<form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= $h(csrf()) ?>"><button class="pri"><?= $pending?'Run database upgrade':'Re-check / repair database' ?></button></form></div></div>
<div class="card"><div class="card-h"><h2>Server health</h2></div><div class="tw"><table><tbody><?php foreach($env as $e): ?><tr><td><?= $h($e[0]) ?></td><td><?= $h($e[1]) ?></td><td><?= $e[2]?'<span class="st Clear">OK</span>':'<span class="st Overpaid">Fix</span>' ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><div class="card-h"><h2>Tables (<?= count($tables) ?>)</h2><span class="mut sm-t">approximate row counts</span></div><div class="tw" style="max-height:420px"><table><tbody><?php ksort($tables); foreach($tables as $t=>$n): ?><tr><td><?= $h($t) ?></td><td class="n"><?= number_format($n) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif; ?></main></body></html>
