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
		$seconds = $date2->getTimestamp() - $date1->getTimestamp();
		$diff = (int)ceil($seconds/(60*60*24));
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

const LK_ORDER_STATE_QUOTE = 'quote';
const LK_ORDER_EVENT_NAME_META = '_rental_event';
const LK_ORDER_START_DATE_META = '_rental_start';
const LK_ORDER_END_DATE_META = '_rental_end';

function lk_order_set_event_name($order, $event_name) {
	$order->update_meta_data(LK_ORDER_EVENT_NAME_META, $event_name);
}

function lk_order_get_event_name($order) {
	return $order->get_meta(LK_ORDER_EVENT_NAME_META);
}

function lk_order_set_start_date($order, $date) {
	$order->update_meta_data(LK_ORDER_START_DATE_META, $date);
}

function lk_order_get_start_date($order) {
	return $order->get_meta(LK_ORDER_START_DATE_META);
}

function lk_order_set_end_date($order, $date) {
	$order->update_meta_data(LK_ORDER_END_DATE_META, $date);
}

function lk_order_get_end_date($order) {
	return $order->get_meta(LK_ORDER_END_DATE_META);
}

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
	return lk_get_total_days(lk_order_get_start_date($order), lk_order_get_end_date($order));
}

function lk_order_get_total_price($order): float {
	return lk_order_get_daily_price($order) * lk_order_get_total_days($order);
}

/* PRODUCT */

const LK_PRODUCT_TOTAL_STOCK_META = '_rental_total_stock';

function lk_set_product_total_stock($product_id, $stock) {
	update_post_meta($product_id, LK_PRODUCT_TOTAL_STOCK_META, (int)$stock);
}

/* Get total owned amount of certain product */
function lk_get_product_total_stock($product_id) {
	return (int) get_post_meta($product_id, LK_PRODUCT_TOTAL_STOCK_META, true);
}


/* Return list of all booked products for certain period */
function lk_get_booked_products($start_date, $end_date, $exclude_order_id = null, $product_id = null): array {
	global $wpdb;

	$states = lk_get_valid_order_states_sql();

	$query = $wpdb->prepare("
        SELECT item_meta_product.meta_value AS product_id, 
        	item_meta_qty.meta_value AS quantity, 
        	meta_start.meta_value AS start_date,
            meta_end.meta_value AS end_date
        FROM {$wpdb->prefix}woocommerce_order_items AS items
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS item_meta_product 
            ON (items.order_item_id = item_meta_product.order_item_id AND item_meta_product.meta_key = '_product_id')
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS item_meta_qty 
            ON (items.order_item_id = item_meta_qty.order_item_id AND item_meta_qty.meta_key = '_qty')
        INNER JOIN {$wpdb->postmeta} AS meta_start ON items.order_id = meta_start.post_id
        INNER JOIN {$wpdb->postmeta} AS meta_end ON items.order_id = meta_end.post_id
        INNER JOIN {$wpdb->posts} AS posts ON items.order_id = posts.ID
        WHERE meta_start.meta_key = %s
            AND meta_end.meta_key = %s
            AND meta_start.meta_value <= %s
            AND meta_end.meta_value >= %s
            AND posts.post_status IN ({$states})
           
    ", LK_ORDER_START_DATE_META, LK_ORDER_END_DATE_META, $end_date, $start_date);

	if ($product_id) {
		$query .= $wpdb->prepare(" AND item_meta_product.meta_value = %s", (string)$product_id);
	}

	if ($exclude_order_id) {
		$query .= $wpdb->prepare(" AND NOT items.order_id = %d", $exclude_order_id);
	}

	error_log($query);

	$items = $wpdb->get_results($query);

	//aggregate by product
	$aggregated = array();

	foreach ($items as $item) {
		$id = $item->product_id;
		if (isset($aggregated[$id])) {
			$existing = $aggregated[$id];
			$sum = 0;
			foreach ($items as $alt) {
				if ($alt->product_id == $id) {
					if ($alt->start_date <= $item->end_date && $alt->end_date >= $item->start_date) {
						$sum += $alt->quantity;
					}
				}
			}
			if ($sum > $existing) $aggregated[$id] = $sum;
		} else {
			$aggregated[$id] = $item->quantity;
		}
	}

	return $aggregated;
}

/* Get total booked amount of certain product inside certain period */
function lk_get_booked_units($product_id, $start_date, $end_date, $exclude_order_id = null) {
	$bookings = lk_get_booked_products($start_date, $end_date, $exclude_order_id, $product_id);
	return $bookings[$product_id] ?? 0;
}
