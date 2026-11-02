# Draft only — not approved for publication

## Current implementation data flow

The staging foundation sends account name/email/password, restaurant name/address/city, branch metadata, privacy consent and API requests to the owner's configured HTTPS PHP server. Passwords and session tokens are hashed server-side; mobile session tokens are stored through flutter_secure_storage. The PHP platform MFA secret is encrypted under a derived form of the existing protected application key. Scoped metadata requests and administrative changes are audited.

No analytics, advertising SDK, payment collection, financial imports, camera/gallery, FCM or mobile Drive/AiSensy client is implemented in the new Flutter foundation. The existing PHP financial application contains additional data flows that must be included in the final product's privacy review after integration. Do not submit a final Data Safety declaration based solely on this draft.

Account deletion requests are recorded for administrator review. No automatic deletion or legal-retention promise is made. Operator identity, support contact, retention schedule, hosted privacy URL, deletion URL, processors, access/export handling and exact security/legal text require owner review before public enrollment or publication. The in-app registration notice is marked as a staging draft.

## Play payment decision

Official policy reviewed 2 November 2026:

- https://support.google.com/googleplay/android-developer/answer/10281818?hl=en
- https://support.google.com/googleplay/android-developer/answer/9858738?hl=en

Google's examples explicitly include cloud/business productivity/financial management software. A restaurant SaaS is therefore not automatically exempt merely because its customers are businesses. An in-app digital subscription normally requires Play Billing unless an applicable policy exception/program permits otherwise. Consumption-only distribution is possible, but links and purchase prompts must be assessed against the applicable policy/program. India alternative billing is not automatic permission to embed arbitrary external checkout.

Current foundation design has **no checkout or external payment link** and collects no money. Paid subscriptions, provider verification/webhooks/refunds and compliant purchase presentation remain unfinished. This is not a claim of approved Play compliance.

## Android release requirements

Official target SDK source reviewed 2 November 2026:

https://developer.android.com/google/play/requirements/target-sdk

For the ordinary mobile app, the source states that new apps/updates require API 36 from 31 August 2026. Source scaffold targets API 36; no build, device behavior or Play acceptance has been validated. Verify again at actual submission. Confirm account-specific testing/developer requirements, identity verification, privacy/deletion URLs, Data Safety, content rating and reviewer access in the owner's Play Console.

## Listing draft — future complete product only

English name: Finance 360
English short description: Restaurant accounts, staff, stock and daily closing in one place.
Hindi short description: रेस्टोरेंट के खाते, स्टाफ, स्टॉक और दैनिक क्लोजिंग एक जगह।

These describe the intended completed product, **not the current foundation**. Do not publish them until the features are implemented and verified. Screenshots/feature graphic/reviewer account, signed production AAB and policy documents are not delivered. There has been no deployment or publication.
