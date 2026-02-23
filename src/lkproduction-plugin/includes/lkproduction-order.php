<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/lk-common.php';

/**
 * Inject editable rental fields directly into the WooCommerce Order "General" section
 */
add_action('woocommerce_admin_order_data_after_order_details', 'rental_editable_fields_in_general_section');

function rental_editable_fields_in_general_section($order) {
	// Get existing values
	$event = $order->get_meta('_rental_event');
	$start = $order->get_meta('_rental_start');
	$end = $order->get_meta('_rental_end');

	// Ensure the datetime-local format is correct for the input (YYYY-MM-DDTHH:MM)
	$start_formatted = $start ? date('Y-m-d\TH:i', strtotime($start)) : '';
	$end_formatted = $end ? date('Y-m-d\TH:i', strtotime($end)) : '';

	echo '<br class="clear" />';
	echo '<h3>' . __('Detail rezervace', 'lkproduction-plugin') . '</h3>';
	echo '<div class="edit_rental">';

	// Event Name
	woocommerce_wp_text_input(array(
		'id' => 'rental_event_name',
		'label' => __('Akce:', 'lkproduction-plugin'),
		'value' => $event,
		'wrapper_class' => 'form-field-wide',
		'custom_attributes' => array(
			'required' => 'required' // This adds the 'required' attribute
		)
	));

	// Start Date & Time
	echo '<p class="form-field form-field-wide">
            <label for="rental_start_date">' . __('Od:', 'lkproduction-plugin') . '</label>
            <input type="datetime-local" name="rental_start_date" required id="rental_start_date" value="' . esc_attr($start_formatted) . '" />
          </p>';

	// End Date & Time
	echo '<p class="form-field form-field-wide">
            <label for="rental_end_date">' . __('Do:', 'lkproduction-plugin') . '</label>
            <input type="datetime-local" name="rental_end_date" required id="rental_end_date" value="' . esc_attr($end_formatted) . '" />
          </p>';

	echo '</div><br class="clear" />';
}

/**
 * Save the rental fields when the Order is saved/updated in admin
 */
add_action('woocommerce_process_shop_order_meta', 'rental_save_general_section_fields');
function rental_save_general_section_fields($order_id) {
	// 1. VALIDATION: Check if fields are empty before doing anything
	$start_date = isset($_POST['rental_start_date']) ? sanitize_text_field($_POST['rental_start_date']) : '';
	$end_date = isset($_POST['rental_end_date']) ? sanitize_text_field($_POST['rental_end_date']) : '';

	if (empty($start_date) || empty($end_date)) {
		// This creates the red "Error" notice at the top of the admin page
		WC_Admin_Meta_Boxes::add_error(__('Rental dates cannot be empty. Order totals were not recalculated.', 'lk-production-plugin'));
		return; // Stop the function here
	}

	// 2. PROCEED WITH SAVING
	$order = wc_get_order($order_id);
	$old_days = lk_order_get_total_days($order);

	if (isset($_POST['rental_event_name'])) {
		lk_order_set_event_name($order, sanitize_text_field($_POST['rental_event_name']));
	}

	lk_order_set_start_date($order, $start_date);
	lk_order_set_end_date($order, $end_date);

	// 3. RECALCULATION LOGIC
	$days = lk_order_get_total_days($order);

	if ($old_days !== $days) {
		$price = lk_order_get_total_price($order);
		$order->set_total($price);

		$order->add_order_note(sprintf(
			__('Cena objednávky přepočítána na %.0f,- pro nový počet dnů: %d', 'lk-production-plugin'),
			$price,
			$days
		));

		$order->save();
	} else {
		$order->save_meta_data();
	}


}

// total order price
add_filter('woocommerce_order_get_total', 'rental_order_total_filter', 10, 2);
function rental_order_total_filter($total, $order) {
	return lk_order_get_total_price($order);
}

add_action('woocommerce_admin_order_totals_after_tax', 'rental_admin_duration_placement_fix');
function rental_admin_duration_placement_fix() {
	global $post;

	// Safety check for the order ID
	$order_id = $post->ID;
	if (!$order_id) return;

	$order = wc_get_order($order_id);
	$days = lk_order_get_total_days($order);

	?>
	<tr>
		<td class="label">Počet dnů:</td>
		<td width="1%"></td>
		<td class="amount">
			<?php echo $days ?>
		</td>
	</tr>
	<?php
}

/**
 * Add custom columns to the Orders list table
 */
add_filter('manage_edit-shop_order_columns', 'rental_add_admin_order_columns');
function rental_add_admin_order_columns($columns) {
	$new_columns = array();

	foreach ($columns as $key => $column) {
		$new_columns[$key] = $column;
		// Insert our columns right after the "Status" column
		if ('order_status' === $key) {
			$new_columns['rental_event'] = 'Akce';
			$new_columns['rental_start'] = 'Od';
			$new_columns['rental_end'] = 'Do';
		}
	}

	return $new_columns;
}

/**
 * Populate the custom columns with metadata
 */
add_action('manage_shop_order_posts_custom_column', 'rental_populate_admin_order_columns');
function rental_populate_admin_order_columns($column) {
	global $post;
	$order = wc_get_order($post->ID);

	switch ($column) {
		case 'rental_event':
			echo esc_html(lk_order_get_event_name($order));
			break;

		case 'rental_start':
			echo esc_html(lk_datetime(lk_order_get_start_date($order)));
			break;

		case 'rental_end':
			echo esc_html(lk_datetime(lk_order_get_end_date($order)));
			break;
	}
}

/**
 * Make the date columns sortable
 */
add_filter('manage_edit-shop_order_sortable_columns', 'rental_sortable_columns');
function rental_sortable_columns($columns) {
	$columns['rental_start'] = LK_ORDER_START_DATE_META;
	$columns['rental_end'] = LK_ORDER_END_DATE_META;
	return $columns;
}

// Logic to handle the sorting query
add_action('pre_get_posts', 'rental_admin_order_orderby');
function rental_admin_order_orderby($query) {
	if (!is_admin() || !$query->is_main_query() || 'shop_order' !== $query->get('post_type')) {
		return;
	}

	$orderby = $query->get('orderby');

	if (LK_ORDER_START_DATE_META === $orderby) {
		$query->set('meta_key', LK_ORDER_START_DATE_META);
		$query->set('orderby', 'meta_value');
	}

	if ('_rental_end' === $orderby) {
		$query->set('meta_key', LK_ORDER_END_DATE_META);
		$query->set('orderby', 'meta_value');
	}
}

/* Overbooking warning */
add_action('woocommerce_admin_order_data_after_billing_address', 'rental_check_overbooking_warning');
function rental_check_overbooking_warning($order) {
	$start = lk_order_get_start_date($order);
	$end = lk_order_get_end_date($order);

	$conflicts = [];
	$items = $order->get_items();

	foreach ($items as $item) {
		$product_id = $item->get_product_id();
		$total_owned = lk_get_product_total_stock($product_id);
		$already_booked = lk_get_booked_units($product_id, $start, $end, $order->get_id());
		$qty = $item->get_quantity();
		if (($already_booked + $qty) > $total_owned) {
			$conflicts[] = $item->get_name();
		}
	}

	if (!empty($conflicts)) {
		echo '<div style="padding: 10px; background: #fbeaea; border-left: 4px solid #d63638; margin-top: 10px;">';
		echo '<strong>⚠️ Překročena kapacita u položek:</strong> ';
		echo join(', ', $conflicts);
		echo '</div>';
	}
}

/**
 * Display an overbooking warning directly under the product in the admin order items list
 */
add_action('woocommerce_after_order_itemmeta', 'lk_admin_item_overbooking_warning', 10, 3);
function lk_admin_item_overbooking_warning($item_id, $item, $product) {
	// Only run this in the admin backend
	if (!is_admin() || !$product) return;

	// 1. Get the Order and Dates
	$order = $item->get_order();
	$start = lk_order_get_start_date($order);
	$end = lk_order_get_end_date($order);

	if (!$start || !$end) return;

	// 2. Get Inventory and Bookings
	$product_id = $product->get_id();
	$total_owned = lk_get_product_total_stock($product_id);
	$booked_elsewhere = lk_get_booked_units($product_id, $start, $end, $order->get_id());
	$qty = $item->get_quantity();
	$total_booked = $booked_elsewhere + $qty;

	// 3. Check for shortage
	if ($total_booked > $total_owned) {
		$shortage = $total_booked - $total_owned;
		?>
		<div class="rental-overbooking-alert" style="margin-top: 5px; padding: 5px 8px; background-color: #fbeaea; border-left: 3px solid #d63638; color: #d63638; font-size: 11px; display: inline-block;">
			<strong>⚠️ Pozor: Překročený stav skladu!</strong><br>
			V tomto termínu je celkem rezervováno <?php echo $booked_elsewhere?> ks,
			nyní rezervujete dalších <?php echo $qty ?> ks,
			ale vlastníte pouze <?php echo $total_owned ?> ks
			(Chybí: <strong><?php echo $shortage?> ks</strong>)
		</div>
		<?php
	}
}
