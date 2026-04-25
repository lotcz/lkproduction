<?php
/**
 * Plugin Name: LK Production Rent
 * Description: Rezervační systém pro LK Production
 * Version: 1.2.4
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

require_once __DIR__ . '/includes/lk-common.php';
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

	// Select2 is already bundled with WooCommerce
	wp_enqueue_style( 'woocommerce_admin_styles' );
	wp_enqueue_script( 'wc-enhanced-select' );

	wp_enqueue_script(
		'lkproduction-custom-form-script',
		plugin_dir_url(__FILE__) . '/static/custom-order-form.js',
		['jquery', 'wc-enhanced-select'],
		filemtime(plugin_dir_path(__FILE__) . '/static/custom-order-form.js')
	);

	// 5. Localize the script to pass the AJAX URL and a Nonce to JS
	wp_localize_script(
		'lkproduction-custom-form-script',
		'lk_admin_ajax_obj',
		[
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('lk_admin_ajax_nonce'),
		]
	);

}

// Add LK Rent menu item
add_action('admin_menu', 'rental_calendar_menu');
function rental_calendar_menu() {
	add_menu_page(
		'Kalendář akcí',
		'LK Rent',
		LK_WP_ADMIN_CAPABILITY,
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
		LK_WP_ADMIN_CAPABILITY,
		'lk-rental-calendar',
		'lk_rental_render_calendar_global'
	);

	add_submenu_page(
		'lk-rental-calendar',
		'Vytvořit cenovou nabídku',
		'Vytvořit cenovou nabídku',
		LK_WP_ADMIN_CAPABILITY,
		'lk-rental-custom-order-form',
		'lk_rental_render_custom_order_form'
	);

	add_submenu_page(
		'lk-rental-calendar',
		'Objednávky',
		'Objednávky',
		LK_WP_ADMIN_CAPABILITY,
		'edit.php?post_type=shop_order&source=lk'
	);

	add_submenu_page(
		null,
		'Náhled',
		'Náhled',
		LK_WP_ADMIN_CAPABILITY,
		'lk-custom-print-preview',
		'lk_render_custom_order_print_preview'
	);
}

// DUPLICATE ORDER

// Add "Duplicate Order" button to the order detail page
add_action('woocommerce_order_actions', function (array $actions) {
	$actions['duplicate_order'] = 'Duplikovat';
	return $actions;
});

add_action('woocommerce_order_action_duplicate_order', function (WC_Order $order) {
	$new_id = lk_production_duplicate_woo_order($order->get_id());

	if (is_wp_error($new_id)) {
		WC_Admin_Meta_Boxes::add_error($new_id->get_error_message());
		return;
	}

	// Covers both legacy (post.php) and HPOS (wc-orders) screens
	$redirect = function_exists('wc_get_order') && class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
		? admin_url('admin.php?page=wc-orders&action=edit&id=' . $new_id)
		: admin_url('post.php?post=' . $new_id . '&action=edit');

	wp_redirect($redirect);
	exit;
});

/**
 * Automatically create a WordPress user account from guest order data.
 * Covers both frontend checkout and admin-created orders.
 */

// Frontend checkout - fires after order is fully saved
add_action(
	'woocommerce_checkout_order_created',
	function ($order) {
		lk_production_auto_create_user_from_order($order);
		lk_add_auto_book_products_to_order($order);
	},
	10,
	1
);

// Admin order creation - use a later priority (999) to run after WooCommerce's own save
add_action(
	'woocommerce_process_shop_order_meta',
	function ($order_id) {
		$order = wc_get_order($order_id);
		if ($order) {
			lk_production_auto_create_user_from_order($order);
			lk_add_auto_book_products_to_order($order);
		}
	},
	999,
	1
);

add_action( 'wp_ajax_my_create_customer', 'my_ajax_create_customer' );

function my_ajax_create_customer() {
	check_ajax_referer( 'lk_admin_ajax_nonce', 'nonce' );

	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'my-plugin' ) );
	}

	$email      = sanitize_email( $_POST['email'] ?? '' );
	$first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
	$last_name  = sanitize_text_field( $_POST['last_name'] ?? '' );
	$phone      = sanitize_text_field( $_POST['phone'] ?? '' );

	if ( ! is_email( $email ) ) {
		wp_send_json_error( __( 'Invalid email address.', 'my-plugin' ) );
	}

	if ( email_exists( $email ) ) {
		wp_send_json_error( __( 'A customer with this email already exists.', 'my-plugin' ) );
	}

	$username    = wc_create_new_customer_username( $email, [
		'first_name' => $first_name,
		'last_name'  => $last_name,
	]);
	$customer_id = wc_create_new_customer( $email, $username, wp_generate_password(), [
		'first_name' => $first_name,
		'last_name'  => $last_name,
	]);

	if ( is_wp_error( $customer_id ) ) {
		wp_send_json_error( $customer_id->get_error_message() );
	}

	update_user_meta( $customer_id, 'first_name',         $first_name );
	update_user_meta( $customer_id, 'last_name',          $last_name );
	update_user_meta( $customer_id, 'billing_first_name', $first_name );
	update_user_meta( $customer_id, 'billing_last_name',  $last_name );
	update_user_meta( $customer_id, 'billing_email',      $email );
	update_user_meta( $customer_id, 'billing_phone',      $phone );

	wp_send_json_success([
		'id'    => $customer_id,
		'label' => sprintf( '%s %s (#%d – %s)', $first_name, $last_name, $customer_id, $email ),
	]);
}
