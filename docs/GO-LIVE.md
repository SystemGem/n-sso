# Go-live checklist

## Before go-live: confirm with N+ (support@netcomplus.com)

The N+ SSO API documentation leaves some values open. The plugin has a setting or filter for each one, so no code change is needed once N+ answers.

| # | Question | Plugin default | Where to change |
|---|---|---|---|
| 1 | Production **and staging** base URLs? | `https://learn.nplus.global` | `NPLUS_SSO_API_BASE_URL` |
| 2 | Are the Create User and Auto Login HMAC secrets the same key? | same secret | `NPLUS_SSO_AUTOLOGIN_SECRET` if different |
| 3 | Accepted values for `payment_status`? | `completed` | Settings > Payment status value |
| 4 | Exact format of `subscription_startdate`? | `Y-m-d H:i:s` (site timezone) | Settings > date format |
| 5 | Format of `phone_country_code`: `+91` or `91`? | `+91` | filter `nplus_sso_create_user_payload` |
| 6 | Value for `source`? | `website` | Settings > Enrollment source |
| 7 | `roleid` for learners? | `5` | Settings > N+ role ID |
| 8 | Is `website_orderid` deduplicated (is a retry safe)? | we send a stable `{order}-{item}` | filter `nplus_sso_create_order_payload` |
| 9 | How long is a `timestamp` valid? | N/A (we sign at click time) | Make sure the server clock is NTP-synced |
| 10 | Is there an API to cancel a subscription on refund? | none in the docs: manual via support | `nplus_sso_access_revoked` action |
| 11 | Campaign ID + Subscription SKU for each product we sell | N/A | Product data > N+ Learning |
| 12 | Should N+ send its own welcome email (`sendmail`)? | `1` | Settings checkbox |
| 13 | Do they need our server IP allow-listed? | N/A | N/A |

## Go-live steps

1. Put the credentials in `wp-config.php` (`NPLUS_SSO_*` constants). Never commit them.
2. Install and activate the plugin on **staging** first and point it at the N+ staging URL (or at the bundled mock server).
3. Map one test product, buy it with a test gateway and click **Access my N+ learning**. You should land signed in on N+.
4. Check the order notes, the "N+ Learning" box on the order, and **WooCommerce > Status > Logs > nplus-sso**.
5. Test a second purchase by the same learner, a guest purchase and a refund.
6. Repeat on production with one real low-value order, then map the remaining products.
7. Turn off **Debug logging** after go-live. Errors are still logged.
8. Make sure WP-Cron or a real cron runs, so Action Scheduler retries happen. On low-traffic sites, add a system cron that calls `wp-cron.php`.

## Operations

- **Stuck order**: Orders > open the order > N+ Learning box shows the reason → fix it → Order actions > **Sync to N+**.
- **Learner says the button doesn't work**: check their orders are Processing/Completed and have the item status "Assigned". Check the log for `N+ launch` and for any N+ error.
- **Refund**: the plugin removes website access automatically. Email N+ support the N+ order ID from the order note so they cancel the subscription.
