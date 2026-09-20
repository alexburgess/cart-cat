/* UPS validation browser fixture: real Cart Cat template/assets with simulated REST.
 * Browser plugin not available; uses the established Playwright/Chrome runtime.
 * No real UPS lookup, order, payment, label, email, or production configuration is used. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.CART_CAT_PLAYWRIGHT || require.resolve('playwright'));
const project = path.resolve(__dirname, '..');
const template = execFileSync('php', ['-r', `define('ABSPATH',true); function esc_html_e($s,$d=''){echo htmlspecialchars($s);} function esc_attr_e($s,$d=''){echo htmlspecialchars($s);} function esc_html($s){return htmlspecialchars($s);} function esc_attr($s){return htmlspecialchars($s);} function __($s,$d=''){return $s;} include ${JSON.stringify(path.join(project, 'cart-cat/templates/admin.php'))};`], { encoding: 'utf8' });
const evidence = process.env.CART_CAT_UPS_EVIDENCE || '/private/tmp/cart-cat-ups-ui';
fs.mkdirSync(evidence, { recursive: true });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const copy = (value) => JSON.parse(JSON.stringify(value));
const postal = { address_1: '123 Test Lane', address_2: 'Suite 3', city: 'Leesburg', state: 'VA', postcode: '20175', country: 'US' };
const corrected = { address_1: '123 TEST LN', address_2: '', city: 'LEESBURG', state: 'VA', postcode: '20175-1234', country: 'US' };
const input = { customer: { reference: 'fixture-customer', first_name: 'Avery', last_name: 'Taylor', email: 'buyer@example.test', phone: '555-0100', billing: { ...postal, first_name: 'Avery', last_name: 'Taylor' }, shipping: { ...postal, first_name: 'Gift', last_name: 'Recipient', company: 'Recipient Company', email: 'recipient@example.test', phone: '555-0199' } }, lines: [{ product_id: 1, variation_id: 0, quantity: 1, addons: {}, attributes: {}, name: 'Estate Cabernet' }], discount: { type: 'percent', amount: '0', mode: 'add' }, fulfillment: { type: 'shipping', rates: { 0: 'flat_rate:1' }, heat_hold_choice: '' }, order_notes: '', order_notes_visible: false, hold_minutes: 60 };
const product = { id: 1, name: 'Estate Cabernet', price: '40.00', type: 'simple', stock_status: 'instock' };
const log = { validations: [], quotes: [], prepares: [] };
let validationDelay = 25, quoteDelay = 25, httpStatus = 200, result = { status: 'suggested', message: 'UPS suggests this address. Review the changes before applying.', candidates: [corrected] };
function draft(id) { return { draft_id: id, title: `Shipping draft ${id}`, state: 'draft', can_edit: true, version: `v${id}`, customer: { name: 'Avery Taylor' }, input: copy(input), item_count: 1, customer_name: 'Avery Taylor', email: input.customer.email, updated_at: '2026-09-18T18:00:00Z', updated_by: 'Fixture staff' }; }
function quote(data) {
	const shipping = data.fulfillment.type === 'shipping', cost = shipping ? data.customer.shipping.postcode.includes('-') ? 18 : 15 : 0;
	return { hash: JSON.stringify(data), currency: 'USD', decimals: 2, automatic_discounts: [], lines: [{ key: '1', name: product.name, quantity: 1, subtotal: '40', total: '40', meta: [] }], totals: { subtotal: '40', automatic_savings: '0', discount: '0', shipping: String(cost), fees: '0', tax: '2.40', total: String(42.40 + cost) }, rates: [{ package_index: 0, rates: [{ id: shipping ? 'flat_rate:1' : 'local_pickup:2', label: shipping ? 'UPS Ground' : 'Winery pickup', cost: String(cost), selected: true }] }], heat_hold: { required: false, choices: [], choice: '' }, customer: { name: 'Avery Taylor', roles: [], sources: [] }, shipping_status: 'available', can_submit: true, notices: [] };
}
const server = http.createServer(async (req, res) => {
	try {
		const origin = `http://${req.headers.host}`, url = new URL(req.url, origin);
		if (url.pathname.startsWith('/rest/')) {
			let body = ''; for await (const part of req) body += part;
			const data = body ? JSON.parse(body) : null, route = url.pathname.slice(6);
			res.setHeader('Content-Type', 'application/json');
			let response;
			if (route === 'shipping/validate-address') { log.validations.push(copy(data)); response = copy(result); const status = httpStatus; await sleep(validationDelay); res.statusCode = status; }
			else if (route === 'quote') { log.quotes.push(copy(data)); response = quote(data); await sleep(quoteDelay); }
			else if (/^drafts\/\d+$/.test(route)) response = draft(Number(route.split('/')[1]));
			else if (route === 'drafts') response = { drafts: [draft(1), draft(2)], page: 1, total_pages: 1, total: 2 };
			else if (/^drafts\/\d+\/prepare$/.test(route)) { log.prepares.push(copy(data)); await sleep(150); res.statusCode = 503; response = { message: 'Fixture stops before creating an order.' }; }
			else if (route === 'customers') response = { customers: [{ reference: 'same-address-customer', name: 'Another Customer', email: 'other@example.test', sources: [], roles: [] }], providerStatuses: { woocommerce: 'ok', square: 'ok' } };
			else if (route === 'customers/details') { await sleep(100); response = { ...copy(input.customer), reference: 'same-address-customer' }; }
			else if (route === 'products/index') response = { products: [product], revision: 'ups-products', complete: true, next_page: null };
			else { res.statusCode = 404; response = { message: `No fixture route: ${route}` }; }
			res.end(JSON.stringify(response)); return;
		}
		if (url.pathname.startsWith('/assets/')) {
			const file = path.resolve(project, 'cart-cat', `.${url.pathname}`);
			if (!file.startsWith(path.join(project, 'cart-cat/assets/'))) { res.statusCode = 403; res.end(); return; }
			res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : file.endsWith('.woff2') ? 'font/woff2' : 'application/javascript');
			res.end(fs.readFileSync(file)); return;
		}
		const config = { restUrl: `${origin}/rest/`, nonce: 'ups-fixture-nonce', addressSearch: { apiKey: 'fixture-only' }, hold: { default_minutes: 60, steps: [15, 30, 60], timezone: 'America/New_York' }, square: { ready: true, currency: 'USD', environment: 'sandbox' }, countries: { US: 'United States', PR: 'Puerto Rico', CA: 'Canada' }, states: { US: { VA: 'Virginia', NY: 'New York' }, PR: {}, CA: { ON: 'Ontario' } } };
		res.setHeader('Content-Type', 'text/html; charset=utf-8');
		res.end(`<!doctype html><html><head><meta charset="utf-8"><title>Cart Cat UPS fixture</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:13px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327;background:#f0f0f1;margin:0;padding:24px}button,input,select,textarea{font:inherit}.wrap{margin:auto}.button{display:inline-block;padding:6px 13px;background:#f6f7f7;color:#2271b1;border:1px solid #2271b1;border-radius:3px;cursor:pointer}.button-primary{color:#fff;background:#2271b1}.button:disabled{cursor:default;color:#a7aaad;background:#f6f7f7;border-color:#dcdcde}.button-link{border:0;background:none;color:#2271b1;cursor:pointer;padding:0}.screen-reader-text{position:absolute;left:-10000px}input,select,textarea{border:1px solid #8c8f94;padding:5px}a{color:#2271b1}@media(max-width:600px){body{padding:12px}}</style><link rel="stylesheet" href="/assets/css/admin.css"><link rel="stylesheet" href="/assets/css/icons.css"></head><body>${template}<script>window.CartCat=${JSON.stringify(config)}; window.CartCatAddress={mount:function(container,options){const control=document.createElement('button');control.type='button';control.textContent='Fixture Google address';control.addEventListener('click',()=>{if(options.isCurrent())options.onSelect({address_1:'456 Sample Avenue',address_2:'',city:'Leesburg',state:'VA',postcode:'20176',country:'US'});});container.append(control);return ()=>control.remove();}};</script><script src="/assets/js/catalog-cache.js"></script><script src="/assets/js/admin.js"></script><script src="/assets/js/icons.js"></script></body></html>`);
	} catch (error) { res.statusCode = 500; res.end(JSON.stringify({ message: error.message })); console.error(error); }
});
(async () => {
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	const origin = `http://127.0.0.1:${server.address().port}`;
	const browser = await chromium.launch({ headless: true, channel: process.env.CART_CAT_BROWSER_CHANNEL || 'chrome' });
	const errors = [];
	let page;
	try {
		page = await browser.newPage({ viewport: { width: 1440, height: 1080 } });
		page.setDefaultTimeout(12000);
		page.on('pageerror', (error) => errors.push(error.message));
		page.on('dialog', (dialog) => dialog.accept());
		const validate = page.locator('#cc-validate-ups'), status = page.locator('#cc-ups-status'), candidates = page.locator('#cc-ups-candidates');
		const idleQuote = () => page.waitForFunction(() => !document.getElementById('cc-create-order').disabled);
		const ready = async () => { await page.goto(`${origin}/?cc_draft=1`); await idleQuote(); };
		const check = async (response, delay = 25, code = 200) => { result = response; validationDelay = delay; httpStatus = code; await validate.click(); };
		const finished = () => page.waitForFunction(() => document.getElementById('cc-validate-ups').textContent === 'Validate with UPS');
		const suggestion = () => ({ status: 'suggested', message: 'UPS suggests this address. Review the changes before applying.', candidates: [corrected] });
		await ready();
		assert.equal(await page.title(), 'Cart Cat UPS fixture'); assert.equal(new URL(page.url()).origin, origin);
		await page.getByRole('heading', { name: 'Cart Cat', exact: true }).waitFor();
		assert.equal(await page.locator('vite-error-overlay, nextjs-portal').count(), 0);
		assert.equal(log.validations.length, 0, 'Loading a draft and quoting do not trigger UPS validation');
		await page.screenshot({ path: `${evidence}/initial-desktop.png` });
		await check(suggestion()); await finished();
		assert.deepEqual(log.validations.at(-1), { address: postal }, 'Only six postal address fields are submitted');
		assert.equal(await page.locator('#cc-shipping-address_1').inputValue(), postal.address_1, 'UPS suggestions are not applied automatically');
		assert.match(await candidates.innerText(), /Suite 3.*will be kept/s, 'The omitted apartment is visibly explained');
		assert(await page.locator('#cc-create-order').isEnabled(), 'An unaccepted UPS suggestion does not block preparation');
		await page.locator('#cc-shipping-panel').screenshot({ path: `${evidence}/suggestion-desktop.png` });
		await page.setViewportSize({ width: 390, height: 844 });
		await page.locator('.cc-ups-validation').screenshot({ path: `${evidence}/suggestion-mobile.png` });
		assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Mobile suggestions do not overflow');
		await page.setViewportSize({ width: 1440, height: 1080 });
		quoteDelay = 200;
		await candidates.getByRole('button', { name: 'Use suggested address', exact: true }).click();
		assert(await page.locator('#cc-create-order').isDisabled(), 'Applying a correction immediately invalidates the prior quote');
		await idleQuote(); quoteDelay = 25;
		const accepted = log.quotes.at(-1).customer.shipping;
		assert.equal(accepted.address_1, corrected.address_1); assert.equal(accepted.postcode, corrected.postcode);
		for (const field of ['first_name', 'last_name', 'company', 'email', 'phone', 'address_2']) assert.equal(accepted[field], input.customer.shipping[field], `Applying UPS preserves ${field}`);
		assert.equal(log.quotes.at(-1).customer.email, 'buyer@example.test', 'Purchaser email is unchanged');
		assert.match(await page.locator('#cc-totals').innerText(), /Shipping\s+\$18\.00/, 'Rates refresh for the corrected address');
		assert.match(await status.innerText(), /apartment or suite was kept/);
		await page.locator('#cc-shipping-postcode').fill('20176');
		assert.match(await status.innerText(), /address changed/i, 'A successful result expires after a postal edit');
		assert.equal(await candidates.locator('button').count(), 0);
		await check(suggestion(), 300); await page.locator('#cc-shipping-address_1').fill('New manual address'); await sleep(360);
		assert.equal(await page.locator('#cc-shipping-address_1').inputValue(), 'New manual address');
		assert.equal(await candidates.locator('button').count(), 0, 'Late results cannot reinstate a candidate after manual edits');
		await check(suggestion(), 300); await page.locator('#cc-copy-billing').click(); await sleep(360);
		assert.equal(await candidates.locator('button').count(), 0, 'Copy billing invalidates a pending request');
		await check(suggestion(), 300); await page.locator('#cc-shipping-fields').getByRole('button', { name: 'Fixture Google address' }).click(); await sleep(360);
		assert.equal(await page.locator('#cc-shipping-address_1').inputValue(), '456 Sample Avenue');
		assert.equal(await candidates.locator('button').count(), 0, 'Google fill invalidates a pending UPS request');
		await check(suggestion(), 300); await page.locator('[name="cc-fulfillment"][value="pickup"]').check(); await sleep(360); await page.locator('[name="cc-fulfillment"][value="shipping"]').check();
		assert.equal(await candidates.locator('button').count(), 0, 'Switching to pickup invalidates old UPS results');
		await ready();
		await check(suggestion(), 600); await page.locator('#cc-open-drafts').click();
		await page.locator('[data-draft-id="2"]').getByRole('button', { name: 'Load draft', exact: true }).click();
		await page.waitForFunction(() => !document.getElementById('cc-drafts-dialog').open); await sleep(650);
		assert.equal(await page.locator('#cc-shipping-address_1').inputValue(), postal.address_1);
		assert.equal(await candidates.locator('button').count(), 0, 'Switching drafts discards the old result even for the identical address');
		await check(suggestion(), 900); await page.locator('#cc-customer-search').fill('Another');
		await page.locator('#cc-customer-results .cc-search-result').click(); await sleep(950);
		assert.equal(await candidates.locator('button').count(), 0, 'Switching customers discards the old result even for the identical address');
		await check({ status: 'valid', message: 'UPS recognized this address.', candidates: [] }); await finished();
		assert.match(await status.innerText(), /recognized/); assert(await page.locator('#cc-create-order').isEnabled());
		await page.locator('#cc-shipping-email').fill('different-recipient@example.test');
		assert.match(await status.innerText(), /recognized/, 'Recipient contact edits do not misrepresent the unchanged postal validation');
		for (const outcome of ['unmatched', 'unavailable', 'unsupported']) {
			await check({ status: outcome, message: `Fixture ${outcome} result`, candidates: [] }); await finished(); await idleQuote();
			assert.match(await status.innerText(), new RegExp(outcome)); assert(await page.locator('#cc-create-order').isEnabled(), `${outcome} remains advisory`);
		}
		await check({ message: 'Enter a complete postal address.' }, 25, 400); await finished();
		assert.match(await status.innerText(), /complete postal address/); assert(await validate.isEnabled(), 'Validation errors allow retry');
		await page.locator('#cc-shipping-country').selectOption('CA');
		assert(await validate.isDisabled()); assert.match(await status.innerText(), /United States and Puerto Rico/);
		await page.locator('#cc-shipping-country').selectOption('PR');
		assert(await validate.isEnabled(), 'Puerto Rico supports explicit validation');
		await ready();
		await check({ status: 'suggested', message: 'Review these UPS suggestions.', candidates: [corrected, { ...corrected, address_1: '125 TEST LN', address_2: 'APT 4' }] }); await finished();
		assert.equal(await candidates.getByRole('button', { name: 'Use suggested address', exact: true }).count(), 2);
		await candidates.getByRole('button', { name: 'Use suggested address', exact: true }).nth(1).click(); await idleQuote();
		assert.equal(await page.locator('#cc-shipping-address_1').inputValue(), '125 TEST LN'); assert.equal(await page.locator('#cc-shipping-address_2').inputValue(), 'APT 4');
		await check(suggestion(), 450); await page.locator('#cc-create-order').click();
		assert(await validate.isDisabled(), 'Preparing an order locks validation');
		await sleep(550);
		assert.equal(log.prepares.length, 1, 'Pending UPS validation never blocks explicit preparation');
		assert.equal(await candidates.locator('button').count(), 0, 'A late UPS result cannot mutate a locked builder');
		assert.deepEqual(errors, [], 'No browser runtime errors');
		console.log(`PASS: UPS explicit lookup, advisory outcomes, candidate review/application, apartment retention, recipient preservation, quote refresh, manual/Google/copy/customer/draft/fulfillment/preparation race guards, retry, US/PR country support, multiple candidates, desktop/mobile overflow and page health. Evidence: ${evidence}`);
	} catch (error) { if (page) await page.screenshot({ path: `${evidence}/failure.png`, fullPage: true }).catch(() => {}); throw error; }
	finally { await browser.close(); await new Promise((resolve) => server.close(resolve)); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
