# Finance 360 V30 on ksdd.in — install, migration, staging and rollback

**One website, one backend, one database.** Everything is served from `https://ksdd.in`:

| Address | What |
|---|---|
| `https://ksdd.in/` | Public website (visitors) / Finance 360 app (signed-in users) |
| `/signin.php`, `/register.php`, `/verify.php`, `/forgot.php` | Sign-in and self-registration (30-day trial) |
| `/owner.php` | Owner portal: branches, users, branding, billing, consolidated report |
| `/platform-admin/` | Super Admin portal (separate login + two-step code) |
| `/api/v1/index.php` | REST API for the Android app (`API_BASE` of the app) |

Database: the existing **`u245900320_ksdd`** holds the existing KSDD tables, the platform tables (`p_*`) and every new
restaurant. No database is ever created per restaurant or branch.

**Nothing in this document may be run on `u245900320_ksdd` or `ksdd.in` without the owner's written approval.**
Every step is first done on the staging database **`u245900320_appksdd`**.

## How isolation works (summary)

* Every business table (`dd_*`, `f3_*`, 129 tables) has `tenant_id` + `branch_id` (`INT NOT NULL`, no default).
* `tenant_scope.php` rewrites every SQL statement on the business connection: reads/updates/deletes get
  `tenant_id = T AND branch_id = B` for **every** table reference (sub-queries, joins, unions); inserts get the two columns.
  T and B come only from the server (verified membership), never from the request. Statements it cannot prove safe are
  **refused** (fail-closed). Platform tables (`p_*`) are accessed only by platform code.
* Keys are per branch: natural/explicit-id primary keys and all unique keys start with `branch_id`.
* Database constraints: every existing parent/child link is `(branch_id, col) → parent(branch_id, id)` (a record can
  never point at another restaurant's record), and every business row `(tenant_id, branch_id) → p_branches(tenant_id, id)`.
* Permissions, subscription state (read-only after expiry → HTTP 402 on writes) and branch membership are checked by the
  backend on every website and app request (`f360/dispatch.php`, `platform/tenancy.php`).
* A restaurant's backup (Google Drive / download) contains only its own rows and never platform tables.

## Folder layout on Hostinger

```
/home/u245900320/domains/ksdd.in/
├── public_html/          ← contents of the ZIP's public_html/ (the website + /api/v1 + /platform-admin)
└── ksdd_private/         ← contents of the ZIP's ksdd_private/  (NOT web-reachable)
    ├── config.php        ← you create it from config.example.php (database password, keys)
    ├── uploads/ backups/ exports/ imports/ logs/ tenants/ platform/ fonts/
```
`public_html/.htaccess` additionally blocks libraries, tools, tests, SQL and documentation from the web. Never put
`config.php` inside `public_html`.

## Phase 0 — backups (before ANY step, on staging and again on production)

1. hPanel → Databases → phpMyAdmin → `u245900320_ksdd` → Export → Custom → all tables, structure + data → save the .sql
   file off the server. Also hPanel → Backups → create a manual backup (files + database).
2. Download the current `public_html` folder (File Manager → compress → download), including `private/` uploads.
3. Record row counts: `SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA='u245900320_ksdd';`

## Phase 1 — staging (`u245900320_appksdd`)

1. Import the Phase 0 export of `u245900320_ksdd` into the empty `u245900320_appksdd` (phpMyAdmin → Import).
2. Create a **temporary** staging address that uses this database, e.g. subdomain `staging.ksdd.in` (hPanel → Subdomains).
   Upload the ZIP there with the same two-folder layout; `ksdd_private/config.php` points at `u245900320_appksdd`, sets
   `platform.base_url` to the staging address and `platform.mail.mode` to `log` while testing. Delete the subdomain after go-live.
3. SSH (hPanel → Advanced → SSH Access), `cd` to the staging `public_html`, then run in this order:

| Step | Command | Must show |
|---|---|---|
| 1 | open the staging site with `platform.enabled => false` | site works exactly as today |
| 2 | `php tools/platform-setup.php migrate` | platform migrations applied |
| 3 | `php tools/platform-adopt-legacy.php` then `… --apply --i-have-a-backup` | "byte-identical" for business tables |
| 4 | `php tools/tenancy-migrate.php` (dry run, read the plan) | 129 tables listed, nothing changed |
| 5 | `php tools/tenancy-migrate.php --apply --i-have-a-backup` | Phase A 129 verified · Phase B FKs · **reconciliation OK** |
| 6 | config: `'platform' => ['enabled' => true, 'provision' => ['mode' => 'shared'], …]` | sign-in with existing passwords works |
| 7 | `php tools/platform-setup.php admin you@ksdd.in "Your Name"` | then sign in at `/platform-admin/`, set up two-step code |
| 8 | `php tools/staging-isolation-check.php --base-url=https://staging.ksdd.in` | `RESULT: … 0 failed` |
| 9 | Android app built with the staging `API_BASE`: sign in as owner, counter, store, salary users | permission-only screens, correct figures |

The migration writes `ksdd_private/logs/tenancy-migrate-<time>.json` (key definitions, fingerprints, checkpoints) and
`…​.md` (reconciliation table: every money/quantity total of ledger, cash book, GL, payroll, supplier, purchase and stock
tables before = after). Keep both with the backup. If the run is interrupted, run step 5 again: it resumes from
`logs/tenancy-migrate-checkpoint.json` and skips tables already converted and verified.

Compare staging reports with the owner: cash in hand, supplier payables, last salary month, stock value on the dashboard
must equal today's live figures.

## Phase 2 — production (only after written approval)

Same steps 1–7 on `u245900320_ksdd`, during closed hours (the migration takes about a minute; writes made between step 5
and step 6 fail safely, so keep the site in maintenance for those minutes). Then:

* cron (hPanel → Advanced → Cron Jobs, every 15 minutes): `/usr/bin/php /home/u245900320/domains/ksdd.in/public_html/platform-cron.php`
* HTTPS: uncomment the HSTS line in `public_html/.htaccess` once SSL is confirmed.
* Keep `signup_enabled` false until you decide to open self-registration (then also Super Admin → Settings).
* Payments stay disabled until Razorpay keys, prices and Play policy are approved.

## Rollback

| Situation | Action |
|---|---|
| Before step 5 | Restore the old `public_html` files; config back inside the old location. Database unchanged except platform tables (harmless). |
| After step 5, no other restaurant has data yet | `php tools/tenancy-migrate.php --rollback=ksdd_private/logs/tenancy-migrate-<time>.json --i-have-a-backup` — restores columns, keys and original foreign keys; verifies every table's content fingerprint equals the pre-migration value. Then set `platform.enabled => false`. |
| After other restaurants have data | The rollback tool refuses. Restore the Phase 0 backup into a new database, or keep V30 and fix forward. |

Verified locally: migration + rollback on a copy of the demo KSDD database reproduces every data row exactly.

## Local test evidence (see `docs/TEST_REPORT_V30.md`)

`public_html/tests/run_all_v30.sh` (needs a local MariaDB test user) runs all suites; the tests folder is not part of the
production upload.
