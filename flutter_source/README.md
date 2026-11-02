# Finance 360 native Android source — staging foundation

This is **incomplete, uncompiled Flutter source**, not a validated Android release. Financial screens and tenant-safe financial integration are not implemented. See the top-level README and blockers. No WebView or second financial database is used.

Included native screens: sign-in; manual-verification-aware restaurant registration; authorized restaurant/branch selection; account/name metadata; trial status; branch list/create; server-driven metadata navigation; sign-out and account deletion review. API reads the implemented `f28-api.php` foundation routes. Financial modules are not represented by mock balances or disconnected sample records.

## Secure configuration in an owner-approved build environment

`API_BASE` is the HTTPS URL of the staging application's **actual** `f28-api.php`, not a guessed `/api/v1` URL. The logical route is sent as a query parameter. There is no API secret in the app. An unconfigured build clearly disables sign-in.

Once a separate build environment is approved and safe to run:

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --debug --flavor staging --dart-define=API_BASE=https://YOUR_STAGING_HOST/f28-api.php
```

These commands **were not executed here**. Do not publish the resulting foundation app. Review dependency resolution, preserve the generated `pubspec.lock`, repair analyzer/test/device failures and complete the outstanding financial implementation before release acceptance. Current Android scaffold was derived from Flutter 3.47.9 templates (AGP 9.1.0, Gradle 9.3.1, Kotlin plugin 2.4.0, compile/target SDK 36). Its compatibility is unverified. Use the matching approved Flutter/JDK/SDK toolchain and validate before changing versions.

Debug-only local test mode requires BOTH `ALLOW_LOCAL_HTTP=true` and a loopback/emulator URL (`localhost`, `127.0.0.1`, `10.0.2.2`). Production code rejects non-HTTPS; release manifest disables cleartext and OS backup. Do not use HTTP on a real financial service.

## Release signing

Application ID `com.finance360.app` and version 0.28.0+28 are provisional. Staging adds `.staging`. Confirm ownership and package identity before a Play listing.

Release Gradle tasks fail unless all four owner-controlled environment values exist:

- `F360_STORE_FILE`: local keystore path in the secured build environment
- `F360_STORE_PASSWORD`
- `F360_KEY_ALIAS`
- `F360_KEY_PASSWORD`

No key, password or debug-key release fallback is included. Do not put these values in chat, git or a distributed ZIP. Once the full app passes tests in an approved environment and signing is configured, production commands are:

```bash
flutter build apk --release --flavor production --dart-define=API_BASE=https://YOUR_APPROVED_HOST/f28-api.php
flutter build appbundle --release --flavor production --dart-define=API_BASE=https://YOUR_APPROVED_HOST/f28-api.php
```

No such production artifacts have been built. The package currently contains no APK/AAB and no generated dependency lockfile. SDK/device tests, secure storage lifecycle, text scaling, localization completeness and platform plugin behavior still need execution.
