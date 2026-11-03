# Finance 360 Android 0.38.0 (V38) — report

**App only.** No PHP, MySQL, Hostinger file, live website or database was changed. Nothing deployed or published.
Staging APK: `releases/v38/finance360-0.38.0-staging-TESTKEY.apk` (app id `com.finance360.app.staging`, versionCode 38,
label "Finance 360", API `https://staging.ksdd.in/api/v1/index.php` only, session TEST key — not for Play).

## Key finding of the audit
The V34 backend already supports most of the V38 salary rules, so they are **real, not placeholders**:
previous balances (arrears vs advance vs penalties) in `salary/drafts`, partial salary payments with mode + amount + reference in
`salary/payments` (amount > 0 and ≤ due enforced), Add Staff with duplicate-code check, Mark Left with date + reason, Rejoin,
roster refresh for mid-month join/exit, Exclude-from-month. The free-trial length is already editable in Super Admin → Settings.
What the server lacks is listed in `BACKEND_CHANGE_REQUESTS_LATER.md` (force-update policy + enforcement, rejoin date, undo exclude,
payment account choice, payment cancel from app, receipts/PDF slip, settlement summary, stock count API, support tickets).

## What changed in the app (all on top of 0.36; every existing feature kept)
| File | Change |
|---|---|
| `lib/screens/salary.dart` | Rewritten wizard: status tabs with counts, department filter, Next pending / Pay next / Add staff, row actions (pay, payments & slip, exclude, mark left); employee page with Previous Balances panel, Present-first attendance, folded adjustments, validation, server calculation, Previous / Save draft / Save & Next / Add later |
| `lib/screens/salary_pay.dart` (new) | Record Salary Payment: Cash / Online (UPI / Bank) + amount mandatory, partial, confirmation, idempotent retry, receipt, payment history, auto-open next payable |
| `lib/screens/staff.dart` | Add / edit staff form, staff profile with balances, Mark Left (date + reason), Rejoin |
| `lib/core/update_policy.dart`, `lib/screens/update.dart` (new) | Force-update client: policy parsing, integer comparison, trusted-destination rules, encrypted cache with TTL, checker, controller, Update Required page, optional banner |
| `lib/main.dart` | Update gate around the whole app, version headers, language choice persisted |
| `lib/core/api.dart` | Local write block while an update is required; `X-App-*` headers; public GET with query |
| `lib/screens/shell.dart` | Optional-update banner; Subscription only for owner / user managers |
| `android/app/src/main/AndroidManifest.xml` | `<queries>` for `market://` and `https://` so Update can open Play / browser |
| `pubspec.yaml` | 0.38.0+38; `package_info_plus`, `url_launcher` |
| tests | `test/v38_test.dart` (new, 19 tests), `widgets_test.dart` updated for the new salary labels, `v36_screens_test.dart` (+Subscription hidden), `test/live/live_api_test.dart` (+3 V38 live tests); fixtures `*_v38.json` captured from the real backend (demo data) |

## Tests executed (logs in `evidence/`)
| Check | Result |
|---|---|
| `flutter analyze` | No issues |
| `dart format --set-exit-if-changed lib test` | 0 changed |
| Unit + widget tests (core, widgets, V36, V38) | **55 / 55 passed** |
| Layout tests: salary line / sheet / payment at 320 dp ×1.0, 360 dp ×1.3, 412 dp ×2.0 | passed (2 real overflows found and fixed) |
| Live API tests vs real V34 PHP + MariaDB (fresh disposable DB, backend suite 64/64 first) | **23 / 23 passed** (incl. 3 new V38: add staff + duplicate + mid-month roster; mark left + rejoin; full month enter → submit → approve → refused ₹0 / over-due → part pay Cash with a retried key counted once → pay rest UPI → Paid → payments listed) |
| Staging APK signature / id / label / API host | verified |

## Status of every requirement
See `APP_FEATURE_MATRIX.csv` (DONE & TESTED / IMPLEMENTED NOT TESTED / CLIENT READY – SERVER BLOCKED / NOT DONE, with reasons).
Not done in this task: full Hindi ARB translation of every screen; stock physical count / transfers in the app; support tickets;
device testing (no phone/emulator here).

## What you can do now (no code change)
* Install the staging APK on your phone and run one salary month on **staging**: enter Present days, check the Previous balances panel,
  approve, record a part payment in Cash and the rest Online.
* Super Admin (staging) → Settings → Free trial days = **10** (affects new restaurants).
* Approve (or not) the backend items in `BACKEND_CHANGE_REQUESTS_LATER.md`; force update becomes active once A1 is deployed and
  becomes a real security control only with A3.
