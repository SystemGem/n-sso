<?php
/**
 * "N+ Learning" product data tab: maps a WooCommerce product (or variation)
 * to an N+ Campaign ID and Subscription SKU.
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Product fields.
 */
class Product_Fields {

	const META_ENABLED  = '_nplus_enabled';
	const META_CAMPAIGN = '_nplus_campaign_id';
	const META_SKU      = '_nplus_subscription_sku';

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );
		add_action( 'woocommerce_product_after_variation_settings_fields', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
	}

	/**
	 * Add the tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function add_tab( $tabs ) {
		$tabs['nplus_sso'] = array(
			'label'    => __( 'N+ Learning', 'nplus-sso' ),
			'target'   => 'nplus_sso_product_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	/**
	 * Render the panel.
	 */
	public function render_panel() {
		echo '<div id="nplus_sso_product_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_ENABLED,
				'label'       => __( 'Grant N+ access', 'nplus-sso' ),
				'description' => __( 'Create the buyer on N+, assign the subscription below and give them Single Sign-On access once the order is paid.', 'nplus-sso' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_CAMPAIGN,
				'label'             => __( 'N+ Campaign ID', 'nplus-sso' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
				'desc_tip'          => true,
				'description'       => __( 'Integer campaign ID supplied by N+ ("campaignid").', 'nplus-sso' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_SKU,
				'label'       => __( 'N+ Subscription SKU', 'nplus-sso' ),
				'desc_tip'    => true,
				'description' => __( 'Subscription SKU supplied by N+ ("subscription_skuid"). Leave empty to use the product SKU.', 'nplus-sso' ),
			)
		);
		echo '<p class="form-field"><em>' . esc_html__( 'Variable products: each variation can override the Campaign ID and SKU.', 'nplus-sso' ) . '</em></p>';
		echo '</div></div>';
	}

	/**
	 * Save product meta.
	 *
	 * @param \WC_Product $product Product.
	 */
	public function save_product( $product ) {
		// Nonce verified by WooCommerce before this hook fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( self::META_ENABLED, isset( $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no' );
		$product->update_meta_data( self::META_CAMPAIGN, isset( $_POST[ self::META_CAMPAIGN ] ) ? absint( wp_unslash( $_POST[ self::META_CAMPAIGN ] ) ) : '' );
		$product->update_meta_data( self::META_SKU, isset( $_POST[ self::META_SKU ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_SKU ] ) ) : '' );
		// phpcs:enable
	}

	/**
	 * Variation override fields.
	 *
	 * @param int      $loop           Index.
	 * @param array    $variation_data Data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input(
			array(
				'id'            => self::META_CAMPAIGN . '_' . $loop,
				'name'          => self::META_CAMPAIGN . '[' . $loop . ']',
				'value'         => get_post_meta( $variation->ID, self::META_CAMPAIGN, true ),
				'label'         => __( 'N+ Campaign ID (override)', 'nplus-sso' ),
				'type'          => 'number',
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => self::META_SKU . '_' . $loop,
				'name'          => self::META_SKU . '[' . $loop . ']',
				'value'         => get_post_meta( $variation->ID, self::META_SKU, true ),
				'label'         => __( 'N+ Subscription SKU (override)', 'nplus-sso' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
	}

	/**
	 * Save variation overrides.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Index.
	 */
	public function save_variation( $variation_id, $loop ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$campaign = isset( $_POST[ self::META_CAMPAIGN ][ $loop ] ) ? absint( wp_unslash( $_POST[ self::META_CAMPAIGN ][ $loop ] ) ) : '';
		$sku      = isset( $_POST[ self::META_SKU ][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_SKU ][ $loop ] ) ) : '';
		// phpcs:enable
		update_post_meta( $variation_id, self::META_CAMPAIGN, $campaign ? $campaign : '' );
		update_post_meta( $variation_id, self::META_SKU, $sku );
	}

	/**
	 * N+ configuration for a product (variations inherit from their parent).
	 *
	 * @param \WC_Product|null $product Product.
	 * @return array{campaign_id:int,subscription_sku:string}|null Null if the product does not grant N+ access.
	 */
	public static function config_for_product( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $parent || 'yes' !== $parent->get_meta( self::META_ENABLED ) ) {
			return null;
		}

		$campaign = 0;
		$sku      = '';
		if ( $product !== $parent ) {
			$campaign = absint( $product->get_meta( self::META_CAMPAIGN ) );
			$sku      = (string) $product->get_meta( self::META_SKU );
		}
		if ( ! $campaign ) {
			$campaign = absint( $parent->get_meta( self::META_CAMPAIGN ) );
		}
		if ( '' === $sku ) {
			$sku = (string) $parent->get_meta( self::META_SKU );
		}
		if ( '' === $sku ) {
			$sku = (string) $product->get_sku();
		}

		if ( ! $campaign ) {
			return null;
		}

		$config = array(
			'campaign_id'      => $campaign,
			'subscription_sku' => $sku,
		);

		/**
		 * Filter the N+ mapping of a product.
		 *
		 * @param array|null  $config  Config or null to disable.
		 * @param \WC_Product $product Product.
		 */
		return apply_filters( 'nplus_sso_product_config', $config, $product );
	}

	/**
	 * N+ line items of an order, keyed by item ID.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array{item:\WC_Order_Item_Product,config:array}>
	 */
	public static function order_items( $order ) {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$config = self::config_for_product( $item->get_product() );
			if ( ! $config ) {
				// Product deleted/changed later: fall back to what was recorded at provisioning time.
				$campaign = absint( $item->get_meta( '_nplus_campaign_id' ) );
				if ( $campaign ) {
					$config = array(
						'campaign_id'      => $campaign,
						'subscription_sku' => (string) $item->get_meta( '_nplus_subscription_sku' ),
					);
				}
			}
			if ( $config ) {
				$items[ $item_id ] = array(
					'item'   => $item,
					'config' => $config,
				);
			}
		}
		return $items;
	}
}
