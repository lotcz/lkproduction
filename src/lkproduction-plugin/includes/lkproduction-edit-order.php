<?php

require_once __DIR__ . '/lk-common.php';

// Register the status
add_action('init', function () {
	register_post_status(
		'wc-' . LK_ORDER_STATE_QUOTE,
		array(
			'label' => 'Cenová nabídka',
			'public' => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list' => true,
			'exclude_from_search' => false,
			'label_count' => _n_noop('Cenová nabídka <span class="count">(%s)</span>', 'Cenové nabídky <span class="count">(%s)</span>'),
		)
	);
});

// Tell WooCommerce about it
add_filter('wc_order_statuses', function ($statuses) {
	$statuses['wc-' . LK_ORDER_STATE_QUOTE] = 'Cenová nabídka';
	return $statuses;
});

/* Load all public products sorted by category [category => {id, title, desc, price, stock}] */
function lk_get_all_public_woocommerce_products() {
	global $wpdb;

	// One query to rule them all: joins posts, terms, and our specific meta field
	$results = $wpdb->get_results(
		$wpdb->prepare("
			SELECT 
				p.ID, 
				p.post_title,
				p.post_excerpt as short_description,
				t.name as category_name,
				pm.meta_value as rental_stock,
				pm2.meta_value as price
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON (p.ID = tr.object_id)
			INNER JOIN {$wpdb->term_taxonomy} tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id)
			INNER JOIN {$wpdb->terms} t ON (tt.term_id = t.term_id)
			LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = %s)
			LEFT JOIN {$wpdb->postmeta} pm2 ON (p.ID = pm2.post_id AND pm2.meta_key = '_price')
			WHERE p.post_type = 'product' 
			  AND p.post_status = 'publish'
			  AND tt.taxonomy = 'product_cat'
			ORDER BY t.name ASC, p.post_title ASC
		",
		LK_PRODUCT_TOTAL_STOCK_META)
	);

	if (empty($results)) return array();

	$grouped = array();

	foreach ($results as $row) {
		// Since a product can have multiple categories, we check if we've added it
		// to avoid duplicates if that's a concern, or just group by the first found.
		if (!isset($grouped[$row->category_name])) {
			$grouped[$row->category_name] = array();
		}

		$grouped[$row->category_name][] = array(
			'id' => $row->ID,
			'title' => $row->post_title,
			'desc' => $row->short_description,
			'price' => $row->price,
			'stock' => $row->rental_stock ?: 0,
			// Only fetch the full object if you absolutely need a method like ->get_price()
			// 'product' => wc_get_product($row->ID)
		);
	}

	return $grouped;
}

/* Return list of all booked products for certain period */
function lk_get_booked_products($start_date, $end_date) {
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
    ", $end_date, $start_date);

	$total_booked = $wpdb->get_var($query);

	return $total_booked ? (int)$total_booked : 0;
}

/* Get list of all public products with information about availability for certain period */
function lk_get_public_products($view_start = null, $view_end = null) {
	$products = lk_get_all_public_woocommerce_products();
// todo: fill booked information
	return $products;
}

function lk_save_order($order_id = null, $event_name = null, $start_date = null, $end_date = null, $status = LK_ORDER_STATE_QUOTE, $items = []) {
	$order = empty($order_id) ? wc_create_order() : wc_get_order($order_id);

	lk_order_set_event_name($order, $event_name);
	lk_order_set_start_date($order, $start_date);
	lk_order_set_end_date($order, $end_date);

	foreach ($items as $item) {
		$qty = absint($item['quantity'] ?? 1);
		if (!$qty) continue;
		$product = wc_get_product(absint($item['product_id']));
		if (!$product) continue;
		$order->add_product($product, $qty);
	}

	$order->calculate_totals();
	$order->set_status($status);
	$order->save();

	return $order;
}

// Render the custom order page
function lk_rental_render_custom_order_form() {
	if (!current_user_can('manage_woocommerce')) {
		wp_die('Unauthorized');
	}

	$order_id = empty($_GET['order_id']) ? null : $_GET['order_id'];
	$order = null;
	$event_name = '';
	$start_date = '';
	$end_date = '';
	$status = LK_ORDER_STATE_QUOTE;
	$edit_url = null;

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$event_name = isset($_POST['event_name']) ? sanitize_text_field($_POST['event_name']) : null;
		$start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : null;
		$end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : null;
		$status = isset($_POST['order_state']) ? sanitize_text_field($_POST['order_state']) : null;

		$order = lk_save_order($order_id, $event_name, $start_date, $end_date, $status);

		if (empty($order_id)) {
			wp_redirect(admin_url('admin.php?page=lk-rental-custom-order-form&order_id=' . $order->get_id()));
			exit;
		}
		$edit_url = $order->get_edit_order_url();
	} elseif (!empty($order_id)) {
		$order = wc_get_order($order_id);
		if (empty($order)) {
			echo '<div class="notice notice-error"><p>Objednávka nebyla nalezena!</p></div>';
			return;
		}
		$event_name = lk_order_get_event_name($order);
		$start_date = lk_order_get_start_date($order);
		$end_date = lk_order_get_end_date($order);
		$status = $order->get_status();
		$edit_url = $order->get_edit_order_url();
	}

	$all_products = lk_get_public_products();
	$heading = $order ? (($status === LK_ORDER_STATE_QUOTE) ? "Cenová nabídka" : "Objednávka") : "Vytvořit cenovou nabídku";

	?>
	<div class="wrap">
		<div class="popup-link-container">
			<h1>
				<?php echo $heading ?>
			</h1>
			<?php
			if (!empty($edit_url)) {
				?>
				<a class="popup-link" href="<?php echo $edit_url ?>" target="_blank">&nbsp;</a>
				<?php
			}
			?>
		</div>
		<div class="lk-custom-order-form">
			<form method="post" action="">
				<div class="form-cols">
					<div class="form-col">
						<div>
							<label for="event_name">Název akce</label>
							<input type="text" id="event_name" name="event_name" required value="<?php echo $event_name; ?>" style="width:100%">
						</div>
						<div>
							<label for="start_date">Od</label>
							<input type="datetime-local" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
						</div>
						<div>
							<label for="total_days">Celkem dní</label>
							<div id="total_days" class="static-value"><?php echo lk_get_total_days($start_date, $end_date) ?></div>
						</div>

					</div>
					<div class="form-col">
						<div>
							<label for="order_state">Typ/Stav</label>
							<select id="order_state" name="order_state">
								<option
									value="<?php echo LK_ORDER_STATE_QUOTE ?>"
									<?php echo $status === LK_ORDER_STATE_QUOTE ? 'selected' : '' ?>
								>Cenová nabídka
								</option>
								<option
									value="pending"
									<?php echo $status === 'pending' ? 'selected' : '' ?>
								>Objednávka - čeká na platbu
								</option>
								<option
									value="processing"
									<?php echo $status === 'processing' ? 'selected' : '' ?>
								>Objednávka - zpracovává se
								</option>
								<option
									value="completed"
									<?php echo $status === 'completed' ? 'selected' : '' ?>
								>Objednávka - dokončená
								</option>
								<option
									value="cancelled"
									<?php echo $status === 'cancelled' ? 'selected' : '' ?>
								>Zrušeno
								</option>
							</select>
						</div>
						<div>
							<label for="end_date">Do</label>
							<input type="datetime-local" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
						</div>
						<div>
							<label for="total_price">Cena celkem</label>
							<div id="total_order_price" class="static-value"><?php echo wc_price(isset($order) ? $order->get_total() : 0) ?></div>
						</div>
					</div>
				</div>
			</form>

			<div>
				<table class="lk-order-products-table">
					<thead>
					<tr>
						<th>Název</th>
						<th>Popis</th>
						<th>Ks</th>
						<th>Cena (den/ks)</th>
						<th>Cena celkem</th>
						<th>Sklad</th>
					</tr>
					</thead>
					<tbody>
					<?php
					foreach ($all_products as $category_name => $products) {
						echo '<tr><td colspan="6"><h2>' . esc_html($category_name) . '</h2></td></tr>';

						foreach ($products as $product) {
							$id = $product['id'];
							$name = $product['title'];
							$desc = $product['desc'];
							$price = $product['price'];
							$stock = $product['stock'];
							$link = admin_url("post.php?post=$id&action=edit");
							?>
							<tr
								class="product-row"
								data-product-id="<?php echo $id ?>"
								data-price="<?php echo $price ?>"
								data-stock="<?php echo $stock ?>"
								data-stock-usage="<?php echo $stock ?>"
							>
								<td>
									<div class="popup-link-container">
									<?php echo $name ?>
									<a class="popup-link" href="<?php echo $link?>" target="_blank">&nbsp;</a>
									</div>
								</td>
								<td><?php echo $desc ?></td>
								<td class="qty"><input type="number" name="product_qty" value="0" min="0"/></td>
								<td class="price"><?php echo wc_price($price) ?></td>
								<td class="price total">0 Kč</td>
								<td class="stock"><?php echo $stock ?></td>
							</tr>
							<?php
						}
					}
					?>
					</tbody>
				</table>
			</div>
			<div class="form-footer">
				<button class="button button-primary" type="submit">Uložit</button>
			</div>
		</div>
	</div>

	<?php
}

/* Add link to custom form into standard WC order */
add_action(
	'add_meta_boxes',
	function () {
		add_meta_box(
			'lk_quick_order_link',
			'LK Rent',
			'lk_render_quick_order_meta_box',
			array('shop_order', 'woocommerce_page_wc-orders'),
			'side',
			'default'
		);
	}
);

/* Render custom link form into standard WC order */
function lk_render_quick_order_meta_box($post_or_order) {
	$order_id = is_a($post_or_order, 'WC_Order')
		? $post_or_order->get_id()
		: $post_or_order->ID;
	$url = admin_url('admin.php?page=lk-rental-custom-order-form&order_id=' . $order_id);
	echo '<a href="' . esc_url($url) . '" class="button button-primary">Otevřít rychlý formulář</a>';
}
