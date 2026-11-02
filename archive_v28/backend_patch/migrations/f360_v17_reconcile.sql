-- Read-only reconciliation of the canonical Finance360 stock movement ledger.
-- Every SELECT must return zero rows; run after migration/import and before release.
SELECT b.item_id,b.location,b.qty,COALESCE(m.qty,0) AS ledger_qty
FROM f3_stock_balances b
LEFT JOIN (SELECT item_id,location,SUM(qty) qty FROM f3_stock_moves GROUP BY item_id,location) m
ON m.item_id=b.item_id AND m.location=b.location
WHERE ABS(b.qty-COALESCE(m.qty,0))>0.0005;

-- A movement must have a corresponding current-balance row.
SELECT m.item_id,m.location
FROM f3_stock_moves m LEFT JOIN f3_stock_balances b ON b.item_id=m.item_id AND b.location=m.location
WHERE b.item_id IS NULL GROUP BY m.item_id,m.location;

-- No orphan purchase lines. Posted rows must keep their canonical parent bill.
SELECT l.id,l.bill_id FROM f3_purchase_lines l LEFT JOIN f3_purchase_bills b ON b.id=l.bill_id WHERE b.id IS NULL;

-- Bill documents must retain a parent bill.
SELECT f.id,f.bill_id FROM f3_bill_files f LEFT JOIN f3_purchase_bills b ON b.id=f.bill_id WHERE b.id IS NULL;

-- Goods receipts must retain a parent bill.
SELECT r.id,r.bill_id FROM f3_receipts r LEFT JOIN f3_purchase_bills b ON b.id=r.bill_id WHERE b.id IS NULL;

-- Allocations must retain a parent bill.
SELECT a.id,a.bill_id FROM f3_supplier_allocations a LEFT JOIN f3_purchase_bills b ON b.id=a.bill_id WHERE b.id IS NULL;

-- Payment allocations must reference the canonical Books transaction.
SELECT a.id,a.payment_txn_id FROM f3_supplier_allocations a LEFT JOIN dd_bk_txns t ON t.id=a.payment_txn_id WHERE t.id IS NULL;

-- A selected supplier must exist in the canonical Books party master.
SELECT b.id,b.supplier_id FROM f3_purchase_bills b LEFT JOIN dd_bk_parties p ON p.id=b.supplier_id WHERE b.supplier_id IS NOT NULL AND p.id IS NULL;

-- Every movement belongs to a canonical item.
SELECT m.id,m.item_id FROM f3_stock_moves m LEFT JOIN dd_bk_items i ON i.id=m.item_id WHERE i.id IS NULL;

-- Every current balance belongs to a canonical item.
SELECT b.item_id,b.location FROM f3_stock_balances b LEFT JOIN dd_bk_items i ON i.id=b.item_id WHERE i.id IS NULL;

-- Supplier payment metadata must reference the canonical Books posting.
SELECT p.txn_id FROM f3_supplier_payments p LEFT JOIN dd_bk_txns t ON t.id=p.txn_id WHERE t.id IS NULL;
