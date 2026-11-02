# Implemented API actions

Actual endpoint: `f28-api.php?route=/v1/ACTION`. This preserves the existing PHP host layout; no rewrite rule or fictional REST path is required. `openapi.json` documents this dispatcher and the actual action inventory.

All POST bodies are JSON objects (including `{}` for logout). Maximum 32 KiB. HTTPS required except explicitly configured loopback-only fixture servers. No CORS wildcard, browser-cookie authentication or auth tokens in URLs. Protected actions use `Authorization: Bearer ACCESS_TOKEN`. Scoped actions also require `X-Tenant-ID` and `X-Branch-ID`; these select a scope only after server membership validation. JSON responses use `data` or `error.code/error.message`; Cache-Control is no-store.

| Method/action | Authentication | Behavior |
|---|---|---|
| POST register | Public, IP-limited | owner_name, email, password (12–128 bytes), restaurant_name, address, city, branch_name optional, language en/hi, privacy_consent=true. Atomically creates pending-verification identity, tenant, branch, membership and 30-day trial. |
| POST login | Public, IP/account-limited | email/password; access and refresh tokens. This authenticates f28 accounts, not legacy dd_users. |
| POST refresh | Refresh token | Rotates refresh/access; replay revokes session family. |
| POST logout | Bearer | Revokes current session, including refresh family. |
| GET plans | Public | Configured available plan metadata; purchase_available=false. No checkout is implemented. |
| GET memberships | Bearer | Only explicitly assigned tenant/branch combinations. |
| GET context | Bearer + scope | Current effective permissions, server subscription state/time, identity verification and financial_scope_state. |
| GET branches | Bearer + scope + branches.view | Only explicitly assigned active branches. |
| POST branches/create | Bearer + scope + branches.create | name, address, cutoff_hour (integer 0–23, default 4); locks tenant subscription row, checks current branch cap, grants creator explicit branch membership. |
| POST branding | Bearer + scope + tenant.branding | name/language only. Logo/contact upload is not implemented. |
| POST account/deletion-request | Bearer | Idempotent review request, not automatic deletion of retained financial records. |
| GET financial/status | Bearer + scope | Always 503 financial_migration_pending. No financial read or posting routes exist in this foundation API. |
| POST platform/step-up | Bearer + explicit platform account + rate limit | code: six-digit TOTP; consumes a nonreplayed time counter and grants five-minute step-up. |
| GET platform/tenants | Platform membership + step-up | First 100 tenant metadata rows; no financial records or impersonation. Pagination still outstanding. |
| POST platform/trial | Platform membership + step-up | tenant_id, days=7/10/30, reason; audited trial-only extension; no paid-account renewal bypass. |
| POST platform/suspend | Platform membership + step-up | tenant_id/reason; audited suspension. Reinstatement/payment workflows are outstanding. |

Tokens are random 256-bit values, persisted only as SHA-256 on the server. Access lasts 15 minutes; rotating refresh lasts 30 days. Account/membership/subscription status is revalidated on each protected/scoped request. Rate limits are backed by SQL row locking. Administrative identity verification and platform provisioning are trusted local CLI procedures, not public browser APIs.

The new f28 identity layer is intentionally not connected to legacy global owner login. Registering a restaurant does not create a global legacy dd_users owner. A reviewed legacy-user mapping and tenant-safe financial service implementation are required before integrating old KSDD records.
