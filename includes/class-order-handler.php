<?php
/**
 * WooCommerce order lifecycle hooks: provision on payment, retry on failure,
 * flag access on refund/cancel, manual "Sync to N+" order action.
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Order handler.
 */
class Order_Handler {

	const ACTION_HOOK = 'nplus_sso_provision_order';
	const AS_GROUP    = 'nplus-sso';

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		foreach ( (array) Settings::get( 'trigger_statuses' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'on_paid' ), 20, 1 );
		}
		add_action( self::ACTION_HOOK, array( $this, 'run_scheduled' ), 10, 1 );

		foreach ( array( 'refunded', 'cancelled' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'on_revoked' ), 20, 1 );
		}

		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_action' ), 10, 2 );
		add_action( 'woocommerce_order_action_nplus_sso_sync', array( $this, 'manual_sync' ) );
	}

	/**
	 * Order reached a paid status.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || empty( Product_Fields::order_items( $order ) ) ) {
			return;
		}
		$order->delete_meta_data( Provisioner::META_ATTEMPTS );
		$order->save();

		/**
		 * Whether to provision in the background (Action Scheduler) instead of during the request.
		 * The launch endpoint provisions on demand, so the buyer is never blocked either way.
		 *
		 * @param bool      $async Async.
		 * @param \WC_Order $order Order.
		 */
		$async = apply_filters( 'nplus_sso_async_provisioning', function_exists( 'as_enqueue_async_action' ), $order );

		if ( $async ) {
			self::enqueue( $order->get_id() );
			return;
		}
		$this->run_scheduled( $order->get_id() );
	}

	/**
	 * Queue background provisioning.
	 *
	 * @param int $order_id Order ID.
	 * @param int $delay    Seconds from now (0 = async ASAP).
	 */
	public static function enqueue( $order_id, $delay = 0 ) {
		$args = array( 'order_id' => (int) $order_id );
		// Only pending actions count: a retry is queued from inside the running action.
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions(
				array(
					'hook'     => self::ACTION_HOOK,
					'args'     => $args,
					'group'    => self::AS_GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				),
				'ids'
			);
			if ( ! empty( $pending ) ) {
				return;
			}
		}
		if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::ACTION_HOOK, $args, self::AS_GROUP );
		} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_HOOK, $args, self::AS_GROUP );
		}
	}

	/**
	 * Action Scheduler callback (also used synchronously).
	 *
	 * @param int $order_id Order ID.
	 */
	public function run_scheduled( $order_id ) {
		$result = ( new Provisioner() )->provision_order( (int) $order_id );
		Access::flush_cache();

		if ( is_wp_error( $result ) ) {
			if ( 'nplus_locked' === $result->get_error_code() ) {
				self::enqueue( $order_id, 60 );
			}
			return;
		}

		if ( $result['failed'] > 0 ) {
			$this->schedule_retry( (int) $order_id );
		}
	}

	/**
	 * Exponential back-off retry: 5, 10, 20, 40 ... minutes (capped at 6h).
	 *
	 * @param int $order_id Order ID.
	 */
	private function schedule_retry( $order_id ) {
		$order    = wc_get_order( $order_id );
		$attempts = $order ? (int) $order->get_meta( Provisioner::META_ATTEMPTS ) : 0;
		$max      = (int) Settings::get( 'max_attempts' );

		if ( ! $order || $attempts >= $max ) {
			if ( $order ) {
				$order->add_order_note(
					/* translators: %d: attempts */
					sprintf( __( 'N+: provisioning still failing after %d attempts. Fix the cause, then use the order action "Sync to N+".', 'nplus-sso' ), $attempts )
				);
				/**
				 * Fires when automatic N+ provisioning gave up.
				 *
				 * @param \WC_Order $order Order.
				 */
				do_action( 'nplus_sso_provisioning_gave_up', $order );
			}
			return;
		}

		$delay = (int) min( 6 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** max( 0, $attempts - 1 ) ) );
		self::enqueue( $order_id, $delay );
	}

	/**
	 * Refunded / cancelled order: stop showing the launch button for it.
	 *
	 * The N+ API has no de-provisioning endpoint, so the subscription itself must be
	 * cancelled with N+ support; the order note tells the admin exactly which one.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_revoked( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( Provisioner::META_HAS_ENROLLMENT ) ) {
			return;
		}
		$order->update_meta_data( Provisioner::META_REVOKED, 'yes' );
		$order->save();

		$ids = array();
		foreach ( Product_Fields::order_items( $order ) as $entry ) {
			$nplus_order = $entry['item']->get_meta( Provisioner::ITEM_ORDER_ID );
			if ( $nplus_order ) {
				$ids[] = $nplus_order;
			}
		}
		$order->add_order_note(
			sprintf(
				/* translators: %s: N+ order IDs */
				__( 'N+: website access removed. N+ has no cancellation API, so ask N+ support to cancel N+ order(s): %s.', 'nplus-sso' ),
				$ids ? implode( ', ', $ids ) : '-'
			)
		);

		/**
		 * Fires when a provisioned order is refunded or cancelled.
		 *
		 * @param \WC_Order $order Order.
		 */
		do_action( 'nplus_sso_access_revoked', $order );
	}

	/**
	 * "Sync to N+" in the order actions dropdown.
	 *
	 * @param array          $actions Actions.
	 * @param \WC_Order|null $order   Order (WC 8.4+).
	 * @return array
	 */
	public function add_order_action( $actions, $order = null ) {
		if ( ! $order || ! empty( Product_Fields::order_items( $order ) ) ) {
			$actions['nplus_sso_sync'] = __( 'Sync to N+ (create learner & assign subscription)', 'nplus-sso' );
		}
		return $actions;
	}

	/**
	 * Manual sync from the order screen.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function manual_sync( $order ) {
		$order->delete_meta_data( Provisioner::META_ATTEMPTS );
		$order->save();
		$result = ( new Provisioner() )->provision_order( $order, true );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( 'N+: ' . $result->get_error_message() );
			return;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: enrolled, 2: failed, 3: already done */
				__( 'N+ sync finished: %1$d assigned, %2$d failed, %3$d already assigned.', 'nplus-sso' ),
				$result['enrolled'],
				$result['failed'],
				$result['skipped']
			)
		);
	}
}
