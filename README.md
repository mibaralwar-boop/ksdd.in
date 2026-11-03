# Finance 360 — ksdd.in (one website, one backend, one shared database, many restaurants)

**Current version: V34** = your latest V31 source (`public_html_3.zip`) + V34 security, hosting and migration fixes.

| Folder | Contents |
|---|---|
| `v34/public_html/` | Website, owner portal, Super Admin, `/api/v1` (+ `tests/`, not uploaded) |
| `v34/ksdd_private/` | Outside-web-root template: `config.example.php` (production), `config.staging.example.php`, storage folders |
| `v34/docs/` | `V34_STAGING_GUIDE.md`, `V34_GO_NO_GO.md`, `V34_ISSUE_REGISTER.md`, `V34_TENANT_ISOLATION_REPORT.md`, `V34_FEATURE_CHECKLIST.md`, `evidence/` |
| `releases/v34/` | `FINANCE360_V34.zip` (upload package) + Android 0.34.0 test-signed APK/AAB |
| `releases/v38/` | Android 0.38.0 (V38 salary wizard, salary payments, staff add/left, force-update client) staging APK + source — docs `docs/v38/` |
| `releases/v36/` | Android 0.36.0 (V36 orange/cream design, native screens) staging APK + Flutter source ZIP — report `docs/V36_APP_REPORT.md` |
| `flutter_source/` | Native Flutter Android app |
| `site/`, `backend_v29/`, `archive_v28/`, `releases/*.zip/apk` (V30) | Superseded earlier versions, kept for reference only — do not install |

Nothing has been deployed to ksdd.in or staging.ksdd.in; `u245900320_ksdd` and `u245900320_staging` were not touched.
