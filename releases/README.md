# Finance 360 V30 — release files (NOT deployed, NOT published)

| File | What |
|---|---|
| `FINANCE360_V30_KSDD_IN.zip` | The ONE Hostinger upload for ksdd.in: `public_html/` (website, owner portal, Super Admin, `/api/v1`) + `ksdd_private/` (outside the web root: config template, storage) + docs. Install/staging/rollback: `docs/HOSTING_KSDD_IN.md` inside the ZIP. |
| `finance360-0.30.0-production-TESTKEY.apk` | Android release APK, `API_BASE=https://ksdd.in/api/v1/index.php` |
| `finance360-0.30.0-production-TESTKEY.aab` | Android App Bundle (build evidence) |
| `SHA256SUMS` | Checksums |

App: `com.finance360.app` (provisional) · 0.30.0 (30) · minSdk 24 · targetSdk 36.

**The app talks to `https://ksdd.in/api/v1/index.php`, which only exists after the V30 backend is installed on ksdd.in.**
Until then sign-in fails with a network/server error. For staging, rebuild with the staging address:
`flutter build apk --release --flavor production --dart-define=API_BASE=https://staging.ksdd.in/api/v1/index.php`

**Signing:** temporary session TEST key (`CN=Finance 360 SESSION TEST KEY - NOT FOR PLAY`,
SHA-256 `d314b7d1e6837629405901f93a773dfda708a3ce79ba46821bf32c7b97da6a09`). Google Play will not accept it; rebuild with
your own upload key (`F360_STORE_FILE`, `F360_STORE_PASSWORD`, `F360_KEY_ALIAS`, `F360_KEY_PASSWORD`). Uninstall this
build before installing one signed with your key.

Tests: see `site/docs/TEST_REPORT_V30.md`. Not installed on a device here (no emulator).
