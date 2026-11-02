# Finance 360 — Khadak Singh Da Dhaba (multi-restaurant)

| Folder | Contents | Status |
|---|---|---|
| `backend_v29/` | V29 PHP/MySQL website + `/api/v1` (multi-restaurant, database per branch), migrations, docs, OpenAPI | Supplied V29 release; platform suite re-run here: 64/64 passed. **Not deployed.** |
| `flutter_source/` | Native Flutter Android app 0.29.0 for the V29 API | analyze clean; 22 unit/widget + 11 live API tests passed |
| `releases/` | Test-signed release APK + AAB, checksums | Session test key — **not for Play**; no server URL configured |
| `docs/APP_TEST_REPORT.md` | What was tested, how, and what is not done | |
| `archive_v28/` | Superseded V28 foundation (f28 API, audit, docs) | Kept for history only |

Nothing here has been deployed or published. Production deployment, live database migration and Play publication need
the owner's explicit approval. Secrets (`config.php`, keystores, passwords) are never committed.
