<?php
declare(strict_types=1);
// Finance 360 V29 — ONE dispatcher for every client. The browser session API (f360-api.php) and the Flutter REST API
// (api/v1) both call f3_dispatch(), so routes, validation, permissions (f3_need), idempotency (client_key), day locks
// and the subscription write gate are literally the same code for web and mobile.
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/purchase.php';
require_once __DIR__ . '/control.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/reconcile.php';
require_once __DIR__ . '/overview.php';
require_once __DIR__ . '/emp_import.php';
require_once __DIR__ . '/v23.php';
require_once __DIR__ . '/v29.php';
require_once __DIR__ . '/v31.php';

function f3_routes(): array {
    $GET = ['me', 'dashboard', 'assist', 'closing', 'sales-history', 'expenses', 'expense-categories', 'accounts', 'book', 'gl', 'gl-pending', 'control', 'suppliers', 'supplier', 'stock', 'items', 'item', 'item-ledger', 'stock-moves', 'stock-valuation', 'stock-count', 'stock-counts', 'bills', 'bill', 'aging', 'purchase-register', 'thumb', 'units', 'departments', 'staff', 'staff-get', 'staff-txns',
            'payroll-months', 'payroll', 'payroll-line', 'payroll-sheet', 'payslip', 'report-daily', 'report-monthly', 'report-staff', 'export', 'notifications', 'users', 'settings', 'audit', 'file', 'corrections', 'pending-closings', 'integrations', 'overview', 'scheduler', 'reconcile', 'emp-import', 'salary-history',
            'handovers', 'search', 'price-history', 'price-alerts', 'docs', 'reorder', 'pay-acks', 'month-end', 'owner-digest', 'exceptions'];
    $POST = ['closing-save', 'closing-finalize', 'closing-review', 'closing-reopen', 'correction-request', 'correction-decide', 'expense-save', 'expense-void', 'expense-review', 'transfer-save', 'supplier-save', 'supplier-active', 'supplier-pay', 'supplier-allocate', 'supplier-pay-void', 'supplier-refund', 'return-save',
             'item-save', 'item-active', 'item-opening', 'stock-move', 'stock-consume', 'stock-reverse', 'count-start', 'count-save', 'count-approve', 'file-rotate', 'gl-sync', 'gl-decide', 'control-save',
             'bill-save', 'bill-attach', 'bill-discard', 'bill-submit', 'bill-approve', 'bill-receive', 'bill-reverse', 'bill-copy',
             'designation-save', 'staff-bulk-join', 'staff-joining-fix', 'staff-save', 'staff-exit', 'staff-txn', 'staff-txn-decide', 'staff-txn-settle', 'staff-txn-reverse', 'payroll-open', 'payroll-line', 'payroll-submit', 'payroll-approve', 'payroll-lock', 'payroll-unlock',
             'payroll-pay', 'payroll-pay-bulk', 'payroll-pay-void', 'snooze', 'user-save', 'user-password', 'settings-save',
             'int-drive-save', 'int-drive-connect', 'int-drive-disconnect', 'int-drive-test', 'int-drive-retry', 'int-drive-resync', 'int-run', 'int-backup-now', 'int-backup-verify', 'int-wa-save', 'int-wa-recipient', 'int-wa-test', 'int-wa-confirm', 'int-wa-retry', 'int-wa-cancel', 'emp-import-upload', 'emp-import-decide', 'emp-import-apply', 'emp-import-rollback',
             'handover-send', 'handover-respond', 'handover-cancel', 'doc-upload', 'doc-act', 'pay-ack', 'pay-ack-resolve', 'exc-scan', 'exc-act'];
    $GET = array_merge($GET, F29_GET, F31_GET); $POST = array_merge($POST, F29_POST, F31_POST);
    return ['GET' => $GET, 'POST' => $POST];
}
/** Runs one route for an authenticated local user. Always ends with send() (or a file stream) — never returns. */
/** $idemAll (API v1): every POST that carries a client_key / Idempotency-Key is replay-safe — the first response is stored in
 *  dd_request_keys and returned again for the same key + payload; the same key with a different payload is refused (409). */
function f3_dispatch(string $method, string $route, array $q, array $b, array $u, bool $idemAll = false): never {
    if ($idemAll && $method === 'POST' && !empty($b['client_key']) && !str_starts_with($route, 'int-') && !str_starts_with($route, 'emp-import-')) {
        $GLOBALS['F3_IDEM_WRAP'] = true;
        send(transaction(fn() => idem('api:' . $route, $b, $u, function () use ($method, $route, $q, $b, $u) { return f3_dispatch_capture($method, $route, $q, $b, $u); })));
    }
    if ($method === 'GET' && in_array($route, F29_GET, true)) send(f29_get($route, $q, $u));
    if ($method === 'GET' && in_array($route, F31_GET, true)) send(f31_get($route, $q, $u));
    if ($method === 'POST' && in_array($route, F31_POST, true)) { if (!empty($GLOBALS['F3_IDEM_CAPTURE'])) throw new F3Result(f31_post($route, $b, $u)); send(transaction(fn() => f31_post($route, $b, $u))); }
    if ($method === 'POST' && in_array($route, F29_POST, true)) { if (!empty($GLOBALS['F3_IDEM_CAPTURE'])) throw new F3Result(f29_post($route, $b, $u)); send(transaction(fn() => f29_post($route, $b, $u))); }
    if ($method === 'GET') {
        $d = fn(string $k, ?string $def = null) => valid_date($q[$k] ?? ($def ?? f3_business_date()));
        switch ($route) {
            case 'me':
                f3_sync_notifications();
                $perms = f3_perms($u);
                send(['platform' => f29_platform_context($u), 'closing_final_on_submit' => f29_closing_direct_on(), 'user' => ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'role_label' => ROLE_LABELS[$u['role']] ?? $u['role'], 'template' => f3_template_of($u), 'template_label' => f3_templates()[f3_template_of($u)][0]],
                      'perms' => $perms, 'csrf' => csrf(), 'restaurant' => setting('restaurant', 'Khadak Singh Da Dhaba'), 'business_date' => f3_business_date(), 'target_date' => f3_target_date(), 'now' => f3_now()->format('Y-m-d H:i'),
                      'popups' => f3_popups($u), 'notifications' => count(array_filter(f3_notifications_for($u), fn($n) => !$n['snoozed'])), 'pending_closings' => f3_can($u, 'closing.view') ? f3_pending_closings() : [],
                      'archive' => ($u['role'] ?? '') === 'owner', 'archived' => legacy_archived(),
                      'accounts' => f3_visible_accounts(), 'accounts_all' => bk_accounts(), 'business' => f3_business(), 'packs' => f3_pack_units(), 'cost_centres' => f3_cost_centres(), 'messages' => f3_messages(), 'modules' => f3_modules(), 'default_paid_leave' => f3_set('f3_default_paid_leave'), 'modes' => ['Cash', 'UPI', 'Bank', 'Card'], 'departments' => f3_departments(), 'version' => 'V23', 'locations' => f3_locations(), 'limits' => f3_user_limits($u), 'purchase_categories' => array_values(array_diff(array_keys(bk_categories()), f3_hidden('f3_hidden_categories'))), 'approval_above' => f3_set_int('f3_purchase_approval_above')]);
            case 'dashboard': send(f3_dashboard($u));
            case 'assist': send(f3_assist(txt($q['section'] ?? 'dashboard', 20), $u));
            case 'pending-closings': f3_need($u, 'closing.view'); send(['days' => f3_pending_closings()]);
            case 'closing': send(f3_closing_state($d('date', f3_target_date()), $u));
            case 'sales-history': send(f3_sales_history($d('from', next_date(f3_business_date(), -30)), $d('to'), $u));
            case 'expenses': send(f3_expense_list($q, $u));
            case 'expense-categories': f3_need($u, 'expenses.view', 'expenses.create'); send(['categories' => f3_expense_categories(), 'frequent' => f3_expense_frequent(), 'accounts' => bk_accounts()]);
            case 'accounts': send(f3_accounts_overview($d('as_of'), $u));
            case 'book': send(f3_book(txt($q['account'] ?? f3_set('f3_acct_cash'), 40), $d('from', substr(f3_business_date(), 0, 8) . '01'), $d('to'), $u));
            case 'gl': send(f3_gl_view(txt($q['what'] ?? 'trial', 12), $q, $u));
            case 'control': send(f3_control_get($u));
            case 'integrations': send(f3_int_state($u));
            case 'overview': send(f3_overview($u));
            case 'salary-history': send(ei_history_view($q, $u));
            case 'emp-import': ei_need($u); send(!empty($q['batch']) ? ei_preview(txt($q['batch'], 40), $u) : ['batches' => rows("SELECT batch_id,status,created_at,applied_at,rolled_back_at FROM f3_import_batches WHERE kind='employees' ORDER BY created_at DESC LIMIT 10"), 'inspect' => ei_inspect(), 'counts' => ei_counts()]);
            case 'reconcile': send(f3_reconcile($u, isset($q['as_of']) ? valid_date($q['as_of']) : null));
            case 'scheduler': f3_need($u, 'settings.manage'); send(['jobs' => f3_scheduler_health()]);
            case 'gl-pending': send(f3_gl_pending($u));
            case 'suppliers': send(f3_sup_list($q, $u));
            case 'supplier': send(f3_sup_get((int)($q['id'] ?? 0), $u, !empty($q['reveal'])));
            case 'stock': send(f3_stock_overview($u));
            case 'items': send(f3_items($q, $u));
            case 'item': send(f3_item_get((int)($q['id'] ?? 0), $u));
            case 'item-ledger': send(f3_item_ledger((int)($q['id'] ?? 0), $q, $u));
            case 'units': send(['units' => f3_units_all(), 'standard' => array_keys(F3_STD_UNITS), 'packs' => F3_PACK_UNITS, 'locations' => f3_locations()]);
            case 'stock-moves': send(f3_stock_moves_report($q, $u));
            case 'stock-valuation': send(f3_stock_valuation($d('as_of'), $q, $u));
            case 'stock-count': send(f3_count_get((int)($q['id'] ?? 0), $u));
            case 'stock-counts': f3_need($u, 'stock.count', 'stock.count_approve'); send(['counts' => rows('SELECT c.*,(SELECT COUNT(*) FROM f3_stock_count_lines l WHERE l.count_id=c.id) n,(SELECT COUNT(*) FROM f3_stock_count_lines l WHERE l.count_id=c.id AND l.counted IS NOT NULL) done,(SELECT COUNT(*) FROM f3_stock_count_lines l WHERE l.count_id=c.id AND l.counted<>l.expected) diff FROM f3_stock_counts c ORDER BY c.id DESC LIMIT 50'), 'locations' => f3_locations()]);
            case 'bills': send(f3_bill_list($q, $u));
            case 'bill': send(f3_bill_get((int)($q['id'] ?? 0), $u));
            case 'aging': send(f3_aging($q, $u));
            case 'purchase-register': send(f3_purchase_register($q, $u));
            case 'thumb': f3_thumb((int)($q['id'] ?? 0), $u);
            case 'departments': f3_need($u, 'staff.view', 'payroll.view', 'stafftxn.create'); send(['departments' => f3_departments()]);
            case 'staff': send(f3_staff_list($q, $u));
            case 'staff-get': send(f3_staff_get((int)($q['id'] ?? 0), $u));
            case 'staff-txns': send(f3_staff_txn_list($q, $u));
            case 'payroll-months': send(f3_payroll_months($u));
            case 'payroll': send(f3_period_view(valid_month($q['period'] ?? ''), $u));
            case 'payroll-line': send(f3_line_get(valid_month($q['period'] ?? ''), (int)($q['employee_id'] ?? 0), $u));
            case 'payroll-sheet': send(f3_sheet(valid_month($q['period'] ?? ''), !empty($q['dept']) ? (int)$q['dept'] : null, $u));
            case 'payslip': send(f3_payslip(valid_month($q['period'] ?? ''), (int)($q['employee_id'] ?? 0), $u));
            case 'report-daily': send(f3_report_daily($d('from', substr(f3_business_date(), 0, 8) . '01'), $d('to'), $u));
            case 'report-monthly': send(f3_report_monthly(valid_month($q['month'] ?? substr(f3_business_date(), 0, 7)), $u));
            case 'report-staff': send(f3_report_staff_outstanding($u));
            case 'export': f3_export(txt($q['kind'] ?? '', 30), $q, $u);
            case 'notifications': f3_need($u, 'notifications.view'); f3_sync_notifications(); send(['items' => f3_notifications_for($u, !empty($q['all'])), 'corrections' => f3_can($u, 'closing.reopen') ? rows("SELECT c.*,u.name requested_by_name FROM f3_corrections c LEFT JOIN dd_users u ON u.id=c.requested_by ORDER BY c.status='open' DESC,c.id DESC LIMIT 100") : []]);
            case 'corrections': f3_need($u, 'closing.reopen', 'closing.correction'); send(['rows' => rows('SELECT c.*,u.name requested_by_name FROM f3_corrections c LEFT JOIN dd_users u ON u.id=c.requested_by' . (f3_can($u, 'closing.reopen') ? '' : ' WHERE c.requested_by=' . (int)$u['id']) . ' ORDER BY c.id DESC LIMIT 100')]);
            case 'users': send(f3_users($u));
            case 'settings': send(f3_settings($u));
            case 'audit': send(f3_audit_list($q, $u));
            case 'file': f3_file((int)($q['id'] ?? 0), $u);
            case 'handovers': send(f3_ho_list($q, $u));
            case 'search': send(f3_search($q, $u));
            case 'price-history': send(f3_price_history((int)($q['item'] ?? 0), $u));
            case 'price-alerts': send(['rows' => f3_price_alerts($u)]);
            case 'docs': send(f3_doc_list($q, $u));
            case 'reorder': send(f3_reorder($q, $u));
            case 'pay-acks': send(f3_ack_list($q, $u));
            case 'month-end': send(f3_month_end(valid_month($q['month'] ?? f3_prev_month(substr(f3_business_date(), 0, 7))), $u));
            case 'owner-digest': f3_need($u, 'reports.view'); send(f3_owner_digest($u, isset($q['month']) ? valid_month($q['month']) : null));
            case 'exceptions': send(f3_exc_list($q, $u));
        }
        fail('Not found', 404);
    }
    // Integrations call Google / AiSensy over the network: they manage their own short transactions and never share one with a posting.
    if (str_starts_with($route, 'int-')) send(f3_int_post($route, $b, $u));
    if (str_starts_with($route, 'emp-import-')) send(match ($route) { 'emp-import-upload' => transaction(fn() => ei_upload($b, $u)), 'emp-import-decide' => transaction(fn() => ei_decide($b, $u)), 'emp-import-apply' => ei_apply($b, $u), 'emp-import-rollback' => ei_rollback($b, $u) });
    $res = (!empty($GLOBALS['F3_IDEM_CAPTURE']) ? fn($f) => $f() : 'transaction')(function () use ($route, $b, $u) {
        return match ($route) {
            'closing-save' => f3_closing_save($b, $u), 'closing-finalize' => f3_closing_finalize($b, $u), 'closing-review' => f3_closing_review($b, $u), 'closing-reopen' => f3_closing_reopen($b, $u),
            'correction-request' => f3_correction_request($b, $u), 'correction-decide' => f3_correction_decide($b, $u),
            'expense-save' => f3_expense_save($b, $u), 'expense-void' => f3_expense_void($b, $u), 'expense-review' => f3_expense_review($b, $u), 'transfer-save' => f3_transfer_save($b, $u),
            'supplier-save' => f3_sup_save($b, $u), 'supplier-active' => f3_sup_active($b, $u), 'supplier-pay' => f3_sup_pay($b, $u), 'supplier-allocate' => f3_sup_allocate($b, $u), 'supplier-pay-void' => f3_sup_pay_void($b, $u),
            'supplier-refund' => f3_sup_refund($b, $u), 'return-save' => f3_return_save($b, $u),
            'item-save' => f3_item_save($b, $u), 'item-active' => f3_item_active($b, $u), 'item-opening' => f3_item_opening($b, $u), 'stock-move' => f3_stock_manual($b, $u), 'stock-consume' => f3_stock_consume($b, $u), 'stock-reverse' => f3_stock_reverse($b, $u),
            'count-start' => f3_count_start($b, $u), 'count-save' => f3_count_save($b, $u), 'count-approve' => f3_count_approve($b, $u), 'file-rotate' => f3_file_rotate($b, $u), 'gl-sync' => f3_gl_sync($u), 'gl-decide' => f3_gl_decide($b, $u), 'control-save' => f3_control_save($b, $u),
            'bill-save' => f3_bill_save($b, $u), 'bill-attach' => f3_bill_attach($b, $u), 'bill-discard' => f3_bill_discard($b, $u), 'bill-submit' => f3_bill_submit($b, $u), 'bill-approve' => f3_bill_approve($b, $u),
            'bill-receive' => f3_bill_receive($b, $u), 'bill-reverse' => f3_bill_reverse($b, $u), 'bill-copy' => f3_bill_copy($b, $u),
            'designation-save' => f3_designation_save($b, $u), 'staff-save' => f3_staff_save($b, $u), 'staff-bulk-join' => f3_staff_bulk_join($b, $u), 'staff-joining-fix' => f3_staff_joining_fix($b, $u), 'staff-exit' => f3_staff_exit($b, $u),
            'staff-txn' => f3_staff_txn_create($b, $u), 'staff-txn-decide' => f3_staff_txn_decide($b, $u), 'staff-txn-settle' => f3_staff_txn_settle($b, $u), 'staff-txn-reverse' => f3_staff_txn_reverse($b, $u),
            'payroll-open' => f3_period_open($b, $u), 'payroll-line' => f3_line_save($b, $u), 'payroll-submit' => f3_period_submit($b, $u), 'payroll-approve' => f3_period_approve($b, $u), 'payroll-lock' => f3_period_lock($b, $u), 'payroll-unlock' => f3_period_unlock($b, $u),
            'payroll-pay' => f3_pay_payment($b, $u), 'payroll-pay-bulk' => f3_pay_bulk($b, $u), 'payroll-pay-void' => f3_pay_void($b, $u),
            'handover-send' => f3_ho_create($b, $u), 'handover-respond' => f3_ho_respond($b, $u), 'handover-cancel' => f3_ho_cancel($b, $u),
            'doc-upload' => f3_doc_upload($b, $u), 'doc-act' => f3_doc_act($b, $u), 'pay-ack' => f3_ack_save($b, $u), 'pay-ack-resolve' => f3_ack_resolve($b, $u),
            'exc-scan' => f3_exc_scan($u), 'exc-act' => f3_exc_act($b, $u),
            'snooze' => f3_snooze($b, $u), 'user-save' => f3_user_save($b, $u), 'user-password' => f3_user_password($b, $u), 'settings-save' => f3_settings_save($b, $u),
        };
    });
    if (!empty($GLOBALS['F3_IDEM_CAPTURE'])) throw new F3Result($res);
    send($res);
}
final class F3Result extends RuntimeException { public function __construct(public array $data) { parent::__construct('result', 200); } }
/** Run a POST route inside the CALLER's transaction and return its result instead of sending it. */
function f3_dispatch_capture(string $method, string $route, array $q, array $b, array $u): array {
    $GLOBALS['F3_IDEM_CAPTURE'] = true;
    try { f3_dispatch($method, $route, $q, $b, $u); } catch (F3Result $r) { return $r->data; } finally { $GLOBALS['F3_IDEM_CAPTURE'] = false; }
    return ['ok' => true];
}
