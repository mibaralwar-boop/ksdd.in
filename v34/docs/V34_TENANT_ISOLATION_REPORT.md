# Finance 360 V34 — tenant isolation report (one shared database)

## Design (V31, kept) and what V34 changed
1. Every business table `dd_x` / `f3_x` becomes a base table `mt_dd_x` with `tenant_id` + `branch_id` (NOT NULL). Every
   primary key that is not an auto-increment id, every unique key and every secondary index is prefixed with
   `(tenant_id, branch_id)`; parent/child foreign keys are composite `(tenant_id, branch_id, col)`.
2. The old name `dd_x` is a VIEW: original columns, `WHERE tenant_id/branch_id = context of THIS connection`
   `WITH CASCADED CHECK OPTION`. The ~1,900 existing statements run unchanged and can only see/change the current branch.
3. The context row (`mt_ctx`, one per MySQL connection) is written by the server from a **verified membership**
   (`platform/tenancy.php`); no context → views return zero rows.
4. INSERT/REPLACE are rewritten to the base table with the server's tenant/branch (application values for those
   columns are refused).
5. **V34:** every application statement passes `mt_guard()` first. It refuses any reference to `mt_*`
   (base tables, `mt_ctx`, `mt_tables`), MySQL executable comments and stacked statements. Only the isolation module and
   the conversion tool use the internal `raw*` methods. New connections clear their own context row; the scope is read
   back after it is set; stale rows of dead connections are purged by cron.
6. **V34:** sessions are stored per installation (staging cannot load production sessions); private storage and config
   are outside the web root; per-restaurant backups contain only that branch's rows.

## Why the view alone was not enough (audit P0-2, demonstrated)
On Hostinger shared hosting the database user the PHP application connects with necessarily owns the base tables and the
context table (it must, to write through the rewriter and to set the context). On the unmodified V31 code a connection
scoped to restaurant B executed, successfully: `SELECT * FROM mt_dd_users` (all restaurants' logins),
`UPDATE mt_ctx SET tenant_id=1 …` (switch its own scope), `DELETE FROM mt_ctx`, `/*!50000 … mt_dd_users */`, stacked
statements — 13 failures of `tests/mt_hostile_tests.php`. With V34 all 29 checks pass.

## Database identities on Hostinger (requested: separate runtime / migration users)
hPanel creates one MySQL user per database with full rights on it and does not offer custom `GRANT`s, so a runtime user
that can use the views but not the base tables cannot be created there. Therefore the boundary is: server-chosen context
+ scoped views + the V34 statement guard + composite keys, all on the application side of one database user, and this is
what the hostile and HTTP tests attack. If the hosting is ever moved to a VPS / managed MySQL with `GRANT` support, the
recommended hardening is: a migration user (DDL) and a runtime user with `SELECT, INSERT, UPDATE, DELETE` on the views
and platform tables only, plus `INSERT`/`UPDATE` on `mt_ctx` through a definer stored procedure. This is a **blocker for
"database-enforced" isolation**, not for application-enforced isolation.

## Evidence (all executed locally, logs in `docs/evidence/`)
| Test | Scope | Result |
|---|---|---|
| `mt_hostile_tests.php` | direct base-table reads (5 syntaxes), context rewrite/replace/delete, executable comments (MySQL + MariaDB), stacked statements, base writes, temp-table copy, `OR 1=1`/UNION, UPDATE/DELETE without WHERE, tenant_id in INSERT, missing scope, stale scope, transaction switch, platform connection | 29/29 (V31: 13 failed) |
| `platform_tests_v31.php` | registration, trial, expiry, billing webhooks, tokens, TOTP, support grant, hostile tenant/branch selection (ISO-01…09, API-03, BR-03, WEB-03) | 64/64 |
| `isolation_sweep_v31.php` | 3 attacker accounts (owner of B, owner of A, cash counter of A) × 2,967 GET calls on all 69 routes with KSDD's ids + secret marker; 42 writes on KSDD ids; restaurant backup | 9/9 — no KSDD data, no 5xx, KSDD rows byte-identical |
| `staging-isolation-check.php` | same attack with REAL names/ids for the staging database (non-destructive) | 4/4 locally; **to be run on `u245900320_staging`** |
| `hosting_rehearsal.sh` | sensitive files over HTTP, session separation, cross-tenant select after web registration | 53/53 |

## Residual risks
* An application code path that builds SQL from request text could still read the **current** restaurant's data in
  unexpected ways, but cannot name base tables or change its scope (guard). No such path was found by the sweep.
* Rows are not bound to `p_branches` by a foreign key (base-table scope columns are `INT UNSIGNED DEFAULT 0`); a row
  inserted around the rewriter would be invisible (tenant 0), not leaked. Recommended follow-up after go-live.
