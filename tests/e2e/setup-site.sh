#!/usr/bin/env bash
# Builds a throw-away WordPress + WooCommerce site (SQLite, no MySQL needed) with this
# plugin installed and pointed at the mock N+ server.
#
# Required env:
#   WP_SRC      path to a WordPress core checkout
#   WC_SRC      path to an unpacked WooCommerce plugin
#   SQLITE_SRC  path to the sqlite-database-integration plugin directory (the one containing load.php)
#   WP_CLI      command for wp-cli (e.g. "php /path/wp-cli.phar")
# Optional: SITE_DIR (default ./.e2e-site), SITE_PORT (8088), MOCK_PORT (8099)
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SITE_DIR="${SITE_DIR:-$PLUGIN_DIR/.e2e-site}"
SITE_PORT="${SITE_PORT:-8088}"
MOCK_PORT="${MOCK_PORT:-8099}"
WP="${WP_CLI} --path=$SITE_DIR --allow-root --quiet"

rm -rf "$SITE_DIR"
mkdir -p "$SITE_DIR"
cp -r "$WP_SRC"/. "$SITE_DIR"/
rm -rf "$SITE_DIR/.git"

cp -rL "$SQLITE_SRC" "$SITE_DIR/wp-content/plugins/sqlite-database-integration"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SITE_DIR/wp-content/plugins/sqlite-database-integration#" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    "$SITE_DIR/wp-content/plugins/sqlite-database-integration/db.copy" > "$SITE_DIR/wp-content/db.php"

cp -r "$WC_SRC" "$SITE_DIR/wp-content/plugins/woocommerce"
mkdir -p "$SITE_DIR/wp-content/plugins/nplus-sso"
for f in nplus-sso.php uninstall.php includes; do
  cp -r "$PLUGIN_DIR/$f" "$SITE_DIR/wp-content/plugins/nplus-sso/"
done

$WP config create --dbname=wp --dbuser=x --dbpass=x --dbhost=localhost --skip-check --extra-php <<PHP
define( 'DB_DIR', '$SITE_DIR/wp-content/database/' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'NPLUS_SSO_API_BASE_URL', 'http://127.0.0.1:$MOCK_PORT' );
define( 'NPLUS_SSO_API_KEY', 'test-api-key' );
define( 'NPLUS_SSO_WSTOKEN', 'test-wstoken' );
define( 'NPLUS_SSO_SECRET', 'test-secret' );
// Same shape as N+ staging: clone function names, Auto Login web service with its own token.
define( 'NPLUS_SSO_AUTOLOGIN_WSTOKEN', 'test-autologin-wstoken' );
define( 'NPLUS_SSO_FN_CREATE_USER', 'local_lms_apis_clone_create_user_site' );
define( 'NPLUS_SSO_FN_CREATE_ORDER', 'local_lms_apis_clone_create_order' );
PHP

$WP core install --url="http://127.0.0.1:$SITE_PORT" --title="Academy" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
$WP option update home "http://127.0.0.1:$SITE_PORT"
$WP option update siteurl "http://127.0.0.1:$SITE_PORT"
$WP rewrite structure '/%postname%/'

# The mock N+ server is plain HTTP on localhost; production enforces HTTPS.
mkdir -p "$SITE_DIR/wp-content/mu-plugins"
cat > "$SITE_DIR/wp-content/mu-plugins/nplus-e2e.php" <<'PHP'
<?php
add_filter( 'nplus_sso_allow_insecure_http', '__return_true' );
add_filter( 'http_request_host_is_external', '__return_true' );
// Capture outgoing mail to a JSON-lines file instead of sending it.
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	file_put_contents( WP_CONTENT_DIR . '/e2e-mail.log', json_encode( $atts ) . "\n", FILE_APPEND );
	return true;
}, 10, 2 );
PHP

$WP plugin activate woocommerce
$WP plugin activate nplus-sso

$WP option update woocommerce_store_address "1 Test Street"
$WP option update woocommerce_default_country "IN:MH"
$WP option update woocommerce_currency "INR"
$WP option update woocommerce_coming_soon "no"
$WP option update woocommerce_enable_guest_checkout "yes"
$WP option update woocommerce_enable_signup_and_login_from_checkout "yes"
$WP option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
$WP option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery","enable_for_virtual":"yes"}' --format=json
$WP option update nplus_sso_settings '{"trigger_statuses":["processing","completed"],"sendmail":1,"show_in_emails":1,"allow_guest_launch":1,"debug":1,"roleid":5,"api_version":"v1","source":"website","payment_status":"completed","date_format":"Y-m-d H:i:s","button_text":"Access my N+ learning","request_timeout":20,"max_attempts":5}' --format=json

# Classic shortcode cart/checkout keep the browser test simple and stable
# (the plugin itself works with block checkout too: it only uses order hooks).
for pair in "cart:[woocommerce_cart]" "checkout:[woocommerce_checkout]" "myaccount:[woocommerce_my_account]"; do
  page="${pair%%:*}"; content="${pair#*:}"
  PAGE_ID=$($WP option get "woocommerce_${page}_page_id")
  $WP post update "$PAGE_ID" --post_content="$content"
done

# Product mapped to N+ (what the admin does in Product data > N+ Learning).
NPLUS_PRODUCT=$($WP wc product create --user=admin --name="Cyber Security Programme (N+)" --type=simple --regular_price=4999 --virtual=true --sku=CYBER-101 --porcelain)
$WP post meta update "$NPLUS_PRODUCT" _nplus_enabled yes
$WP post meta update "$NPLUS_PRODUCT" _nplus_campaign_id 12345
$WP post meta update "$NPLUS_PRODUCT" _nplus_subscription_sku NPLUS-CYBER-12M

# A normal product that must NOT touch N+.
PLAIN_PRODUCT=$($WP wc product create --user=admin --name="Printed Workbook" --type=simple --regular_price=499 --virtual=true --sku=BOOK-1 --porcelain)

$WP user create learner learner@example.com --role=customer --user_pass=learner --first_name=Asha --last_name=Rao

$WP rewrite flush
cat > "$SITE_DIR/e2e.json" <<JSON
{ "siteUrl": "http://127.0.0.1:$SITE_PORT", "mockUrl": "http://127.0.0.1:$MOCK_PORT", "nplusProductId": $NPLUS_PRODUCT, "plainProductId": $PLAIN_PRODUCT }
JSON
echo "NPLUS_PRODUCT=$NPLUS_PRODUCT"
echo "PLAIN_PRODUCT=$PLAIN_PRODUCT"
echo "Site ready: $SITE_DIR"
