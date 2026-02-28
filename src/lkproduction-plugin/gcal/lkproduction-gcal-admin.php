<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/lkproduction-gcal-common.php';

function lk_gcal_settings_url(): string {
	return admin_url('admin.php?page=wc-settings&tab=rental_gcal');
}

function lk_gcal_add_settings_tab($tabs) {
	$tabs['rental_gcal'] = __('LK Rent Sync', 'rental-gcal');
	return $tabs;
}

function lk_gcal_render_settings_page() {
	$enabled = lk_gcal_is_enabled();
	$calendar_id = lk_gcal_get_calendar_id();
	$has_creds = lk_gcal_has_valid_credentials();
	$creds_label = $has_creds
		? __('✔ Service account credentials are saved.', 'rental-gcal')
		: __('No credentials saved yet.', 'rental-gcal');
	?>
	<div class="rental-gcal-wrap">
		<h2><?php esc_html_e('Google Calendar Sync — Service Account', 'rental-gcal'); ?></h2>
		<p class="description">
			<?php esc_html_e('Automatically creates, updates, and deletes Google Calendar events when rental reservations change status.', 'rental-gcal'); ?>
		</p>

		<?php lk_gcal_render_setup_guide(); ?>

		<form method="post" id="rental-gcal-settings-form" enctype="multipart/form-data">
			<?php wp_nonce_field(LK_GCAL_NONCE_ACTION, 'rental_gcal_nonce'); ?>
			<input type="hidden" name="action" value="rental_gcal_save"/>

			<table class="form-table" role="presentation">

				<!-- Enable / disable sync -->
				<tr>
					<th scope="row">
						<label for="rental_gcal_enabled">
							<?php esc_html_e('Enable Sync', 'rental-gcal'); ?>
						</label>
					</th>
					<td>
						<label class="rental-gcal-toggle">
							<input
								type="checkbox"
								id="rental_gcal_enabled"
								name="rental_gcal_enabled"
								value="yes"
								<?php checked($enabled); ?>
							/>
							<span class="rental-gcal-toggle__slider"></span>
						</label>
						<p class="description">
							<?php esc_html_e('When disabled, no events are sent to Google Calendar.', 'rental-gcal'); ?>
						</p>
					</td>
				</tr>

				<!-- Calendar ID -->
				<tr>
					<th scope="row">
						<label for="rental_gcal_calendar_id">
							<?php esc_html_e('Calendar ID', 'rental-gcal'); ?>
							<span class="rental-gcal-required">*</span>
						</label>
					</th>
					<td>
						<input
							type="text"
							id="rental_gcal_calendar_id"
							name="rental_gcal_calendar_id"
							value="<?php echo esc_attr($calendar_id); ?>"
							class="regular-text"
							placeholder="yourname@group.calendar.google.com"
						/>
						<p class="description">
							<?php esc_html_e('Find this in Google Calendar → Settings → your calendar → "Calendar ID". Use "primary" for the account\'s main calendar.', 'rental-gcal'); ?>
						</p>
					</td>
				</tr>

				<!-- Service account JSON – paste -->
				<tr>
					<th scope="row">
						<label for="rental_gcal_json_paste">
							<?php esc_html_e('Service Account JSON', 'rental-gcal'); ?>
							<span class="rental-gcal-required">*</span>
						</label>
					</th>
					<td>
						<div class="rental-gcal-credentials-status <?php echo $has_creds ? 'has-creds' : 'no-creds'; ?>">
							<span class="dashicons <?php echo $has_creds ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
							<?php echo esc_html($creds_label); ?>
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
							<?php esc_html_e('Paste the full contents of the JSON key file you downloaded from Google Cloud Console. The key is stored encrypted in the database. Leave blank to keep the existing credentials.', 'rental-gcal'); ?>
						</p>

						<?php if ($has_creds) : ?>
							<label class="rental-gcal-danger-label">
								<input type="checkbox" name="rental_gcal_clear_credentials" value="1"/>
								<?php esc_html_e('Remove saved credentials', 'rental-gcal'); ?>
							</label>
						<?php endif; ?>
					</td>
				</tr>

				<!-- Service account e-mail (read-only, derived) -->
				<?php if ($has_creds) :
					$client_email = lk_gcal_get_client_email();
					?>
					<tr>
						<th scope="row">
							<?php esc_html_e('Service Account E-mail', 'rental-gcal'); ?>
						</th>
						<td>
							<code><?php echo esc_html($client_email); ?></code>
							<p class="description">
								<?php
								printf(
								/* translators: %s = service account email */
									esc_html__('Share your Google Calendar with this address and grant it "Make changes to events" permission.', 'rental-gcal'),
									esc_html($client_email)
								);
								?>
							</p>
						</td>
					</tr>
				<?php endif; ?>

				<!-- Test connection -->
				<?php if ($has_creds && $calendar_id) : ?>
					<tr>
						<th scope="row">
							<?php esc_html_e('Connection Test', 'rental-gcal'); ?>
						</th>
						<td>
							<button
								type="button"
								id="rental-gcal-test-btn"
								class="button button-secondary"
							>
								<?php esc_html_e('Test connection', 'rental-gcal'); ?>
							</button>
							<span id="rental-gcal-test-result" class="rental-gcal-test-result"></span>
							<p class="description">
								<?php esc_html_e('Verifies that the service account can read the specified calendar.', 'rental-gcal'); ?>
							</p>
						</td>
					</tr>
				<?php endif; ?>

			</table>

			<?php submit_button(__('Save Settings', 'rental-gcal')); ?>
		</form>
	</div>
	<?php
}

function lk_gcal_render_setup_guide() {
	?>
	<details class="rental-gcal-guide">
		<summary><?php esc_html_e('📋 Setup guide – click to expand', 'rental-gcal'); ?></summary>
		<ol>
			<li><?php esc_html_e('Go to Google Cloud Console → Create or select a project.', 'rental-gcal'); ?></li>
			<li><?php esc_html_e('Enable the Google Calendar API for the project.', 'rental-gcal'); ?></li>
			<li><?php esc_html_e('Go to IAM & Admin → Service Accounts → Create Service Account.', 'rental-gcal'); ?></li>
			<li><?php esc_html_e('Open the service account → Keys tab → Add Key → JSON. Download the file.', 'rental-gcal'); ?></li>
			<li><?php esc_html_e('Open Google Calendar, go to Settings for the target calendar, and share it with the service account e-mail (ending in .iam.gserviceaccount.com). Grant "Make changes to events".', 'rental-gcal'); ?></li>
			<li><?php esc_html_e('Paste the JSON contents into the field below and save.', 'rental-gcal'); ?></li>
		</ol>
	</details>
	<?php
}

function lk_gcal_maybe_save_settings() {
	// Only act when our form was submitted
	if (
		!isset($_POST['action']) ||
		$_POST['action'] !== 'rental_gcal_save' ||
		!isset($_POST['rental_gcal_nonce'])
	) {
		return;
	}

	if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rental_gcal_nonce'])), LK_GCAL_NONCE_ACTION)) {
		wp_die(esc_html__('Security check failed.', 'rental-gcal'));
	}

	if (!lk_user_can_manage()) {
		wp_die(esc_html__('Permission denied.', 'rental-gcal'));
	}

	// Enabled toggle
	$enabled = isset($_POST['rental_gcal_enabled']) && ($_POST['rental_gcal_enabled'] === 'yes');
	lk_gcal_set_enabled($enabled);

	// Calendar ID
	$calendar_id = isset($_POST['rental_gcal_calendar_id'])
		? sanitize_text_field(wp_unslash($_POST['rental_gcal_calendar_id']))
		: '';
	lk_gcal_set_calendar_id($calendar_id);

	// Clear credentials if requested
	if (!empty($_POST['rental_gcal_clear_credentials'])) {
		lk_gcal_delete_credentials();
		set_transient('rental_gcal_admin_notice', 'credentials_cleared', 30);
	}

	// Save new credentials JSON
	$raw_json = isset($_POST['rental_gcal_json_paste'])
		? trim(wp_unslash($_POST['rental_gcal_json_paste']))
		: '';

	if (!empty($raw_json)) {
		$validation = lk_gcal_validate_credentials_json($raw_json);

		if ($validation !== true) {
			set_transient('rental_gcal_admin_notice', 'json_error:' . $validation, 30);
			wp_safe_redirect(lk_gcal_settings_url());
			exit;
		}

		// Encrypt before storing (uses WP secret keys as cipher key)
		lk_gcal_set_credentials_json($raw_json);
		set_transient('rental_gcal_admin_notice', 'saved', 30);
	} elseif (empty($_POST['rental_gcal_clear_credentials'])) {
		// Nothing changed for credentials – just mark general save
		set_transient('rental_gcal_admin_notice', 'saved', 30);
	}

	// Post-Redirect-Get: prevents "resubmit form?" on refresh
	wp_safe_redirect(lk_gcal_settings_url());
	exit;
}

function lk_gcal_admin_notices() {
	$notice = get_transient('rental_gcal_admin_notice');
	if (!$notice) {
		return;
	}
	delete_transient('rental_gcal_admin_notice');

	// Only show on our tab
	$screen = get_current_screen();
	if (!$screen || strpos($screen->id, 'woocommerce_page_wc-settings') === false) {
		return;
	}

	if ($notice === 'saved') {
		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html__('Rental GCal Sync settings saved.', 'rental-gcal') .
			'</p></div>';
	} elseif ($notice === 'credentials_cleared') {
		echo '<div class="notice notice-warning is-dismissible"><p>' .
			esc_html__('Service account credentials have been removed.', 'rental-gcal') .
			'</p></div>';
	} elseif (strpos($notice, 'json_error:') === 0) {
		$error = substr($notice, strlen('json_error:'));
		echo '<div class="notice notice-error is-dismissible"><p>' .
			esc_html__('Invalid service account JSON: ', 'rental-gcal') .
			esc_html($error) .
			'</p></div>';
	} elseif ($notice === 'test_ok') {
		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html__('Connection successful! The service account can access the calendar.', 'rental-gcal') .
			'</p></div>';
	} elseif (strpos($notice, 'test_fail:') === 0) {
		$error = substr($notice, strlen('test_fail:'));
		echo '<div class="notice notice-error is-dismissible"><p>' .
			esc_html__('Connection test failed: ', 'rental-gcal') .
			esc_html($error) .
			'</p></div>';
	}
}

