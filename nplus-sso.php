<?php
/**
 * Plugin Name:       N+ SSO Integration
 * Plugin URI:        https://github.com/SystemGem/n-sso
 * Description:       Provisions learners on the N+ Learning Platform when they buy a mapped WooCommerce product, assigns their N+ subscription and gives them one-click Single Sign-On into N+.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            SystemGem
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nplus-sso
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   10.2
 *
 * @package NPlusSSO
 */

defined( 'ABSPATH' ) || exit;

define( 'NPLUS_SSO_VERSION', '1.0.0' );
define( 'NPLUS_SSO_FILE', __FILE__ );
define( 'NPLUS_SSO_DIR', plugin_dir_path( __FILE__ ) );
define( 'NPLUS_SSO_URL', plugin_dir_url( __FILE__ ) );

require_once NPLUS_SSO_DIR . 'includes/autoload.php';
require_once NPLUS_SSO_DIR . 'includes/functions.php';

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, array( '\NPlusSSO\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\NPlusSSO\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\NPlusSSO\Plugin', 'instance' ) );
