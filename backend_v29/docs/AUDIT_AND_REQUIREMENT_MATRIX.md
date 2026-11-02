# Finance 360 V29 — Phase 0 audit and final requirement matrix

Audit basis: the supplied `public_html_4.zip` (137 files, PHP ≈15k lines, 129 tables after migrations) run on a **local staging** MariaDB 10.11 / PHP 8.3.
**No live database or schema export was supplied**, so the live schema was not inspected (see "Blocked" below). Nothing was deployed.

Status key: **Done & tested** (automated test in this build) · **Done, manual check** (screenshot / run, no automated assertion) · **Existing** (already in V23 and covered by the existing suite) · **Partial** · **Missing** · **Blocked** (needs something only you can provide).

## 1. Source audit findings

| # | Finding | Evidence | Action taken |
|---|---|---|---|
| A1 | **Live credentials inside the ZIP.** `config.php` contains the production DB host/name/user/password and the `setup_key` (which also encrypts PII when `pii_key` is unset). | `config.php` (values not copied anywhere) | Excluded from the release ZIP. **Rotate the DB password and setup_key** (see MIGRATION doc §6) because the ZIP has been shared. |
| A2 | Private HR / staff data inside the ZIP: `private/imports/EMP-…/oneclick-….xlsx` (staff import incl. salaries), `private/uploads/2026/11/*.jpg`, `private/uploads/thumbs/*`, `private/reference-documents/*.pdf` (staff rules, voucher register, roster, working plan), `private/logs/db-errors.log`. | file listing | Excluded from the release ZIP (they stay on your server). Do not email ZIPs of `public_html`. |
| A3 | Live error log shows **error 1267 (mixed collations) on `GET payroll-line`** on 2 Nov 2026 (4 times). | `private/logs/db-errors.log` | V23 code already contains the collation fix (schema step 11 + connection collation); tests COL-01/COL-02 pass. **Run `migrate-f360.php` on live** to apply step 11 if not yet done; then confirm the log stops growing. |
| A4 | ZIP is missing `migrations/f360_v17_reconcile.sql`, `scripts/mock_int.py` and the earlier `docs/`. | test V17-REC-01 fails; V20 integration tests skipped | Not recreated (content unknown). Supply the files if you have them. |
| A5 | Single static DB connection + ~1,600 raw SQL call sites with no tenant column. | `lib.php db()`; grep count | Chose **database-per-branch** isolation (no retrofit of 1,600 queries). See SECURITY_AND_ISOLATION.md. |
| A6 | Thumbnail cache keyed only by file id (`private/uploads/thumbs/{id}.jpg`). With several databases ids collide → **cross-restaurant image leak** if storage were shared. | `f360/stock.php f3_thumb` | All private paths now go through `storage_dir()` (per-branch root) and thumbnails are keyed by id + sha256. |
| A7 | HR reference PDFs served by `api.php?route=reference` from a fixed folder → would be visible to every new restaurant. | `api.php` | Now read from the branch's own storage root; other restaurants get 404. |
| A8 | Encryption keys for bank/PAN/Drive/AiSensy secrets are global. | `f3_pii_keys()` | New branches use a key derived per branch; the original restaurant keeps its existing keys (no re-encryption needed). Test ISO-05. |
| A9 | Closing still required admin "Review closing" to classify variance. | `f3_closing_finalize` → `submitted` + review task | V29 §8 implemented: final on submit (setting `f3_closing_direct`, on for all platform restaurants and set by the adoption tool). |
| A10 | Mobile `/api` did not exist; web uses session + CSRF only. | — | `/api/v1` with device-bound rotating tokens; same dispatcher as the website. |
| A11 | Local password login (`api.php?route=login`) would bypass platform memberships. | — | Disabled in multi-restaurant mode (test WEB-02). `install.php` / `reset-owner.php` disabled in that mode. |
| A12 | No dependency manager; no third-party PHP packages. | no composer.json | Dependency audit = PHP 8.2+ extensions only (pdo_mysql, openssl, sodium, gd, fileinfo, zip, curl, mbstring). |
| A13 | Earlier concerns re-validated: salary autosave/Save & Next, physical-count post-snapshot movements, payroll GL posting, reorder scope. | existing tests PAY-*, STK-*, V23-* in `tests/run_tests.php` | All pass in staging (139/140; the 1 failure is A4). Not re-implemented. |

## 2. Requirement matrix (V29 sections; V19–V28 finance items as they apply)

| V29 § | Requirement | Status | Where |
|---|---|---|---|
| 0 | Source audit, secret scan, dependency graph | Done (this file; live schema **Blocked**) | — |
| 1 | Tenant → branch → users → records; multiple restaurants per owner via memberships | Done & tested (REG-08, BR-01, WEB-03) | `platform/tenancy.php`, `p_tenant_members`, `p_branch_members` |
| 1 | Tenant-level billing, plan limits on branches/users/modules | Done & tested (BR-02, PERM-02) | `platform/billing.php` |
| 2 | Strict isolation; server-chosen context; forged ids rejected; no existence leak | Done & tested (ISO-01…09, API-03, WEB-03) | `tenancy_authorize`, `db()` no-fallback |
| 2 | Files, encryption, cache, exports, jobs scoped | Done & tested for files/keys (ISO-05/06); exports & backups run inside the branch DB + branch storage (by construction) | `storage_dir()`, `platform-cron.php` |
| 2 | Composite keys preventing cross-tenant links | Done & tested (ISO-03) — at platform level; inside a branch DB cross-tenant links are impossible (other tenants' rows are not there) | `fk_bm_b` |
| 2 | Super Admin break-glass: reason, time limit, owner approval, read-only, audited | Done & tested (ADM-02) | `p_support_grants`, `support.php`, owner portal |
| 3 | Public site: landing, features, pricing, FAQ, contact, terms, privacy, sign in, register, forgot, verify, status | Done, manual check (screenshots 01–04, WEB-01 renders) | `site.php`, `register.php`, … |
| 3 | Onboarding wizard (owner, restaurant, logo, branch, trial terms, consent) → atomic provisioning | Done & tested (REG-03…07, injected failures) | `platform/register.php` |
| 3 | Email verification, resend, password reset, rate limits, honeypot, CSRF | Done & tested (REG-02/03, WEB-02); CAPTCHA **Missing** (no provider) | |
| 3 | Mobile OTP | **Blocked** — no SMS/WhatsApp OTP provider connected; mobile is stored unverified and labelled so | |
| 3 | Owner edits name/logo/contact/address (logged) | Done, manual check | `owner.php?p=branding` |
| 4 | 30-day trial, exact end shown, one automatic trial per owner | Done & tested (REG-06, REG-08) | |
| 4 | Plans monthly/quarterly/6-monthly/yearly, versioned prices, quotas, modules, grace | Done & tested (PLAT-02, PAY-01, PERM-02) | |
| 4 | State machine, read-only on expiry, nothing deleted, admin extend/suspend | Done & tested (SUB-01…03) | |
| 4 | Reminders 7/3/1/today/expired with dedupe | Done & tested for dedupe (SUB-04); real email delivery **Blocked** (SMTP not configured in staging) | `billing_reminders()` |
| 4 | Payment: server order, signed webhook only, dedupe, replay, out-of-order, refunds, disputes, renewal without gap | Done & tested with simulated signed Razorpay events (PAY-01…11, API-09) | `billing-webhook.php` |
| 4 | Real Razorpay order API / hosted checkout | **Blocked** — needs your Razorpay account, keys and approval; checkout button is disabled until then | |
| 4 | Google Play billing compliance | **Blocked (owner/legal decision)** — app purchase endpoints are off and no external payment links are returned to the app | SUBSCRIPTIONS_AND_BILLING.md §5 |
| 4 | Subscription tax invoice | **Partial** — receipt number + seller fields; GST invoice format needs your accountant | |
| 5 | Super Admin portal: overview, restaurants, trials, plans, payments/webhooks, lifecycle, admins + TOTP, support, system/jobs/pool, MRR estimate, notices, audit | Done; TOTP & support tested (MFA-01, ADM-01/02); pages manual check (screenshots 14–18) | `platform-admin/index.php` |
| 5 | Fraud monitoring, account deletion/export workflow | **Partial** — failed sign-ins/registrations, rejected webhooks and audit are visible; formal deletion workflow **Missing** (needs retention policy) | |
| 6 | Owner portal: branch selector, logo/name, branches CRUD within quota, users/invites, billing, audit, consolidated report | Done; manual check (screenshots 05–09); quotas tested | `owner.php` |
| 7 | Deny-by-default permissions, web = app, plan entitlements, read-only filter, revocation without reinstall | Done & tested (PERM-01/02, API-04/05/08) | `f360/dispatch.php`, `/permissions/me` |
| 7 | Cash Counter sees only Closing | Done & tested (API-04) + screenshot 11 | template `cash_counter`, `core.js` |
| 8 | Closing final without admin approval; Cash Acess/Short; card/UPI not in drawer; locked days need reopen with reason | Done & tested (CLO-01, CLO-03, existing FIN-*/ACC-*) | `f360/v29.php`, `closing.php` |
| 8 | Optional daybook photo, validated, private, never blocks closing | Done & tested (CLO-02) | `f3_closing_files` |
| 8 | Duplicate/concurrent finalisation | Done & tested (CLO-01 idempotent retry + second attempt 409) | |
| 9 | Historical employee/salary import protected, month selector | Existing (OC-01…07, V21 tests) | `f360/emp_import.php` |
| 9 | Actual Sept 2026 sheet / 61 vs 62 rows reconciliation | **Blocked** — the source Excel was not in this upload | |
| 10 | Four departments; six-field wizard; calendar-day divisor; OT info only; autosave/version; Save & Next | Existing & tested (STA-*, PAY-*, V19 tests) | `f360/payroll.php`, `assets/f360/salary.js` |
| 11 | Supplier-first: balance, last items and rates, other suppliers per unit | Done & tested (PUR-01) | `supplier-suggest` |
| 11 | Rate-change confirmation before saving new rate | Done (server rule + web dialog); tested via PHP lint + manual code path; **no automated posted-bill test** | `f29_rate_changes`, `purchase.js` |
| 12 | Stock: counts with snapshot, valuation, reorder, transfers | Existing (V23 tests) | |
| 12 | Inter-**branch** stock/cash transfer as a two-sided document | **Missing** — branches are separate books; guidance shown in the consolidated report | |
| 13 | Single double-entry journal, reports, P&L/TB | Existing per branch; **consolidated** = Books month P&L summed across permitted branches (GL consolidation **Partial**) | `owner.php?p=consolidated` |
| 14 | Dashboards per role, historical salary visible | Existing; role menus verified by screenshot/test | |
| 15 | Drive / AiSensy / FCM tenant-scoped | Partial — per-branch credentials and storage (by construction), AiSensy webhook routed by branch uid; FCM token registration endpoint done; **FCM sending Missing**; real Drive/AiSensy runs **Blocked** (credentials, mock server file missing) | |
| 15 | Cron per branch, catch-up, dedupe, job logs | Done, manual run of design; per-branch isolation in `platform-cron.php` (not run against live cron) | |
| 16 | Mobile-first UI, Hindi/English | Done for platform pages; **Hindi coverage partial** (public site/owner portal key strings; Super Admin English only; existing finance app unchanged) | |
| 17 | REST API + OpenAPI + idempotency + versions + structured errors | Done & tested (API-01…09); contract `docs/openapi.yaml` | `api/v1/index.php` |
| 17 | ETag headers | **Partial** — `version` fields in bodies are used for concurrency; no HTTP ETag headers | |
| 18 | Hashing, CSRF, rate limits, upload validation, secrets outside code, logging | Done (see SECURITY doc); HSTS left commented until SSL confirmed | |
| 19 | KSDD migration without data loss; checksums; rehearsal | Done & tested on a staging copy built from demo data (ADOPT-01…05). **Rehearsal on a copy of the LIVE database is Blocked** (no snapshot supplied) | `tools/platform-adopt-legacy.php` |
| 20 | P0 tests 1–14 | 1–3, 4, 5, 6, 7 (existing), 8 (partial), 9 (existing), 10 (partial), 11 (existing per branch), 12, 13 (screenshots), 14 (lint + suites) | `docs/TEST_REPORT_*.md` |
| 21 | Sanitized ZIP, migrations, OpenAPI, docs, screenshots, rollback | Done | release ZIP |
