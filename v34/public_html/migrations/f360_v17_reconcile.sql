-- Finance 360 reconciliation checks (V17 numbering, rewritten in V31 because the original file was missing from the ZIP).
-- Every query lists PROBLEM rows; a healthy ledger returns ZERO rows for each. Read-only. Run per branch
-- (in single-database mode the dd_/f3_ names are scoped views, so each run covers exactly one restaurant branch).
-- 1. Every journal balances (debit = credit)
SELECT j.id, SUM(l.debit) d, SUM(l.credit) c FROM dd_gl_journals j JOIN dd_gl_lines l ON l.journal_id=j.id GROUP BY j.id HAVING SUM(l.debit)<>SUM(l.credit);
-- 2. No negative or two-sided journal lines
SELECT id FROM dd_gl_lines WHERE debit<0 OR credit<0 OR (debit>0 AND credit>0);
-- 3. No journal line without its journal
SELECT l.id FROM dd_gl_lines l LEFT JOIN dd_gl_journals j ON j.id=l.journal_id WHERE j.id IS NULL;
-- 4. No purchase line without its bill
SELECT l.id FROM f3_purchase_lines l LEFT JOIN f3_purchase_bills b ON b.id=l.bill_id WHERE b.id IS NULL;
-- 5. No negative quantities / rates on purchase lines
SELECT id FROM f3_purchase_lines WHERE qty<0 OR rate<0 OR line_total<0;
-- 6. Stock balance = sum of stock movements (per item + location)
SELECT b.item_id, b.location, b.qty, m.q FROM f3_stock_balances b LEFT JOIN (SELECT item_id, location, SUM(qty) q FROM f3_stock_moves GROUP BY item_id, location) m ON m.item_id=b.item_id AND m.location=b.location WHERE ABS(b.qty-COALESCE(m.q,0))>0.0005;
-- 7. No stock movement for an unknown item
SELECT m.id FROM f3_stock_moves m LEFT JOIN dd_bk_items i ON i.id=m.item_id WHERE i.id IS NULL;
-- 8. Closing total = cash + card + UPI
SELECT id, business_date FROM f3_closings WHERE total_sale IS NOT NULL AND total_sale<>COALESCE(cash_sale,0)+COALESCE(card_sale,0)+COALESCE(upi_sale,0);
-- 9. One payroll line per employee per month
SELECT period, employee_id FROM f3_pay_lines GROUP BY period, employee_id HAVING COUNT(*)>1;
-- 10. No salary payment for an unknown employee
SELECT p.id FROM f3_pay_payments p LEFT JOIN dd_employees e ON e.id=p.employee_id WHERE e.id IS NULL;
-- 11. Salary paid never exceeds the net payable of that employee-month
SELECT p.period, p.employee_id, SUM(p.amount) paid, MAX(l.net_payable) net FROM f3_pay_payments p JOIN f3_pay_lines l ON l.period=p.period AND l.employee_id=p.employee_id WHERE p.voided=0 GROUP BY p.period, p.employee_id HAVING SUM(p.amount)>MAX(l.net_payable);
-- 12. (warning only) negative stock on hand
SELECT b.item_id, b.location, b.qty FROM f3_stock_balances b WHERE b.qty<0;
