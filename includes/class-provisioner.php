<?php
/**
 * Runs the N+ integration workflow for an order:
 *
 *   1. Create User API               -> N+ user ID (stored on the WP user and the order)
 *   2. Subscription Assignment API   -> one call per N+ line item (idempotent per item)
 *   3. (Auto Login happens later, on demand, in Sso::launch.)
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Provisioner.
 */
class Provisioner {

	// User meta + order meta.
	const META_USER_ID = '_nplus_user_id';

	// Order meta.
	const META_HAS_ENROLLMENT = '_nplus_has_enrollment';
	const META_REVOKED        = '_nplus_revoked';
	const META_ATTEMPTS       = '_nplus_attempts';

	// Line item meta.
	const ITEM_STATUS           = '_nplus_status';
	const ITEM_ORDER_ID         = '_nplus_orderid';
	const ITEM_ERROR            = '_nplus_error';
	const ITEM_ALREADY_ENROLLED = '_nplus_already_enrolled';
	const ITEM_ENROLLED_AT      = '_nplus_enrolled_at';
	const ITEM_WEBSITE_ORDER_ID = '_nplus_website_orderid';

	const STATUS_ENROLLED = 'enrolled';
	const STATUS_FAILED   = 'failed';

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Api_Client|null $client Client.
	 */
	public function __construct( $client = null ) {
		$this->client = $client ? $client : new Api_Client();
	}

	/**
	 * Whether an order is in a status that should be provisioned.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_eligible_status( $order ) {
		return in_array( $order->get_status(), (array) Settings::get( 'trigger_statuses' ), true );
	}

	/**
	 * Provision every N+ line item of an order.
	 *
	 * @param \WC_Order|int $order Order or ID.
	 * @param bool          $force Ignore the order status check (admin "Sync to N+").
	 * @return array{enrolled:int,failed:int,skipped:int,errors:string[]}|WP_Error
	 */
	public function provision_order( $order, $force = false ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return new WP_Error( 'nplus_no_order', __( 'Order not found.', 'nplus-sso' ) );
		}

		$items = Product_Fields::order_items( $order );
		if ( empty( $items ) ) {
			return array( 'enrolled' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => array() );
		}

		if ( ! $force && ! self::is_eligible_status( $order ) ) {
			return new WP_Error( 'nplus_not_paid', __( 'Order is not in a status that grants N+ access.', 'nplus-sso' ) );
		}

		if ( ! $this->acquire_lock( $order->get_id() ) ) {
			return new WP_Error( 'nplus_locked', __( 'N+ provisioning for this order is already running.', 'nplus-sso' ) );
		}

		try {
			return $this->run( $order, $items );
		} finally {
			$this->release_lock( $order->get_id() );
		}
	}

	/**
	 * Locked part of provision_order().
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $items N+ items.
	 * @return array|WP_Error
	 */
	private function run( $order, array $items ) {
		$summary = array( 'enrolled' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => array() );

		// Re-paid after a refund/cancel: access is granted again.
		if ( 'yes' === $order->get_meta( self::META_REVOKED ) ) {
			$order->delete_meta_data( self::META_REVOKED );
			$order->save();
		}

		$pending = array();
		foreach ( $items as $item_id => $entry ) {
			if ( self::STATUS_ENROLLED === $entry['item']->get_meta( self::ITEM_STATUS ) ) {
				++$summary['skipped'];
			} else {
				$pending[ $item_id ] = $entry;
			}
		}
		if ( empty( $pending ) ) {
			return $summary;
		}

		$order->update_meta_data( self::META_ATTEMPTS, (int) $order->get_meta( self::META_ATTEMPTS ) + 1 );
		$order->save();

		$nplus_user_id = $this->ensure_user( $order );
		if ( is_wp_error( $nplus_user_id ) ) {
			foreach ( $pending as $entry ) {
				$this->mark_failed( $entry['item'], $nplus_user_id );
			}
			$order->add_order_note(
				/* translators: %s: error message */
				sprintf( __( 'N+: could not create the learner account: %s', 'nplus-sso' ), $nplus_user_id->get_error_message() )
			);
			$summary['failed']   = count( $pending );
			$summary['errors'][] = $nplus_user_id->get_error_message();
			return $summary;
		}

		foreach ( $pending as $item_id => $entry ) {
			$result = $this->assign_subscription( $order, $item_id, $entry['item'], $entry['config'], $nplus_user_id );
			if ( is_wp_error( $result ) ) {
				++$summary['failed'];
				$summary['errors'][] = $result->get_error_message();
			} else {
				++$summary['enrolled'];
			}
		}

		return $summary;
	}

	/**
	 * Return the N+ user ID for the buyer, creating the N+ user when needed.
	 *
	 * @param \WC_Order $order Order.
	 * @return int|WP_Error
	 */
	public function ensure_user( $order ) {
		$wp_user_id = (int) $order->get_customer_id();

		if ( $wp_user_id ) {
			$existing = (int) get_user_meta( $wp_user_id, self::META_USER_ID, true );
			if ( $existing ) {
				if ( (int) $order->get_meta( self::META_USER_ID ) !== $existing ) {
					$order->update_meta_data( self::META_USER_ID, $existing );
					$order->save();
				}
				return $existing;
			}
		}

		$on_order = (int) $order->get_meta( self::META_USER_ID );
		if ( $on_order ) {
			if ( $wp_user_id ) {
				update_user_meta( $wp_user_id, self::META_USER_ID, $on_order );
			}
			return $on_order;
		}

		$response = $this->client->create_user( self::user_payload( $order ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$nplus_user_id = (int) $response['data']['id'];

		if ( $wp_user_id ) {
			update_user_meta( $wp_user_id, self::META_USER_ID, $nplus_user_id );
		}
		$order->update_meta_data( self::META_USER_ID, $nplus_user_id );
		$order->save();

		$order->add_order_note(
			! empty( $response['already_exists'] )
				/* translators: %d: N+ user ID */
				? sprintf( __( 'N+: linked existing learner account (N+ user ID %d).', 'nplus-sso' ), $nplus_user_id )
				/* translators: %d: N+ user ID */
				: sprintf( __( 'N+: learner account created (N+ user ID %d).', 'nplus-sso' ), $nplus_user_id )
		);

		/**
		 * Fires after the buyer has an N+ user ID.
		 *
		 * @param int       $nplus_user_id N+ user ID.
		 * @param \WC_Order $order         Order.
		 * @param array     $response      Create User API response.
		 */
		do_action( 'nplus_sso_user_created', $nplus_user_id, $order, $response );

		return $nplus_user_id;
	}

	/**
	 * Call the Subscription Assignment API for one line item.
	 *
	 * @param \WC_Order              $order         Order.
	 * @param int                    $item_id       Item ID.
	 * @param \WC_Order_Item_Product $item          Item.
	 * @param array                  $config        N+ product config.
	 * @param int                    $nplus_user_id N+ user ID.
	 * @return array|WP_Error
	 */
	private function assign_subscription( $order, $item_id, $item, array $config, $nplus_user_id ) {
		$payload  = self::order_payload( $order, $item_id, $item, $config, $nplus_user_id );
		$response = $this->client->create_order( $payload );

		if ( is_wp_error( $response ) ) {
			$this->mark_failed( $item, $response );
			$order->add_order_note(
				sprintf(
					/* translators: 1: product name, 2: error message */
					__( 'N+: subscription assignment failed for "%1$s": %2$s', 'nplus-sso' ),
					$item->get_name(),
					$response->get_error_message()
				)
			);
			return $response;
		}

		$already = ! empty( $response['isUserAlreadyEnrolled'] );
		$item->update_meta_data( self::ITEM_STATUS, self::STATUS_ENROLLED );
		$item->update_meta_data( self::ITEM_ORDER_ID, isset( $response['orderid'] ) ? (string) $response['orderid'] : '' );
		$item->update_meta_data( self::ITEM_ALREADY_ENROLLED, $already ? 1 : 0 );
		$item->update_meta_data( self::ITEM_ENROLLED_AT, time() );
		$item->update_meta_data( self::ITEM_WEBSITE_ORDER_ID, $payload['website_orderid'] );
		$item->update_meta_data( '_nplus_campaign_id', $config['campaign_id'] );
		$item->update_meta_data( '_nplus_subscription_sku', $config['subscription_sku'] );
		$item->delete_meta_data( self::ITEM_ERROR );
		$item->save();

		$order->update_meta_data( self::META_HAS_ENROLLMENT, 'yes' );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: product name, 2: N+ order ID, 3: new/existing */
				__( 'N+: subscription assigned for "%1$s" (N+ order %2$s, %3$s).', 'nplus-sso' ),
				$item->get_name(),
				isset( $response['orderid'] ) ? $response['orderid'] : '-',
				$already ? __( 'learner was already enrolled', 'nplus-sso' ) : __( 'new enrollment', 'nplus-sso' )
			)
		);

		/**
		 * Fires after an N+ subscription has been assigned.
		 *
		 * @param \WC_Order_Item_Product $item     Line item.
		 * @param \WC_Order              $order    Order.
		 * @param array                  $response Subscription Assignment API response.
		 */
		do_action( 'nplus_sso_subscription_assigned', $item, $order, $response );

		return $response;
	}

	/**
	 * Record a failure on a line item.
	 *
	 * @param \WC_Order_Item_Product $item  Item.
	 * @param WP_Error               $error Error.
	 */
	private function mark_failed( $item, WP_Error $error ) {
		$item->update_meta_data( self::ITEM_STATUS, self::STATUS_FAILED );
		$item->update_meta_data( self::ITEM_ERROR, $error->get_error_message() );
		$item->save();
	}

	/**
	 * Create User API fields for the buyer of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function user_payload( $order ) {
		$user  = $order->get_customer_id() ? get_userdata( $order->get_customer_id() ) : null;
		$email = $user ? $user->user_email : $order->get_billing_email();

		$first = $order->get_billing_first_name();
		$last  = $order->get_billing_last_name();
		if ( '' === $first && $user ) {
			$first = $user->first_name ? $user->first_name : $user->display_name;
		}
		if ( '' === $last && $user ) {
			$last = $user->last_name;
		}
		if ( '' === $last ) {
			$last = $first;
		}

		$country = $order->get_billing_country();
		$calling = '';
		if ( $country && function_exists( 'WC' ) && WC()->countries && method_exists( WC()->countries, 'get_country_calling_code' ) ) {
			$calling = (string) WC()->countries->get_country_calling_code( $country );
		}

		$address = implode(
			', ',
			array_filter(
				array(
					$order->get_billing_address_1(),
					$order->get_billing_address_2(),
					$order->get_billing_city(),
					$order->get_billing_state(),
					$order->get_billing_postcode(),
				)
			)
		);

		$payload = array(
			'firstname'               => $first,
			'lastname'                => $last,
			'email'                   => $email,
			'phone1'                  => $order->get_billing_phone(),
			'roleid'                  => (int) Settings::get( 'roleid' ),
			'country'                 => $country,
			'address'                 => $address,
			'phone_country_code'      => $calling,
			'company_name'            => $order->get_billing_company(),
			'partner_additional_info' => wp_json_encode(
				array(
					'site'     => home_url(),
					'order_id' => $order->get_order_number(),
				)
			),
			'partner_userid'          => $order->get_customer_id() ? (string) $order->get_customer_id() : '',
		);

		/**
		 * Filter the Create User API fields.
		 *
		 * @param array     $payload Fields.
		 * @param \WC_Order $order   Order.
		 */
		return apply_filters( 'nplus_sso_create_user_payload', $payload, $order );
	}

	/**
	 * Subscription Assignment API fields for one line item.
	 *
	 * @param \WC_Order              $order         Order.
	 * @param int                    $item_id       Item ID.
	 * @param \WC_Order_Item_Product $item          Item.
	 * @param array                  $config        N+ product config.
	 * @param int                    $nplus_user_id N+ user ID.
	 * @return array<string,mixed>
	 */
	public static function order_payload( $order, $item_id, $item, array $config, $nplus_user_id ) {
		$paid = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();

		$payload = array(
			// Stable per line item so retries never create a second subscription.
			'website_orderid'        => $order->get_order_number() . '-' . $item_id,
			'campaignid'             => (int) $config['campaign_id'],
			'userid'                 => (int) $nplus_user_id,
			'quantity'               => max( 1, (int) $item->get_quantity() ),
			'payment'                => (float) $item->get_total() + (float) $item->get_total_tax(),
			'payment_currency'       => $order->get_currency(),
			'payment_status'         => (string) Settings::get( 'payment_status' ),
			'source'                 => (string) Settings::get( 'source' ),
			'subscription_skuid'     => (string) $config['subscription_sku'],
			'subscription_startdate' => $paid ? $paid->date_i18n( (string) Settings::get( 'date_format' ) ) : gmdate( 'Y-m-d H:i:s' ),
			'sendmail'               => (int) Settings::get( 'sendmail' ),
		);

		/**
		 * Filter the Subscription Assignment API fields.
		 *
		 * @param array                  $payload Fields.
		 * @param \WC_Order              $order   Order.
		 * @param \WC_Order_Item_Product $item    Item.
		 */
		return apply_filters( 'nplus_sso_create_order_payload', $payload, $order, $item );
	}

	/**
	 * Atomic per-order lock (add_option fails when the row exists).
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	private function acquire_lock( $order_id ) {
		$key = 'nplus_sso_lock_' . $order_id;
		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}
		// Stale lock (crashed request): take it over after 2 minutes.
		$since = (int) get_option( $key );
		if ( $since && $since < time() - 120 ) {
			delete_option( $key );
			return add_option( $key, time(), '', 'no' );
		}
		return false;
	}

	/**
	 * Release the lock.
	 *
	 * @param int $order_id Order ID.
	 */
	private function release_lock( $order_id ) {
		delete_option( 'nplus_sso_lock_' . $order_id );
	}
}
