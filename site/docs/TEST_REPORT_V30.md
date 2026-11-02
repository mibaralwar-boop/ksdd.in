# Finance 360 V30 — test report (single site ksdd.in, one shared database)

Date 2 Nov 2026 · Local staging: PHP 8.3.6, MariaDB 10.11, Apache 2.4 (with .htaccess, as LiteSpeed on Hostinger),
Flutter 3.47.9. Statuses: Passed / Failed / Blocked / Not run. Lint is not counted as a test.

| Suite | Result | What it proves |
|---|---|---|
| `tests/tenant_scope_tests.php` | **40 passed, 0 failed** | SQL scoping of SELECT/UPDATE/DELETE/INSERT (joins, LEFT JOIN, sub-queries, UNION, INSERT…SELECT, strings/comments), fail-closed refusals, per-branch keys, composite FKs; rewritten SQL accepted by MariaDB; `OR 1=1` cannot reach another branch |
| Captured SQL corpus | **2,039 / 2,039 scoped, 1,476 / 1,476 DML prepared OK** | Every distinct statement the application executed during all test suites (MariaDB general log) is scoped and valid on the migrated schema |
| Legacy finance suite (`tests/run_tests.php`), shared DB | **140 passed, 0 failed** | All V16–V23 money rules (closing, payroll, GL, stock, supplier, imports) through the tenant-scoped connection |
| Legacy finance suite, original single DB | **140 passed, 0 failed** | No regression for the current single-restaurant mode |
| Platform suite (`tests/platform_tests.php`), shared DB | **64 passed, 0 failed** | Registration, 30-day trial, read-only expiry, signed webhooks, tokens, TOTP, support access, direct closing, photos, supplier-first purchase, hostile cross-tenant tests, every row tagged with a real tenant/branch |
| Platform suite, V29 database-per-branch | **64 passed, 0 failed** | Backward compatibility |
| `tests/isolation_sweep.php` | **9 passed, 0 failed** | 3 attacker accounts × 2,881 GET calls over all 67 routes with KSDD record ids + secret marker; 42 write attempts; KSDD rows byte-identical; restaurant backup contains no KSDD rows / platform tables; controls prove the detector works |
| `tools/staging-isolation-check.php` (local run) | **4 passed, 0 failed** | Same attack with REAL KSDD names/ids from two freshly registered restaurants; non-destructive; for the staging database |
| `tests/rollout_rehearsal.sh` (Apache, production folder layout) | **34 passed, 0 failed** | config outside public_html; 9 sensitive paths 403; adoption byte-identical; migration + reconciliation; old passwords work; new restaurant registers, verifies, gets 30-day trial and its own 4 departments in the SAME database; cannot open KSDD; 129 tables bound to real branches; no extra databases |
| Migration rollback | **Passed** | Data rows identical to the pre-migration dump; original foreign keys restored (only the listing order of 5 unique keys differs) |
| DB constraint checks | **Passed** | Rows for a non-existent branch or a wrong tenant/branch pair, and cross-branch parent/child links, are rejected by MariaDB itself |
| Flutter `flutter analyze` / `flutter test` | **No issues / 22 passed** | App logic and screens on captured real responses |
| Flutter live API tests vs V30 shared backend | **11 passed, 0 failed** | The app's API client against the real backend: counter closing final without approval, photo, refresh rotation, salary, purchase rate confirmation, read-only 402, cross-restaurant 403 |
| Release APK / AAB 0.30.0 | **Built, signatures verified** | Signed with a session TEST key (not the Play upload key); `API_BASE=https://ksdd.in/api/v1/index.php` |

## Defects found and fixed during V30

1. Explicit-id primary keys (e.g. `f3_departments`) were global → a second restaurant got **no payroll departments**. Now per branch.
2. `P_ALL_MODULES` undefined during web registration (latent V29 bug, hidden because tests loaded `schema.php`) → every
   self-registration would have failed at provisioning.
3. Drive/scheduled backup dumped all tables → in one database it would have included every restaurant's users. Now only
   the branch's own business rows.
4. Scoper ordering for `LEFT JOIN … ON … GROUP BY` without WHERE; tables created after the connection opened.

## Not run / blocked

* Staging run on Hostinger (`u245900320_appksdd`) — **Blocked**: no access from this environment; use
  `docs/HOSTING_KSDD_IN.md` Phase 1 (all tools included) and send the reports.
* Production (`u245900320_ksdd`, ksdd.in) — **Not run**: needs your written approval.
* Android device/emulator install, screenshots, Play Console upload — **Not run** (no emulator here; no upload key).
* Real SMTP, Razorpay, OTP, FCM, Google Drive, AiSensy — **Blocked** on credentials.
