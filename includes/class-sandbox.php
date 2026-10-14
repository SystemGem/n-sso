<?php
/**
 * Test mode ("sandbox"): a simulated N+ inside this WordPress site.
 *
 * While test mode is on, no request leaves the site. Create User, Assign
 * Subscription and Auto Login are answered here, with the same response shapes
 * as N+, and "Access my N+ learning" lands on a simulated N+ page on this site.
 * This lets the whole purchase journey be tested before N+ is reachable.
 *
 * Everything created in test mode is flagged, and is removed automatically when
 * test mode is switched off, so simulated IDs never reach the real N+.
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Sandbox.
 */
class Sandbox {

	const STATE_OPTION = 'nplus_sso_sandbox_state';
	const META_FLAG    = '_nplus_sandbox';
	const QUERY_VALUE  = 'sandbox';
	const RESET_ACTION = 'nplus_sso_sandbox_reset';

	/**
	 * Simulated IDs start here, far from real N+ IDs.
	 */
	const FIRST_USER_ID  = 900000001;
	const FIRST_ORDER_ID = 800000001;

	/**
	 * Whether test mode is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'sandbox' );
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_render_page' ), 0 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_change' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
	}

	/**
	 * Answer a web service call the way N+ would.
	 *
	 * @param array $body Request body (wstoken, wsfunction, params).
	 * @param array $fns  Configured function names: create_user, create_order, autologin.
	 * @return array Decoded "response".
	 */
	public static function handle( array $body, array $fns ) {
		$fn    = isset( $body['wsfunction'] ) ? (string) $body['wsfunction'] : '';
		$state = self::state();

		if ( $fn === $fns['create_user'] ) {
			foreach ( array( 'firstname', 'lastname', 'email', 'signature', 'timestamp' ) as $required ) {
				if ( ! isset( $body[ $required ] ) || '' === (string) $body[ $required ] ) {
					return array( 'exception' => 'moodle_exception', 'errorcode' => 'missingparam', 'message' => 'Missing parameter: ' . $required );
				}
			}
			$email = strtolower( (string) $body['email'] );
			foreach ( $state['users'] as $id => $user ) {
				if ( $user['email'] === $email ) {
					return array( 'status' => 'success', 'message' => 'User already exists. (test mode)', 'version' => 'v1', 'already_exists' => true, 'data' => array_merge( $user, array( 'id' => (int) $id ) ) );
				}
			}
			$id                               = self::FIRST_USER_ID + count( $state['users'] );
			$state['users'][ (string) $id ] = array(
				'email'     => $email,
				'firstname' => (string) $body['firstname'],
				'lastname'  => (string) $body['lastname'],
			);
			self::save( $state );
			return array( 'status' => 'success', 'message' => 'User created. (test mode)', 'version' => 'v1', 'already_exists' => false, 'data' => array_merge( $state['users'][ (string) $id ], array( 'id' => $id ) ) );
		}

		if ( $fn === $fns['create_order'] ) {
			foreach ( array( 'website_orderid', 'campaignid', 'userid', 'signature', 'timestamp' ) as $required ) {
				if ( ! isset( $body[ $required ] ) || '' === (string) $body[ $required ] ) {
					return array( 'exception' => 'moodle_exception', 'errorcode' => 'missingparam', 'message' => 'Missing parameter: ' . $required );
				}
			}
			if ( ! isset( $state['users'][ (string) (int) $body['userid'] ] ) ) {
				// A learner linked before test mode was switched on: register them in the simulation.
				$state['users'][ (string) (int) $body['userid'] ] = array( 'email' => '', 'firstname' => 'N+ user', 'lastname' => (string) (int) $body['userid'] );
			}
			$already = 0;
			foreach ( $state['orders'] as $order ) {
				if ( $order['website_orderid'] === (string) $body['website_orderid'] ) {
					return array( 'status' => 'success', 'orderid' => $order['orderid'], 'isUserAlreadyEnrolled' => 1, 'message' => 'Subscription assignment successfully. (test mode)' );
				}
				if ( (int) $order['userid'] === (int) $body['userid'] && (int) $order['campaignid'] === (int) $body['campaignid'] ) {
					$already = 1;
				}
			}
			$orderid           = self::FIRST_ORDER_ID + count( $state['orders'] );
			$state['orders'][] = array(
				'orderid'            => $orderid,
				'website_orderid'    => (string) $body['website_orderid'],
				'userid'             => (int) $body['userid'],
				'campaignid'         => (int) $body['campaignid'],
				'subscription_skuid' => isset( $body['subscription_skuid'] ) ? (string) $body['subscription_skuid'] : '',
				'quantity'           => isset( $body['quantity'] ) ? (int) $body['quantity'] : 1,
				'time'               => time(),
			);
			self::save( $state );
			return array( 'status' => 'success', 'orderid' => $orderid, 'isUserAlreadyEnrolled' => $already, 'message' => 'Subscription assignment successfully. (test mode)' );
		}

		if ( $fn === $fns['autologin'] ) {
			$uid = isset( $body['uid'] ) ? (int) $body['uid'] : 0;
			if ( ! isset( $state['users'][ (string) $uid ] ) || empty( $body['signature'] ) ) {
				return array( 'status' => 'error', 'message' => 'Invalid user or signature (test mode)' );
			}
			return array( 'status' => 'success', 'data' => array( 'loginurl' => self::login_url( $uid ) ) );
		}

		return array( 'exception' => 'moodle_exception', 'errorcode' => 'invalidfunction', 'message' => 'Unknown wsfunction (test mode): ' . $fn );
	}

	/**
	 * One-time link to the simulated N+ page.
	 *
	 * @param int $uid Simulated N+ user ID.
	 * @return string
	 */
	public static function login_url( $uid ) {
		$token = wp_generate_password( 32, false );
		set_transient( 'nplus_sso_sandbox_' . $token, (int) $uid, 10 * MINUTE_IN_SECONDS );
		return add_query_arg(
			array(
				Sso::QUERY_VAR => self::QUERY_VALUE,
				'token'        => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Simulated N+ landing page.
	 */
	public function maybe_render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- one-time token link.
		if ( ! isset( $_GET[ Sso::QUERY_VAR ] ) || self::QUERY_VALUE !== $_GET[ Sso::QUERY_VAR ] ) {
			return;
		}
		$token = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable
		nocache_headers();

		$uid = $token ? (int) get_transient( 'nplus_sso_sandbox_' . $token ) : 0;
		if ( $token ) {
			delete_transient( 'nplus_sso_sandbox_' . $token );
		}
		$state = self::state();
		if ( ! $uid || ! isset( $state['users'][ (string) $uid ] ) ) {
			wp_die( esc_html__( 'This simulated N+ login link is invalid or was already used. Go back and click the button again.', 'nplus-sso' ), esc_html__( 'N+ test mode', 'nplus-sso' ), array( 'response' => 403 ) );
		}

		$user    = $state['users'][ (string) $uid ];
		$courses = array_filter(
			$state['orders'],
			static function ( $order ) use ( $uid ) {
				return (int) $order['userid'] === $uid;
			}
		);

		$rows = '';
		foreach ( $courses as $order ) {
			$rows .= sprintf(
				'<tr><td>%d</td><td>%s</td><td>%d</td><td>%s</td></tr>',
				(int) $order['campaignid'],
				esc_html( $order['subscription_skuid'] ),
				(int) $order['orderid'],
				esc_html( $order['website_orderid'] )
			);
		}
		if ( '' === $rows ) {
			$rows = '<tr><td colspan="4">' . esc_html__( 'No subscriptions assigned yet.', 'nplus-sso' ) . '</td></tr>';
		}

		$html  = '<h1 id="nplus-sandbox-welcome">' . esc_html( sprintf( /* translators: %s: learner name */ __( 'Welcome to N+, %s', 'nplus-sso' ), trim( $user['firstname'] . ' ' . $user['lastname'] ) ) ) . '</h1>';
		$html .= '<p style="padding:12px;background:#fcf9e8;border-left:4px solid #dba617"><strong>' . esc_html__( 'TEST MODE.', 'nplus-sso' ) . '</strong> ' . esc_html__( 'This page is simulated by the N+ SSO plugin on your own site. With test mode off, the learner lands on the real N+ platform at this point, already signed in.', 'nplus-sso' ) . '</p>';
		$html .= '<p>' . esc_html( sprintf( /* translators: 1: N+ user id, 2: email */ __( 'Signed in as N+ user %1$d (%2$s).', 'nplus-sso' ), $uid, $user['email'] ) ) . '</p>';
		$html .= '<h2>' . esc_html__( 'Subscriptions on N+', 'nplus-sso' ) . '</h2>';
		$html .= '<table id="nplus-sandbox-subscriptions" style="border-collapse:collapse;width:100%" border="1" cellpadding="6"><thead><tr><th>' . esc_html__( 'Campaign ID', 'nplus-sso' ) . '</th><th>' . esc_html__( 'Subscription SKU', 'nplus-sso' ) . '</th><th>' . esc_html__( 'N+ order', 'nplus-sso' ) . '</th><th>' . esc_html__( 'Website order', 'nplus-sso' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
		$html .= '<p><a class="button" href="' . esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) ) . '">' . esc_html__( 'Back to my account', 'nplus-sso' ) . '</a></p>';

		wp_die( $html, esc_html__( 'N+ (test mode)', 'nplus-sso' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Remember on the WP user / order that their N+ data is simulated.
	 *
	 * @param \WC_Order $order      Order.
	 * @param int       $wp_user_id WP user ID (0 for guests).
	 */
	public static function flag( $order, $wp_user_id ) {
		if ( ! self::enabled() ) {
			return;
		}
		$state = self::state();
		if ( $wp_user_id ) {
			update_user_meta( $wp_user_id, self::META_FLAG, 1 );
			$state['flagged_users'][] = (int) $wp_user_id;
		}
		if ( $order ) {
			if ( 'yes' !== $order->get_meta( self::META_FLAG ) ) {
				$order->update_meta_data( self::META_FLAG, 'yes' );
				$order->save();
			}
			$state['flagged_orders'][] = (int) $order->get_id();
		}
		$state['flagged_users']  = array_values( array_unique( $state['flagged_users'] ) );
		$state['flagged_orders'] = array_values( array_unique( $state['flagged_orders'] ) );
		self::save( $state );
	}

	/**
	 * Test mode switched off: remove simulated data so real N+ starts clean.
	 *
	 * @param mixed $old Old settings.
	 * @param mixed $new New settings.
	 */
	public function on_settings_change( $old, $new ) {
		if ( ! empty( $old['sandbox'] ) && empty( $new['sandbox'] ) ) {
			set_transient( 'nplus_sso_sandbox_reset_' . get_current_user_id(), self::reset(), MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Remove all simulated N+ data.
	 *
	 * @return array{users:int,orders:int}
	 */
	public static function reset() {
		$counts = array( 'users' => 0, 'orders' => 0 );
		$state  = self::state();

		// Only the exact users/orders recorded while test mode was on, and only if still flagged.
		foreach ( $state['flagged_users'] as $user_id ) {
			if ( get_user_meta( $user_id, self::META_FLAG, true ) ) {
				delete_user_meta( $user_id, Provisioner::META_USER_ID );
				delete_user_meta( $user_id, self::META_FLAG );
				++$counts['users'];
			}
		}

		foreach ( $state['flagged_orders'] as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! $order || 'yes' !== $order->get_meta( self::META_FLAG ) ) {
				continue;
			}
			foreach ( array( Provisioner::META_USER_ID, Provisioner::META_HAS_ENROLLMENT, Provisioner::META_REVOKED, Provisioner::META_ATTEMPTS, self::META_FLAG ) as $key ) {
				$order->delete_meta_data( $key );
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				foreach ( array( Provisioner::ITEM_STATUS, Provisioner::ITEM_ORDER_ID, Provisioner::ITEM_ERROR, Provisioner::ITEM_ALREADY_ENROLLED, Provisioner::ITEM_ENROLLED_AT, Provisioner::ITEM_WEBSITE_ORDER_ID ) as $key ) {
					$item->delete_meta_data( $key );
				}
				$item->save();
			}
			$order->save();
			$order->add_order_note( __( 'N+: test-mode data removed. Use Order actions > "Sync to N+" to provision this order on the real N+ if needed.', 'nplus-sso' ) );
			++$counts['orders'];
		}

		delete_option( self::STATE_OPTION );
		Access::flush_cache();
		return $counts;
	}

	/**
	 * Manual "Clear test data" button.
	 */
	public function handle_reset() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nplus-sso' ), 403 );
		}
		check_admin_referer( self::RESET_ACTION );
		$counts = self::reset();
		set_transient( 'nplus_sso_sandbox_reset_' . get_current_user_id(), $counts, MINUTE_IN_SECONDS );
		wp_safe_redirect( Settings::page_url() );
		exit;
	}

	/**
	 * Warn admins on every screen while test mode is on.
	 */
	public function admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$reset = get_transient( 'nplus_sso_sandbox_reset_' . get_current_user_id() );
		if ( is_array( $reset ) ) {
			delete_transient( 'nplus_sso_sandbox_reset_' . get_current_user_id() );
			/* translators: 1: users, 2: orders */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'N+ test data cleared (%1$d learners, %2$d orders).', 'nplus-sso' ), $reset['users'], $reset['orders'] ) ) . '</p></div>';
		}
		if ( ! self::enabled() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'N+ test mode is ON.', 'nplus-sso' ),
			esc_html__( 'Purchases are NOT sent to N+: a simulated N+ on this site answers instead. Turn it off before selling for real.', 'nplus-sso' ),
			esc_url( Settings::page_url() ),
			esc_html__( 'N+ SSO settings', 'nplus-sso' )
		);
	}

	/**
	 * Simulated N+ state.
	 *
	 * @return array{users:array,orders:array,flagged_users:int[],flagged_orders:int[]}
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		$out   = array();
		foreach ( array( 'users', 'orders', 'flagged_users', 'flagged_orders' ) as $key ) {
			$out[ $key ] = isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? $state[ $key ] : array();
		}
		return $out;
	}

	/**
	 * Persist state.
	 *
	 * @param array $state State.
	 */
	private static function save( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}
}
