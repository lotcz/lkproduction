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
