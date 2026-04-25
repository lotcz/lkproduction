<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/lk-common.php';

/**
 * ADD AUTO-BOOKED PRODUCTS
 */
function lk_add_auto_book_products_to_order($order) {
	if (lk_order_get_auto_products_added($order)) return;

	$products = lk_get_auto_book_products();

	if (empty($products)) return;

	foreach ($products as $product_id => $qty) {
		if ($qty < 1) {
			continue;
		}

		// Skip if product is already in the order
		$already_added = false;
		foreach ($order->get_items() as $item) {
			if ($item->get_product_id() === $product_id) {
				$already_added = true;
				break;
			}
		}

		if ($already_added) {
			continue;
		}

		$product = wc_get_product($product_id);
		if ($product) $order->add_product($product, $qty);
	}

	$order->calculate_totals();

	lk_order_set_auto_products_added($order);
	$order->save();
}

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
		'wrapper_class' => 'form-field-wide'
	));

	// Start Date & Time
	echo '<p class="form-field form-field-wide">
            <label for="rental_start_date">' . __('Od:', 'lkproduction-plugin') . '</label>
            <input type="datetime-local" name="rental_start_date" id="rental_start_date" value="' . esc_attr($start_formatted) . '" />
          </p>';

	// End Date & Time
	echo '<p class="form-field form-field-wide">
            <label for="rental_end_date">' . __('Do:', 'lkproduction-plugin') . '</label>
            <input type="datetime-local" name="rental_end_date" id="rental_end_date" value="' . esc_attr($end_formatted) . '" />
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
		<div class="rental-overbooking-alert"
			style="margin-top: 5px; padding: 5px 8px; background-color: #fbeaea; border-left: 3px solid #d63638; color: #d63638; font-size: 11px; display: inline-block;">
			<strong>⚠️ Pozor: Překročený stav skladu!</strong><br>
			V tomto termínu je celkem rezervováno <?php echo $booked_elsewhere ?> ks,
			nyní rezervujete dalších <?php echo $qty ?> ks,
			ale vlastníte pouze <?php echo $total_owned ?> ks
			(Chybí: <strong><?php echo $shortage ?> ks</strong>)
		</div>
		<?php
	}
}

/**
 * Duplicate an existing WooCommerce order.
 *
 * @param int $order_id The ID of the order to duplicate.
 * @return int|WP_Error  New order ID on success, WP_Error on failure.
 */
function lk_production_duplicate_woo_order(int $order_id) {
	$original = wc_get_order($order_id);

	if (!$original) {
		return new WP_Error('invalid_order', __('Order not found.', 'lkproduction-plugin'));
	}

	// --- Create the new order ---
	$new_order = wc_create_order([
		'customer_id' => $original->get_customer_id(),
		'customer_note' => $original->get_customer_note(),
		'created_via' => 'duplicate',
		'status' => 'pending',
	]);

	if (is_wp_error($new_order)) {
		return $new_order;
	}

	// --- Copy line items (products) ---
	foreach ($original->get_items() as $item) {
		$product = $item->get_product();
		$new_item = new WC_Order_Item_Product();

		$new_item->set_props([
			'product' => $product,
			'quantity' => $item->get_quantity(),
			'variation_id' => $item->get_variation_id(),
			//'variation' => $item->get_variation(),
			'subtotal' => $item->get_subtotal(),
			'total' => $item->get_total(),
			'tax_class' => $item->get_tax_class(),
		]);

		$new_item->set_backorder_meta();
		$new_order->add_item($new_item);
	}

	// --- Copy shipping items ---
	foreach ($original->get_items('shipping') as $shipping) {
		$new_shipping = new WC_Order_Item_Shipping();
		$new_shipping->set_props([
			'method_title' => $shipping->get_method_title(),
			'method_id' => $shipping->get_method_id(),
			'instance_id' => $shipping->get_instance_id(),
			'total' => $shipping->get_total(),
			'taxes' => $shipping->get_taxes(),
		]);
		$new_order->add_item($new_shipping);
	}

	// --- Copy fee items ---
	foreach ($original->get_items('fee') as $fee) {
		$new_fee = new WC_Order_Item_Fee();
		$new_fee->set_props([
			'name' => $fee->get_name(),
			'tax_class' => $fee->get_tax_class(),
			'total' => $fee->get_total(),
			'taxes' => $fee->get_taxes(),
		]);
		$new_order->add_item($new_fee);
	}

	// --- Copy coupon items ---
	foreach ($original->get_items('coupon') as $coupon) {
		$new_coupon = new WC_Order_Item_Coupon();
		$new_coupon->set_props([
			'code' => $coupon->get_code(),
			'discount' => $coupon->get_discount(),
			'discount_tax' => $coupon->get_discount_tax(),
		]);
		$new_order->add_item($new_coupon);
	}

	// --- Copy addresses ---
	$new_order->set_address($original->get_address('billing'), 'billing');
	$new_order->set_address($original->get_address('shipping'), 'shipping');

	// --- Copy payment & totals ---
	$new_order->set_payment_method($original->get_payment_method());
	$new_order->set_payment_method_title($original->get_payment_method_title());
	$new_order->set_currency($original->get_currency());
	$new_order->set_prices_include_tax($original->get_prices_include_tax());
	$new_order->set_discount_total($original->get_discount_total());
	$new_order->set_discount_tax($original->get_discount_tax());
	$new_order->set_shipping_total($original->get_shipping_total());
	$new_order->set_shipping_tax($original->get_shipping_tax());
	$new_order->set_cart_tax($original->get_cart_tax());
	$new_order->set_total($original->get_total());

	// --- Store a reference to the original order ---
	$new_order->update_meta_data('_duplicated_from', $order_id);

	$new_order->calculate_totals();
	$new_order->save();

	lk_order_set_auto_products_added($new_order);

	return $new_order->get_id();
}

function lk_production_auto_create_user_from_order($order) {

	// Skip if the order already belongs to a registered user
	if ($order->get_user_id()) {
		return;
	}

	$email = $order->get_billing_email();

	// Skip if no email
	if (!$email) {
		return;
	}

	// If user already exists with this email, just link them
	$existing_user = get_user_by('email', $email);
	if ($existing_user) {
		update_post_meta($order->get_id(), '_customer_user', $existing_user->ID);
		return;
	}

	$first_name = $order->get_billing_first_name();
	$last_name = $order->get_billing_last_name();
	$username = wc_create_new_customer_username($email, [
		'first_name' => $first_name,
		'last_name' => $last_name,
	]);

	// Create the user (no email notification)
	$customer_id = wc_create_new_customer($email, $username, wp_generate_password(), [
		'first_name' => $first_name,
		'last_name' => $last_name,
	]);

	if (is_wp_error($customer_id)) {
		wc_get_logger()->error(
			'auto_create_user_from_order failed: ' . $customer_id->get_error_message(),
			['source' => 'auto-customer-creation']
		);
		return;
	}

	// Update user meta with billing info
	update_user_meta($customer_id, 'first_name', $first_name);
	update_user_meta($customer_id, 'last_name', $last_name);
	update_user_meta($customer_id, 'billing_first_name', $first_name);
	update_user_meta($customer_id, 'billing_last_name', $last_name);
	update_user_meta($customer_id, 'billing_email', $email);
	update_user_meta($customer_id, 'billing_phone', $order->get_billing_phone());
	update_user_meta($customer_id, 'billing_address_1', $order->get_billing_address_1());
	update_user_meta($customer_id, 'billing_address_2', $order->get_billing_address_2());
	update_user_meta($customer_id, 'billing_city', $order->get_billing_city());
	update_user_meta($customer_id, 'billing_postcode', $order->get_billing_postcode());
	update_user_meta($customer_id, 'billing_country', $order->get_billing_country());
	update_user_meta($customer_id, 'billing_state', $order->get_billing_state());

	// Use update_post_meta directly to bypass WooCommerce's save cycle
	update_post_meta($order->get_id(), '_customer_user', $customer_id);

	// Recalculate total spent and order count for the new customer
	//WC_Customer::delete_meta_cache($customer_id);
	wc_update_total_sales_counts($order->get_id());
}
