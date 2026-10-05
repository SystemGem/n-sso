# N+ SSO Integration for WordPress / WooCommerce

This WordPress plugin connects our WooCommerce store to the **N+ Learning Platform** (`learn.nplus.global`). It follows the *N+ SSO API Documentation*.

When a customer buys a product that is mapped to N+, the plugin:

1. **Creates the learner on N+.** It calls the Create User API (`local_lms_create_user_site`), signed with HMAC-SHA256 over `email:timestamp`.
2. **Assigns the purchased subscription.** It calls the N+ Subscription Assignment API (`local_lms_create_order`) once for each purchased line item.
3. **Signs the learner straight into N+.** The "Access my N+ learning" button calls the Auto Login API with a signature over `userid:timestamp`, generated on the server at click time.

The plugin works alongside our existing **WordPress → Edwiser Bridge → Moodle LMS** setup. The same WooCommerce product can still enrol the learner in our own Moodle course through Edwiser Bridge, and also grant N+ access.

> **Why a WordPress plugin and not a Moodle plugin?** The purchase, payment and customer account all live in WordPress/WooCommerce. N+ is an external platform, and its APIs are called *by the partner website*. Our own Moodle LMS never sees the purchase event: Edwiser Bridge only syncs our own courses. So the integration has to run where the order is paid, which is WordPress. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Learner journey

```
Customer buys product ──► WooCommerce order paid (processing/completed)
                               │
                               ▼  (background job via Action Scheduler, retried on failure)
                     N+ Create User API ──► N+ user ID saved on the WP user + order
                               │
                               ▼
                     N+ Subscription Assignment API (per line item) ──► N+ order ID saved on the item
                               │
Thank-you page / My Account > N+ Learning / order email
   "Access my N+ learning"  ──► https://our-site/?nplus-sso=launch
                               │  (checks login or order key, provisions on demand if the job hasn't run yet)
                               ▼
                     302 ──► https://learn.nplus.global/auto-login/?uid=…&timestamp=…&signature=…
                               │
                               ▼
                     Learner is signed in to N+ and sees the purchased material
```

The full step-by-step journey, including guest checkout, refunds and failures, is in [docs/USER-JOURNEY.md](docs/USER-JOURNEY.md).

## Installation

1. Build the zip (`bin/build-zip.sh` creates `dist/nplus-sso.zip`), or download it from the CI artifacts.
2. Go to **WordPress admin > Plugins > Add New > Upload Plugin**, upload the zip and activate it. WooCommerce must be active.
3. Add the credentials N+ issued to `wp-config.php`. This is recommended: the N+ docs say "do not hardcode credentials", and this keeps them out of the database.

   ```php
   define( 'NPLUS_SSO_API_BASE_URL', 'https://learn.nplus.global' );
   define( 'NPLUS_SSO_API_KEY', '…' );          // x-api-key header
   define( 'NPLUS_SSO_WSTOKEN', '…' );          // wstoken
   define( 'NPLUS_SSO_SECRET', '…' );           // HMAC secret
   // define( 'NPLUS_SSO_AUTOLOGIN_SECRET', '…' ); // only if N+ gives a separate Auto Login secret
   ```

   You can also enter them in **WooCommerce > N+ SSO**. Saved secrets are never shown again.
4. Check the other settings in **WooCommerce > N+ SSO**: role ID (default 5 = student), source, payment status value, sendmail, and which order statuses trigger provisioning.
5. Edit each product that should grant N+ access. In **Product data > N+ Learning**, tick **Grant N+ access** and enter the **N+ Campaign ID** and **Subscription SKU** from N+. Variations can override both.

That's it. From now on, every paid order for that product provisions the learner on N+.

## Where learners find the button

| Place | Notes |
|---|---|
| Order-received (thank-you) page | Works for guest checkout too (protected by the order key) |
| My Account > **N+ Learning** | Lists every N+ programme bought, with its status, plus the launch button |
| My Account > Orders > View order | Launch button under the order table |
| "Processing order" / "Completed order" emails | A launch link (contains no secrets) |
| Anywhere: `[nplus_sso_button text="Go to N+"]` | Shown only to learners with access |

## Admin tools

- **Order screen > "N+ Learning" box**: shows the N+ user ID, each item's status (Assigned / Failed + reason / Pending) and the N+ order ID.
- **Order actions > "Sync to N+"**: retries provisioning manually.
- **Order notes**: every N+ call (success or failure) is recorded.
- **Logs**: WooCommerce > Status > Logs, source `nplus-sso`. Secrets and signatures are always redacted.
- **Automatic retries**: failed provisioning is retried with exponential back-off (5 min, 10 min, 20 min …, up to "Max automatic attempts").
- **Refunds and cancellations**: the website stops offering the launch button for that order. N+ has no cancellation API, so the order note names the N+ order ID to send to N+ support.

## Security

- Signatures are only ever generated on the server, at the moment of the click, with a fresh timestamp. Pages and emails link to our own `/?nplus-sso=launch` endpoint and never to a pre-signed N+ URL.
- The launch endpoint only signs in **the logged-in owner** of an active enrollment, or the buyer of a specific order identified by its secret WooCommerce order key (guest checkout, which can be disabled).
- HTTPS is enforced for the N+ base URL.
- Secrets can live in `wp-config.php` constants, are never printed back into the settings form, and are redacted from all logs.

## Developer hooks

| Hook | Type | Purpose |
|---|---|---|
| `nplus_sso_create_user_payload` | filter | Change Create User fields (e.g. `phone_country_code` format) |
| `nplus_sso_create_order_payload` | filter | Change Subscription Assignment fields (e.g. `website_orderid`, date format) |
| `nplus_sso_product_config` | filter | Map products to campaigns in code |
| `nplus_sso_user_has_access` | filter | Custom entitlement rules |
| `nplus_sso_async_provisioning` | filter | `false` = provision during checkout instead of in the background |
| `nplus_sso_user_created`, `nplus_sso_subscription_assigned`, `nplus_sso_access_revoked`, `nplus_sso_provisioning_gave_up`, `nplus_sso_before_launch` | actions | Integrations, notifications |

Functions: `nplus_sso_get_user_id()`, `nplus_sso_user_has_access()`, `nplus_sso_launch_url()`, `nplus_sso_provision_order()`.

## Testing

```bash
composer install && composer test   # 26 unit tests: signatures, API requests/responses, errors, redaction, settings
```

**End-to-end.** This builds a throw-away WordPress + WooCommerce site on SQLite, runs a **mock N+ server** that validates the API key, token, HMAC signatures and timestamps the way the docs describe, then drives real purchases in Chromium:

```bash
cd tests/e2e && npm install && cd ../..
WP_SRC=/path/to/wordpress WC_SRC=/path/to/woocommerce \
SQLITE_SRC=/path/to/sqlite-database-integration/packages/plugin-sqlite-database-integration \
WP_CLI="php /path/to/wp-cli.phar" tests/e2e/run.sh
```

What the tests cover:

- **Browser journey (Playwright).** A registered learner buys, sees the thank-you box, clicks once and lands in N+. A second purchase reuses the same N+ user. Guest checkout works, and a forged order key is rejected. Logged-out visitors are sent to log in. Non-N+ products never call N+.
- **Server scenarios (`tests/e2e/scenarios.php`).** N+ outage → failure recorded → retry scheduled → retry succeeds. Provisioning is idempotent across status changes. Refund removes access and re-completing restores it. Unpaid orders are not provisioned. Emails contain the launch link but no signature. N+ rejects tampered or expired auto-login links.
- The run fails if the plugin raises any PHP notice.

CI (`.github/workflows/ci.yml`) runs the unit tests on PHP 7.4, 8.1 and 8.3, runs the full end-to-end suite, and publishes the installable zip.

You can also use the mock server on its own to try the plugin on a staging site before N+ issues production credentials:

```bash
php -S 127.0.0.1:8099 tests/e2e/mock-nplus/router.php
```

## Go-live checklist

See [docs/GO-LIVE.md](docs/GO-LIVE.md). It includes the questions to confirm with N+ (support@netcomplus.com) where their documentation leaves a value open.
