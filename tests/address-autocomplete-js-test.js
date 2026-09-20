'use strict';
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const code = fs.readFileSync(require('node:path').join(__dirname, '../cart-cat/assets/js/address-autocomplete.js'), 'utf8');
let count = 0;
function equal(actual, expected) { count++; assert.deepEqual(JSON.parse(JSON.stringify(actual)), expected); }
function ok(value) { count++; assert.ok(value); }
const tick = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
const defer = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
function harness({ loaded = true, existingScript = false, importError = false } = {}) {
	const scripts = [], widgets = [], imports = [], timeouts = new Map(), intervals = new Map(); let timerId = 0;
	class Node {
		constructor(tag) { this.tagName = tag; this.children = []; this.listeners = {}; this.attributes = {}; this.isConnected = true; this.textContent = ''; }
		setAttribute(name, value) { this.attributes[name] = value; }
		appendChild(node) { this.children.push(node); node.parentNode = this; if (this.tagName === 'head' && node.tagName === 'script') scripts.push(node); }
		insertBefore(node, before) { this.children.splice(this.children.indexOf(before), 0, node); node.parentNode = this; }
		addEventListener(name, handler) { (this.listeners[name] ||= new Set()).add(handler); }
		removeEventListener(name, handler) { this.listeners[name]?.delete(handler); }
		emit(name, event = {}) { return Promise.all(Array.from(this.listeners[name] || []).map((handler) => handler(event))); }
		remove() { this.isConnected = false; if (this.parentNode) this.parentNode.children = this.parentNode.children.filter((child) => child !== this); }
	}
	class Widget extends Node { constructor(options) { super('gmp-place-autocomplete'); this.options = options; widgets.push(this); } }
	const document = { scripts, head: new Node('head'), createElement: (tag) => new Node(tag), querySelector: () => null };
	if (existingScript) { const script = new Node('script'); script.src = 'https://maps.googleapis.com/maps/api/js?key=another-plugin'; document.head.appendChild(script); }
	const window = { location: { href: 'https://shop.example.test/wp-admin/admin.php?page=cart-cat' } };
	const installMaps = () => { window.google = { maps: { importLibrary: async (name) => { imports.push(name); if (importError) throw new Error('Denied'); return { PlaceAutocompleteElement: Widget }; } } }; };
	if (loaded) installMaps();
	vm.runInNewContext(code, { window, document, URL, URLSearchParams, setTimeout: (fn) => { timeouts.set(++timerId, fn); return timerId; }, clearTimeout: (id) => timeouts.delete(id), setInterval: (fn) => { intervals.set(++timerId, fn); return timerId; }, clearInterval: (id) => intervals.delete(id) });
	const start = (options = {}) => {
		const container = new Node('div'), selected = [];
		const cleanup = window.CartCatAddress.mount(container, { apiKey: 'browser-key', country: 'US', onSelect: (address) => selected.push(address), ...options });
		return { container, selected, cleanup, status: () => container.children.find((node) => node.tagName === 'p')?.textContent || '' };
	};
	return { window, scripts, widgets, imports, timeouts, intervals, installMaps, start };
}
const component = (type, longText, shortText = longText) => ({ types: [type], longText, shortText });
const usAddress = [component('street_number', '19925'), component('route', 'Hogback Mountain Road'), component('subpremise', 'Suite 5'), component('locality', 'Leesburg'), component('administrative_area_level_1', 'Virginia', 'VA'), component('country', 'United States', 'US'), component('postal_code', '20175'), component('postal_code_suffix', '6752')];
function selection(components = usAddress, pending = null) {
	const fields = [];
	const place = { addressComponents: components, fetchFields: (options) => { fields.push(options.fields); return pending || Promise.resolve(); } };
	return { fields, event: { placePrediction: { toPlace: () => place } } };
}
(async () => {
	const h = harness(), mounted = h.start(); await tick();
	equal(h.imports, ['places']); equal(h.scripts.length, 0); equal(h.widgets[0].options, { includedRegionCodes: ['us'] });
	equal(h.widgets[0].attributes['aria-label'], 'Search for an address with Google'); ok(mounted.container.children[1].attributes['aria-live'] === 'polite');
	const chosen = selection(); await h.widgets[0].emit('gmp-select', chosen.event);
	equal(chosen.fields, [['addressComponents']]);
	equal(mounted.selected, [{ address_1: '19925 Hogback Mountain Road', address_2: 'Suite 5', city: 'Leesburg', state: 'VA', postcode: '20175-6752', country: 'US' }]);
	ok(mounted.status().includes('Address filled')); equal(h.timeouts.size, 0);
	// Filling a second address clears a prior suite and ZIP suffix instead of carrying them over.
	await h.widgets[0].emit('gmp-select', selection(usAddress.filter((part) => !['subpremise', 'postal_code_suffix'].includes(part.types[0]))).event);
	equal(mounted.selected[1].address_2, ''); equal(mounted.selected[1].postcode, '20175');
	for (const fallback of ['postal_town', 'sublocality_level_1', 'sublocality', 'administrative_area_level_3']) {
		const parts = usAddress.filter((part) => part.types[0] !== 'locality'); parts.push(component(fallback, 'Fallback town'));
		await h.widgets[0].emit('gmp-select', selection(parts).event); equal(mounted.selected.at(-1).city, 'Fallback town');
	}
	const priorCount = mounted.selected.length;
	for (const parts of [null, [component('locality', 'Leesburg'), component('country', 'United States', 'US')], usAddress.map((part) => part.types.includes('country') ? component('country', 'Canada', 'CA') : part)]) {
		await h.widgets[0].emit('gmp-select', selection(parts).event); equal(mounted.selected.length, priorCount); ok(mounted.status().includes('manually'));
	}
	// The latest selected prediction wins even when a previous request resolves later.
	const slow = defer(), fast = defer(), slowPick = h.widgets[0].emit('gmp-select', selection(usAddress, slow.promise).event);
	const fastParts = usAddress.map((part) => part.types.includes('street_number') ? component('street_number', '7') : part);
	const fastPick = h.widgets[0].emit('gmp-select', selection(fastParts, fast.promise).event);
	fast.resolve(); await fastPick; slow.resolve(); await slowPick;
	equal(mounted.selected.length, priorCount + 1); equal(mounted.selected.at(-1).address_1, '7 Hogback Mountain Road');
	const typingResponse = defer(), typingPick = h.widgets[0].emit('gmp-select', selection(usAddress, typingResponse.promise).event);
	await h.widgets[0].emit('input'); typingResponse.resolve(); await typingPick; equal(mounted.selected.length, priorCount + 1);
	// A manual edit while Google is fetching details must not be overwritten.
	let addressSnapshot = 'original'; const edit = h.start({ getAddressSnapshot: () => addressSnapshot }); await tick();
	const editResponse = defer(), editPick = h.widgets.at(-1).emit('gmp-select', selection(usAddress, editResponse.promise).event);
	addressSnapshot = 'staff edit'; editResponse.resolve(); await editPick;
	equal(edit.selected, []); ok(edit.status().includes('edits were kept'));
	// A new customer/order, detached form, or explicit cleanup rejects a late response.
	for (const invalidation of ['state', 'detach', 'cleanup']) {
		let current = true; const stale = h.start({ isCurrent: () => current }); await tick();
		const late = defer(), request = h.widgets.at(-1).emit('gmp-select', selection(usAddress, late.promise).event);
		if (invalidation === 'state') current = false;
		if (invalidation === 'detach') stale.container.isConnected = false;
		if (invalidation === 'cleanup') stale.cleanup();
		late.resolve(); await request; equal(stale.selected, []);
	}
	const errorCase = h.start(); await tick(); const errorWidget = h.widgets.at(-1);
	await errorWidget.emit('gmp-error'); ok(errorCase.status().includes('unavailable')); equal(errorCase.selected, []);
	await errorWidget.emit('gmp-select', selection(usAddress, Promise.reject(new Error('Quota'))).event); ok(errorCase.status().includes('manually')); equal(errorCase.selected, []);
	await errorWidget.emit('gmp-select', {}); ok(errorCase.status().includes('unavailable'));
	const hanging = defer(), hangingPick = errorWidget.emit('gmp-select', selection(usAddress, hanging.promise).event);
	for (const timeout of Array.from(h.timeouts.values())) timeout(); await hangingPick; ok(errorCase.status().includes('unavailable')); equal(errorCase.selected, []);
	hanging.resolve(); await tick(); equal(errorCase.selected, []);
	// Multiple forms share one API load, including when another plugin began loading Maps.
	for (const existingScript of [false, true]) {
		const loading = harness({ loaded: false, existingScript }), first = loading.start(), second = loading.start(); await tick();
		equal(loading.scripts.length, 1); equal(loading.widgets.length, 0);
		loading.installMaps(); await loading.scripts[0].emit('load'); await tick();
		equal(loading.widgets.length, 2); equal(loading.imports, ['places']); equal(loading.intervals.size, 0); equal(loading.timeouts.size, 0);
		ok(first.status().includes('suggestion')); ok(second.status().includes('suggestion'));
		if (!existingScript) { const url = new URL(loading.scripts[0].src); equal(url.searchParams.get('key'), 'browser-key'); equal(url.searchParams.get('loading'), 'async'); equal(url.searchParams.get('callback'), 'CartCatAddress.mapsReady'); }
	}
	const noKey = harness({ loaded: false }), disabled = noKey.start({ apiKey: '' }); await tick();
	equal(noKey.scripts.length, 0); equal(noKey.widgets.length, 0); ok(disabled.status().includes('not been configured'));
	for (const failure of ['network', 'timeout', 'import']) {
		const broken = harness({ loaded: failure === 'import', importError: failure === 'import' }), fallback = broken.start();
		if (failure === 'network') await broken.scripts[0].emit('error');
		if (failure === 'timeout') for (const timeout of Array.from(broken.timeouts.values())) timeout();
		await tick(); equal(broken.widgets.length, 0); ok(fallback.status().includes('manually')); equal(fallback.selected, []);
	}
	const abandoned = harness({ loaded: false }), closed = abandoned.start(); closed.cleanup(); abandoned.installMaps(); await abandoned.scripts[0].emit('load'); await tick();
	equal(abandoned.widgets.length, 0); equal(closed.container.children.length, 0);
	// Hidden address sections still initialize; selection is gated until their form is active.
	let visible = false; const hidden = harness(), hiddenMount = hidden.start({ isCurrent: () => visible, onReady: (widget) => { widget.disabled = true; } }); await tick();
	equal(hidden.widgets.length, 1); equal(hidden.widgets[0].disabled, true);
	await hidden.widgets[0].emit('gmp-select', selection().event); equal(hiddenMount.selected.length, 0);
	visible = true; await hidden.widgets[0].emit('gmp-select', selection().event); equal(hiddenMount.selected.length, 1);
	console.log(`Cart Cat Google address autocomplete: ${count} assertions passed.`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
