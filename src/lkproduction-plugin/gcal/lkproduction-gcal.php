<?php

add_action(
	'plugins_loaded',
	function () {
		if (!class_exists('WooCommerce')) return;

		if (is_admin()) {
			require_once plugin_dir_path(__FILE__) . '/Rental_GCal_Admin.php';
			new Rental_GCal_Admin();
		}

		require_once plugin_dir_path(__FILE__) . '../vendor/autoload.php';
		require_once plugin_dir_path(__FILE__) . '/Rental_GCal_Sync.php';

		add_action(
			'woocommerce_order_status_changed',
			['Rental_GCal_Sync', 'handle_status_change'],
			10,
			3
		);
	}
);
