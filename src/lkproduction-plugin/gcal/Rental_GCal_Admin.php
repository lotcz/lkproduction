<?php
/**
 * Rental GCal Sync – Admin Settings Page
 *
 * Renders a WooCommerce-style settings tab under
 * WooCommerce → Settings → Rental GCal Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rental_GCal_Admin {

	const OPTION_CALENDAR_ID   = 'rental_gcal_calendar_id';
	const OPTION_CREDENTIALS   = 'rental_gcal_service_account_json';
	const OPTION_ENABLED       = 'rental_gcal_enabled';
	const NONCE_ACTION         = 'rental_gcal_save_settings';

	public function __construct() {
		// Add tab to WooCommerce → Settings
		add_filter( 'woocommerce_settings_tabs_array', [ $this, 'add_settings_tab' ], 99 );
		add_action( 'woocommerce_settings_tabs_rental_gcal', [ $this, 'render_settings_page' ] );

		// Process form POST before any output is sent
		add_action( 'admin_init', [ $this, 'maybe_save_settings' ] );

		// Admin notices
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );

		// AJAX: test connection
		add_action( 'wp_ajax_rental_gcal_test_connection', [ $this, 'ajax_test_connection' ] );

		// Enqueue admin JS/CSS only on our tab
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// -------------------------------------------------------------------------
	// WooCommerce settings tab
	// -------------------------------------------------------------------------

	public function add_settings_tab( $tabs ) {
		$tabs['rental_gcal'] = __( 'Rental GCal Sync', 'rental-gcal' );
		return $tabs;
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public function render_settings_page() {
		$enabled     = get_option( self::OPTION_ENABLED, 'no' );
		$calendar_id = get_option( self::OPTION_CALENDAR_ID, '' );
		$has_creds   = $this->has_valid_credentials();
		$creds_label = $has_creds
			? __( '✔ Service account credentials are saved.', 'rental-gcal' )
			: __( 'No credentials saved yet.', 'rental-gcal' );

		?>
		<div class="rental-gcal-wrap">
			<h2><?php esc_html_e( 'Google Calendar Sync — Service Account', 'rental-gcal' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Automatically creates, updates, and deletes Google Calendar events when rental reservations change status.', 'rental-gcal' ); ?>
			</p>

			<?php $this->render_setup_guide(); ?>

			<form method="post" id="rental-gcal-settings-form" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION, 'rental_gcal_nonce' ); ?>
				<input type="hidden" name="action" value="rental_gcal_save" />

				<table class="form-table" role="presentation">

					<!-- Enable / disable sync -->
					<tr>
						<th scope="row">
							<label for="rental_gcal_enabled">
								<?php esc_html_e( 'Enable Sync', 'rental-gcal' ); ?>
							</label>
						</th>
						<td>
							<label class="rental-gcal-toggle">
								<input
									type="checkbox"
									id="rental_gcal_enabled"
									name="rental_gcal_enabled"
									value="yes"
									<?php checked( $enabled, 'yes' ); ?>
								/>
								<span class="rental-gcal-toggle__slider"></span>
							</label>
							<p class="description">
								<?php esc_html_e( 'When disabled, no events are sent to Google Calendar.', 'rental-gcal' ); ?>
							</p>
						</td>
					</tr>

					<!-- Calendar ID -->
					<tr>
						<th scope="row">
							<label for="rental_gcal_calendar_id">
								<?php esc_html_e( 'Calendar ID', 'rental-gcal' ); ?>
								<span class="rental-gcal-required">*</span>
							</label>
						</th>
						<td>
							<input
								type="text"
								id="rental_gcal_calendar_id"
								name="rental_gcal_calendar_id"
								value="<?php echo esc_attr( $calendar_id ); ?>"
								class="regular-text"
								placeholder="yourname@group.calendar.google.com"
							/>
							<p class="description">
								<?php esc_html_e( 'Find this in Google Calendar → Settings → your calendar → "Calendar ID". Use "primary" for the account\'s main calendar.', 'rental-gcal' ); ?>
							</p>
						</td>
					</tr>

					<!-- Service account JSON – paste -->
					<tr>
						<th scope="row">
							<label for="rental_gcal_json_paste">
								<?php esc_html_e( 'Service Account JSON', 'rental-gcal' ); ?>
								<span class="rental-gcal-required">*</span>
							</label>
						</th>
						<td>
							<div class="rental-gcal-credentials-status <?php echo $has_creds ? 'has-creds' : 'no-creds'; ?>">
								<span class="dashicons <?php echo $has_creds ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
								<?php echo esc_html( $creds_label ); ?>
							</div>

							<textarea
								id="rental_gcal_json_paste"
								name="rental_gcal_json_paste"
								rows="10"
								class="large-text code rental-gcal-json-textarea"
								placeholder='{"type":"service_account","project_id":"...","private_key_id":"...","private_key":"-----BEGIN RSA PRIVATE KEY-----\n...","client_email":"name@project.iam.gserviceaccount.com",...}'
								spellcheck="false"
							></textarea>

							<p class="description">
								<?php esc_html_e( 'Paste the full contents of the JSON key file you downloaded from Google Cloud Console. The key is stored encrypted in the database. Leave blank to keep the existing credentials.', 'rental-gcal' ); ?>
							</p>

							<?php if ( $has_creds ) : ?>
								<label class="rental-gcal-danger-label">
									<input type="checkbox" name="rental_gcal_clear_credentials" value="1" />
									<?php esc_html_e( 'Remove saved credentials', 'rental-gcal' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>

					<!-- Service account e-mail (read-only, derived) -->
					<?php if ( $has_creds ) :
						$client_email = $this->get_client_email();
						?>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Service Account E-mail', 'rental-gcal' ); ?>
							</th>
							<td>
								<code><?php echo esc_html( $client_email ); ?></code>
								<p class="description">
									<?php
									printf(
									/* translators: %s = service account email */
										esc_html__( 'Share your Google Calendar with this address and grant it "Make changes to events" permission.', 'rental-gcal' ),
										esc_html( $client_email )
									);
									?>
								</p>
							</td>
						</tr>
					<?php endif; ?>

					<!-- Test connection -->
					<?php if ( $has_creds && $calendar_id ) : ?>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Connection Test', 'rental-gcal' ); ?>
							</th>
							<td>
								<button
									type="button"
									id="rental-gcal-test-btn"
									class="button button-secondary"
								>
									<?php esc_html_e( 'Test connection', 'rental-gcal' ); ?>
								</button>
								<span id="rental-gcal-test-result" class="rental-gcal-test-result"></span>
								<p class="description">
									<?php esc_html_e( 'Verifies that the service account can read the specified calendar.', 'rental-gcal' ); ?>
								</p>
							</td>
						</tr>
					<?php endif; ?>

				</table>

				<?php submit_button( __( 'Save Settings', 'rental-gcal' ) ); ?>
			</form>
		</div>

		<?php $this->render_inline_styles(); ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Setup guide accordion
	// -------------------------------------------------------------------------

	private function render_setup_guide() {
		?>
		<details class="rental-gcal-guide">
			<summary><?php esc_html_e( '📋 Setup guide – click to expand', 'rental-gcal' ); ?></summary>
			<ol>
				<li><?php esc_html_e( 'Go to Google Cloud Console → Create or select a project.', 'rental-gcal' ); ?></li>
				<li><?php esc_html_e( 'Enable the Google Calendar API for the project.', 'rental-gcal' ); ?></li>
				<li><?php esc_html_e( 'Go to IAM & Admin → Service Accounts → Create Service Account.', 'rental-gcal' ); ?></li>
				<li><?php esc_html_e( 'Open the service account → Keys tab → Add Key → JSON. Download the file.', 'rental-gcal' ); ?></li>
				<li><?php esc_html_e( 'Open Google Calendar, go to Settings for the target calendar, and share it with the service account e-mail (ending in .iam.gserviceaccount.com). Grant "Make changes to events".', 'rental-gcal' ); ?></li>
				<li><?php esc_html_e( 'Paste the JSON contents into the field below and save.', 'rental-gcal' ); ?></li>
			</ol>
		</details>
		<?php
	}

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	public function maybe_save_settings() {
		// Only act when our form was submitted
		if (
			! isset( $_POST['action'] ) ||
			$_POST['action'] !== 'rental_gcal_save' ||
			! isset( $_POST['rental_gcal_nonce'] )
		) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rental_gcal_nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'rental-gcal' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'rental-gcal' ) );
		}

		// Enabled toggle
		$enabled = isset( $_POST['rental_gcal_enabled'] ) && $_POST['rental_gcal_enabled'] === 'yes' ? 'yes' : 'no';
		update_option( self::OPTION_ENABLED, $enabled );

		// Calendar ID
		$calendar_id = isset( $_POST['rental_gcal_calendar_id'] )
			? sanitize_text_field( wp_unslash( $_POST['rental_gcal_calendar_id'] ) )
			: '';
		update_option( self::OPTION_CALENDAR_ID, $calendar_id );

		// Clear credentials if requested
		if ( ! empty( $_POST['rental_gcal_clear_credentials'] ) ) {
			delete_option( self::OPTION_CREDENTIALS );
			set_transient( 'rental_gcal_admin_notice', 'credentials_cleared', 30 );
		}

		// Save new credentials JSON
		$raw_json = isset( $_POST['rental_gcal_json_paste'] )
			? trim( wp_unslash( $_POST['rental_gcal_json_paste'] ) )
			: '';

		if ( ! empty( $raw_json ) ) {
			$validation_error = $this->validate_service_account_json( $raw_json );

			if ( $validation_error ) {
				set_transient( 'rental_gcal_admin_notice', 'json_error:' . $validation_error, 30 );
				wp_safe_redirect( $this->settings_url() );
				exit;
			}

			// Encrypt before storing (uses WP secret keys as cipher key)
			$encrypted = $this->encrypt( $raw_json );
			update_option( self::OPTION_CREDENTIALS, $encrypted );
			set_transient( 'rental_gcal_admin_notice', 'saved', 30 );
		} elseif ( empty( $_POST['rental_gcal_clear_credentials'] ) ) {
			// Nothing changed for credentials – just mark general save
			set_transient( 'rental_gcal_admin_notice', 'saved', 30 );
		}

		// Post-Redirect-Get: prevents "resubmit form?" on refresh
		wp_safe_redirect( $this->settings_url() );
		exit;
	}

	private function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=rental_gcal' );
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	public function admin_notices() {
		$notice = get_transient( 'rental_gcal_admin_notice' );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'rental_gcal_admin_notice' );

		// Only show on our tab
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'woocommerce_page_wc-settings' ) === false ) {
			return;
		}

		if ( $notice === 'saved' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Rental GCal Sync settings saved.', 'rental-gcal' ) .
				'</p></div>';
		} elseif ( $notice === 'credentials_cleared' ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				esc_html__( 'Service account credentials have been removed.', 'rental-gcal' ) .
				'</p></div>';
		} elseif ( strpos( $notice, 'json_error:' ) === 0 ) {
			$error = substr( $notice, strlen( 'json_error:' ) );
			echo '<div class="notice notice-error is-dismissible"><p>' .
				esc_html__( 'Invalid service account JSON: ', 'rental-gcal' ) .
				esc_html( $error ) .
				'</p></div>';
		} elseif ( $notice === 'test_ok' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Connection successful! The service account can access the calendar.', 'rental-gcal' ) .
				'</p></div>';
		} elseif ( strpos( $notice, 'test_fail:' ) === 0 ) {
			$error = substr( $notice, strlen( 'test_fail:' ) );
			echo '<div class="notice notice-error is-dismissible"><p>' .
				esc_html__( 'Connection test failed: ', 'rental-gcal' ) .
				esc_html( $error ) .
				'</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// AJAX – test connection
	// -------------------------------------------------------------------------

	public function ajax_test_connection() {
		check_ajax_referer( 'rental_gcal_test_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'rental-gcal' ) ] );
		}

		if ( ! $this->has_valid_credentials() ) {
			wp_send_json_error( [ 'message' => __( 'No credentials saved.', 'rental-gcal' ) ] );
		}

		try {
			// Delegate to sync class
			$sync   = new Rental_GCal_Sync();
			$result = $sync->test_connection();

			if ( $result === true ) {
				wp_send_json_success( [ 'message' => __( 'Connection successful! ✔', 'rental-gcal' ) ] );
			} else {
				wp_send_json_error( [ 'message' => $result ] );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( $hook ) {
		// Only on WooCommerce settings page
		if ( $hook !== 'woocommerce_page_wc-settings' ) {
			return;
		}
		if ( ! isset( $_GET['tab'] ) || $_GET['tab'] !== 'rental_gcal' ) {
			return;
		}

		wp_enqueue_script(
			'rental-gcal-admin',
			plugin_dir_url(__FILE__) . '../static/gcal-admin.js',
			[ 'jquery' ],
			1,
			true
		);

		wp_localize_script( 'rental-gcal-admin', 'rentalGcal', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'testNonce' => wp_create_nonce( 'rental_gcal_test_nonce' ),
			'i18n'      => [
				'testing' => __( 'Testing…', 'rental-gcal' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public function has_valid_credentials(): bool {
		$stored = get_option( self::OPTION_CREDENTIALS, '' );
		if ( empty( $stored ) ) {
			return false;
		}
		$json = $this->decrypt( $stored );
		if ( ! $json ) {
			return false;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) && isset( $data['type'] ) && $data['type'] === 'service_account';
	}

	public function get_credentials_json(): string {
		$stored = get_option( self::OPTION_CREDENTIALS, '' );
		if ( empty( $stored ) ) {
			return '';
		}
		return $this->decrypt( $stored ) ?: '';
	}

	public function get_client_email(): string {
		$json = $this->get_credentials_json();
		if ( ! $json ) {
			return '';
		}
		$data = json_decode( $json, true );
		return $data['client_email'] ?? '';
	}

	private function validate_service_account_json( string $json ): string {
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return __( 'Could not parse JSON – check for syntax errors.', 'rental-gcal' );
		}

		$required = [ 'type', 'project_id', 'private_key_id', 'private_key', 'client_email' ];
		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) ) {
				/* translators: %s = missing JSON field name */
				return sprintf( __( 'Missing required field: %s', 'rental-gcal' ), $field );
			}
		}

		if ( $data['type'] !== 'service_account' ) {
			return __( 'JSON "type" must be "service_account".', 'rental-gcal' );
		}

		return ''; // no error
	}

	/**
	 * Simple reversible encryption using WP secret keys.
	 * For stronger security, consider using libsodium (available since PHP 7.2 / WP 5.2).
	 */
	private function encrypt( string $plaintext ): string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$key        = $this->derive_key();
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return base64_encode( $nonce . $ciphertext );
		}
		// Fallback: base64 only (no real encryption — warn in UI)
		return base64_encode( $plaintext );
	}

	private function decrypt( string $encoded ): string|false {
		if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$decoded    = base64_decode( $encoded, true );
			if ( $decoded === false ) {
				return false;
			}
			$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$key        = $this->derive_key();
			$result     = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
			return $result;
		}
		// Fallback
		$decoded = base64_decode( $encoded, true );
		return $decoded !== false ? $decoded : false;
	}

	private function derive_key(): string {
		// Stretch WP secret key to 32 bytes required by libsodium
		$secret = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : wp_salt( 'secure_auth' );
		return substr( hash( 'sha256', $secret, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	// -------------------------------------------------------------------------
	// Inline styles (scoped to our settings page)
	// -------------------------------------------------------------------------

	private function render_inline_styles() {
		?>
		<style>
			.rental-gcal-wrap { max-width: 800px; }
			.rental-gcal-required { color: #d63638; margin-left: 2px; }

			/* Setup guide */
			.rental-gcal-guide {
				background: #f0f6fc;
				border: 1px solid #c3d9ef;
				border-radius: 4px;
				padding: 12px 16px;
				margin-bottom: 20px;
			}
			.rental-gcal-guide summary {
				cursor: pointer;
				font-weight: 600;
				color: #135e96;
			}
			.rental-gcal-guide ol {
				margin: 10px 0 0 20px;
			}
			.rental-gcal-guide li { margin-bottom: 6px; }

			/* JSON textarea */
			.rental-gcal-json-textarea {
				font-size: 12px;
				line-height: 1.5;
				border-radius: 4px;
				margin-top: 6px;
			}

			/* Credentials status badge */
			.rental-gcal-credentials-status {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				padding: 4px 10px;
				border-radius: 12px;
				font-size: 13px;
				margin-bottom: 8px;
				font-weight: 500;
			}
			.rental-gcal-credentials-status.has-creds {
				background: #edfaef;
				color: #1a7430;
				border: 1px solid #86e49d;
			}
			.rental-gcal-credentials-status.no-creds {
				background: #fcf0d4;
				color: #7a4b00;
				border: 1px solid #f5c843;
			}

			/* Toggle switch */
			.rental-gcal-toggle {
				position: relative;
				display: inline-block;
				width: 44px;
				height: 24px;
			}
			.rental-gcal-toggle input { opacity: 0; width: 0; height: 0; }
			.rental-gcal-toggle__slider {
				position: absolute; inset: 0;
				background-color: #ccc;
				border-radius: 24px;
				cursor: pointer;
				transition: background-color 0.2s;
			}
			.rental-gcal-toggle__slider::before {
				content: "";
				position: absolute;
				height: 18px; width: 18px;
				left: 3px; bottom: 3px;
				background-color: white;
				border-radius: 50%;
				transition: transform 0.2s;
			}
			.rental-gcal-toggle input:checked + .rental-gcal-toggle__slider { background-color: #2271b1; }
			.rental-gcal-toggle input:checked + .rental-gcal-toggle__slider::before { transform: translateX(20px); }

			/* Test result */
			.rental-gcal-test-result {
				display: inline-block;
				margin-left: 10px;
				font-style: italic;
				vertical-align: middle;
			}
			.rental-gcal-test-result.success { color: #1a7430; }
			.rental-gcal-test-result.error   { color: #d63638; }

			/* Danger label */
			.rental-gcal-danger-label {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				margin-top: 8px;
				color: #d63638;
				font-size: 13px;
				cursor: pointer;
			}
		</style>
		<?php
	}
}
