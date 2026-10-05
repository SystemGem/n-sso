<?php
/**
 * Who may launch N+ and with which N+ user ID.
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Access / entitlement checks.
 */
class Access {

	/**
	 * Per-request cache of customer orders.
	 *
	 * @var array<int,\WC_Order[]>
	 */
	private static $cache = array();

	/**
	 * Paid orders of a customer that contain N+ products (newest first).
	 *
	 * @param int $wp_user_id WP user ID.
	 * @return \WC_Order[]
	 */
	public static function customer_nplus_orders( $wp_user_id ) {
		$wp_user_id = (int) $wp_user_id;
		if ( ! $wp_user_id || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		if ( isset( self::$cache[ $wp_user_id ] ) ) {
			return self::$cache[ $wp_user_id ];
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $wp_user_id,
				'status'      => array( 'wc-processing', 'wc-completed' ),
				'limit'       => apply_filters( 'nplus_sso_order_lookup_limit', 100 ),
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		self::$cache[ $wp_user_id ] = array_values(
			array_filter(
				$orders,
				static function ( $order ) {
					return ! empty( Product_Fields::order_items( $order ) );
				}
			)
		);
		return self::$cache[ $wp_user_id ];
	}

	/**
	 * Forget cached lookups (after provisioning changed state).
	 */
	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * Whether an order currently grants N+ access.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function order_grants_access( $order ) {
		return 'yes' === $order->get_meta( Provisioner::META_HAS_ENROLLMENT )
			&& 'yes' !== $order->get_meta( Provisioner::META_REVOKED )
			&& Provisioner::is_eligible_status( $order );
	}

	/**
	 * Whether a WP user has at least one active N+ enrollment.
	 *
	 * @param int $wp_user_id WP user ID.
	 * @return bool
	 */
	public static function user_has_access( $wp_user_id ) {
		$has = false;
		if ( (int) get_user_meta( (int) $wp_user_id, Provisioner::META_USER_ID, true ) ) {
			foreach ( self::customer_nplus_orders( $wp_user_id ) as $order ) {
				if ( self::order_grants_access( $order ) ) {
					$has = true;
					break;
				}
			}
		}

		/**
		 * Filter whether a user may launch N+.
		 *
		 * @param bool $has        Access.
		 * @param int  $wp_user_id WP user ID.
		 */
		return (bool) apply_filters( 'nplus_sso_user_has_access', $has, (int) $wp_user_id );
	}

	/**
	 * Paid N+ orders of the user that still need provisioning.
	 *
	 * @param int $wp_user_id WP user ID.
	 * @return \WC_Order[]
	 */
	public static function pending_orders( $wp_user_id ) {
		return array_values(
			array_filter(
				self::customer_nplus_orders( $wp_user_id ),
				array( __CLASS__, 'order_needs_provisioning' )
			)
		);
	}

	/**
	 * Whether a paid, non-revoked order still has N+ items to assign.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function order_needs_provisioning( $order ) {
		if ( 'yes' === $order->get_meta( Provisioner::META_REVOKED ) || ! Provisioner::is_eligible_status( $order ) ) {
			return false;
		}
		foreach ( Product_Fields::order_items( $order ) as $entry ) {
			if ( Provisioner::STATUS_ENROLLED !== $entry['item']->get_meta( Provisioner::ITEM_STATUS ) ) {
				return true;
			}
		}
		return false;
	}
}
