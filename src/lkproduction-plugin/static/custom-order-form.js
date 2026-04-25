function lkHasClass(el, css) {
	return el.classList.contains(css);
}

function lkAddClass(el, css) {
	if (!lkHasClass(el, css)) el.classList.add(css);
}

function lkRemoveClass(el, css) {
	if (lkHasClass(el, css)) el.classList.remove(css);
}

function lkToggleClass(el, css, active) {
	if (active) {
		lkAddClass(el, css);
	} else {
		lkRemoveClass(el, css);
	}
}

function lkFormatMoney(value) {
	if (isNaN(value)) return '--';
	return value.toLocaleString('cs-CZ', {style: 'currency', currency: 'CZK', maximumFractionDigits: 0});
}

async function lkLoadProductBookings(start, end, order_id) {
	const formData = new URLSearchParams();
	formData.append('action', 'lk_load_product_bookings');
	formData.append('security', lk_admin_ajax_obj.nonce);
	formData.append('start', start);
	formData.append('end', end);
	formData.append('order_id', order_id);

	try {
		const response = await fetch(lk_admin_ajax_obj.ajax_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: formData,
		});

		if (!response.ok) {
			throw new Error('Network response was not ok');
		}

		const result = await response.json();

		if (result.success) {
			return result.data;
		} else {
			console.error('Logic Error:', result.data);
		}
	} catch (error) {
		console.error('Fetch Error:', error);
	}
}

window.addEventListener(
	'load',
	() => {
		const form = document.querySelector('.lk-custom-order-form form');
		if (!form) {
			//Not on an LK custom form
			return;
		}
		const start_date = form.querySelector('#start_date');
		const end_date = form.querySelector('#end_date');
		const rows = document.querySelectorAll('.lk-order-products-table .product-row');

		if (!(form && start_date && end_date && rows)) {
			console.error('Some elements are missing on LK custom form!');
			return;
		}

		const getFormTotalDays = () => {
			const start = new Date(start_date.value).getTime();
			const end = new Date(end_date.value).getTime();
			if (isNaN(start) || isNaN(end)) return 1;
			const diff = end - start;
			return Math.max(1, Math.ceil(diff / (1000 * 60 * 60 * 24)));
		}

		const getRowQty = (row) => {
			const n = parseInt(row.querySelector('.qty input').value);
			return isNaN(n) ? 0 : n;
		}

		const getRowPrice = (row) => {
			const f = parseFloat(row.dataset.price);
			return isNaN(f) ? 0 : f;
		}

		const getRowDaily = (row) => getRowQty(row) * getRowPrice(row);

		// discounts for additional days
		const getPriceMultiplicator = (days) => {
			if (days <= 0) return 0;
			if (days <= 1) return 1;
			if (days <= 2) return 1.6;
			days -= 2;
			return 1.6 + (days * 0.4);
		};

		const getRowTotal = (row) => getRowDaily(row) * getPriceMultiplicator(getFormTotalDays());

		const getRowTotalStock = (row) => {
			const n = parseInt(row.dataset.stock_total);
			return isNaN(n) ? 0 : n;
		}

		const getRowBookedStock = (row) => {
			const n = parseInt(row.dataset.stock_booked);
			return isNaN(n) ? 0 : n;
		}

		const getFormTotal = () => {
			let total = 0;
			for (let i = 0, max = rows.length; i < max; i++) {
				const row = rows[i];
				total += getRowDaily(row);
			}
			return total * getPriceMultiplicator(getFormTotalDays());
		}

		const updateFormTotal = () => {
			const total = getFormTotal();
			const elements = document.querySelectorAll('.total-order-price');
			elements.forEach((el) => el.innerText = lkFormatMoney(total));
		}

		const updateRowTotal = (row) => {
			const total = getRowTotal(row);
			row.querySelector('.total').innerText = lkFormatMoney(total);
		}

		const updateRowStock = (row) => {
			const totalStock = getRowTotalStock(row);
			const bookedStock = getRowBookedStock(row);
			const qty = getRowQty(row);
			const stock = totalStock - (bookedStock + qty);

			row.querySelector('.stock').innerText = stock;

			lkToggleClass(row, 'used', qty > 0);
			lkToggleClass(row, 'overbooked', stock < 0);

			updateRowTotal(row);
			updateFormTotal();
		}

		const updateRows = () => rows.forEach(
			(row) => {
				updateRowStock(row);
				updateFormTotal();
			}
		);

		const updateBookings = async () => {
			const bookings = await lkLoadProductBookings(start_date.value, end_date.value, form.order_id.value);
			rows.forEach(
				(row) => {
					const id = row.dataset.product_id;
					row.dataset.stock_booked = (bookings[id]) ? bookings[id] : 0;
					updateRowStock(row);
				}
			);
		}

		const updateFormDays = async () => {
			form.querySelector('#total_days').innerText = getFormTotalDays();
			updateFormTotal();
			updateRows();
			await updateBookings();
		}

		const saveForm = (e) => {
			const items = [];
			rows.forEach(
				(row) => {
					const qty = getRowQty(row);
					if (qty > 0) items.push({id: parseInt(row.dataset.product_id), qty: qty});
				}
			);
			form.items.value = JSON.stringify(items);
		}

		// register events

		start_date.addEventListener('change', updateFormDays);
		end_date.addEventListener('change', updateFormDays);

		rows.forEach(
			(row) => {
				const qty = row.querySelector('input');
				qty.addEventListener('change', (e) => updateRowStock(row));
			}
		);
		form.addEventListener('submit', saveForm);
		const saveButtons = document.querySelectorAll('.lk-custom-order-form button[type=submit]');
		saveButtons.forEach(
			(el) => el.addEventListener(
				'click',
				() => {
					form.form_action.value = el.value;
					form.requestSubmit();
				}
			)
		);

		updateFormTotal();
	}
);

jQuery(function ($) {

	// Toggle the new customer form
	$('#my_create_customer_toggle').on('click', function (e) {
		e.preventDefault();
		$('#my_new_customer_form').slideToggle();
	});

	// Create new customer via AJAX
	$('#my_create_customer_btn').on('click', function () {
		const $btn = $(this);
		const $spinner = $('#my_create_customer_spinner');
		const $error = $('#my_create_customer_error').text('');

		const first_name = $('#new_customer_first_name').val().trim();
		const last_name = $('#new_customer_last_name').val().trim();
		const email = $('#new_customer_email').val().trim();
		const phone = $('#new_customer_phone').val().trim();

		if (!email) {
			$error.text('Vložte email');
			return;
		}

		$btn.prop('disabled', true);
		$spinner.addClass('is-active');

		$.post(lk_admin_ajax_obj.ajax_url, {
			action: 'my_create_customer',
			nonce: lk_admin_ajax_obj.nonce,
			first_name,
			last_name,
			email,
			phone,
		})
			.done(function (response) {
				if (response.success) {
					const customer = response.data;

					// Add the new customer as a Select2 option and select them
					const $select = $('#my_customer_search');
					const option = new Option(customer.label, customer.id, true, true);
					$select.append(option).trigger('change');

					// Hide the form and clear fields
					$('#my_new_customer_form').slideUp();
					$('#new_customer_first_name, #new_customer_last_name, #new_customer_email, #new_customer_phone').val('');
				} else {
					$error.text(response.data || 'Chyba');
				}
			})
			.fail(function () {
				$error.text('Chyba');
			})
			.always(function () {
				$btn.prop('disabled', false);
				$spinner.removeClass('is-active');
			});
	});

	// Preselect existing customer if editing an order
	const $form = $('#custom_order_form');
	const existing_customer_id = $form.data('existing_customer_id');
	const existing_customer_name = $form.data('existing_customer_name');
	if (existing_customer_id && existing_customer_name) {
		const $select = $('#my_customer_search');
		const option = new Option(
			existing_customer_name,
			existing_customer_id,
			true,
			true
		);
		$select.append(option).trigger('change');
	}

});
