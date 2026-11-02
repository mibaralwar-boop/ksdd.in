# Staging installation and migration boundaries

Do not deploy this foundation to production. The patch is disabled unless the existing protected configuration explicitly enables `f28_enabled`. The owner has not approved production changes.

## Before staging

- Obtain a current schema export and a masked restore backup, preserve encryption keys, verify recovery on an isolated instance, and review the existing audit.
- Use dedicated credentials limited to the staging database. A production name containing `test` is not sufficient protection for the existing destructive test runner.
- Back up current application files and database. The existing `config.php`, private HR uploads and signing material are excluded from this package.

## Applying SaaS metadata to staging

Overlay `backend_patch/` onto the staging PHP source. Existing `lib.php` must be present. Keep the staging database connection in the application's existing protected config; the patch does not define a second database connection.

Review the dry run:

```bash
php tools/f28-migrate.php --expected-db=YOUR_EXACT_STAGING_DB
```

After reviewing this specific staging target and backup, apply the additive metadata tables:

```bash
php tools/f28-migrate.php --expected-db=YOUR_EXACT_STAGING_DB --apply-additive-schema
```

MySQL DDL commits implicitly; this is not an all-or-nothing financial migration. Every table uses `CREATE TABLE IF NOT EXISTS`, a migration lock serializes runs, and version completion is recorded after creation. The migration changes no legacy table and performs no employee/finance backfill. Existing table-name collisions or divergent preexisting f28 definitions require manual schema review; do not assume IF NOT EXISTS validates table shape.

Enable staging flags from `backend_patch/f28-config.example.txt`. Keep local HTTP disabled except on a loopback-only synthetic test server. Production requires HTTPS. Public identity verification remains `manual_pending` unless an administrator verifies evidence; there is no fake OTP success.

Owner-authorized manual verification is a local CLI operation with a recorded evidence reference:

```bash
php tools/f28-verify-account.php --expected-db=YOUR_EXACT_STAGING_DB --account=ACCOUNT_ID --evidence='Reviewed evidence reference'
```

The CLI assumes a trusted server operator. Do not expose it over HTTP. Platform enrollment additionally requires explicit CLI acknowledgement and an owner-held Base32 TOTP secret supplied through the process environment; see the script's instructions. Do not place the secret in shell history or the source package. Access `f28-platform.php` only over the same HTTPS origin as the API. Sign-in tokens remain in browser memory.

## Financial migration is a separate unfinished gate

All new tenants have `financial_scope_state=blocked`; no public API can unlock it. Do not manually change it to `ready`: no tenant-safe financial implementation exists yet. The financial-status endpoint always returns 503, including if someone alters that metadata field. Nothing in this package scopes the existing legacy financial APIs. Do not add f28 identities to global `dd_users` as owners or route their tokens into legacy browser sessions.

Before financial enablement: review all table/FK paths and global unique keys, backfill verified KSDD tenant/branch ownership, refactor and test all readers/writers, migrate file access and jobs, reconcile financial totals, and execute adversarial isolation tests. Never add only tenant columns and enable registration into global finance services.

## Rollback

Disable `f28_enabled` and remove/disable the new API/portal routes; retain f28 tables and audit records for review. Since this patch does not modify legacy finance tables, its metadata layer can be disabled without rewriting the canonical ledger. Do not drop newly registered accounts or trial history as a routine rollback. Recover a pre-migration backup only in isolated staging and compare existing financial records. Production recovery after new financial posting needs a separate reconciled plan; no such rollout or restore drill has been executed here.
