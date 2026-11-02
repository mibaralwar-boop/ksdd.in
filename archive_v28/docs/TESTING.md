# Executed and pending tests

Runtime used for executed backend tests: locally extracted PHP 8.3.6 with PDO MySQL/mbstring/XML/ZIP/cURL modules; MariaDB 10.11.14; a loopback-only `finance360_test_v28` synthetic database. No production connection or data was used. MariaDB and tests ran in the same isolated command environment. No runtime binaries or database fixture files are distributed.

Executed evidence:

- `audit/baseline_tests.log`: 140 passed, 0 failed. This is the supplied Finance360 regression runner with missing fixture assets restored, the omitted reconciliation SQL replaced with 11 meaningful read-only stock/reference checks, test SQL path corrected and a test-only PDO DSN updated to include db_port.
- `audit/v28_tests.log`: 36 passed, 0 failed. Atomic registration, consent, hashes, membership/branch scope, invalid IDs, branch cap, immediate denies/revocation, exact expiry, token rotation/replay/logout, rate limiting, retained legacy row hashes, TOTP vector/replay, MFA platform checks and scoped trial/suspension audit.
- `audit/http_tests.log`: 17 passed, 0 failed. Real PHP HTTP gateway, method/content/JSON validation, request cap, registration/duplicate signup, context denial, blocked financial API, platform denial and empty-object logout/token revocation.
- `audit/php_lint.json`: 11 changed PHP files passed syntax checks. Lint is separate from functional tests.

The V28 core tests have an explicit fixed local DSN and an exact disposable database-name check. They delete only f28 fixture tables in that dedicated test database. The existing regression runner is destructive: do not point it at production. Test passwords/TOTP seeds in test source are fictional fixtures, not production credentials.

Backend code overlays require the original PHP source. `tests/v17_tests.php` and `tests/v21_oneclick_tests.php` are replacement test files only; legacy business services were not replaced. `migrations/f360_v17_reconcile.sql` is read-only; run all SELECT checks and investigate every returned row.

Not Run / Blocked:

- Financial tenant migration and all-API/file/export/notification isolation under migrated live schema.
- Concurrent registration/branch-cap stress, financial concurrency, full security/performance scans, production financial reconciliation and backup restoration.
- Provider webhooks/payment/OTP/FCM/Drive/AiSensy external validation.
- Flutter dependency resolution, analyze, source tests, widget tests, integration/lifecycle/permission navigation and Android compile/sign/install/AAB validation. Source tests are included but have not been executed.

Automatic review rejected the Flutter version verification after an unexpected sensitive cloud metadata request. No retry, indirect Dart/Gradle command or bypass was used. Source-only Android scaffolding was prepared; this is not a verified build. After an approved external environment is supplied, resolve dependencies and lock them, run source tests/analyze, repair failures, then execute device E2E and owner-key signing. Do not treat the included test source as passing evidence.
