# Architecture

## Where the integration lives

```
┌──────────────────────── Our business ────────────────────────┐        ┌──────── N+ (external) ────────┐
│                                                              │        │                               │
│  WordPress + WooCommerce  ── Edwiser Bridge ──►  Our Moodle  │        │  learn.nplus.global (Moodle)  │
│   • products, checkout,                         LMS (own     │        │   /webservice/rest/server.php │
│     payments, accounts                           courses)    │        │   /auto-login/                │
│   • nplus-sso plugin  ───────────────── HTTPS ───────────────┼───────►│                               │
│                                                              │        │                               │
└──────────────────────────────────────────────────────────────┘        └───────────────────────────────┘
```

**Decision: a WordPress plugin, not a Moodle plugin.**

- The trigger is a **paid WooCommerce order**. It exists only in WordPress.
- The N+ APIs are partner-side calls. They need the buyer's details (name, email, phone, country), the order ID, the amount, the currency and the payment status, and all of that is WooCommerce data.
- The learner's "home" is their WordPress account (My Account, order emails), so that is where the SSO button belongs.
- Edwiser Bridge only syncs *our* Moodle courses and users. A Moodle-side plugin would never see the purchase. It would need its own copy of order data and could not show the button at checkout.
- N+ is itself Moodle-based (`/webservice/rest/server.php`, `wstoken`), but it is **their** Moodle. We only call it. Nothing needs installing in our Moodle.

The plugin is independent of Edwiser Bridge. Both hook into the same WooCommerce order, so one product can enrol the learner in our Moodle course (Edwiser) **and** grant N+ access (this plugin).

## Components (`includes/`)

| Class | Responsibility |
|---|---|
| `Signer` | HMAC-SHA256 signatures exactly as in §6 of the N+ docs |
| `Api_Client` | HTTP calls to the three N+ APIs; error normalisation (HTTP errors, Moodle `exception` payloads, `status != success`, invalid JSON) |
| `Settings` | Option storage, `wp-config.php` constant overrides, admin screen |
| `Product_Fields` | "N+ Learning" product tab: Campaign ID and Subscription SKU (with variation overrides) |
| `Provisioner` | Workflow for one order: ensure the N+ user exists, then assign each line item. Idempotent and locked per order |
| `Order_Handler` | WooCommerce status hooks, Action Scheduler background jobs, exponential-backoff retries, refund/cancel handling, "Sync to N+" order action |
| `Access` | Entitlement checks (who may launch N+) |
| `Sso` | `/?nplus-sso=launch` endpoint: authorises, provisions on demand, signs, redirects |
| `Account` | Thank-you box, My Account tab, order details button, email link, shortcode |
| `Admin_Order` | Order screen meta box |
| `Logger` | WooCommerce logger with secret redaction |

## Data stored

| Where | Key | Value |
|---|---|---|
| User meta | `_nplus_user_id` | N+ user ID (`data.id` from Create User) |
| Order meta | `_nplus_user_id` | N+ user ID (used for guest orders) |
| Order meta | `_nplus_has_enrollment` | `yes` once any item is assigned |
| Order meta | `_nplus_revoked` | `yes` after refund/cancel |
| Order meta | `_nplus_attempts` | automatic attempt counter |
| Line item meta | `_nplus_status` | `enrolled` / `failed` |
| Line item meta | `_nplus_orderid` | N+ order ID |
| Line item meta | `_nplus_website_orderid` | the `website_orderid` we sent (`{order number}-{item id}`) |
| Line item meta | `_nplus_error`, `_nplus_already_enrolled`, `_nplus_enrolled_at`, `_nplus_campaign_id`, `_nplus_subscription_sku` | diagnostics / history |

All order access goes through WooCommerce CRUD, so the plugin is compatible with HPOS (High-Performance Order Storage). Compatibility is declared.

## API field mapping

### Create User (`local_lms_create_user_site`)

| N+ field | Source |
|---|---|
| firstname / lastname | billing name (falls back to the WP profile) |
| email | WP account email (billing email for guests) |
| phone1 | billing phone |
| roleid | setting (default 5) |
| signature | `hash_hmac('sha256', email . ':' . timestamp, secret)` |
| timestamp | `time()` at the moment of the call |
| version | setting (default `v1`) |
| country | billing country (ISO code) |
| address | billing address lines, city, state, postcode |
| phone_country_code | WooCommerce calling code for the country, e.g. `+91` |
| company_name | billing company |
| partner_additional_info | JSON `{"site": …, "order_id": …}` |
| partner_userid | WordPress user ID (empty for guests) |

### Subscription Assignment (`local_lms_create_order`)

| N+ field | Source |
|---|---|
| website_orderid | `{order number}-{line item id}`: stable, so retries never duplicate |
| campaignid | product's N+ Campaign ID |
| userid | N+ user ID |
| quantity | line item quantity |
| payment | line total + tax, `1234.50` |
| payment_currency | order currency |
| payment_status | setting (default `completed`) |
| source | setting (default `website`) |
| subscription_skuid | product's N+ SKU (falls back to the product SKU) |
| subscription_startdate | order paid date, format from settings (default `Y-m-d H:i:s`) |
| sendmail | setting (default 1) |

### Auto Login

`{base}/auto-login/?uid={N+ user id}&timestamp={time()}&signature={hash_hmac('sha256', uid . ':' . timestamp, secret)}`. The URL is built in `Sso::maybe_launch()` at click time and sent as a 302 redirect.
