=== N+ SSO Integration ===
Contributors: systemgem
Tags: woocommerce, sso, lms, moodle, learning
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell N+ Learning Platform access with WooCommerce: provisions learners, assigns subscriptions and signs them in to N+ with one click.

== Description ==

When a customer pays for a WooCommerce product mapped to an N+ campaign, the plugin:

1. Creates the learner on N+ (Create User API, HMAC signed).
2. Assigns the purchased subscription (N+ Subscription Assignment API).
3. Gives the learner an "Access my N+ learning" button (thank-you page, My Account > N+ Learning, order emails, shortcode) that signs them in to N+ through the Auto Login API.

Background processing with automatic retries, an order meta box, order notes, refund handling and HPOS support are included.

== Installation ==

1. Upload and activate the plugin. WooCommerce is required.
2. Add the N+ credentials to wp-config.php (NPLUS_SSO_API_KEY, NPLUS_SSO_WSTOKEN, NPLUS_SSO_SECRET), or enter them in WooCommerce > N+ SSO.
3. Edit a product > Product data > N+ Learning: tick "Grant N+ access" and enter the N+ Campaign ID and Subscription SKU.

== Changelog ==

= 1.0.0 =
* Initial release.
