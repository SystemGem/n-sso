<?php
/**
 * Customer-facing UI: thank-you page, order details, My Account > N+ Learning,
 * order emails and the [nplus_sso_button] shortcode.
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Account UI.
 */
class Account {

	/**
	 * My Account endpoint slug.
	 *
	 * @return string
	 */
	public static function endpoint() {
		return (string) apply_filters( 'nplus_sso_account_endpoint', 'nplus-learning' );
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ) );
		add_action( 'woocommerce_account_' . self::endpoint() . '_endpoint', array( $this, 'render_endpoint' ) );
		add_filter( 'woocommerce_endpoint_' . self::endpoint() . '_title', array( $this, 'endpoint_title' ) );

		add_action( 'woocommerce_thankyou', array( $this, 'render_thankyou' ), 5 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_details' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email' ), 10, 4 );

		add_shortcode( 'nplus_sso_button', array( $this, 'shortcode' ) );
	}

	/**
	 * Rewrite endpoint (flushed on activation).
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::endpoint(), EP_ROOT | EP_PAGES );
	}

	/**
	 * Register the endpoint with WooCommerce.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[ self::endpoint() ] = self::endpoint();
		return $vars;
	}

	/**
	 * Add "N+ Learning" to the My Account menu for customers who bought N+ access.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function menu_items( $items ) {
		if ( ! is_user_logged_in() || empty( Access::customer_nplus_orders( get_current_user_id() ) ) ) {
			return $items;
		}
		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new[ self::endpoint() ] = __( 'N+ Learning', 'nplus-sso' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new[ self::endpoint() ] ) ) {
			$new[ self::endpoint() ] = __( 'N+ Learning', 'nplus-sso' );
		}
		return $new;
	}

	/**
	 * Endpoint title.
	 *
	 * @return string
	 */
	public function endpoint_title() {
		return __( 'N+ Learning', 'nplus-sso' );
	}

	/**
	 * Launch button HTML.
	 *
	 * @param \WC_Order|null $order Order for guest/order-scoped launch.
	 * @param string         $text  Button text.
	 * @return string
	 */
	public static function button( $order = null, $text = '' ) {
		$text = '' !== $text ? $text : Settings::button_text();
		return sprintf(
			'<a class="button nplus-sso-launch" href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( Sso::launch_url( $order ) ),
			esc_html( $text )
		);
	}

	/**
	 * My Account > N+ Learning.
	 */
	public function render_endpoint() {
		$user_id = get_current_user_id();
		$orders  = Access::customer_nplus_orders( $user_id );

		echo '<div class="nplus-sso-account">';
		if ( empty( $orders ) ) {
			echo '<p>' . esc_html__( 'You have not purchased any N+ learning yet.', 'nplus-sso' ) . '</p></div>';
			return;
		}

		$has_access = Access::user_has_access( $user_id );
		$pending    = ! empty( Access::pending_orders( $user_id ) );

		if ( $has_access || $pending ) {
			echo '<p>' . esc_html__( 'Your N+ learning materials are one click away. You will be signed in automatically.', 'nplus-sso' ) . '</p>';
			echo '<p>' . self::button() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		}

		echo '<table class="woocommerce-orders-table shop_table shop_table_responsive"><thead><tr>';
		echo '<th>' . esc_html__( 'Programme', 'nplus-sso' ) . '</th><th>' . esc_html__( 'Order', 'nplus-sso' ) . '</th><th>' . esc_html__( 'Date', 'nplus-sso' ) . '</th><th>' . esc_html__( 'Status', 'nplus-sso' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $orders as $order ) {
			foreach ( Product_Fields::order_items( $order ) as $entry ) {
				$item = $entry['item'];
				printf(
					'<tr><td>%s</td><td><a href="%s">#%s</a></td><td>%s</td><td>%s</td></tr>',
					esc_html( $item->get_name() ),
					esc_url( $order->get_view_order_url() ),
					esc_html( $order->get_order_number() ),
					esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ),
					esc_html( self::status_label( $order, $item ) )
				);
			}
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Human readable status of an N+ line item.
	 *
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Item.
	 * @return string
	 */
	public static function status_label( $order, $item ) {
		if ( 'yes' === $order->get_meta( Provisioner::META_REVOKED ) ) {
			return __( 'Cancelled', 'nplus-sso' );
		}
		if ( Provisioner::STATUS_ENROLLED === $item->get_meta( Provisioner::ITEM_STATUS ) ) {
			return __( 'Active', 'nplus-sso' );
		}
		return __( 'Being set up', 'nplus-sso' );
	}

	/**
	 * Box on the order-received (thank-you) page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function render_thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || empty( Product_Fields::order_items( $order ) ) ) {
			return;
		}
		if ( ! Provisioner::is_eligible_status( $order ) ) {
			echo '<section class="woocommerce-notice woocommerce-info nplus-sso-thankyou">' . esc_html__( 'Your N+ learning access will be activated as soon as your payment is confirmed.', 'nplus-sso' ) . '</section>';
			return;
		}
		if ( ! $order->get_customer_id() && ! Settings::get( 'allow_guest_launch' ) ) {
			echo '<section class="woocommerce-notice woocommerce-info nplus-sso-thankyou">' . esc_html__( 'Your purchase includes N+ learning access. Create an account or log in with your order email to access it.', 'nplus-sso' ) . '</section>';
			return;
		}
		echo '<section class="nplus-sso-thankyou" style="margin:1.5em 0;padding:1.25em;border:1px solid #dcdcde;border-radius:4px">';
		echo '<h2>' . esc_html__( 'Start learning on N+', 'nplus-sso' ) . '</h2>';
		echo '<p>' . esc_html__( 'Your purchase includes access to the N+ Learning Platform. Click below to be signed in automatically.', 'nplus-sso' ) . '</p>';
		echo '<p>' . self::button( $order ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';
	}

	/**
	 * Button on My Account > View order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function render_order_details( $order ) {
		if ( is_wc_endpoint_url( 'order-received' ) ) {
			return; // Thank-you page already shows the box.
		}
		if ( ! $order instanceof \WC_Order || ! Access::order_grants_access( $order ) && ! Access::order_needs_provisioning( $order ) ) {
			return;
		}
		echo '<p class="nplus-sso-order">' . self::button( $order ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Access link in customer processing/completed emails.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Admin email.
	 * @param bool      $plain_text    Plain text.
	 * @param \WC_Email $email         Email.
	 */
	public function render_email( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( $sent_to_admin || ! Settings::get( 'show_in_emails' ) || ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $email && ! in_array( $email->id, array( 'customer_processing_order', 'customer_completed_order' ), true ) ) {
			return;
		}
		if ( empty( Product_Fields::order_items( $order ) ) || ! Provisioner::is_eligible_status( $order ) ) {
			return;
		}

		$url = Sso::launch_url( $order );
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Access your N+ learning:', 'nplus-sso' ) . ' ' . esc_url_raw( $url ) . "\n\n";
			return;
		}
		echo '<h2>' . esc_html__( 'Your N+ learning access', 'nplus-sso' ) . '</h2>';
		echo '<p>' . esc_html__( 'Your purchase includes the N+ Learning Platform. Use the link below to sign in automatically.', 'nplus-sso' ) . '</p>';
		printf( '<p><a href="%s" style="font-weight:bold">%s</a></p>', esc_url( $url ), esc_html( Settings::button_text() ) );
	}

	/**
	 * [nplus_sso_button text="Go to N+" fallback="..."]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'text'     => '',
				'fallback' => '',
			),
			$atts,
			'nplus_sso_button'
		);
		if ( ! is_user_logged_in() ) {
			return '' !== $atts['fallback'] ? esc_html( $atts['fallback'] ) : '';
		}
		$user_id = get_current_user_id();
		if ( ! Access::user_has_access( $user_id ) && empty( Access::pending_orders( $user_id ) ) ) {
			return '' !== $atts['fallback'] ? esc_html( $atts['fallback'] ) : '';
		}
		return self::button( null, $atts['text'] );
	}
}
