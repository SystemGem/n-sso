<?php
/**
 * Plugin bootstrap.
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and boot) the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Wire up components.
	 */
	private function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			( new Settings() )->register_hooks();
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_woocommerce_missing' ) );
			return;
		}

		( new Product_Fields() )->register_hooks();
		( new Order_Handler() )->register_hooks();
		( new Sso() )->register_hooks();
		( new Account() )->register_hooks();

		if ( is_admin() ) {
			( new Admin_Order() )->register_hooks();
		}
	}

	/**
	 * Translations (must not load before init since WP 6.7).
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'nplus-sso', false, dirname( plugin_basename( NPLUS_SSO_FILE ) ) . '/languages' );
	}

	/**
	 * WooCommerce is required.
	 */
	public function notice_woocommerce_missing() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'N+ SSO Integration requires WooCommerce to be installed and active.', 'nplus-sso' ) . '</p></div>';
	}

	/**
	 * Activation: register the My Account endpoint and flush rewrite rules.
	 */
	public static function activate() {
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', 'no' );
		}
		Account::add_endpoint();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Order_Handler::ACTION_HOOK, null, Order_Handler::AS_GROUP );
		}
		flush_rewrite_rules();
	}
}
