<?php
/**
 * "N+ Learning" meta box on the order edit screen (classic and HPOS).
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Admin order meta box.
 */
class Admin_Order {

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
	}

	/**
	 * Register the meta box.
	 */
	public function add_meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'nplus-sso-order', __( 'N+ Learning', 'nplus-sso' ), array( $this, 'render' ), $screen, 'side', 'default' );
		}
	}

	/**
	 * Render.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post (classic) or order (HPOS).
	 */
	public function render( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$items = Product_Fields::order_items( $order );
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No N+ products in this order.', 'nplus-sso' ) . '</p>';
			return;
		}

		$uid = (int) $order->get_meta( Provisioner::META_USER_ID );
		echo '<p><strong>' . esc_html__( 'N+ user ID:', 'nplus-sso' ) . '</strong> ' . ( $uid ? esc_html( (string) $uid ) : '&mdash;' ) . '</p>';
		if ( 'yes' === $order->get_meta( Provisioner::META_REVOKED ) ) {
			echo '<p style="color:#b32d2e">' . esc_html__( 'Access removed (refunded/cancelled).', 'nplus-sso' ) . '</p>';
		}

		echo '<ul>';
		foreach ( $items as $entry ) {
			$item   = $entry['item'];
			$status = (string) $item->get_meta( Provisioner::ITEM_STATUS );
			$line   = $item->get_name() . ' — ' . sprintf(
				/* translators: %d: campaign id */
				__( 'campaign %d', 'nplus-sso' ),
				$entry['config']['campaign_id']
			);
			echo '<li><strong>' . esc_html( $line ) . '</strong><br/>';
			if ( Provisioner::STATUS_ENROLLED === $status ) {
				echo '<span style="color:#008a20">' . esc_html(
					sprintf(
						/* translators: %s: N+ order id */
						__( 'Assigned (N+ order %s)', 'nplus-sso' ),
						$item->get_meta( Provisioner::ITEM_ORDER_ID )
					)
				) . '</span>';
			} elseif ( Provisioner::STATUS_FAILED === $status ) {
				echo '<span style="color:#b32d2e">' . esc_html__( 'Failed:', 'nplus-sso' ) . ' ' . esc_html( (string) $item->get_meta( Provisioner::ITEM_ERROR ) ) . '</span>';
			} else {
				echo '<span>' . esc_html__( 'Pending', 'nplus-sso' ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'To retry, choose "Sync to N+" in Order actions and click Update.', 'nplus-sso' ) . '</p>';
	}
}
