<?php
/**
 * HMAC-SHA256 signatures required by the N+ APIs.
 *
 * Two "string to sign" conventions exist:
 *
 *  - FORMAT_TIMESTAMP ("timestamp"): data = "{timestamp}".
 *    What N+ staging actually validates for Create User and Assign Subscription
 *    (verified against the signed sample requests N+ sent with the credentials).
 *  - FORMAT_PREFIXED ("prefixed"): data = "{email}:{timestamp}" / "{userid}:{timestamp}".
 *    What section 6 of the N+ SSO API documentation (PDF) describes.
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

	const FORMAT_TIMESTAMP = 'timestamp';
	const FORMAT_PREFIXED  = 'prefixed';

	/**
	 * Signature for the Create User API.
	 *
	 * @param string $email     Email exactly as sent in the request.
	 * @param int    $timestamp Unix timestamp sent in the request.
	 * @param string $secret    Shared secret issued by N+.
	 * @param string $format    FORMAT_TIMESTAMP or FORMAT_PREFIXED.
	 * @return string Lower-case hex digest.
	 */
	public static function create_user( $email, $timestamp, $secret, $format = self::FORMAT_PREFIXED ) {
		return self::sign( self::data( $email, $timestamp, $format ), $secret );
	}

	/**
	 * Signature for the Assign Subscription API (N+ signs the timestamp).
	 *
	 * @param int    $timestamp Unix timestamp.
	 * @param string $secret    Secret.
	 * @return string
	 */
	public static function create_order( $timestamp, $secret ) {
		return self::sign( (string) (int) $timestamp, $secret );
	}

	/**
	 * Signature for the Auto Login API.
	 *
	 * @param int    $nplus_user_id N+ user ID (data.id from Create User API).
	 * @param int    $timestamp     Unix timestamp.
	 * @param string $secret        Shared secret issued by N+.
	 * @param string $format        FORMAT_PREFIXED (userid:timestamp, default) or FORMAT_TIMESTAMP.
	 * @return string Lower-case hex digest.
	 */
	public static function auto_login( $nplus_user_id, $timestamp, $secret, $format = self::FORMAT_PREFIXED ) {
		return self::sign( self::data( (int) $nplus_user_id, $timestamp, $format ), $secret );
	}

	/**
	 * Build the string to sign.
	 *
	 * @param string|int $subject   Email or user ID.
	 * @param int        $timestamp Timestamp.
	 * @param string     $format    Format.
	 * @return string
	 */
	public static function data( $subject, $timestamp, $format ) {
		return self::FORMAT_TIMESTAMP === $format ? (string) (int) $timestamp : $subject . ':' . (int) $timestamp;
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
