# Finance 360 V34 — production readiness: Go / No-Go

**Decision today: GO for staging (staging.ksdd.in / u245900320_staging). NO-GO for production (ksdd.in /
u245900320_ksdd) until the staging gates below are passed on Hostinger and you approve in writing.**
Nothing has been deployed; no Hostinger database or file was touched from here (no access).

## Test results (one complete local run, 3 Nov 2026 — logs in `docs/evidence/`)
Environment: PHP 8.3.6, MariaDB 10.11, Apache 2.4 with `.htaccess` (as LiteSpeed), Flutter 3.47.9. Demo KSDD data
(14,245 business rows, 40 GL journals). Status words: Passed / Failed / Fixed and retested / Not executed / Blocked.

| Suite | Result | Status |
|---|---|---|
| PHP syntax, every file (`php -l`) | 86/86 | Passed |
| Legacy finance suite, original database (closing, payroll, GL, stock, supplier, imports) | 140/140 | Passed |
| Legacy finance suite on the **converted single database** | 140/140 | Passed |
| Hostile isolation tests (`mt_hostile_tests.php`) | 29/29 | Fixed and retested (V31: 13 failed) |
| Platform suite, single database (registration, trial, expiry, billing, Super Admin, API, cross-tenant) | 64/64 | Passed |
| Adversarial HTTP sweep (3 attackers × 2,967 calls, 42 writes, backup) | 9/9 | Passed |
| Staging isolation check (real-data tool, local run) | 4/4 | Passed locally · **Not executed on staging** |
| Conversion: dry run, convert, 243 totals + GL reconciliation, verify, rollback, row-identical | 12/12 | Fixed and retested (V31: 4 failed) |
| Apache hosting rehearsal (19 sensitive URLs, config/private outside, web registration, sessions, nested staging) | 53/53 | Fixed and retested |
| Android: `flutter analyze` / unit+widget / live API tests against V34 | clean / 22/22 / 11/11 | Passed |
| Android install on a device, camera/gallery, screenshots | — | Not executed (no emulator here) |
| Real SMTP, OTP, Razorpay, Drive, AiSensy, FCM | — | Blocked (credentials) |
| Mobile browser / responsive UI review | — | Not executed |
| Concurrent financial transactions | partly (version 409, idempotent retries, duplicate finalize) | Passed in suites · no load test |

## Android API compatibility
The app (`flutter_source/`, 0.34.0) uses `/api/v1/index.php?__path=…`: auth/login, refresh, logout, profile,
tenants/select, permissions/me, subscriptions/plans, tenants/register, password-forgot, dashboard, closing (+save,
finalize, photos, correction), expenses (+categories), salary/periods, sheet, drafts (preview/save/later), open, submit,
approve, history, departments, employees, suppliers, suppliers/suggest, purchase-bills (+submit), items, stock,
reports/monthly, reports/gl. All are unchanged in V34 and exercised by the 11 live tests against the V34 backend
(single-database mode). No API change was required. Builds: `releases/v34/` (session TEST key — not for Play).

## Gates before production
| Gate | Status |
|---|---|
| G0 config/private outside web root, backup restored | Verified locally · **do on staging** (guide: Backup and restore test) |
| G1 conversion on `u245900320_staging`: verify 0 mismatch, reconciliation 0 differences, rollback rehearsal, old logins | **Pending (you)** — guide Steps 2–4 |
| G2 cross-tenant attack with real data (`staging-isolation-check.php`) | **Pending (you)** — guide Step 5 |
| G3 role / subscription checks by hand (owner, counter, store, salary, Super Admin) | **Pending (you)** — guide Step 6 |
| G4 Android on a real phone against staging (`finance360-0.34.0-staging-TESTKEY.apk`) | **Pending (you)** |
| G5 HTTPS 403 checks on Hostinger for `/config.php`, `/lib.php`, `/platform/mt.php`, `/tools/…`, `/migrations/…` | **Pending (you)** — same URLs as the rehearsal |
| G6 written approval, maintenance window, fresh backup | **Pending (you)** |

No P0 issue is open in the code. P0 items that only Hostinger can prove (G0–G2, G5) are open until run there.

## Remaining blockers and gaps (exact reasons)
1. **Hostinger access** — staging/production can only be installed and verified by you (no hosting access from here).
2. **Database-enforced isolation with restricted identities** — hPanel gives one full-rights MySQL user per database;
   restricted runtime/migration users need `GRANT` (VPS / managed MySQL). Isolation is application-enforced and tested.
3. **Credentials not supplied** — SMTP mailbox, SMS/WhatsApp OTP, Razorpay (and Play billing decision), Google Drive
   OAuth, AiSensy, Firebase (FCM). Code is present; nothing is claimed as working.
4. **Real salary history** — June–September 2026 Excel / bank files are not in this package; September active-staff
   reconciliation (61 vs 62 rows, duplicate names) needs the owner's review on staging.
5. **Missing features** — Super Admin support tickets; employee transfer between branches; backup-health page in Super
   Admin (CLI health check exists); database FK from business rows to `p_branches`.
6. **Android release** — owner upload key, final application id (`com.finance360.app` is provisional), device testing,
   Play listing / Data Safety.
7. **UI** — website screens are V31's (not redesigned in V34); Hindi coverage partial; no browser/device review here.
