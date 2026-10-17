# Go-live checklist

## Current N+ setup (Hrishikesh, 16 Oct 2026)

| Item | Value |
|---|---|
| Base URL | `https://learn.netcomplus.com` (no Cloudflare challenge for our server) |
| x-api-key | `NzM=` (partner 73) for all three calls |
| Create User / Assign Subscription | main wstoken + `local_lms_apis_clone_create_user_site` / `local_lms_apis_clone_create_order` |
| Auto Login | its own wstoken (setting "Auto Login web service token"), same x-api-key, `cid` empty |
| Test campaigns | `846109` (alternative `528808`), SKU `10877` |
| Signature | HMAC-SHA256 of the timestamp with the SSO secret (verified: Create User succeeded from our server on 15 Oct, N+ user 246367) |

## What N+ confirmed earlier (staging credentials email)

| Item | Value |
|---|---|
| Staging base URL | `https://stagelms.nplus.global` |
| Create User wsfunction (staging) | `local_lms_apis_clone_create_user_site` |
| Assign Subscription wsfunction (staging) | `local_lms_apis_clone_create_order` |
| Auto Login | web service `local_react_lms_apis_sso_autologin` (POST `uid`, `timestamp`, `signature`), **not** the GET `/auto-login/` URL from the PDF |
| Create User signature | `hash_hmac('sha256', timestamp, secret)`: **timestamp only**, not `email:timestamp`. Verified: N+'s signed sample request matches this exactly |
| Assign Subscription | now also takes `signature` + `timestamp` (also timestamp only, verified against their sample) |
| `subscription_startdate` format | `Y-m-d H:i:s` (plugin default) |
| `payment_status` example | `processing` |
| Test campaign | `889904` (valid until 31 Oct 2026) |
| Partner ID | `77` (the API key is this ID base64-encoded) |
| Auto Login signature | **timestamp only** — in N+'s Postman collection (15 Oct) the Auto Login and Create User requests carry the identical signature for the identical timestamp |
| Auto Login credentials | its **own x-api-key and wstoken** (Postman: different from the Create User ones) plus an optional `cid` field; settings "Auto Login API key" / "Auto Login web service token" |
| N+ hosts | `stagelms.nplus.global` (staging), `learn.nplus.global`, and `learn.netcomplus.com` (Postman collection) |
| Cloudflare | blocks our SiteGround server (34.174.37.99) with a JS challenge; requests from a normal office connection reach the API |

All of these are settings or `wp-config.php` constants (see the README).

## Still to confirm with N+ (support@netcomplus.com)

| # | Question | Plugin default until answered |
|---|---|---|
| 1 | **Auto Login credentials for partner 77.** Auto Login uses its own x-api-key and wstoken (Postman). Please send ours. | main API key + wstoken until provided; signing is timestamp only |
| 2 | **Auto Login response.** Please send a sample success response. Which field holds the login URL? | the plugin looks for `loginurl`, `login_url`, `url`, `redirect_url`… (also inside `data`), and accepts only `*.nplus.global` HTTPS links |
| 3 | Production base URL and production function names (are the `_clone_` names staging-only?) | `https://learn.nplus.global`, PDF names |
| 4 | Accepted `payment_status` and `source` values | `completed`, `website` |
| 5 | Which `subscription_skuid` goes with campaign 889904? (their sample used `10877`) | the product's N+ SKU field |
| 6 | Is `sendmail` still accepted on Assign Subscription? (not in their sample) | sent as `1` |
| 7 | Is `website_orderid` deduplicated, so a retry is safe? | we send a stable `{order}-{item}` |
| 8 | How long is a `timestamp` valid? Are login links one-time? | N/A (we call at click time) |
| 9 | Is there an API to cancel a subscription on refund? | manual, via N+ support |
| 10 | Allow-list our server IP 34.174.37.99 in Cloudflare (or skip the bot challenge for `/webservice/rest/server.php`) | blocking |

## Go-live steps

1. Put the credentials in `wp-config.php` (`NPLUS_SSO_*` constants). Never commit them.
2. Install and activate the plugin on **staging** first and point it at the N+ staging URL (or at the bundled mock server).
3. In **WooCommerce > N+ SSO > Test N+ connection**, run the test with campaign `889904`. All rows should say OK, and "Open N+ as the test learner" should sign you in. If the Auto Login row fails, send N+ the response shown there (question 1 and 2 above).
4. Map one test product to campaign `889904`, buy it with a test gateway and click **Access my N+ learning**. You should land signed in on N+.
5. Check the order notes, the "N+ Learning" box on the order, and **WooCommerce > Status > Logs > nplus-sso**.
6. Test a second purchase by the same learner, a guest purchase and a refund.
7. Repeat on production with one real low-value order, then map the remaining products.
8. Turn off **Debug logging** after go-live. Errors are still logged.
9. Make sure WP-Cron or a real cron runs, so Action Scheduler retries happen. On low-traffic sites, add a system cron that calls `wp-cron.php`.

## Operations

- **Stuck order**: Orders > open the order > N+ Learning box shows the reason → fix it → Order actions > **Sync to N+**.
- **Learner says the button doesn't work**: check their orders are Processing/Completed and have the item status "Assigned". Check the log for `N+ launch` and for any N+ error.
- **Refund**: the plugin removes website access automatically. Email N+ support the N+ order ID from the order note so they cancel the subscription.
