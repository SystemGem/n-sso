<?php
/**
 * Server-side end-to-end scenarios run inside the real WordPress + WooCommerce
 * site against the mock N+ server:
 *
 *   wp eval-file tests/e2e/scenarios.php
 *
 * Covers what is awkward to drive through a browser: N+ outages and automatic
 * retry, idempotency, refunds, unpaid orders, quantities, emails and signature
 * tampering.
 *
 * @package NPlusSSO
 */

use NPlusSSO\Access;
use NPlusSSO\Api_Client;
use NPlusSSO\Order_Handler;
use NPlusSSO\Provisioner;

// wp eval-file runs this file inside a function, so share state via constants/static, not globals.
$env  = json_decode( file_get_contents( ABSPATH . 'e2e.json' ), true );
$mock = $env['mockUrl'];
define( 'NPLUS_E2E_MOCK', $mock );

function check( $label = null, $condition = null ) {
	static $failures = 0;
	if ( null === $label ) {
		return $failures;
	}
	if ( $condition ) {
		WP_CLI::log( "  \u{2713} $label" );
	} else {
		++$failures;
		WP_CLI::warning( "  \u{2717} $label" );
	}
	return $failures;
}
function mock_state() {
	return json_decode( wp_remote_retrieve_body( wp_remote_get( NPLUS_E2E_MOCK . '/__state' ) ), true );
}
function new_order( $user_id, $product_id, $qty = 1, $status = 'processing' ) {
	$order = wc_create_order( array( 'customer_id' => $user_id ) );
	$order->add_product( wc_get_product( $product_id ), $qty );
	$order->set_address(
		array(
			'first_name' => 'Meera',
			'last_name'  => 'Iyer',
			'email'      => get_userdata( $user_id )->user_email,
			'phone'      => '9000000001',
			'country'    => 'IN',
			'state'      => 'KA',
			'city'       => 'Bengaluru',
			'address_1'  => '5 Residency Rd',
			'postcode'   => '560025',
		),
		'billing'
	);
	$order->set_payment_method( 'cod' );
	$order->calculate_totals();
	$order->save();
	if ( $status ) {
		$order->update_status( $status );
	}
	return wc_get_order( $order->get_id() );
}
function item_of( $order ) {
	$items = $order->get_items();
	return reset( $items );
}
function run_provisioning_jobs( $order_id ) {
	( new Order_Handler() )->run_scheduled( $order_id );
	Access::flush_cache();
	return wc_get_order( $order_id );
}

// Synchronous provisioning keeps these scenarios deterministic.
add_filter( 'nplus_sso_async_provisioning', '__return_false' );

$user_id = wp_insert_user(
	array(
		'user_login' => 'meera' . wp_rand(),
		'user_email' => 'meera' . wp_rand() . '@example.com',
		'user_pass'  => wp_generate_password(),
		'role'       => 'customer',
	)
);
$product = (int) $env['nplusProductId'];

WP_CLI::log( 'Scenario: N+ outage during subscription assignment, then automatic retry' );
wp_remote_post( $mock . '/__fail?fn=local_lms_create_order&n=1' );
$orders_before = count( mock_state()['orders'] );
$order         = new_order( $user_id, $product, 2 ); // update_status('processing') fires the plugin.
$order         = wc_get_order( $order->get_id() );
$item          = item_of( $order );
check( 'item is marked failed', Provisioner::STATUS_FAILED === $item->get_meta( Provisioner::ITEM_STATUS ) );
check( 'error message stored', false !== strpos( (string) $item->get_meta( Provisioner::ITEM_ERROR ), 'Simulated N+ outage' ) );
check( 'N+ user was still created and linked', (int) get_user_meta( $user_id, Provisioner::META_USER_ID, true ) > 0 );
check(
	'retry scheduled in Action Scheduler',
	! empty(
		as_get_scheduled_actions(
			array(
				'hook'   => Order_Handler::ACTION_HOOK,
				'args'   => array( 'order_id' => $order->get_id() ),
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		)
	)
);
check( 'learner has no access yet', ! Access::user_has_access( $user_id ) );

$order = run_provisioning_jobs( $order->get_id() );
$item  = item_of( $order );
check( 'retry succeeds', Provisioner::STATUS_ENROLLED === $item->get_meta( Provisioner::ITEM_STATUS ) );
check( 'N+ order id stored', (int) $item->get_meta( Provisioner::ITEM_ORDER_ID ) > 0 );
check( 'learner now has access', Access::user_has_access( $user_id ) );
$state = mock_state();
$last  = end( $state['orders'] );
check( 'quantity 2 sent', '2' === $last['quantity'] );
check( 'payment is the line total', '9998.00' === $last['payment'] );
check( 'website_orderid is order-item', $order->get_order_number() . '-' . $item->get_id() === $last['website_orderid'] );

WP_CLI::log( 'Scenario: re-running provisioning is idempotent' );
$count = count( mock_state()['orders'] );
$res   = nplus_sso_provision_order( $order->get_id() );
check( 'nothing re-sent', 1 === $res['skipped'] && 0 === $res['enrolled'] && count( mock_state()['orders'] ) === $count );
$order->update_status( 'completed' ); // processing -> completed fires the hook again.
check( 'status change to completed does not duplicate', count( mock_state()['orders'] ) === $count );

WP_CLI::log( 'Scenario: refund removes website access, re-completing restores it' );
$order->update_status( 'refunded' );
Access::flush_cache();
check( 'order flagged revoked', 'yes' === wc_get_order( $order->get_id() )->get_meta( Provisioner::META_REVOKED ) );
check( 'learner loses access', ! Access::user_has_access( $user_id ) );
$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
$note  = implode( "\n", wp_list_pluck( $notes, 'content' ) );
check( 'admin note names the N+ order to cancel', false !== strpos( $note, 'ask N+ support to cancel N+ order(s): ' . $item->get_meta( Provisioner::ITEM_ORDER_ID ) ) );
wc_get_order( $order->get_id() )->update_status( 'completed' );
Access::flush_cache();
check( 'access restored', Access::user_has_access( $user_id ) );

WP_CLI::log( 'Scenario: unpaid orders are not provisioned' );
$count   = count( mock_state()['orders'] );
$pending = new_order( $user_id, $product, 1, 'on-hold' );
$res     = nplus_sso_provision_order( $pending->get_id() );
check( 'on-hold order refused', is_wp_error( $res ) && 'nplus_not_paid' === $res->get_error_code() );
check( 'no N+ call made', count( mock_state()['orders'] ) === $count );

WP_CLI::log( 'Scenario: customer email contains the N+ launch link' );
$log = (string) @file_get_contents( WP_CONTENT_DIR . '/e2e-mail.log' );
check( 'processing/completed email has launch link', false !== strpos( $log, 'nplus-sso=launch' ) );
check( 'emails never contain a signature', false === strpos( $log, 'signature=' ) );

WP_CLI::log( 'Scenario: N+ rejects tampered or expired auto-login links' );
$uid    = (int) get_user_meta( $user_id, Provisioner::META_USER_ID, true );
$client = new Api_Client();
$good   = $client->auto_login_url( $uid );
check( 'valid link accepted', 200 === wp_remote_retrieve_response_code( wp_remote_get( $good ) ) );
check( 'other uid with same signature rejected', 403 === wp_remote_retrieve_response_code( wp_remote_get( str_replace( 'uid=' . $uid, 'uid=' . ( $uid + 1 ), $good ) ) ) );
check( 'expired timestamp rejected', 403 === wp_remote_retrieve_response_code( wp_remote_get( $client->auto_login_url( $uid, time() - 3600 ) ) ) );

$failures = check();
if ( $failures ) {
	WP_CLI::error( "$failures scenario check(s) failed." );
}
WP_CLI::success( 'All server-side scenarios passed.' );
