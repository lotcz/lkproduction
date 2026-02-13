<?php
/**
 * Plugin Name: LK Production Rent
 * Description: Rezervační systém pro LK Production
 * Version: 1.0.0
 * Author: Karel
 * Text Domain: lkproduction
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 * Requires Plugins: woocommerce
 */

if (!defined( 'ABSPATH')) {
	exit;
}

require_once __DIR__ . '/includes/lkproduction-customer.php';
require_once __DIR__ . '/includes/lkproduction-admin.php';


add_filter('gettext', 'rental_rename_subtotal_to_daily_fee', 20, 3);
function rental_rename_subtotal_to_daily_fee($translated_text, $text, $domain) {
	if ($domain === 'woocommerce') {
		switch ($text) {
			case 'Subtotal':
			case 'Estimated total':
			case 'Items Subtotal':
				return 'Denní sazba';
			case 'Proceed to Checkout':
				return 'Rezervovat';
			case 'Add to Cart':
				return 'Přidat k rezervaci';
		}
	}
	return $translated_text;
}
