/* Online cashier. Server records are authoritative; no offline storage or payment queue. */
(() => {
	'use strict';
	const C = JSON.parse(document.getElementById('upos-config').textContent);
	const $ = (id) => document.getElementById(id);
	const t = (message, values = {}) => Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`{${key}}`, String(value)), C.catalog[message] || message);
	const node = (tag, className = '', text = '') => {
		const item = document.createElement(tag);
		if (className) item.className = className;
		if (text !== '') item.textContent = text;
		return item;
	};
	const icon = (name) => {
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor');
		svg.setAttribute('stroke-width', '1.6');
		svg.setAttribute('aria-hidden', 'true');
		const path = document.createElementNS(svg.namespaceURI, 'path');
		path.setAttribute('d', {plus: 'M12 5v14M5 12h14', basket: 'M4 8h16l-2 12H6L4 8ZM8 8l4-6 4 6', chevron: 'm9 5 7 7-7 7'}[name]);
		svg.append(path);
		return svg;
	};
	const button = (label, className, action) => {
		const item = node('button', className, label);
		item.type = 'button';
		item.addEventListener('click', action);
		return item;
	};
	const S = {store: null, methods: {}, cart: new Map(), products: [], page: 1, pages: 1, parent: null, query: '', revision: 0, phase: 'editing', sale: null, restoreId: null, attempt: null, prepareBody: null, paymentKey: null, paymentBody: null, parkKey: null, parkBody: null, sourceCart: null, customerId: 0, draftNote: '', busy: false};
	const editable = () => S.phase === 'editing' && !S.busy;
	const fa = C.locale.startsWith('fa');
	const digits = (value) => fa ? String(value).replace(/\d/g, (n) => '۰۱۲۳۴۵۶۷۸۹'[n]) : String(value);
	const latin = (value) => String(value).replace(/[۰-۹٠-٩]/g, (n) => '۰۱۲۳۴۵۶۷۸۹'.includes(n) ? String('۰۱۲۳۴۵۶۷۸۹'.indexOf(n)) : String('٠١٢٣٤٥٦٧٨٩'.indexOf(n)));
	const decimals = () => S.sale?.price_decimals ?? S.store?.price_decimals ?? 2;
	const minor = (value, strict = false) => {
		const normalized = latin(value).trim().replace('٫', '.');
		if (!/^\d+(?:\.\d+)?$/.test(normalized)) throw new Error(t('Enter a valid decimal amount without separators.'));
		let [whole, fraction = ''] = normalized.split('.');
		if (strict && fraction.length > decimals()) throw new Error(t('The amount has more decimal places than the store currency allows.'));
		fraction = fraction.padEnd(decimals() + 1, '0');
		let amount = BigInt(whole + fraction.slice(0, decimals()));
		if (!strict && Number(fraction[decimals()] || 0) >= 5) amount += 1n;
		return amount;
	};
	const decimal = (amount) => {
		const negative = amount < 0n;
		const raw = (negative ? -amount : amount).toString().padStart(decimals() + 1, '0');
		return `${negative ? '-' : ''}${decimals() ? `${raw.slice(0, -decimals())}.${raw.slice(-decimals())}` : raw}`;
	};
	const money = (value) => {
		if (!S.store) return '—';
		try {
			const raw = decimal(typeof value === 'bigint' ? value : minor(value));
			const [whole, fraction] = raw.split('.');
			const formatted = digits(whole.replace(/\B(?=(\d{3})+(?!\d))/g, S.store.thousand_separator) + (fraction !== undefined ? S.store.decimal_separator + fraction : ''));
			const symbol = S.sale?.currency_symbol_text ?? S.store.currency_symbol_text;
			const output = {left: symbol + formatted, right: formatted + symbol, left_space: symbol + ' ' + formatted, right_space: formatted + ' ' + symbol}[S.store.currency_position] || formatted + ' ' + symbol;
			return '\u2066' + output + '\u2069';
		} catch { return '—'; }
	};
	const key = () => {
		if (!window.crypto?.getRandomValues) throw new Error(t('Secure request identifiers are unavailable. Use an up-to-date browser.'));
		return Array.from(crypto.getRandomValues(new Uint8Array(20)), (b) => b.toString(16).padStart(2, '0')).join('');
	};
	const showMessage = (message = '', error = false) => {
		$('messages').hidden = !message;
		$('messages').classList.toggle('error', error);
		$('messages').textContent = message;
		mirrorMessage();
	};
	function mirrorMessage() {
		const catalog = $('catalog-error');
		const message = $('messages');
		if (matchMedia('(max-width:760px)').matches && !document.body.classList.contains('order-tab') && !message.hidden) {
			catalog.textContent = message.textContent;
			catalog.classList.toggle('error', message.classList.contains('error'));
			catalog.dataset.messageMirror = '1'; catalog.hidden = false;
		} else if (catalog.dataset.messageMirror) {
			catalog.hidden = true; catalog.textContent = ''; delete catalog.dataset.messageMirror;
		}
	}
	function connection(connected) {
		$('connection').textContent = t(connected ? 'Connected to store' : 'Connection needs checking');
		$('connection').className = `connection ${connected ? 'connected' : 'offline'}`;
	}
	async function api(path, options = {}) {
		if (!navigator.onLine) { connection(false); throw new Error(t('You are offline. Reconnect before continuing; no sale is queued.')); }
		const url = new URL(path, C.restUrl.endsWith('/') ? C.restUrl : C.restUrl + '/');
		// rest_url may use ?rest_route= when pretty permalinks are disabled.
		const base = new URL(C.restUrl);
		if (base.searchParams.has('rest_route')) {
			url.href = base.href;
			const [route, query = ''] = path.split('?');
			url.searchParams.set('rest_route', base.searchParams.get('rest_route').replace(/\/$/, '') + '/' + route);
			new URLSearchParams(query).forEach((value, name) => url.searchParams.set(name, value));
		}
		if (url.origin !== location.origin) throw new Error(t('The store API must use the same origin as this page.'));
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 20000);
		try {
			const response = await fetch(url, {method: options.method || (options.body ? 'POST' : 'GET'), credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce, ...(options.key ? {'Idempotency-Key': options.key} : {})}, ...(options.body ? {body: JSON.stringify(options.body)} : {})});
			let data;
			try { data = await response.json(); } catch { throw new Error(t('The server response could not be read. Check the sale status before retrying.')); }
			if (!response.ok) {
				const message = ['rest_cookie_invalid_nonce', 'rest_not_logged_in'].includes(data.code) ? 'Your session has expired. Sign in again, then check the sale before retrying.' : data.message || 'The request could not be completed.';
				const error = new Error(t(message));
				error.status = response.status;
				error.code = data.code;
				throw error;
			}
			connection(true);
			return {data, headers: response.headers};
		} catch (error) {
			if (!error.status) connection(false);
			if (error.name === 'AbortError') throw new Error(t('The response timed out. Check the recorded sale before trying again.'));
			if (error instanceof TypeError) throw new Error(t('The connection was interrupted. Check the sale status before retrying.'));
			throw error;
		} finally { clearTimeout(timeout); }
	}
	function setLocation() {
		const params = new URLSearchParams();
		if (S.sale || S.restoreId) params.set('sale', S.sale?.id || S.restoreId);
		else if (S.attempt) params.set('attempt', S.attempt);
		else if (S.parkKey) params.set('park', S.parkKey);
		else if (S.sourceCart) params.set('cart', S.sourceCart);
		if (S.paymentKey) params.set('payment', S.paymentKey);
		history.replaceState(null, '', location.pathname + location.search + (params.size ? '#' + params.toString() : ''));
	}
	function tab(name) {
		document.body.classList.toggle('order-tab', name === 'order');
		document.querySelectorAll('[data-tab]').forEach((item) => { const active = item.dataset.tab === name; item.classList.toggle('selected', active); item.setAttribute('aria-pressed', String(active)); });
		mirrorMessage();
	}
	function available(product) {
		return product.type === 'variable' ? product.variation_ids.length > 0 : product.purchasable && product.stock_status !== 'outofstock' && (product.stock_quantity === null || Number(product.stock_quantity) > 0);
	}
	function stockText(product) {
		if (product.stock_status === 'outofstock' || !available(product)) return t('Unavailable');
		return product.stock_quantity === null ? t('In stock') : t('{count} in stock', {count: digits(product.stock_quantity)});
	}
	function renderProducts() {
		const list = $('product-list');
		list.replaceChildren();
		list.setAttribute('aria-busy', 'false');
		if (!S.products.length) list.append(node('p', 'product-empty', t('No matching products. Try a different name or an exact SKU.')));
		for (const product of S.products) {
			const item = button('', 'product', (event) => product.type === 'variable' ? openVariations(product) : addProduct(product, event.detail === 0));
			item.dataset.productId = product.id;
			item.disabled = !editable() || !available(product);
			const image = node('span', 'product-image');
			if (product.image_url && /^https?:/i.test(product.image_url)) {
				const img = document.createElement('img');
				img.src = product.image_url; img.alt = ''; img.loading = 'lazy'; img.width = 220; img.height = 140;
				image.append(img);
			} else image.append(node('span', 'product-initials', Array.from(product.name).slice(0, 2).join('')));
			const info = node('span', 'product-info');
			info.append(node('span', 'product-name', product.name), node('span', 'product-sku', product.sku || ''));
			const stock = node('span', `product-stock ${available(product) ? '' : 'unavailable'}`, stockText(product));
			stock.id = `product-stock-${product.id}`; info.append(stock);
			const bottom = node('span', 'product-bottom');
			const price = node('span', 'product-price', product.type === 'variable' ? t('From {price}', {price: money(product.price)}) : money(product.price));
			price.id = `product-price-${product.id}`; bottom.append(price);
			const add = node('span', 'product-add'); add.append(icon(product.type === 'variable' ? 'chevron' : 'plus')); bottom.append(add);
			info.append(bottom); item.append(image, info);
			item.setAttribute('aria-label', t(product.type === 'variable' ? 'Choose options for {name}' : 'Add {name} to basket', {name: product.name}));
			item.setAttribute('aria-describedby', `${stock.id} ${price.id}`);
			list.append(item);
		}
		$('previous-page').disabled = S.page <= 1 || !editable();
		$('next-page').disabled = S.page >= S.pages || !editable();
		$('page-label').textContent = t('Page {page} of {pages}', {page: digits(S.page), pages: digits(S.pages)});
		$('catalog-status').textContent = t('{count} products shown', {count: digits(S.products.length)});
		$('variant-heading').hidden = !S.parent;
		$('variant-title').textContent = S.parent?.name || '';
	}
	async function loadProducts() {
		if (!S.store) return;
		const revision = ++S.revision;
		$('catalog-error').hidden = true;
		delete $('catalog-error').dataset.messageMirror; $('catalog-error').classList.add('error');
		$('product-list').setAttribute('aria-busy', 'true');
		$('catalog-status').textContent = t('Loading live products…');
		if (!S.products.length) $('product-list').replaceChildren(...Array.from({length: 6}, () => { const item = node('div', 'skeleton'); item.setAttribute('aria-hidden', 'true'); return item; }));
		try {
			const params = new URLSearchParams({page: S.page, per_page: 20});
			if (S.parent) params.set('parent', S.parent.id);
			if (S.query) params.set('search', S.query);
			const requests = [api('products?' + params)];
			if (S.query) { const sku = new URLSearchParams(params); sku.delete('search'); sku.set('sku', S.query); requests.push(api('products?' + sku)); }
			const results = await Promise.all(requests);
			if (revision !== S.revision) return;
			S.products = Array.from(new Map(results.flatMap((result) => result.data.products).map((product) => [product.id, product])).values());
			if (editable()) for (const product of S.products) { if (S.cart.has(product.id)) S.cart.get(product.id).product = product; }
			S.pages = Math.max(1, ...results.map((result) => Number(result.headers.get('X-WP-TotalPages')) || 1));
			renderProducts(); renderCart();
			mirrorMessage();
		} catch (error) {
			if (revision !== S.revision) return;
			$('catalog-error').textContent = error.message; $('catalog-error').hidden = false;
			$('catalog-status').textContent = t('Products could not be refreshed. Use Refresh stock to try again.');
			$('product-list').setAttribute('aria-busy', 'false');
		}
	}
	function openVariations(product) { S.parent = product; S.page = 1; S.query = ''; $('search').value = ''; loadProducts(); }
	function addProduct(product, returnFocus = false) {
		if (!editable()) return;
		const previous = S.cart.get(product.id);
		const quantity = (previous?.quantity || 0) + 1;
		if ((product.sold_individually && quantity > 1) || quantity > 1000 || (product.stock_quantity !== null && quantity > Number(product.stock_quantity))) { showMessage(t('The requested quantity is not currently available. Refresh stock before continuing.'), true); return; }
		S.cart.set(product.id, {product, quantity});
		showMessage(); renderCart();
		$('announcement').textContent = t('{name} added to basket', {name: product.name});
		$('mobile-count').classList.remove('cart-pulse');
		requestAnimationFrame(() => $('mobile-count').classList.add('cart-pulse'));
		if (returnFocus) $('search').focus({preventScroll: true});
	}
	function quantity(id, amount) {
		if (!editable()) return;
		const line = S.cart.get(id);
		if (!line) return;
		if (!Number.isInteger(amount) || amount < 1 || amount > 1000 || (line.product.sold_individually && amount > 1) || (line.product.stock_quantity !== null && amount > Number(line.product.stock_quantity))) { showMessage(t('The requested quantity is not currently available. Refresh stock before continuing.'), true); renderCart(); return; }
		line.quantity = amount; renderCart();
		$(`cart-items`).querySelector(`[data-product-id="${id}"] input`)?.focus({preventScroll: true});
	}
	function renderCart() {
		const list = $('cart-items'); list.replaceChildren();
		const lines = S.sale ? S.sale.items.map((item) => ({id: item.variation_id || item.product_id, name: item.name, quantity: item.quantity, total: item.total})) : Array.from(S.cart.values()).map(({product, quantity: qty}) => ({id: product.id, name: product.name, quantity: qty, total: decimal(minor(product.price) * BigInt(qty))}));
		if (!lines.length) { const empty = node('div', 'cart-empty'); empty.append(icon('basket'), node('p', '', t('Your next sale starts here.')), node('p', '', t('Choose a product or scan its SKU.'))); list.append(empty); }
		for (const line of lines) {
			const row = node('div', 'cart-row'); row.dataset.productId = line.id; const main = node('div'); main.append(node('div', 'cart-name', line.name));
			const controls = node('div', 'cart-controls');
			if (!S.sale) {
				const group = node('div', 'quantity');
				const decrease = button('−', '', () => quantity(line.id, line.quantity - 1));
				decrease.setAttribute('aria-label', t('Decrease quantity of {name}', {name: line.name})); decrease.disabled = !editable() || line.quantity <= 1;
				const input = document.createElement('input'); input.type = 'number'; input.min = 1; input.max = S.cart.get(line.id).product.sold_individually ? 1 : 1000; input.value = line.quantity; input.disabled = !editable(); input.setAttribute('aria-label', t('Quantity of {name}', {name: line.name})); input.addEventListener('change', () => quantity(line.id, Number(input.value)));
				const increase = button('+', '', () => quantity(line.id, line.quantity + 1)); increase.setAttribute('aria-label', t('Increase quantity of {name}', {name: line.name})); increase.disabled = !editable() || S.cart.get(line.id).product.sold_individually;
				group.append(decrease, input, increase);
				const remove = button(t('Remove'), 'text-button remove-item danger', () => { S.cart.delete(line.id); renderCart(); }); remove.disabled = !editable(); remove.setAttribute('aria-label', t('Remove {name}', {name: line.name}));
				controls.append(group, remove);
			} else controls.append(node('span', 'muted', t('Quantity: {count}', {count: digits(line.quantity)})));
			main.append(controls); row.append(main, node('div', 'cart-value', money(line.total))); list.append(row);
		}
		const total = S.sale ? minor(S.sale.total) : lines.reduce((sum, item) => sum + minor(item.total), 0n);
		const totals = $('totals-lines'); totals.replaceChildren();
		const totalRow = (label, value, grand = false) => { const row = node('div', `total-row ${grand ? 'grand' : ''}`); row.append(node('dt', '', label), node('dd', '', value)); totals.append(row); };
		if (S.sale) { totalRow(t('Subtotal'), money(S.sale.items.reduce((sum, item) => sum + minor(item.subtotal), 0n))); totalRow(t('Tax'), money(S.sale.tax)); if (Number(S.sale.discount)) totalRow(t(S.sale.manual_discount ? 'Manual discount' : 'Discount'), money(S.sale.discount)); }
		totalRow(t(S.sale ? 'Amount due' : 'Estimated product total'), money(total), true);
		$('estimate-note').hidden = !!S.sale;
		$('mobile-count').textContent = digits(lines.reduce((sum, item) => sum + item.quantity, 0));
		$('clear-cart').disabled = !editable() || !S.cart.size;
		$('prepare-sale').disabled = !editable() || !S.cart.size || !S.store || !navigator.onLine;
		$('park-cart').disabled = !editable() || !S.cart.size || !S.store || !navigator.onLine;
	}
	function syncDiscount() {
		const kind = $('discount-kind').value;
		$('coupon-field').hidden = kind !== 'coupon';
		$('coupon-code').disabled = !editable() || kind !== 'coupon'; $('coupon-code').required = kind === 'coupon';
		$('manual-fields').hidden = !['amount', 'percent'].includes(kind);
		for (const id of ['manual-value', 'manual-reason']) { $(id).disabled = !editable() || !['amount', 'percent'].includes(kind); $(id).required = ['amount', 'percent'].includes(kind); }
	}
	function basketInput() {
		const billing = Object.fromEntries(new FormData($('customer-form')));
		const body = {items: Array.from(S.cart.values()).map(({product, quantity: qty}) => ({product_id: product.id, quantity: qty})), billing};
		const kind = $('discount-kind').value;
		if (kind === 'coupon') { const code = $('coupon-code').value.trim(); if (!code) throw new Error(t('Enter a coupon code before continuing.')); body.coupon = code; }
		if (['amount', 'percent'].includes(kind)) {
			const value = latin($('manual-value').value.trim()).replace('٫', '.');
			const reason = $('manual-reason').value.trim();
			if (!reason) throw new Error(t('A reason is required for manual discounts.'));
			body.manual_discount = {type: kind, value: kind === 'amount' ? decimal(minor(value, true)) : value, reason};
		}
		if (S.sourceCart) body.parked_cart_key = S.sourceCart;
		if (S.customerId) body.customer_id = S.customerId;
		if (S.draftNote) body.note = S.draftNote;
		return body;
	}
	function setPhase(phase) {
		S.phase = phase;
		const waiting = ['preparing', 'paying', 'cancelling', 'checking', 'parking', 'loadingCart'].includes(phase);
		const recovery = ['uncertainPrepare', 'uncertainPayment', 'uncertainPark'].includes(phase);
		const complete = ['complete', 'closed'].includes(phase);
		$('customer-form').hidden = !!S.sale || recovery || complete;
		$('customer-form').querySelectorAll('input,select,button').forEach((item) => { item.disabled = phase !== 'editing'; });
		$('payment-section').hidden = !S.sale || complete || recovery;
		$('recovery-section').hidden = !recovery;
		$('complete-section').hidden = !complete;
		$('retry-request').hidden = !(S.prepareBody && phase === 'uncertainPrepare') && !(S.paymentBody && phase === 'uncertainPayment') && !(S.parkBody && phase === 'uncertainPark');
		$('abandon-attempt').hidden = !['uncertainPrepare', 'uncertainPark'].includes(phase);
		$('abandon-attempt').textContent = t(phase === 'uncertainPark' ? 'Discard unresolved basket save' : 'Abandon unresolved preparation');
		$('recovery-hint').textContent = t(phase === 'uncertainPark' ? 'The basket save result is unknown. Check the server before changing it.' : 'Do not collect funds again. Check the recorded sale or retry the same request.');
		$('back-products').disabled = !editable();
		$('search').disabled = !editable();
		$('search-form').querySelector('button').disabled = !editable();
		$('payment-form').querySelectorAll('input,select,button').forEach((item) => { item.disabled = phase !== 'reserved'; });
		$('cancel-sale').disabled = phase !== 'reserved';
		$('prepare-sale').textContent = t(phase === 'preparing' ? 'Preparing sale…' : 'Review sale and reserve stock');
		$('pay-sale').textContent = t(phase === 'paying' ? 'Recording payment…' : 'Confirm payment and complete sale');
		$('order-meta').textContent = S.sale ? t('Order #{number}', {number: S.sale.number}) : t('A new in-person sale');
		$('order').setAttribute('aria-busy', String(waiting));
		renderCart(); renderProducts(); updateBalance(); countdown(); syncDiscount();
	}
	function acceptSale(sale) {
		S.sale = sale; setLocation();
		if (sale.paid || sale.payments.length) {
			setPhase('complete'); $('complete-title').textContent = t('Payment recorded'); $('complete-detail').textContent = t('Order #{number} is recorded in WooCommerce. Do not collect the funds again.', {number: sale.number}); $('print-actions').hidden = false; receiptLink();
		} else if (sale.status !== 'pending') {
			setPhase('closed'); $('complete-title').textContent = t('This sale is closed'); $('complete-detail').textContent = t('Start a new sale if the customer wants to continue.'); $('print-actions').hidden = true;
		} else {
			$('payment-lines').replaceChildren();
			if (minor(sale.total) > 0n) addPayment(sale.total);
			$('received').checked = false; setPhase('reserved');
			if (sale.expired) showMessage(t('The stock reservation has expired. Cancel this sale and prepare a new one.'), true);
		}
		tab('order');
	}
	function receiptLink() {
		if (!S.sale) return;
		const url = new URL(C.receiptUrl); url.searchParams.set('upos_receipt', S.sale.id); url.searchParams.set('paper', $('paper').value); $('print-receipt').href = url.href;
	}
	async function prepare() {
		if (S.busy || !['editing', 'uncertainPrepare'].includes(S.phase)) return;
		if (!S.prepareBody) {
			if (!S.cart.size || !$('customer-form').reportValidity()) return;
			S.prepareBody = basketInput();
			S.attempt = key(); setLocation();
		}
		S.busy = true; showMessage(); setPhase('preparing');
		try { const {data} = await api('sales', {body: S.prepareBody, key: S.attempt}); acceptSale(data); }
		catch (error) {
			if (error.status && error.status < 500 && error.code !== 'upos_sale_conflict') { S.attempt = null; S.prepareBody = null; setLocation(); setPhase('editing'); }
			else setPhase('uncertainPrepare');
			showMessage(error.message, true);
		} finally { S.busy = false; setPhase(S.phase); }
	}
	function addPayment(amount = null) {
		if ($('payment-lines').children.length >= 20) return;
		const row = node('div', 'payment-line'); const fields = node('div', 'field-row');
		const methodLabel = node('label', '', t('Payment method')); const method = document.createElement('select'); method.className = 'payment-method'; method.required = true;
		for (const [id, label] of Object.entries(S.methods)) { const option = node('option', '', t(label)); option.value = id; method.append(option); }
		methodLabel.append(method);
		const amountLabel = node('label', '', t('Amount')); const input = document.createElement('input'); input.className = 'payment-amount'; input.type = 'text'; input.inputMode = 'decimal'; input.dir = 'ltr'; input.required = true; input.autocomplete = 'off';
		if (amount === null) { const remaining = minor(S.sale.total) - paymentSum(); amount = decimal(remaining > 0n ? remaining : 0n); }
		input.value = amount; amountLabel.append(input); fields.append(methodLabel, amountLabel);
		const referenceLabel = node('label', 'payment-reference', t('Tracking reference')); const reference = document.createElement('input'); reference.className = 'payment-reference-input'; reference.maxLength = 191; reference.dir = 'ltr'; reference.autocomplete = 'off'; referenceLabel.append(reference);
		const remove = button(t('Remove payment'), 'text-button delete-payment danger', () => { row.remove(); updateBalance(); });
		const sync = () => { referenceLabel.hidden = method.value === 'cash'; reference.required = method.value !== 'cash'; };
		method.addEventListener('change', () => { sync(); updateBalance(); }); input.addEventListener('input', updateBalance);
		row.append(fields, referenceLabel, remove); $('payment-lines').append(row); sync(); updateBalance();
	}
	function paymentSum() {
		try { return Array.from(document.querySelectorAll('.payment-amount')).reduce((sum, input) => sum + minor(input.value, true), 0n); }
		catch { return -1n; }
	}
	function updateBalance() {
		if (!S.sale) return;
		const sum = paymentSum(); const difference = minor(S.sale.total) - sum;
		$('payment-balance').textContent = sum < 0n ? t('Enter a valid decimal amount without separators.') : difference === 0n ? t('Payments match the amount due.') : difference > 0n ? t('Remaining: {amount}', {amount: money(difference)}) : t('Overpaid: {amount}', {amount: money(-difference)});
		$('payment-balance').classList.toggle('unbalanced', sum < 0n || difference !== 0n);
		$('pay-sale').disabled = S.phase !== 'reserved' || difference !== 0n || sum < 0n || !$('received').checked || !navigator.onLine || new Date(S.sale.expires_at).getTime() <= Date.now();
	}
	function countdown() {
		if (!S.sale || S.phase !== 'reserved') return;
		const seconds = Math.max(0, Math.ceil((new Date(S.sale.expires_at).getTime() - Date.now()) / 1000));
		$('countdown').textContent = seconds ? t('Reserved: {time}', {time: digits(`${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`)}) : t('Reservation expired');
		if (!seconds) $('pay-sale').disabled = true;
	}
	async function pay() {
		if (S.busy || !S.sale || !['reserved', 'uncertainPayment'].includes(S.phase)) return;
		if (!S.paymentBody) {
			if (!$('payment-form').reportValidity() || !$('received').checked) return;
			try {
				const payments = Array.from(document.querySelectorAll('.payment-line')).map((row) => ({method: row.querySelector('select').value, amount: decimal(minor(row.querySelector('.payment-amount').value, true)), reference: row.querySelector('.payment-reference-input').value.trim()}));
				S.paymentBody = {received: true, expected_total: S.sale.total, payments}; S.paymentKey ||= key(); setLocation();
			} catch (error) { showMessage(error.message, true); return; }
		}
		S.busy = true; showMessage(); setPhase('paying');
		try { const {data} = await api(`sales/${S.sale.id}/payments`, {body: S.paymentBody, key: S.paymentKey}); acceptSale(data); showMessage(t('Payment and shared stock were recorded successfully.')); loadProducts(); }
		catch (error) {
			if (error.code === 'upos_quote_changed') S.paymentBody = null;
			if (error.status && error.status < 500 && !['upos_sale_conflict', 'upos_quote_changed'].includes(error.code)) { S.paymentBody = null; setPhase('reserved'); }
			else setPhase('uncertainPayment');
			showMessage(error.message, true);
		} finally { S.busy = false; }
	}
	async function checkResult() {
		if (S.busy) return;
		S.busy = true;
		try {
			if (S.phase === 'uncertainPark') { const {data} = await api('carts/' + encodeURIComponent(S.parkKey)); await hydrateCart(data); showMessage(t('The saved basket was recovered from the server.')); return; }
			const {data} = await api(S.sale || S.restoreId ? `sales/${S.sale?.id || S.restoreId}` : 'sales/lookup?key=' + encodeURIComponent(S.attempt));
			S.restoreId = null;
			if (data.abandoned) { reset(); return; }
			if (!S.sale || data.paid || data.payments.length || data.status !== 'pending') { showMessage(); acceptSale(data); }
			else if (S.phase === 'uncertainPayment' && S.paymentBody) { S.sale = data; showMessage(t('Payment is not confirmed yet. Retry the same request; do not collect funds again.')); }
			else { S.paymentBody = null; acceptSale(data); showMessage(t('Review the recorded sale before confirming any funds.')); }
		} catch (error) { showMessage(error.message, true); }
		finally { S.busy = false; setPhase(S.phase); }
	}
	async function abandon() {
		if (S.busy || !(S.attempt || S.parkKey)) return;
		S.busy = true;
		try {
			if (S.phase === 'uncertainPark') { await api('carts/' + encodeURIComponent(S.parkKey), {method: 'DELETE'}); reset(); return; }
			const {data} = await api('sales/abandon', {body: {key: S.attempt}});
			if (data.abandoned) reset();
			else { acceptSale(data); showMessage(t('The sale was already recorded. Cancel the unpaid sale if you do not want to continue.')); }
		} catch (error) { showMessage(error.message, true); }
		finally { S.busy = false; }
	}
	async function cancelSale() {
		if (S.busy) return;
		S.busy = true; setPhase('cancelling');
		try { const {data} = await api(`sales/${S.sale.id}/cancel`, {body: {}}); acceptSale(data); showMessage(t('The unpaid sale was cancelled and its stock reservation released.')); }
		catch (error) { setPhase('reserved'); showMessage(error.message, true); }
		finally { S.busy = false; }
	}
	function reset() {
		S.sale = null; S.restoreId = null; S.cart.clear(); S.attempt = null; S.prepareBody = null; S.paymentBody = null; S.paymentKey = null; S.busy = false;
		$('customer-form').reset(); $('country').value = C.country; $('customer-state').value = C.state;
		S.parkKey = null; S.parkBody = null; S.sourceCart = null; S.customerId = 0; S.draftNote = '';
		$('payment-lines').replaceChildren(); setLocation(); showMessage(); setPhase('editing'); tab('catalog'); loadProducts(); $('search').focus();
	}
	async function parkCart() {
		if (S.busy || !['editing', 'uncertainPark'].includes(S.phase)) return;
		try {
			if (!S.parkBody) {
				if (!S.cart.size) return;
				S.parkKey = key(); S.parkBody = {label: $('park-label').value.trim(), basket: basketInput(), ...(S.sourceCart ? {replaces: S.sourceCart} : {})}; setLocation();
			}
			S.busy = true; setPhase('parking'); showMessage();
			await api('carts/' + encodeURIComponent(S.parkKey), {body: S.parkBody});
			reset(); showMessage(t('Basket saved on the server. No stock has been reserved.')); await parkedList();
		} catch (error) {
			if (!S.parkBody || error.status && error.status < 500) { S.parkKey = null; S.parkBody = null; setLocation(); setPhase('editing'); }
			else setPhase('uncertainPark');
			showMessage(error.message, true);
		} finally { S.busy = false; setPhase(S.phase); }
	}
	async function hydrateCart(data) {
		const {data: resolved} = await api('products/batch', {body: {ids: data.basket.items.map((line) => line.product_id)}});
		S.cart.clear();
		for (const line of data.basket.items) { const product = resolved.products.find((item) => item.id === line.product_id); if (product) S.cart.set(product.id, {product, quantity: line.quantity}); }
		$('customer-form').reset();
		for (const [name, value] of Object.entries(data.basket.billing || {})) { const input = $('customer-form').elements.namedItem(name); if (input) input.value = value; }
		$('park-label').value = data.label;
		if (data.basket.coupon) { $('discount-kind').value = 'coupon'; $('coupon-code').value = data.basket.coupon; }
		else if (data.basket.manual_discount) { $('discount-kind').value = data.basket.manual_discount.type; $('manual-value').value = data.basket.manual_discount.value; $('manual-reason').value = data.basket.manual_discount.reason; }
		S.sourceCart = data.key; S.parkKey = null; S.parkBody = null; S.customerId = data.basket.customer_id || 0; S.draftNote = data.basket.note || '';
		S.attempt = null; S.prepareBody = null; setLocation(); setPhase('editing'); tab('order');
		showMessage(resolved.missing.length ? t('Some saved products were removed or unpublished and were omitted. Review the remaining basket.') : t('Saved basket restored with current WooCommerce prices. Review stock before checkout.'), resolved.missing.length > 0);
	}
	async function restoreCart(cartKey) {
		if (S.busy || S.phase !== 'editing' || S.cart.size) { showMessage(t('Finish or clear the current basket before opening another sale.'), true); return; }
		S.busy = true; setPhase('loadingCart');
		try { const {data} = await api('carts/' + encodeURIComponent(cartKey)); await hydrateCart(data); }
		catch (error) { setPhase('editing'); showMessage(error.message, true); }
		finally { S.busy = false; setPhase(S.phase); }
	}
	async function parkedList() {
		$('parked-list').textContent = t('Loading…');
		try {
			const {data} = await api('carts'); $('parked-list').replaceChildren();
			if (!data.carts.length) $('parked-list').append(node('p', 'muted', t('No saved baskets.')));
			for (const cart of data.carts) {
				const row = node('div', 'recent-row'); const info = node('div'); info.append(node('strong', '', cart.label || t('Saved basket')), node('span', '', t('Prices are recalculated when restored.')));
				const actions = node('div', 'parked-actions');
				actions.append(button(t('Restore'), 'text-button', () => restoreCart(cart.key)), button(t('Delete'), 'text-button danger', async () => {
					if (S.busy || !editable()) return;
					try { await api('carts/' + encodeURIComponent(cart.key), {method: 'DELETE'}); if (S.sourceCart === cart.key) { S.sourceCart = null; setLocation(); } await parkedList(); }
					catch (error) { showMessage(error.message, true); }
				}));
				row.append(info, actions); $('parked-list').append(row);
			}
		} catch (error) { $('parked-list').textContent = error.message; }
	}
	async function recent() {
		$('recent-list').textContent = t('Loading…');
		try {
			const {data} = await api('sales/recent'); $('recent-list').replaceChildren();
			if (!data.sales.length) $('recent-list').append(node('p', 'muted', t('No recorded sales yet.')));
			for (const sale of data.sales) {
				const row = node('div', 'recent-row'); const info = node('div'); info.append(node('strong', '', t('Order #{number}', {number: sale.number})), node('span', '', money(sale.total) + ' · ' + t(sale.paid || sale.payments.length ? 'Payment recorded' : sale.status === 'pending' ? 'Unpaid' : 'Closed')));
				let control;
				if (sale.paid || sale.payments.length) { control = node('a', 'text-button', t('Receipt')); const url = new URL(C.receiptUrl); url.searchParams.set('upos_receipt', sale.id); url.searchParams.set('paper', $('paper').value); control.href = url; control.target = '_blank'; control.rel = 'noopener'; }
				else control = button(t('Open sale'), 'text-button', () => {
					if (S.phase !== 'editing' || S.cart.size) { showMessage(t('Finish or clear the current basket before opening another sale.'), true); tab('order'); return; }
					S.attempt = null; S.paymentKey = null; acceptSale(sale);
				});
				row.append(info, control); $('recent-list').append(row);
			}
		} catch (error) { $('recent-list').textContent = error.message; }
	}
	async function bootstrap() {
		try {
			const [store, methods] = await Promise.all([api('store'), api('payment-methods')]);
			S.store = store.data; S.methods = methods.data.methods;
			if (!Object.keys(S.methods).length) showMessage(t('No manual payment methods are enabled. Ask the store manager to enable one.'), true);
			await loadProducts(); renderCart();
			const params = new URLSearchParams(location.hash.slice(1));
			const id = params.get('sale'); const attempt = params.get('attempt');
			if (id && /^\d+$/.test(id)) {
				S.restoreId = id; S.paymentKey = params.get('payment'); setPhase('uncertainPayment');
				try { const {data} = await api(`sales/${id}`); S.restoreId = null; acceptSale(data); if (S.paymentKey && !data.paid) showMessage(t('Review the recorded sale before confirming any funds.')); }
				catch (error) { showMessage(error.message, true); }
			} else if (attempt) { S.attempt = attempt; setPhase('uncertainPrepare'); await checkResult(); }
			else if (params.get('park') || params.get('cart')) { S.parkKey = params.get('park') || params.get('cart'); setPhase('uncertainPark'); await checkResult(); }
			else setPhase('editing');
		} catch (error) { showMessage(error.message, true); connection(false); }
	}
	const countryNames = Intl.DisplayNames ? new Intl.DisplayNames([C.locale], {type: 'region'}) : null;
	for (const [code, name] of Object.entries(C.countries)) { const option = node('option', '', countryNames?.of(code) || name); option.value = code; $('country').append(option); }
	$('country').value = C.country; $('customer-state').value = C.state;
	$('country').addEventListener('change', () => { $('customer-state').value = ''; });
	document.querySelectorAll('[data-tab]').forEach((item) => item.addEventListener('click', () => tab(item.dataset.tab)));
	let searchTimer;
	$('search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { S.query = $('search').value.trim(); S.page = 1; loadProducts(); }, 280); });
	$('search-form').addEventListener('submit', async (event) => {
		event.preventDefault(); clearTimeout(searchTimer); const code = $('search').value.trim(); if (!code || !editable()) return;
		try { const {data} = await api('products/scan?code=' + encodeURIComponent(code)); if (data.type === 'variable') openVariations(data); else { addProduct(data, true); $('search').value = ''; S.query = ''; S.page = 1; loadProducts(); } }
		catch (error) { if (error.status === 404) { S.query = code; S.page = 1; loadProducts(); } else showMessage(error.message, true); }
	});
	$('previous-page').addEventListener('click', () => { S.page--; loadProducts(); });
	$('next-page').addEventListener('click', () => { S.page++; loadProducts(); });
	$('back-products').addEventListener('click', () => { S.parent = null; S.page = 1; loadProducts(); });
	$('refresh-products').addEventListener('click', () => S.store ? loadProducts() : bootstrap());
	$('clear-cart').addEventListener('click', () => { if (editable()) { S.cart.clear(); renderCart(); } });
	$('customer-form').addEventListener('submit', (event) => { event.preventDefault(); prepare().catch((error) => showMessage(error.message, true)); });
	$('payment-form').addEventListener('submit', (event) => { event.preventDefault(); pay(); });
	$('add-payment').addEventListener('click', () => addPayment());
	$('received').addEventListener('change', updateBalance);
	$('discount-kind').addEventListener('change', syncDiscount);
	$('park-cart').addEventListener('click', parkCart);
	$('parked-carts').addEventListener('toggle', () => { if ($('parked-carts').open) parkedList(); });
	$('refresh-parked').addEventListener('click', parkedList);
	$('cancel-sale').addEventListener('click', cancelSale);
	$('check-result').addEventListener('click', checkResult);
	$('retry-request').addEventListener('click', () => S.phase === 'uncertainPrepare' ? prepare() : S.phase === 'uncertainPark' ? parkCart() : pay());
	$('abandon-attempt').addEventListener('click', abandon);
	$('new-sale').addEventListener('click', reset);
	$('paper').addEventListener('change', receiptLink);
	$('recent-sales').addEventListener('toggle', () => { if ($('recent-sales').open) recent(); });
	$('refresh-recent').addEventListener('click', recent);
	document.addEventListener('keydown', (event) => { if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName) && editable()) { event.preventDefault(); tab('catalog'); $('search').focus(); } });
	window.addEventListener('offline', () => { connection(false); showMessage(t('You are offline. Reconnect before continuing; no sale is queued.'), true); renderCart(); updateBalance(); });
	window.addEventListener('online', () => { showMessage(t('Connection restored. Refresh stock or check the sale status before continuing.')); renderCart(); updateBalance(); });
	window.addEventListener('resize', mirrorMessage);
	window.addEventListener('beforeunload', (event) => { if (S.cart.size && S.phase === 'editing' || !['editing', 'complete', 'closed'].includes(S.phase)) { event.preventDefault(); event.returnValue = ''; } });
	setInterval(countdown, 1000);
	renderCart(); bootstrap();
})();
