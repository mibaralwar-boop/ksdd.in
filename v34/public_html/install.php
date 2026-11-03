<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
security_headers();
// V34: an EXISTING installation whose config.php was not found must never be offered "first-time setup".
// Explain where the settings file must be and point at a misplaced copy instead (paths shown relative to the domain folder).
if(!configured()){
    $dom=dirname(__DIR__); $rel=fn(string $p)=>ltrim(substr($p,strlen($dom)),'/');
    $misplaced=[];
    foreach([__DIR__.'/ksdd_private/config.php',__DIR__.'/FINANCE360_V34/ksdd_private/config.php',$dom.'/FINANCE360_V34/ksdd_private/config.php',__DIR__.'/private/config.php',__DIR__.'/ksdd_private/config.example.php'] as $f) if(is_file($f)) $misplaced[]=$rel($f);
    $oldInstall=is_dir(__DIR__.'/private/uploads')||is_dir($dom.'/ksdd_private/uploads');
    http_response_code(503); header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Settings file not found</title>'
       .'<body style="font-family:system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 18px;line-height:1.55;color:#1b2b24"><h1>Settings file not found</h1>'
       .'<p>The website files are in place, but the database settings file <b>config.php</b> was not found, so the site cannot open your existing data. <b>Your data is not lost.</b> Do not run a new installation.</p>'
       .'<p>Put your existing <b>config.php</b> (database name, user, password, setup_key, pii_key) in <b>one</b> of these places:</p><ol>'
       .'<li><code>'.htmlspecialchars($rel($dom.'/ksdd_private/config.php')).'</code> — recommended: a folder <b>next to</b> public_html, not inside it</li>'
       .'<li><code>'.htmlspecialchars($rel(__DIR__.'/config.php')).'</code> — the old location (still accepted)</li></ol>'
       .($misplaced?'<p style="background:#fff3cd;padding:10px;border-radius:8px">Found in the wrong place: <code>'.htmlspecialchars(implode('</code>, <code>',$misplaced)).'</code><br>Move <b>config.php</b> (not config.example.php) to the location in step 1 and delete the misplaced folder from public_html.</p>':'')
       .'<p>No copy of your old config.php? Restore it from hPanel → Files → Backups (a backup from before the upload), or ask your developer to recreate it with the SAME setup_key and pii_key.</p>'
       .($oldInstall?'<p>Existing uploads were found, which confirms this is an existing installation.</p>':'').'</body>';
    exit;
}
if(platform_mode()){ http_response_code(404); exit('Not available in multi-restaurant mode. Restaurants are created by registration; platform admins use platform-admin/.'); }
$error='';$done=false;$requirements=PHP_VERSION_ID>=80200&&extension_loaded('pdo_mysql');
try {
    start_session();
    if(configured()&&installed()) { header('Location: index.php');exit; }
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!$requirements)fail('Select PHP 8.2 or newer and enable pdo_mysql in Hostinger.');
        check_csrf((string)($_POST['csrf']??''));
        $key=(string)(cfg()['setup_key']??'');
        if(strlen($key)<32||str_starts_with($key,'REPLACE_')||!hash_equals($key,(string)($_POST['setup_key']??'')))fail('The setup key does not match ksdd_private/config.php.');
        if(!configured())fail('Enter your Hostinger database credentials in ksdd_private/config.php first.');
        $name=name_required($_POST['name']??'');$email=strtolower(txt($_POST['email']??'',190));$password=(string)($_POST['password']??'');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12||strlen($password)>200)fail('Use a valid email address and a password of 12–200 characters.');
        $lock='dd_install_'.substr(hash('sha256',(string)cfg()['db_name']),0,35);
        if((int)row('SELECT GET_LOCK(?,10) n',$lock)['n']!==1)fail('Another installation is in progress. Try again shortly.');
        try {
            if(installed())fail('Installation is already complete. Open the dashboard.');
            foreach(explode(';',file_get_contents(__DIR__.'/schema.sql')) as $sql)if(trim($sql)!=='')db()->exec($sql);
            if(row('SELECT id FROM dd_users LIMIT 1'))fail('An owner already exists. Do not run installation again.');
            transaction(function()use($name,$email,$password){
                run('INSERT INTO dd_users(name,email,password,role) VALUES(?,?,?,?)',$name,$email,password_hash($password,PASSWORD_DEFAULT),'owner');
                $owner=['id'=>(int)db()->lastInsertId(),'role'=>'owner'];
                run('INSERT INTO dd_settings(`key`,value) VALUES(?,?)','restaurant',txt($_POST['restaurant']??cfg()['restaurant']??'Khadak Singh Da Dhaba',160));
                if(!empty($_POST['sample'])){
                    $samples=json_decode(file_get_contents((is_file(private_root().'/sample-daybook.json')?private_root():__DIR__.'/private').'/sample-daybook.json'),true,64,JSON_THROW_ON_ERROR);
                    foreach($samples as $i=>$e)add_entry($e+['date'=>'2026-08-31','source'=>'sample-workbook','source_key'=>'sample-20260831-'.$i],$owner);
                    run('UPDATE dd_days SET note=? WHERE date=?','Sample daybook. Opening cash was not supplied. Allocate mixed Petpooja payment before closing.','2026-08-31');
                }
                run('INSERT INTO dd_settings(`key`,value) VALUES(?,?)','installed','1');
                run('INSERT INTO dd_settings(`key`,value) VALUES(?,?)','schema_version','1');
                audit($owner,'installation.complete',['sample_loaded'=>!empty($_POST['sample'])]);
            });
            require_once __DIR__.'/migrations.php';migrate_all(null); // CRM 360 tables on fresh installs
        } finally {run('SELECT RELEASE_LOCK(?)',$lock);}
        session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));$done=true;
    }
}catch(Throwable $e){if($e instanceof PDOException){error_log('Dhaba Desk installer database error: '.$e->getCode());$error='Database connection or setup failed. Check the database host, full database name, user and password in config.php. The database user must have permission to create tables.';}else $error=$e->getMessage();}
function h(mixed $s): string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Install Dhaba Desk</title><link rel="stylesheet" href="assets/style.css"><style>body{padding:35px 18px}.installer{max-width:590px;margin:auto}.installer .card{padding:30px}.installer h1{margin:14px 0}.installer p{color:#7e8975;font-size:12px;line-height:1.8}.installer .field{margin-top:17px}.installer .brand{padding:0 0 15px}.setup-note{margin:20px 0;background:#f2f5e9;border-radius:9px;padding:15px}.installer button{margin-top:22px}.setup-check{display:flex;gap:9px;align-items:start;font-size:11px;line-height:1.7;color:#7f8c70;margin-top:20px}.setup-check input{margin-top:4px}.error{background:#fff0e9;color:#a65742;padding:14px;border-radius:8px;font-size:12px;line-height:1.7;margin-top:20px}</style></head><body><main class="installer"><div class="brand"><div class="brand-icon">d.</div><div><b>dhaba desk</b><small>HOSTINGER · PHP + MYSQL</small></div></div><section class="card">
<?php if($done): ?><div class="eyebrow">WORKSPACE READY</div><h1>Installation complete.</h1><p>Your owner account is ready. Sign in using the email and password you just entered. The installer is now locked by the database.</p><a href="index.php"><button class="primary full">Open dashboard →</button></a><p style="margin-top:20px">You can delete install.php through Hostinger File Manager after signing in.</p>
<?php else: ?><div class="eyebrow">FIRST-TIME SETUP</div><h1>Make it your workspace.</h1><p>Configure your database, then create the owner account. Existing restaurant website files are separate from this dashboard.</p><div class="setup-note"><p><b>1.</b> In Hostinger, create a MySQL database and database user.<br><b>2.</b> Open <b>ksdd_private/config.php</b> in File Manager and enter those details.<br><b>3.</b> Copy its unique <b>setup_key</b> into the form below.</p></div><p>PHP <?=h(PHP_VERSION)?> · pdo_mysql <?=extension_loaded('pdo_mysql')?'available':'not enabled'?> · Database <?=configured()?'configured':'needs configuration'?></p>
<?php if($error):?><div class="error" role="alert"><?=h($error)?></div><?php endif;?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf']??'')?>"><label class="field">Setup key from config.php<input type="password" name="setup_key" minlength="32" required autocomplete="off"></label><label class="field">Restaurant name<input name="restaurant" value="<?=h(cfg()['restaurant']??'Khadak Singh Da Dhaba')?>" required maxlength="160"></label><div class="form-grid"><label class="field">Owner name<input name="name" required maxlength="160" autocomplete="name"></label><label class="field">Owner email<input name="email" type="email" required maxlength="190" autocomplete="username"></label></div><label class="field">New owner password (12+ characters)<input name="password" type="password" minlength="12" maxlength="200" required autocomplete="new-password"></label><label class="setup-check"><input type="checkbox" name="sample" value="1"><span>Load the supplied sample daybook dated 31 August 2026. Leave unchecked for an empty live workspace. Sample entries affect that historical date only.</span></label><button class="primary full" <?=$requirements?'':'disabled'?>>Create restaurant workspace →</button></form><?php endif;?></section></main></body></html>
