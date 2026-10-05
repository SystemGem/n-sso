<?php
/**
 * Mock N+ Learning Platform for local / CI testing.
 *
 * Implements the three endpoints from the N+ SSO API documentation and validates
 * them the way N+ does (x-api-key, wstoken, HMAC-SHA256 signatures, timestamp window):
 *
 *   POST /webservice/rest/server.php  wsfunction=local_lms_create_user_site
 *   POST /webservice/rest/server.php  wsfunction=local_lms_create_order
 *   GET  /auto-login/?uid=&timestamp=&signature=
 *
 * Test helpers:
 *   GET  /__state          JSON dump of users, orders, logins, requests
 *   POST /__reset          clear state
 *   POST /__fail?fn=X&n=N  make the next N calls of wsfunction X fail
 *
 * Run: NPLUS_MOCK_STATE=/tmp/nplus.json php -S 127.0.0.1:8099 router.php
 */

$api_key  = getenv( 'NPLUS_MOCK_API_KEY' ) ?: 'test-api-key';
$wstoken  = getenv( 'NPLUS_MOCK_WSTOKEN' ) ?: 'test-wstoken';
$secret   = getenv( 'NPLUS_MOCK_SECRET' ) ?: 'test-secret';
$window   = 300; // Seconds a timestamp is accepted.
$state_fn = getenv( 'NPLUS_MOCK_STATE' ) ?: sys_get_temp_dir() . '/nplus-mock-state.json';

function load_state( $file ) {
	$empty = array( 'users' => array(), 'orders' => array(), 'logins' => array(), 'requests' => array(), 'fail' => array(), 'next_user' => 81288, 'next_order' => 75606 );
	if ( ! is_readable( $file ) ) {
		return $empty;
	}
	$data = json_decode( (string) file_get_contents( $file ), true );
	return is_array( $data ) ? array_merge( $empty, $data ) : $empty;
}
function save_state( $file, $state ) {
	file_put_contents( $file, json_encode( $state, JSON_PRETTY_PRINT ), LOCK_EX );
}
function json_out( $data, $code = 200 ) {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
	exit;
}
function ws_error( $errorcode, $message ) {
	json_out( array( 'exception' => 'moodle_exception', 'errorcode' => $errorcode, 'message' => $message ) );
}

$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$method = $_SERVER['REQUEST_METHOD'];
$state  = load_state( $state_fn );

if ( '/__state' === $path ) {
	json_out( $state );
}
if ( '/__reset' === $path ) {
	@unlink( $state_fn );
	json_out( array( 'ok' => true ) );
}
if ( '/__fail' === $path ) {
	$state['fail'][ $_GET['fn'] ] = (int) ( $_GET['n'] ?? 1 );
	save_state( $state_fn, $state );
	json_out( array( 'ok' => true ) );
}

if ( '/webservice/rest/server.php' === $path ) {
	if ( 'POST' !== $method ) {
		json_out( array( 'status' => 'error', 'message' => 'POST required' ), 405 );
	}
	$headers = array_change_key_case( function_exists( 'getallheaders' ) ? getallheaders() : array(), CASE_LOWER );
	$p       = $_POST;
	$fn      = $p['wsfunction'] ?? '';

	$log            = $p;
	$log['wstoken'] = isset( $log['wstoken'] ) ? '***' : null;
	$state['requests'][] = array( 'fn' => $fn, 'params' => $log, 'time' => time() );
	save_state( $state_fn, $state );

	if ( ( $headers['x-api-key'] ?? '' ) !== $api_key ) {
		json_out( array( 'status' => 'error', 'message' => 'Invalid API key' ), 401 );
	}
	if ( ( $p['wstoken'] ?? '' ) !== $wstoken ) {
		ws_error( 'invalidtoken', 'Invalid token - token not found' );
	}
	if ( ( $p['moodlewsrestformat'] ?? '' ) !== 'json' ) {
		ws_error( 'invalidformat', 'moodlewsrestformat must be json' );
	}
	if ( ! empty( $state['fail'][ $fn ] ) ) {
		$state['fail'][ $fn ]--;
		save_state( $state_fn, $state );
		json_out( array( 'status' => 'error', 'message' => 'Simulated N+ outage' ), 503 );
	}

	if ( 'local_lms_create_user_site' === $fn ) {
		foreach ( array( 'firstname', 'lastname', 'email', 'roleid', 'signature', 'timestamp', 'version' ) as $req ) {
			if ( ! isset( $p[ $req ] ) || '' === $p[ $req ] ) {
				ws_error( 'missingparam', "Missing parameter: $req" );
			}
		}
		if ( abs( time() - (int) $p['timestamp'] ) > $window ) {
			json_out( array( 'status' => 'error', 'message' => 'Timestamp expired' ) );
		}
		$expected = hash_hmac( 'sha256', $p['email'] . ':' . $p['timestamp'], $secret );
		if ( ! hash_equals( $expected, (string) $p['signature'] ) ) {
			json_out( array( 'status' => 'error', 'message' => 'Invalid signature' ) );
		}
		$email = strtolower( $p['email'] );
		foreach ( $state['users'] as $user ) {
			if ( $user['email'] === $email ) {
				json_out( array( 'status' => 'success', 'message' => 'User already exists.', 'version' => 'v1', 'already_exists' => true, 'data' => $user ) );
			}
		}
		$user = array(
			'id'                      => $state['next_user']++,
			'firstname'               => $p['firstname'],
			'lastname'                => $p['lastname'],
			'email'                   => $email,
			'phone1'                  => $p['phone1'] ?? '',
			'roleid'                  => (int) $p['roleid'],
			'company_name'            => $p['company_name'] ?? '',
			'partner_additional_info' => $p['partner_additional_info'] ?? null,
			'partner_userid'          => $p['partner_userid'] ?? null,
			'country'                 => $p['country'] ?? '',
			'phone_country_code'      => $p['phone_country_code'] ?? '',
		);
		$state['users'][ (string) $user['id'] ] = $user;
		save_state( $state_fn, $state );
		json_out( array( 'status' => 'success', 'message' => 'User created.', 'version' => 'v1', 'already_exists' => false, 'data' => $user ) );
	}

	if ( 'local_lms_create_order' === $fn ) {
		foreach ( array( 'website_orderid', 'campaignid', 'userid', 'quantity', 'payment', 'payment_currency', 'payment_status', 'source', 'subscription_skuid', 'subscription_startdate', 'sendmail' ) as $req ) {
			if ( ! isset( $p[ $req ] ) ) {
				ws_error( 'missingparam', "Missing parameter: $req" );
			}
		}
		if ( ! isset( $state['users'][ (string) (int) $p['userid'] ] ) ) {
			json_out( array( 'status' => 'error', 'message' => 'User not found' ) );
		}
		$already = 0;
		foreach ( $state['orders'] as $order ) {
			if ( $order['website_orderid'] === $p['website_orderid'] ) {
				// Same partner order sent twice: N+ returns the existing order.
				json_out( array( 'status' => 'success', 'orderid' => $order['orderid'], 'isUserAlreadyEnrolled' => 1, 'message' => 'Subscription assignment successfully.' ) );
			}
			if ( (int) $order['userid'] === (int) $p['userid'] && (int) $order['campaignid'] === (int) $p['campaignid'] ) {
				$already = 1;
			}
		}
		$order                                   = array_merge( array( 'orderid' => $state['next_order']++ ), $p );
		unset( $order['wstoken'] );
		$state['orders'][] = $order;
		save_state( $state_fn, $state );
		json_out( array( 'status' => 'success', 'orderid' => $order['orderid'], 'isUserAlreadyEnrolled' => $already, 'message' => 'Subscription assignment successfully.' ) );
	}

	ws_error( 'invalidfunction', 'Unknown wsfunction' );
}

if ( '/auto-login/' === $path || '/auto-login' === $path ) {
	$uid = (string) ( $_GET['uid'] ?? '' );
	$ts  = (int) ( $_GET['timestamp'] ?? 0 );
	$sig = (string) ( $_GET['signature'] ?? '' );
	$ok  = isset( $state['users'][ $uid ] )
		&& abs( time() - $ts ) <= $window
		&& hash_equals( hash_hmac( 'sha256', $uid . ':' . $ts, $secret ), $sig );

	$state['logins'][] = array( 'uid' => $uid, 'ok' => $ok, 'time' => time() );
	save_state( $state_fn, $state );

	header( 'Content-Type: text/html; charset=utf-8' );
	if ( ! $ok ) {
		http_response_code( 403 );
		echo '<!doctype html><title>N+ login failed</title><h1 id="nplus-error">Auto login failed</h1>';
		exit;
	}
	$u       = $state['users'][ $uid ];
	$courses = array_values( array_filter( $state['orders'], static function ( $o ) use ( $uid ) { return (string) (int) $o['userid'] === $uid; } ) );
	echo '<!doctype html><title>N+ Dashboard</title>';
	echo '<h1 id="nplus-welcome">Welcome to N+, ' . htmlspecialchars( $u['firstname'] . ' ' . $u['lastname'] ) . '</h1>';
	echo '<p id="nplus-uid">N+ user ' . htmlspecialchars( $uid ) . '</p><ul id="nplus-subscriptions">';
	foreach ( $courses as $c ) {
		echo '<li>Campaign ' . (int) $c['campaignid'] . ' / ' . htmlspecialchars( $c['subscription_skuid'] ) . '</li>';
	}
	echo '</ul>';
	exit;
}

http_response_code( 404 );
echo 'Not found';
