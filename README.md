# Finance 360 — ksdd.in (one website, one backend, one database, many restaurants)

| Folder | Contents | Status |
|---|---|---|
| `site/public_html/` | V30 integrated website + backend + `/api/v1` + Super Admin (+ `tests/`, not uploaded) | All local suites pass (see report) |
| `site/ksdd_private/` | Outside-web-root folder: config template, storage skeleton | — |
| `site/docs/HOSTING_KSDD_IN.md` | Install, staging (`u245900320_appksdd`), production migration, rollback | Follow in order |
| `site/docs/TEST_REPORT_V30.md` | What was tested and what is blocked | |
| `flutter_source/` | Native Flutter Android app 0.30.0 | analyze clean, 22 unit/widget + 11 live API tests |
| `releases/` | Hostinger ZIP, test-signed APK + AAB | Not deployed; test key — not for Play |
| `backend_v29/`, `archive_v28/` | Supplied V29 release and V28 foundation, kept for reference | Superseded by `site/` |

Nothing has been deployed to ksdd.in and the production database `u245900320_ksdd` has not been touched.
