<?php

require_once __DIR__ . '/lk-common.php';

/**
 * Inject editable rental fields directly into the WooCommerce Order "General" section
 */
add_action( 'woocommerce_admin_order_data_after_order_details', 'rental_editable_fields_in_general_section' );

function rental_editable_fields_in_general_section( $order ) {
	// Get existing values
	$event = $order->get_meta( '_rental_event' );
	$start = $order->get_meta( '_rental_start' );
	$end   = $order->get_meta( '_rental_end' );

	// Ensure the datetime-local format is correct for the input (YYYY-MM-DDTHH:MM)
	$start_formatted = $start ? date( 'Y-m-d\TH:i', strtotime( $start ) ) : '';
	$end_formatted   = $end ? date( 'Y-m-d\TH:i', strtotime( $end ) ) : '';

	echo '<br class="clear" />';
	echo '<h3>' . __( 'Detail rezervace', 'lkproduction-plugin' ) . '</h3>';
	echo '<div class="edit_rental">';

	// Event Name
	woocommerce_wp_text_input( array(
		'id'            => 'rental_event_name',
		'label'         => __( 'Akce:', 'lkproduction-plugin' ),
		'value'         => $event,
		'wrapper_class' => 'form-field-wide',
	) );

	// Start Date & Time
	echo '<p class="form-field form-field-wide">
            <label for="rental_start_date">' . __( 'Od:', 'lkproduction-plugin' ) . '</label>
            <input type="datetime-local" name="rental_start_date" id="rental_start_date" value="' . esc_attr( $start_formatted ) . '" />
          </p>';

	// End Date & Time
	echo '<p class="form-field form-field-wide">
            <label for="rental_end_date">' . __( 'Do:', 'lkproduction-plugin' ) . '</label>
            <input type="datetime-local" name="rental_end_date" id="rental_end_date" value="' . esc_attr( $end_formatted ) . '" />
          </p>';

	echo '</div><br class="clear" />';
}

/**
 * Save the rental fields when the Order is saved/updated in admin
 */
add_action('woocommerce_process_shop_order_meta', 'rental_save_general_section_fields');
function rental_save_general_section_fields($order_id) {
	$order = wc_get_order($order_id);
	$old_days = lk_order_get_total_days($order);

	if ( isset( $_POST['rental_event_name'] ) ) {
		$order->update_meta_data( '_rental_event', sanitize_text_field( $_POST['rental_event_name'] ) );
	}

	if ( isset( $_POST['rental_start_date'] ) ) {
		$order->update_meta_data( '_rental_start', sanitize_text_field( $_POST['rental_start_date'] ) );
	}

	if ( isset( $_POST['rental_end_date'] ) ) {
		$order->update_meta_data( '_rental_end', sanitize_text_field( $_POST['rental_end_date'] ) );
	}

	$order->save();

	$days = lk_order_get_total_days($order);
	if ($old_days !== $days) {
		$price = lk_order_get_total_price($order);
		$order->set_total($price);
		$order->save();
		$order->add_order_note(sprintf( __( 'Cena objednávky přepočítána na %.0f,- pro nový počet dnů: %d', 'lk-production-plugin' ), $price, $days));
		update_post_meta( $order_id, '_order_total', $price );
	}
}

// total order price
add_filter( 'woocommerce_order_get_total', 'rental_order_total_filter', 10, 2);
function rental_order_total_filter($total, $order) {
	return lk_order_get_total_price($order);
}

add_action( 'woocommerce_admin_order_totals_after_tax', 'rental_admin_duration_placement_fix' );
function rental_admin_duration_placement_fix() {
	global $post;

	// Safety check for the order ID
	$order_id = $post->ID;
	if (!$order_id ) return;

	$order = wc_get_order( $order_id );
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
add_filter( 'manage_edit-shop_order_columns', 'rental_add_admin_order_columns' );
function rental_add_admin_order_columns( $columns ) {
	$new_columns = array();

	foreach ( $columns as $key => $column ) {
		$new_columns[ $key ] = $column;
		// Insert our columns right after the "Status" column
		if ( 'order_status' === $key ) {
			$new_columns['rental_event'] = 'Akce';
			$new_columns['rental_start'] = 'Od';
			$new_columns['rental_end']   = 'Do';
		}
	}

	return $new_columns;
}

/**
 * Populate the custom columns with metadata
 */
add_action( 'manage_shop_order_posts_custom_column', 'rental_populate_admin_order_columns' );
function rental_populate_admin_order_columns( $column ) {
	global $post;
	$order = wc_get_order( $post->ID );

	switch ( $column ) {
		case 'rental_event':
			echo esc_html( $order->get_meta( '_rental_event' ) ?: '—' );
			break;

		case 'rental_start':
			echo esc_html(lk_datetime($order->get_meta( '_rental_start')));
			break;

		case 'rental_end':
			echo esc_html(lk_datetime($order->get_meta( '_rental_end' )));
			break;
	}
}

/**
 * Make the start date column sortable
 */
add_filter( 'manage_edit-shop_order_sortable_columns', 'rental_sortable_columns' );
function rental_sortable_columns( $columns ) {
	$columns['rental_start'] = '_rental_start';
	$columns['rental_end'] = '_rental_end';
	return $columns;
}

// Logic to handle the sorting query
add_action( 'pre_get_posts', 'rental_admin_order_orderby' );
function rental_admin_order_orderby($query) {
	if (!is_admin() || !$query->is_main_query() || 'shop_order' !== $query->get( 'post_type')) {
		return;
	}

	$orderby = $query->get('orderby');

	if ('_rental_start' === $orderby) {
		$query->set('meta_key', '_rental_start');
		$query->set('orderby', 'meta_value');
	}

	if ('_rental_end' === $orderby) {
		$query->set('meta_key', '_rental_end');
		$query->set('orderby', 'meta_value');
	}
}

/* Overbooking warning */
add_action('woocommerce_admin_order_data_after_billing_address', 'rental_check_overbooking_warning');
function rental_check_overbooking_warning($order) {
	$start = $order->get_meta('_rental_start');
	$end = $order->get_meta('_rental_end');

	foreach ($order->get_items() as $item) {
		$product_id = $item->get_product_id();
		$total_owned = (int) get_post_meta( $product_id, '_rental_total_stock', true );
		$already_booked = lk_get_booked_units($product_id, $start, $end);

		if ( $already_booked > $total_owned ) {
			echo '<div style="padding: 10px; background: #fbeaea; border-left: 4px solid #d63638; margin-top: 10px;">';
			echo '<strong>⚠️ Překročena kapacita:</strong> ';
			echo $item->get_name() . ' je zamluveno <strong>' . $already_booked . '</strong> kusů, ';
			echo 'ale celkový počet je jen <strong>' . $total_owned . '</strong>.';
			echo '</div>';
		}
	}
}
