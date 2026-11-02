# V28 source-evidenced audit

This is a static audit of source and declared schema. It is not an audit of current production database contents, a penetration test, or an executed migration. Line references refer to the supplied source archive. Findings below distinguish confirmed structural gaps from runtime behavior requiring tests.

## Critical structural gaps

| Finding | Source evidence | Required implementation |
|---|---|---|
| Date-only global closing identity | `f360/schema.php:24` declares closing fields; `uq_close_day(business_date)` in migration 1. `f360/closing.php:21` retrieves solely by date. | Scope by authenticated tenant and authorized branch; use `(tenant_id,branch_id,business_date)` uniqueness. Update events, notifications, Books and idempotency keys together. |
| Payroll period is global | `f360/schema.php`, migration 1, declares `f3_pay_periods(period PRIMARY KEY)` and pay-line uniqueness on `(period,employee_id)`; `f360/payroll.php:88` queries period alone. | Define branch payroll ownership and employee transfer rules, preserve employee IDs and frozen historical calculations, migrate keys and every related payment/reference. |
| Access grants are global user records | `f360/core.php:127` (`f3_template_of`) and `:132` (`f3_perms`) query `f3_user_access` by user. Owner role receives all enabled permissions, cache keyed only by user. | Introduce tenant/branch membership and role context, explicit deny precedence, entitlement intersection and scoped permission cache invalidation. Owner of A must never imply owner of B. |
| Shared SQL helpers have no tenant context | `lib.php:41–43`, `run`, `rows`, `row`: prepare and execute supplied SQL. | Prepared SQL mitigates injection but does not add isolation. Refactor service queries explicitly with an authenticated context; do not regex-rewrite SQL or use a user-supplied tenant ID as authority. |
| App auth/session boundary missing | `f360-api.php:17–35`: existing browser session, POST CSRF and `user()`; `:40–47` returns user, global restaurant setting, permissions. | Keep browser CSRF. Add versioned mobile access/refresh rotation, hashed token storage, revocation and membership revalidation. Petpooja `sync.php:10` Bearer token is integration authentication, not mobile user login. |
| Integration secrets keyed by name | `f360/integrations.php:44–49`, `f3_secret_get/set`; global Drive queue reads at `:353`. | Tenant-scoped encrypted credentials, queue/job scope, credential-independent backup recovery, and authorized file/folder mapping. Keep existing encryption key recovery intact. |
| No SaaS tenant/branch/subscription infrastructure found in PHP/SQL search | Source-wide search for `tenant_id`, `branch_id`, `subscription` returned no implementation hits. Runtime auth/refresh matches are unrelated integration OAuth. | Registration transaction, verified identities, plan versions, trial clock, state transitions, payment event log, entitlement middleware, limits and audited platform administration. |

Exact declaration/function locations are machine indexed in `evidence/source_inventory.json`. SQL scope inventory includes queries in tests and migrations; lack of a scope marker on one line is a review signal, not an automatically proven exploitable endpoint.

## Preserve these existing services

**One financial ledger.** `f360/core.php:3–7` documents `dd_bk_*` as the existing canonical cash/bank book; `f3_bk_post` at `:227` bridges Finance 360 movements. Payroll/Books/GL/legacy record paths coexist. Migrations must not create a second mobile-only ledger. Reconcile source events into `dd_bk_txns` and GL before/after tenancy changes; retain conflict/held posting behavior rather than forcing duplicates.

**Closing is already directly effective.** `f360/closing.php:120–148`, `f3_closing_finalize`, checks permission, date guard, version and validation, posts tender sales unless conflicting legacy sales exist, writes `submitted`, and locks the day. It does not wait for routine approval to post normal sales. Review at `:151` classifies accounting variance and resolves held legacy sales. V28 UI should clearly distinguish finalized closing from outstanding variance review. Do not globally rename statuses without auditing dependent reports/tests. Sale idempotency keys currently omit tenant/branch, so date keys must be migrated safely and remain traceable.

**Cash variance distinctions.** `f360/closing.php:45`, `f3_closing_calc`, keeps operational difference separate from physical drawer variance. Card/UPI do not belong in physical cash. Preserve the exact `Cash Acess` label and avoid treating automatic positive variance as separate income. Optional authenticated closing-specific daybook-photo association was not established in the inspected closing path; generic uploads/document inbox do not prove this requirement works.

**V19 calendar payroll already exists.** `f360/payroll.php:61`, `f3_pay_calc_v19`, calculates paid equivalent using present, paid leave, half days and policy-based rest; `lib360.php:36` uses integer paise proration. Overtime is returned as reference. `f360/payroll.php:251`, `f3_line_save_v19`, currently permits absent to be derived and handles rest days beyond the six main fields. V28 needs an explicit UI/API reconciliation for its six inputs while preserving previously approved months and server authority. Add negative input, half-day, deduction provenance, concurrency and payment tests rather than replacing this calculator with Flutter arithmetic.

**Salary history exists but needs tenancy/pagination.** `f360/emp_import.php:553`, `ei_history_view`, exposes period and employee selectors over `f3_sal_history`, marks copied rows as not used for payroll/payment/cashbook, and does not filter out inactive employees in the inspected query. It currently has no tenant/branch scoping and returns unpaginated matching rows. Source existence is not evidence that September production imports or identity matches are correct.

**Purchases and stock exist.** `f360/purchase.php:74`, supplier detail; `:171`, bill save; `:209`, bill get; `:317–318`, receipt. `f360/v23.php:114` has price history and `:129` price alerts. Existing supplier balances, bill attachments, stock ledger and returns should be adapted. Supplier-specific auto-suggest, normalized pack comparison, rate-change confirmation and update-only-on-posting require end-to-end verification. No claim is made that these V28 behaviors are complete.

**Reports and controlled files exist.** `f360/money.php:130` routes GL views. `f360/admin.php:221` (`f3_file_ok`) and `:231` (`f3_file`) authorize files in the current application; `f360/stock.php:305` serves thumbnails. All need explicit tenant and branch ownership checks, including photo derivatives, PDF/Excel exports, caches and integration uploads. Existing permission checks alone do not establish tenancy.

## Migration dependency map

Use the same existing PHP/MySQL database. Add tenant organizational metadata and membership; business records remain canonical in their existing financial tables. Audit all 128 detected table declarations, including legacy `dd_*` paths, before enabling a second tenant.

1. Obtain current `SHOW CREATE TABLE` and migration state with read-only staging credentials. Inventory triggers, views, FKs, indexes and storage references. Code schema declarations cannot prove live state.
2. Restore a masked backup into isolated staging and establish counts, employee IDs, historical period totals, supplier balances, stock quantities/values, trial balance, cash/bank and upload references.
3. Agree the initial Khadak Singh Da Dhaba tenant/branch mapping. Unknown identities stay flagged; do not backfill employee join dates or assume September duplicates are the same person.
4. Create tenant/branch/membership/subscription metadata in the same DB. Add nullable scope during a controlled migration window, backfill using the verified mapping, and validate ownership coverage. MySQL DDL has implicit commits: a PHP transaction is not a universal rollback for schema changes.
5. Apply scope to parents/children, scoped unique constraints and composite FKs where compatible. Plan replacements for date/period PKs and all dependent references, source keys, integration keys and historical idempotency records. Keep immutable financial identifiers stable.
6. Refactor all readers/writers, direct SQL in API switches, jobs, legacy archives, sync, exports, files and search. Mixed old/new deployment cannot safely accept additional tenants. Fail closed until an audited migration flag and complete route coverage are satisfied.
7. Test tenant A/B with deliberate duplicate names/dates/periods, unauthorized IDs, branch revocation, expired subscriptions and cache/device switches. Only then enable registration and mobile integration.
8. Reconcile unchanged legacy totals after conversion. Rehearse rollback by restoring the pre-cutover backup in staging. A rollback after new financial postings requires a recovery plan preserving those postings; never blindly drop tenant columns or rewind history.

No executable migration is provided because the live constraint map/backfill mapping and runnable staging environment are unavailable. A speculative ALTER script is not a safe tested migration.

## Test constraints and release boundaries

`tests/run_tests.php:9` invokes `tests/staging_setup.php`; `tests/staging_setup.php:11–16` accepts a database name containing `test` or `staging` then drops tables. Those scripts must only run with credentials restricted to an explicitly provisioned disposable schema. A production database name containing one of those strings is not sufficient protection. They were not executed here.

Release ZIPs must exclude real `config.php`, private imported workbooks, photos, bank details, log files, encryption/signing keys and credentials. The original archive contains `config.php` and private uploads/imports; this audit deliberately does not redistribute it. Presence is a packaging concern; no secret values were printed or copied into this audit package.

Flutter source, UI/widget/integration tests, signed artifacts, package ID, app screenshots, OpenAPI for newly implemented routes, provider webhooks and Play documentation remain undelivered. Existing API route CSV documents source cases only and is not an invented mobile API contract.
