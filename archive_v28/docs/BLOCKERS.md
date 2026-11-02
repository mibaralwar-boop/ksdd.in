# Outstanding work and blockers

## Inputs/environment needed

1. Current sanitized MySQL schema export, including all actual SHOW CREATE TABLE outputs, views/triggers and applied legacy/Finance360 migration versions. The source's `schema.sql` alone is an initial schema, not the deployed state.
2. An owner-reviewed branch/employee/legacy-user identity mapping and safely masked staging dataset. September current staff versus historical June–September rows, duplicate HR identities and bank-period mismatch are unresolved. No production HR workbook was reimported.
3. An isolated approved Android build environment. Automatic approval review blocked the Flutter version command after detecting unexpected access to `169.254.169.254`. That command was not retried or indirectly executed. Android checks remain blocked until the separate build environment is approved and configured.
4. Final application ID, owner-controlled upload signing identity, approved staging/prod API URLs, Android SDK/build tools and resolved dependencies. Never put keystore or passwords in shared ZIPs or chat.
5. Approved payment-provider/Play Billing design, provider sandbox credentials, OTP policy/provider, FCM project and tenant integration configuration. No paid checkout is active; no money has been collected.

## Outstanding implementation (not represented as completed)

- Tenant/branch columns, composite financial keys and service/query scopes for canonical `dd_*`/`f3_*` financial tables, legacy archives, uploads, reports, exports, notifications, jobs and integration secrets. Safe backfill, provenance, reconciliation, rollback and restoration drill.
- Mapping existing KSDD identities to new tenant-aware authentication without granting global legacy owner rights to public registrations.
- Native daily closing, optional authenticated daybook photo, Cash Acess, physical cash reconciliation, final lock and correction flows.
- Native four-department six-field payroll wizard, encrypted offline drafts, serialized autosave/ETag, next pending employee, historical salary pagination, salary approvals, partial payments and exports.
- Native supplier-first purchase, suggested prior items, normalized supplier price comparisons, rate confirmation, receipt/stock posting, returns and payments.
- Native employees, attendance, stock, accounting, P&L, consolidated branch reporting, counter sales, handover, search, document inbox and operational modules.
- Complete owner/branch/user administration, all granular permission action mappings and subscription feature enforcement on those business services.
- Complete SaaS admin portal (plans/prices, billing history, renewal/refund/reconciliation, reinstatement policy, support scopes, pagination), provider-signed idempotent webhooks and paid subscription state transitions. The supplied portal implements only metadata listing, trial extension and suspension.
- Logo upload/camera/gallery, tenant contact/branding details, notifications, FCM tokens, backup/Drive/AiSensy tenancy, opt-in and restore tests.
- Complete Hindi localization, accessibility/device/tablet validation, poor-network/lifecycle tests and security/performance review.
- Resolved dependency lockfile, compiled native app, screenshots, signed production APK, signed upload AAB, installation/bundle validation and final privacy/Data Safety/Play listing.

Missing production data is not the only reason the app is incomplete: the financial refactor and native business modules are substantial unfinished implementation work. This package is a tested backend foundation and unvalidated native onboarding source, not the complete requested app.
