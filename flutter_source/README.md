# Finance 360 Android app (Flutter) — v0.29.0

Native Flutter / Material 3 app for the **Finance 360 V29** multi-restaurant PHP/MySQL backend in `../backend_v29/public_html`.
No WebView. No second database: every figure is read from and written to the restaurant's own branch ledger through the
same server functions the website uses (`/api/v1` → `f3_dispatch`).

## What the app does (implemented and tested)

| Area | Behaviour |
|---|---|
| Sign-in / session | Email + password → 15-min access token + rotating 30-day refresh token bound to this device id (stored with Android encrypted storage). Parallel 401s cause exactly one refresh; a revoked/replayed refresh signs out. |
| Restaurant + branch | Only explicit memberships from the server. One restaurant with one branch opens directly. The context is held on the **server token** (`POST tenants/select`); finance calls never send tenant/branch ids. |
| Permission-only UI | Modules come only from `permissions.menus` (+ `dashboard.view`). One module → single screen, no bottom bar (Cash Counter = Daily Closing only). 2–5 → bottom bar. More → 4 + "More". Permissions re-read on resume, on the server's refresh interval and after a 403 (revoked access returns to the chooser). |
| Subscription | Trial days-left banner; read-only banner when expired/suspended (server returns 402 on writes; reads continue). No payment links in the app (Play policy, backend `allow_app_checkout` off). |
| Daily closing | Cash / Card / UPI → automatic total → expense review ("reviewed" / "No expenses") → Actual Cash with optional note calculator → live Cash Acess / Cash Short → review → **Finalize & lock without admin approval**. Server version check (409 → reload). Finalize retries reuse the same Idempotency-Key. Locked day: summary + correction request. |
| Daybook photo | Optional, camera or gallery, compressed to ≤1600 px / ≤1.5 MB, uploaded to the server's private storage; failure never affects the closing. |
| Salary | Periods → sheet grouped Management → UT → Kitchen → Waiter, by designation, with Pending/Draft/Completed and Start/Resume/Review. Six inputs: Present, Absent (auto if empty), Half days, Paid leave, Advance recovery, approved Penalties. Debounced **server** preview (stale replies ignored). Save draft / Save & Next (opens the next pending employee in the same department) / Add later. Errors keep the user on the same employee. Submit / approve when permitted. Imported old salary months (read-only, unverified rows flagged). |
| Purchase | Supplier first → ledger balance + last bill + last-delivered items with last rate prefilled; user fills quantity; Old → New rate and % shown; server 409 `rates_ok` → confirmation dialog → resubmit; duplicate invoice / credit-limit confirmations; optional bill photo; optional payment (otherwise on credit). No GST fields. |
| Expenses | List + add (category, amount, Cash/Bank/UPI/Card, note, optional receipt photo; duplicate warning). |
| Stock / Staff / Reports | Stock value, reorder list, items; staff by department with search and status; monthly P&L (cash & purchases view, with the server's own basis note), items not in P&L (Cash Acess, transfers), expenses by category, GL P&L + balance-sheet check. |

Modules the server allows but the app does not implement yet (cash book, handover, suppliers ledger, documents, accounts,
settings) show an honest "use the website" page — never a fake screen.

## Configure and build

`API_BASE` = full HTTPS URL of the server's `api/v1/index.php` (e.g. `https://YOUR_DOMAIN/YOUR_FOLDER/api/v1/index.php`).
The backend must be in multi-restaurant mode (`platform` block in `config.php`) — see `../backend_v29/docs/`.

```bash
flutter pub get && flutter analyze && flutter test
# owner-controlled signing (never commit these):
export F360_STORE_FILE=/secure/upload.jks F360_STORE_PASSWORD=… F360_KEY_ALIAS=… F360_KEY_PASSWORD=…
flutter build apk       --release --flavor production --dart-define=API_BASE=https://YOUR_DOMAIN/…/api/v1/index.php
flutter build appbundle --release --flavor production --dart-define=API_BASE=https://YOUR_DOMAIN/…/api/v1/index.php
```

Release tasks fail without the four signing variables (no debug-key fallback). Plain HTTP is refused except for
loopback/emulator hosts in debug builds with `--dart-define=ALLOW_LOCAL_HTTP=true`.

## Tests

* `flutter test` — 22 unit + widget tests (money/paise, permission-only navigation from real server permission
  fixtures, token refresh/rotation, closing wizard + idempotent finalize, salary six-field Save & Next, purchase
  rate-change confirmation). Fixtures in `test/fixtures` are real responses captured from the V29 backend with demo data.
* `tool/live_test.sh` — 11 live tests of the app's API client against the **real V29 PHP backend + MariaDB**
  (disposable `*_test` databases rebuilt by the backend's own 64-test platform suite). See `../docs/APP_TEST_REPORT.md`.
