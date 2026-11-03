# Finance 360 V34 — feature checklist (source + executed tests)

Status: **Verified** = exercised by an executed automated test · **Present** = in code, not covered by an automated test
in this release · **Partial** · **Missing** · **Blocked** = needs credentials / owner decision / device.

| Area | Item | Status | Evidence |
|---|---|---|---|
| Registration | owner account, restaurant, first branch, terms consent, duplicate control, atomic provisioning | Verified | platform REG-01…08; hosting rehearsal (web) |
| | e-mail verification | Verified (mail mode log) | REG-03, verify.php in rehearsal · real SMTP **Blocked** |
| | mobile OTP | **Blocked** | no SMS/WhatsApp OTP provider |
| | logo / branding | Present | owner.php branding, logo.php |
| | default roles, 4 departments, 30-day trial | Verified | REG-06, rehearsal "own 4 departments", trialing |
| Subscriptions | plans monthly/quarterly/6-monthly/yearly, versioned prices | Verified | PLAT-02, PAY-01 |
| | trial countdown, reminders (7,4,3,1 days; T-4 mandatory) | Verified (dedupe) | SUB-04 · delivery **Blocked** (SMTP) |
| | grace, read-only expiry, nothing deleted | Verified | SUB-01…03, API-06 (402) |
| | gateway payments, renewal, refunds, failed payments, replay | Verified with signed test events | PAY-01…11, API-09 · live Razorpay **Blocked** |
| | Play Store payment compliance | **Blocked** | owner/legal decision (app checkout off) |
| Super Admin | restaurants, branches, plans, subscriptions, payments, audit, TOTP, support access | Verified (core) | ADM-01/02, WEB-04 |
| | support tickets | **Missing** | not in source |
| | backup health / platform monitoring dashboard | **Partial** | `tools/health-check.php` (CLI); no Super Admin page |
| Daily closing | previous day, Cash/Card/UPI, expenses, physical cash, Cash Acess/Short, final without approval, lock, correction request | Verified | CLO-01…03, legacy FIN/ACC tests, app live tests |
| | optional daybook photo | Verified | CLO-02, app live test |
| Accounting | cash book, bank/UPI, supplier ledger, advances, salary liabilities, GL, trial balance, P&L, balance sheet | Verified (legacy suite) | 140 legacy tests in both modes |
| | branch-wise + consolidated | Partial | consolidated = sum of permitted branches (owner portal) |
| | inter-branch stock/cash transfer | Present | `f360/v31.php` reviewed; no automated test in this release |
| Salary | 4 departments, Guard under Waiter, six inputs, calendar-day divisor, draft/autosave/resume/Save & Next, partial payments, history | Verified | legacy STA/PAY/V19 tests, app live salary test |
| | paid overtime excluded from payable | Verified (code) | `ot_paid_info` information only |
| | employee transfer between branches | **Missing** | only department/designation history within a branch |
| | June–September 2026 history from the real Excel files | **Blocked** | source files not supplied in this package |
| Purchase / stock | supplier-first, last items/rates, rate-change confirmation, price comparison, bill photo, credit, partial payments, returns, receiving, consumption, counts, unit conversions, valuation, reorder | Verified (core) | PUR-01, legacy STK/V23 tests, app live purchase test |
| Permissions | backend-enforced, counter = closing only, store = stock, revocation | Verified | API-04, API-08, PERM-*, app widget tests |
| Integrations | Google Drive, AiSensy, FCM, cron | Present · **Blocked** | credentials not provided; `environment=staging` keeps them off |
| UI | mobile/desktop pages, Hindi/English | Partial | website pages unchanged from V31; Hindi partial; no browser/device test run here |
| Android | API compatibility | Verified | app 22 unit/widget + 11 live tests against V34 |
| Hosting | config/private outside web root, sensitive files blocked, sessions separated, staging layout | Verified | hosting rehearsal 53/53 |
| Migration | conversion, reconciliation, verify, rollback | Verified on demo data | conversion rehearsal 12/12 · **staging run pending** |
