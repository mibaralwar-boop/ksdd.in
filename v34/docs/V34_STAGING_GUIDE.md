# Finance 360 V34 — Hostinger staging guide (staging.ksdd.in · u245900320_staging)

This guide matches **this package only** (V34 = your V31 source + V34 fixes). It does not use any V29/V30 command
(`tenancy-migrate.php`, `platform-adopt-legacy.php`, database pools — none of them exist here).
**Nothing in this guide touches `ksdd.in` or `u245900320_ksdd`** except reading/exporting a backup in Step 0.

| Package folder | Goes to (Hostinger) |
|---|---|
| `public_html/` | `/home/u245900320/domains/ksdd.in/public_html/staging/` (document root of staging.ksdd.in) |
| `ksdd_private/` | `/home/u245900320/domains/ksdd.in/staging_private/` (outside every web root) |
| `tests/`, `docs/` | not uploaded (kept with you; `tests/` runs on a local/CI MariaDB) |

The same `public_html/` + `ksdd_private/` pair is later used for production as
`domains/ksdd.in/public_html/` + `domains/ksdd.in/ksdd_private/` — only after your written approval.

## Step 0 — backups (read-only on production)
1. hPanel → Databases → phpMyAdmin → `u245900320_ksdd` → **Export** → Custom → all tables, structure + data, `utf8mb4`
   → save `ksdd-YYYYMMDD.sql` on your computer.
2. hPanel → Files → File Manager → `public_html/private/` (uploads, payroll files) → **Compress** → download.
3. Note the row counts: phpMyAdmin → SQL on `u245900320_ksdd`:
   `SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY 1;`

## Step 1 — staging database and site
1. phpMyAdmin → `u245900320_staging` → **Import** `ksdd-YYYYMMDD.sql` (the database must be empty first).
2. hPanel → Domains → Subdomains → `staging.ksdd.in`, folder `public_html/staging`; enable SSL for it.
3. File Manager: upload the ZIP, extract, move the **contents** of `public_html/` into `public_html/staging/`, and
   create `/home/u245900320/domains/ksdd.in/staging_private/` with the contents of `ksdd_private/`.
4. In `staging_private/` copy `config.staging.example.php` → `config.php` and fill in: the staging database user and
   password, **the same `setup_key` and `pii_key` as the live config** (otherwise stored bank/PAN data cannot be read),
   a new `platform.key`. Keep `environment => 'staging'`, `mail => log`, billing `disabled`, `single_db => false` for now.
5. Append to `public_html/staging/.htaccess` (tells the staging site where its private folder is, and keeps it private):
   ```
   SetEnv F360_PRIVATE_DIR /home/u245900320/domains/ksdd.in/staging_private
   # Staging is not public: allow only your own IP address(es) (find yours at https://ifconfig.me)
   <RequireAny>
     Require ip YOUR.PUBLIC.IP.ADDRESS
   </RequireAny>
   ```
   (Password protection in hPanel also works for browsers, but it uses the same `Authorization` header as the app's
   tokens, so use the IP rule while testing the Android app.)
6. Copy the downloaded `private/uploads/` into `staging_private/uploads/` (documents and photos for testing).

## Step 2 — SSH commands (hPanel → Advanced → SSH Access)
```bash
cd ~/domains/ksdd.in/public_html/staging
export F360_PRIVATE_DIR=~/domains/ksdd.in/staging_private
php tools/health-check.php                          # expect: config + private storage OUTSIDE the web root; WARN "not converted yet"
php tools/v31-convert.php                           # DRY RUN: preflight, row counts, "review:" lines, reconciliation baseline — writes nothing
```
Read the `review:` lines (rows without a date, staff sharing a name). They are reported, never merged or reassigned.
Every existing row belongs to Khadak Singh Da Dhaba because this database has only ever held that restaurant.

## Step 3 — conversion rehearsal INCLUDING rollback (do this first, before any test restaurant exists)
```bash
php tools/v31-convert.php --apply --i-have-a-backup     # must end: "Verification: 129 OK, 0 mismatch" and
                                                        # "Financial reconciliation: N totals compared, 0 difference(s) · GL … OK"
ls $F360_PRIVATE_DIR/logs/                              # v31-convert-<time>.json + -reconciliation.md  → download both
```
Edit `config.php`: `'single_db' => true, 'single_db_scope' => [...]` exactly as printed by the tool. Then:
```bash
php tools/v31-convert.php --verify                      # "129 tables checked, 0 mismatch(es)"
php tools/v31-convert.php --rollback --i-have-a-backup  # rollback rehearsal: "0 mismatch(es)", "Rolled back"
```
Set `single_db => false` again, run `php tools/health-check.php` (site back to the original layout), then convert again:
```bash
php tools/v31-convert.php --apply --i-have-a-backup     # second conversion; keep this run's reports
```
Set `single_db => true` + `single_db_scope` (as printed) and `platform.enabled => true` in `config.php`.

## Step 4 — platform, Super Admin, health
```bash
php tools/platform-setup.php migrate
php tools/platform-setup.php admin you@ksdd.in "Your Name"      # then open https://staging.ksdd.in/platform-admin/ and set up two-step sign-in
php tools/health-check.php                                      # must end "RESULT: OK"
php tools/v31-convert.php --verify
```

## Step 5 — isolation check on your REAL data (non-destructive)
```bash
php tools/staging-isolation-check.php --base-url=https://staging.ksdd.in
```
Registers two throw-away restaurants, attacks KSDD's real ids/names through every API route, checks that nothing
leaks and that KSDD's rows stay byte-identical, then suspends the test restaurants. Must end `RESULT: … 0 failed`.
(It refuses to run on `u245900320_ksdd`.) After this, `--rollback` is refused by design — use the backup instead.

## Step 6 — what you check by hand on staging
* Sign in as the owner and as counter / store / salary users with their **existing** passwords; each sees only its modules.
* Dashboard: cash in hand, supplier payable, stock value, last salary month equal today's live figures.
* Reports → P&L / Trial balance for a past month equal the live site.
* Daily closing for a past test day, daybook photo, salary wizard on the current month (Save & Next), a purchase bill.
* Android app built for staging (`API_BASE=https://staging.ksdd.in/api/v1/index.php`): sign in, closing, photo.
* Optional cron: hPanel → Cron Jobs: `F360_PRIVATE_DIR=/home/u245900320/domains/ksdd.in/staging_private /usr/bin/php /home/u245900320/domains/ksdd.in/public_html/staging/platform-cron.php` every 15 min.

## Backup and restore test (required before production)
1. phpMyAdmin → `u245900320_staging` → Export (this is now a converted, multi-restaurant database).
2. Drop all tables of `u245900320_staging` (phpMyAdmin → Check all → Drop), Import the file again.
3. `php tools/v31-convert.php --refresh-views` then `php tools/health-check.php` → `RESULT: OK` and `--verify` → 0 mismatch.
   (Views are recreated because a phpMyAdmin export may record a different view definer.)

## Rollback summary
| When | How |
|---|---|
| Before the conversion | Nothing to undo in the database; remove the uploaded files. |
| After conversion, only KSDD has data | `php tools/v31-convert.php --rollback --i-have-a-backup` (verified: every business row identical), then `single_db => false`. |
| After other restaurants have data | Rollback is refused. Restore the Step 0 export into the database and the old files. |

## Production (only after written approval) — same package, same commands
`public_html/` → `domains/ksdd.in/public_html/` (keep `public_html/staging/` out of it or delete staging afterwards),
`ksdd_private/` → `domains/ksdd.in/ksdd_private/` with `config.example.php` → `config.php` (production database
`u245900320_ksdd`, existing keys). No `SetEnv` is needed: the sibling `ksdd_private` folder is found automatically.
Move `public_html/config.php` and `public_html/private/*` into `ksdd_private/` (the old locations are still read as a
fallback, so the site keeps working during the move; `health-check.php` warns until they are moved).
Steps 2–4 run with `--owner-approved-live` added to `--apply` (the tool refuses the live database without it), during a
maintenance window, after a fresh Step 0 backup. Then cron every 15 minutes, HSTS in `.htaccess`, signup stays closed
until you open it, payments stay disabled until approved.
