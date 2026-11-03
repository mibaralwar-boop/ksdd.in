# Finance 360 Android 0.36.0 (V36 design) — NOT published, NOT deployed

| File | What |
|---|---|
| `finance360-0.36.0-staging-TESTKEY.apk` | Staging app `com.finance360.app.staging`, label "Finance 360", talks ONLY to `https://staging.ksdd.in/api/v1/index.php` |
| `finance360-0.36.0-flutter-source.zip` | Full Flutter source (lib, tests, fixtures with demo data only, Android project; no keys, no build output) |
| `SHA256SUMS` | Checksums |

Report and test results: `docs/V36_APP_REPORT.md` (+ `docs/v36_evidence/`).
Signed with the temporary session TEST key (`CN=Finance 360 SESSION TEST KEY - NOT FOR PLAY`): fine for installing on
your phone, not accepted by Google Play. It installs next to a production build (different app id); if an older
0.34 staging build is installed, this one updates it.
No production APK/AAB was built for 0.36 — that needs your approval and your own upload key.
