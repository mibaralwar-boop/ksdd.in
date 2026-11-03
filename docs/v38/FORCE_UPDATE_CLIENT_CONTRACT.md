# Finance 360 V38 — force update: client contract (app 0.38)

**Status: client implemented and tested; server policy route and server enforcement NOT implemented (backend items A1–A4).**
With the current backend the check answers 401/404 → "no policy" → the app runs normally.

## When the app checks
App start, right after sign-in, every resume, and every 30 minutes. Request:
`GET api/v1/index.php?__path=app/version-policy&platform=android&channel=<play|direct>&package=<applicationId>&version_code=<int>`
(no token). Every API request also carries `X-App-Platform`, `X-App-Package`, `X-App-Version-Code`, `X-App-Version-Name`,
`X-App-Channel` so the server can enforce later.

## Decision (integers, never version names)
| Condition | Result |
|---|---|
| no / invalid policy | run normally |
| `version_code < minimum_supported_version_code` | **Update required** |
| `force_update = true` and `version_code < latest_version_code` | **Update required** |
| `version_code < latest_version_code` | optional banner (dismissible) |
| otherwise | run normally |

## Update required screen
Replaces the whole app (all open pages are closed first — payment sheets, forms). Back button cannot leave (PopScope). Shows title,
message, installed version, minimum build, destination. Buttons: **Update now**, **I have updated — check again**, **Sign out**.
Every POST is refused inside the app with `UPDATE_REQUIRED` before it reaches the network ("Nothing was saved").

## Where "Update now" goes (no URL is built into the app)
* `destination_type = play_store`: only `market://details?id=<installed package>` then `https://play.google.com/store/apps/details?id=<installed package>`.
  A server URL may only confirm that listing; any other app id / host → refused.
* `destination_type = external_apk`: only `https://` on a host in the build-time allow-list `UPDATE_TRUSTED_HOSTS`
  (default `ksdd.in,staging.ksdd.in`), port 443, no user-info. HTTP, look-alike hosts (`staging.ksdd.in.evil.example`), other ports,
  `javascript:` → refused; the page then says to contact the owner and the block stays.
* `package_id` in the policy must equal the installed package (staging `com.finance360.app.staging` ≠ production `com.finance360.app`).
* Android always asks the user to approve an APK install; the app cannot install silently and does not try. `apk_sha256` is shown
  for checking; the app does not download/verify the file itself (browser + Android installer). Android also refuses an APK
  signed with a different key over the installed app.

## Offline / server down
* A fetched policy is cached in encrypted storage with `max_cache_age` (default 6 h, 1 min – 7 days).
* Check fails + cache still fresh → the cached decision applies (a mandatory update stays mandatory offline).
* Check fails + no fresh cache → run normally and keep retrying (fail-open), so a server outage cannot lock every user out.
  This is why server enforcement (426 on writes, item A3) is required for real security.
* 401/404/405 (route not deployed) → cache cleared, run normally.

## Build settings
`--dart-define=DIST_CHANNEL=direct|play` (staging APK: `direct`), `--dart-define=UPDATE_TRUSTED_HOSTS=ksdd.in,staging.ksdd.in`.
versionCode comes from `pubspec.yaml` (`0.38.0+38` → 38) via `package_info_plus`.

## Tests executed (`test/v38_test.dart`)
integer comparison incl. string "9" vs 10; optional vs required; force flag; HTTP / foreign host / look-alike host / user-info / port /
javascript refused; Play only for the installed package; production policy refused on the staging app; 401 = no policy; fresh cache
keeps a mandatory block offline; expired cache ignored; whole-app replacement closes an open page; back cannot leave; Update opens
exactly the policy URL; POST refused locally with UPDATE_REQUIRED and never reaches the server.
Not executed: real device install of a newer APK, Play in-app update API (needs a Play listing).
