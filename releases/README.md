# Finance 360 0.29.0 — test builds (NOT for Google Play, NOT a production release)

| File | What |
|---|---|
| `finance360-0.29.0-production-TESTKEY.apk` | Release APK (installable, sideload) |
| `finance360-0.29.0-production-TESTKEY.aab` | Release App Bundle (build evidence only — see signing) |
| `SHA256SUMS` | Checksums |

Application ID `com.finance360.app` (provisional), versionName 0.29.0, versionCode 29, minSdk 24, targetSdk 36.
Built with Flutter 3.47.9 stable: `flutter build apk|appbundle --release --flavor production`.

**Signing:** both files are signed with a temporary key generated during a Claude Code session
(`CN=Finance 360 SESSION TEST KEY - NOT FOR PLAY`, certificate SHA-256
`d314b7d1e6837629405901f93a773dfda708a3ce79ba46821bf32c7b97da6a09`). The key is not in this repository. Google Play
will not accept these as your upload; rebuild with your own upload key. A build signed with your key cannot install
over this one — uninstall this first.

**Server:** built **without `API_BASE`**, so the app opens to "no configured secure server" and sign-in is disabled.
Rebuild with `--dart-define=API_BASE=https://YOUR_DOMAIN/…/api/v1/index.php` once the V29 backend runs on a staging copy.

**Verified:** `apksigner verify` OK, `jarsigner -verify` (AAB) OK, `flutter analyze` no issues, `flutter test` 22 passed,
live API tests 11 passed against the real V29 backend on a local staging server (see `../docs/APP_TEST_REPORT.md`).
**Not done:** installation on a device/emulator in this environment, screenshots, Play bundle upload validation.
