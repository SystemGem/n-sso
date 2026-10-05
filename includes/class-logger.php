<?php
/**
 * Logging with secret redaction.
 *
 * Errors are always logged; info/debug only when "Debug logging" is enabled.
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
class Logger {

	const SOURCE = 'nplus-sso';

	/**
	 * Keys whose values must never reach a log file.
	 *
	 * @var string[]
	 */
	const REDACT_KEYS = array( 'wstoken', 'signature', 'x-api-key', 'api_key', 'secret', 'autologin_secret' );

	/**
	 * Log an error (always written).
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * Log an informational message (debug mode only).
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( $message, array $context = array() ) {
		if ( Settings::get( 'debug' ) ) {
			self::write( 'info', $message, $context );
		}
	}

	/**
	 * Recursively redact secret values.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), self::REDACT_KEYS, true ) ) {
				$data[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			}
		}
		return $data;
	}

	/**
	 * Write to the WooCommerce logger, falling back to error_log().
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private static function write( $level, $message, array $context ) {
		$line = $message;
		if ( $context ) {
			$line .= ' ' . wp_json_encode( self::redact( $context ) );
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $line, array( 'source' => self::SOURCE ) );
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[nplus-sso][' . $level . '] ' . $line );
	}
}
