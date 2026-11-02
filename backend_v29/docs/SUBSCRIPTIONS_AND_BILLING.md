# Subscriptions, trials and billing runbook (V29)

## 1. States (computed, never stored as "truth")
`end` = latest `ends_at` of non-revoked rows in `p_sub_periods` (trial, payment, admin extension, legacy grant).

| State | When | Access |
|---|---|---|
| trialing | trial period running | full |
| active | paid / complimentary period running (also when "do not renew" is set, until the end) | full |
| grace | paid period ended less than `grace_days` ago (plan version, default 3) | full + "payment due" banner |
| expired | after end (+grace for paid) | **read-only**: sign in, view, export, billing |
| cancelled | owner chose not to renew and the period ended | read-only |
| suspended | Super Admin suspended (reason shown) | read-only |
| none | restaurant never had a trial or payment (e.g. owner's 2nd restaurant) | read-only until a plan is bought |

Read-only = HTTP **402 `SUBSCRIPTION_READ_ONLY`** on every POST except snooze / own password; nothing is ever deleted. Storage UTC; shown in IST.

## 2. Trial
* Automatic, once per **owner account** (`p_trial_grants.auto_key = owner:<id>`), not per device/phone/branch.
* Length = Super Admin setting `trial_days` (default 30). Ends **23:59:59 IST on sign-up date + N days** and is shown before sign-up.
* The founding restaurant (KSDD) gets a *complimentary* period instead (default 365 days, `--complimentary-days`), recorded as legacy.
* Super Admin can extend (reason required, logged); extensions start at max(now, current end) → no gaps/overlaps.

## 3. Plans and prices
Seeded plan versions have **₹0 and are inactive**: nothing is purchasable and the pricing page says prices will be published.
Super Admin → Plans → "Publish a price" creates a **new version** (old one deactivated for new purchases; existing subscribers keep theirs).
Price is for the whole restaurant organisation (not per branch). Quotas: max branches, max users, modules.

## 4. Payments (Razorpay) — **not activated**
Config `platform.billing`:
```php
'billing' => ['provider' => 'razorpay', 'mode' => 'disabled',        // disabled | test | live
              'key_id' => 'rzp_test_…', 'key_secret' => '…', 'webhook_secret' => '…',
              'live_approved' => false,                            // must be true (set by you) for live keys to work
              'allow_app_checkout' => false, 'app_may_link_web' => false],   // see §5
```
Flow: owner clicks *Pay & renew* → server creates `p_orders` row for that tenant + plan version + amount → Razorpay order →
hosted checkout → **only the signed webhook credits access** (`billing-webhook.php`, header `X-Razorpay-Signature` = HMAC-SHA256 of the raw
body with the webhook secret; `X-Razorpay-Event-Id` de-duplicates). A browser "success" redirect never activates anything.
Events handled: `payment.captured`, `order.paid` (credit once), `payment.failed` (never downgrades a paid order), `refund.processed`
(full refund revokes that order's period; partial → flagged), `payment.dispute.created` (flagged for billing admin).
Amount/currency mismatch → order `review`, no credit. Renewal start = max(now, current end) or the old end while in grace → no gap, no overlap.
Offline payments (bank transfer): Super Admin → restaurant → *Record offline payment* (UTR must be unique; same path, same idempotency).

**To activate (needs your approval):**
1. Razorpay account KYC complete; create **test** keys; set `mode => 'test'` on *staging*; add webhook URL `https://<staging>/billing-webhook.php`
   with events above and a webhook secret.
2. Implement the hosted checkout script on `owner.php?p=pay` (Razorpay Checkout JS needs a CSP exception for `checkout.razorpay.com`;
   not added yet — **Blocked** on keys). Run a ₹1 test payment; confirm the webhook appears as *processed* in Super Admin → Payments.
3. Have your accountant confirm GST on subscription fees and the receipt/invoice format (Super Admin → Settings → seller details).
4. Publish real prices; then switch production to `live` keys and set `live_approved => true` yourself.

## 5. Google Play (Android app)
Google Play's Payments policy requires Play's billing system for in-app purchases of digital goods/subscriptions unless an exception applies,
and apps may not lead users to other payment methods unless the policy permits it. For India, Google offers *user choice billing* (an
alternative billing system offered **alongside** Play billing, with a service fee) and a *consumption-only* model (nothing can be bought in the app).
Default in this build: **consumption-only** — `POST /subscriptions/checkout` returns 403 and no web payment link is returned to the app.
Before changing `allow_app_checkout` or `app_may_link_web`, review the current *Payments policy* in Google Play Console Help (and Play's user choice billing FAQ for India) with your advisor. This is a business/legal decision, not code.

## 6. Reminders
`platform-cron.php` queues (once per period end) emails at 7, 3, 1 days, on the day, in grace and after expiry. Email is sent with your
Hostinger mailbox when `platform.mail.mode = 'smtp'`; in staging they are only stored (`logged`).
