# Finance 360 V28 — implemented SaaS foundation, incomplete application

**Status: staging foundation, not a production release.** This package does not satisfy the complete V28 acceptance criteria. It must not be deployed as the finished Finance 360 product.

The complete V28 document in the newly attached prompt ZIP was read and compared byte-for-byte with the earlier attachment: the specifications are identical. The newly attached ZIP contains a prompt and START_HERE only; it does not supply newer PHP source or a current database export. Work therefore continued from the previously identified `public_html (1)(3).zip` Finance 360 source.

## Actual implementation

- Additive SaaS metadata in the **existing MySQL database**, using the application's existing `db()` connection; no second financial database or duplicate mobile ledger.
- Atomic restaurant/account/first-branch registration with privacy consent and an exact server-controlled default 30-calendar-day trial.
- Honest `manual_pending` identity verification when no OTP provider is configured; unverified accounts cannot perform management mutations.
- Hashed passwords, hashed bearer tokens, 15-minute access tokens, 30-day rotating refresh tokens, refresh replay family revocation, logout, login/registration/MFA rate limits and noncached JSON responses.
- Explicit restaurant/branch membership, branch composite foreign keys, backend permission intersection/denials, trial expiration with read access preserved, scope rejection and branch-limit enforcement.
- Audited manual identity verification, account deletion-review requests, restaurant name/language updates and branch creation.
- Dedicated Super Admin metadata portal with explicitly provisioned platform membership, encrypted TOTP secret, replay-resistant MFA, five-minute step-up, tenant listing, audited trial extensions and suspension. No financial impersonation or paid activation bypass.
- Native Material 3 Flutter **source** for login, four-stage restaurant registration, authorized restaurant/branch selection, server permission-based navigation, branch metadata, restaurant identity, trial status, Hindi/English core labels, secure token storage, sign-out and deletion requests. Android scaffolding, staging/production flavors and release-signing guard are included. No WebView.

**Financial APIs intentionally remain blocked.** The old Finance 360 financial tables and single-restaurant services have not been converted to tenant-safe financial operations. New restaurants cannot read or write those global financial records. The Flutter source currently contains no salary, closing, purchase, stock or accounting screens. Those requirements are outstanding, not fulfilled by the existing web application or by passing its tests. The initial KSDD account/employee mapping has not been migrated.

## Executed verification

| Suite | Result | Scope |
|---|---:|---|
| Existing V16/V17/V19/V21 regression runner | 140 passed, 0 failed | Isolated synthetic MariaDB fixture, existing financial services |
| New V28 PHP/MySQL tests | 36 passed, 0 failed | SaaS metadata/auth/scope/expiry/platform foundation |
| Real local HTTP API tests | 17 passed, 0 failed | Request handling, auth/scope, registration, logout, blocked financial API |
| Changed PHP syntax checks | 11 files passed | Syntax only |
| Flutter analyze, unit/widget/integration | **Blocked / Not Run** | No Flutter correctness claim |
| APK/AAB build, signature, install | **Blocked / Not Run** | No Android binaries delivered |
| Production data reconciliation/restore | **Not Run** | No current database snapshot supplied |

The legacy runner initially failed on missing fixture assets, an omitted `migrations/f360_v17_reconcile.sql`, and a test-only PDO DSN that omitted the configured database port. Fixture assets were restored from the supplied archive, a new 11-query read-only reconciliation file was implemented, and test paths/port handling were corrected. No legacy financial PHP business service was changed. See `docs/TESTING.md` and the actual logs. Passing tests are not a full V28 acceptance claim.

## Android build boundary

Flutter SDK download completed and its published SHA-256 matched, but automatic approval review rejected the attempted `flutter --version` verification because it unexpectedly attempted access to sensitive cloud metadata address `169.254.169.254`. Flutter was not retried, and no Dart/Gradle execution was used to work around that rejection. Android source was written without executing those tools. A separate owner-approved build environment is required for compilation and testing.

There is no `pubspec.lock`: dependency resolution was not run. There are no signed APK/AAB, app screenshots, or Play-ready release. No keys or credentials have been fabricated or bundled. The application ID `com.finance360.app` is provisional and requires owner confirmation before release.

## Package layout

- `backend_patch/`: new PHP/API/portal/schema/migration/test changes only. Overlay into a reviewed **staging copy**, never directly into production.
- `flutter_source/`: native Flutter/Android source, tests and secure configuration instructions. Not analyzed or built here.
- `audit/`: prior source evidence, actual test logs, PHP lint evidence and requirement status.
- `docs/`: exact API contract, migration/rollback notes, release blockers and privacy/listing drafts.

Read `docs/BLOCKERS.md` and `audit/FEATURE_MATRIX.csv` before using any part of this package. Production import, deployment, payment enablement and publication require separate approval and passing the complete V28 gates.
