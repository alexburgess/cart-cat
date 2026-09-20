(function () {
	'use strict';
	const root = document.getElementById('cart-cat-app');
	if (!root) return;
	const selector = '#cc-customer-search, #cc-product-search, .cc-field > input, .cc-field > select, .cc-field > textarea, [data-address-key], #cc-order-notes';
	const fields = { first_name: 'user', last_name: 'signature', email: 'envelope', phone: 'phone', company: 'building', address_1: 'location', address_2: 'building', city: 'city', country: 'globe', state: 'map', postcode: 'pin' };
	function icon(name) {
		const node = document.createElement('span');
		node.className = `cc-icon cc-icon-${name}${['woocommerce', 'square'].includes(name) ? ' cc-icon-provider' : ''}`;
		node.setAttribute('aria-hidden', 'true');
		return node;
	}
	function decorate(input) {
		if (input.closest('.cc-input-wrap') || ['radio', 'checkbox', 'range', 'hidden'].includes(input.type) || input.closest('.cart-cat-payment, #cc-lines')) return;
		const focused = document.activeElement === input;
		const name = fields[input.dataset.contact || input.dataset.addressKey] || (input.id === 'cc-customer-search' ? 'search' : input.id === 'cc-product-search' ? 'wine' : input.id === 'cc-order-notes' ? 'notes' : input.id.startsWith('cc-discount-') ? 'tag' : input.type === 'email' ? 'envelope' : input.type === 'tel' ? 'phone' : 'options');
		const wrapper = document.createElement('span');
		wrapper.className = `cc-input-wrap${input.type === 'search' ? ' cc-search-wrap' : ''}${input.tagName === 'TEXTAREA' ? ' cc-textarea-wrap' : ''}`;
		input.before(wrapper); wrapper.append(input);
		const leading = document.createElement('span'); leading.className = 'cc-field-icon'; leading.setAttribute('aria-hidden', 'true'); leading.append(icon(name)); wrapper.append(leading);
		if (focused) input.focus({ preventScroll: true });
	}
	function scan(node) {
		if (!(node instanceof Element)) return;
		if (node.matches(selector)) decorate(node);
		node.querySelectorAll(selector).forEach(decorate);
		const targets = { '#cc-toggle-billing': 'address', '#cc-toggle-discount': 'tag', '#cc-new-customer': 'user-plus', '.cc-badge-woo': 'woocommerce', '.cc-badge-square': 'square' };
		Object.entries(targets).forEach(([match, name]) => {
			const nodes = [...node.querySelectorAll(match)]; if (node.matches(match)) nodes.unshift(node);
			nodes.forEach((target) => { if (!target.querySelector('.cc-icon')) target.prepend(icon(name)); });
		});
		const choices = [...node.querySelectorAll('.cc-choice')]; if (node.matches('.cc-choice')) choices.unshift(node);
		choices.forEach((choice) => {
			const input = choice.querySelector('input[name="cc-fulfillment"]');
			const label = choice.querySelector('strong');
			if (input && label && !label.querySelector('.cc-icon')) label.prepend(icon(input.value === 'pickup' ? 'store' : 'truck'));
		});
	}
	scan(root);
	new MutationObserver((mutations) => {
		const parents = new Set();
		mutations.forEach((mutation) => { mutation.addedNodes.forEach((node) => { if (node instanceof Element) parents.add(node); }); if (mutation.target instanceof Element && mutation.target.matches('#cc-toggle-billing, #cc-toggle-discount, #cc-new-customer')) parents.add(mutation.target); });
		parents.forEach((node) => { if (node.isConnected) scan(node); });
	}).observe(root, { childList: true, subtree: true });
})();
