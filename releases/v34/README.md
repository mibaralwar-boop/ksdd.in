# Finance 360 V34 release (NOT deployed, NOT published)

| File | What |
|---|---|
| `FINANCE360_V34.zip` | Upload package: `public_html/` → staging web root, `ksdd_private/` → `staging_private/` (see `docs/V34_STAGING_GUIDE.md` inside). Also `tests/` (local test suite) and `docs/` (reports + evidence). `SHA256SUMS.txt` inside. |
| `finance360-0.34.0-staging-TESTKEY.apk` | Android app for staging: `API_BASE=https://staging.ksdd.in/api/v1/index.php` (app id `com.finance360.app.staging`) |
| `finance360-0.34.0-production-TESTKEY.apk` / `.aab` | Production builds: `API_BASE=https://ksdd.in/api/v1/index.php` |
| `SHA256SUMS` | Checksums |

Signed with a temporary session TEST key (`CN=Finance 360 SESSION TEST KEY - NOT FOR PLAY`) — Google Play will not accept
it; rebuild with your own upload key. Not installed on a device here.
