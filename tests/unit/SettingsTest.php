<?php
use NPlusSSO\Logger;
use NPlusSSO\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['nplus_test_options'] = array();
	}

	public function test_defaults_follow_documentation() {
		$d = Settings::defaults();
		$this->assertSame( 'https://learn.nplus.global', $d['api_base_url'] );
		$this->assertSame( 'v1', $d['api_version'] );
		$this->assertSame( 5, $d['roleid'] );
	}

	public function test_sanitize_keeps_saved_secret_when_field_left_empty() {
		$GLOBALS['nplus_test_options']['nplus_sso_settings'] = array( 'secret' => 'OLD', 'api_key' => 'K' );
		$out = ( new Settings() )->sanitize( array( 'secret' => '', 'api_key' => 'NEW%41<x' ) );
		$this->assertSame( 'OLD', $out['secret'] );
		$this->assertSame( 'NEW%41<x', $out['api_key'], 'secrets must not be mangled by text sanitising' );
	}

	public function test_sanitize_can_clear_secret() {
		$GLOBALS['nplus_test_options']['nplus_sso_settings'] = array( 'secret' => 'OLD' );
		$out = ( new Settings() )->sanitize( array( 'secret_clear' => '1' ) );
		$this->assertSame( '', $out['secret'] );
	}

	public function test_sanitize_trigger_statuses_and_bounds() {
		$out = ( new Settings() )->sanitize( array( 'trigger_statuses' => array( 'pending', 'processing' ), 'max_attempts' => 999, 'request_timeout' => 1 ) );
		$this->assertSame( array( 'processing' ), $out['trigger_statuses'] );
		$this->assertSame( 20, $out['max_attempts'] );
		$this->assertSame( 5, $out['request_timeout'] );

		$out = ( new Settings() )->sanitize( array( 'trigger_statuses' => array() ) );
		$this->assertSame( array( 'completed' ), $out['trigger_statuses'] );
	}

	public function test_autologin_secret_falls_back_to_main_secret() {
		$GLOBALS['nplus_test_options']['nplus_sso_settings'] = array( 'secret' => 'MAIN' );
		$this->assertSame( 'MAIN', Settings::autologin_secret() );
		$GLOBALS['nplus_test_options']['nplus_sso_settings']['autologin_secret'] = 'AL';
		$this->assertSame( 'AL', Settings::autologin_secret() );
	}

	public function test_missing_credentials() {
		$this->assertSame( array( 'api_key', 'wstoken', 'secret' ), Settings::missing_credentials() );
	}

	public function test_logger_redacts_nested_secrets() {
		$out = Logger::redact( array( 'wstoken' => 'a', 'nested' => array( 'Signature' => 'b', 'email' => 'c' ) ) );
		$this->assertSame( '***', $out['wstoken'] );
		$this->assertSame( '***', $out['nested']['Signature'] );
		$this->assertSame( 'c', $out['nested']['email'] );
	}
}
