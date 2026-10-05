<?php
/**
 * HTTP client for the N+ Learning Platform APIs.
 *
 *  - Create User API                  POST /webservice/rest/server.php  wsfunction=local_lms_create_user_site
 *  - N+ Subscription Assignment API   POST /webservice/rest/server.php  wsfunction=local_lms_create_order
 *  - Auto Login API                   GET  /auto-login/?uid=&timestamp=&signature=
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * API client. All methods return the decoded response array or a WP_Error.
 */
class Api_Client {

	const ENDPOINT        = '/webservice/rest/server.php';
	const AUTO_LOGIN_PATH = '/auto-login/';
	const FN_CREATE_USER  = 'local_lms_create_user_site';
	const FN_CREATE_ORDER = 'local_lms_create_order';

	/**
	 * Configuration.
	 *
	 * @var array<string,mixed>
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $config Optional explicit config (defaults to plugin settings).
	 */
	public function __construct( $config = null ) {
		$this->config = is_array( $config ) ? $config : array(
			'base_url'         => Settings::base_url(),
			'api_key'          => (string) Settings::get( 'api_key' ),
			'wstoken'          => (string) Settings::get( 'wstoken' ),
			'secret'           => (string) Settings::get( 'secret' ),
			'autologin_secret' => Settings::autologin_secret(),
			'version'          => (string) Settings::get( 'api_version' ),
			'timeout'          => (int) Settings::get( 'request_timeout' ),
		);
		$this->config['base_url'] = untrailingslashit( (string) $this->config['base_url'] );
		if ( empty( $this->config['autologin_secret'] ) ) {
			$this->config['autologin_secret'] = $this->config['secret'];
		}
	}

	/**
	 * Create (or look up) a learner on N+.
	 *
	 * Required keys in $user: firstname, lastname, email. Optional: phone1, roleid,
	 * country, address, phone_country_code, company_name, partner_additional_info,
	 * partner_userid.
	 *
	 * @param array<string,mixed> $user      Learner fields.
	 * @param int|null            $timestamp Unix timestamp (injectable for tests).
	 * @return array|WP_Error Decoded response; $response['data']['id'] is the N+ user ID.
	 */
	public function create_user( array $user, $timestamp = null ) {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;
		$email     = trim( (string) ( $user['email'] ?? '' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'nplus_invalid_email', __( 'A valid email is required to create an N+ user.', 'nplus-sso' ) );
		}

		$params = array(
			'wsfunction'              => self::FN_CREATE_USER,
			'firstname'               => (string) ( $user['firstname'] ?? '' ),
			'lastname'                => (string) ( $user['lastname'] ?? '' ),
			'email'                   => $email,
			'phone1'                  => (string) ( $user['phone1'] ?? '' ),
			'roleid'                  => (int) ( $user['roleid'] ?? 5 ),
			'signature'               => Signer::create_user( $email, $timestamp, $this->config['secret'] ),
			'timestamp'               => $timestamp,
			'version'                 => (string) $this->config['version'],
			'country'                 => (string) ( $user['country'] ?? '' ),
			'address'                 => (string) ( $user['address'] ?? '' ),
			'phone_country_code'      => (string) ( $user['phone_country_code'] ?? '' ),
			'company_name'            => (string) ( $user['company_name'] ?? '' ),
			'partner_additional_info' => (string) ( $user['partner_additional_info'] ?? '' ),
			'partner_userid'          => (string) ( $user['partner_userid'] ?? '' ),
		);

		$response = $this->call( $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['data']['id'] ) || (int) $response['data']['id'] <= 0 ) {
			return new WP_Error( 'nplus_missing_user_id', __( 'N+ did not return a user ID.', 'nplus-sso' ), array( 'response' => $response ) );
		}

		return $response;
	}

	/**
	 * Assign a purchased subscription to an N+ user.
	 *
	 * @param array<string,mixed> $order Keys: website_orderid, campaignid, userid, quantity, payment,
	 *                                   payment_currency, payment_status, source, subscription_skuid,
	 *                                   subscription_startdate, sendmail.
	 * @return array|WP_Error Decoded response; $response['orderid'] is the N+ order ID.
	 */
	public function create_order( array $order ) {
		foreach ( array( 'website_orderid', 'campaignid', 'userid' ) as $required ) {
			if ( empty( $order[ $required ] ) ) {
				/* translators: %s: parameter name */
				return new WP_Error( 'nplus_missing_param', sprintf( __( 'Missing required parameter: %s', 'nplus-sso' ), $required ) );
			}
		}

		$params = array(
			'wsfunction'             => self::FN_CREATE_ORDER,
			'website_orderid'        => (string) $order['website_orderid'],
			'campaignid'             => (int) $order['campaignid'],
			'userid'                 => (int) $order['userid'],
			'quantity'               => max( 1, (int) ( $order['quantity'] ?? 1 ) ),
			'payment'                => self::format_decimal( $order['payment'] ?? 0 ),
			'payment_currency'       => (string) ( $order['payment_currency'] ?? '' ),
			'payment_status'         => (string) ( $order['payment_status'] ?? '' ),
			'source'                 => (string) ( $order['source'] ?? '' ),
			'subscription_skuid'     => (string) ( $order['subscription_skuid'] ?? '' ),
			'subscription_startdate' => (string) ( $order['subscription_startdate'] ?? '' ),
			'sendmail'               => empty( $order['sendmail'] ) ? 0 : 1,
		);

		return $this->call( $params );
	}

	/**
	 * Build a signed Auto Login URL. Generate it at the moment of redirect: N+ validates the timestamp.
	 *
	 * @param int      $nplus_user_id N+ user ID.
	 * @param int|null $timestamp     Unix timestamp (injectable for tests).
	 * @return string
	 */
	public function auto_login_url( $nplus_user_id, $timestamp = null ) {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;
		$uid       = (int) $nplus_user_id;

		return $this->config['base_url'] . self::AUTO_LOGIN_PATH . '?' . http_build_query(
			array(
				'uid'       => $uid,
				'timestamp' => $timestamp,
				'signature' => Signer::auto_login( $uid, $timestamp, $this->config['autologin_secret'] ),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Host of the N+ platform (used to whitelist the redirect).
	 *
	 * @return string
	 */
	public function host() {
		return (string) wp_parse_url( $this->config['base_url'], PHP_URL_HOST );
	}

	/**
	 * POST to the Moodle web service endpoint and normalise errors.
	 *
	 * @param array<string,mixed> $params Function specific params.
	 * @return array|WP_Error
	 */
	private function call( array $params ) {
		if ( '' === $this->config['base_url'] || '' === $this->config['wstoken'] || '' === $this->config['api_key'] ) {
			return new WP_Error( 'nplus_not_configured', __( 'N+ API credentials are not configured.', 'nplus-sso' ) );
		}
		if ( 0 !== strpos( $this->config['base_url'], 'https://' ) && ! apply_filters( 'nplus_sso_allow_insecure_http', false ) ) {
			return new WP_Error( 'nplus_insecure_url', __( 'The N+ base URL must use HTTPS.', 'nplus-sso' ) );
		}

		$body = array_merge(
			array(
				'wstoken'            => $this->config['wstoken'],
				'moodlewsrestformat' => 'json',
			),
			$params
		);

		$url  = $this->config['base_url'] . self::ENDPOINT;
		$args = array(
			'method'      => 'POST',
			'timeout'     => max( 5, (int) $this->config['timeout'] ),
			'redirection' => 0,
			'headers'     => array(
				'x-api-key'    => $this->config['api_key'],
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			'body'        => http_build_query( $body, '', '&' ),
		);

		Logger::info( 'N+ request ' . $params['wsfunction'], self::loggable( $body ) );

		$raw = wp_remote_post( $url, $args );

		if ( is_wp_error( $raw ) ) {
			Logger::error( 'N+ transport error ' . $params['wsfunction'], array( 'error' => $raw->get_error_message() ) );
			return new WP_Error( 'nplus_http_error', $raw->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $raw );
		$payload = wp_remote_retrieve_body( $raw );
		$data    = json_decode( $payload, true );

		Logger::info( 'N+ response ' . $params['wsfunction'], array( 'code' => $code, 'body' => is_array( $data ) ? $data : substr( (string) $payload, 0, 500 ) ) );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : sprintf( 'HTTP %d', $code );
			Logger::error( 'N+ HTTP error ' . $params['wsfunction'], array( 'code' => $code, 'message' => $message ) );
			return new WP_Error( 'nplus_http_' . $code, $message, array( 'status' => $code, 'response' => $data ) );
		}

		if ( ! is_array( $data ) ) {
			Logger::error( 'N+ invalid JSON ' . $params['wsfunction'], array( 'body' => substr( (string) $payload, 0, 500 ) ) );
			return new WP_Error( 'nplus_invalid_json', __( 'N+ returned an invalid response.', 'nplus-sso' ) );
		}

		// Moodle web service exceptions: {"exception":"...","errorcode":"...","message":"..."}.
		if ( isset( $data['exception'] ) || isset( $data['errorcode'] ) ) {
			$message = $data['message'] ?? ( $data['errorcode'] ?? 'Unknown N+ error' );
			Logger::error( 'N+ web service exception ' . $params['wsfunction'], $data );
			return new WP_Error( 'nplus_ws_' . sanitize_key( $data['errorcode'] ?? 'exception' ), $message, array( 'response' => $data ) );
		}

		if ( ! isset( $data['status'] ) || 'success' !== strtolower( (string) $data['status'] ) ) {
			$message = $data['message'] ?? __( 'N+ reported a failure.', 'nplus-sso' );
			Logger::error( 'N+ API failure ' . $params['wsfunction'], $data );
			return new WP_Error( 'nplus_api_failure', $message, array( 'response' => $data ) );
		}

		return $data;
	}

	/**
	 * Request body with secrets redacted (and the PII kept minimal).
	 *
	 * @param array $body Body.
	 * @return array
	 */
	private static function loggable( array $body ) {
		return Logger::redact( $body );
	}

	/**
	 * Format a decimal amount for the API ("1234.50").
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	public static function format_decimal( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}
}
