<?php
/**
 * The launch endpoint: /?nplus-sso=launch
 *
 * Every "Access N+" button links here, never directly to N+, so that the
 * Auto Login signature is generated server-side, at click time, with a fresh
 * timestamp, and only for the right person:
 *
 *   - logged-in customer           -> their own N+ user ID (if they have an active enrollment)
 *   - &order_id=..&key=wc_order_.. -> the buyer of that order (guest checkout / thank-you page)
 *
 * If the order is paid but background provisioning has not finished yet, the
 * launch endpoint provisions on demand before redirecting.
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * SSO launch.
 */
class Sso {

	const QUERY_VAR = 'nplus-sso';

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_launch' ), 1 );
	}

	/**
	 * Launch URL (safe to print in pages and emails: contains no signature).
	 *
	 * @param \WC_Order|null $order Optional order (adds order ID + key for guest launch).
	 * @return string
	 */
	public static function launch_url( $order = null ) {
		$args = array( self::QUERY_VAR => 'launch' );
		if ( $order instanceof \WC_Order ) {
			$args['order_id'] = $order->get_id();
			$args['key']      = $order->get_order_key();
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Handle a launch request.
	 */
	public function maybe_launch() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation link from emails; authorised by login session or order key.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || 'launch' !== $_GET[ self::QUERY_VAR ] ) {
			return;
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );

		$nplus_user_id = $order_id ? $this->resolve_from_order( $order_id, $key ) : $this->resolve_from_session();

		if ( is_wp_error( $nplus_user_id ) ) {
			$this->fail( $nplus_user_id );
			return;
		}

		$client = new Api_Client();
		$url    = $client->auto_login_url( $nplus_user_id );

		/**
		 * Fires right before the browser is sent to N+.
		 *
		 * @param int $nplus_user_id N+ user ID.
		 * @param int $wp_user_id    WP user ID (0 for guests).
		 */
		do_action( 'nplus_sso_before_launch', $nplus_user_id, get_current_user_id() );
		Logger::info( 'N+ launch', array( 'uid' => $nplus_user_id, 'wp_user' => get_current_user_id() ) );

		// The N+ host comes from admin settings, so allow it explicitly for wp_safe_redirect().
		$host = $client->host();
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $host ) {
				$hosts[] = $host;
				return $hosts;
			}
		);
		wp_safe_redirect( $url, 302, 'N+ SSO' );
		$this->terminate();
	}

	/**
	 * Logged-in customer launching their own N+ account.
	 *
	 * @return int|\WP_Error
	 */
	private function resolve_from_session() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::launch_url() ) );
			$this->terminate();
			return new \WP_Error( 'nplus_login_required', '' );
		}

		$user_id = get_current_user_id();

		// Paid but not yet provisioned (background job not run yet): do it now so a new
		// purchase is visible in N+ immediately. Learners who can already get in are not
		// held up by retries of previously failed items; the background retry handles those.
		$has_access  = Access::user_has_access( $user_id );
		$provisioner = new Provisioner();
		foreach ( Access::pending_orders( $user_id ) as $order ) {
			if ( ! $has_access || ! self::has_failed_items( $order ) ) {
				$provisioner->provision_order( $order );
			}
		}
		Access::flush_cache();

		if ( ! Access::user_has_access( $user_id ) ) {
			return $this->not_ready_error( ! empty( Access::customer_nplus_orders( $user_id ) ) );
		}

		return (int) get_user_meta( $user_id, Provisioner::META_USER_ID, true );
	}

	/**
	 * Launch for the buyer of a specific order (validated by order key).
	 *
	 * @param int    $order_id Order ID.
	 * @param string $key      Order key.
	 * @return int|\WP_Error
	 */
	private function resolve_from_order( $order_id, $key ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return new \WP_Error( 'nplus_invalid_link', __( 'This access link is not valid.', 'nplus-sso' ), array( 'status' => 403 ) );
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id ) {
			// Registered buyer: require them to be logged in as themselves.
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( wp_login_url( self::launch_url( $order ) ) );
				$this->terminate();
				return new \WP_Error( 'nplus_login_required', '' );
			}
			if ( get_current_user_id() !== $customer_id ) {
				return new \WP_Error( 'nplus_wrong_user', __( 'This order belongs to a different account.', 'nplus-sso' ), array( 'status' => 403 ) );
			}
		} elseif ( ! Settings::get( 'allow_guest_launch' ) ) {
			return new \WP_Error( 'nplus_guest_disabled', __( 'Please create an account or log in to access N+.', 'nplus-sso' ), array( 'status' => 403 ) );
		}

		if ( empty( Product_Fields::order_items( $order ) ) ) {
			return new \WP_Error( 'nplus_no_items', __( 'This order does not include N+ access.', 'nplus-sso' ), array( 'status' => 403 ) );
		}

		if ( 'yes' === $order->get_meta( Provisioner::META_REVOKED ) || ! Provisioner::is_eligible_status( $order ) ) {
			return $this->not_ready_error( true );
		}

		if ( Access::order_needs_provisioning( $order ) ) {
			( new Provisioner() )->provision_order( $order );
			$order = wc_get_order( $order_id );
		}

		$nplus_user_id = (int) $order->get_meta( Provisioner::META_USER_ID );
		if ( ! $nplus_user_id || ! Access::order_grants_access( $order ) ) {
			return $this->not_ready_error( true );
		}
		return $nplus_user_id;
	}

	/**
	 * Whether any N+ item of the order already failed provisioning.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private static function has_failed_items( $order ) {
		foreach ( Product_Fields::order_items( $order ) as $entry ) {
			if ( Provisioner::STATUS_FAILED === $entry['item']->get_meta( Provisioner::ITEM_STATUS ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Error shown while access is not (yet) available.
	 *
	 * @param bool $has_purchase Whether the customer bought an N+ product.
	 * @return \WP_Error
	 */
	private function not_ready_error( $has_purchase ) {
		if ( $has_purchase ) {
			return new \WP_Error( 'nplus_pending', __( 'Your N+ access is being set up. Please try again in a few minutes. If this persists, contact us with your order number.', 'nplus-sso' ), array( 'status' => 503 ) );
		}
		return new \WP_Error( 'nplus_no_access', __( 'You do not have an active N+ subscription yet.', 'nplus-sso' ), array( 'status' => 403 ) );
	}

	/**
	 * Show a friendly error page.
	 *
	 * @param \WP_Error $error Error.
	 */
	private function fail( \WP_Error $error ) {
		if ( 'nplus_login_required' === $error->get_error_code() ) {
			return; // Already redirected.
		}
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 403;
		$back   = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );

		wp_die(
			'<h1>' . esc_html__( 'N+ Learning', 'nplus-sso' ) . '</h1><p>' . esc_html( $error->get_error_message() ) . '</p><p><a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Back to my account', 'nplus-sso' ) . '</a></p>',
			esc_html__( 'N+ Learning', 'nplus-sso' ),
			array( 'response' => $status )
		);
	}

	/**
	 * End the request after a redirect (overridable in tests).
	 */
	protected function terminate() {
		exit;
	}
}
