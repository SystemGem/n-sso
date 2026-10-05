<?php
/**
 * Uninstall: remove settings. Learner/order links (N+ user IDs, N+ order IDs) are
 * kept unless NPLUS_SSO_REMOVE_ALL_DATA is true, because they are the only record
 * of which N+ account belongs to which customer.
 *
 * @package NPlusSSO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'nplus_sso_settings' );

if ( defined( 'NPLUS_SSO_REMOVE_ALL_DATA' ) && NPLUS_SSO_REMOVE_ALL_DATA ) {
	delete_metadata( 'user', 0, '_nplus_user_id', '', true );
}
