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

require_once __DIR__ . '/includes/lkproduction-cart.php';
require_once __DIR__ . '/includes/lkproduction-product.php';
require_once __DIR__ . '/includes/lkproduction-order.php';
require_once __DIR__ . '/includes/lkproduction-calendar.php';

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
		plugin_dir_url( __FILE__ ) . '/static/lkproduction.css',
		[],
		filemtime(plugin_dir_path(__FILE__) . '/static/lkproduction.css')
	);
}

/* ADMIN STYLES */
add_action('admin_enqueue_scripts', 'lkproduction_admin_scripts');
function lkproduction_admin_scripts() {
	wp_enqueue_style(
		'lkproduction-admin-style',
		plugin_dir_url( __FILE__ ) . '/static/lkproduction-admin.css',
		[],
		filemtime(plugin_dir_path(__FILE__) . '/static/lkproduction-admin.css')
	);
}

function lk_rental_render_calendar_global() {
	echo '<h1>Kalendář akcí</h1>';
	lk_rental_render_calendar();
}

// Add Menu Item
add_action('admin_menu', 'rental_calendar_menu');
function rental_calendar_menu() {
	add_menu_page(
		'Kalendář akcí',
		'LK Rent',
		'manage_options',
		'rental-calendar',
		'lk_rental_render_calendar_global',
		'dashicons-calendar-alt',
		1
	);
}

add_action('admin_menu', 'rental_calendar_submenu_links', 20);
function rental_calendar_submenu_links() {
	add_submenu_page(
		'rental-calendar',
		'Kalendář akcí',
		'Kalendář akcí',
		'manage_options',
		'rental-calendar',
		'lk_rental_render_calendar_global'
	);

	add_submenu_page(
		'rental-calendar',
		'Objednávky',
		'Objednávky',
		'manage_options',
		'edit.php?post_type=shop_order&source=lk'
	);

	add_submenu_page(
		'rental-calendar',
		'Nová objednávka',
		'Nová objednávka',
		'manage_options',
		'post-new.php?post_type=shop_order&source=lk'
	);
}
