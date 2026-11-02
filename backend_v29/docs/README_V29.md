# Finance 360 V29 — multi-restaurant backend + website (developer README)

Built on the existing V23 PHP/MySQL code (same finance engine, same database schema per restaurant).
Status: **staging build, tested locally, not deployed.** See `AUDIT_AND_REQUIREMENT_MATRIX.md` for exactly what is done / partial / blocked.

## New files
| Path | Purpose |
|---|---|
| `platform/core.php` | platform DB, crypto, audit, rate limits, email outbox, i18n helpers |
| `platform/schema.php` | platform DB migrations (control plane only) |
| `platform/tenancy.php` | membership checks, per-request branch routing, provisioning, write gate |
| `platform/billing.php` | trials, plans, subscription state, orders, Razorpay webhooks, reminders |
| `platform/auth.php` | users, verification/reset, mobile tokens, TOTP, platform admins |
| `platform/register.php` | restaurant self-registration (resumable provisioning) |
| `platform/ui.php`, `assets/platform.css`, `assets/f360-mark.svg` | server-rendered UI kit |
| `site.php`, `signin.php`, `register.php`, `verify.php`, `forgot.php`, `reset.php`, `choose.php` | public website + sign-in flow |
| `owner.php`, `logo.php` | owner portal |
| `platform-admin/index.php`, `support.php` | Super Admin portal, break-glass entry |
| `api/v1/index.php` (+ `.htaccess`) | REST API for Flutter (`docs/openapi.yaml`) |
| `billing-webhook.php` | Razorpay webhook |
| `platform-cron.php` | scheduler for platform + every branch |
| `f360/dispatch.php` | ONE route dispatcher shared by the web API and `/api/v1` |
| `f360/v29.php` | closing final-on-submit, daybook photos, supplier suggestions, rate-change check |
| `tools/platform-setup.php`, `tools/platform-adopt-legacy.php`, `tools/platform-migrate-branches.php` | operator CLI |
| `tests/platform_tests.php`, `tests/platform_test_config.php` | platform test suite (staging only) |

## Changed files (all changes small and commented "V29")
`lib.php` (db routing, storage_dir, write gate in user(), 402 errors, test config hook) · `f360-api.php` (uses dispatcher) ·
`f360/core.php` (plan modules, per-branch keys, `cash_counter` role) · `f360/closing.php` · `f360/purchase.php` · `f360/admin.php` ·
`f360/schema.php` (migration 13) · `f360/stock.php`, `f360/integrations.php`, `f360/emp_import.php`, `lib360.php`, `finance360.php`, `api360.php`, `api.php`
(private paths per branch) · `index.php`, `install.php`, `reset-owner.php`, `sync.php`, `f360-wa-webhook.php` · `assets/f360/core.js`, `daily.js`, `purchase.js` · `.htaccess`.
Single-restaurant mode (no `platform` block in config.php) behaves as before — the legacy suite still passes 139/140 (1 = file missing from the ZIP).

## Run tests (staging machine with MySQL/MariaDB)
```
php tests/run_tests.php                                               # legacy finance suite (config.php → *_staging DB, f3_allow_fake_clock)
F360_CONFIG=tests/platform_test_config.php php tests/platform_tests.php   # platform suite, rebuilds f360_platform_test / f360test_* DBs
```

## Config
See `config.example.php` (`platform` block). Secrets live only in `config.php` (denied by `.htaccess`). Never ship `config.php` or `private/`.

## Cron (hPanel, every 15 minutes)
`/usr/bin/php /home/<user>/public_html/<folder>/platform-cron.php`

## Incident response (short)
Suspected cross-restaurant exposure → set `platform.enabled=false` (stops all platform sign-ins; KSDD keeps single mode), rotate `platform.key`
only with a re-encryption plan (branch DB passwords are encrypted with it), review `p_audit`, `p_api_tokens` (revoke: `UPDATE p_api_tokens SET revoked_at=UTC_TIMESTAMP()`),
bump `p_users.session_rev` to sign everyone out. Payment anomaly → `billing.mode='disabled'`, review Super Admin → Payments.
