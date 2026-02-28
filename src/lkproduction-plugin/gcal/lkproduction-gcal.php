<?php

if (!defined('ABSPATH')) {
	exit;
}

add_action(
	'plugins_loaded',
	function () {
		if (!class_exists('WooCommerce')) return;

		if (is_admin()) {
			require_once __DIR__ . '/lkproduction-gcal-admin.php';

			add_filter('woocommerce_settings_tabs_array', 'lk_gcal_add_settings_tab', 99);
			add_action('woocommerce_settings_tabs_rental_gcal', 'lk_gcal_render_settings_page');

			// Process form POST before any output is sent
			add_action('admin_init', 'lk_gcal_maybe_save_settings');

			// Admin notices
			add_action('admin_notices', 'lk_gcal_admin_notices');

			// AJAX: test connection
			add_action(
				'wp_ajax_lk_gcal_test_connection',
				function () {
					check_ajax_referer('rental_gcal_test_nonce', 'nonce');

					if (!lk_user_can_manage()) {
						wp_send_json_error(['message' => __('Permission denied.', 'rental-gcal')]);
					}

					require_once __DIR__ . '/lkproduction-gcal-sync.php';

					if (!lk_gcal_has_valid_credentials()) {
						wp_send_json_error(['message' => __('No credentials saved.', 'rental-gcal')]);
					}

					try {
						$result = lk_gcal_test_connection();

						if ($result === true) {
							wp_send_json_success(['message' => __('Connection successful! ✔', 'rental-gcal')]);
						} else {
							wp_send_json_error(['message' => $result]);
						}
					} catch (Exception $e) {
						wp_send_json_error(['message' => $e->getMessage()]);
					}
				}
			);

			// Enqueue admin JS/CSS only on our tab
			add_action(
				'admin_enqueue_scripts',
				function ($hook) {
					// Only on WooCommerce settings page
					if ($hook !== 'woocommerce_page_wc-settings') {
						return;
					}
					if (!isset($_GET['tab']) || $_GET['tab'] !== 'rental_gcal') {
						return;
					}

					wp_enqueue_style(
						'rental-gcal-admin-css',
						plugin_dir_url(__FILE__) . '../static/gcal-admin.css',
						[],
						filemtime(plugin_dir_path(__FILE__) . '../static/gcal-admin.css')
					);

					wp_enqueue_script(
						'rental-gcal-admin',
						plugin_dir_url(__FILE__) . '../static/gcal-admin.js',
						['jquery'],
						filemtime(plugin_dir_path(__FILE__) . '../static/gcal-admin.js'),
						true
					);

					wp_localize_script(
						'rental-gcal-admin',
						'rentalGcal',
						[
							'ajaxUrl' => admin_url('admin-ajax.php'),
							'testNonce' => wp_create_nonce('rental_gcal_test_nonce')
						]
					);
				}
			);

		}

		add_action(
			'woocommerce_after_order_object_save',
			function (WC_Order $order) {
				require_once __DIR__ . '/lkproduction-gcal-sync.php';

				if (!lk_gcal_is_enabled()) return;

				try {
					lk_gcal_update_event($order);
				} catch (Exception $e) {
					error_log("Google calendar sync failed: " . $e->getMessage());
				}
			}
		);
	}
);
