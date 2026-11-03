# Finance 360 V38 — salary flow and ledger rules (as implemented in app 0.38 on the V34 backend)

## Flow
1. **Salary → month.** The due month opens with "Make Salary Sheet" (server `salary/open`). Imported months are read-only.
2. **Sheet.** Hero = balance to pay (net + arrears − paid). Filters: department (Management, UT, Kitchen, Waiter — Guard is a
   designation under Waiter) and status tabs with counts: **Pending · Draft · Ready · Part paid · Paid · Left/excluded · All**.
   Buttons: **Next pending**, **Pay next** (after approval), **Add staff** (`staff.manage`). Row menu: enter/review, pay,
   payments & slip, exclude from this month (`payroll.approve`, reason), mark left (`staff.manage`).
3. **Employee page** (one at a time, "Employee X of N" in the department):
   * header: code, designation, month, calendar days, daily rate (= monthly ÷ calendar days 28/29/30/31), days employed;
   * **Previous balances first** (see below);
   * **Present days \*** (required — 0 is a valid answer, empty is not), **Half days**, **Paid / approved leave** (empty = 0);
   * **Adjustments & details** (folded): absent override (empty = server derives it), advance recovery this month, approved
     penalties to deduct (checkboxes; pending penalties shown but not deductible);
   * **Server calculation** (debounced preview; stale replies ignored): payable days, earned, − advance recovery, − penalties,
     = net this month, + arrears, − already paid, = remaining due, advance still owed after this salary;
   * **Save & Next** (opens the next pending/draft person of the same department), **Previous**, **Save draft**, **Add later**, Reload.
4. **Submit → Approve** (server permissions `payroll.prepare` / `payroll.approve`).
5. **Pay** (`payroll.pay`, only after approval) — see "Recording a payment".

## Six-field compatibility
The server's v19 calculation still receives the six inputs: `present`, `absent` (empty → derived), `half_days` (empty → "0"),
`paid_leave` (empty → "0"), `advance_recovery` (empty → **"0"**), `penalty_ids`. Old drafts load into the same fields.

## Client validation (the server validates again)
Present required for Save & Next; no negative values; half days whole numbers; present ≤ days employed; present + half days + leave ≤
days employed ("each day counted once"); advance recovery ≤ advance outstanding. Invalid input never calls preview or save.

## Previous balances — never netted
| Line | Direction | Source (`salary/drafts` context) |
|---|---|---|
| Unpaid salary from earlier months (arrears) | **Employee is owed** | `carried_forward` (> 0), `cf_source` |
| Overpaid earlier (if `carried_forward` < 0) | Employee owes | `−carried_forward` |
| Advance / loan outstanding (+ given this month, already recovered) | **Employee owes** | `advance_outstanding`, `advance_given`, `advance_recovered` |
| Approved penalties not yet deducted | Employee owes | `penalties`, `penalty_items` |
| Penalties waiting for approval | — (not deductible) | `penalty_pending_approval` |
| Already paid for this month | — | `paid` |
| Overtime paid immediately (information only, not added again) | — | `ot_paid`; unpaid OT `ot_unpaid` |

* An advance owed by the employee and salary owed to the employee are separate lines; nothing is auto-netted.
* **No automatic advance recovery**: empty = ₹0. "Use agreed instalment" fills the instalment only on tap.
* A penalty is deducted only if ticked and approved; it cannot be deducted twice (server marks it applied).
* If any of `carried_forward, advance_outstanding, penalties, paid, ot_paid` is missing, the panel shows
  **"Balance verification unavailable"** and Save & Next is disabled — no invented zero balances.

Example (server figures): earned ₹15,000 − approved recovery ₹1,000 = net ₹14,000; + arrears ₹2,000 = ₹16,000 payable;
payment ₹6,000 → ₹10,000 due; the advance ledger falls by ₹1,000 only (the recovery), never by the salary payment.

## Recording a payment (records money already paid; the app never transfers money)
* Shows staff, code, month, restaurant · branch, net this month, + arrears, total payable, − already paid, **remaining due**, and every
  payment already recorded (date, receipt no., mode, UTR, cancelled).
* **Method is mandatory**: Cash or Online; Online then requires **UPI** or **Bank transfer** (+ UTR/reference field).
  Only the methods the user may pay with (`pay.cash`, `pay.upi`, `pay.bank`) are enabled.
* **Amount is mandatory for both methods**: > ₹0 and ≤ remaining due; "Full remaining due" fills it only on tap. Part payments allowed.
* Confirmation dialog repeats amount, method, account type, staff, month and the due after payment.
* `POST salary/payments` with one idempotency key per payment attempt. A lost reply ("no internet / timeout") keeps the key, so
  pressing Confirm again cannot record a second payment (tested). 409 / 422 reload the server figures.
* Receipt from the server (receipt no., paid now, method, remaining due). Status after server confirmation only:
  balance > 0 → **Part paid** (stays in the to-pay list); balance 0 → **Paid**, and the next employee with salary due opens
  automatically (same department first).
* Posting is done by the server: salary payment row + cash/bank/UPI book entry ("Salary - Current/Previous Month") in the same
  transaction. Corrections: cancel with reason on the website (backend item B4 for the app).

## Staff
* **Add staff**: name, optional HR code (duplicates refused by the server, never merged), department → designation, basic monthly,
  joining date, phone, salary mode. From the salary sheet the roster is refreshed so a mid-month joiner gets a line with only the
  employed days.
* **Mark left**: last working day + reason mandatory (remarks optional); status change only — record, months, advances and payments
  stay. The open month is refreshed: earnings only up to the exit date; a person who left before the month is excluded.
  The server's warning (e.g. advance still outstanding) is shown.
* **Exclude from this month**: separate from leaving (reason, `payroll.approve`), keeps the person on staff.
* **Rejoin**: reactivates the same record and code (effective date: backend item B1).
* No delete anywhere.
