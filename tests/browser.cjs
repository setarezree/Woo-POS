/* Run against the disposable fixtures from browser-fixture.php. No mocked store/API responses. */
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const output = process.argv[3] || '/tmp/upos-browser-results';
fs.mkdirSync(output, {recursive: true});
let checks = 0;
const check = (condition, label) => { assert.ok(condition, label); checks++; console.log('PASS: ' + label); };
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

(async () => {
	const browser = await chromium.launch({headless: true, executablePath: process.env.UPOS_CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox', '--disable-dev-shm-usage']});
	try {
		const contexts = {};
		for (const role of ['cashier', 'other', 'subscriber']) {
			contexts[role] = await browser.newContext({viewport: {width: 1440, height: 1000}});
			await contexts[role].addCookies([{name: fixture.cookieName, value: fixture.users[role].cookie, url: fixture.baseUrl}]);
		}
		if (process.argv[4] === '--english') {
			const page = await contexts.cashier.newPage();
			await page.goto(fixture.baseUrl + '?upos_app=1'); await page.locator('.product').first().waitFor();
			check(await page.locator('html').getAttribute('dir') === 'ltr', 'English interface uses LTR');
			check(await page.locator('.catalog-heading h2').textContent() === 'Products', 'English catalog labels render from the same application');
			check(await page.locator('.order-heading h2').textContent() === 'Basket', 'English basket labels render correctly');
			check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'English desktop fits the viewport');
			await page.setViewportSize({width: 390, height: 844});
			check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'English mobile fits the viewport');
			console.log(`Completed ${checks} English browser checks.`); return;
		}
		const anonymous = await browser.newContext();
		const anon = await anonymous.request.get(fixture.baseUrl + '?upos_app=1', {maxRedirects: 0});
		check(anon.status() === 302 && anon.headers().location.includes('wp-login.php'), 'anonymous cashier page redirects to WordPress login');
		const denied = await contexts.subscriber.request.get(fixture.baseUrl + '?upos_app=1');
		check(denied.status() === 403, 'subscriber cannot open cashier');
		const page = await contexts.cashier.newPage();
		const errors = [];
		page.on('pageerror', (error) => errors.push(error.message));
		page.on('dialog', (dialog) => dialog.accept());
		const pageResponse = await page.goto(fixture.baseUrl + '?upos_app=1');
		await page.locator('.product').first().waitFor();
		check(pageResponse.status() === 200 && pageResponse.headers()['cache-control'].includes('no-store'), 'authorized cashier HTML is private and not cached');
		check(await page.locator('html').getAttribute('dir') === 'rtl', 'cashier defaults to Persian RTL without a WordPress language pack');
		check(await page.locator('.product').count() >= 7, 'catalog renders real WooCommerce fixtures');
		check(await page.locator('.product').first().evaluate((item) => {
			const descriptions = item.getAttribute('aria-describedby').split(' ').map((id) => document.getElementById(id)?.textContent);
			return descriptions.length === 2 && descriptions.every(Boolean);
		}), 'product accessibility description includes actual price and stock');
		check(!(await page.locator('body').textContent()).includes('API Key'), 'page does not ask for API credentials');
		const overflow = () => page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
		check(!await overflow(), 'desktop layout has no horizontal overflow');
		const api = async (route, body = null, extraHeaders = {}) => page.evaluate(async ({route, body, extraHeaders}) => {
			const config = JSON.parse(document.getElementById('upos-config').textContent);
			const url = new URL(config.restUrl);
			if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + '/' + route);
			else url.pathname = url.pathname.replace(/\/$/, '') + '/' + route;
			const response = await fetch(url, {method: body ? 'POST' : 'GET', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce, ...extraHeaders}, ...(body ? {body: JSON.stringify(body)} : {})});
			return {status: response.status, data: await response.json()};
		}, {route, body, extraHeaders});
		check((await api('products', null, {'X-WP-Nonce': 'invalid'})).status === 403, 'real HTTP REST request with invalid nonce is rejected');
		await page.locator('h2').first().click(); await page.keyboard.press('/');
		check(await page.locator('#search').evaluate((input) => input === document.activeElement), 'slash shortcut focuses product search');
		await page.locator('#search').fill('UI-0'); await page.locator('#search').press('Enter');
		await page.locator('.cart-row').first().waitFor();
		check((await page.locator('.cart-name').first().textContent()).includes('پیراهن'), 'exact SKU scan adds real product');
		await page.locator('.quantity button').last().click();
		check(await page.locator('.quantity input').inputValue() === '2', 'quantity increment updates basket');
		await page.locator('.quantity input').fill('1'); await page.locator('.quantity input').press('Tab');
		await page.locator('.quantity input').fill('2'); await page.locator('.quantity input').press('Tab');
		check(await page.locator('.quantity input').inputValue() === '2', 'keyboard quantity editing retains the entered value');
		await page.locator('#search').fill('UI-SIZE-LARGE'); await page.locator('#search').press('Enter');
		await page.waitForFunction(() => document.querySelectorAll('.cart-row').length === 2);
		check((await page.locator('.cart-name').allTextContents()).some((name) => name.includes('large')), 'scanner selects a specific WooCommerce variation');
		await page.screenshot({path: path.join(output, 'desktop.png'), fullPage: true});
		await page.setViewportSize({width: 390, height: 844});
		await page.screenshot({path: path.join(output, 'mobile.png'), fullPage: true});
		check(!await overflow(), 'mobile catalog has no horizontal overflow');
		await page.locator('[data-tab="order"]').click();
		check(await page.locator('#order').isVisible() && !await page.locator('#catalog').isVisible(), 'mobile basket tab exposes checkout without clipping catalog');
		check(!await overflow(), 'mobile basket and customer form fit the viewport');
		await page.locator('.quantity input').first().fill('10'); await page.locator('.quantity input').first().press('Tab');
		await page.locator('[data-tab="catalog"]').click();
		await page.locator(`.product[data-product-id="${fixture.products[0]}"]`).click();
		check(await page.locator('#catalog-error').isVisible() && (await page.locator('#catalog-error').textContent()).includes('این تعداد'), 'mobile product stock-limit error is visible in the active catalog tab');
		await page.screenshot({path: path.join(output, 'mobile-feedback.png'), fullPage: true});
		await page.locator(`.product[data-product-id="${fixture.products[2]}"]`).click();
		check(!await page.locator('#search').evaluate((input) => input === document.activeElement), 'touch product selection does not summon the search keyboard');
		await page.locator('[data-tab="order"]').click();
		await page.locator(`.cart-row[data-product-id="${fixture.products[2]}"] .remove-item`).click();
		await page.locator('.quantity input').first().fill('2'); await page.locator('.quantity input').first().press('Tab');
		await page.setViewportSize({width: 1440, height: 1000});
		const customer = async () => { await page.locator('#first-name').fill('مشتری'); await page.locator('#last-name').fill('آزمایشی'); await page.locator('#phone').fill('۰۹۱۲۳۴۵۶۷۸۹'); };
		await customer();
		await page.locator('#prepare-sale').click();
		await page.locator('#payment-section').waitFor({state: 'visible'});
		check((await page.locator('#order-meta').textContent()).includes('سفارش'), 'preparation shows a real server-assigned order number');
		check(await page.locator('#pay-sale').isDisabled(), 'payment cannot submit before explicit receipt confirmation');
		check(await page.locator('#search').isDisabled(), 'reserved basket cannot be silently edited');
		await page.locator('.payment-amount').fill('40.00'); await page.locator('#add-payment').click();
		await page.locator('.payment-method').nth(1).selectOption('card_terminal');
		await page.locator('.payment-reference-input').nth(1).fill('browser-terminal-reference');
		await page.locator('#received').check();
		check(await page.locator('.payment-amount').nth(1).inputValue() === '36.00', 'split payment fills the exact remaining amount');
		await page.locator('#pay-sale').click();
		await page.locator('#complete-section').waitFor({state: 'visible'});
		check((await page.locator('#complete-title').textContent()).includes('پرداخت ثبت'), 'browser completes a real split payment');
		const saleId = new URLSearchParams(new URL(page.url()).hash.slice(1)).get('sale');
		const paid = await api('sales/' + saleId);
		check(paid.data.paid && paid.data.payments.length === 2 && paid.data.total === '76.00', 'server confirms paid WooCommerce order with two financial lines');
		check((await api('products/' + fixture.products[0])).data.stock_quantity === 8, 'browser sale reduced shared product stock exactly twice for quantity two');
		check((await api('products/' + fixture.variable)).data.stock_quantity === 4, 'variation sale reduced its parent stock');
		const receiptUrl = await page.locator('#print-receipt').getAttribute('href');
		const receipt = await contexts.cashier.newPage();
		const receiptResponse = await receipt.goto(receiptUrl);
		check(receiptResponse.status() === 200 && receiptResponse.headers()['cache-control'].includes('no-store'), 'receipt is authenticated and not cached');
		check((await receipt.locator('.receipt').textContent()).includes('مشتری آزمایشی'), 'receipt uses actual saved customer details');
		check((await receipt.locator('.receipt').textContent()).includes('browser-terminal-reference'), 'receipt includes the recorded terminal reference');
		await receipt.emulateMedia({media: 'print'});
		const width80 = await receipt.locator('.receipt').evaluate((item) => item.getBoundingClientRect().width);
		check(!await receipt.locator('.print-toolbar').isVisible(), 'print controls disappear in print media');
		await receipt.screenshot({path: path.join(output, 'receipt-80.png'), fullPage: true});
		await receipt.pdf({path: path.join(output, 'receipt-80.pdf'), width: '80mm', margin: {top: '2mm', right: '2mm', bottom: '2mm', left: '2mm'}, printBackground: true});
		const narrow = new URL(receiptUrl); narrow.searchParams.set('paper', '58'); await receipt.goto(narrow.href);
		const width58 = await receipt.locator('.receipt').evaluate((item) => item.getBoundingClientRect().width);
		check(width80 > width58 + 70 && width58 < 206, '58mm and 80mm receipts use different print widths');
		await receipt.screenshot({path: path.join(output, 'receipt-58.png'), fullPage: true});
		await receipt.pdf({path: path.join(output, 'receipt-58.pdf'), width: '58mm', margin: {top: '2mm', right: '2mm', bottom: '2mm', left: '2mm'}, printBackground: true});
		check((await contexts.other.request.get(receiptUrl)).status() === 404, 'another cashier cannot read the receipt');
		await page.reload(); await page.locator('#complete-section').waitFor({state: 'visible'});
		check((await page.locator('#complete-title').textContent()).includes('پرداخت ثبت'), 'reloading a completed sale restores its server result');
		check((await api('sales/' + saleId)).data.payments.length === 2, 'restoring result creates no duplicate payments');
		await page.locator('#new-sale').click(); await page.locator('.product').first().waitFor();
		await page.locator(`[data-product-id="${fixture.products[1]}"]`).click(); await customer();
		let droppedPrepare = false;
		await page.route('**/*', async (route) => {
			const request = route.request(); const url = new URL(request.url()); const rest = url.searchParams.get('rest_route') || url.pathname;
			if (!droppedPrepare && request.method() === 'POST' && /\/universal-pos\/v1\/sales\/?$/.test(rest)) { droppedPrepare = true; await route.fetch(); await route.abort('failed'); }
			else await route.continue();
		});
		await page.locator('#prepare-sale').click(); await page.locator('#recovery-section').waitFor({state: 'visible'});
		check(droppedPrepare, 'test dropped a real preparation response after server commit');
		await page.unroute('**/*'); await page.locator('#check-result').click(); await page.locator('#payment-section').waitFor({state: 'visible'});
		check(await page.locator('#cancel-sale').isEnabled(), 'uncertain preparation is recovered from server without another sale');
		const recoveredId = new URLSearchParams(new URL(page.url()).hash.slice(1)).get('sale');
		await page.locator('#received').check();
		let droppedPayment = false;
		await page.route('**/*', async (route) => {
			const request = route.request(); const url = new URL(request.url()); const rest = url.searchParams.get('rest_route') || url.pathname;
			if (!droppedPayment && request.method() === 'POST' && /\/payments\/?$/.test(rest)) { droppedPayment = true; await route.fetch(); await route.abort('failed'); }
			else await route.continue();
		});
		await page.locator('#pay-sale').click(); await page.locator('#recovery-section').waitFor({state: 'visible'});
		check(droppedPayment, 'test dropped a real payment response after server commit');
		await page.unroute('**/*'); await page.locator('#check-result').click(); await page.locator('#complete-section').waitFor({state: 'visible'});
		check((await api('sales/' + recoveredId)).data.payments.length === 1, 'uncertain payment recovery keeps exactly one recorded payment');
		await page.locator('#new-sale').click(); await page.locator(`[data-product-id="${fixture.products[2]}"]`).click(); await customer();
		let lateRequest;
		await page.route('**/*', async (route) => {
			const request = route.request(); const rest = new URL(request.url()).searchParams.get('rest_route') || new URL(request.url()).pathname;
			if (!lateRequest && request.method() === 'POST' && /\/universal-pos\/v1\/sales\/?$/.test(rest)) { lateRequest = {body: request.postDataJSON(), key: request.headers()['idempotency-key']}; await route.abort('failed'); }
			else await route.continue();
		});
		await page.locator('#prepare-sale').click(); await page.locator('#recovery-section').waitFor({state: 'visible'}); await page.unroute('**/*');
		await page.locator('#abandon-attempt').click(); await page.locator('#customer-form').waitFor({state: 'visible'});
		check((await api('sales', lateRequest.body, {'Idempotency-Key': lateRequest.key})).status === 409, 'abandoning an unresolved attempt prevents a delayed POST from creating a ghost sale');
		await page.locator(`[data-product-id="${fixture.products[3]}"]`).click(); await customer(); await page.locator('#prepare-sale').click(); await page.locator('#payment-section').waitFor({state: 'visible'});
		const unpaidId = new URLSearchParams(new URL(page.url()).hash.slice(1)).get('sale');
		const pendingReceipt = new URL(fixture.baseUrl); pendingReceipt.searchParams.set('upos_receipt', unpaidId);
		check((await contexts.cashier.request.get(pendingReceipt.href)).status() === 409, 'unpaid orders cannot issue a paid receipt');
		await page.locator('#cancel-sale').click(); await page.locator('#complete-section').waitFor({state: 'visible'});
		check((await api('sales/' + unpaidId)).data.status === 'cancelled', 'cashier can cancel an unpaid server order');
		await page.locator('#new-sale').click();
		await contexts.cashier.setOffline(true); await delay(150);
		check((await page.locator('#connection').textContent()).includes('اتصال') && await page.locator('#prepare-sale').isDisabled(), 'offline state disables preparation and explains connection status');
		await contexts.cashier.setOffline(false);
		check(await page.evaluate(() => localStorage.length === 0 && sessionStorage.length === 0), 'cashier stores no cart, customer or payment queue in browser storage');
		await page.locator('#recent-sales').evaluate((item) => item.open = true);
		await page.locator('.recent-row').first().waitFor();
		check(await page.locator('.recent-row').count() >= 3, 'cashier can retrieve recent server sales and receipt links');
		check(errors.length === 0, 'no uncaught JavaScript errors: ' + errors.join('; '));
		console.log(`Completed ${checks} browser checks.`);
	} finally { await browser.close(); }
})().catch((error) => { console.error(error.stack); process.exit(1); });
