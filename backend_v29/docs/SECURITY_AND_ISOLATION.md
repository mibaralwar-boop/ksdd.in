# Tenant isolation, security design and threat model (V29)

## 1. Isolation model — database per branch
```
Platform DB (control plane)            Branch ledger DBs (one per branch, same schema as the original KSDD database)
 p_users, p_admins                      ksdd_live            ← original restaurant, "Main" branch (unchanged)
 p_tenants, p_branches  ── creds ──▶    f360_b<uid1>          ← Alpha Dhaba · Main
 p_tenant_members, p_branch_members     f360_b<uid2>          ← Alpha Dhaba · Highway
 plans, subscriptions, orders, audit    f360_b<uid3>          ← Bravo Kitchen · Main
 (no financial records)
```
* A request holds **one** branch connection, chosen in `platform/tenancy.php` from a verified membership:
  web → `tenancy_resolve_session()` on the first `db()` call; app → `v1_enter()` from the server-side token row.
* Every request re-checks: user active + password revision, tenant-membership active, branch-membership active, branch active,
  tenant active/suspended, local ledger user id matches. Revocation therefore takes effect on the **next request** (tests ISO-07, API-08).
* **No fallback**: in multi-restaurant mode `db()` without a resolved context throws (ISO-08). The client never sends database names;
  tenant/branch ids it sends are only *requests* that are re-authorised, with the same 403 for "not yours" and "does not exist" (ISO-02).
* Why this model: the existing code has ~1,600 SQL statements without a tenant column. Retro-fitting `tenant_id` into all of them would be
  high-risk for money correctness. With a database per branch, a forgotten `WHERE` cannot reach another restaurant because its rows are
  not in the connected database. The original restaurant's data is not moved or re-keyed.
* Platform-level integrity: composite FK `(tenant_id, branch_id) → p_branches(tenant_id, id)` and `(tenant_id, user_id) → p_tenant_members`
  make a cross-tenant membership impossible even by direct SQL (ISO-03).
* Files: `storage_dir()` → `private/` for the original restaurant, `private/tenants/<tenant uid>/<branch uid>/` for every other branch.
  Uploads, thumbnails, exports, backups, imports and reference documents all use it (ISO-06). Logos: `private/platform/logos/` served by
  `logo.php` to members only.
* Secrets: branch DB passwords encrypted in the platform DB with libsodium (`platform.key`). Bank/PAN/Drive/AiSensy secrets in new branches use
  a per-branch derived key (ISO-05); the original restaurant keeps its existing key ring.
* Backups: the existing encrypted backup job dumps **its own branch database** only; shared-DB leakage into a tenant's Drive cannot happen.
  Platform DB backup is a separate operator task (MIGRATION doc §5).

### Costs / limits of this model (be aware)
* One MySQL database per branch. Hostinger **shared/cloud** hosting does not let the app run `CREATE DATABASE`, so new branches take an
  empty database from a **pool** you create in hPanel (`provision.mode = pool`). Check your plan's database limit. When the pool is empty,
  registration is saved and waits ("setup not finished") — it is never half-activated. VPS: `provision.mode = create`.
* Schema upgrades must run on every branch DB (`platform-cron.php` reports `f3_ready` failures per branch; run `migrate-f360.php` logic per branch — see MIGRATION doc).
* Consolidated reports read each permitted branch in turn.

## 2. Authentication & sessions
* Restaurant users: `p_users` (bcrypt via `password_hash`), email verification required before sign-in, 10+ char passwords, rate limits per IP and per email,
  generic error messages, password reset links 1 h, single-use; reset signs out all browsers and revokes all app tokens.
* Web: existing hardened PHP session (HTTPS only, HttpOnly, SameSite=Strict, strict mode, 8 h), CSRF token on every POST, CSP `default-src 'self'`,
  no inline scripts, `X-Frame-Options: DENY`.
* App: access token 15 min, refresh 30 days, bound to `device_id`, rotated on use; reuse of an old refresh token revokes the whole family
  (API-07). Tokens stored only as SHA-256. Changing restaurant/branch issues a new pair and revokes the old one.
* Platform admins: separate table, separate cookie (`F360_PADMIN`, path `/platform-admin/`), TOTP mandatory (RFC 6238, replay-protected,
  MFA-01/ADM-01), 30-min idle / 8-h hard session, roles super_admin / billing_admin / support.
* Ledger (local) passwords are never used in multi-restaurant mode; `api.php?route=login`, `install.php`, `reset-owner.php` are disabled.

## 3. Authorisation (web = app)
Effective permission = local template + explicit allow/deny overrides (existing V17 engine, deny wins) **∩** switched-on modules **∩** plan
modules **∩** subscription state (read-only removes every write) **∩** support session (read-only). Implemented once in `f3_perms()` /
`platform_write_gate()` and used by: every web router (`user()` gate), `f360/dispatch.php` (shared by web and `/api/v1`), menus
(`core.js`), and `/permissions/me` for Flutter. Files are released only if linked to a record the user may see (`f3_file_ok`).

## 4. Threat model (summary)
| Threat | Control | Test |
|---|---|---|
| Cross-tenant read via id guessing (IDOR) | other tenants' rows not in the connected DB; platform ids re-authorised | ISO-02, ISO-04, ISO-06, WEB-03 |
| Forged tenant/branch in session/token | context stored server-side, re-checked every request | ISO-07, API-03 |
| Stale tab / revoked user | per-request membership + revision checks | API-08, ISO-07 |
| Payment spoofing / replay | HMAC signature, event-id dedupe, unique order credit, amount check | PAY-03…09, API-09 |
| Privilege escalation via plan/role | plan modules and read-only filter applied for owner too | PERM-02, SUB-01 |
| Support abuse | owner approval, time limit, read-only, audit | ADM-02 |
| Stolen refresh token | rotation + family revocation, device binding | API-07 |
| Brute force | rate limits (login, admin login, forgot, register, contact) | (manual) |
| Malicious uploads | MIME sniff, size limit, JPEG/PNG re-encode (logo always PNG re-encode), private storage, no direct URLs | CLO-02 |
| SQL injection / XSS | prepared statements everywhere; output escaped (`pe()`/`esc()`); CSP | code review |
| SSRF | outbound calls only to fixed Google/AiSensy/Razorpay hosts | code review |
| Secrets in ZIP / app | release ZIP excludes `config.php`, private data, logs; `.env`-style values only in config.php outside webroot rules | packaging script |

## 5. Secret scan of the supplied ZIP
Found: `config.php` (live DB credentials + setup_key), `private/imports/*.xlsx` (staff data), `private/uploads/*`, `private/reference-documents/*`,
`private/logs/db-errors.log`. No tokens/API keys in code. **Rotate**: DB password (hPanel → Databases), `setup_key`; if `pii_key` was never set,
set one and run `php tools/f360-rekey.php` (dry run, then `--apply --i-have-a-backup`) so bank/Drive secrets move off the old setup_key.

## 6. Not done / limits
CAPTCHA (no provider), mobile OTP (no provider), HTTP ETag headers, FCM push sending, formal data-deletion workflow, WAF/monitoring
service integration, penetration test by a third party. `.htaccess` deny rules were not testable with PHP's built-in server; verify on staging
hosting that `private/`, `platform/`, `tests/`, `tools/`, `docs/` return 403.
