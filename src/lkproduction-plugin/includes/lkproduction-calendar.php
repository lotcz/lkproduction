<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/lk-common.php';

function lk_get_calendar_events($view_start, $view_end, $product_id = null) {
	global $wpdb;

	$sql = "SELECT p.ID, 
		p.post_status, 
        m1.meta_value as start_date, 
        m2.meta_value as end_date, 
        m3.meta_value as event_name";

	if ($product_id) {
		$sql .= ", item_meta2.meta_value as item_count";
	}

	$sql .= " FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_rental_start'
            INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_rental_end'
            LEFT JOIN {$wpdb->postmeta} m3 ON p.ID = m3.post_id AND m3.meta_key = '_rental_event'";

	// If Product ID is provided, join with order items
	if ($product_id) {
		$sql .= $wpdb->prepare("
            INNER JOIN {$wpdb->prefix}woocommerce_order_items AS items ON p.ID = items.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS item_meta ON items.order_item_id = item_meta.order_item_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS item_meta2 ON items.order_item_id = item_meta2.order_item_id
            WHERE item_meta.meta_key = '_product_id' AND item_meta2.meta_key = '_qty' AND item_meta.meta_value = %d AND ", $product_id);
	} else {
		$sql .= " WHERE ";
	}

	$states = lk_get_valid_order_states_sql();

	// Date Range Filter (Overlap logic)
	$sql .= $wpdb->prepare("
        p.post_type = 'shop_order' 
        AND p.post_status IN ($states)
        AND m1.meta_value <= %s 
        AND m2.meta_value >= %s
    ", $view_end, $view_start);

	$results = $wpdb->get_results($sql);
	$events = [];

	foreach ($results as $row) {
		$start_iso = str_replace('T', ' ', $row->start_date);
		$end_iso = str_replace('T', ' ', $row->end_date);

		$color = '#95a5a6';
		switch ($row->post_status) {
			case 'wc-pending':
				$color = '#9b59b6';
				break;
			case 'wc-on-hold':
				$color = '#f1c40f';
				break;
			case 'wc-processing':
				$color = '#2ecc71';
				break;
			case 'wc-completed':
				$color = '#3498db';
				break;
		}

		$name = ($row->event_name ?: 'Objednávka #' . $row->ID);

		if ($product_id) {
			$name .= " ($row->item_count ks)";
		}

		$events[] = [
			'id' => $row->ID,
			'title' => $name,
			'start' => $start_iso,
			'end' => $end_iso,
			'url' => html_entity_decode(lk_order_get_edit_link_custom($row->ID)),
			'color' => $color,
			'allDay' => false
		];
	}

	return $events;
}

// Create a simple AJAX endpoint for the calendar
add_action('wp_ajax_get_rental_calendar_events', function () {
	$view_start = sanitize_text_field($_GET['start']);
	$view_end = sanitize_text_field($_GET['end']);
	$product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : null;

	wp_send_json(lk_get_calendar_events($view_start, $view_end, $product_id));
});

// Render the Page
function lk_rental_render_calendar($product_id = null) {
	?>
	<div id="lk_rent_calendar"></div>

	<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var calendarEl = document.getElementById('lk_rent_calendar');
			var calendar = new FullCalendar.Calendar(calendarEl, {
				locale: 'cs',
				buttonText: {
					today: 'Dnes',
					month: 'Měsíc',
					week: 'Týden',
					day: 'Den',
					list: 'Přehled pronájmů'
				},
				initialView: 'dayGridMonth',
				displayEventStart: true,
				displayEventEnd: true,
				fixedWeekCount: false,
				aspectRatio: 2.2,
				eventTimeFormat: {
					hour: '2-digit',
					minute: '2-digit',
					meridiem: false,
					hour12: false
				},
				events: {
					url: ajaxurl,
					extraParams: {
						action: 'get_rental_calendar_events',
						product_id: <?php echo isset($product_id) ? $product_id : 'null'; ?>
					}
				},
				loading: function (isLoading) {
					if (isLoading) {
						calendarEl.style.opacity = '0.5';
					} else {
						calendarEl.style.opacity = '1';
					}
				}
			});
			calendar.render();

			jQuery(document).on('sortstop', '#poststuff', function () {
				calendar.updateSize();
			});
		});
	</script>
	<?php
}

/* RENDER CALENDAR */
function lk_rental_render_calendar_global() {
	?>
	<div class="wrap">
		<h1>Kalendář akcí</h1>
		<?php lk_rental_render_calendar(); ?>
	</div>
	<?php
}
