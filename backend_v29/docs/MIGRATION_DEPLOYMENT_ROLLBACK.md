# KSDD migration, staged deployment and rollback (V29)

**Nothing in this document has been run on your live site.** Every step below needs your go-ahead. Do them on a staging copy first.

## 1. What changes for the existing restaurant (KSDD)
* Its database is **not restructured**. Adoption only *registers* it as tenant #1 / branch "Main" in a new platform database.
* Schema change inside the KSDD database: Finance 360 migration **13** = one new table `f3_closing_files` (daybook photos). Additive.
* Data change inside the KSDD database: setting `f3_closing_direct = 1` (V29 §8, closing final on submit) + one audit line.
  Use `--no-closing-direct` if you want to keep admin review for now.
* Existing users keep their passwords (bcrypt hashes copied to the platform). They sign in at `signin.php` instead of the old box.
* Files stay in `private/` exactly where they are.

## 2. Hostinger prerequisites (check in hPanel; not verified from here)
PHP ≥ 8.2 with pdo_mysql, openssl, **sodium**, gd, fileinfo, zip, curl, mbstring · HTTPS · cron (every 15 min) · mailbox for SMTP ·
enough MySQL databases: 1 platform + 1 per branch (+ a few empty spares for the pool). Check your plan's database limit.

## 3. Staging rehearsal (recommended order)
1. hPanel: create a **staging** subdomain + copy of the live DB (phpMyAdmin export → import into `…_staging`) + copy of `private/`.
2. Upload the release ZIP to the staging folder (it contains no `config.php` and no private data).
3. Create `config.php` from `config.example.php`: legacy `db_*` = the staging copy; `platform` block = a new empty `…_platform_staging` DB;
   generate `platform.key` (32+ random chars, keep offline); `provision.mode = 'pool'`; `billing.mode = 'disabled'`; `signup_enabled = false`.
4. `php tools/platform-setup.php migrate` → `php tools/platform-setup.php admin you@domain "Your Name"`.
5. Run Finance 360 migration 13 on the KSDD copy: sign in to the old app as owner → `migrate-f360.php` **before** enabling platform mode,
   or after adoption run `php tools/platform-migrate-branches.php --apply`.
6. `php tools/platform-adopt-legacy.php` (dry run, read the user list) → `php tools/platform-adopt-legacy.php --apply --i-have-a-backup`.
   It prints "byte-identical" for all financial tables or exits with code 3 (then restore and report).
7. Sign in at `signin.php` with the old owner password; check dashboard, last closings, salary history (June–Sept), supplier balances,
   stock value, trial balance against the live site **for the same dates**. Record differences (expected: none).
8. Create 2–3 empty databases in hPanel → Super Admin → System → add to pool. Set `signup_enabled = true` in config **and** in
   Super Admin → Settings **only on staging**; register a synthetic restaurant; repeat the cross-tenant checks in §7.
9. Run the automated suites on a VPS/local copy: `php tests/run_tests.php` (legacy) and
   `F360_CONFIG=tests/platform_test_config.php php tests/platform_tests.php` (needs a MySQL user allowed to create `f360test_*` databases).
10. Cron: replace the old `f360-cron.php` entry with `platform-cron.php` (every 15 min). Check Super Admin → System → Jobs.

## 4. Production cut-over (only with your written approval)
1. Announce a 30-minute window. Take: full DB export (Settings → Download backup + phpMyAdmin), `private/` archive, current files ZIP.
2. Upload release files (keep `config.php`, `private/`). Do **not** delete old files you may need for rollback.
3. Add the `platform` block to `config.php` with `'enabled' => false` first; run `tools/platform-setup.php migrate` + `admin`;
   run `tools/platform-adopt-legacy.php` dry run, then `--apply --i-have-a-backup`.
4. Set `'enabled' => true`. Sign in, verify §3.7. Keep public sign-up **closed** and billing **disabled** until you decide.
5. Switch cron to `platform-cron.php`.

## 5. Backups
* Each branch DB: the existing encrypted backup (Settings → Integrations → Backup) now runs per branch from `platform-cron.php`.
* Platform DB: export it daily (hPanel automatic backups or phpMyAdmin); it holds logins, memberships, subscriptions, orders.
* Restore of one restaurant = restore **its** branch DB into an empty database and point `p_branches.db_*` at it; never overwrite another branch.

## 6. Secret rotation after this ZIP was shared
hPanel → Databases → change the DB user password → update `config.php`. Generate a new `setup_key`. If `pii_key` was not set: add one,
then `php tools/f360-rekey.php` (dry run) and `--apply --i-have-a-backup`; keep the old value as `pii_key_previous` until it reports legacy=0.

## 7. Rollback
| Situation | Rollback |
|---|---|
| Problem after adoption, before anyone used the new sign-in | Set `platform.enabled = false` in `config.php`. The app is back to single-restaurant mode on the same KSDD DB; old sign-in works. Optionally `SET` `f3_closing_direct` = `0` in Settings. |
| Problem after some days of use | Same switch. All entries made meanwhile are in the KSDD database already (it is the same database). Users of *other* restaurants lose access until re-enabled — their databases are untouched. |
| Bad file upload | Re-upload the previous files ZIP (keep `config.php`, `private/`). Migration 13 table can stay (unused by old code). |
| Data damage | Restore the pre-cut-over DB export into an **empty** database, point `config.php` at it. Never import over a live DB. |
| Platform DB lost | Restore its export. Branch DBs are independent and unaffected. |
Removing V29 entirely: drop the platform database, delete `platform/`, `platform-admin/`, `api/`, new root pages; KSDD keeps working.

## 8. Cross-tenant checklist for staging (manual, in a browser and with the app)
Two owners A and B, a cashier of A. A cannot: open B in `owner.php?t=`, select B's branch in the app, open B's file ids, see B's staff in
search/exports, receive B's notifications. Cashier sees only Closing. Revoke cashier → next refresh fails. Expire A (Super Admin → no extension,
or wait) → A can read/export, cannot add. All of these are automated in `tests/platform_tests.php`; repeat once on real hosting.
