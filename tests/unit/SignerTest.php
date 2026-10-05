<?php
use NPlusSSO\Signer;
use PHPUnit\Framework\TestCase;

class SignerTest extends TestCase {

	public function test_create_user_signature_matches_documented_algorithm() {
		// N+ docs §6: hash_hmac('sha256', $email . ':' . $timestamp, $secret).
		$expected = hash_hmac( 'sha256', 'test@test.com:1749360000', 's3cret' );
		$this->assertSame( $expected, Signer::create_user( 'test@test.com', 1749360000, 's3cret' ) );
	}

	public function test_auto_login_signature_matches_documented_algorithm() {
		// N+ docs §6: hash_hmac('sha256', $userid . ':' . $timestamp, $secret).
		$expected = hash_hmac( 'sha256', '81288:1749360000', 's3cret' );
		$this->assertSame( $expected, Signer::auto_login( 81288, 1749360000, 's3cret' ) );
		$this->assertSame( $expected, Signer::auto_login( '81288', '1749360000', 's3cret' ) );
	}

	public function test_rfc4231_known_vector() {
		// RFC 4231 test case 2 guards against an accidental algorithm/encoding change.
		$this->assertSame(
			'5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843',
			Signer::sign( 'what do ya want for nothing?', 'Jefe' )
		);
	}

	public function test_signature_changes_with_secret_and_timestamp() {
		$a = Signer::auto_login( 1, 100, 'x' );
		$this->assertNotSame( $a, Signer::auto_login( 1, 101, 'x' ) );
		$this->assertNotSame( $a, Signer::auto_login( 1, 100, 'y' ) );
		$this->assertNotSame( $a, Signer::auto_login( 2, 100, 'x' ) );
	}
}
