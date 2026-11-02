# Finance 360 Android app 0.29.0 — test report

Date: 2 Nov 2026. Environment: Linux container, Flutter 3.47.9 (stable, SHA-256 verified download), Android SDK 36,
JDK 21, PHP 8.3.6, MariaDB 10.11. Statuses: **Passed / Failed / Blocked / Not run** — lint is not counted as testing.

## Summary

| Suite | Result | What it proves |
|---|---|---|
| Backend V29 platform suite (`backend_v29/public_html/tests/platform_tests.php`) | **64 passed, 0 failed** (re-run here) | The backend's own isolation / registration / trial / billing / API tests, independently reproduced |
| `flutter analyze` | No issues | Static analysis only |
| `flutter test` unit + widget | **22 passed, 0 failed** (+1 live test skipped) | App logic and screens using real captured server responses |
| `tool/live_test.sh` live API tests | **11 passed, 0 failed** | The app's own `Api`/`Session` code against the real PHP backend + MariaDB |
| Release APK build + `apksigner verify` | Passed | Signed with a session test key (not your upload key) |
| Release AAB build + `jarsigner -verify` | Passed | Same test key; not validated by Play Console |
| Install / run on device or emulator | **Not run** | No emulator/KVM in this environment |
| Screenshots | **Not run** | Same reason |
| Real Hostinger staging / live database | **Blocked** | No server URL / database snapshot supplied |

## Live API tests (real backend)

`tool/live_test.sh` copies `backend_v29/public_html`, rebuilds disposable `*_test` databases with the backend's platform
suite (demo data: KSDD adopted tenant + "Alpha Dhaba" + "Alpha Two"), pins the server clock to 3 Nov 2026 08:00 IST and runs:

1. Health endpoint is public — Passed
2. Cash Counter: single membership opens directly; server `menus` = `[closing]`, home = closing — Passed
3. Cash Counter: salary, suppliers, reports return **403 from the server** (not just hidden) — Passed
4. Cash Counter cannot select another restaurant; same 403 for a non-existent one — Passed
5. Previous-day closing: sales (₹25,000 / ₹3,000 / ₹4,000.50 → total 3200050 paise), stale version → 409, server refuses
   "No expenses" when expenses exist, **final without admin approval**, retry with same Idempotency-Key → `duplicate`,
   day locked (`approved`), further save refused — Passed
6. Daybook photo: JPEG accepted after finalising and listed; PDF refused — Passed
7. Refresh rotation: new pair issued; replay of old refresh → 401 and the whole session family revoked — Passed
8. Owner: dashboard reads the ledger; every module visible — Passed
9. Salary Oct 2026: divisor 31, Present 28 + Half 2 → payable 29, earned = monthly × 29 / 31 (server), save → completed,
   stale version → 409, next pending employee is in the same department — Passed
10. Purchase: supplier suggestion with last rate; changed rate → 409 `rates_ok` with rate changes; confirmed → posted once;
    retry with same key → `duplicate`; last rate updated only after posting — Passed
11. Read-only restaurant (second restaurant of the same owner gets **no second trial**): reads work, write → 402,
    write actions removed from permissions — Passed

## Widget / unit tests (captured real responses)

Money formatting in Indian grouping and exact paise parsing; permission-only navigation for Cash Counter, Storekeeper and
Owner fixtures; HTTPS-only rule; device id + `__path` routing + Idempotency-Key; single refresh for parallel 401s; revoked
refresh → signed out; 409 `needs` and 402 surfaced; offline errors and offline sign-out; revoked membership → back to the
chooser; unconfigured build blocks sign-in; Cash Counter has no bottom bar and no other modules; Storekeeper tabs; Owner
dashboard figures; read-only banner; full closing wizard incl. reason required above ₹500 and lost-reply finalize retry
with the same key; salary six inputs + server preview + Save & Next to the next pending employee; purchase suggestion,
Old → New rate, confirmation dialog and resubmission; no GST field sent.

A layout defect found by the widget tests (long amount rows overflowing on narrow phones) was fixed.

## Not covered / outstanding (honest list)

* Not implemented natively in the app (server allows them; app shows "use the website"): cash book, cash handover,
  supplier ledger/payments screen, document inbox, accounts, user/permission administration, stock receive / consume /
  physical count, salary payments, staff edit, corrections approval, Super Admin portal (web only by design).
* Push notifications (FCM): not wired (no Firebase project); 6 AM reminders are server-side only.
* Encrypted offline drafts: not implemented — salary/closing drafts are saved on the server; on network failure the
  typed values stay on screen and are not lost while the screen is open.
* Hindi: navigation and key labels only; full localisation pending.
* Device testing (camera/gallery, lifecycle, tablets, accessibility), screenshots, Play pre-launch report: not run.
* Backend blockers from `backend_v29/docs/AUDIT_AND_REQUIREMENT_MATRIX.md` still apply (live schema/snapshot, Sept 2026
  salary Excel reconciliation, Razorpay/OTP/SMTP credentials, Play billing decision, inter-branch transfers).
