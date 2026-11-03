# Finance 360 V38 — backend change requests (for your later approval)

**Nothing in this file was implemented.** No PHP, MySQL, Hostinger file, live website or database was changed in V38.
Each item says what the Android app 0.38 already does, what the server must add, and the exact contract the app expects.
All routes go through the existing `api/v1/index.php` (`__path=`), with the restaurant/branch taken from the token (never from the request).

## Already supported by the current V34 backend (used by app 0.38, verified by live tests)
| App feature | Existing route | Notes |
|---|---|---|
| Salary roster, statuses, totals | `GET salary/sheet` | lines carry `entry_status`, `paid`, `balance`, `eligible_days`, `carried_forward` |
| Previous balances panel | `GET salary/drafts` → `context` | `carried_forward` (+`cf_source`), `advance_outstanding/given/recovered`, `installment`, `penalties`, `penalty_items`, `penalty_pending_approval`, `paid`, `ot_paid`, `ot_unpaid` |
| Present-first entry, server preview, Save & Next | `POST salary/drafts` (`preview` / `save` / `draft` / `later` / `exclude`) | half days / leave sent as `0` when empty; advance recovery sent as `0` unless typed |
| Record salary payment (Cash / UPI / Bank, partial) | `POST salary/payments` | amount > 0 and ≤ balance enforced (400 / 422); idempotent with `client_key` |
| Payments of a person / slip | `GET salary/payslip` | payment list (no ids — see B4) |
| Add / edit staff, duplicate code refused | `POST employees` | 409 on duplicate code |
| Mark left (date + reason) / rejoin | `POST f360/staff-exit` | roster refresh = `POST salary/open` on a draft/review month |

## A. Force update (required for real enforcement)
**A1 — `GET app/version-policy` (public, no token)**
Query: `platform=android&channel=play|direct&package=<applicationId>&version_code=<int>`. Response (200):
```json
{"policy": {
  "platform": "android", "channel": "direct", "package_id": "com.finance360.app.staging",
  "minimum_supported_version_code": 38, "latest_version_code": 39, "force_update": false,
  "update_title": "Update Finance 360", "update_message": "Version 0.39 fixes salary slips.",
  "destination_type": "external_apk", "update_url": "https://staging.ksdd.in/downloads/finance360-0.39.0-staging.apk",
  "apk_sha256": "<64 hex>", "policy_revision": "2026-11-03-1", "max_cache_age": 21600, "maintenance_mode": false }}
```
Rules: one row per (package_id, channel); `update_url` must be HTTPS on an allow-listed host (Play policies must point to
`play.google.com/store/apps/details?id=<same package>`); every change audited (who, when, old/new). 404 = no policy (app runs).
**A2 — Super Admin screen** to edit A1 (min / latest code, forced flag, title, message, channel, destination, URL, SHA-256), with preview and audit.
**A3 — Server enforcement (the real guarantee).** The app already sends `X-App-Platform`, `X-App-Package`, `X-App-Version-Code`,
`X-App-Version-Name`, `X-App-Channel` on every request. The server must refuse **writes** (and optionally reads) from a version
below the minimum with **HTTP 426** `{"code":"UPDATE_REQUIRED","error":"…"}`. Without A3 an old/modified app can still post.
**A4 — APK hosting** outside the PHP code: a fixed HTTPS path on ksdd.in for signed APKs, served with `Content-Type: application/vnd.android.package-archive`.

## B. Salary and staff
**B1 — Exit / rejoin with effective dates.** `staff-exit` with `rejoin=1` sets the person active with no rejoin date and keeps the
old `left_on` history only in the audit log. Needed: `rejoined_on` (required), an employment-period table (join/left pairs) so
eligible days use every period; refuse rejoin before the last `left_on`.
**B2 — Include again after "Exclude from this month".** There is `action=exclude` but no undo. Add `action=include` (reason, audit).
**B3 — Payment account choice.** `salary/payments` maps `mode` → one fixed account (Cash / UPI / Bank). If a branch has several cash
drawers or bank accounts, add `account` (validated against the branch's accounts and the user's `pay.*` permission) and return the
account balance before/after.
**B4 — Payment correction from the app.** `GET salary/payslip` returns payments without `id`, so the app cannot call
`payroll-pay-void`. Add `id` and `voided_reason` to the payment rows; the app will add "Cancel payment (reason)" behind `payroll.pay`.
**B5 — UTR policy flag.** `f3_require_utr` exists for suppliers; expose `salary_require_reference` in `GET salary/sheet` → `can`/`policy`
so the app can mark UTR as required for Online salary payments.
**B6 — Receipt.** Return `{pay_no, amount, mode, account, date, period, employee, balance_before, balance_after}` from `salary/payments`
(today: `pay_no`, `balance`) so the receipt can be shared / printed.
**B7 — Arrears allocation detail.** `carried_forward` is one number with `cf_source`. For a clear statement add
`arrears: [{period, amount, paid, remaining}]` and allocate payments oldest-first in the response.
**B8 — Final settlement of a leaver.** A `GET salary/settlement?employee_id=` summary (days to exit, arrears, advance owed, penalties,
net payable) so the app can show it on Mark Left. Today the app shows the warning returned by `staff-exit` only.
**B9 — Salary slip in Hindi/English** as a PDF stream (`GET salary/payslip.pdf`, authenticated), for share/print.

## C. Subscription / SaaS
**C1 — Trial length 10 days: NO code change needed.** Super Admin → Settings already has "Free trial days (new restaurants)"
(1–90, audited, `p_setting('trial_days')`, platform default 30). After your approval set it to **10** there (staging first). It applies
to restaurants registered afterwards; existing trials keep their end date (recommended — say if you want existing ones changed, that
is a separate, audited data change). The app shows only the server's `days_left` / `period_end_ist`.
**C2 — Explicit inconsistency flag.** If a tenant has a trial longer than policy (e.g. 365 days), return `trial_policy_mismatch: true`
in `subscriptions/current`; the app will show it to the owner instead of guessing.

## D. Other app gaps that need server support
| # | App screen | Missing on server |
|---|---|---|
| D1 | Stock physical count, transfers between locations | mobile-safe routes exist on the website dispatcher (`count-start/save/approve`, `stock-move transfer`) but need a count snapshot API that returns the lines to count; app keeps them on the website until then |
| D2 | Purchase: photo required at final posting | a server setting `purchase_photo_required` and enforcement in `bill-submit` (the app will then block Submit without a photo) |
| D3 | Bill photo after posting | `purchase-bills/attach` exists; needs `bill_id` + file + permission `purchase.upload` checked for posted bills |
| D4 | Documents: secure preview | `GET files/thumb` returns image bytes; the app needs it with `Authorization` (works) plus `Content-Type` and a size cap |
| D5 | Support tickets | no ticket tables/routes (V34 gap) |
| D6 | Notifications push | FCM server key + `devices/register` already exists; needs credentials |
| D7 | Cash Acess vs drawer variance | already separated by the server (`operational_*` vs `accounting_variance`); a label change "Cash Acess (extra income)" vs "Drawer over/short" needs a server text change in `closing` calc labels |

## Acceptance tests to run after implementing (backend side)
426 for an old version on POST; policy URL validation; payment with `account` from another branch → 403; rejoin before exit → 400;
`include` after `exclude` → line back to pending; trial default 10 for a new registration; existing trials unchanged.
