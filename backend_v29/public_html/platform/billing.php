<?php
declare(strict_types=1);
// Finance 360 V29 — trials, plans, subscriptions, orders, payment webhooks.
//
// STATE MACHINE (docs/SUBSCRIPTIONS.md). Access is derived from time-stamped periods, never from a client claim:
//   end = MAX(ends_at) of non-revoked rows in p_sub_periods.
//   suspended (admin)                         → 'suspended'  read-only
//   now < end                                 → 'trialing' (trial) / 'active' (paid, complimentary)
//   paid & now < end + grace_days             → 'grace'      full access + renewal banner
//   otherwise                                 → 'expired' (or 'cancelled' if the owner cancelled)   read-only
// Read-only = sign in, view, export, billing & renewal allowed; every data-changing request is refused with HTTP 402.
// Nothing is ever deleted on expiry.
// Payments: an order is created on the server for a specific tenant + plan version + amount. Access is credited ONLY
// by a verified provider webhook (HMAC signature) or by an audited offline payment entry by a billing admin.
// p_sub_periods.order_id is UNIQUE → a repeated / replayed webhook can never credit the same order twice.
require_once __DIR__ . '/core.php';

const P_TERMS = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];
const P_STATE_LABELS = ['trialing' => 'Free trial', 'active' => 'Active', 'grace' => 'Payment due (grace period)', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'suspended' => 'Suspended', 'none' => 'No plan'];

function billing_cache_clear(): void { $GLOBALS['P_ENT'] = []; }
function billing_period_end(int $tenantId): ?string { return pval('SELECT MAX(ends_at) FROM p_sub_periods WHERE tenant_id=? AND revoked_at IS NULL', $tenantId); }
function billing_entitlement(int $tenantId): array {
    $c = &$GLOBALS['P_ENT']; $c ??= [];
    if (isset($c[$tenantId])) return $c[$tenantId];
    $s = prow('SELECT * FROM p_subscriptions WHERE tenant_id=?', $tenantId);
    $t = prow('SELECT status FROM p_tenants WHERE id=?', $tenantId);
    $pv = $s && $s['plan_version_id'] ? prow('SELECT v.*,p.name plan_name,p.code plan_code FROM p_plan_versions v JOIN p_plans p ON p.id=v.plan_id WHERE v.id=?', (int)$s['plan_version_id']) : null;
    $end = billing_period_end($tenantId); $now = p_now()->getTimestamp(); $endTs = $end ? p_ts($end) : 0;
    $grace = $pv ? (int)$pv['grace_days'] : (int)p_setting('grace_days');
    if (!$s) $state = 'none';
    elseif ($s['status'] === 'suspended' || ($t['status'] ?? '') === 'suspended') $state = 'suspended';
    elseif (!$end) $state = 'none'; // never had a trial or paid period (e.g. owner's 2nd restaurant): choose a plan
    elseif ($endTs > $now) $state = $s['kind'] === 'trial' ? 'trialing' : 'active';
    elseif ($s['kind'] === 'paid' && $endTs + $grace * 86400 > $now && $s['status'] !== 'cancelled') $state = 'grace';
    else $state = $s['status'] === 'cancelled' ? 'cancelled' : 'expired';
    $ro = in_array($state, ['expired', 'cancelled', 'suspended', 'none'], true) && p_setting('readonly_on_expiry') !== '0';
    if ($state === 'suspended' || $state === 'none') $ro = true;
    $mods = $pv ? (json_decode((string)$pv['modules_json'], true) ?: []) : P_ALL_MODULES;
    return $c[$tenantId] = ['state' => $state, 'state_label' => P_STATE_LABELS[$state], 'read_only' => $ro, 'kind' => $s['kind'] ?? null,
        'period_end' => $end, 'grace_until' => $end && $state === 'grace' ? p_utc((new DateTimeImmutable($end, new DateTimeZone('UTC')))->modify('+' . $grace . ' days')) : null,
        'days_left' => $end ? (int)max(0, ceil(($endTs - $now) / 86400)) : 0, 'plan' => $pv['plan_name'] ?? null, 'plan_code' => $pv['plan_code'] ?? null, 'term' => $pv['term'] ?? null,
        'plan_version_id' => $pv ? (int)$pv['id'] : null, 'max_branches' => $pv ? (int)$pv['max_branches'] : 1, 'max_users' => $pv ? (int)$pv['max_users'] : 5, 'modules' => $mods,
        'cancel_at_period_end' => (bool)($s['cancel_at_period_end'] ?? false), 'suspended_reason' => $s['suspended_reason'] ?? null];
}
function billing_public_state(int $tenantId): array {
    $e = billing_entitlement($tenantId);
    return ['state' => $e['state'], 'label' => $e['state_label'], 'read_only' => $e['read_only'], 'days_left' => $e['days_left'], 'period_end' => $e['period_end'],
            'period_end_ist' => p_ist($e['period_end']), 'plan' => $e['plan'], 'term' => $e['term'], 'kind' => $e['kind']];
}

/* ------------------------------ trials ------------------------------ */
/** End of a trial: the sign-up day plus N full calendar days, ending 23:59:59 Asia/Kolkata. */
function billing_trial_end(DateTimeImmutable $startUtc, int $days): DateTimeImmutable {
    $ist = $startUtc->setTimezone(new DateTimeZone(P_TZ));
    return (new DateTimeImmutable($ist->format('Y-m-d') . ' 23:59:59', new DateTimeZone(P_TZ)))->modify('+' . $days . ' days')->setTimezone(new DateTimeZone('UTC'));
}
function billing_trial_plan_version(): int {
    $v = pval("SELECT v.id FROM p_plan_versions v JOIN p_plans p ON p.id=v.plan_id WHERE p.code='standard' ORDER BY v.active DESC, v.months, v.version DESC LIMIT 1");
    if (!$v) fail('Plans are not configured.', 503); return (int)$v;
}
/** Automatic sign-up trial: once per owner account (auto_key), whatever device or app reinstall. Returns false if not eligible. */
function billing_start_trial(int $tenantId, int $ownerUserId): bool {
    $days = max(1, min(90, (int)p_setting('trial_days'))); $start = p_now(); $end = billing_trial_end($start, $days);
    $pv = billing_trial_plan_version();
    prun("INSERT INTO p_subscriptions(tenant_id,plan_version_id,kind,status) VALUES(?,?,'trial','trialing') ON DUPLICATE KEY UPDATE id=id", $tenantId, $pv);
    $ok = prun('INSERT IGNORE INTO p_trial_grants(tenant_id,owner_user_id,auto_key,days,starts_at,ends_at) VALUES(?,?,?,?,?,?)', $tenantId, $ownerUserId, 'owner:' . $ownerUserId, $days, p_utc($start), p_utc($end))->rowCount() > 0;
    if ($ok) prun("INSERT INTO p_sub_periods(tenant_id,source,plan_version_id,starts_at,ends_at,reason) VALUES(?,'trial',?,?,?,'Automatic sign-up trial')", $tenantId, $pv, p_utc($start), p_utc($end));
    billing_cache_clear(); return $ok;
}
/** Contiguous extension starting at max(now, current end). Used by admin trial extensions and complimentary grants. */
function billing_admin_extend(int $tenantId, int $days, int $adminId, string $reason, string $source = 'admin'): array {
    if ($days < 1 || $days > 400) fail('Days must be between 1 and 400.');
    $reason = txt($reason, 300); if (mb_strlen($reason) < 5) fail('A reason is required.');
    return ptx(function () use ($tenantId, $days, $adminId, $reason, $source) {
        prow('SELECT id FROM p_subscriptions WHERE tenant_id=? FOR UPDATE', $tenantId) ?? fail('Restaurant has no subscription.', 404);
        $end = billing_period_end($tenantId); $start = $end && p_ts($end) > p_now()->getTimestamp() ? new DateTimeImmutable($end, new DateTimeZone('UTC')) : p_now();
        $new = $start->modify('+' . $days . ' days');
        prun('INSERT INTO p_sub_periods(tenant_id,source,starts_at,ends_at,admin_id,reason) VALUES(?,?,?,?,?,?)', $tenantId, $source, p_utc($start), p_utc($new), $adminId, $reason);
        if ($source === 'admin') prun('INSERT INTO p_trial_grants(tenant_id,owner_user_id,auto_key,days,starts_at,ends_at,admin_id,reason) SELECT id,owner_user_id,NULL,?,?,?,?,? FROM p_tenants WHERE id=?', $days, p_utc($start), p_utc($new), $adminId, $reason, $tenantId);
        p_audit('admin', $adminId, $tenantId, 'subscription.extend', ['days' => $days, 'reason' => $reason, 'until' => p_utc($new)]);
        billing_cache_clear(); return billing_entitlement($tenantId);
    });
}
function billing_set_suspended(int $tenantId, bool $suspend, int $adminId, string $reason): void {
    $reason = txt($reason, 300); if (mb_strlen($reason) < 5) fail('A reason is required.');
    ptx(function () use ($tenantId, $suspend, $adminId, $reason) {
        $s = prow('SELECT * FROM p_subscriptions WHERE tenant_id=? FOR UPDATE', $tenantId) ?? fail('No subscription.', 404);
        if ($suspend) prun("UPDATE p_subscriptions SET status='suspended',suspended_reason=? WHERE id=?", $reason, $s['id']);
        else prun("UPDATE p_subscriptions SET status=IF(kind='trial','trialing','active'),suspended_reason=NULL WHERE id=?", $s['id']);
        p_audit('admin', $adminId, $tenantId, $suspend ? 'subscription.suspend' : 'subscription.reactivate', ['reason' => $reason]);
    });
    billing_cache_clear();
}

/* ------------------------------ plans ------------------------------ */
function billing_plans(bool $publicOnly = true): array {
    $o = [];
    foreach (prows('SELECT * FROM p_plans WHERE active=1' . ($publicOnly ? ' AND public=1' : '') . ' ORDER BY sort') as $p) {
        $vs = prows("SELECT v.* FROM p_plan_versions v WHERE v.plan_id=? AND v.active=1 AND v.effective_from<=UTC_TIMESTAMP() AND v.version=(SELECT MAX(x.version) FROM p_plan_versions x WHERE x.plan_id=v.plan_id AND x.term=v.term AND x.active=1) ORDER BY v.months", $p['id']);
        $o[] = ['code' => $p['code'], 'name' => $p['name'], 'description' => $p['description'], 'terms' => array_map(fn($v) => ['plan_version_id' => (int)$v['id'], 'term' => $v['term'], 'months' => (int)$v['months'],
            'price_paise' => (int)$v['price_paise'], 'currency' => $v['currency'], 'max_branches' => (int)$v['max_branches'], 'max_users' => (int)$v['max_users'], 'modules' => json_decode((string)$v['modules_json'], true)], $vs)];
    }
    return $o;
}
/** A price change never edits history: it creates a NEW version; existing subscriptions keep the version they bought. */
function billing_plan_version_save(int $planId, string $term, array $in, int $adminId): int {
    if (!isset(P_TERMS[$term])) fail('Choose a valid term.');
    $price = (int)($in['price_paise'] ?? -1); if ($price < 100) fail('Price must be at least ₹1.');
    $mods = []; foreach (P_ALL_MODULES as $k => $_) $mods[$k] = !empty($in['modules'][$k]);
    $mb = max(1, min(100, (int)($in['max_branches'] ?? 1))); $mu = max(1, min(1000, (int)($in['max_users'] ?? 5))); $g = max(0, min(30, (int)($in['grace_days'] ?? 3)));
    return ptx(function () use ($planId, $term, $price, $mods, $mb, $mu, $g, $adminId) {
        $v = (int)pval('SELECT COALESCE(MAX(version),0) FROM p_plan_versions WHERE plan_id=? AND term=?', $planId, $term) + 1;
        prun("UPDATE p_plan_versions SET active=0 WHERE plan_id=? AND term=?", $planId, $term);
        prun("INSERT INTO p_plan_versions(plan_id,version,term,months,price_paise,currency,max_branches,max_users,modules_json,grace_days,effective_from,active) VALUES(?,?,?,?,?,'INR',?,?,?,?,UTC_TIMESTAMP(),1)", $planId, $v, $term, P_TERMS[$term], $price, $mb, $mu, json_encode($mods), $g);
        $id = pid(); p_audit('admin', $adminId, null, 'plan.version', ['plan' => $planId, 'term' => $term, 'version' => $v, 'price' => $price]); return $id;
    });
}

/* ------------------------------ gateway configuration ------------------------------ */
function billing_gw(): array { $g = PHP_SAPI === 'cli' && isset($GLOBALS['P_BILLING_OVERRIDE']) ? (array)$GLOBALS['P_BILLING_OVERRIDE'] : (array)(pcfg()['billing'] ?? []); /* override = CLI test seam only */ $g['mode'] = (string)($g['mode'] ?? 'disabled'); return $g; }
/** 'disabled' (default) | 'test' (rzp_test_ keys) | 'live' (rzp_live_ keys AND live_approved=true set by the owner). */
function billing_gw_ready(): ?string {
    $g = billing_gw();
    if ($g['mode'] === 'disabled') return 'Online payment is not activated yet. Contact support to renew.';
    if (empty($g['key_id']) || empty($g['key_secret']) || empty($g['webhook_secret'])) return 'Payment gateway keys are not configured.';
    if ($g['mode'] === 'test' && !str_starts_with((string)$g['key_id'], 'rzp_test_')) return 'Test mode needs Razorpay TEST keys.';
    if ($g['mode'] === 'live' && (!str_starts_with((string)$g['key_id'], 'rzp_live_') || ($g['live_approved'] ?? false) !== true)) return 'Live payments need owner approval (billing.live_approved).';
    if (!in_array($g['mode'], ['test', 'live'], true)) return 'Unknown gateway mode.';
    return null;
}

/* ------------------------------ orders & crediting ------------------------------ */
function billing_create_order(int $tenantId, int $planVersionId, int $userId): array {
    $why = billing_gw_ready(); if ($why) fail($why, 503);
    $pv = prow("SELECT v.*,p.public,p.active pactive FROM p_plan_versions v JOIN p_plans p ON p.id=v.plan_id WHERE v.id=? AND v.active=1", $planVersionId);
    if (!$pv || !$pv['public'] || !$pv['pactive'] || (int)$pv['price_paise'] < 100) fail('This plan is not available for purchase.', 400);
    $role = tenancy_member_role($userId, $tenantId); if (!in_array($role, ['owner', 'admin'], true)) fail('Only the restaurant owner can buy a plan.', 403);
    $open = prow("SELECT * FROM p_orders WHERE tenant_id=? AND plan_version_id=? AND status='created' AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) ORDER BY id DESC LIMIT 1", $tenantId, $planVersionId);
    if ($open && $open['provider_order_id']) return billing_checkout_params($open);
    $uid = 'ord' . p_uid(13); $g = billing_gw();
    prun("INSERT INTO p_orders(uid,tenant_id,plan_version_id,amount_paise,currency,months,status,provider,created_by,gateway_mode) VALUES(?,?,?,?,?,?,'created','razorpay',?,?)", $uid, $tenantId, $pv['id'], $pv['price_paise'], $pv['currency'], $pv['months'], $userId, $g['mode']);
    $id = pid(); $t = prow('SELECT uid FROM p_tenants WHERE id=?', $tenantId);
    if (!empty($g['offline_test']) && $g['mode'] === 'test') $poid = 'order_TEST' . strtoupper(p_uid(10)); // staging: no network call to Razorpay
    else {
        $r = billing_http('POST', 'https://api.razorpay.com/v1/orders', ['amount' => (int)$pv['price_paise'], 'currency' => $pv['currency'], 'receipt' => $uid, 'notes' => ['tenant' => $t['uid'], 'order' => $uid]]);
        if ($r['code'] !== 200 || empty($r['json']['id'])) { prun("UPDATE p_orders SET status='failed',note=? WHERE id=?", 'Gateway order failed (HTTP ' . $r['code'] . ')', $id); fail('Could not start the payment. Please try again.', 502); }
        if ((int)$r['json']['amount'] !== (int)$pv['price_paise']) fail('Gateway amount mismatch.', 502);
        $poid = (string)$r['json']['id'];
    }
    prun('UPDATE p_orders SET provider_order_id=? WHERE id=?', $poid, $id);
    p_audit('user', $userId, $tenantId, 'order.create', ['order' => $uid, 'amount' => (int)$pv['price_paise'], 'plan_version' => (int)$pv['id']]);
    return billing_checkout_params(prow('SELECT * FROM p_orders WHERE id=?', $id));
}
function billing_checkout_params(array $o): array {
    return ['order_uid' => $o['uid'], 'provider' => 'razorpay', 'key_id' => (string)(billing_gw()['key_id'] ?? ''), 'provider_order_id' => $o['provider_order_id'],
            'amount_paise' => (int)$o['amount_paise'], 'currency' => $o['currency'], 'note' => 'Access is activated only after the payment is confirmed by the payment provider (usually within a minute).'];
}
function billing_http(string $method, string $url, ?array $body): array {
    $g = billing_gw(); $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERPWD => $g['key_id'] . ':' . $g['key_secret'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'json' => is_string($raw) ? (json_decode($raw, true) ?: []) : []];
}
function billing_add_months(DateTimeImmutable $d, int $m): DateTimeImmutable {
    $ist = $d->setTimezone(new DateTimeZone(P_TZ)); $day = (int)$ist->format('j');
    $first = $ist->modify('first day of this month')->modify('+' . $m . ' months'); $last = (int)$first->format('t');
    return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($day, $last))->setTimezone(new DateTimeZone('UTC'));
}
/** Credit one paid order exactly once. New period = [max(now, current end), +months). Paying during grace continues from the old end (no gap, no overlap). */
function billing_credit_order(int $orderId, string $paymentId, string $via, ?int $adminId = null): array {
    return ptx(function () use ($orderId, $paymentId, $via, $adminId) {
        $o = prow('SELECT * FROM p_orders WHERE id=? FOR UPDATE', $orderId) ?? fail('Order not found.', 404);
        prow('SELECT id FROM p_subscriptions WHERE tenant_id=? FOR UPDATE', (int)$o['tenant_id']);
        $have = prow('SELECT * FROM p_sub_periods WHERE order_id=?', $orderId);
        if ($have) return ['credited' => false, 'duplicate' => true, 'ends_at' => $have['ends_at']];
        if (!in_array($o['status'], ['created', 'review', 'failed'], true)) return ['credited' => false, 'duplicate' => true, 'status' => $o['status']];
        $end = billing_period_end((int)$o['tenant_id']); $now = p_now();
        $pv = prow('SELECT * FROM p_plan_versions WHERE id=?', (int)$o['plan_version_id']);
        $start = $now;
        if ($end) { $e = new DateTimeImmutable($end, new DateTimeZone('UTC')); $graceEnd = $e->modify('+' . (int)$pv['grace_days'] . ' days');
            $sub = prow('SELECT kind FROM p_subscriptions WHERE tenant_id=?', (int)$o['tenant_id']);
            if ($e > $now) $start = $e; elseif (($sub['kind'] ?? '') === 'paid' && $graceEnd > $now) $start = $e; }
        $new = billing_add_months($start, (int)$o['months']);
        prun("INSERT INTO p_sub_periods(tenant_id,source,order_id,plan_version_id,starts_at,ends_at,admin_id,reason) VALUES(?,'payment',?,?,?,?,?,?)", $o['tenant_id'], $orderId, $o['plan_version_id'], p_utc($start), p_utc($new), $adminId, $via);
        prun("INSERT INTO p_subscriptions(tenant_id,plan_version_id,kind,status) VALUES(?,?,'paid','active') ON DUPLICATE KEY UPDATE plan_version_id=VALUES(plan_version_id),kind='paid',status=IF(status='suspended','suspended','active'),cancel_at_period_end=0", $o['tenant_id'], $o['plan_version_id']);
        $rno = 'F360-' . $now->format('Y') . '-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
        prun("UPDATE p_orders SET status='paid',provider_payment_id=?,paid_at=UTC_TIMESTAMP(),receipt_no=? WHERE id=?", $paymentId, $rno, $orderId);
        p_audit($adminId ? 'admin' : 'webhook', $adminId, (int)$o['tenant_id'], 'subscription.credit', ['order' => $o['uid'], 'via' => $via, 'from' => p_utc($start), 'to' => p_utc($new)]);
        $owner = prow('SELECT u.email,t.name FROM p_tenants t JOIN p_users u ON u.id=t.owner_user_id WHERE t.id=?', (int)$o['tenant_id']);
        if ($owner) p_queue('receipt', 'receipt:' . $orderId, 'email', $owner['email'], 'Payment received — receipt ' . $rno, '<p>Thank you. ' . pe($owner['name']) . ' is active until ' . pe(p_ist(p_utc($new), 'j M Y')) . '.</p><p>Amount: ' . pe(p_rs((int)$o['amount_paise'])) . ' · Receipt ' . pe($rno) . '</p>', (int)$o['tenant_id']);
        billing_cache_clear(); return ['credited' => true, 'from' => p_utc($start), 'ends_at' => p_utc($new), 'receipt_no' => $rno];
    });
}
/** Offline payment (bank transfer / cash) recorded by a billing admin. Same crediting path → same idempotency. */
function billing_admin_offline_payment(int $tenantId, int $planVersionId, string $reference, int $adminId): array {
    $reference = txt($reference, 80); if (mb_strlen($reference) < 4) fail('Payment reference is required.');
    $pv = prow('SELECT * FROM p_plan_versions WHERE id=?', $planVersionId) ?? fail('Plan version not found.', 404);
    $key = 'offline:' . $tenantId . ':' . $reference;
    $ex = prow('SELECT id FROM p_orders WHERE provider_order_id=?', $key);
    if (!$ex) { prun("INSERT INTO p_orders(uid,tenant_id,plan_version_id,amount_paise,currency,months,status,provider,provider_order_id,created_by,gateway_mode,note) VALUES(?,?,?,?,?,?,'created','offline',?,NULL,'offline',?)",
        'ord' . p_uid(13), $tenantId, $planVersionId, $pv['price_paise'], $pv['currency'], $pv['months'], $key, 'Recorded by admin #' . $adminId); $oid = pid(); }
    else $oid = (int)$ex['id'];
    return billing_credit_order($oid, $key, 'offline payment ' . $reference, $adminId);
}

/* ------------------------------ webhooks (Razorpay) ------------------------------ */
function billing_sig_ok(string $raw, string $sig): bool { $s = (string)(billing_gw()['webhook_secret'] ?? ''); return $s !== '' && $sig !== '' && hash_equals(hash_hmac('sha256', $raw, $s), $sig); }
/** Returns [http status, message]. Signature first; then de-duplicate by provider event id; then process inside one transaction. */
function billing_webhook(string $raw, string $sig, string $eventId): array {
    if (billing_gw()['mode'] === 'disabled') return [503, 'payments disabled'];
    $ok = billing_sig_ok($raw, $sig); $sha = p_sha($raw); $eventId = $eventId !== '' ? mb_substr($eventId, 0, 100) : 'sha:' . $sha;
    $j = json_decode($raw, true); $type = is_array($j) ? (string)($j['event'] ?? '') : '';
    if (!$ok) { prun("INSERT IGNORE INTO p_webhook_events(provider,event_id,event_type,signature_ok,payload_sha,status,message) VALUES('razorpay',?,?,0,?,'rejected','bad signature')", 'rej:' . $sha, mb_substr($type, 0, 60), $sha); return [400, 'invalid signature']; }
    if (!is_array($j)) return [400, 'invalid json'];
    $ins = prun("INSERT IGNORE INTO p_webhook_events(provider,event_id,event_type,signature_ok,payload_sha,payload,status) VALUES('razorpay',?,?,1,?,?,'received')", $eventId, mb_substr($type, 0, 60), $sha, $raw)->rowCount();
    if (!$ins) return [200, 'duplicate'];
    try { [$st, $msg] = billing_webhook_process($type, $j); }
    catch (Throwable $e) { $st = 'error'; $msg = mb_substr($e->getMessage(), 0, 250); }
    prun('UPDATE p_webhook_events SET status=?,message=?,processed_at=UTC_TIMESTAMP() WHERE provider=\'razorpay\' AND event_id=?', $st, $msg, $eventId);
    return [200, $st];
}
function billing_webhook_process(string $type, array $j): array {
    $pay = $j['payload']['payment']['entity'] ?? null; $ord = $j['payload']['order']['entity'] ?? null; $ref = $j['payload']['refund']['entity'] ?? null;
    $poid = (string)($pay['order_id'] ?? $ord['id'] ?? '');
    switch ($type) {
        case 'payment.captured': case 'order.paid':
            $o = $poid !== '' ? prow("SELECT * FROM p_orders WHERE provider='razorpay' AND provider_order_id=?", $poid) : null;
            if (!$o) return ['ignored', 'unknown order'];
            $amt = (int)($pay['amount'] ?? $ord['amount_paid'] ?? 0); $cur = (string)($pay['currency'] ?? $ord['currency'] ?? '');
            if ($amt !== (int)$o['amount_paise'] || $cur !== $o['currency']) { prun("UPDATE p_orders SET status='review',note='Amount/currency mismatch from gateway' WHERE id=? AND status<>'paid'", $o['id']); p_audit('webhook', null, (int)$o['tenant_id'], 'order.mismatch', ['order' => $o['uid'], 'amount' => $amt, 'currency' => $cur]); return ['error', 'amount mismatch'];}
            $pid = (string)($pay['id'] ?? ('order:' . $poid));
            prun("INSERT INTO p_payments(order_id,provider_payment_id,amount_paise,currency,status,method) VALUES(?,?,?,?, 'captured', ?) ON DUPLICATE KEY UPDATE status='captured',updated_at=UTC_TIMESTAMP()", $o['id'], $pid, $amt, $cur, mb_substr((string)($pay['method'] ?? ''), 0, 30) ?: null);
            $r = billing_credit_order((int)$o['id'], $pid, 'webhook ' . $type);
            return ['processed', $r['credited'] ? 'credited until ' . $r['ends_at'] : 'already credited'];
        case 'payment.failed':
            $o = $poid !== '' ? prow("SELECT * FROM p_orders WHERE provider='razorpay' AND provider_order_id=?", $poid) : null; if (!$o) return ['ignored', 'unknown order'];
            if ($pay && !empty($pay['id'])) prun("INSERT INTO p_payments(order_id,provider_payment_id,amount_paise,currency,status) VALUES(?,?,?,?,'failed') ON DUPLICATE KEY UPDATE status=IF(status='captured','captured','failed')", $o['id'], (string)$pay['id'], (int)($pay['amount'] ?? 0), (string)($pay['currency'] ?? 'INR'));
            // A failure never downgrades a paid order (events can arrive out of order); the owner may simply retry.
            return ['processed', $o['status'] === 'paid' ? 'late failure ignored (order already paid)' : 'payment failed; order stays open for retry'];
        case 'refund.processed': case 'refund.created':
            $p = $ref ? prow('SELECT * FROM p_payments WHERE provider_payment_id=?', (string)($ref['payment_id'] ?? '')) : null; if (!$p) return ['ignored', 'unknown payment'];
            return ptx(function () use ($p, $ref) {
                $o = prow('SELECT * FROM p_orders WHERE id=? FOR UPDATE', (int)$p['order_id']);
                $total = (int)$p['refunded_paise'] + (int)($ref['amount'] ?? 0); prun('UPDATE p_payments SET refunded_paise=?,status=? WHERE id=?', $total, $total >= (int)$p['amount_paise'] ? 'refunded' : 'partial_refund', $p['id']);
                if ($total >= (int)$p['amount_paise']) { prun("UPDATE p_orders SET status='refunded' WHERE id=?", $o['id']); prun('UPDATE p_sub_periods SET revoked_at=UTC_TIMESTAMP(),reason=CONCAT(reason,\' · refunded\') WHERE order_id=? AND revoked_at IS NULL', $o['id']); }
                else prun("UPDATE p_orders SET note='Partial refund — billing admin review' WHERE id=?", $o['id']);
                p_audit('webhook', null, (int)$o['tenant_id'], 'order.refund', ['order' => $o['uid'], 'refunded' => $total]); billing_cache_clear();
                return ['processed', $total >= (int)$p['amount_paise'] ? 'full refund: period revoked' : 'partial refund recorded for review'];
            });
        case 'payment.dispute.created':
            $p = $pay ? prow('SELECT * FROM p_payments WHERE provider_payment_id=?', (string)($pay['id'] ?? '')) : null;
            if ($p) { prun("UPDATE p_orders SET status='review',note='Chargeback / dispute opened' WHERE id=?", $p['order_id']); p_audit('webhook', null, (int)pval('SELECT tenant_id FROM p_orders WHERE id=?', $p['order_id']), 'order.dispute', ['payment' => $p['provider_payment_id']]); }
            return ['processed', 'dispute flagged for billing admin'];
    }
    return ['ignored', 'event not used'];
}

/* ------------------------------ reminders (cron) ------------------------------ */
/** Trial / renewal reminders at 7, 3, 1 days, due day and expiry. Dedupe key includes the period end, so a renewal re-arms them. */
function billing_reminders(): int {
    $n = 0;
    foreach (prows("SELECT t.id,t.name,u.email FROM p_tenants t JOIN p_users u ON u.id=t.owner_user_id WHERE t.status='active'") as $t) {
        $e = billing_entitlement((int)$t['id']); if (!$e['period_end']) continue;
        $left = $e['days_left']; $kind = null;
        if (in_array($e['state'], ['trialing', 'active'], true) && in_array($left, [7, 3, 1], true)) $kind = $left . 'd';
        elseif (in_array($e['state'], ['trialing', 'active'], true) && $left === 0) $kind = 'today';
        elseif (in_array($e['state'], ['expired', 'grace'], true)) $kind = $e['state'];
        if (!$kind || $e['cancel_at_period_end']) continue;
        $what = $e['kind'] === 'trial' ? 'free trial' : 'subscription';
        $subj = match ($kind) { 'expired' => "Your Finance 360 $what has ended — your records are safe", 'grace' => 'Payment due — please renew Finance 360', 'today' => "Your Finance 360 $what ends today", default => "Your Finance 360 $what ends in $left day" . ($left > 1 ? 's' : '') };
        $body = '<p>' . pe($t['name']) . ': ' . pe($subj) . '. End: ' . pe(p_ist($e['period_end'])) . '.</p><p>Your data is never deleted. Renew from Owner portal → Billing.</p>';
        if (p_queue('sub_reminder', 'subrem:' . $t['id'] . ':' . $e['period_end'] . ':' . $kind, 'email', $t['email'], $subj, $body, (int)$t['id'])) $n++;
    }
    return $n;
}
