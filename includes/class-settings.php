<?php
/**
 * Plugin settings storage and admin screen (WooCommerce > N+ SSO).
 *
 * Every credential can also be supplied as a constant in wp-config.php, which
 * takes precedence over the database value and keeps secrets out of the DB:
 *
 *   define( 'NPLUS_SSO_API_BASE_URL', 'https://learn.nplus.global' );
 *   define( 'NPLUS_SSO_API_KEY', '...' );          // x-api-key header
 *   define( 'NPLUS_SSO_WSTOKEN', '...' );          // wstoken
 *   define( 'NPLUS_SSO_SECRET', '...' );           // HMAC secret (Create User)
 *   define( 'NPLUS_SSO_AUTOLOGIN_SECRET', '...' ); // HMAC secret (Auto Login), optional
 *   define( 'NPLUS_SSO_AUTOLOGIN_WSTOKEN', '...' ); // wstoken for the Auto Login web service, optional
 *   define( 'NPLUS_SSO_FN_CREATE_USER', 'local_lms_apis_clone_create_user_site' ); // staging names
 *   define( 'NPLUS_SSO_FN_CREATE_ORDER', 'local_lms_apis_clone_create_order' );
 *   define( 'NPLUS_SSO_FN_AUTOLOGIN', 'local_react_lms_apis_sso_autologin' );
 *
 * @package NPlusSSO
 */

namespace NPlusSSO;

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class Settings {

	const OPTION = 'nplus_sso_settings';

	/**
	 * Settings that may be overridden by a wp-config.php constant.
	 *
	 * @var array<string,string>
	 */
	const CONSTANTS = array(
		'api_base_url'     => 'NPLUS_SSO_API_BASE_URL',
		'api_key'          => 'NPLUS_SSO_API_KEY',
		'wstoken'          => 'NPLUS_SSO_WSTOKEN',
		'secret'           => 'NPLUS_SSO_SECRET',
		'autologin_secret'    => 'NPLUS_SSO_AUTOLOGIN_SECRET',
		'autologin_wstoken'   => 'NPLUS_SSO_AUTOLOGIN_WSTOKEN',
		'fn_create_user'      => 'NPLUS_SSO_FN_CREATE_USER',
		'fn_create_order'     => 'NPLUS_SSO_FN_CREATE_ORDER',
		'fn_autologin'        => 'NPLUS_SSO_FN_AUTOLOGIN',
		'autologin_mode'      => 'NPLUS_SSO_AUTOLOGIN_MODE',
		'signature_format'    => 'NPLUS_SSO_SIGNATURE_FORMAT',
		'autologin_signature' => 'NPLUS_SSO_AUTOLOGIN_SIGNATURE',
	);

	/**
	 * Fields that are secrets: never rendered back into the form.
	 *
	 * @var string[]
	 */
	const SECRET_FIELDS = array( 'api_key', 'wstoken', 'secret', 'autologin_secret', 'autologin_wstoken' );

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'api_base_url'       => 'https://learn.nplus.global',
			'api_key'            => '',
			'wstoken'            => '',
			'secret'             => '',
			'autologin_secret'   => '',
			'autologin_wstoken'  => '',
			// Function names: PDF documentation defaults. N+ staging uses local_lms_apis_clone_*.
			'fn_create_user'     => 'local_lms_create_user_site',
			'fn_create_order'    => 'local_lms_create_order',
			'fn_autologin'       => 'local_react_lms_apis_sso_autologin',
			// "ws" = Auto Login web service returning a login URL (N+ staging); "redirect" = GET /auto-login/ (PDF).
			'autologin_mode'      => 'ws',
			// "timestamp" = sign the timestamp only (verified against N+ staging samples); "prefixed" = email:timestamp (PDF).
			'signature_format'    => 'timestamp',
			// Auto Login string to sign: "prefixed" = userid:timestamp (PDF + N+ sample code comment) or "timestamp".
			'autologin_signature' => 'prefixed',
			'api_version'        => 'v1',
			'roleid'             => 5,
			'source'             => 'website',
			'payment_status'     => 'completed',
			'sendmail'           => 1,
			'date_format'        => 'Y-m-d H:i:s',
			'trigger_statuses'   => array( 'processing', 'completed' ),
			'allow_guest_launch' => 1,
			'show_in_emails'     => 1,
			'button_text'        => '', // Empty = translated default, see Settings::button_text().
			'request_timeout'    => 20,
			'max_attempts'       => 5,
			'debug'              => 0,
		);
	}

	/**
	 * Get one setting (constants win over stored values).
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		if ( isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] ) ) {
			return constant( self::CONSTANTS[ $key ] );
		}
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * All stored settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Launch button text.
	 *
	 * @return string
	 */
	public static function button_text() {
		$text = (string) self::get( 'button_text' );
		return '' !== $text ? $text : __( 'Access my N+ learning', 'nplus-sso' );
	}

	/**
	 * API base URL without trailing slash.
	 *
	 * @return string
	 */
	public static function base_url() {
		return untrailingslashit( (string) self::get( 'api_base_url' ) );
	}

	/**
	 * Secret used for the Auto Login signature (falls back to the main secret).
	 *
	 * @return string
	 */
	public static function autologin_secret() {
		$secret = (string) self::get( 'autologin_secret' );
		return '' !== $secret ? $secret : (string) self::get( 'secret' );
	}

	/**
	 * Whether a setting is locked by a wp-config.php constant.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_constant( $key ) {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	/**
	 * Names of required credentials that are still missing.
	 *
	 * @return string[]
	 */
	public static function missing_credentials() {
		$missing = array();
		foreach ( array( 'api_base_url', 'api_key', 'wstoken', 'secret' ) as $key ) {
			if ( '' === trim( (string) self::get( $key ) ) ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/**
	 * Hooks for the admin screen.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice_missing_credentials' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NPLUS_SSO_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'nplus-sso' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings page URL.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=nplus-sso' );
	}

	/**
	 * Register the submenu under WooCommerce (or Settings if WooCommerce is missing).
	 */
	public function add_menu() {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
		add_submenu_page( $parent, __( 'N+ SSO', 'nplus-sso' ), __( 'N+ SSO', 'nplus-sso' ), 'manage_woocommerce', 'nplus-sso', array( $this, 'render_page' ) );
	}

	/**
	 * Register the option with a sanitize callback.
	 */
	public function register_setting() {
		register_setting(
			'nplus_sso',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = self::all();
		$out     = $current;

		$out['api_base_url'] = isset( $input['api_base_url'] ) ? esc_url_raw( trim( $input['api_base_url'] ) ) : $current['api_base_url'];

		// Secrets: keep the stored value when the field is submitted empty; allow explicit clearing.
		foreach ( self::SECRET_FIELDS as $field ) {
			if ( ! empty( $input[ $field . '_clear' ] ) ) {
				$out[ $field ] = '';
			} elseif ( isset( $input[ $field ] ) && '' !== trim( $input[ $field ] ) ) {
				// Not sanitize_text_field(): it would mangle secrets containing "%xx" or "<".
				$out[ $field ] = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $input[ $field ] ) );
			}
		}

		foreach ( array( 'fn_create_user', 'fn_create_order', 'fn_autologin' ) as $fn ) {
			if ( isset( $input[ $fn ] ) && '' !== trim( $input[ $fn ] ) ) {
				$out[ $fn ] = preg_replace( '/[^a-z0-9_]/', '', strtolower( $input[ $fn ] ) );
			}
		}
		$choices = self::choices();
		foreach ( $choices as $key => $options ) {
			if ( isset( $input[ $key ] ) && isset( $options[ $input[ $key ] ] ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}

		$out['api_version']     = isset( $input['api_version'] ) ? sanitize_text_field( $input['api_version'] ) : $current['api_version'];
		$out['roleid']          = isset( $input['roleid'] ) ? absint( $input['roleid'] ) : $current['roleid'];
		$out['source']          = isset( $input['source'] ) ? sanitize_text_field( $input['source'] ) : $current['source'];
		$out['payment_status']  = isset( $input['payment_status'] ) ? sanitize_text_field( $input['payment_status'] ) : $current['payment_status'];
		$out['date_format']     = isset( $input['date_format'] ) && '' !== trim( $input['date_format'] ) ? sanitize_text_field( $input['date_format'] ) : $current['date_format'];
		$out['button_text']     = isset( $input['button_text'] ) ? sanitize_text_field( $input['button_text'] ) : $current['button_text'];
		$out['request_timeout'] = isset( $input['request_timeout'] ) ? max( 5, min( 120, absint( $input['request_timeout'] ) ) ) : $current['request_timeout'];
		$out['max_attempts']    = isset( $input['max_attempts'] ) ? max( 1, min( 20, absint( $input['max_attempts'] ) ) ) : $current['max_attempts'];

		foreach ( array( 'sendmail', 'allow_guest_launch', 'show_in_emails', 'debug' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$allowed                 = array( 'processing', 'completed' );
		$statuses                = isset( $input['trigger_statuses'] ) ? array_map( 'sanitize_key', (array) $input['trigger_statuses'] ) : array();
		$out['trigger_statuses'] = array_values( array_intersect( $allowed, $statuses ) );
		if ( empty( $out['trigger_statuses'] ) ) {
			$out['trigger_statuses'] = array( 'completed' );
		}

		return $out;
	}

	/**
	 * Allowed values of the select settings.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function choices() {
		return array(
			'autologin_mode'      => array(
				'ws'       => __( 'Web service (POST, N+ returns a login link) - N+ staging', 'nplus-sso' ),
				'redirect' => __( 'Redirect (GET /auto-login/ with signature) - PDF documentation', 'nplus-sso' ),
			),
			'signature_format'    => array(
				'timestamp' => __( 'Timestamp only - matches N+ staging', 'nplus-sso' ),
				'prefixed'  => __( 'email:timestamp - PDF documentation', 'nplus-sso' ),
			),
			'autologin_signature' => array(
				'prefixed'  => __( 'userid:timestamp - PDF documentation', 'nplus-sso' ),
				'timestamp' => __( 'Timestamp only', 'nplus-sso' ),
			),
		);
	}

	/**
	 * Warn admins when credentials are incomplete.
	 */
	public function maybe_notice_missing_credentials() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$missing = self::missing_credentials();
		if ( empty( $missing ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma separated list of missing settings */
					__( 'N+ SSO is not fully configured. Missing: %s.', 'nplus-sso' ),
					implode( ', ', $missing )
				)
			),
			esc_url( self::page_url() ),
			esc_html__( 'Configure now', 'nplus-sso' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s    = self::all();
		$name = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'N+ SSO Integration', 'nplus-sso' ); ?></h1>
			<p><?php esc_html_e( 'When a customer pays for a WooCommerce product that has an N+ Campaign ID, the plugin creates the learner on N+, assigns the subscription and gives the learner a one-click "Access N+" button (thank-you page, My Account, emails).', 'nplus-sso' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'nplus_sso' ); ?>
				<h2><?php esc_html_e( 'API credentials (issued by N+)', 'nplus-sso' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nplus_api_base_url"><?php esc_html_e( 'N+ base URL', 'nplus-sso' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="nplus_api_base_url" name="<?php echo esc_attr( $name ); ?>[api_base_url]" value="<?php echo esc_attr( self::get( 'api_base_url' ) ); ?>" <?php disabled( self::is_constant( 'api_base_url' ) ); ?> />
							<p class="description"><?php esc_html_e( 'Production: https://learn.nplus.global. Staging: https://stagelms.nplus.global. Must be HTTPS.', 'nplus-sso' ); ?></p>
						</td>
					</tr>
					<?php
					$secret_labels = array(
						'api_key'          => array( __( 'API key (x-api-key)', 'nplus-sso' ), '' ),
						'wstoken'          => array( __( 'Web service token (wstoken)', 'nplus-sso' ), '' ),
						'secret'           => array( __( 'HMAC secret', 'nplus-sso' ), __( 'The "SSO API Secret Key" from N+. Used to sign the N+ requests (see the signature settings below).', 'nplus-sso' ) ),
						'autologin_secret' => array( __( 'Auto Login HMAC secret (optional)', 'nplus-sso' ), __( 'Only if N+ issued a different secret for Auto Login. Leave empty to reuse the HMAC secret.', 'nplus-sso' ) ),
						'autologin_wstoken' => array( __( 'Auto Login web service token (optional)', 'nplus-sso' ), __( 'Only if N+ issued a separate wstoken for the Auto Login web service. Leave empty to reuse the web service token.', 'nplus-sso' ) ),
					);
					foreach ( $secret_labels as $field => $label ) :
						$locked = self::is_constant( $field );
						$is_set = '' !== (string) self::get( $field );
						?>
						<tr>
							<th scope="row"><label for="nplus_<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label[0] ); ?></label></th>
							<td>
								<?php if ( $locked ) : ?>
									<code><?php echo esc_html( self::CONSTANTS[ $field ] ); ?></code> <?php esc_html_e( 'is defined in wp-config.php.', 'nplus-sso' ); ?>
								<?php else : ?>
									<input type="password" autocomplete="new-password" class="regular-text" id="nplus_<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $field ); ?>]" value="" placeholder="<?php echo $is_set ? esc_attr__( '•••••••• (saved, leave empty to keep)', 'nplus-sso' ) : ''; ?>" />
									<?php if ( $is_set ) : ?>
										<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $field ); ?>_clear]" value="1" /> <?php esc_html_e( 'Clear', 'nplus-sso' ); ?></label>
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( $label[1] ) : ?>
									<p class="description"><?php echo esc_html( $label[1] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'N+ platform', 'nplus-sso' ); ?></h2>
				<p class="description"><?php esc_html_e( 'N+ staging (stagelms.nplus.global) uses the function names local_lms_apis_clone_create_user_site and local_lms_apis_clone_create_order. Confirm the production names with N+.', 'nplus-sso' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$fns = array(
						'fn_create_user'  => __( 'Create User wsfunction', 'nplus-sso' ),
						'fn_create_order' => __( 'Assign Subscription wsfunction', 'nplus-sso' ),
						'fn_autologin'    => __( 'Auto Login wsfunction', 'nplus-sso' ),
					);
					foreach ( $fns as $field => $label ) :
						?>
						<tr>
							<th scope="row"><label for="nplus_<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="text" class="regular-text code" id="nplus_<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( self::get( $field ) ); ?>" <?php disabled( self::is_constant( $field ) ); ?> /></td>
						</tr>
					<?php endforeach; ?>
					<?php
					$selects = array(
						'autologin_mode'      => __( 'Auto Login method', 'nplus-sso' ),
						'signature_format'    => __( 'Create User / Assign Subscription signature', 'nplus-sso' ),
						'autologin_signature' => __( 'Auto Login signature', 'nplus-sso' ),
					);
					foreach ( $selects as $field => $label ) :
						?>
						<tr>
							<th scope="row"><label for="nplus_<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<select id="nplus_<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $field ); ?>]" <?php disabled( self::is_constant( $field ) ); ?>>
									<?php foreach ( self::choices()[ $field ] as $value => $text ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( self::get( $field ), $value ); ?>><?php echo esc_html( $text ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Provisioning', 'nplus-sso' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Provision when order is', 'nplus-sso' ); ?></th>
						<td>
							<?php foreach ( array( 'processing' => __( 'Processing (paid)', 'nplus-sso' ), 'completed' => __( 'Completed', 'nplus-sso' ) ) as $status => $label ) : ?>
								<label style="margin-right:1em"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[trigger_statuses][]" value="<?php echo esc_attr( $status ); ?>" <?php checked( in_array( $status, (array) $s['trigger_statuses'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<?php
					$text_fields = array(
						'api_version'    => array( __( 'API version', 'nplus-sso' ), 'text', __( 'Sent as "version" to Create User.', 'nplus-sso' ) ),
						'roleid'         => array( __( 'N+ role ID', 'nplus-sso' ), 'number', __( 'Moodle role for new learners (5 = student).', 'nplus-sso' ) ),
						'source'         => array( __( 'Enrollment source', 'nplus-sso' ), 'text', __( 'Sent as "source" to the Subscription Assignment API.', 'nplus-sso' ) ),
						'payment_status' => array( __( 'Payment status value', 'nplus-sso' ), 'text', __( 'Sent as "payment_status" for paid orders. Confirm the expected value with N+.', 'nplus-sso' ) ),
						'date_format'    => array( __( 'Subscription start date format', 'nplus-sso' ), 'text', __( 'PHP date() format for "subscription_startdate".', 'nplus-sso' ) ),
						'button_text'    => array( __( 'Button text', 'nplus-sso' ), 'text', '' ),
						'request_timeout' => array( __( 'Request timeout (seconds)', 'nplus-sso' ), 'number', '' ),
						'max_attempts'   => array( __( 'Max automatic attempts', 'nplus-sso' ), 'number', __( 'Failed provisioning is retried automatically with back-off.', 'nplus-sso' ) ),
					);
					foreach ( $text_fields as $field => $f ) :
						?>
						<tr>
							<th scope="row"><label for="nplus_<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $f[0] ); ?></label></th>
							<td>
								<input type="<?php echo esc_attr( $f[1] ); ?>" class="regular-text" id="nplus_<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( 'button_text' === $field ? self::button_text() : $s[ $field ] ); ?>" />
								<?php if ( $f[2] ) : ?>
									<p class="description"><?php echo esc_html( $f[2] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php
					$flags = array(
						'sendmail'           => __( 'Ask N+ to send its welcome email (sendmail=1)', 'nplus-sso' ),
						'show_in_emails'     => __( 'Add the N+ access link to WooCommerce order emails', 'nplus-sso' ),
						'allow_guest_launch' => __( 'Allow guest-checkout buyers to launch N+ from their order link (order key protected)', 'nplus-sso' ),
						'debug'              => __( 'Debug logging (WooCommerce > Status > Logs, source "nplus-sso"). Secrets are always redacted.', 'nplus-sso' ),
					);
					foreach ( $flags as $flag => $label ) :
						?>
						<tr>
							<th scope="row"></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $flag ); ?>]" value="1" <?php checked( ! empty( $s[ $flag ] ) ); ?> /> <?php echo esc_html( $label ); ?></label></td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php
			/**
			 * Fires after the settings form (used by the connection test).
			 */
			do_action( 'nplus_sso_settings_after_form' );
			?>

			<h2><?php esc_html_e( 'How to sell N+ access', 'nplus-sso' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Edit the WooCommerce product (it can also be your Edwiser Bridge / Moodle course product).', 'nplus-sso' ); ?></li>
				<li><?php esc_html_e( 'In Product data > N+ Learning, tick "Grant N+ access" and enter the N+ Campaign ID and Subscription SKU supplied by N+.', 'nplus-sso' ); ?></li>
				<li><?php esc_html_e( 'Use the [nplus_sso_button] shortcode anywhere to show the launch button to entitled learners.', 'nplus-sso' ); ?></li>
			</ol>
		</div>
		<?php
	}
}
