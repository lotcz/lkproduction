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

window.addEventListener(
	'load',
	() => {
		const form = document.querySelector('.lk-custom-order-form form');
		const start_date = form.querySelector('#start_date');
		const end_date = form.querySelector('#end_date');
		const rows = document.querySelectorAll('.lk-order-products-table .product-row');

		const formatMoney = (value) => {
			if (isNaN(value)) return '--';
			return value.toLocaleString('cs-CZ', {style: 'currency', currency: 'CZK', maximumFractionDigits: 0});
		}

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

		const getRowStock = (row) => {
			const n = parseInt(row.dataset.stock);
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
			form.querySelector('#total_order_price').innerText = formatMoney(total);
		}

		const updateRowTotal = (row) => {
			const total = getRowTotal(row);
			row.querySelector('.total').innerText = formatMoney(total);
			updateFormTotal();
		}

		const updateRowStock = (row) => {
			const totalStock = getRowStock(row);
			const qty = getRowQty(row);
			const stock = totalStock - qty;

			row.querySelector('.stock').innerText = stock;

			lkToggleClass(row, 'used', qty > 0);
			lkToggleClass(row, 'overbooked', stock < 0);

			updateRowTotal(row);
		}

		const updateRows = () => rows.forEach(updateRowStock);

		const updateFormDays = () => {
			form.querySelector('#total_days').innerText = getFormTotalDays();
			updateFormTotal();
			updateRows();
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

	}
);
