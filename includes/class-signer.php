<?php
/**
 * HMAC-SHA256 signatures required by the N+ APIs.
 *
 * Section 6 of the N+ SSO API documentation:
 *   - Create User API:  hash_hmac( 'sha256', "{email}:{timestamp}",  $secret )
 *   - Auto Login API:   hash_hmac( 'sha256', "{userid}:{timestamp}", $secret )
 *
 * Signatures must only ever be generated server-side.
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless signature helper.
 */
class Signer {

	/**
	 * Signature for the Create User API (string to sign: "email:timestamp").
	 *
	 * @param string $email     Email exactly as sent in the request.
	 * @param int    $timestamp Unix timestamp sent in the request.
	 * @param string $secret    Shared secret issued by N+.
	 * @return string Lower-case hex digest.
	 */
	public static function create_user( $email, $timestamp, $secret ) {
		return self::sign( $email . ':' . (int) $timestamp, $secret );
	}

	/**
	 * Signature for the Auto Login API (string to sign: "userid:timestamp").
	 *
	 * @param int    $nplus_user_id N+ user ID (data.id from Create User API).
	 * @param int    $timestamp     Unix timestamp.
	 * @param string $secret        Shared secret issued by N+.
	 * @return string Lower-case hex digest.
	 */
	public static function auto_login( $nplus_user_id, $timestamp, $secret ) {
		return self::sign( (int) $nplus_user_id . ':' . (int) $timestamp, $secret );
	}

	/**
	 * HMAC-SHA256.
	 *
	 * @param string $data   String to sign.
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function sign( $data, $secret ) {
		return hash_hmac( 'sha256', (string) $data, (string) $secret );
	}
}
