# Platform test run — 2026-11-02 20:19 UTC

Environment: PHP 8.3.6, 10.11.14-MariaDB-0ubuntu0.24.04.1 (local staging, test databases rebuilt from scratch)

**Result: 77 passed, 0 failed (77)**

| ID | Test | Result | Detail |
|---|---|---|---|
| PLAT-01 | Platform tables live in the SAME database (created by the V31 conversion); re-running migrations is a no-op | PASS |  |
| PLAT-02 | Seeded paid plan versions are inactive at ₹0 (no invented pricing; nothing purchasable until Super Admin publishes) | PASS |  |
| CONV-01 | Dry run writes nothing: no platform (p_*) or scoped (mt_*) tables created | PASS |  |
| CONV-02 | Apply: KSDD = tenant #1 / branch #1 "Main"; every finance table verified (row count + SHA-256 of every row); no per-branch database fields | PASS |  |
| CONV-03 | Every legacy table seen through its view in the KSDD scope is IDENTICAL to before the conversion (independent fingerprint) | PASS |  |
| CONV-04 | Existing users sign in with their OLD passwords; roles/templates carried over; owner is tenant owner | PASS |  |
| CONV-05 | Re-running --apply is idempotent: same tenant, 0 mismatches, nothing duplicated | PASS |  |
| CONV-06 | Closing final-on-submit set for KSDD; legacy demo employees visible to the owner; no unscoped dd_/f3_ base table remains | PASS |  |
| REG-01 | Sign-up closed → refused | PASS |  |
| REG-02 | Validation: missing terms / bad mobile / bad PIN / weak password refused; honeypot refused | PASS |  |
| REG-03 | Unverified owner cannot sign in; no tenant exists before verification | PASS |  |
| REG-04 | Duplicate submission before verifying → same registration, no second user | PASS |  |
| REG-05 | Injected failures (after tenant row, after DB install, inside final transaction) leave NO active tenant; retry completes exactly once | PASS |  |
| REG-06 | 30-day trial: ends 23:59:59 IST on sign-up day + 30; state trialing; owner linked as local owner in the NEW database | PASS |  |
| REG-07 | Second tenant B registers; verify link is single-use | PASS |  |
| REG-08 | Same owner adds another restaurant → NO second automatic trial (state none = read-only until a plan is bought) | PASS |  |
| ISO-01 | ONE database for all restaurants; distinct storage roots; the DATABASE itself binds each connection to the entered tenant+branch | PASS |  |
| ISO-02 | Owner A cannot authorise B’s branch, nor A-tenant + B-branch (composite), nor a non-existent id — same 403 message | PASS |  |
| ISO-03 | Composite FK: a branch membership pointing at another tenant’s branch is rejected by the database | PASS |  |
| ISO-04 | Employee created in B is invisible to A (list + search); A’s id lookup returns A’s own row, never B’s | PASS |  |
| ISO-05 | Encrypted secrets: ciphertext from B cannot be decrypted in A (per-branch derived keys); KSDD keeps its original key | PASS |  |
| ISO-06 | Uploaded files land under the branch’s own storage root; B’s file id read from A’s DB is not B’s file | PASS |  |
| ISO-07 | Web session forged to B’s branch is refused; stale password revision is refused (fresh PHP process, real session) | PASS |  |
| ISO-08 | No fallback: in platform mode db() with no resolved context fails instead of opening any database | PASS |  |
| ISO-09 | Machine endpoint routing by branch uid rejects unknown / malformed uids (404) | PASS |  |
| ISO-10 | EVERY scoped table: the view in A’s scope returns exactly A’s base rows (never B’s or KSDD’s); a connection with NO scope sees zero rows everywhere | PASS |  |
| ISO-11 | UPDATE / DELETE aimed at B’s ids from A’s scope change NOTHING (0 rows) and B’s rows stay byte-identical | PASS |  |
| ISO-12 | Writes cannot escape the scope: unscoped view insert → CHECK OPTION error; app-supplied tenant_id refused; direct base-table write refused; cross-branch FK refused by the database | PASS |  |
| ISO-13 | Same business keys in two restaurants coexist (settings key, closing date, day lock); upsert in A never touches B | PASS |  |
| ISO-14 | Destructive / unknown schema changes through the app connection are refused (DROP, TRUNCATE, RENAME, DROP VIEW, new unscoped finance table at runtime) | PASS |  |
| ISO-15 | Static scan: no CREATE DATABASE / per-branch DB code left; only the tenancy layer names base tables; EVERY literal INSERT/REPLACE into a finance table in the code is accepted by the rewriter | PASS |  |
| BR-01 | Owner A adds branch A2 within quota (same database, own scope + settings); owner linked automatically | PASS |  |
| BR-02 | Branch quota enforced (402) and trial plan limit read from plan version | PASS |  |
| BR-03 | Cash counter invited to A1 only: can enter A1, refused on A2 | PASS |  |
| XFER-01 | Stock A1 → A2: transfer_out at A1 (valued at A1 cost), in transit, received once at A2 with the SAME qty and value; total value conserved; second receive refused | PASS |  |
| XFER-02 | Hostile: another restaurant cannot send to A’s branch, cannot see/receive/cancel A’s transfer (same 404); the destination cannot cancel; a branch cannot send to itself | PASS |  |
| XFER-03 | Cash A1 → A2 is a TRANSFER on both sides (no sale, no expense): A1 Cash −₹1,500 → transit → A2 Cash +₹1,500; transit nets to zero | PASS |  |
| PUSH-01 | Device bound to A1 receives A1 notifications only if the user still has the permission + membership; B’s device gets nothing; mock FCM got a signed JWT and a bearer token | PASS |  |
| CRON-01 | Scheduler runs platform jobs + every active branch inside its own scope; context rows of dead connections are purged | PASS |  |
| SUB-01 | After trial end: state expired, read-only; POST blocked with 402, reads allowed | PASS |  |
| SUB-02 | Admin extension is contiguous from max(now, end) and logged | PASS |  |
| SUB-03 | Suspend → read-only (owner can still read); reactivate restores | PASS |  |
| SUB-04 | Reminder jobs: 7-day reminder queued once (dedupe) for a trial ending in 7 days | PASS |  |
| PAY-01 | Price published as a new plan version; checkout creates a server order for the exact tenant/plan/amount | PASS |  |
| PAY-02 | Only owner/admin of THAT tenant can create an order (A’s owner for B → 403) | PASS |  |
| PAY-03 | Bad signature → 400, logged as rejected, no access granted | PASS |  |
| PAY-04 | Amount mismatch → order flagged for review, no credit | PASS |  |
| PAY-05 | Valid payment.captured → credited once; period starts at trial end (contiguous); receipt issued | PASS |  |
| PAY-06 | Replay (same event id) → duplicate; same payment under a NEW event id / order.paid → no second credit | PASS |  |
| PAY-07 | Out-of-order payment.failed after capture never downgrades the paid order | PASS |  |
| PAY-08 | Renewal exactly at expiry starts at the old end (no gap/overlap); paying during grace continues from the old end | PASS |  |
| PAY-09 | Full refund revokes that period; partial refund is only flagged for review | PASS |  |
| PAY-10 | Offline payment by billing admin: same reference never credits twice | PASS |  |
| PAY-11 | Gateway gating: disabled → no orders and webhook 503; live keys without owner approval refused; test keys in live mode refused | PASS |  |
| PERM-01 | Cash Counter (closing only): permissions = closing.* only; owner sees everything allowed by plan | PASS |  |
| PERM-02 | Plan without payroll module removes payroll.* for EVERY user, owner included | PASS |  |
| MFA-01 | RFC 6238 SHA-1 test vectors (8 digits) and ±1 step window, replay of same step refused | PASS |  |
| ADM-01 | Admin without TOTP must set it up; wrong code refused; correct code accepted once | PASS |  |
| ADM-02 | Support break-glass: no access before owner approval; approved → read-only (writes 403); after expiry → ended | PASS |  |
| CLO-01 | Counter finalizes yesterday directly: status approved (final), accounting variance auto-posted once, no review task | PASS |  |
| CLO-02 | Daybook photo optional: added after final, listed, file readable by closing users; removal after final refused; PDF refused | PASS |  |
| CLO-03 | Counter cannot reopen a final day (needs admin correction with reason) | PASS |  |
| API-01 | Health is public; unauthenticated finance call → 401 UNAUTHENTICATED | PASS |  |
| API-02 | Login → tokens; finance call before choosing a branch → 409; select A1 → permissions returned | PASS |  |
| API-03 | Hostile: A’s token selecting B’s tenant/branch → 403; old token after re-select is revoked | PASS |  |
| API-04 | Cash Counter on the app: menus = [closing], home = closing; payroll / staff / reports routes → 403 | PASS |  |
| API-05 | Web and app identical: same route through f360/{route} and alias gives the same JSON for the owner | PASS |  |
| API-06 | Idempotency-Key header: same expense posted twice → one entry; read-only subscription → POST 402 SUBSCRIPTION_READ_ONLY | PASS |  |
| API-07 | Refresh rotation; reusing an old refresh token revokes the whole family; wrong device refused | PASS |  |
| API-08 | Revocation mid-session: owner removes the cashier → next app call fails immediately (403) | PASS |  |
| API-09 | Webhook endpoint over HTTP: bad signature 400; valid signature 200 processed | PASS |  |
| API-10 | Hostile HTTP: A’s owner token asks for B’s staff / supplier / item / file / payslip / payroll line / search / exports by id → never 200 with B data; writes by B id change nothing | PASS |  |
| WEB-01 | Public pages render (200) with security headers; no invented prices; sign-in form has CSRF token | PASS |  |
| WEB-02 | Browser sign-in → choose branch → app API works with session; legacy local login is disabled; CSRF missing → refused | PASS |  |
| WEB-03 | Owner portal: other owner cannot open A’s tenant via ?t= (falls back to own tenant); cash counter is sent away | PASS |  |
| WEB-04 | Platform admin portal is separate: restaurant owner session does not open it | PASS |  |
| PUR-01 | Supplier suggestion shows ledger balance, last bill and last items with last rate; rate change needs confirmation | PASS |  |
