<?php
/**
 * Public helper functions for themes and other plugins (e.g. Edwiser Bridge customisations).
 *
 * @package NPlusSSO
 */

defined( 'ABSPATH' ) || exit;

/**
 * N+ user ID linked to a WordPress user (0 if none).
 *
 * @param int|null $wp_user_id Defaults to the current user.
 * @return int
 */
function nplus_sso_get_user_id( $wp_user_id = null ) {
	$wp_user_id = null === $wp_user_id ? get_current_user_id() : (int) $wp_user_id;
	return (int) get_user_meta( $wp_user_id, \NPlusSSO\Provisioner::META_USER_ID, true );
}

/**
 * Whether a WordPress user has an active N+ enrollment.
 *
 * @param int|null $wp_user_id Defaults to the current user.
 * @return bool
 */
function nplus_sso_user_has_access( $wp_user_id = null ) {
	return \NPlusSSO\Access::user_has_access( null === $wp_user_id ? get_current_user_id() : (int) $wp_user_id );
}

/**
 * URL of the launch endpoint (contains no secrets; the signed N+ URL is built at click time).
 *
 * @param WC_Order|null $order Optional order for order-scoped / guest launch.
 * @return string
 */
function nplus_sso_launch_url( $order = null ) {
	return \NPlusSSO\Sso::launch_url( $order );
}

/**
 * Provision an order now (create learner + assign subscriptions).
 *
 * @param int  $order_id Order ID.
 * @param bool $force    Ignore order status.
 * @return array|WP_Error Summary.
 */
function nplus_sso_provision_order( $order_id, $force = false ) {
	return ( new \NPlusSSO\Provisioner() )->provision_order( (int) $order_id, $force );
}
