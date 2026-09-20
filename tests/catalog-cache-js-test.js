'use strict';
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const code = fs.readFileSync(require('node:path').join(__dirname, '../cart-cat/assets/js/catalog-cache.js'), 'utf8');
let count = 0;
function equal(actual, expected) { count++; assert.equal(actual, expected); }
function harness(responder) {
	let now = 100000, timerId = 0; const calls = [], timers = new Map(), window = {};
	vm.runInNewContext(code, { window, Date: { now: () => now }, AbortController, DOMException, setTimeout: (fn) => { timers.set(++timerId, fn); return timerId; }, clearTimeout: (id) => timers.delete(id) });
	const api = async (path, data, signal) => { calls.push({ path, data, signal }); return responder(path, signal); };
	return { cache: window.CartCatCatalog.create(api), calls, timers, advance: (ms) => { now += ms; } };
}
const product = (id, name, sku = '') => ({ id, name, sku, price: '42.00', stock_status: 'instock', type: 'simple' });
const page = (products, extra = {}) => ({ products, revision: 'generation-one', page: 0, next_page: null, complete: true, partial: false, max_age: 60, ...extra });
(async () => {
	const h = harness((path) => path.includes('page=1') ? page([product(2, 'Gift Membership')], { page: 1 }) : path.includes('check=1') ? { revision: 'generation-one', not_modified: true } : path.startsWith('products/index') ? page([{ ...product(1, '2025 Rosé', 'ROSE'), search_terms: 'BOTTLE-750-25' }], { next_page: 1, complete: false }) : { products: [product(99, 'Fresh server result')] });
	equal(h.cache.isReady(), false);
	const first = h.cache.warm(), second = h.cache.warm(); equal(first, second); equal(h.cache.isReady(), false); await first;
	equal(h.cache.isReady(), true); equal((await h.cache.search('rose')).cached, true);
	equal(h.calls.length, 2); equal(h.timers.size, 0);
	equal((await h.cache.search('rose')).products[0].id, 1);
	equal((await h.cache.search('2025 rosé')).products[0].id, 1);
	equal((await h.cache.search('BOTTLE-750')).products[0].id, 1);
	equal((await h.cache.search('gift')).products[0].id, 2);
	equal((await h.cache.search('absent')).products.length, 0);
	equal(h.calls.length, 2); equal('search_terms' in (await h.cache.search('rose')).products[0], false);
	const cancel = new AbortController(); cancel.abort(); count++; await assert.rejects(h.cache.search('rose', cancel.signal), /canceled/); equal(h.calls.length, 2);
	h.advance(61000); equal(h.cache.isReady(), false); const refresh = await h.cache.search('rose'); equal(refresh.products[0].id, 99); equal(refresh.cached, undefined); await h.cache.warm(); equal(h.cache.isReady(), true);
	equal(h.calls.filter((call) => call.path.includes('check=1')).length, 1); equal((await h.cache.search('rose')).products[0].id, 1);
	for (const mode of ['overflow', 'partial', 'outage', 'changed']) {
		const fallback = harness((path) => {
			if (!path.startsWith('products/index')) return { products: [product(88, 'Authoritative search')] };
			if (mode === 'outage') throw new Error('Unavailable');
			if (mode === 'changed' && path.includes('page=1')) return page([], { revision: 'generation-two' });
			return page([product(4, 'Incomplete hint')], mode === 'changed' ? { next_page: 1, complete: false } : { complete: mode !== 'overflow', partial: mode === 'partial' });
		});
		await fallback.cache.warm(); equal(fallback.cache.isReady(), false); const fresh = await fallback.cache.search('hint'); equal(fresh.products[0].id, 88); equal(fresh.cached, undefined); equal(fallback.timers.size, 0);
	}
	const stock = harness(() => page([{ ...product(3, 'Wine awaiting a fresh stock check'), stock_status: 'outofstock' }]));
	await stock.cache.warm(); const stockHint = await stock.cache.search('wine'); equal(stockHint.cached, true); equal(stockHint.products[0].stock_status, 'outofstock');
	const many = harness(() => page(Array.from({ length: 80 }, (_, i) => product(i + 1, `Wine ${i}`, i === 70 ? 'Wine' : ''))));
	await many.cache.warm(); const matches = await many.cache.search('wine'); equal(matches.products.length, 30); equal(matches.products[0].id, 71);
	const cold = harness((path) => path.startsWith('products/index') ? page([product(1, 'Wine')]) : { products: [product(7, 'Fresh match')] });
	equal((await cold.cache.search('wine')).products[0].id, 7); await cold.cache.warm(); equal((await cold.cache.search('wine')).products[0].id, 1);
	const full = harness((path) => { const index = Number(new URL(path, 'https://example.test/').searchParams.get('page') || 0); return page(Array.from({ length: 250 }, (_, i) => product(index * 250 + i + 1, `Wine ${index * 250 + i}`, `SKU-${index * 250 + i}`)), { page: index, next_page: index === 3 ? null : index + 1, complete: index === 3 }); });
	await full.cache.warm(); const started = performance.now();
	for (let i = 0; i < 1000; i++) equal((await full.cache.search(`SKU-${i}`)).products[0].id, i + 1);
	const elapsed = performance.now() - started; equal(full.calls.length, 4);
	console.log(`Cart Cat catalog cache JavaScript: ${count} assertions passed. 1000 local lookups over 1000 products=${elapsed.toFixed(2)}ms; no search requests.`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
