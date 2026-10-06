<?php
/**
 * Unit test bootstrap: minimal WordPress function stubs so the pure parts of the
 * plugin (signatures, API client, settings sanitising, log redaction) can be
 * tested without a WordPress install. The full WordPress + WooCommerce flow is
 * covered by tests/e2e.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'NPLUS_SSO_FILE', dirname( __DIR__, 2 ) . '/nplus-sso.php' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['nplus_test_options']  = array();
$GLOBALS['nplus_test_http']     = null; // callable( $url, $args ) => response array|WP_Error.
$GLOBALS['nplus_test_requests'] = array();
$GLOBALS['nplus_test_logs']     = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $hook, $value ) { return $value; }
function do_action() {}
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function esc_url_raw( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['nplus_test_options'] ) ? $GLOBALS['nplus_test_options'][ $key ] : $default; }
function wp_remote_post( $url, $args ) {
	$GLOBALS['nplus_test_requests'][] = array( 'url' => $url, 'args' => $args );
	return call_user_func( $GLOBALS['nplus_test_http'], $url, $args );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) && isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }
function error_log_capture( $line ) { $GLOBALS['nplus_test_logs'][] = $line; }
function wc_get_logger() {
	return new class() {
		public function log( $level, $message ) { $GLOBALS['nplus_test_logs'][] = $level . ' ' . $message; }
	};
}

require dirname( __DIR__, 2 ) . '/includes/autoload.php';
