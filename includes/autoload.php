<?php
/**
 * Minimal PSR-4 style autoloader for the NPlusSSO namespace.
 *
 * NPlusSSO\Api_Client -> includes/class-api-client.php
 *
 * @package NPlusSSO
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'NPlusSSO\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = __DIR__ . '/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
