<?php
declare(strict_types=1);
// Finance 360 V29 — restaurant self-registration.
// Flow: form (owner, restaurant, logo, first branch, terms) → account created UNVERIFIED → email link → provisioning
// → tenant ACTIVE + 30-day trial → owner dashboard.
// Provisioning is a resumable saga: tenant/branch rows are created in status 'provisioning' (cannot be entered), the
// branch ledger DB is claimed and built, and only the LAST platform transaction flips the tenant to 'active' and starts
// the trial. Any failure leaves an inactive tenant that the next attempt (same registration) continues — never a
// half-built active restaurant, never a second tenant for the same registration.
require_once __DIR__ . '/auth.php';

function reg_validate(array $in): array {
    if (!empty($in['website'])) fail('Registration could not be completed.', 400); // honeypot (bots fill hidden fields)
    $d = [
        'owner_name' => txt($in['owner_name'] ?? '', 160), 'mobile' => p_mobile($in['mobile'] ?? ''), 'email' => p_email($in['email'] ?? ''),
        'restaurant' => txt($in['restaurant'] ?? '', 160), 'contact_phone' => p_mobile($in['contact_phone'] ?? ($in['mobile'] ?? '')),
        'address' => txt($in['address'] ?? '', 300), 'city' => txt($in['city'] ?? '', 80), 'state' => txt($in['state'] ?? '', 80), 'pincode' => txt($in['pincode'] ?? '', 6),
        'branch_name' => txt($in['branch_name'] ?? '', 120) ?: 'Main', 'cutoff_hour' => (int)($in['cutoff_hour'] ?? 4),
    ];
    if (mb_strlen($d['owner_name']) < 2) fail('Owner name is required.');
    if (mb_strlen($d['restaurant']) < 2) fail('Restaurant name is required.');
    if ($d['city'] === '') fail('City is required.');
    if ($d['pincode'] !== '' && !preg_match('/^[1-9]\d{5}$/', $d['pincode'])) fail('Enter a valid 6-digit PIN code.');
    if ($d['cutoff_hour'] < 0 || $d['cutoff_hour'] > 12) fail('Business-day cut-off must be between 0 and 12 (hours after midnight).');
    if (empty($in['accept_terms'])) fail('Please accept the Terms and Privacy Policy.');
    $d['terms_version'] = '2026-11'; $d['accepted_at'] = p_utc();
    return $d;
}
/** Validated, re-encoded logo (PNG, max 512 px). Re-encoding drops metadata and any embedded payload. */
function reg_logo_png(?array $f): ?string {
    if (!$f || empty($f['data'])) return null;
    $raw = base64_decode((string)$f['data'], true); if ($raw === false || strlen($raw) > 2000000) fail('Logo must be an image under 2 MB.', 413);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw); if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) fail('Logo must be a PNG, JPG or WEBP image.');
    $im = @imagecreatefromstring($raw); if (!$im) fail('The logo image could not be read.');
    $w = imagesx($im); $h = imagesy($im); $s = min(1, 512 / max($w, $h)); $tw = max(1, (int)round($w * $s)); $th = max(1, (int)round($h * $s));
    $o = imagecreatetruecolor($tw, $th); imagealphablending($o, false); imagesavealpha($o, true); imagefill($o, 0, 0, imagecolorallocatealpha($o, 0, 0, 0, 127));
    imagecopyresampled($o, $im, 0, 0, 0, 0, $tw, $th, $w, $h); ob_start(); imagepng($o, null, 6); $png = (string)ob_get_clean(); imagedestroy($o); imagedestroy($im);
    return $png;
}

/** Step 1: create (or refresh) an unverified registration. Same answer for new and pending emails. */
function reg_start(array $in, ?array $signedIn = null): array {
    if (!p_signup_open()) fail('New restaurant registration is not open yet.', 403);
    p_rate('reg-ip:' . p_ip_hash(), 8, 3600);
    $d = reg_validate($in + ($signedIn ? ['owner_name' => $signedIn['name'], 'email' => $signedIn['email'], 'mobile' => $signedIn['mobile'] ?? ''] : []));
    $logo = reg_logo_png($in['logo'] ?? null);
    return ptx(function () use ($d, $in, $logo, $signedIn) {
        $u = prow('SELECT * FROM p_users WHERE email=? FOR UPDATE', $d['email']);
        if ($signedIn) { if (!$u || (int)$u['id'] !== (int)$signedIn['id']) fail('Signed-in account mismatch.', 403); }
        elseif ($u && $u['email_verified_at']) fail('An account with this email already exists. Sign in, then use "Add another restaurant".', 409);
        if (!$u) {
            p_password_ok((string)($in['password'] ?? ''));
            prun('INSERT INTO p_users(uid,email,name,mobile,password_hash) VALUES(?,?,?,?,?)', p_uid(), $d['email'], $d['owner_name'], $d['mobile'], password_hash((string)$in['password'], PASSWORD_DEFAULT));
            $u = prow('SELECT * FROM p_users WHERE id=?', pid());
        } elseif (!$signedIn) {
            if (!password_verify((string)($in['password'] ?? ''), $u['password_hash'])) { p_password_ok((string)($in['password'] ?? '')); prun('UPDATE p_users SET password_hash=?,name=?,mobile=? WHERE id=?', password_hash((string)$in['password'], PASSWORD_DEFAULT), $d['owner_name'], $d['mobile'], $u['id']); }
        }
        $reg = prow("SELECT * FROM p_registrations WHERE user_id=? AND status IN ('draft','verified','failed') ORDER BY id DESC LIMIT 1 FOR UPDATE", $u['id']);
        if ($reg) prun('UPDATE p_registrations SET data_json=?,status=IF(status=\'failed\',\'verified\',status),error=NULL WHERE id=?', json_encode($d), $reg['id']);
        else { prun("INSERT INTO p_registrations(uid,email,data_json,status,user_id) VALUES(?,?,?,?,?)", 'reg' . p_uid(13), $d['email'], json_encode($d), $signedIn ? 'verified' : 'draft', $u['id']); $reg = prow('SELECT * FROM p_registrations WHERE id=?', pid()); }
        if ($logo !== null) file_put_contents(p_private('reg-logos') . '/' . $reg['uid'] . '.png', $logo);
        if (!$signedIn) p_send_verification($u);
        p_audit('user', (int)$u['id'], null, 'registration.start', ['reg' => $reg['uid']]);
        return ['registration' => $reg['uid'], 'status' => $signedIn ? 'verified' : 'verify_email', 'email' => $d['email']];
    });
}
function reg_fail_point(string $where): void { if (($GLOBALS['P_FAIL_AT'] ?? '') === $where) throw new RuntimeException('Injected failure at ' . $where . ' (test)', 500); }

/** Provision the registration's tenant. Idempotent and resumable; returns the active tenant. */
function reg_provision(string $regUid): array {
    $reg = prow('SELECT * FROM p_registrations WHERE uid=?', $regUid) ?? fail('Registration not found.', 404);
    if ($reg['status'] === 'complete') return prow('SELECT * FROM p_tenants WHERE id=?', $reg['tenant_id']);
    $u = prow('SELECT * FROM p_users WHERE id=?', $reg['user_id']);
    if (!$u || !$u['email_verified_at']) fail('Verify the email address first.', 403);
    $lock = 'f360_reg_' . substr(p_sha($regUid), 0, 40);
    if ((int)pval('SELECT GET_LOCK(?,15)', $lock) !== 1) fail('This registration is already being set up. Refresh in a moment.', 409);
    try {
        $reg = prow('SELECT * FROM p_registrations WHERE uid=?', $regUid);
        if ($reg['status'] === 'complete') return prow('SELECT * FROM p_tenants WHERE id=?', $reg['tenant_id']);
        $d = json_decode((string)$reg['data_json'], true);
        prun("UPDATE p_registrations SET status='provisioning',error=NULL WHERE id=?", $reg['id']);
        try {
            $tid = $reg['tenant_id'] ? (int)$reg['tenant_id'] : ptx(function () use ($reg, $d, $u) {
                $tuid = p_uid();
                prun("INSERT INTO p_tenants(uid,name,status,owner_user_id,contact_phone,contact_email,address,city,state,pincode) VALUES(?,?,'provisioning',?,?,?,?,?,?,?)", $tuid, $d['restaurant'], $u['id'], $d['contact_phone'], $u['email'], $d['address'] ?: null, $d['city'], $d['state'] ?: null, $d['pincode'] ?: null);
                $tid = pid();
                prun("INSERT INTO p_tenant_members(tenant_id,user_id,role) VALUES(?,?,'owner')", $tid, $u['id']);
                $buid = p_uid();
                prun("INSERT INTO p_branches(uid,tenant_id,name,address,city,cutoff_hour,status,is_primary,storage) VALUES(?,?,?,?,?,?,'provisioning',1,?)", $buid, $tid, $d['branch_name'], $d['address'] ?: null, $d['city'], $d['cutoff_hour'], 'tenants/' . $tuid . '/' . $buid);
                prun('UPDATE p_registrations SET tenant_id=? WHERE id=?', $tid, $reg['id']);
                return $tid;
            });
            reg_fail_point('after_tenant');
            $branch = prow('SELECT * FROM p_branches WHERE tenant_id=? AND is_primary=1', $tid);
            prov_claim_db((int)$branch['id']); reg_fail_point('after_claim');
            $branch = prow('SELECT * FROM p_branches WHERE id=?', $branch['id']);
            prov_install_branch($branch, $d['restaurant'], (int)$d['cutoff_hour']); reg_fail_point('after_install');
            $local = prov_local_user($branch, $u, 'owner');
            ptx(function () use ($tid, $branch, $u, $local, $reg) {
                $t = prow('SELECT * FROM p_tenants WHERE id=? FOR UPDATE', $tid);
                prun("INSERT INTO p_branch_members(tenant_id,branch_id,user_id,local_user_id,template) VALUES(?,?,?,?,'owner') ON DUPLICATE KEY UPDATE local_user_id=VALUES(local_user_id),status='active'", $tid, $branch['id'], $u['id'], $local);
                prun("UPDATE p_branches SET status='active' WHERE id=?", $branch['id']);
                $trial = billing_start_trial($tid, (int)$u['id']);
                if (!$trial) prun("INSERT INTO p_subscriptions(tenant_id,plan_version_id,kind,status) VALUES(?,?,'trial','trialing') ON DUPLICATE KEY UPDATE id=id", $tid, billing_trial_plan_version());
                prun("UPDATE p_tenants SET status='active',activated_at=UTC_TIMESTAMP() WHERE id=? AND status='provisioning'", $tid);
                prun("UPDATE p_registrations SET status='complete',error=NULL WHERE id=?", $reg['id']);
                p_audit('user', (int)$u['id'], $tid, 'tenant.activated', ['trial' => $trial, 'branch' => (int)$branch['id']]);
                reg_fail_point('final_tx');
            });
            $logo = p_private('reg-logos') . '/' . $reg['uid'] . '.png'; $t = prow('SELECT * FROM p_tenants WHERE id=?', $tid);
            if (is_file($logo)) { @rename($logo, p_private('logos') . '/' . $t['uid'] . '.png'); prun('UPDATE p_tenants SET logo_path=? WHERE id=?', 'logos/' . $t['uid'] . '.png', $tid); }
            p_queue('welcome', 'welcome:' . $tid, 'email', $u['email'], 'Your restaurant is ready on Finance 360', '<p>' . pe($d['restaurant']) . ' is set up. ' . pe(($e = billing_entitlement($tid))['kind'] === 'trial' && $e['period_end'] ? 'Your free trial ends ' . p_ist($e['period_end']) . '. No payment is taken during the trial.' : 'Choose a plan in Owner portal → Billing.') . '</p>', $tid);
            return prow('SELECT * FROM p_tenants WHERE id=?', $tid);
        } catch (Throwable $e) {
            prun("UPDATE p_registrations SET status='failed',error=? WHERE id=?", mb_substr($e->getMessage(), 0, 300), $reg['id']);
            p_audit('system', null, $reg['tenant_id'] ? (int)$reg['tenant_id'] : null, 'registration.failed', ['reg' => $regUid, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            throw $e;
        }
    } finally { pval('SELECT RELEASE_LOCK(?)', $lock); }
}
