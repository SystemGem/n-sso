<?php
/**
 * "Test N+ connection" tool on the settings page.
 *
 * Runs the real integration sequence against the configured N+ server
 * (Create User -> Assign Subscription -> Auto Login) for a test learner and
 * shows the result of each step, so the integration can be verified on staging
 * without placing a WooCommerce order.
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Connection test.
 */
class Connection_Test {

	const ACTION    = 'nplus_sso_connection_test';
	const TRANSIENT = 'nplus_sso_connection_test_';

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'nplus_sso_settings_after_form', array( $this, 'render' ) );
	}

	/**
	 * Render the form and the last result.
	 */
	public function render() {
		$result = get_transient( self::TRANSIENT . get_current_user_id() );
		?>
		<hr />
		<h2 id="nplus-test"><?php esc_html_e( 'Test N+ connection', 'nplus-sso' ); ?></h2>
		<p><?php esc_html_e( 'Runs the real sequence against the N+ server above: creates (or finds) a test learner, assigns a subscription and requests an Auto Login link. This creates real records on N+, so use the staging server and the test campaign N+ gave you.', 'nplus-sso' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="nplus_test_email"><?php esc_html_e( 'Test learner email', 'nplus-sso' ); ?></label></th>
					<td><input type="email" class="regular-text" id="nplus_test_email" name="email" required value="<?php echo esc_attr( 'nplus-test-' . wp_date( 'YmdHi' ) . '@yopmail.com' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="nplus_test_campaign"><?php esc_html_e( 'Campaign ID', 'nplus-sso' ); ?></label></th>
					<td><input type="number" id="nplus_test_campaign" name="campaign" min="0" value="" placeholder="e.g. 889904" />
						<p class="description"><?php esc_html_e( 'Leave empty to only test Create User and Auto Login.', 'nplus-sso' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="nplus_test_sku"><?php esc_html_e( 'Subscription SKU', 'nplus-sso' ); ?></label></th>
					<td><input type="text" id="nplus_test_sku" name="sku" value="" /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Run test', 'nplus-sso' ), 'secondary' ); ?>
		</form>
		<?php
		if ( ! is_array( $result ) ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Last test result', 'nplus-sso' ) . ' <small>(' . esc_html( $result['time'] ) . ')</small></h3>';
		echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>' . esc_html__( 'Step', 'nplus-sso' ) . '</th><th>' . esc_html__( 'Result', 'nplus-sso' ) . '</th><th>' . esc_html__( 'N+ response', 'nplus-sso' ) . '</th></tr></thead><tbody>';
		foreach ( $result['steps'] as $step ) {
			printf(
				'<tr><td>%s</td><td style="color:%s;font-weight:600">%s</td><td><code style="white-space:pre-wrap;word-break:break-all">%s</code></td></tr>',
				esc_html( $step['label'] ),
				$step['ok'] ? '#008a20' : '#b32d2e',
				$step['ok'] ? esc_html__( 'OK', 'nplus-sso' ) : esc_html__( 'Failed', 'nplus-sso' ),
				esc_html( $step['detail'] )
			);
		}
		echo '</tbody></table>';
		if ( ! empty( $result['login_url'] ) ) {
			echo '<p><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' . esc_url( $result['login_url'] ) . '">' . esc_html__( 'Open N+ as the test learner', 'nplus-sso' ) . '</a> ';
			echo '<span class="description">' . esc_html__( 'Login links are short-lived: if it has expired, run the test again.', 'nplus-sso' ) . '</span></p>';
		}
	}

	/**
	 * Run the test.
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nplus-sso' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$campaign = isset( $_POST['campaign'] ) ? absint( $_POST['campaign'] ) : 0;
		$sku      = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';

		set_transient( self::TRANSIENT . get_current_user_id(), self::run( $email, $campaign, $sku ), HOUR_IN_SECONDS );
		wp_safe_redirect( Settings::page_url() . '#nplus-test' );
		exit;
	}

	/**
	 * Execute the three API steps.
	 *
	 * @param string          $email    Test email.
	 * @param int             $campaign Campaign ID (0 = skip subscription).
	 * @param string          $sku      Subscription SKU.
	 * @param Api_Client|null $client   Client.
	 * @return array{time:string,steps:array,login_url:string}
	 */
	public static function run( $email, $campaign, $sku, $client = null ) {
		$client = $client ? $client : new Api_Client();
		$result = array(
			'time'      => wp_date( 'Y-m-d H:i:s' ),
			'steps'     => array(),
			'login_url' => '',
		);

		$missing = Settings::missing_credentials();
		if ( $missing ) {
			$result['steps'][] = self::step( __( 'Configuration', 'nplus-sso' ), false, 'Missing: ' . implode( ', ', $missing ) );
			return $result;
		}
		$diag = array();
		foreach ( $client->diagnostics() as $key => $value ) {
			$diag[] = $key . ': ' . $value;
		}
		$diag[]            = 'server time: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC (timestamp ' . time() . ')';
		$result['steps'][] = self::step( __( 'Configuration', 'nplus-sso' ), true, implode( "\n", $diag ) );

		$user = $client->create_user(
			array(
				'firstname'               => 'NPlus',
				'lastname'                => 'Test',
				'email'                   => $email,
				'roleid'                  => (int) Settings::get( 'roleid' ),
				'partner_additional_info' => 'connection test from ' . home_url(),
			)
		);
		$result['steps'][] = self::step( __( '1. Create User', 'nplus-sso' ), ! is_wp_error( $user ), $user );
		if ( is_wp_error( $user ) ) {
			return $result;
		}
		$uid = (int) $user['data']['id'];

		if ( $campaign ) {
			$order             = $client->create_order(
				array(
					'website_orderid'        => 'TEST-' . time(),
					'campaignid'             => $campaign,
					'userid'                 => $uid,
					'quantity'               => 1,
					'payment'                => 0,
					'payment_currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
					'payment_status'         => (string) Settings::get( 'payment_status' ),
					'source'                 => (string) Settings::get( 'source' ),
					'subscription_skuid'     => $sku,
					'subscription_startdate' => wp_date( (string) Settings::get( 'date_format' ) ),
					'sendmail'               => 0,
				)
			);
			$result['steps'][] = self::step( __( '2. Assign Subscription', 'nplus-sso' ), ! is_wp_error( $order ), $order );
		}

		$login             = $client->auto_login( $uid );
		$result['steps'][] = self::step( __( '3. Auto Login', 'nplus-sso' ), ! is_wp_error( $login ), is_wp_error( $login ) ? $login : __( 'Login link received.', 'nplus-sso' ) );
		if ( ! is_wp_error( $login ) ) {
			$result['login_url'] = $login;
		}
		return $result;
	}

	/**
	 * One result row (secrets redacted).
	 *
	 * @param string $label  Label.
	 * @param bool   $ok     Success.
	 * @param mixed  $detail Response, WP_Error or text.
	 * @return array
	 */
	private static function step( $label, $ok, $detail ) {
		if ( is_wp_error( $detail ) ) {
			$data   = $detail->get_error_data();
			$detail = $detail->get_error_message() . ( is_array( $data ) && ! empty( $data['response'] ) ? "\n" . wp_json_encode( Logger::redact( (array) $data['response'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '' );
			if ( is_array( $data ) && isset( $data['status'] ) && in_array( (int) $data['status'], array( 401, 403 ), true ) ) {
				$detail .= "\n\n" . __( 'N+ (or a firewall in front of it) refused the request before checking the data. Check that the API key above matches "Api key" from N+ exactly (browsers sometimes auto-fill a saved password into these fields), and ask N+ whether your server IP must be allow-listed.', 'nplus-sso' );
			}
		} elseif ( is_array( $detail ) ) {
			$detail = wp_json_encode( Logger::redact( $detail ) );
		}
		return array(
			'label'  => $label,
			'ok'     => (bool) $ok,
			'detail' => (string) $detail,
		);
	}
}
