<?php
/**
 * Plugin Name: LK Production Rent
 * Description: Rezervační systém pro LK Production
 * Version: 1.1.1
 * Author: Karel
 * Text Domain: lkproduction
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/includes/lkproduction-cart.php';
require_once __DIR__ . '/includes/lkproduction-product.php';
require_once __DIR__ . '/includes/lkproduction-order.php';
require_once __DIR__ . '/includes/lkproduction-calendar.php';
require_once __DIR__ . '/includes/lkproduction-edit-order.php';
require_once __DIR__ . '/gcal/lkproduction-gcal.php';

/* STRINGS REPLACEMENTS */
add_filter('gettext', 'rental_rename_subtotal_to_daily_fee', 20, 3);
function rental_rename_subtotal_to_daily_fee($translated_text, $text, $domain) {
	if ($domain === 'woocommerce') {
		switch ($text) {
			case 'Subtotal':
			case 'Estimated total':
			case 'Items Subtotal':
				return 'Denní sazba';
			case 'Proceed to checkout':
				return 'Rezervovat';
			case 'Add to Cart':
				return 'Přidat k rezervaci';
		}
	}
	if ($domain === 'sydney') {
		switch ($text) {
			case 'Proceed to checkout':
				return 'Rezervovat';
		}
	}
	return $translated_text;
}

/* CUSTOM STYLES AND SCRIPTS */
add_action('wp_enqueue_scripts', 'lkproduction_scripts');
function lkproduction_scripts() {
	wp_enqueue_style(
		'lkproduction-style',
		plugin_dir_url(__FILE__) . '/static/lkproduction.css',
		[],
		filemtime(plugin_dir_path(__FILE__) . '/static/lkproduction.css')
	);
}

/* ADMIN SCRIPTS AND STYLES */
add_action('admin_enqueue_scripts', 'lkproduction_admin_scripts');
function lkproduction_admin_scripts() {
	wp_enqueue_style(
		'lkproduction-admin-style',
		plugin_dir_url(__FILE__) . '/static/lkproduction-admin.css',
		[],
		filemtime(plugin_dir_path(__FILE__) . '/static/lkproduction-admin.css')
	);

	wp_enqueue_script(
		'lkproduction-custom-form-script',
		plugin_dir_url(__FILE__) . '/static/custom-order-form.js',
		[],
		filemtime(plugin_dir_path(__FILE__) . '/static/custom-order-form.js')
	);

	// 5. Localize the script to pass the AJAX URL and a Nonce to JS
	wp_localize_script('lkproduction-custom-form-script', 'lk_admin_ajax_obj', [
		'ajax_url' => admin_url('admin-ajax.php'),
		'nonce' => wp_create_nonce('lk_admin_ajax_nonce'),
	]);

}

// Add LK Rent menu item
add_action('admin_menu', 'rental_calendar_menu');
function rental_calendar_menu() {
	add_menu_page(
		'Kalendář akcí',
		'LK Rent',
		'manage_options',
		'lk-rental-calendar',
		'lk_rental_render_calendar_global',
		'dashicons-calendar-alt',
		1
	);
}

/* Add submenu items */
add_action('admin_menu', 'rental_calendar_submenu_links', 20);
function rental_calendar_submenu_links() {
	add_submenu_page(
		'lk-rental-calendar',
		'Kalendář akcí',
		'Kalendář akcí',
		'manage_options',
		'lk-rental-calendar',
		'lk_rental_render_calendar_global'
	);

	add_submenu_page(
		'lk-rental-calendar',
		'Vytvořit cenovou nabídku',
		'Vytvořit cenovou nabídku',
		'manage_options',
		'lk-rental-custom-order-form',
		'lk_rental_render_custom_order_form'
	);

	add_submenu_page(
		'lk-rental-calendar',
		'Objednávky',
		'Objednávky',
		'manage_options',
		'edit.php?post_type=shop_order&source=lk'
	);

	add_submenu_page(
		null,
		'Náhled',
		'Náhled',
		'manage_woocommerce',
		'lk-custom-print-preview',
		'lk_render_custom_order_print_preview'
	);
}
