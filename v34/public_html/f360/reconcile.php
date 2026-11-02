<?php
declare(strict_types=1);
require_once __DIR__ . '/reports.php';
// Finance 360 V21 §1/§14 — financial reconciliation. Every check compares an operational sub-ledger with the Books 360
// cashbook (the single money ledger) or with its own movement ledger, independently recomputed in SQL. Read-only.
// A check never "fixes" anything; differences are listed with the records involved so an admin can open and correct them.

function f3_reconcile(array $u, ?string $asOf = null): array {
    f3_need($u, 'accounts.view');
    $out = []; $add = function (string $id, string $area, string $title, array $bad, string $how, ?string $link = null) use (&$out) {
        $out[] = ['id' => $id, 'area' => $area, 'check' => $title, 'ok' => !$bad, 'issues' => count($bad), 'examples' => array_slice($bad, 0, 10), 'how' => $how, 'link' => $link]; };
    $live = "COALESCE(t.voided,0)=0";

    // --- Daily closing ---
    $add('CL-1', 'Daily closing', 'Each approved closing posted its Cash Acess / Cash Short at most once, for exactly the approved amount', array_map(fn($r) => $r['business_date'] . ': approved ' . f3_r(abs((int)$r['variance_amount'])) . ', posted ' . f3_r((int)$r['posted']) . ' in ' . $r['n'] . ' entr' . ((int)$r['n'] === 1 ? 'y' : 'ies'),
        rows("SELECT c.business_date,c.variance_amount,c.variance_class,COUNT(t.id) n,COALESCE(SUM(t.total),0) posted FROM f3_closings c LEFT JOIN dd_bk_txns t ON t.import_key LIKE CONCAT(?,c.business_date,':r%') AND $live
              WHERE c.status='approved' GROUP BY c.id HAVING n>1 OR (c.variance_amount<>0 AND c.variance_class LIKE 'post_%' AND posted<>ABS(c.variance_amount)) OR ((c.variance_amount=0 OR c.variance_class='no_posting') AND n>0)", F3_VAR_KEY)),
        'Independently entered Cash Acess / receipts are part of Expected Cash, so the posted difference never repeats them.', '#/sales');
    $add('CL-2', 'Daily closing', 'Closing sales in Books = Cash + Card + UPI of the final closing (no double or missing sales)', array_map(fn($r) => $r['business_date'] . ': closing ' . f3_r((int)$r['want']) . ' vs Books ' . f3_r((int)$r['got']),
        rows("SELECT c.business_date,(COALESCE(c.cash_sale,0)+COALESCE(c.card_sale,0)+COALESCE(c.upi_sale,0)) want,(SELECT COALESCE(SUM(t.total),0) FROM dd_bk_txns t WHERE t.import_key LIKE CONCAT(?,c.business_date,':%') AND $live) got
              FROM f3_closings c WHERE c.status IN ('submitted','approved') AND c.sales_post_status='posted' HAVING want<>got", F3_SALE_KEY)), 'Card and UPI are booked to their clearing accounts, never to the cash drawer.', '#/sales');
    $add('CL-3', 'Daily closing', 'Card / UPI sales never posted to the physical cash account', array_map(fn($r) => $r['date'] . ' ' . $r['import_key'],
        rows("SELECT t.date,t.import_key FROM dd_bk_txns t WHERE $live AND (t.import_key LIKE CONCAT(?,'%:card:%') OR t.import_key LIKE CONCAT(?,'%:upi:%')) AND t.account=?", F3_SALE_KEY, F3_SALE_KEY, f3_acct('cash'))), '', '#/cash');
    $add('CL-4', 'Daily closing', 'One closing per business date; approved/submitted days are locked', array_map(fn($r) => $r['business_date'] . ' ×' . $r['n'], rows('SELECT business_date,COUNT(*) n FROM f3_closings GROUP BY business_date HAVING n>1')), 'Enforced by a unique key.');

    // --- Payroll ---
    $add('PY-1', 'Payroll', 'Every salary payment has exactly one live Books entry with the same amount (voided payments are voided in Books too)', array_map(fn($r) => $r['pay_no'] . ': ' . $r['why'],
        rows("SELECT p.pay_no, CASE WHEN p.voided=0 AND (t.id IS NULL OR t.voided=1) THEN 'missing in Books' WHEN p.voided=0 AND t.total<>p.amount THEN CONCAT('Books ',t.total,' vs ',p.amount) WHEN p.voided=1 AND t.voided=0 THEN 'voided here, still live in Books' END why
              FROM f3_pay_payments p LEFT JOIN dd_bk_txns t ON t.id=p.bk_txn_id HAVING why IS NOT NULL")), '', '#/payroll');
    $add('PY-2', 'Payroll', 'No employee paid more than the approved net salary for a month (salary payable never negative)', array_map(fn($r) => $r['period'] . ' ' . $r['emp_name'] . ': net ' . f3_r((int)$r['net']) . ', paid ' . f3_r((int)$r['paid']),
        rows("SELECT l.period,l.emp_name,l.net_payable+l.carried_forward net,(SELECT COALESCE(SUM(amount),0) FROM f3_pay_payments p WHERE p.period=l.period AND p.employee_id=l.employee_id AND p.voided=0) paid
              FROM f3_pay_lines l JOIN f3_pay_periods pp ON pp.period=l.period WHERE pp.status IN ('approved','locked') AND l.state='done' HAVING paid>net")), '', '#/payroll');
    $add('PY-3', 'Payroll', 'Advance recovery never exceeds what was given (per employee, all time)', array_map(fn($r) => $r['name'] . ': given ' . f3_r((int)$r['given']) . ', recovered ' . f3_r((int)$r['rec']),
        rows("SELECT e.name,(SELECT COALESCE(SUM(amount),0) FROM f3_staff_txns WHERE employee_id=e.id AND kind IN ('advance','opening_advance') AND status IN ('approved','paid')) given,
              (SELECT COALESCE(SUM(amount),0) FROM f3_staff_txns WHERE employee_id=e.id AND kind='recovery' AND status='approved') rec FROM dd_employees e HAVING rec>given")),
        'A recovery in payroll only reduces the advance balance; it never creates a second cash payment.', '#/staff');
    $add('PY-4', 'Payroll', 'Advance recoveries recorded = advance recovery on approved salary rows (no double deduction)', array_map(fn($r) => $r['period'] . ' ' . $r['emp_name'] . ': salary row ' . f3_r((int)$r['row_rec']) . ' vs recovery entries ' . f3_r((int)$r['txn_rec']),
        rows("SELECT l.period,l.emp_name,l.advance_recovery row_rec,(SELECT COALESCE(SUM(amount),0) FROM f3_staff_txns s WHERE s.employee_id=l.employee_id AND s.kind='recovery' AND s.status='approved' AND s.applied_period=l.period) txn_rec
              FROM f3_pay_lines l JOIN f3_pay_periods pp ON pp.period=l.period WHERE pp.status IN ('approved','locked') AND l.state='done' HAVING row_rec<>txn_rec")), '', '#/payroll');
    $add('PY-5', 'Payroll', 'Overtime paid instantly is never added to (or deducted from) a salary row', array_map(fn($r) => $r['txn_no'] . ' applied to ' . $r['applied_period'],
        rows("SELECT txn_no,applied_period FROM f3_staff_txns WHERE kind='overtime' AND applied_period IS NOT NULL")), 'OT is shown on the sheet as information only.', '#/staff-entries');
    $add('PY-6', 'Payroll', 'Each paid advance / overtime has one live cash or bank entry in Books', array_map(fn($r) => $r['txn_no'] . ' (' . $r['kind'] . ')',
        rows("SELECT s.txn_no,s.kind FROM f3_staff_txns s LEFT JOIN dd_bk_txns t ON t.id=s.bk_txn_id WHERE s.kind IN ('advance','overtime') AND s.status='paid' AND (t.id IS NULL OR t.voided=1 OR t.total<>s.amount)")), '', '#/staff-entries');
    $add('PY-7', 'Payroll', 'No negative net salary on any row', array_map(fn($r) => $r['period'] . ' ' . $r['emp_name'], rows('SELECT period,emp_name FROM f3_pay_lines WHERE net_payable<0')), 'Deductions above earnings are capped and the rest carried forward.');

    // --- Purchases & suppliers ---
    $add('PU-1', 'Purchases', 'Every posted bill has exactly one live Books purchase with the same total', array_map(fn($r) => $r['bill_no'] . ': ' . $r['n'] . ' entr. / Books ' . f3_r((int)$r['bk']) . ' vs bill ' . f3_r((int)$r['grand_total']),
        rows("SELECT b.bill_no,b.grand_total,COUNT(t.id) n,COALESCE(SUM(t.total),0) bk FROM f3_purchase_bills b LEFT JOIN dd_bk_txns t ON t.import_key=CONCAT('f360:pb:',b.id) AND $live WHERE b.status='posted' GROUP BY b.id HAVING n<>1 OR bk<>b.grand_total")), '', '#/purchases');
    $add('PU-2', 'Purchases', 'Payment allocations never exceed the payment or the bill', array_map(fn($r) => $r['what'], rows("SELECT CONCAT('payment ',a.payment_txn_id,' over-allocated') what FROM f3_supplier_allocations a JOIN dd_bk_txns t ON t.id=a.payment_txn_id WHERE a.voided=0 GROUP BY a.payment_txn_id,t.paid HAVING SUM(a.amount)>t.paid
        UNION ALL SELECT CONCAT('bill ',b.bill_no,' over-paid') FROM f3_supplier_allocations a JOIN f3_purchase_bills b ON b.id=a.bill_id WHERE a.voided=0 GROUP BY b.id,b.grand_total HAVING SUM(a.amount)>b.grand_total")), '', '#/suppliers');
    $add('PU-3', 'Purchases', 'Stock received once per bill line (edits never duplicate a receipt)', array_map(fn($r) => 'line ' . $r['source_line_id'] . ' ×' . $r['n'],
        rows("SELECT source_line_id,COUNT(*) n FROM f3_stock_moves WHERE kind='receipt' AND reversed_by IS NULL AND source_line_id IS NOT NULL GROUP BY source_line_id,source_id HAVING n>1")), '', '#/stock-moves');

    // --- Stock ---
    $add('ST-1', 'Stock', 'Stock balance (qty and value) = sum of the movement ledger, per item and location', array_map(fn($r) => 'item ' . $r['item_id'] . ' @ ' . $r['location'] . ': balance ' . $r['qty'] . ' / ledger ' . $r['mq'],
        rows("SELECT b.item_id,b.location,b.qty,b.value,COALESCE(m.q,0) mq,COALESCE(m.v,0) mv FROM f3_stock_balances b LEFT JOIN (SELECT item_id,location,SUM(qty) q,SUM(value) v FROM f3_stock_moves GROUP BY item_id,location) m ON m.item_id=b.item_id AND m.location=b.location
              WHERE ABS(b.qty-COALESCE(m.q,0))>0.0005 OR b.value<>COALESCE(m.v,0)")), 'Valuation method: weighted average cost (documented in ACCOUNTING_RULES §4).', '#/valuation');
    $add('ST-2', 'Stock', 'Opening stock and manual corrections all have a reason and a user', array_map(fn($r) => $r['move_no'], rows("SELECT move_no FROM f3_stock_moves WHERE kind IN ('opening','adjust_in','adjust_out','count_adj') AND (COALESCE(reason,'')='' OR (created_by IS NULL AND source_type<>'legacy')) LIMIT 50")), '', '#/stock-moves');
    $neg = rows("SELECT i.name,b.location,b.qty FROM f3_stock_balances b JOIN dd_bk_items i ON i.id=b.item_id WHERE b.qty<0");
    $add('ST-3', 'Stock', 'No negative stock (allowed only by a logged manager override)', array_map(fn($r) => $r['name'] . ' @ ' . $r['location'] . ': ' . $r['qty'], $neg), setting('f3_negative_stock', '0') === '1' ? 'Negative stock is switched ON in settings — review these.' : '', '#/stock');

    // --- Expenses ---
    $add('EX-1', 'Expenses', 'The same receipt file is not attached to two different live expenses (prevents double posting from re-uploads)', array_map(fn($r) => 'receipt ' . substr($r['sha256'], 0, 10) . ' on ' . $r['n'] . ' expenses',
        rows("SELECT d.sha256,COUNT(DISTINCT x.txn_id) n FROM (SELECT txn_id,file_id FROM f3_expense_files WHERE removed=0 UNION SELECT txn_id,file_id FROM f3_expense_meta WHERE file_id IS NOT NULL) x JOIN dd_files d ON d.id=x.file_id JOIN dd_bk_txns t ON t.id=x.txn_id AND t.voided=0 GROUP BY d.sha256 HAVING n>1")),
        'Shown for review: the same photo can legitimately support two entries, but usually it is a duplicate.', '#/expenses');

    // --- Double-entry ledger ---
    $glNote = null;
    if (function_exists('f3_gl_available') && f3_gl_available()) { require_once dirname(__DIR__) . '/post360.php';
        if (!fin_unify_from()) $glNote = 'The double-entry ledger is not switched on (no "post Books to ledger from" date), so ledger checks are skipped and the P&L / Balance sheet screens are empty. The cash book, sub-ledgers and all checks above are unaffected.'; }
    if (!$glNote && function_exists('f3_gl_available') && f3_gl_available()) {
        require_once dirname(__DIR__) . '/gl360.php';
        $to = $asOf ?? f3_business_date(); $tb = gl_trial_balance($to);
        $add('GL-1', 'General ledger', 'Trial balance balances (total debits = total credits)', $tb['balanced'] ? [] : ['Debits ' . f3_r((int)$tb['total_debit']) . ' vs credits ' . f3_r((int)$tb['total_credit']) . (count($tb['unbalanced_journals']) ? ' · ' . count($tb['unbalanced_journals']) . ' unbalanced journal(s)' : '')], 'As of ' . $to, '#/gl');
        $st = gl_statements(substr($to, 0, 8) . '01', $to);
        $add('GL-2', 'General ledger', 'Balance sheet balances: assets = liabilities + equity + retained profit', $st['balance_sheet']['balanced'] ? [] : ['Assets ' . f3_r($st['balance_sheet']['assets']) . ' vs ' . f3_r($st['balance_sheet']['liabilities'] + $st['balance_sheet']['equity'] + $st['balance_sheet']['retained_profit'])], '', '#/gl?tab=statements');
        $pend = count(f3_gl_pending($u)['rows'] ?? []);
        $add('GL-4', 'General ledger', 'Advance recoveries of every approved salary month are in the ledger (Dr Salary / Cr Employee Advances), so ledger advances match the staff ledger', array_map(fn($r) => $r['period'] . ': recovered ' . f3_r((int)$r['want']) . ', ledger ' . f3_r((int)$r['got']),
            rows("SELECT x.period,x.want,(SELECT COALESCE(SUM(l.credit),0) FROM dd_gl_journals j JOIN dd_gl_lines l ON l.journal_id=j.id JOIN dd_gl_accounts a ON a.id=l.account_id AND a.code='1300' WHERE j.source_key LIKE CONCAT('f3:payrec:',x.period,':%') AND j.status='posted') got
                  FROM (SELECT applied_period period,SUM(amount) want FROM f3_staff_txns WHERE kind='recovery' AND status='approved' AND applied_period IS NOT NULL AND LAST_DAY(CONCAT(applied_period,'-01'))>=? GROUP BY applied_period) x HAVING want<>got", fin_unify_from())), 'Months approved before V21 are added by Accounts → Update ledger.', '#/accounts?tab=trial');
        $from = fin_unify_from(); $glAdv = (int)row("SELECT COALESCE(SUM(l.debit-l.credit),0) n FROM dd_gl_lines l JOIN dd_gl_accounts a ON a.id=l.account_id AND a.code='1300' JOIN dd_gl_journals j ON j.id=l.journal_id WHERE j.business_date<=?", $asOf ?? '9999-12-31')['n'];
        $subAdv = (int)row("SELECT (SELECT COALESCE(SUM(amount),0) FROM f3_staff_txns WHERE kind='advance' AND status IN ('approved','paid') AND bk_txn_id IS NOT NULL AND date>=?) - (SELECT COALESCE(SUM(amount),0) FROM f3_staff_txns WHERE kind='recovery' AND status='approved' AND LAST_DAY(CONCAT(applied_period,'-01'))>=?) n", $from, $from)['n'];
        $add('GL-5', 'General ledger', 'Ledger 1300 Employee Advances = staff ledger (advances paid − recovered) since the ledger start date', $glAdv === $subAdv ? [] : ['Ledger ' . f3_r($glAdv) . ' vs staff ledger ' . f3_r($subAdv) . ' (difference ' . f3_r($glAdv - $subAdv) . ')'],
            'Advances given before ' . $from . ', old CRM / Salary Register advances and opening advances need an opening journal (Dr 1300 / Cr 3900) to be included.', '#/accounts?tab=ledger&code=1300');
        $add('GL-3', 'General ledger', 'No financial events are waiting to be classified for the double-entry ledger', $pend ? [$pend . ' event(s) waiting — Accounts → Update ledger'] : [], 'The ledger is a shadow of Books; run “Update ledger” after a busy day.', '#/gl');
    }
    $ok = count(array_filter($out, fn($c) => $c['ok']));
    return ['as_of' => $asOf ?? f3_business_date(), 'gl_note' => $glNote, 'checks' => $out, 'passed' => $ok, 'failed' => count($out) - $ok, 'at' => gmdate('Y-m-d H:i:s')];
}
