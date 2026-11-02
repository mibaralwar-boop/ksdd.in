<?php
declare(strict_types=1);
// One-time owner login recovery. Upload, use once, then DELETE this file.
// Proof of ownership = the setup_key in config.php (only someone with Hostinger File Manager access can read it).
require __DIR__.'/lib.php';
security_headers();
if(platform_mode()){ http_response_code(404); exit('Not available in multi-restaurant mode. Restaurants are created by registration; platform admins use platform-admin/.'); }
$err='';$done='';$owners=[];
try{
    start_session();
    // Audit C19: recovery is OFF unless the owner creates private/ENABLE-OWNER-RESET in File Manager (delete it, or this file, after use).
    if(!is_file(private_root().'/ENABLE-OWNER-RESET')){ http_response_code(404); fail('Owner recovery is disabled. To use it, create an empty file private/ENABLE-OWNER-RESET in Hostinger File Manager, then reload. Delete it again afterwards.'); }
    if(!configured()||!installed()) fail('The CRM is not installed yet.');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf((string)($_POST['csrf']??''));
        $key=(string)(cfg()['setup_key']??'');
        if(strlen($key)<32||!hash_equals($key,trim((string)($_POST['setup_key']??'')))){ sleep(2); fail('Setup key does not match config.php.'); }
        $owners=rows("SELECT id,name,email FROM dd_users WHERE role='owner' ORDER BY id");
        if(!empty($_POST['user_id'])){
            $id=(int)$_POST['user_id']; $pw=(string)($_POST['password']??'');
            $o=row("SELECT * FROM dd_users WHERE id=? AND role='owner'",$id); if(!$o) fail('Owner account not found.');
            if(strlen($pw)<12||strlen($pw)>200) fail('New password must be 12–200 characters.');
            if($pw!==(string)($_POST['password2']??'')) fail('The two passwords do not match.');
            $email=strtolower(trim((string)($_POST['email']??'')))?:$o['email'];
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Enter a valid email.');
            if(row('SELECT id FROM dd_users WHERE email=? AND id<>?',$email,$id)) fail('That email is already used by another user.');
            transaction(function()use($id,$pw,$email){
                $cols=row("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dd_users' AND COLUMN_NAME='active'");
                run('UPDATE dd_users SET email=?,password=?,session_rev=session_rev+1'.($cols?',active=1':'').' WHERE id=?',$email,password_hash($pw,PASSWORD_DEFAULT),$id);
                run('DELETE FROM dd_login_attempts');
                audit(['id'=>$id],'owner.password.reset',['via'=>'reset-owner.php','ip'=>$_SERVER['REMOTE_ADDR']??'']);
            });
            $done='Done. Sign in with '.$email.' and your new password. Now DELETE reset-owner.php from File Manager.';
            $owners=[];
        }
    }
}catch(Throwable $e){ $err=$e instanceof RuntimeException?$e->getMessage():'Something went wrong. Check database settings in config.php.'; }
$h=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); $key=$h($_POST['setup_key']??'');
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Owner login recovery</title><link rel="stylesheet" href="assets/style.css"></head>
<body style="padding:30px 16px"><div class="card" style="max-width:520px;margin:auto;padding:26px">
<h1>Owner login recovery</h1>
<p style="margin:10px 0">Copy <b>setup_key</b> from <b>config.php</b> (Hostinger File Manager) and paste it below.</p>
<?php if($err): ?><div class="form-error" style="margin:10px 0"><?= $h($err) ?></div><?php endif; ?>
<?php if($done): ?><div class="notification" style="margin:10px 0"><?= $h($done) ?></div><p><a href="index.php"><button class="primary">Open the CRM</button></a></p>
<?php elseif($owners): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= $h(csrf()) ?>"><input type="hidden" name="setup_key" value="<?= $key ?>">
<label class="field">Owner account<select name="user_id"><?php foreach($owners as $o): ?><option value="<?= (int)$o['id'] ?>"><?= $h($o['name'].' — '.$o['email']) ?></option><?php endforeach; ?></select></label>
<label class="field">Login email (leave blank to keep the one shown above)<input name="email" type="email" autocomplete="username"></label>
<label class="field">New password (12+ characters)<input name="password" type="password" minlength="12" required autocomplete="new-password"></label>
<label class="field">Repeat new password<input name="password2" type="password" minlength="12" required autocomplete="new-password"></label>
<button class="primary" style="margin-top:14px">Set new password</button></form>
<?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?= $h(csrf()) ?>"><label class="field">Setup key<input name="setup_key" type="password" required autocomplete="off"></label><button class="primary" style="margin-top:14px">Continue</button></form>
<?php endif; ?>
</div></body></html>
