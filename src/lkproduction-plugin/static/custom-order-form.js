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

function lkFormatMoney (value) {
	if (isNaN(value)) return '--';
	return value.toLocaleString('cs-CZ', {style: 'currency', currency: 'CZK', maximumFractionDigits: 0});
}

window.addEventListener(
	'load',
	() => {
		const form = document.querySelector('.lk-custom-order-form form');
		const start_date = form.querySelector('#start_date');
		const end_date = form.querySelector('#end_date');
		const rows = document.querySelectorAll('.lk-order-products-table .product-row');

		const getFormTotalDays = () => {
			const start = new Date(start_date.value).getTime();
			const end = new Date(end_date.value).getTime();
			if (isNaN(start) || isNaN(end)) return 1;
			const diff = end - start;
			return Math.max(1, Math.ceil(diff/(1000*60*60*24)));
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

		const getRowTotal = (row) => getRowDaily(row) * getFormTotalDays();

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
			return total * getFormTotalDays();
		}

		const updateFormTotal = () => {
			const total = getFormTotal();
			form.querySelector('#total_order_price').innerText = lkFormatMoney(total);
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

		const updateFormDays = () => {
			form.querySelector('#total_days').innerText = getFormTotalDays();
			updateFormTotal();
			updateRows();
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
		end_date.addEventListener('change', updateFormDays);
		rows.forEach(
			(row) => {
				const qty = row.querySelector('input');
				qty.addEventListener('change', (e) => updateRowStock(row));
			}
		);
		form.addEventListener('submit', saveForm);
		const saveButton = document.querySelector('.lk-custom-order-form button[type=submit]');
		saveButton.addEventListener('click', () => form.requestSubmit());
	}
);
