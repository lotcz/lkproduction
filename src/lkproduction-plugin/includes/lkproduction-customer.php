<?php

require_once __DIR__ . '/lk-common.php';

// Append "per day" to the WooCommerce product price HTML
add_filter( 'woocommerce_get_price_html', 'rental_add_per_day_label', 10, 2 );
function rental_add_per_day_label( $price, $product ) {
	$label = '<span class="price-per-day-label"> / den</span>';
	return $price . $label;
}

// Add Rental Fields to the Checkout Form
add_action('woocommerce_before_checkout_billing_form', 'rental_global_fields');
function rental_global_fields($checkout) {
	echo '<div id="rental_global_details"><h3>Rezervace pronájmu</h3>';

	woocommerce_form_field('rental_event_name', array(
		'type' => 'text',
		'class' => array('my-field-class form-row-wide'),
		'label' => 'Název akce',
		'required' => true,
	), $checkout->get_value('rental_event_name'));

	woocommerce_form_field('rental_start_date', array(
		'type' => 'datetime-local',
		'class' => array('form-row-first'),
		'label' => 'Začátek pronájmu',
		'required' => true,
		'custom_attributes' => array(
			'min' => date('Y-m-d') // Prevents picking dates in the past
		),
	), $checkout->get_value('rental_start_date'));

	woocommerce_form_field('rental_end_date', array(
		'type' => 'datetime-local',
		'class' => array('form-row-last'),
		'label' => 'Konec pronájmu',
		'required' => true,
		'custom_attributes' => array(
			'min' => date('Y-m-d') // Prevents picking dates in the past
		),
	), $checkout->get_value('rental_end_date'));

	echo '<div class="clear"></div></div>';

	// Simple script to refresh totals when dates change
	?>
	<script type="text/javascript">
		jQuery(function($){
			$('input[name="rental_start_date"], input[name="rental_end_date"]').on('change', function(){
				$('body').trigger('update_checkout');
			});
		});
	</script>
	<?php
}

/**
 * Validate rental dates and event name during checkout
 */
add_action( 'woocommerce_checkout_process', 'rental_validate_checkout_fields' );
function rental_validate_checkout_fields() {
	// Check if Event Name is empty
	if ( empty( $_POST['rental_event_name'] ) ) {
		wc_add_notice( __( 'Vložte prosím název akce, pro kterou si techniku pronajímáte.' ), 'error' );
	}

	// Check if Start Date is empty
	if ( empty( $_POST['rental_start_date'] ) ) {
		wc_add_notice( __( 'Vyberte prosím datum začátku pronájmu.' ), 'error' );
	}

	// Check if End Date is empty
	if ( empty( $_POST['rental_end_date'] ) ) {
		wc_add_notice( __( 'Vyberte prosím datum konce pronájmu.' ), 'error' );
	}

	// Logical Validation: Ensure End Date is after Start Date
	if (!empty( $_POST['rental_start_date']) && !empty($_POST['rental_end_date'])) {
		$start = strtotime( $_POST['rental_start_date'] );
		$end = strtotime( $_POST['rental_end_date'] );

		if ($end <= $start) {
			wc_add_notice( __( 'Datum konce pronájmu musí být až po jeho začátku.' ), 'error' );
		}

		if ($start <= time()) {
			wc_add_notice( __( 'Datum začátku pronájmu nesmí být v minulosti.' ), 'error' );
		}
	}
}

// Save fields to Order Meta
add_action('woocommerce_checkout_update_order_meta', 'rental_save_order_meta');
function rental_save_order_meta($order_id) {
	if (empty($_POST['rental_start_date']) || empty($_POST['rental_end_date']) || empty($_POST['rental_event_name'])) {
		wc_add_notice('Nejsou vyplněna všechna povinná pole!', 'error');
		return;
	}

	update_post_meta($order_id, '_rental_event', sanitize_text_field($_POST['rental_event_name']));
	update_post_meta($order_id, '_rental_end', sanitize_text_field($_POST['rental_end_date']));
	update_post_meta($order_id, '_rental_start', sanitize_text_field($_POST['rental_start_date']));
}

// Calculate rental price
add_action( 'woocommerce_calculate_totals', 'rental_calculate_final_multiplied_total', 20, 1 );
function rental_calculate_final_multiplied_total($cart) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

	// 1. Determine the Days
	$post_data = array();
	if ( isset( $_POST['post_data'] ) ) {
		parse_str( $_POST['post_data'], $post_data );
	} else {
		$post_data = $_POST;
	}

	$start = !empty($post_data['rental_start_date']) ? $post_data['rental_start_date'] : '';
	$end = !empty($post_data['rental_end_date']) ? $post_data['rental_end_date'] : '';

	lk_cart_set_dates($start, $end);
	//$total_price = lk_cart_get_total_price();

}

// total cart price
add_filter( 'woocommerce_calculated_total', 'rental_cart_total_filter', 10, 2);
function rental_cart_total_filter($total, $cart) {
	return lk_cart_get_total_price();
}

// Display number of days in cart
add_action('woocommerce_review_order_before_order_total', 'rental_display_duration_row' );
function rental_display_duration_row() {
	$days = WC()->session->get('rental_days_count') ? WC()->session->get('rental_days_count') : 1;
	?>
	<tr class="rental-duration-row">
		<th>Celkem dní</th>
		<td><strong><?php echo $days; ?></strong></td>
	</tr>
	<?php
}

/**
 * Display Rental Details on the Order Received page
 */
add_action( 'woocommerce_order_details_before_order_table', 'rental_display_details_on_thank_you', 10, 1 );
function rental_display_details_on_thank_you( $order ) {
	// Get the metadata
	$start = $order->get_meta( '_rental_start' );
	$end   = $order->get_meta( '_rental_end' );
	$event = $order->get_meta( '_rental_event' );

	// If no rental data exists, don't show the box
	if ( ! $start || ! $end ) return;

	$days = lk_order_get_total_days($order);

	?>
	<section class="woocommerce-rental-details" style="margin-bottom: 2em; padding: 20px; background: #fef9e7; border: 1px solid #f39c12; border-radius: 5px;">
		<h2 class="woocommerce-column__title">Rezervace potvrzena</h2>
		<table class="woocommerce-table woocommerce-table--rental-details shop_table">
			<tbody>
			<tr>
				<th><strong>Název akce:</strong></th>
				<td><?php echo esc_html( $event ); ?></td>
			</tr>
			<tr>
				<th><strong>Termín:</strong></th>
				<td><?php echo lk_datetime($start); ?> — <?php echo lk_datetime($end); ?></td>
			</tr>
			<tr>
				<th><strong>Celkový počet dnů:</strong></th>
				<td><strong><?php echo $days; ?></strong></td>
			</tr>
			</tbody>
		</table>
	</section>
	<?php
}

/**
 * Add Rental Details to WooCommerce Emails
 */
add_action( 'woocommerce_email_after_order_table', 'rental_add_details_to_emails', 10, 4 );
function rental_add_details_to_emails( $order, $sent_to_admin, $plain_text, $email ) {
	$start = lk_datetime($order->get_meta( '_rental_start' ));
	$end   = lk_datetime($order->get_meta( '_rental_end' ));
	$event = $order->get_meta( '_rental_event' );

	if ( ! $start || ! $end ) return;

	if ( $plain_text ) {
		echo "\nRezervace potvrzena\n";
		echo "Název akce: " . $event . "\n";
		echo "Termín: " . $start . " - " . $end . "\n";
	} else {
		echo '<h2>Rezervace potvrzena</h2>';
		echo '<ul>';
		echo '<li><strong>Název akce:</strong> ' . esc_html( $event ) . '</li>';
		echo '<li><strong>Termín:</strong> ' . esc_html( $start ) . ' - ' . esc_html( $end ) . '</li>';
		echo '</ul>';
	}
}

/**
 * Add the Rental Duration row to the Order Details table (Thank You page / My Account)
 */
add_filter('woocommerce_get_order_item_totals', 'rental_add_duration_row_to_order_details', 10, 3);
function rental_add_duration_row_to_order_details( $total_rows, $order, $tax_display ) {
	$days = lk_order_get_total_days($order);

	// Create the new row
	$new_row = array(
		'rental_duration' => array(
			'label' => __( 'Počet dnů:', 'lkproduction-plugin' ),
			'value' => $days
		),
	);

	// We want to insert this row BEFORE the total.
	// We find the 'order_total' key and split the array there.
	$total_key = 'order_total';
	if ( array_key_exists( $total_key, $total_rows ) ) {
		$offset = array_search( $total_key, array_keys( $total_rows ) );
		$total_rows = array_merge(
			array_slice( $total_rows, 0, $offset ),
			$new_row,
			array_slice( $total_rows, $offset )
		);
	} else {
		$total_rows['rental_duration'] = $new_row['rental_duration'];
	}

	return $total_rows;
}
