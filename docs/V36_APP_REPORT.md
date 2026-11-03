# Finance 360 Android app 0.36.0 — V36 design merge: report

**Status: built and tested here; not installed on a phone here (no emulator in this environment); not published; nothing deployed.**
Staging APK: `releases/v36/finance360-0.36.0-staging-TESTKEY.apk` → `https://staging.ksdd.in/api/v1/index.php` only.

## What was done
The V36 pack (`FINANCE360_FLUTTER_V36_COMPLETE_FRONTEND_FOR_CLAUDE.zip`) is a design prototype with fictional data and no API
calls. It was **merged into the existing working app** (`flutter_source/`), not swapped in:

* Kept unchanged: sign-in, encrypted token storage, rotating refresh, restaurant/branch selection on the server token,
  permission-only navigation, 402/403/409 handling, idempotent POSTs, closing wizard + finalize without admin approval,
  daybook photo, salary six-field wizard with server preview and Save & Next, supplier-first purchase with rate-change
  confirmation, app id `com.finance360.app` (+ `.staging`), signing setup.
* Taken from V36: orange `#EE7418` / cream `#FFF8EC` / brown `#2E2119` tokens, white rounded cards, orange hero summary,
  metric tiles, status pills, empty states, grouped **More** grid, header "Restaurant · Branch" + page title + avatar,
  bottom bar Home · Daily Closing · Sales · Expenses · More (only what the server permits).
* **Not** taken from V36: sample data, the demo role switcher, demo banners, local-only "save" actions. No figure in the app
  is invented; every number comes from the server.
* The old "available on the website" placeholder is gone. A module the app does not know (newer server) shows an honest
  "needs a newer app" page instead.

## Screens (native Flutter, all wired to the real `/api/v1`)
Status: **Verified** = exercised by an executed widget and/or live-API test · **Present** = implemented, compiled, analyzed,
reachable, no dedicated automated test · **Website** = deliberately left on the website · **Blocked** = needs credentials/device.

| Screen | API | Status |
|---|---|---|
| Header, bottom bar, More grid (permission-only) | `permissions/me` | Verified (owner, storekeeper, legacy counter, cash counter; revocation closes an open page) |
| Home (hero, metrics, action center, position, accounts) | `dashboard` | Verified |
| Daily Closing (wizard, finalize, locked view, correction, daybook photo) | `closing*` | Verified (unchanged logic, new look) |
| **Sales history** (period chips/date range, Cash/Card/UPI hero, days, day detail, copy as table if `reports.export`) | `sales` | Verified (widget + live: days add up to totals) |
| Expenses (Add at top, period filter, by category, empty state, long-press void with reason) | `expenses`, `expenses/void` | Verified (empty state) · void Present |
| **Cash book** (any account, period, opening/closing, day groups, running entries) | `cashbook` | Verified (live: closing = opening + movements = account balance) |
| **Accounts** (balances → book) | `accounts` | Verified |
| **Cash handover** (send with idempotency key, accept / part / dispute) | `handover`, `handover/respond` | Verified (live: duplicate key → same handover; second answer refused 409) |
| Staff (Management → UT → Kitchen → Waiter expandable, search, Active/Inactive/All; pay shown only with payroll/staff permission) | `employees` | Verified (order) |
| Salary wizard + periods | `salary/*` | Verified (unchanged) |
| **Salary history** (imported months, totals, search, linked/unverified rows) | `salary/history` | Present (no imported months in demo data) |
| **Staff payments** (advance / penalty / overtime / extra; paid overtime never added to salary again) | `staff-entries`, `advances`, `penalties`, `overtime` | Verified (widget + live: unpaid OT = unpaid; paid OT without mode refused) |
| Purchase (supplier-first, rates) | `suppliers/suggest`, `purchase-bills*` | Verified (unchanged) |
| **Purchase bills** (filters: drafts, review, goods pending, unpaid, overdue; bill detail with lines, photos count, history) | `purchase-bills`, `purchase-bills/one` | Present |
| **Suppliers** (payable/overdue, search, ledger, aging, pay with confirmation) | `suppliers`, `suppliers/one`, `suppliers/pay` | Verified (widget: no post before confirm; live: retry with same key pays once) |
| Stock (hero, low-stock filter, movements, use / wastage / damage / expired / correction) | `stock`, `items`, `stock/moves`, `stock/move` | Present (movement form not covered by a test) |
| **Documents** (private inbox, camera/gallery upload, status filters) | `documents` | Verified (live upload) · camera on device not executed |
| **Notifications** (per-user snooze, correction requests list) | `notifications`, `f360/snooze` | Verified (live) |
| **Subscription** (state, days left, owner payment history; no in-app payment) | `subscriptions/current`, `subscriptions/payments` | Verified (live state) |
| **Users & permissions** (read-only list with permissions) | `f360/users` | Present · editing stays on the website |
| **Profile & settings** (restaurant, branch switch, device, sign out) | `auth/*` | Present |
| Reports | `reports/*` | Present (unchanged) |

Deliberately left on the website (no fake buttons in the app): physical stock count, bill photo add after posting,
correction approval, user/permission editing, plan purchase (Play payment policy), month-end close.

## Tests executed (logs in `docs/v36_evidence/`)
| Check | Result |
|---|---|
| `dart format --set-exit-if-changed` (page width 140) | 0 files to change |
| `flutter analyze` | No issues |
| Unit + widget tests (`test/core_test.dart`, `widgets_test.dart`, `v36_screens_test.dart`) | **36 / 36 passed** (22 existing + 14 new) |
| Layout: Home, Sales, More at 360 dp ×1.0, 360 dp ×1.3 text, 412 dp ×2.0 text | Passed after fixing 3 real overflows found by the test |
| Layout sweep: all 17 More screens opened at 360 dp ×1.3 text | 0 layout errors (`layout_sweep_360dp_1.3x.log`) |
| Live API tests vs the real V34 PHP backend + MariaDB (fresh disposable DB; backend suite 64/64 first) | **20 / 20 passed** (11 existing + 9 new) |
| Live cross-restaurant check: another owner cannot see KSDD's supplier / sales; cash counter gets 403 on cash book, accounts, suppliers, staff entries, users | Passed |
| Staging APK: signature, package, label, API address | `com.finance360.app.staging` 0.36.0 (36), label "Finance 360", only `https://staging.ksdd.in/api/v1/index.php`, no demo strings |

Bugs found and fixed by these tests: list refresh crashed (`setState` returned a Future in `Loader`), overflow of the
home period switch and of list amounts at 360 dp with large text, ListTile ink hidden inside cards.

## Not executed / blockers (exact reasons)
1. **Real phone / emulator** — none available here: install, camera/gallery, back gesture, keyboard, Android 13–16, screenshots.
   Please install the staging APK and check sign-in, closing, a photo and the More screens.
2. **Staging server sign-in** — the app needs staging converted + platform enabled (done by you on Hostinger) and your
   login linked to KSDD there. The isolation check on staging was still running at your last message.
3. **Hindi** — the language switch translates navigation labels only; full Hindi translation of every screen is not done.
4. **Credentials** — FCM push, AiSensy WhatsApp, Google Drive, Razorpay, SMS/WhatsApp OTP: not configured; nothing in the
   app claims they work.
5. **Release signing** — APK uses the session TEST key (not for Play). A Play build needs your upload key, final app id
   decision and Play listing / Data Safety.
6. Missing backend features (unchanged from V34): support tickets, cross-branch employee transfer, Super Admin backup page.
