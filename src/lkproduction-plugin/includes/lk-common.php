<?php

function lk_date($date) {
	if (empty($date)) return '';
	$date_format = get_option('date_format');
	return wp_date($date_format, strtotime($date));
}

function lk_time($date) {
	if (empty($date)) return '';
	$date_format = get_option('time_format');
	return wp_date($date_format, strtotime($date));
}

function lk_datetime($date) {
	if (empty($date)) return '';
	return lk_date($date) . ' ' . lk_time($date);
}

function lk_get_total_days($start, $end): int {
	if (!($start && $end)) {
		return 1;
	}
	try {
		$date1 = new DateTime($start);
		$date2 = new DateTime($end);
		$diff = (int)$date1->diff($date2)->format("%r%a");
		return ($diff <= 1) ? 1 : $diff;
	} catch (Exception $e) {
		error_log("Error when calculating days: " . $e->getMessage());
		return 1;
	}
}

/* CART */

function lk_cart_get_daily_price(): float {
	$cart = WC()->cart;
	return $cart->get_subtotal();
}

function lk_cart_set_dates($start, $end): int {
	WC()->session->set('rental_start_date', $start);
	WC()->session->set('rental_end_date', $end);
	$days = lk_get_total_days($start, $end);
	WC()->session->set('rental_days_count', $days);
	return $days;
}

function lk_cart_get_total_days(): float {
	return lk_get_total_days(WC()->session->get('rental_start_date'), WC()->session->get('rental_end_date'));
}

function lk_cart_get_total_price(): float {
	return lk_cart_get_daily_price() * lk_cart_get_total_days();
}

/* ORDER */

function lk_get_valid_order_states(): array {
	return ['wc-pending', 'wc-processing', 'wc-completed', 'wc-on-hold'];
}

function lk_get_valid_order_states_sql(): string {
	return "'" . join("','", lk_get_valid_order_states()) . "'";
}

function lk_order_get_daily_price($order): float {
	if (empty($order)) return 0;

	$items = $order->get_items();
	$daily_subtotal = 0;

	foreach ($items as $item) {
		$daily_subtotal += $item->get_total();
	}

	return $daily_subtotal;
}

function lk_order_get_daily_price_by_id($order_id): float {
	return lk_order_get_daily_price(wc_get_order($order_id));
}

function lk_order_get_total_days($order): float {
	return lk_get_total_days($order->get_meta('_rental_start'), $order->get_meta('_rental_end'));
}

function lk_order_get_total_price($order): float {
	return lk_order_get_daily_price($order) * lk_order_get_total_days($order);
}

/* PRODUCT */

function lk_get_total_owned($product_id) {
	return (int) get_post_meta( $product_id, '_rental_total_stock', true );
}

function lk_get_booked_units($product_id, $start_date, $end_date) {
	global $wpdb;

	$states = lk_get_valid_order_states_sql();

	$query = $wpdb->prepare("
        SELECT SUM(item_meta_qty.meta_value) 
        FROM {$wpdb->prefix}woocommerce_order_items AS items
        -- Join to get the Product ID for each line item
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS item_meta_product 
            ON items.order_item_id = item_meta_product.order_item_id
        -- Join to get the Quantity for each line item
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS item_meta_qty 
            ON items.order_item_id = item_meta_qty.order_item_id
        -- Join to Order Meta for Rental Start Date
        INNER JOIN {$wpdb->postmeta} AS meta_start 
            ON items.order_id = meta_start.post_id
        -- Join to Order Meta for Rental End Date
        INNER JOIN {$wpdb->postmeta} AS meta_end 
            ON items.order_id = meta_end.post_id
        -- Join to Posts to check Order Status
        INNER JOIN {$wpdb->posts} AS posts 
            ON items.order_id = posts.ID
        WHERE item_meta_product.meta_key = '_product_id' 
            AND item_meta_product.meta_value = %d
            AND item_meta_qty.meta_key = '_qty'
            AND meta_start.meta_key = '_rental_start'
            AND meta_end.meta_key = '_rental_end'
            -- Date Overlap Logic: (StartA <= EndB) AND (EndA >= StartB)
            AND meta_start.meta_value <= %s
            AND meta_end.meta_value >= %s
            AND posts.post_status IN ({$states})
    ", $product_id, $end_date, $start_date);

	$total_booked = $wpdb->get_var($query);

	return $total_booked ? (int) $total_booked : 0;
}
