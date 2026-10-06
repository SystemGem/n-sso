<?php
use NPlusSSO\Api_Client;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase {

	private function client( array $overrides = array() ) {
		return new Api_Client(
			array_merge(
				array(
					'base_url'         => 'https://learn.nplus.global/',
					'api_key'          => 'KEY123',
					'wstoken'          => 'TOKEN123',
					'secret'           => 'SECRET123',
					'autologin_secret' => '',
					'version'          => 'v1',
					'timeout'          => 20,
				),
				$overrides
			)
		);
	}

	private function respond( $code, $body ) {
		$GLOBALS['nplus_test_http'] = static function () use ( $code, $body ) {
			return array(
				'response' => array( 'code' => $code ),
				'body'     => is_string( $body ) ? $body : json_encode( $body ),
			);
		};
	}

	private function last_request() {
		$req = end( $GLOBALS['nplus_test_requests'] );
		parse_str( $req['args']['body'], $body );
		$req['parsed'] = $body;
		return $req;
	}

	protected function setUp(): void {
		$GLOBALS['nplus_test_requests'] = array();
		$GLOBALS['nplus_test_logs']     = array();
	}

	public function test_create_user_sends_documented_request() {
		$this->respond(
			200,
			array(
				'status'         => 'success',
				'message'        => 'User created.',
				'version'        => 'v1',
				'already_exists' => false,
				'data'           => array( 'id' => 81288, 'email' => 'test@test.com' ),
			)
		);

		$result = $this->client()->create_user(
			array(
				'firstname'      => 'Vishal',
				'lastname'       => 'Soni',
				'email'          => 'test@test.com',
				'phone1'         => '7089587765',
				'roleid'         => 5,
				'country'        => 'IN',
				'partner_userid' => '42',
			),
			1749360000
		);

		$this->assertIsArray( $result );
		$this->assertSame( 81288, $result['data']['id'] );

		$req = $this->last_request();
		$this->assertSame( 'https://learn.nplus.global/webservice/rest/server.php', $req['url'] );
		$this->assertSame( 'POST', $req['args']['method'] );
		$this->assertSame( 'KEY123', $req['args']['headers']['x-api-key'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $req['args']['headers']['Content-Type'] );

		$p = $req['parsed'];
		$this->assertSame( 'TOKEN123', $p['wstoken'] );
		$this->assertSame( 'local_lms_create_user_site', $p['wsfunction'] );
		$this->assertSame( 'json', $p['moodlewsrestformat'] );
		$this->assertSame( 'test@test.com', $p['email'] );
		$this->assertSame( '1749360000', $p['timestamp'] );
		$this->assertSame( 'v1', $p['version'] );
		$this->assertSame( '5', $p['roleid'] );
		// N+ staging signs the timestamp only (verified against the signed samples N+ sent).
		$this->assertSame( hash_hmac( 'sha256', '1749360000', 'SECRET123' ), $p['signature'] );
		foreach ( array( 'firstname', 'lastname', 'phone1', 'country', 'address', 'phone_country_code', 'company_name', 'partner_additional_info', 'partner_userid' ) as $field ) {
			$this->assertArrayHasKey( $field, $p, "missing $field" );
		}
	}

	public function test_create_user_prefixed_signature_per_pdf() {
		$this->respond( 200, array( 'status' => 'success', 'data' => array( 'id' => 1 ) ) );
		$this->client( array( 'signature_format' => 'prefixed' ) )->create_user( array( 'email' => 'a@b.co' ), 99 );
		$this->assertSame( hash_hmac( 'sha256', 'a@b.co:99', 'SECRET123' ), $this->last_request()['parsed']['signature'] );
	}

	public function test_function_names_come_from_config() {
		$this->respond( 200, array( 'status' => 'success', 'data' => array( 'id' => 1 ), 'orderid' => 2 ) );
		$client = $this->client(
			array(
				'fn_create_user'  => 'local_lms_apis_clone_create_user_site',
				'fn_create_order' => 'local_lms_apis_clone_create_order',
			)
		);
		$client->create_user( array( 'email' => 'a@b.co' ), 1 );
		$this->assertSame( 'local_lms_apis_clone_create_user_site', $this->last_request()['parsed']['wsfunction'] );
		$client->create_order( array( 'website_orderid' => 'x', 'campaignid' => 1, 'userid' => 2 ), 1 );
		$this->assertSame( 'local_lms_apis_clone_create_order', $this->last_request()['parsed']['wsfunction'] );
	}

	public function test_create_user_rejects_invalid_email_without_calling_api() {
		$GLOBALS['nplus_test_http'] = function () {
			$this->fail( 'API must not be called' );
		};
		$result = $this->client()->create_user( array( 'email' => 'not-an-email' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nplus_invalid_email', $result->get_error_code() );
	}

	public function test_create_user_existing_user_is_success() {
		$this->respond( 200, array( 'status' => 'success', 'already_exists' => true, 'data' => array( 'id' => 7 ) ) );
		$result = $this->client()->create_user( array( 'email' => 'a@b.co' ), 1 );
		$this->assertTrue( $result['already_exists'] );
		$this->assertSame( 7, $result['data']['id'] );
	}

	public function test_create_user_without_id_is_error() {
		$this->respond( 200, array( 'status' => 'success', 'data' => array() ) );
		$result = $this->client()->create_user( array( 'email' => 'a@b.co' ), 1 );
		$this->assertSame( 'nplus_missing_user_id', $result->get_error_code() );
	}

	public function test_create_order_sends_documented_request() {
		$this->respond( 200, array( 'status' => 'success', 'orderid' => 75606, 'isUserAlreadyEnrolled' => 0, 'message' => 'Subscription assignment successfully.' ) );

		$result = $this->client()->create_order(
			array(
				'website_orderid'        => '1001-5',
				'campaignid'             => 12345,
				'userid'                 => 81288,
				'quantity'               => 1,
				'payment'                => 499.5,
				'payment_currency'       => 'INR',
				'payment_status'         => 'completed',
				'source'                 => 'website',
				'subscription_skuid'     => 'NPLUS-SKU',
				'subscription_startdate' => '2026-10-05 10:00:00',
				'sendmail'               => 1,
			),
			1791315010
		);

		$this->assertSame( 75606, $result['orderid'] );
		$p = $this->last_request()['parsed'];
		$this->assertSame( 'local_lms_create_order', $p['wsfunction'] );
		$this->assertSame( 'TOKEN123', $p['wstoken'] );
		$this->assertSame( 'json', $p['moodlewsrestformat'] );
		$this->assertSame( '1001-5', $p['website_orderid'] );
		$this->assertSame( '12345', $p['campaignid'] );
		$this->assertSame( '81288', $p['userid'] );
		$this->assertSame( '499.50', $p['payment'] );
		$this->assertSame( 'INR', $p['payment_currency'] );
		$this->assertSame( 'NPLUS-SKU', $p['subscription_skuid'] );
		$this->assertSame( '2026-10-05 10:00:00', $p['subscription_startdate'] );
		$this->assertSame( '1', $p['sendmail'] );
		$this->assertSame( '1791315010', $p['timestamp'] );
		$this->assertSame( hash_hmac( 'sha256', '1791315010', 'SECRET123' ), $p['signature'] );
	}

	public function test_create_order_requires_ids() {
		$result = $this->client()->create_order( array( 'website_orderid' => 'x', 'campaignid' => 1 ) );
		$this->assertSame( 'nplus_missing_param', $result->get_error_code() );
	}

	public function test_moodle_exception_is_error() {
		$this->respond( 200, array( 'exception' => 'moodle_exception', 'errorcode' => 'invalidtoken', 'message' => 'Invalid token' ) );
		$result = $this->client()->create_user( array( 'email' => 'a@b.co' ), 1 );
		$this->assertSame( 'nplus_ws_invalidtoken', $result->get_error_code() );
		$this->assertSame( 'Invalid token', $result->get_error_message() );
	}

	public function test_failure_status_is_error() {
		$this->respond( 200, array( 'status' => 'error', 'message' => 'Invalid signature' ) );
		$result = $this->client()->create_order( array( 'website_orderid' => 'x', 'campaignid' => 1, 'userid' => 2 ) );
		$this->assertSame( 'nplus_api_failure', $result->get_error_code() );
		$this->assertSame( 'Invalid signature', $result->get_error_message() );
	}

	public function test_http_error_and_invalid_json() {
		$this->respond( 500, 'oops' );
		$this->assertSame( 'nplus_http_500', $this->client()->create_user( array( 'email' => 'a@b.co' ), 1 )->get_error_code() );

		$this->respond( 200, '<html>' );
		$this->assertSame( 'nplus_invalid_json', $this->client()->create_user( array( 'email' => 'a@b.co' ), 1 )->get_error_code() );
	}

	public function test_transport_error() {
		$GLOBALS['nplus_test_http'] = static function () {
			return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		};
		$result = $this->client()->create_user( array( 'email' => 'a@b.co' ), 1 );
		$this->assertSame( 'nplus_http_error', $result->get_error_code() );
	}

	public function test_not_configured_and_insecure_url() {
		$this->assertSame( 'nplus_not_configured', $this->client( array( 'wstoken' => '' ) )->create_user( array( 'email' => 'a@b.co' ) )->get_error_code() );
		$this->assertSame( 'nplus_insecure_url', $this->client( array( 'base_url' => 'http://learn.nplus.global' ) )->create_user( array( 'email' => 'a@b.co' ) )->get_error_code() );
	}

	public function test_auto_login_url_format() {
		$url = $this->client()->auto_login_url( 81288, 1749360000 );
		$sig = hash_hmac( 'sha256', '81288:1749360000', 'SECRET123' );
		$this->assertSame( 'https://learn.nplus.global/auto-login/?uid=81288&timestamp=1749360000&signature=' . $sig, $url );
	}

	public function test_auto_login_web_service() {
		$this->respond( 200, array( 'status' => 'success', 'data' => array( 'loginurl' => 'https://stage.nplus.global/sso?token=abc' ) ) );
		$client = $this->client( array( 'base_url' => 'https://stagelms.nplus.global', 'autologin_wstoken' => 'ALTOKEN' ) );
		$url    = $client->auto_login( 230428, 1769693436 );

		$this->assertSame( 'https://stage.nplus.global/sso?token=abc', $url );
		$req = $this->last_request();
		$this->assertSame( 'https://stagelms.nplus.global/webservice/rest/server.php', $req['url'] );
		$this->assertSame( 'KEY123', $req['args']['headers']['x-api-key'] );
		$p = $req['parsed'];
		$this->assertSame( 'local_react_lms_apis_sso_autologin', $p['wsfunction'] );
		$this->assertSame( 'ALTOKEN', $p['wstoken'], 'Auto Login has its own wstoken' );
		$this->assertSame( 'json', $p['moodlewsrestformat'] );
		$this->assertSame( '230428', $p['uid'] );
		$this->assertSame( '1769693436', $p['timestamp'] );
		$this->assertSame( hash_hmac( 'sha256', '230428:1769693436', 'SECRET123' ), $p['signature'] );
	}

	public function test_auto_login_web_service_falls_back_to_main_token_and_accepts_plain_url() {
		$this->respond( 200, array( 'url' => 'https://learn.nplus.global/x' ) );
		$this->assertSame( 'https://learn.nplus.global/x', $this->client()->auto_login( 1, 1 ) );
		$this->assertSame( 'TOKEN123', $this->last_request()['parsed']['wstoken'] );
	}

	public function test_auto_login_rejects_foreign_or_missing_urls() {
		$this->respond( 200, array( 'status' => 'success', 'loginurl' => 'https://evil.example.com/phish' ) );
		$this->assertSame( 'nplus_no_login_url', $this->client()->auto_login( 1, 1 )->get_error_code() );

		$this->respond( 200, array( 'status' => 'success', 'loginurl' => 'https://nplus.global.evil.com/' ) );
		$this->assertSame( 'nplus_no_login_url', $this->client()->auto_login( 1, 1 )->get_error_code() );

		$this->respond( 200, array( 'status' => 'success', 'loginurl' => 'http://learn.nplus.global/insecure' ) );
		$this->assertSame( 'nplus_no_login_url', $this->client()->auto_login( 1, 1 )->get_error_code() );

		$this->respond( 200, array( 'status' => 'error', 'message' => 'Invalid signature' ) );
		$this->assertSame( 'Invalid signature', $this->client()->auto_login( 1, 1 )->get_error_message() );
	}

	public function test_auto_login_redirect_mode_per_pdf() {
		$GLOBALS['nplus_test_http'] = function () {
			$this->fail( 'redirect mode must not call the API' );
		};
		$url = $this->client( array( 'autologin_mode' => 'redirect' ) )->auto_login( 81288, 1749360000 );
		$this->assertStringStartsWith( 'https://learn.nplus.global/auto-login/?uid=81288&timestamp=1749360000&signature=', $url );
	}

	public function test_auto_login_uses_dedicated_secret_when_set() {
		$url = $this->client( array( 'autologin_secret' => 'OTHER' ) )->auto_login_url( 5, 10 );
		$this->assertStringEndsWith( 'signature=' . hash_hmac( 'sha256', '5:10', 'OTHER' ), $url );
	}

	public function test_secrets_never_logged() {
		$GLOBALS['nplus_test_options']['nplus_sso_settings'] = array( 'debug' => 1 );
		$this->respond( 200, array( 'exception' => 'x', 'errorcode' => 'y', 'message' => 'z' ) );
		$this->client()->create_user( array( 'email' => 'a@b.co' ), 1 );
		$GLOBALS['nplus_test_options'] = array();

		$this->assertNotEmpty( $GLOBALS['nplus_test_logs'] );
		$all = implode( "\n", $GLOBALS['nplus_test_logs'] );
		$this->assertStringNotContainsString( 'TOKEN123', $all );
		$this->assertStringNotContainsString( 'SECRET123', $all );
		$this->assertStringNotContainsString( hash_hmac( 'sha256', '1', 'SECRET123' ), $all );
	}

	public function test_format_decimal() {
		$this->assertSame( '0.00', Api_Client::format_decimal( null ) );
		$this->assertSame( '1234.50', Api_Client::format_decimal( '1234.5' ) );
	}
}
