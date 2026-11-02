# Finance 360 0.28.0 — test APK (NOT a production or Play release)

File: `finance360-0.28.0-production-TESTKEY.apk` (application ID `com.finance360.app`, minSdk 24, targetSdk 36)

SHA-256: `f69271d9250c3df600593ec84556494f2b42d4a7492bb76d0977e88f1efcc799`

Signing certificate:
```
Signer #1 certificate DN: CN=Finance 360 SESSION TEST KEY - NOT FOR PLAY
Signer #1 certificate SHA-256 digest: d314b7d1e6837629405901f93a773dfda708a3ce79ba46821bf32c7b97da6a09
```

- This APK is signed with a **temporary key generated during a Claude Code session** and does not use your Play upload key. Google Play will not accept it. Later builds signed with your own key cannot install over it, so uninstall this one first.
- Built **without API_BASE**: the app opens to a "not configured" state and sign-in is disabled. Rebuild with your staging `f28-api.php` HTTPS URL to use it.
- Contents: V28 foundation only (sign-in, restaurant registration, restaurant/branch selection, trial status, branch management, sign-out, deletion request). No closing, salary, purchase, stock or accounting screens.
- Verified: `flutter analyze` reported no issues; `flutter test` passed 8 tests. **Not installed or tested on any device yet.**

Install: download the APK on your Android phone, allow "Install unknown apps" for your browser or file manager, then open the file.
