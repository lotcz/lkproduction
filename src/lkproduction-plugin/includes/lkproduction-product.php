<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/lkproduction-calendar.php';
require_once __DIR__ . '/lk-common.php';

/**
 * Add a custom "Rental Stock" tab to the Product Data box
 */
add_filter('woocommerce_product_data_tabs', 'rental_add_inventory_tab');
function rental_add_inventory_tab($tabs) {
	$tabs['rental_inventory'] = array(
		'label' => __('LK Rent', 'rental-plugin'),
		'target' => 'lk_rental_inventory_options', // This must match the ID in Step 2
		'class' => array('show_if_simple', 'show_if_variable'),
		'priority' => 1, // Places it near the standard Inventory tab
	);
	return $tabs;
}

/**
 * Add the panel content for the Rental Stock tab
 */
add_action('woocommerce_product_data_panels', 'rental_inventory_tab_panel');
function rental_inventory_tab_panel() {
	// ID matches the 'target' in the tab registration
	echo '<div id="lk_rental_inventory_options" class="panel woocommerce_options_panel">';
	echo '<div class="options_group">';

	// We use woocommerce_wp_text_input with 'type' => 'number'
	woocommerce_wp_text_input(array(
		'id' => LK_PRODUCT_TOTAL_STOCK_META,
		'label' => 'Celkový počet',
		'placeholder' => '0',
		'desc_tip' => true,
		'description' => 'Celkový počet kusů ve firmě, který je k dispozici pro pronájem.',
		'type' => 'number',
		'custom_attributes' => array(
			'step' => '1',
			'min' => '0'
		)
	));

	echo '</div>';
	echo '</div>';
}

/**
 * Save the rental stock field
 */
add_action('woocommerce_process_product_meta', 'rental_save_tab_data');
function rental_save_tab_data($product_id) {
	$rental_stock = isset($_POST[LK_PRODUCT_TOTAL_STOCK_META]) ? $_POST[LK_PRODUCT_TOTAL_STOCK_META] : '';
	lk_set_product_total_stock($product_id, sanitize_text_field($rental_stock));
}

/* availability on product page */
add_action('add_meta_boxes', 'lk_add_rental_availability_metabox');
function lk_add_rental_availability_metabox() {
	add_meta_box(
		'lk_rental_availability_box',
		'Kalendář obsazenosti',
		'lk_render_rental_metabox_content',
		'product',
		'normal',
		'high'
	);
}

function lk_render_rental_metabox_content($post) {
	?>
	<div id="product-metabox-calendar-wrapper" style="min-height: 300px;">
		<?php lk_rental_render_calendar($post->ID); ?>
	</div>
	<?php
}
