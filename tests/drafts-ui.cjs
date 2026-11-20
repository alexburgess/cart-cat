/* Saved-draft browser fixture: real admin template/assets with simulated REST data.
 * Browser plugin not available; uses the existing Playwright/Chrome runtime.
 * Does not exercise production persistence, live pricing, stock holds, payments, or email. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.CART_CAT_PLAYWRIGHT || require.resolve('playwright'));
const project = path.resolve(__dirname, '..');
const template = execFileSync('php', ['-r', `define('ABSPATH',true); function esc_html_e($s,$d=''){echo htmlspecialchars($s);} function esc_attr_e($s,$d=''){echo htmlspecialchars($s);} function esc_html($s){return htmlspecialchars($s);} function esc_attr($s){return htmlspecialchars($s);} function __($s,$d=''){return $s;} include ${JSON.stringify(path.join(project, 'cart-cat/templates/admin.php'))};`], { encoding: 'utf8' });
const evidence = process.env.CART_CAT_DRAFT_EVIDENCE || '/private/tmp/cart-cat-drafts-ui';
fs.mkdirSync(evidence, { recursive: true });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const product = { id: 1, name: 'Estate Cabernet', sku: 'WINE-01', type: 'simple', price: '40.00', stock_status: 'instock', requires_configuration: false };
const copy = (value) => JSON.parse(JSON.stringify(value));
const logs = { requests: [], saves: [], updates: [], deletes: [], prepares: [], quotes: [] };
const drafts = new Map();
const createReceipts = new Map();
let nextId = 100, price = 40, conflictNext = false, updateDelay = 0, referenceSequence = 0, loseCreateResponse = true, orderGate = null;
function fullInput() {
	return { customer: { reference: 'selected-customer-reference', first_name: 'Avery', last_name: 'Taylor', email: 'avery@example.test', phone: '555-0100', billing: { first_name: 'Avery', last_name: 'Taylor', country: 'US', state: 'VA', postcode: '20175', address_1: '123 Test Lane', city: 'Leesburg', email: 'avery@example.test', phone: '555-0100' }, shipping: { first_name: 'Gift', last_name: 'Recipient', email: 'recipient@example.test', phone: '555-0199', country: 'US', state: 'VA', postcode: '20175', address_1: '45 Delivery Lane', address_2: 'Suite 3', city: 'Leesburg' } }, lines: [{ product_id: 1, variation_id: 0, quantity: 2, addons: {}, attributes: {}, name: product.name }], discount: { type: 'percent', amount: '0', mode: 'add' }, fulfillment: { type: 'pickup', rates: { 0: 'local_pickup:2' }, heat_hold_choice: '' }, order_notes: 'Dinner on Friday', order_notes_visible: false, hold_minutes: 60 };
}
function draftRecord(id, title, input, version = 1) {
	return { draft_id: id, title, version: `draft-${id}-v${version}`, state: 'draft', can_edit: true, input: copy(input), customer: { name: [input.customer?.first_name, input.customer?.last_name].filter(Boolean).join(' ') }, customer_name: [input.customer?.first_name, input.customer?.last_name].filter(Boolean).join(' '), email: input.customer?.email || '', item_count: input.lines?.reduce((sum, line) => sum + Number(line.quantity), 0) || 0, updated_at: '2026-09-18T18:00:00+00:00', updated_by: 'Fixture staff', _version: version };
}
function draftDetail(draft) {
	const detail = copy(draft);
	// Production reissues a session-bound customer reference on every save/load response.
	if (detail.input.customer.reference) detail.input.customer.reference = `refreshed-customer-reference-${++referenceSequence}`;
	// PHP stores draft quantities as text and returns empty add-on and attribute maps as arrays.
	for (const line of detail.input.lines || []) {
		line.quantity = String(line.quantity);
		if (!Object.keys(line.addons || {}).length) line.addons = [];
		if (!Object.keys(line.attributes || {}).length) line.attributes = [];
	}
	return detail;
}
drafts.set(10, draftRecord(10, 'Cabernet dinner', fullInput()));
drafts.set(11, draftRecord(11, 'Club collection', fullInput()));
drafts.set(12, draftRecord(12, 'Weekend gift', fullInput()));
for (const id of [20, 21, 22]) drafts.set(id, draftRecord(id, `Pagination basket ${id}`, fullInput()));
const initialDraftCount = drafts.size;
function quote(input) {
	const subtotal = input.lines.reduce((sum, line) => sum + price * line.quantity, 0);
	let adjustment = 0;
	const lines = input.lines.map((line) => {
		const override = line.price_override;
		const unit = !override ? price : override.type === 'fixed' ? Number(override.value) : price * (1 - Number(override.value) / 100);
		const change = (unit - price) * line.quantity;
		adjustment += change;
		return { key: String(line.product_id), name: product.name, quantity: line.quantity, subtotal: String(price * line.quantity), total: String(unit * line.quantity), meta: [], ...(override ? { price_override: override, price_adjustment: String(change) } : {}) };
	});
	const shipping = input.fulfillment.type === 'shipping';
	const cost = shipping ? 15 : 0;
	const tax = (subtotal + adjustment) * .06;
	return { hash: Buffer.from(JSON.stringify({ input, price })).toString('base64'), currency: 'USD', decimals: 2, automatic_discounts: [], lines, totals: { subtotal: String(subtotal), automatic_savings: '0', price_adjustment: String(adjustment), discount: '0', shipping: String(cost), fees: '0', tax: String(tax), total: String(subtotal + adjustment + cost + tax) }, rates: [{ package_index: 0, rates: [{ id: shipping ? 'flat_rate:1' : 'local_pickup:2', label: shipping ? 'UPS Ground' : 'Winery pickup', cost: String(cost), selected: true }] }], heat_hold: { required: false, choices: [], choice: '' }, customer: { name: [input.customer.first_name, input.customer.last_name].filter(Boolean).join(' '), roles: [], sources: [] }, shipping_status: 'available', can_submit: input.lines.length > 0 && (!shipping || Boolean(input.customer.shipping.address_1 && input.customer.shipping.email && input.customer.shipping.phone)), notices: [] };
}
function order(origin, draft) {
	return { order_id: 700, number: '700', status: 'pending', payment_status: 'unpaid', preparation: 'ready', can_edit: true, input: copy(draft.input), review: quote(draft.input), customer: draft.customer, hold: { minutes: 60, expires_at: Math.floor(Date.now() / 1000) + 3600, expires_label: 'September 18, 2026, 3:00 PM EDT', active: true }, total: quote(draft.input).totals.total, currency: 'USD', billing: copy(draft.input.customer.billing), payment_url: `${origin}/order-pay/700`, edit_url: `${origin}/wp-admin/post.php?post=700&action=edit`, version: 'order-700-v1', payment_email: { uncertain: false, accepted: false }, paid: false };
}
let preparedDraft = null;
const server = http.createServer(async (req, res) => {
	try {
		const origin = `http://${req.headers.host}`, url = new URL(req.url, origin);
		if (url.pathname.startsWith('/rest/')) {
			let body = ''; for await (const chunk of req) body += chunk;
			const data = body ? JSON.parse(body) : null, route = url.pathname.slice(6);
			logs.requests.push({ method: req.method, route, data: copy(data), query: url.search });
			res.setHeader('Content-Type', 'application/json');
			let result;
			if (route === 'products/index') result = { products: [product], revision: 'draft-fixture-products', complete: true, next_page: null };
			else if (route === 'products') result = { products: [product] };
			else if (route === 'products/1') result = { ...product, variations: [], addons: [], sold_individually: false };
			else if (route === 'customers') result = { customers: [], providerStatuses: { woocommerce: 'ok', square: 'ok' } };
			else if (route === 'quote') { logs.quotes.push(copy(data)); await sleep(30); result = quote(data); }
			else if (route === 'drafts' && req.method === 'GET') {
				const q = (url.searchParams.get('q') || '').toLowerCase(), page = Math.max(1, Number(url.searchParams.get('page') || 1));
				await sleep(q === 'club' ? 500 : 25);
				const matching = [...drafts.values()].filter((draft) => draft.state === 'draft' && `${draft.title} ${draft.customer_name} ${draft.email} ${draft.draft_id}`.toLowerCase().includes(q)).sort((a, b) => b.draft_id - a.draft_id);
				result = { drafts: matching.slice((page - 1) * 2, page * 2).map(copy), page, total_pages: Math.max(1, Math.ceil(matching.length / 2)), total: matching.length };
			} else if (route === 'drafts' && req.method === 'POST') {
				logs.saves.push(copy(data)); assert(data.request_id, 'Draft creation is idempotent');
				const receipt = createReceipts.get(data.request_id);
				if (receipt) { assert.deepEqual(data, receipt.data, 'Uncertain save retries retain their exact request'); result = draftDetail(drafts.get(receipt.id)); }
				else { result = draftRecord(nextId++, data.title, data.input); drafts.set(result.draft_id, result); createReceipts.set(data.request_id, { id: result.draft_id, data: copy(data) }); result = draftDetail(result); }
				await sleep(100);
				if (loseCreateResponse) { loseCreateResponse = false; res.statusCode = 503; result = { message: 'The response was lost after the draft was stored.' }; }
			} else if (/^drafts\/\d+$/.test(route)) {
				const id = Number(route.split('/')[1]), existing = drafts.get(id);
				if (!existing) { res.statusCode = 404; result = { code: 'cart_cat_draft_not_found', message: 'This draft could not be found.' }; }
				else if (req.method === 'GET') result = draftDetail(existing);
				else {
					logs.updates.push({ id, ...copy(data) }); await sleep(updateDelay);
					if (conflictNext) { conflictNext = false; existing._version++; existing.version = `draft-${id}-v${existing._version}`; existing.input.order_notes = 'Changed by another staff member'; }
					if (data.version !== existing.version) { res.statusCode = 409; result = { code: 'cart_cat_draft_conflict', message: 'This draft changed in another session. Reload the saved draft before saving again.' }; }
					else { result = draftRecord(id, data.title, data.input, existing._version + 1); drafts.set(id, result); result = draftDetail(result); }
				}
			} else if (/^drafts\/\d+\/delete$/.test(route)) {
				logs.deletes.push(copy(data)); const id = Number(route.split('/')[1]); assert.equal(data.version, drafts.get(id).version); drafts.delete(id); result = { deleted: true, draft_id: id };
			} else if (/^drafts\/\d+\/prepare$/.test(route)) {
				logs.prepares.push({ route, ...copy(data) }); const id = Number(route.split('/')[1]); preparedDraft = drafts.get(id);
				assert.equal(data.version, preparedDraft.version); assert.equal(data.quote_hash, quote(data.input).hash); assert(data.request_id);
				preparedDraft.input = copy(data.input); preparedDraft.state = 'prepared'; preparedDraft.can_edit = false; preparedDraft.order_id = 700;
				await sleep(120); result = order(origin, preparedDraft);
			} else if (route === 'orders/700') { if (orderGate) await orderGate; result = order(origin, preparedDraft); }
			else if (route === 'orders/700/cards') result = { cards: [], message: 'No enabled cards.' };
			else if (route === 'orders/700/payment') result = { order_id: 700, status: 'unpaid' };
			else { res.statusCode = 404; result = { message: `Unexpected fixture request: ${req.method} ${route}` }; }
			res.end(JSON.stringify(result)); return;
		}
		if (url.pathname.startsWith('/assets/')) {
			const file = path.resolve(project, 'cart-cat', `.${url.pathname}`);
			if (!file.startsWith(path.join(project, 'cart-cat/assets/'))) { res.statusCode = 403; res.end(); return; }
			res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : file.endsWith('.woff2') ? 'font/woff2' : 'application/javascript');
			res.end(fs.readFileSync(file)); return;
		}
		const bootstrap = { restUrl: `${origin}/rest/`, nonce: 'draft-fixture-nonce', hold: { default_minutes: 60, steps: [15, 30, 60, 120], timezone: 'America/New_York' }, square: { ready: true, currency: 'USD', environment: 'sandbox' }, countries: { US: 'United States', CA: 'Canada' }, states: { US: { VA: 'Virginia', NY: 'New York' }, CA: { ON: 'Ontario' } } };
		res.setHeader('Content-Type', 'text/html; charset=utf-8');
		res.end(`<!doctype html><html><head><meta charset="utf-8"><title>Cart Cat draft fixture</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:13px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2327;background:#f0f0f1;margin:0;padding:24px}button,input,select,textarea{font:inherit}.wrap{margin:auto}.button{display:inline-block;padding:6px 13px;background:#f6f7f7;color:#2271b1;border:1px solid #2271b1;border-radius:3px;cursor:pointer}.button-primary{color:#fff;background:#2271b1}.button:disabled{cursor:default;color:#a7aaad;background:#f6f7f7;border-color:#dcdcde}.button-link{border:0;background:none;color:#2271b1;cursor:pointer;padding:0}.screen-reader-text{position:absolute;left:-10000px}input,select,textarea{border:1px solid #8c8f94;padding:5px}a{color:#2271b1}@media(max-width:600px){body{padding:12px}}</style><link rel="stylesheet" href="/assets/css/admin.css"><link rel="stylesheet" href="/assets/css/icons.css"></head><body>${template}<script>window.CartCat=${JSON.stringify(bootstrap)}; window.CartCatPayment={mount:async function(){return {tokenize:async()=>{throw new Error('Payment must not run in draft fixture')},destroy:async()=>{}}}};</script><script src="/assets/js/catalog-cache.js"></script><script src="/assets/js/address-autocomplete.js"></script><script src="/assets/js/admin.js"></script><script src="/assets/js/icons.js"></script></body></html>`);
	} catch (error) { res.statusCode = 500; res.end(JSON.stringify({ message: error.message })); console.error(error); }
});
(async () => {
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	const origin = `http://127.0.0.1:${server.address().port}`;
	const browser = await chromium.launch({ headless: true, channel: process.env.CART_CAT_BROWSER_CHANNEL || 'chrome' });
	const errors = [], confirmations = [], confirmationReplies = [];
	let page;
	try {
		page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, timezoneId: 'America/New_York' });
		page.setDefaultTimeout(12000);
		page.on('pageerror', (error) => errors.push(error.message));
		page.on('dialog', async (dialog) => { confirmations.push(dialog.message()); if (confirmationReplies.shift() === false) await dialog.dismiss(); else await dialog.accept(); });
		const list = page.locator('#cc-draft-list');
		const waitCount = async (collection, length) => { for (let i = 0; i < 120 && collection.length < length; i++) await sleep(50); assert.equal(collection.length, length); };
		const idleSave = async () => { await page.waitForFunction(() => !document.getElementById('cc-save-draft').disabled); };
		const openDrafts = async () => {
			await Promise.all([page.waitForResponse((response) => new URL(response.url()).pathname === '/rest/drafts'), page.locator('#cc-open-drafts').click()]);
			await page.locator('#cc-drafts-dialog').waitFor();
			await page.waitForFunction(() => !document.getElementById('cc-draft-list').textContent.includes('Loading drafts'));
		};
		const search = async (text) => {
			const current = await page.locator('#cc-draft-search').inputValue();
			if (current === text) return;
			await Promise.all([page.waitForResponse((response) => { const url = new URL(response.url()); return url.pathname === '/rest/drafts' && (url.searchParams.get('q') || '') === text; }), page.locator('#cc-draft-search').fill(text)]);
			await page.waitForFunction(() => !document.getElementById('cc-draft-list').textContent.includes('Loading drafts'));
		};
		const load = async (id, title) => { await search(title); await list.locator(`[data-draft-id="${id}"]`).getByRole('button', { name: 'Load draft', exact: true }).click(); await page.waitForFunction(() => !document.getElementById('cc-drafts-dialog').open); await idleSave(); };
		const noCommerceWrites = () => assert.equal(logs.requests.filter((request) => request.method === 'POST' && (/^orders(?:\/|$)/.test(request.route) || /\/(email|pay|complete)$/.test(request.route))).length, 0, 'Saving/loading drafts must not create an order, send email, or take payment');
		await page.goto(origin);
		assert.equal(await page.title(), 'Cart Cat draft fixture');
		assert.equal(new URL(page.url()).origin, origin);
		await page.getByRole('heading', { name: 'Cart Cat', exact: true }).waitFor();
		assert.equal(await page.locator('vite-error-overlay, nextjs-portal').count(), 0);
		assert(!await page.getByRole('checkbox', { name: 'SOMMinar Order', exact: true }).isChecked());
		await page.getByRole('checkbox', { name: 'SOMMinar Order', exact: true }).check();
		await page.locator('#cc-draft-title').fill('Unfinished gift');
		await page.locator('[name="cc-fulfillment"][value="shipping"]').check();
		await page.locator('#cc-shipping-first_name').fill('Gift recipient');
		await page.locator('#cc-shipping-email').fill('not-an-email');
		assert(await page.locator('#cc-create-order').isDisabled());
		await page.locator('#cc-save-draft').click(); await waitCount(logs.saves, 1); await idleSave();
		const firstId = 100;
		assert.equal(drafts.size, initialDraftCount + 1, 'The server stored the first save before returning its uncertain response');
		assert(await page.locator('#cc-builder').evaluate((fieldset) => fieldset.disabled), 'An uncertain save locks the draft fields until its result is recovered');
		assert(await page.locator('#cc-shipping-email').isDisabled(), 'The fieldset lock prevents recipient edits during recovery');
		assert(await page.locator('#cc-open-drafts').isDisabled(), 'An uncertain save cannot be abandoned by loading another draft');
		assert(await page.locator('#cc-draft-title').isDisabled());
		assert.match(await page.locator('#cc-save-draft').innerText(), /Retry saving draft/);
		assert.match(await page.locator('#cc-draft-status').innerText(), /Retry saving this same draft/);
		await page.locator('#cc-save-draft').click(); await waitCount(logs.saves, 2); await idleSave();
		assert.deepEqual(logs.saves[1], logs.saves[0], 'Recovery sends the same request ID, title, and input');
		assert.equal(drafts.size, initialDraftCount + 1, 'Retrying the uncertain save does not create a second draft');
		assert(!await page.locator('#cc-builder').evaluate((fieldset) => fieldset.disabled));
		assert.equal(drafts.get(firstId).title, 'Unfinished gift');
		assert.equal(drafts.get(firstId).input.order_type, 'somminar', 'SOMMinar selection survives an uncertain draft save retry');
		assert.equal(drafts.get(firstId).input.lines.length, 0);
		assert.equal(drafts.get(firstId).input.customer.shipping.email, 'not-an-email', 'Drafts can preserve incomplete recipient details');
		assert(new URL(page.url()).searchParams.get('cc_draft') === String(firstId), 'Saved draft has a reloadable URL');
		noCommerceWrites();
		await page.goto(origin); await openDrafts(); await load(firstId, 'Unfinished');
		assert.equal(await page.locator('#cc-draft-title').inputValue(), 'Unfinished gift');
		assert(await page.locator('#cc-somminar-order').isChecked(), 'Saved SOMMinar selection is restored on load');
		assert.equal(await page.locator('#cc-shipping-first_name').inputValue(), 'Gift recipient');
		assert.equal(await page.locator('#cc-shipping-email').inputValue(), 'not-an-email');
		assert.equal(await page.locator('.cc-line').count(), 0);
		await page.locator('#cc-order-notes').fill('Keep the original gift packaging');
		const versionBeforeUpdate = drafts.get(firstId).version; updateDelay = 120;
		await page.locator('#cc-save-draft').evaluate((button) => { button.click(); button.click(); });
		await waitCount(logs.updates, 1); await idleSave(); updateDelay = 0;
		assert.equal(logs.updates[0].id, firstId); assert.equal(logs.updates[0].version, versionBeforeUpdate); assert(logs.updates[0].request_id);
		assert.equal(logs.saves.length, 2, 'Saving again updates the same draft');
		assert.notEqual(drafts.get(firstId).version, versionBeforeUpdate);
		await page.reload(); await idleSave();
		await page.waitForFunction(() => document.getElementById('cc-order-notes').value === 'Keep the original gift packaging');
		conflictNext = true;
		await page.locator('#cc-order-notes').fill('My unsaved local changes');
		await page.locator('#cc-save-draft').click(); await waitCount(logs.updates, 2); await idleSave();
		await page.waitForFunction(() => document.getElementById('cc-draft-status').textContent.includes('changed in another session'));
		assert.equal(await page.locator('#cc-order-notes').inputValue(), 'My unsaved local changes', 'A conflict preserves local edits for review');
		await page.screenshot({ path: path.join(evidence, 'draft-conflict-desktop.png'), fullPage: true });
		confirmationReplies.push(false); await page.locator('#cc-new-draft').click();
		assert.equal(await page.locator('#cc-order-notes').inputValue(), 'My unsaved local changes', 'Canceling reset retains unsaved draft work');
		assert(confirmations.length > 0, 'Unsaved edits require a discard confirmation');
		await openDrafts(); confirmationReplies.push(true); await load(firstId, 'Unfinished');
		assert.equal(await page.locator('#cc-order-notes').inputValue(), 'Changed by another staff member');
		await page.locator('#cc-order-notes').fill('Merged after reviewing the newer saved draft');
		await page.locator('#cc-save-draft').click(); await waitCount(logs.updates, 3); await idleSave();
		assert.equal(drafts.get(firstId).input.order_notes, 'Merged after reviewing the newer saved draft');
		await page.locator('#cc-new-draft').click();
		assert.equal(await page.locator('#cc-draft-title').inputValue(), '');
		assert(!await page.locator('#cc-somminar-order').isChecked(), 'A new draft resets to a regular order');
		assert.equal(await page.locator('.cc-line').count(), 0);
		assert(!new URL(page.url()).searchParams.has('cc_draft'));
		await page.locator('#cc-draft-title').fill('Separate saved cart');
		await page.locator('#cc-save-draft').click(); await waitCount(logs.saves, 3); await idleSave();
		assert(drafts.has(firstId) && drafts.has(101), 'Starting a new draft leaves the original saved draft intact');
		await openDrafts(); await search('');
		await page.locator('#cc-drafts-next').waitFor(); assert(await page.locator('#cc-drafts-next').isEnabled());
		const firstPageIds = await list.locator('[data-draft-id]').evaluateAll((rows) => rows.map((row) => row.dataset.draftId));
		await Promise.all([page.waitForResponse((response) => new URL(response.url()).pathname === '/rest/drafts' && new URL(response.url()).searchParams.get('page') === '2'), page.locator('#cc-drafts-next').click()]);
		await list.locator('[data-draft-id="22"]').waitFor();
		assert(await page.locator('#cc-drafts-prev').isEnabled());
		const secondPageIds = await list.locator('[data-draft-id]').evaluateAll((rows) => rows.map((row) => row.dataset.draftId));
		assert.notDeepEqual(secondPageIds, firstPageIds);
		await search('Cabernet');
		await list.locator('[data-draft-id="10"]').waitFor();
		assert.equal(await list.locator('[data-draft-id]').count(), 1);
		await Promise.all([page.waitForRequest((request) => new URL(request.url()).pathname === '/rest/drafts' && new URL(request.url()).searchParams.get('q') === 'Club'), page.locator('#cc-draft-search').fill('Club')]);
		await search('Cabernet'); await sleep(550);
		assert.equal(await list.locator('[data-draft-id="10"]').count(), 1, 'A late earlier search cannot replace the current query');
		assert.equal(await list.locator('[data-draft-id="11"]').count(), 0);
		await page.setViewportSize({ width: 390, height: 844 });
		assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'Draft search dialog fits a narrow viewport');
		await page.screenshot({ path: path.join(evidence, 'draft-search-mobile.png'), fullPage: true });
		await page.setViewportSize({ width: 1440, height: 1000 });
		await search('Separate'); confirmationReplies.push(false);
		await list.locator('[data-draft-id="101"]').getByRole('button', { name: 'Delete draft', exact: true }).click();
		assert.equal(logs.deletes.length, 0, 'Canceling deletion makes no mutation');
		confirmationReplies.push(true); await list.locator('[data-draft-id="101"]').getByRole('button', { name: 'Delete draft', exact: true }).click();
		await waitCount(logs.deletes, 1); assert(!drafts.has(101));
		await page.waitForFunction(() => !document.getElementById('cc-open-drafts').disabled);
		await search('Pagination');
		await Promise.all([page.waitForResponse((response) => { const url = new URL(response.url()); return url.pathname === '/rest/drafts' && url.searchParams.get('q') === 'Pagination' && url.searchParams.get('page') === '2'; }), page.locator('#cc-drafts-next').click()]);
		await list.locator('[data-draft-id="20"]').waitFor();
		assert.equal(await list.locator('[data-draft-id]').count(), 1, 'The final page contains one remaining row');
		confirmationReplies.push(true); await list.locator('[data-draft-id="20"]').getByRole('button', { name: 'Delete draft', exact: true }).click();
		await waitCount(logs.deletes, 2);
		await list.locator('[data-draft-id="22"]').waitFor();
		assert.equal(await page.locator('#cc-drafts-page').innerText(), 'Page 1 of 1', 'Deleting the only last-page row returns to a valid page');
		assert.equal(await list.locator('[data-draft-id]').count(), 2);
		assert(await page.locator('#cc-drafts-prev').isDisabled()); assert(await page.locator('#cc-drafts-next').isDisabled());
		await page.locator('#cc-close-drafts').click(); noCommerceWrites();
		// Saved drafts reprice on load; their earlier product hint is not a live quote.
		price = 55; const quotesBefore = logs.quotes.length;
		await openDrafts(); await load(10, 'Cabernet');
		await page.waitForFunction(() => !document.getElementById('cc-create-order').disabled);
		assert(logs.quotes.length > quotesBefore, 'Loading asks the backend for current prices');
		assert.match(await page.locator('#cc-totals').innerText(), /116\.60/);
		assert.equal(await page.locator('#cc-customer-email').inputValue(), 'avery@example.test');
		assert.equal(await page.locator('#cc-shipping-email').inputValue(), 'recipient@example.test');
		noCommerceWrites(); assert.equal(logs.prepares.length, 0);
		assert.equal(logs.requests.filter((request) => /^orders(?:\/|$)/.test(request.route)).length, 0, 'Unprepared drafts never request order or payment endpoints');
		const editPrice = page.getByRole('button', { name: 'Edit price for Estate Cabernet' });
		const priceDialog = page.getByRole('dialog', { name: 'Edit item price' });
		const adjustmentType = priceDialog.getByLabel('Price adjustment');
		const waitForTotal = (expected) => page.waitForFunction((total) => document.getElementById('cc-totals').textContent.includes(total) && !document.getElementById('cc-create-order').disabled, expected);
		await editPrice.click();
		await adjustmentType.selectOption('percent');
		await priceDialog.getByLabel('Discount (%)').fill('101');
		await priceDialog.getByRole('button', { name: 'Apply', exact: true }).click();
		assert(await priceDialog.isVisible(), 'A percentage above 100 is rejected before submission');
		await priceDialog.getByLabel('Discount (%)').fill('20');
		await priceDialog.getByRole('button', { name: 'Apply', exact: true }).click();
		assert(await editPrice.evaluate((button) => button === document.activeElement), 'Applying restores focus to the item price button');
		await waitForTotal('93.28');
		assert.equal(logs.quotes.at(-1).lines[0].price_override.type, 'percent');
		assert.equal(Number(logs.quotes.at(-1).lines[0].price_override.value), 20);
		assert.match(await page.locator('#cc-totals').innerText(), /22\.00/, 'The item adjustment is listed in the totals');
		await page.screenshot({ path: path.join(evidence, 'item-price-desktop.png') });
		await page.setViewportSize({ width: 390, height: 844 });
		await editPrice.click();
		assert.equal(await priceDialog.getByLabel('Discount (%)').inputValue(), '20');
		await adjustmentType.selectOption('fixed');
		await priceDialog.getByLabel('Unit price (USD)').fill('17.25');
		assert(await priceDialog.evaluate((dialog) => { const rect = dialog.getBoundingClientRect(); return rect.left >= 0 && rect.right <= window.innerWidth && rect.bottom <= window.innerHeight; }), 'The item price dialog fits a narrow viewport');
		await page.screenshot({ path: path.join(evidence, 'item-price-mobile.png') });
		await priceDialog.getByRole('button', { name: 'Cancel', exact: true }).click();
		assert(await editPrice.evaluate((button) => button === document.activeElement), 'Canceling restores focus without applying changes');
		await waitForTotal('93.28');
		await page.setViewportSize({ width: 1440, height: 1000 });
		await page.locator('#cc-product-search').fill('Estate Cabernet');
		await page.locator('#cc-product-results').getByRole('button', { name: /Estate Cabernet/ }).click();
		await waitForTotal('151.58');
		assert.equal(await editPrice.count(), 2, 'Adding the same product at catalog price leaves the adjusted row separate');
		assert(!logs.quotes.at(-1).lines[1].price_override, 'New units do not inherit the existing override');
		await page.locator('#cc-save-draft').click(); await waitCount(logs.updates, 4); await idleSave();
		await page.reload();
		await waitForTotal('151.58');
		assert.deepEqual(logs.quotes.at(-1).lines.map((line) => line.quantity), ['2', '1'], 'Both quantities restore from the saved draft as strings');
		await editPrice.first().click();
		await priceDialog.getByRole('button', { name: 'Reset price', exact: true }).click();
		await waitForTotal('174.90');
		assert(await editPrice.evaluate((button) => button === document.activeElement), 'Reset focuses the remaining row after it merges');
		assert.equal(logs.quotes.at(-1).lines.length, 1, 'Reset combines otherwise identical catalog rows');
		assert.equal(logs.quotes.at(-1).lines[0].quantity, 3, 'Reset adds the restored quantities numerically');
		await page.getByRole('spinbutton', { name: 'Quantity for Estate Cabernet' }).fill('2');
		await waitForTotal('116.60');
		assert(!logs.quotes.at(-1).lines[0].price_override, 'Reset removes the item override from the quote input');
		await editPrice.click();
		await adjustmentType.selectOption('fixed');
		await priceDialog.getByLabel('Unit price (USD)').fill('17.25');
		await priceDialog.getByRole('button', { name: 'Apply', exact: true }).click();
		await waitForTotal('36.57');
		await page.setViewportSize({ width: 1440, height: 1000 });
		await page.getByRole('checkbox', { name: 'SOMMinar Order', exact: true }).check();
		await page.waitForFunction(() => !document.getElementById('cc-create-order').disabled);
		assert.equal(logs.quotes.at(-1).order_type, 'somminar', 'Changing the type gets a fresh quote');
		await page.setViewportSize({ width: 390, height: 844 });
		await page.locator('#cc-somminar-order').scrollIntoViewIfNeeded();
		assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'SOMMinar control fits a narrow viewport');
		await page.screenshot({ path: path.join(evidence, 'somminar-mobile.png') });
		await page.setViewportSize({ width: 1440, height: 1000 });
		const quoteBeforeSave = copy(logs.quotes.at(-1)), quoteCountBeforeSave = logs.quotes.length;
		await page.locator('#cc-save-draft').click(); await waitCount(logs.updates, 5); await idleSave();
		await page.waitForFunction(() => !document.getElementById('cc-create-order').disabled);
		assert(logs.quotes.length > quoteCountBeforeSave, 'Saving a linked-customer draft requotes after its reference rotates');
		assert.notEqual(logs.quotes.at(-1).customer.reference, quoteBeforeSave.customer.reference);
		assert.equal(logs.updates.at(-1).input.customer.reference, quoteBeforeSave.customer.reference, 'Save and subsequent fresh quote use their own session-bound references');
		assert.equal(Number(logs.updates.at(-1).input.lines[0].price_override.value), 17.25);
		await page.reload();
		await waitForTotal('36.57');
		await editPrice.click();
		assert.equal(await adjustmentType.inputValue(), 'fixed');
		assert.equal(await priceDialog.getByLabel('Unit price (USD)').inputValue(), '17.25');
		await priceDialog.getByRole('button', { name: 'Cancel', exact: true }).click();
		await page.screenshot({ path: path.join(evidence, 'draft-loaded-desktop.png'), fullPage: true });
		await page.locator('#cc-create-order').evaluate((button) => { button.click(); button.click(); });
		await waitCount(logs.prepares, 1);
		await page.locator('#cc-payment-panel').waitFor();
		assert.equal(logs.prepares[0].route, 'drafts/10/prepare');
		assert.equal(logs.prepares[0].input.order_type, 'somminar', 'Prepare sends the selected order type');
		assert.equal(logs.prepares[0].input.lines[0].price_override.type, 'fixed');
		assert.equal(Number(logs.prepares[0].input.lines[0].price_override.value), 17.25);
		await page.getByRole('heading', { name: 'SOMMinar™ Pickup', exact: true }).waitFor();
		await page.screenshot({ path: path.join(evidence, 'somminar-prepared-desktop.png'), fullPage: true });
		assert.equal(new URL(page.url()).searchParams.get('cc_order'), '700');
		assert(!new URL(page.url()).searchParams.has('cc_draft'));
		noCommerceWrites();
		let releaseOrder;
		orderGate = new Promise((resolve) => { releaseOrder = resolve; });
		await page.goto(`${origin}/?cc_order=700`, { waitUntil: 'domcontentloaded' });
		assert(await page.locator('#cc-open-drafts').isDisabled(), 'Saved drafts are blocked while the initial order response is pending');
		assert(await page.locator('#cc-builder').evaluate((fieldset) => fieldset.disabled));
		releaseOrder(); orderGate = null;
		await page.locator('#cc-payment-panel').waitFor();
		await page.goto(`${origin}/?cc_draft=10`);
		await page.locator('#cc-payment-panel').waitFor();
		assert.equal(new URL(page.url()).searchParams.get('cc_order'), '700', 'Reopening a converted draft returns its existing order');
		assert.equal(logs.prepares.length, 1, 'Reopening the draft cannot prepare a second order');
		noCommerceWrites(); assert.deepEqual(errors, [], 'No browser runtime errors');
		console.log('PASS: incomplete saved drafts, exact uncertain-save retry without duplicate draft, pending-save lock, separate shipping contacts, searchable reload, same-ID/versioned updates, duplicate-click guard, conflict recovery, unsaved-work confirmation, new draft isolation, pagination and last-page deletion clamp, delete confirmation, linked-customer reference refresh, current-price quotes, per-item discounts and unit prices, duplicate product isolation and reset, focus restoration, draft-specific prepare once, initial order-load lock, prepared-draft redirect, no payment/email/order creation during save/load, and mobile layout.');
		console.log(`Screenshots: ${evidence}`);
	} catch (error) {
		console.error('Browser errors:', errors);
		console.error('Draft status:', await page?.locator('#cc-draft-status').textContent().catch(() => 'unavailable'));
		console.error('Recent fixture requests:', logs.requests.slice(-8).map(({ method, route, query }) => ({ method, route, query })));
		await page?.screenshot({ path: path.join(evidence, 'draft-failure.png'), fullPage: true }).catch(() => {});
		throw error;
	} finally { await browser.close(); server.closeAllConnections(); await new Promise((resolve) => server.close(resolve)); }
})().catch((error) => { console.error(error); server.close(); process.exitCode = 1; });
