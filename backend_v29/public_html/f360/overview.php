<?php
declare(strict_types=1);
// Finance 360 V21 §11 — Owner / Admin overview: one list of everything that needs action, each opening the screen
// where it is fixed. Every item is filtered by the signed-in user's permissions (no number is shown that the user
// could not open). Cheap COUNT/SUM queries only.

function f3_overview(array $u): array {
    $o = []; $add = function (string $key, string $label, int $count, string $link, string $level = 'warn', ?int $amount = null, string $note = '') use (&$o) {
        $o[] = ['key' => $key, 'label' => $label, 'count' => $count, 'amount' => $amount, 'link' => $link, 'level' => $count ? $level : 'ok', 'note' => $note]; };
    $today = f3_business_date();
    if (f3_can($u, 'closing.view')) { $pc = f3_pending_closings(); $add('closing', 'Daily closing pending', count($pc), $pc ? '#/closing/' . $pc[0]['date'] : '#/closing', 'bad', null, $pc ? 'Oldest: ' . $pc[count($pc) - 1]['date'] : ''); }
    if (f3_can($u, 'closing.approve')) { $n = (int)row("SELECT COUNT(*) n FROM f3_closings WHERE status='submitted'")['n']; $add('closing_review', 'Closings to review (Cash Acess / Cash Short)', $n, '#/cash-acess'); }
    if (f3_can($u, 'closing.variance')) { $r = row("SELECT COUNT(*) n,COALESCE(SUM(-operational_diff),0) a FROM f3_closings WHERE status IN ('submitted','approved') AND operational_diff<0 AND business_date>=?", next_date($today, -30));
        $add('cash_short', 'Cash Short days (last 30 days)', (int)$r['n'], '#/cash-acess', 'warn', (int)$r['a']); }
    if (f3_can($u, 'expenses.review')) { $n = (int)row("SELECT COUNT(*) n FROM f3_expense_meta m JOIN dd_bk_txns t ON t.id=m.txn_id AND t.voided=0 WHERE m.review_status IN ('pending','flagged')")['n']; $add('expenses', 'Expenses not yet reviewed', $n, '#/expenses?review=pending', 'info'); }
    if (f3_can($u, 'payroll.view') || f3_can($u, 'payroll.prepare')) {
        $p = f3_payroll_due_period() ?? f3_prev_month(substr($today, 0, 7)); $per = f3_period($p);
        $c = $per ? row("SELECT COUNT(*) total,SUM(state='done') done,SUM(state='draft') draft,SUM(state IN ('pending','later','skipped')) pend FROM f3_pay_lines WHERE period=?", $p) : null;
        $label = (new DateTimeImmutable($p . '-01'))->format('F Y');
        $add('payroll', 'Salary ' . $label . ' — ' . ($per ? $per['status'] : 'not started') . ($c ? sprintf(' · %d completed, %d draft, %d pending', $c['done'], $c['draft'], $c['pend']) : ''), $per && in_array($per['status'], ['approved', 'locked'], true) ? 0 : max(1, (int)($c['draft'] ?? 0) + (int)($c['pend'] ?? 0)), '#/payroll/' . $p . '/wizard', 'warn');
        $add('payroll_review', 'Salary months waiting for approval', (int)row("SELECT COUNT(*) n FROM f3_pay_periods WHERE status='review'")['n'], '#/payroll', 'warn');
    }
    if (f3_can($u, 'payroll.pay') || f3_can($u, 'payroll.approve')) { $r = row("SELECT COUNT(*) n,COALESCE(SUM(due),0) a FROM (SELECT l.employee_id,(l.net_payable+l.carried_forward)-(SELECT COALESCE(SUM(amount),0) FROM f3_pay_payments p WHERE p.period=l.period AND p.employee_id=l.employee_id AND p.voided=0) due
            FROM f3_pay_lines l JOIN f3_pay_periods pp ON pp.period=l.period WHERE pp.status IN ('approved','locked') AND l.state='done') x WHERE due>0");
        $add('unpaid_salary', 'Unpaid / part-paid salaries', (int)$r['n'], '#/payroll', 'warn', (int)$r['a']); }
    if (f3_can($u, 'staff.ledger')) { $r = row("SELECT COUNT(*) n,COALESCE(SUM(g-r),0) a FROM (SELECT employee_id,SUM(CASE WHEN kind IN ('advance','opening_advance') AND status IN ('approved','paid') THEN amount ELSE 0 END) g,SUM(CASE WHEN kind='recovery' AND status='approved' THEN amount ELSE 0 END) r FROM f3_staff_txns GROUP BY employee_id) x WHERE g>r");
        $add('advances', 'Staff with unrecovered advances', (int)$r['n'], '#/reports?tab=staff', 'info', (int)$r['a']); }
    if (f3_can($u, 'stafftxn.approve')) $add('staff_approvals', 'Staff entries waiting for approval (advance / penalty / extra)', (int)row("SELECT COUNT(*) n FROM f3_staff_txns WHERE status='pending'")['n'], '#/staff-entries?status=pending');
    if (f3_can($u, 'closing.approve')) $add('corrections', 'Correction requests for locked days', (int)(row("SELECT COUNT(*) n FROM f3_corrections WHERE status='open'")['n'] ?? 0), '#/notifications');
    if (f3_can($u, 'supplier.outstanding') || f3_can($u, 'suppliers.view')) { $r = row("SELECT COUNT(*) n,COALESCE(SUM(b.grand_total-COALESCE(a.paid,0)),0) a FROM f3_purchase_bills b LEFT JOIN (SELECT bill_id,SUM(amount) paid FROM f3_supplier_allocations WHERE voided=0 GROUP BY bill_id) a ON a.bill_id=b.id
            WHERE b.status='posted' AND b.due_date IS NOT NULL AND b.due_date<? AND b.grand_total>COALESCE(a.paid,0)", $today);
        $add('supplier_overdue', 'Supplier bills overdue', (int)$r['n'], '#/aging', 'bad', (int)$r['a']); }
    if (f3_can($u, 'purchase.approve')) $add('bills_review', 'Purchase bills waiting for approval', (int)row("SELECT COUNT(*) n FROM f3_purchase_bills WHERE status='review'")['n'], '#/purchases?status=review');
    if (f3_can($u, 'purchase.view')) $add('bills_draft', 'Purchase bill drafts not finished', (int)row("SELECT COUNT(*) n FROM f3_purchase_bills WHERE status='draft' AND created_at<NOW()-INTERVAL 1 DAY")['n'], '#/purchases?status=draft', 'info');
    if (f3_can($u, 'stock.view')) $add('low_stock', 'Low-stock items', (int)row('SELECT COUNT(*) n FROM dd_bk_items i LEFT JOIN (SELECT item_id,SUM(qty) q FROM f3_stock_balances GROUP BY item_id) b ON b.item_id=i.id WHERE i.active=1 AND i.is_stock=1 AND GREATEST(i.min_level,i.reorder)>0 AND COALESCE(b.q,0)<=GREATEST(i.min_level,i.reorder)')['n'], '#/stock', 'warn');
    if (($u['role'] ?? '') === 'owner') {
        $add('drive_fail', 'Google Drive uploads failed', (int)row("SELECT COUNT(*) n FROM f3_drive_items WHERE status='failed'")['n'], '#/settings?tab=integrations&sec=drive', 'bad');
        $add('wa_fail', 'WhatsApp messages failed', (int)row("SELECT COUNT(*) n FROM f3_wa_messages WHERE status='failed' AND created_at>NOW()-INTERVAL 7 DAY")['n'], '#/settings?tab=integrations&sec=wa', 'bad');
        $bad = array_filter(f3_scheduler_health(), fn($j) => in_array($j['health'], ['late', 'failing'], true));
        $add('cron', 'Scheduled jobs late or failing', count($bad), '#/settings?tab=integrations&sec=scheduler', 'bad', null, implode(', ', array_column($bad, 'label')));
        $lastBk = row("SELECT MAX(finished_at) t FROM f3_job_runs WHERE job='backup' AND status='ok' AND message LIKE 'Backup %'")['t'] ?? null;
        $add('backup', 'No verified backup in the last 8 days', !$lastBk || strtotime($lastBk . ' UTC') < time() - 8 * 86400 ? 1 : 0, '#/settings?tab=integrations&sec=backup', 'warn', null, $lastBk ? 'Last: ' . $lastBk . ' UTC' : 'Never');
    }
    $need = array_values(array_filter($o, fn($x) => $x['count'] > 0)); $okc = count($o) - count($need);
    usort($need, fn($a, $b) => (['bad' => 0, 'warn' => 1, 'info' => 2][$a['level']] ?? 3) <=> (['bad' => 0, 'warn' => 1, 'info' => 2][$b['level']] ?? 3));
    return ['items' => $need, 'all_clear' => $okc, 'checked' => count($o)];
}
