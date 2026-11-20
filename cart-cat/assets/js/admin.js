(function () {
	'use strict';
	const root = document.getElementById('cart-cat-app');
	if (!root || !window.CartCat) return;
	const config = window.CartCat;
	const $ = (id) => document.getElementById(id);
	const state = {
		customer: {}, customerLoading: false, customerSearchStarted: false, lines: [], quote: null, quoteInput: '', quotePayload: null, pendingQuoteInput: '', revision: 0,
		order: null, orderLoading: false, editingOrder: null, preparing: false, pendingCreate: null, paymentBusy: false, emailBusy: false, emailSent: false, pendingEmail: null, emailStatusUncertain: false, emailSetupTrigger: null,
		paymentChecking: false, paymentController: null, paymentMounting: false, paymentPoll: null,
		draft: null, draftBusy: false, draftError: false, pendingDraft: null, savedDraftFingerprint: '', draftPage: 1, draftPages: 1, navigating: false,
		upsValidation: { generation: 0, busy: false, snapshot: '', notice: '', tone: '' },
		cards: [], configProduct: null, priceEditor: null, quoteTimer: null, searchTimers: {}, requests: {},
	};
	const catalog = typeof window.CartCatCatalog?.create === 'function' ? window.CartCatCatalog.create(api) : null;
	const holdDefault = Number.isInteger(Number(config.hold?.default_minutes)) && Number(config.hold.default_minutes) > 0 ? Number(config.hold.default_minutes) : 60;
	const holdSteps = [...new Set([...(config.hold?.steps || []), holdDefault].map(Number).filter((minutes) => Number.isInteger(minutes) && minutes > 0))].sort((a, b) => a - b);
	const money = (value, currency, decimals) => {
		const number = Number(value);
		if (!Number.isFinite(number)) return '—';
		const precision = Number.isInteger(decimals) && decimals >= 0 && decimals <= 20 ? decimals : undefined;
		try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || state.quote?.currency || state.order?.currency || config.currency || config.square?.currency || 'USD', minimumFractionDigits: precision, maximumFractionDigits: precision }).format(number); }
		catch (_) { return number.toFixed(precision ?? 2); }
	};
	function el(tag, attrs, content) {
		const node = document.createElement(tag);
		// Staff enter customer details here; these are not the staff member's login fields.
		if (['input', 'textarea', 'select'].includes(tag)) {
			node.setAttribute('autocomplete', 'off');
			node.setAttribute('data-1p-ignore', 'true');
		}
		Object.entries(attrs || {}).forEach(([key, value]) => {
			if (key === 'class') node.className = value;
			else if (key === 'hidden') node.hidden = value;
			else if (key === 'checked') node.checked = value;
			else if (key === 'disabled') node.disabled = value;
			else node.setAttribute(key, value);
		});
		if (content != null) node.textContent = String(content);
		return node;
	}
	function button(label, handler, classes = 'button') {
		const node = el('button', { type: 'button', class: classes }, label);
		node.addEventListener('click', handler);
		return node;
	}
	function holdDuration(minutes) {
		if (minutes % 1440 === 0) return `${minutes / 1440} day${minutes === 1440 ? '' : 's'}`;
		if (minutes % 60 === 0) return `${minutes / 60} hour${minutes === 60 ? '' : 's'}`;
		return `${minutes} minutes`;
	}
	function holdMinutes() { return holdSteps[Number($('cc-hold-duration').value)] ?? holdDefault; }
	function updateHoldPreview() {
		const minutes = holdMinutes();
		const duration = holdDuration(minutes);
		$('cc-hold-value').textContent = duration;
		$('cc-hold-duration').setAttribute('aria-valuetext', duration);
		let preview = 'The final expiry is set when you confirm sending.';
		try {
			const expiry = new Intl.DateTimeFormat(undefined, { timeZone: config.hold?.timezone || 'UTC', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }).format(new Date(Date.now() + minutes * 60000));
			preview = (state.order?.payment_email?.accepted || state.pendingEmail) && minutes === Number(state.order.hold?.minutes) && state.order.hold?.expires_label ? `Existing expiry will stay: ${state.order.hold.expires_label}.` : `Approximate expiry if sent now: ${expiry}.`;
		} catch (_) { /* Never substitute the staff browser's timezone for the store timezone. */ }
		$('cc-hold-preview').textContent = preview;
	}
	function setHoldMinutes(value) {
		const minutes = Number(value);
		if (Number.isInteger(minutes) && minutes > 0 && !holdSteps.includes(minutes)) { holdSteps.push(minutes); holdSteps.sort((a, b) => a - b); }
		$('cc-hold-duration').max = String(holdSteps.length - 1);
		$('cc-hold-duration').value = String(Math.max(0, holdSteps.indexOf(Number.isInteger(minutes) && minutes > 0 ? minutes : holdDefault)));
		$('cc-hold-min').textContent = holdDuration(holdSteps[0]);
		$('cc-hold-max').textContent = holdDuration(holdSteps.at(-1));
		updateHoldPreview();
	}
	function message(id, text, type = '') {
		const node = $(id);
		node.textContent = text || '';
		node.className = `cc-message${type ? ` is-${type}` : ''}`;
		node.hidden = !text;
	}
	function badges(sources, roles) {
		const group = el('span', { class: 'cc-badges' });
		(sources || []).forEach((source) => {
			const name = String(source);
			const label = /woo/i.test(name) ? 'WooCommerce' : /square/i.test(name) ? 'Square' : name;
			group.append(el('span', { class: `cc-badge cc-badge-${/woo/i.test(name) ? 'woo' : 'square'}`, role: 'img', 'aria-label': label, title: label }));
		});
		(roles || []).forEach((role) => group.append(el('span', { class: 'cc-badge cc-badge-role' }, typeof role === 'object' ? role.label || role.name || '' : String(role).replace(/_/g, ' '))));
		return group;
	}
	async function api(path, data, signal) {
		const endpoint = new URL(config.restUrl, window.location.href);
		const separator = path.indexOf('?');
		const route = separator < 0 ? path : path.slice(0, separator);
		if (endpoint.searchParams.has('rest_route')) endpoint.searchParams.set('rest_route', `${endpoint.searchParams.get('rest_route').replace(/\/$/, '')}/${route}`);
		else endpoint.pathname = `${endpoint.pathname.replace(/\/$/, '')}/${route}`;
		if (separator >= 0) new URLSearchParams(path.slice(separator + 1)).forEach((value, key) => endpoint.searchParams.set(key, value));
		const response = await fetch(endpoint.href, {
			method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
			body: data === undefined ? undefined : JSON.stringify(data), signal,
		});
		let result;
		try { result = await response.json(); } catch (_) { result = {}; }
		if (!response.ok) {
			const error = new Error(result.message || `The request could not be completed (${response.status}).`);
			error.status = response.status;
			error.code = result.code;
			error.data = result.data;
			throw error;
		}
		return result;
	}
	function newOrderUrl() {
		const url = new URL(window.location.href);
		url.search = ''; url.hash = '';
		url.searchParams.set('page', 'cart-cat');
		return url.href;
	}
	function resetBlocked() { return Boolean(state.orderLoading || state.preparing || state.pendingCreate || state.paymentBusy || state.paymentChecking || state.emailBusy || state.paymentMounting || state.draftBusy || state.pendingDraft); }
	function syncNewOrderControls() {
		syncDraftControls();
		syncUPSValidation();
		root.querySelectorAll('[data-new-order]').forEach((control) => { control.disabled = resetBlocked(); });
		if ($('cc-email-setup-dialog').open) syncEmailSetup();
		else $('cc-hold-duration').disabled = resetBlocked();
	}
	function startNewOrder() {
		if (resetBlocked() || !confirmLeaveDraft()) return;
		state.navigating = true;
		window.location.assign(newOrderUrl());
	}
	function newOrderButton() {
		const control = button('New Cart Cat order', startNewOrder);
		control.setAttribute('data-new-order', '');
		control.disabled = resetBlocked();
		return control;
	}
	function newRequest(name) {
		state.requests[name]?.abort();
		const controller = new AbortController();
		state.requests[name] = controller;
		return controller;
	}
	function activeRequest(name, controller) { return state.requests[name] === controller && !controller.signal.aborted; }
	function draftFingerprint() { return JSON.stringify({ input: getInput(), title: $('cc-draft-title').value.trim() }); }
	function draftDirty() { return !state.order && Boolean(state.savedDraftFingerprint) && draftFingerprint() !== state.savedDraftFingerprint; }
	function confirmLeaveDraft() { return !draftDirty() || window.confirm('You have unsaved changes. Leave them and load another order? Save as draft first to keep them.'); }
	function syncDraftControls() {
		const busy = resetBlocked() || state.customerLoading;
		$('cc-open-drafts').disabled = busy;
		$('cc-draft-controls').hidden = Boolean(state.order || state.editingOrder || state.orderLoading);
		$('cc-save-draft').disabled = Boolean(state.orderLoading || state.draftBusy || state.preparing || state.pendingCreate || state.customerLoading || state.order || state.editingOrder);
		$('cc-save-draft').textContent = state.draftBusy ? 'Saving…' : state.pendingDraft ? 'Retry saving draft' : state.draft ? 'Save draft' : 'Save as draft';
		$('cc-draft-title').disabled = busy;
		$('cc-new-draft').hidden = !state.draft;
		$('cc-new-draft').disabled = busy;
		if (state.draft && !state.draftBusy && !state.pendingDraft && !state.draftError && draftDirty()) $('cc-draft-status').textContent = 'Unsaved changes. Save this draft to keep them.';
	}
	async function saveDraft() {
		if (state.order || state.orderLoading || state.editingOrder || state.preparing || state.pendingCreate || state.draftBusy || state.customerLoading) return;
		if (!state.pendingDraft) {
			const input = getInput();
			input.lines = input.lines.map((line, index) => ({ ...line, name: state.lines[index].name, meta: state.lines[index].meta || [] }));
			state.pendingDraft = { input, title: $('cc-draft-title').value.trim(), request_id: uid(), ...(state.draft ? { version: state.draft.version } : {}) };
		}
		state.draftBusy = true; state.draftError = false;
		clearTimeout(state.quoteTimer); state.requests.quote?.abort(); state.pendingQuoteInput = '';
		lockBuilder(true); syncNewOrderControls();
		$('cc-create-order').disabled = true;
		$('cc-draft-status').textContent = 'Saving draft…';
		try {
			const draft = await api(state.draft ? `drafts/${state.draft.draft_id}` : 'drafts', state.pendingDraft);
			if (!draft?.draft_id || !draft.version || draft.state !== 'draft') throw new Error('The save was not confirmed. Retry to recover this same draft.');
			state.draft = draft;
			state.pendingDraft = null;
			if (draft.input?.customer) state.customer.reference = draft.input.customer.reference;
			$('cc-draft-title').value = draft.title || '';
			state.savedDraftFingerprint = draftFingerprint();
			const url = new URL(window.location.href);
			url.searchParams.delete('cc_order'); url.searchParams.set('cc_draft', draft.draft_id);
			window.history.replaceState({}, '', url);
			$('cc-order-state').textContent = `Draft #${draft.draft_id}`;
			$('cc-draft-status').textContent = 'Draft saved. You can find it in Saved drafts. No stock is reserved.';
		} catch (error) {
			if (error.status && error.status < 500 && !['cart_cat_busy', 'cart_cat_draft_busy'].includes(error.code)) state.pendingDraft = null;
			state.draftError = true;
			$('cc-draft-status').textContent = state.pendingDraft ? `${error.message} Retry saving this same draft.` : error.message;
		} finally {
			state.draftBusy = false;
			lockBuilder(Boolean(state.pendingDraft)); syncNewOrderControls();
			if (!state.pendingDraft) {
				$('cc-create-order').disabled = !state.quote?.can_submit;
				// A save reissues linked customer references. Their old quote must not stay enabled.
				changed();
			}
		}
	}
	function draftDate(value) {
		const date = new Date(typeof value === 'number' ? value * 1000 : value);
		return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
	}
	async function listDrafts(page = 1) {
		const request = newRequest('drafts');
		state.draftPage = page;
		$('cc-draft-list').replaceChildren(el('p', { class: 'cc-help' }, 'Loading drafts…'));
		$('cc-drafts-prev').disabled = true; $('cc-drafts-next').disabled = true;
		message('cc-drafts-message', '');
		try {
			const result = await api(`drafts?q=${encodeURIComponent($('cc-draft-search').value.trim())}&page=${page}`, undefined, request.signal);
			if (!activeRequest('drafts', request)) return;
			const lastPage = Math.max(1, Number(result.total_pages) || 1);
			if (page > lastPage) { await listDrafts(lastPage); return; }
			state.draftPage = result.page || page; state.draftPages = result.total_pages || 1;
			$('cc-draft-list').replaceChildren();
			(result.drafts || []).forEach((draft) => {
				const row = el('article', { class: 'cc-draft-row', 'data-draft-id': draft.draft_id });
				const details = el('div');
				details.append(el('h3', {}, draft.title || `Draft #${draft.draft_id}`), el('p', {}, [draft.customer_name, draft.email].filter(Boolean).join(' · ')));
				details.append(el('p', { class: 'cc-help' }, [`${draft.item_count || 0} item${draft.item_count === 1 ? '' : 's'}`, draftDate(draft.updated_at), draft.updated_by?.name || draft.updated_by].filter(Boolean).join(' · ')));
				if (draft.state === 'prepared') details.append(el('p', { class: 'cc-help' }, `Prepared as order #${draft.order_id}`));
				else if (draft.state === 'preparing') details.append(el('p', { class: 'cc-help' }, 'Preparation needs review. Open to check the existing order.'));
				const actions = el('div', { class: 'cc-actions' });
				actions.append(button(draft.state === 'prepared' ? 'Open order' : 'Load draft', () => loadDraft(draft.draft_id), 'button button-primary'));
				if (!draft.state || draft.state === 'draft') actions.append(button('Delete draft', () => deleteDraft(draft), 'button'));
				row.append(details, actions); $('cc-draft-list').append(row);
			});
			if (!result.drafts?.length) $('cc-draft-list').append(el('p', {}, $('cc-draft-search').value.trim() ? 'No matching drafts.' : 'No saved drafts yet. Use Save as draft while building an order.'));
			$('cc-drafts-page').textContent = `Page ${state.draftPage} of ${state.draftPages}`;
			$('cc-drafts-prev').disabled = state.draftPage <= 1;
			$('cc-drafts-next').disabled = state.draftPage >= state.draftPages;
		} catch (error) {
			if (error.name !== 'AbortError' && activeRequest('drafts', request)) { $('cc-draft-list').replaceChildren(); message('cc-drafts-message', error.message, 'error'); }
		}
	}
	function openDrafts() {
		if (resetBlocked() || state.customerLoading) return;
		$('cc-drafts-dialog').showModal(); $('cc-draft-search').focus(); listDrafts(1);
	}
	async function loadDraft(id, initial = false) {
		if (resetBlocked() || (!initial && !confirmLeaveDraft())) return;
		state.draftBusy = true; syncNewOrderControls(); lockBuilder(true);
		$('cc-create-order').disabled = true;
		clearTimeout(state.quoteTimer); state.requests.quote?.abort();
		try {
			const draft = await api(`drafts/${id}`);
			if (!draft?.draft_id || !draft.input) throw new Error('This draft could not be loaded.');
			if (draft.order_id && draft.state === 'prepared') {
				const url = new URL(newOrderUrl()); url.searchParams.set('cc_order', draft.order_id);
				state.navigating = true; window.location.assign(url.href); return;
			}
			if (draft.can_edit === false || draft.state !== 'draft') throw Object.assign(new Error(draft.prepare_error?.message || draft.prepare_error || 'This draft is being prepared. Reopen it after the current request finishes.'), { data: { edit_url: draft.edit_url } });
			Object.values(state.requests).forEach((request) => request.abort());
			Object.values(state.searchTimers).forEach(clearTimeout);
			clearTimeout(state.paymentPoll);
			await state.paymentController?.destroy().catch(() => {}); state.paymentController = null;
			state.order = null; state.editingOrder = null; state.draft = draft; state.customerLoading = false; state.draftError = false;
			state.emailSent = false; state.pendingEmail = null; state.emailStatusUncertain = false;
			state.quote = null; state.quoteInput = ''; state.quotePayload = null; state.pendingQuoteInput = '';
			clearQuote();
			hydrateOrder(draft);
			$('cc-draft-title').value = draft.title || '';
			['cc-customer-results', 'cc-product-results', 'cc-product-config'].forEach((key) => { $(key).replaceChildren(); $(key).hidden = true; });
			$('cc-customer-search').value = ''; $('cc-product-search').value = '';
			$('cc-customer-search-status').textContent = draft.warning || 'Saved customer details restored. Current pricing and availability will be checked.';
			$('cc-builder').hidden = false; $('cc-payment-panel').hidden = true;
			$('cc-create-order').hidden = false; $('cc-create-order').textContent = 'Prepare order';
			$('cc-prepare-help').hidden = false; $('cc-prepare-help').textContent = 'Next: email a payment link or take payment.';
			$('cc-order-links').hidden = true; $('cc-order-state').textContent = `Draft #${draft.draft_id}`;
			state.savedDraftFingerprint = draftFingerprint();
			$('cc-draft-status').textContent = 'Draft loaded. Save any changes before leaving. No stock is reserved.';
			message('cc-global-message', draft.warning || '');
			const url = new URL(window.location.href); url.searchParams.delete('cc_order'); url.searchParams.set('cc_draft', draft.draft_id);
			window.history.replaceState({}, '', url);
			$('cc-drafts-dialog').close();
			$('cc-customer-heading').focus(); root.scrollIntoView({ block: 'start' });
		} catch (error) {
			const target = $('cc-drafts-dialog').open ? 'cc-drafts-message' : 'cc-global-message';
			message(target, error.message, 'error');
			if (error.data?.edit_url) $(target).append(orderLink('Review the existing order in WooCommerce', error.data.edit_url, 'cc-order-link'));
		} finally {
			state.draftBusy = false; lockBuilder(false); syncNewOrderControls(); changed();
		}
	}
	async function deleteDraft(draft) {
		if (resetBlocked() || !window.confirm(`Delete “${draft.title || 'this draft'}”? This cannot be undone.`)) return;
		state.draftBusy = true; syncNewOrderControls();
		try {
			await api(`drafts/${draft.draft_id}/delete`, { version: draft.version });
			if (state.draft?.draft_id === draft.draft_id) {
				state.draft = null; state.savedDraftFingerprint = 'deleted';
				const url = new URL(window.location.href); url.searchParams.delete('cc_draft'); window.history.replaceState({}, '', url);
				$('cc-order-state').textContent = 'New order';
				$('cc-draft-status').textContent = 'Saved draft deleted. The details on screen have not been saved.';
			}
			await listDrafts(state.draftPage);
		} catch (error) { message('cc-drafts-message', error.message, 'error'); }
		finally { state.draftBusy = false; syncNewOrderControls(); }
	}
	function disclosure(id, expanded) {
		const control = $(`cc-toggle-${id}`);
		control.setAttribute('aria-expanded', String(expanded));
		$(control.getAttribute('aria-controls')).hidden = !expanded;
	}
	function revealCustomer() { $('cc-customer-details').hidden = false; }
	function cancelCustomerSearch() {
		state.requests.customerSearch?.abort();
		clearTimeout(state.searchTimers['cc-customer-search']);
		$('cc-customer-results').replaceChildren();
		$('cc-customer-results').hidden = true;
	}
	function uid() {
		if (window.crypto?.randomUUID) return window.crypto.randomUUID();
		const bytes = new Uint8Array(16);
		window.crypto.getRandomValues(bytes);
		return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
	}
	const addressFields = [
		['first_name', 'First name'], ['last_name', 'Last name'], ['company', 'Company (optional)', 'wide'],
		['address_1', 'Street address', 'wide'], ['address_2', 'Apartment, suite, etc. (optional)', 'wide'],
		['city', 'City'], ['country', 'Country'], ['state', 'State / province'], ['postcode', 'ZIP / postal code'],
	];
	const addressSearchCleanup = {};
	const postalAddressKeys = ['address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
	function addressValues(kind) {
		const result = {};
		$(`cc-${kind}-fields`).querySelectorAll('[data-address-key]').forEach((node) => { result[node.dataset.addressKey] = node.value.trim(); });
		return result;
	}
	function addressLines(values = {}) {
		const locality = [values.city, [config.states?.[values.country]?.[values.state] || values.state, values.postcode].filter(Boolean).join(' ')].filter(Boolean).join(', ');
		return [values.company, values.address_1, values.address_2, locality, config.countries?.[values.country] || values.country].filter(Boolean);
	}
	function shippingPostalAddress(values = addressValues('shipping')) {
		return Object.fromEntries(postalAddressKeys.map((key) => [key, typeof values?.[key] === 'string' ? values[key].trim() : '']));
	}
	function canValidateUPS() {
		return !state.order && !state.orderLoading && !state.preparing && !state.pendingCreate && !state.customerLoading && !state.draftBusy && !state.pendingDraft && !$('cc-builder').disabled && !$('cc-shipping-panel').hidden;
	}
	function invalidateUPSValidation(notice = '') {
		const validation = state.upsValidation;
		validation.generation++;
		state.requests.upsValidation?.abort();
		Object.assign(validation, { busy: false, snapshot: '', notice, tone: '' });
		$('cc-ups-candidates').replaceChildren();
	}
	function syncUPSValidation() {
		const validation = state.upsValidation;
		const address = shippingPostalAddress();
		const addressChanged = validation.snapshot && validation.snapshot !== JSON.stringify(address);
		if (validation.snapshot && (addressChanged || !canValidateUPS())) invalidateUPSValidation(addressChanged ? 'The address changed. Validate with UPS again to check the new address.' : '');
		const supported = ['US', 'PR'].includes(address.country);
		$('cc-validate-ups').disabled = validation.busy || !supported || !canValidateUPS();
		$('cc-validate-ups').textContent = validation.busy ? 'Checking with UPS…' : 'Validate with UPS';
		message('cc-ups-status', supported ? validation.notice : 'UPS address validation is available for United States and Puerto Rico addresses.', supported ? validation.tone : '');
	}
	async function validateUPSAddress() {
		if (!canValidateUPS() || state.upsValidation.busy) return;
		const address = shippingPostalAddress();
		if (!['US', 'PR'].includes(address.country)) return;
		invalidateUPSValidation();
		const validation = state.upsValidation;
		const generation = validation.generation;
		const snapshot = JSON.stringify(address);
		const request = newRequest('upsValidation');
		Object.assign(validation, { busy: true, snapshot, notice: 'Checking the shipping address with UPS…' });
		syncUPSValidation();
		const current = () => generation === validation.generation && activeRequest('upsValidation', request) && canValidateUPS() && snapshot === JSON.stringify(shippingPostalAddress());
		try {
			const result = await api('shipping/validate-address', { address }, request.signal);
			if (!current()) return;
			if (!['valid', 'suggested', 'unmatched', 'unsupported', 'unavailable'].includes(result?.status)) throw new Error('UPS did not return an address result. Try again or continue with the entered address.');
			const candidates = (Array.isArray(result.candidates) ? result.candidates : []).map((candidate) => shippingPostalAddress(candidate)).filter((candidate) => ['address_1', 'city', 'state', 'postcode', 'country'].every((key) => candidate[key]) && ['US', 'PR'].includes(candidate.country));
			if (result.status === 'suggested' && !candidates.length) throw new Error('UPS did not return a usable suggestion. Check the address or try again.');
			validation.notice = result.message || ({ valid: 'UPS recognized this address.', suggested: 'Review the UPS suggestions below.', unmatched: 'UPS could not match this address. Check the entered details.', unsupported: 'UPS cannot validate this address.', unavailable: 'UPS validation is unavailable. Try again or continue with the entered address.' })[result.status];
			validation.tone = result.status === 'valid' ? 'success' : ['unmatched', 'unavailable'].includes(result.status) ? 'warning' : '';
			if (result.status === 'suggested') candidates.forEach((candidate, index) => {
				const card = el('div', { class: 'cc-ups-candidate' });
				card.append(el('h4', {}, candidates.length === 1 ? 'UPS suggestion' : `UPS suggestion ${index + 1}`));
				const summary = el('div', { class: 'cc-address-summary' });
				addressLines(candidate).forEach((line) => summary.append(el('p', {}, line)));
				card.append(summary);
				const keepUnit = Boolean(address.address_2 && !candidate.address_2);
				if (keepUnit) card.append(el('p', { class: 'cc-help cc-ups-unit-warning' }, `UPS did not return your apartment or suite (${address.address_2}). It will be kept; check it before continuing.`));
				else if (address.address_2 && candidate.address_2 !== address.address_2) card.append(el('p', { class: 'cc-help cc-ups-unit-warning' }, `UPS suggests a different apartment or suite from ${address.address_2}. Review it before using this address.`));
				card.append(button('Use suggested address', () => {
					if (!current()) return;
					const corrected = { ...addressValues('shipping'), ...candidate, address_2: keepUnit ? address.address_2 : candidate.address_2 };
					renderAddress('shipping', corrected);
					changed();
					Object.assign(validation, { snapshot: JSON.stringify(shippingPostalAddress()), notice: keepUnit ? 'UPS suggestion applied. Your apartment or suite was kept because UPS did not return it; check it before continuing.' : 'UPS suggestion applied. Review the address before continuing.', tone: 'success' });
					syncUPSValidation();
					$('cc-shipping-address_2').focus();
				}));
				$('cc-ups-candidates').append(card);
			});
		} catch (error) {
			if (error.name !== 'AbortError' && current()) Object.assign(validation, { notice: error.message, tone: 'warning' });
		} finally {
			if (current()) { validation.busy = false; syncUPSValidation(); }
		}
	}
	function renderBillingSummary() {
		const values = addressValues('billing');
		const summary = $('cc-billing-summary');
		summary.replaceChildren();
		if (![values.address_1, values.address_2, values.city, values.state, values.postcode].some(Boolean)) {
			summary.append(el('p', { class: 'cc-muted' }, 'No billing address provided.'));
			return;
		}
		addressLines(values).forEach((line) => summary.append(el('p', {}, line)));
	}
	function renderAddress(kind, values = {}) {
		if (kind === 'shipping') invalidateUPSValidation();
		const container = $(`cc-${kind}-fields`);
		addressSearchCleanup[kind]?.();
		container.replaceChildren();
		let search;
		if (config.addressSearch?.apiKey && window.CartCatAddress?.mount) {
			search = el('div', { class: 'cc-address-search cc-field-wide' });
			container.append(search);
		}
		const fields = kind === 'shipping' ? [...addressFields.slice(0, 2), ['email', 'Recipient email'], ['phone', 'Recipient phone'], ...addressFields.slice(2)] : addressFields;
		fields.forEach(([key, label, width]) => {
			const id = `cc-${kind}-${key}`;
			const field = el('label', { class: `cc-field${width ? ' cc-field-wide' : ''}`, for: id });
			field.append(el('span', {}, label));
			let input;
			const country = values.country || 'US';
			const regions = config.states?.[country];
			if (key === 'country' && config.countries) {
				input = el('select', { id });
				Object.entries(config.countries).forEach(([value, name]) => input.append(el('option', { value }, name)));
			} else if (key === 'state' && regions && Object.keys(regions).length) {
				input = el('select', { id });
				input.append(el('option', { value: '' }, 'Select a state / province'));
				Object.entries(regions).forEach(([value, name]) => input.append(el('option', { value }, name)));
			} else input = el('input', { id, type: key === 'email' ? 'email' : key === 'phone' ? 'tel' : 'text', autocomplete: 'off' });
			if (kind === 'shipping' && ['email', 'phone'].includes(key)) input.required = true;
			input.dataset.addressKey = key;
			input.value = values[key] || (key === 'country' ? 'US' : '');
			if (key === 'country') input.addEventListener('change', () => {
				const current = addressValues(kind);
				current.state = '';
				renderAddress(kind, current);
				changed();
			});
			field.append(input);
			container.append(field);
		});
		if (search) addressSearchCleanup[kind] = window.CartCatAddress.mount(search, {
			apiKey: config.addressSearch.apiKey,
			country: values.country || 'US',
			onReady: (widget) => { widget.disabled = $('cc-builder').disabled; },
			isCurrent: () => !state.order && !state.preparing && !state.pendingCreate && !state.customerLoading && !$('cc-builder').disabled && !container.closest('[hidden]'),
			getAddressSnapshot: () => JSON.stringify(addressValues(kind)),
			onSelect: (address) => {
				renderAddress(kind, { ...addressValues(kind), ...address });
				changed();
				$(`cc-${kind}-address_2`).focus();
			},
		});
		if (kind === 'billing') renderBillingSummary();
		else syncUPSValidation();
	}
	function getInput() {
		const customer = { reference: state.customer.reference };
		root.querySelectorAll('[data-contact]').forEach((node) => { customer[node.dataset.contact] = node.value.trim(); });
		customer.billing = { ...addressValues('billing'), email: customer.email, phone: customer.phone };
		customer.shipping = addressValues('shipping');
		const rates = {};
		$('cc-rates').querySelectorAll('input:checked').forEach((node) => { rates[node.dataset.package] = node.value; });
		return {
			customer,
			hold_minutes: Number(state.editingOrder?.hold?.minutes ?? state.editingOrder?.input?.hold_minutes ?? state.draft?.input?.hold_minutes ?? holdDefault),
			order_type: $('cc-somminar-order').checked ? 'somminar' : 'regular',
			order_notes: $('cc-order-notes').value,
			order_notes_visible: $('cc-order-notes-visible').checked,
			lines: state.lines.map((line) => ({ product_id: line.product_id, variation_id: line.variation_id || 0, quantity: line.quantity, addons: line.addons || {}, attributes: line.attributes || {}, ...(line.price_override ? { price_override: line.price_override } : {}) })),
			discount: { type: $('cc-discount-type').value, amount: $('cc-discount-amount').value || '0', mode: root.querySelector('[name="cc-discount-mode"]:checked').value },
			fulfillment: { type: root.querySelector('[name="cc-fulfillment"]:checked').value, rates, heat_hold_choice: root.querySelector('[name="cc-heat-choice"]:checked')?.value || '' },
		};
	}
	function changed() {
		syncUPSValidation();
		syncDraftControls();
		if (state.order || state.preparing || state.pendingCreate || state.draftBusy || state.pendingDraft) return;
		updateHoldPreview();
		const fingerprint = JSON.stringify(getInput());
		// Input/change and bubbling quantity events can describe the same edit.
		// Keep the existing calculation instead of aborting and restarting it.
		if (!state.customerLoading && ((state.quote && state.quoteInput === fingerprint) || state.pendingQuoteInput === fingerprint)) return;
		const count = state.lines.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
		$('cc-item-count').textContent = `${count} item${count === 1 ? '' : 's'}`;
		state.revision++;
		state.quote = null;
		state.quoteInput = '';
		state.quotePayload = null;
		state.pendingQuoteInput = state.lines.length && !state.customerLoading ? fingerprint : '';
		state.requests.quote?.abort();
		$('cc-create-order').disabled = true;
		$('cc-quote-dot').className = 'cc-dot';
		$('cc-quote-status').textContent = state.lines.length ? 'Updating totals…' : 'Add products to calculate totals.';
		if (state.lines.length) markTotalsPending();
		clearTimeout(state.quoteTimer);
		if (state.lines.length && !state.customerLoading) state.quoteTimer = setTimeout(() => refreshQuote(), 450);
		else if (!state.lines.length) clearQuote();
	}
	function markTotalsPending(failed = false) {
		$('cc-totals').querySelectorAll('[data-total-label]').forEach((row) => {
			if (row.dataset.totalLabel === 'Shipping' && getInput().fulfillment.type === 'shipping') row.querySelector('dd').textContent = failed ? 'Not available' : 'Calculating…';
			if (row.dataset.totalLabel === 'Total') row.querySelector('dd').textContent = failed ? 'Not available' : 'Pending';
		});
	}
	function clearQuote() {
		$('cc-totals').replaceChildren();
		[['Subtotal', '—'], ['Shipping', '—'], ['Tax', '—'], ['Total', '—']].forEach(([label, value], index) => {
			const row = el('div', { class: index === 3 ? 'cc-total' : '', 'data-total-label': label });
			row.append(el('dt', {}, label), el('dd', {}, value)); $('cc-totals').append(row);
		});
		$('cc-quote-notices').replaceChildren();
		$('cc-rates').replaceChildren();
		$('cc-heat-hold').hidden = true;
		$('cc-heat-hold').replaceChildren();
	}
	async function refreshQuote() {
		if (!state.lines.length || state.order || state.customerLoading || state.draftBusy || state.pendingDraft) return null;
		clearTimeout(state.quoteTimer);
		const revision = state.revision;
		const input = getInput();
		const fingerprint = JSON.stringify(input);
		const controller = newRequest('quote');
		$('cc-quote-status').textContent = 'Calculating totals…';
		$('cc-quote-dot').className = 'cc-dot is-loading';
		$('cc-create-order').disabled = true;
		try {
			const quote = await api('quote', input, controller.signal);
			if (!activeRequest('quote', controller) || revision !== state.revision || fingerprint !== JSON.stringify(getInput())) return null;
			const wasClean = !draftDirty();
			state.quote = quote;
			renderQuote(quote);
			if (wasClean) state.savedDraftFingerprint = draftFingerprint();
			syncDraftControls();
			// Woo has already calculated its selected rate. Keep the original payload
			// paired with this hash, and track the displayed selection for later edits.
			// Order creation freshly validates that same payload server-side.
			state.quotePayload = input;
			state.quoteInput = JSON.stringify(getInput());
			state.pendingQuoteInput = '';
			$('cc-quote-status').textContent = quote.can_submit ? 'Totals are up to date.' : 'Complete the details below to continue.';
			$('cc-quote-dot').className = `cc-dot${quote.can_submit ? ' is-ready' : ''}`;
			$('cc-create-order').disabled = !quote.can_submit || state.preparing;
			return quote;
		} catch (error) {
			if (error.name === 'AbortError' || !activeRequest('quote', controller) || revision !== state.revision) return null;
			state.quote = null;
			state.quoteInput = '';
			state.quotePayload = null;
			state.pendingQuoteInput = '';
			$('cc-quote-status').textContent = 'Totals could not be calculated.';
			markTotalsPending(true);
			$('cc-quote-dot').className = 'cc-dot';
			$('cc-quote-notices').replaceChildren(el('p', {}, error.message));
			if (error.data?.checkout_url) $('cc-quote-notices').append(orderLink('Continue at the store checkout', error.data.checkout_url, 'cc-order-link'));
			return null;
		}
	}
	function automaticDiscountRows(quote) {
		const total = Number(quote.totals?.automatic_savings || 0);
		if (!(total > 0)) return [];
		const discounts = (Array.isArray(quote.automatic_discounts) ? quote.automatic_discounts : []).filter((discount) => typeof discount.label === 'string' && discount.label.trim() && Number(discount.amount) > 0);
		const accounted = discounts.reduce((sum, discount) => sum + Number(discount.amount), 0);
		if (discounts.length && Math.abs(accounted - total) < 10 ** -(quote.decimals ?? 2)) return discounts.map((discount) => [discount.label, discount.amount, 'saving']);
		// Older prepared orders may predate the named breakdown. Preserve their actual total.
		return [[discounts.map((discount) => discount.label).join(', ') || 'Automatic discounts', total, 'saving']];
	}
	function renderQuote(quote) {
		const totals = quote.totals || {};
		const shippingUnavailable = quote.shipping_status === 'unavailable' || (quote.shipping_status !== 'not_required' && getInput().fulfillment.type === 'shipping' && (quote.rates || []).some((pkg) => !pkg.rates?.length));
		const rows = [['Subtotal', totals.subtotal], ...automaticDiscountRows(quote), ['Item price adjustments', totals.price_adjustment, 'adjustment'], ['Discount', totals.discount, 'saving'], ['Shipping', totals.shipping], ['Fees', totals.fees, 'optional'], ['Tax', totals.tax], ['Total', totals.total, 'total']];
		$('cc-totals').replaceChildren();
		rows.forEach(([label, value, kind]) => {
			const amount = Number(value);
			if (['saving', 'optional', 'adjustment'].includes(kind) && !amount) return;
			const row = el('div', { class: kind === 'saving' || (kind === 'adjustment' && amount < 0) ? 'cc-saving' : kind ? `cc-${kind}` : '', 'data-total-label': label });
			let display = money(value, quote.currency, quote.decimals);
			if (kind === 'adjustment') display = `${amount < 0 ? '−' : '+'}${money(Math.abs(amount), quote.currency, quote.decimals)}`;
			if (kind === 'saving' && amount > 0) display = `−${display}`;
			if (shippingUnavailable && label === 'Shipping') display = 'Not available';
			if (shippingUnavailable && label === 'Total') display = 'Pending shipping';
			row.append(el('dt', {}, label), el('dd', {}, display));
			$('cc-totals').append(row);
		});
		$('cc-summary-customer').replaceChildren();
		if (quote.customer?.name) $('cc-summary-customer').append(el('strong', {}, quote.customer.name), badges(quote.customer.sources, quote.customer.roles));
		$('cc-quote-notices').replaceChildren();
		(quote.notices || []).forEach((notice) => $('cc-quote-notices').append(el('p', {}, typeof notice === 'string' ? notice : notice.message || notice.text || '')));
		if ($('cc-lines').querySelectorAll('.cc-line').length !== state.lines.length) renderLines();
		$('cc-lines').querySelectorAll('.cc-line').forEach((row, index) => {
			const line = quote.lines?.[index];
			renderLinePrice(row.querySelector('.cc-line-price'), line, quote.currency, quote.decimals);
		});
		$('cc-rates').replaceChildren();
		(quote.rates || []).forEach((pkg, index) => {
			const group = el('fieldset', { class: 'cc-rate-group' });
			group.append(el('legend', {}, quote.rates.length > 1 ? `Delivery option · package ${index + 1}` : 'Delivery option'));
			(pkg.rates || []).forEach((rate) => {
				const label = el('label', { class: 'cc-radio' });
				const input = el('input', { type: 'radio', name: `cc-rate-${pkg.package_index}`, value: rate.id, checked: Boolean(rate.selected) });
				input.dataset.package = pkg.package_index;
				label.append(input, el('span', {}, rate.label));
				const pickup = (state.order?.input?.fulfillment?.type || getInput().fulfillment.type) === 'pickup';
				if (!pickup || Number(rate.cost) !== 0) label.append(el('span', { class: 'cc-rate-cost' }, money(rate.cost, quote.currency)));
				group.append(label);
			});
			$('cc-rates').append(group);
		});
		$('cc-heat-hold').replaceChildren();
		$('cc-heat-hold').hidden = !quote.heat_hold?.required;
		if (quote.heat_hold?.required) {
			const box = el('div', { class: 'cc-heat-box' });
			box.append(el('h3', {}, 'Warm-weather shipping'));
			(quote.heat_hold.choices || []).forEach((choice) => {
				const label = el('label', { class: 'cc-radio' });
				const text = el('span', {}, choice.label);
				if (choice.description) text.append(el('small', {}, choice.description));
				label.append(el('input', { type: 'radio', name: 'cc-heat-choice', value: choice.value, checked: choice.value === quote.heat_hold.choice }), text);
				box.append(label);
			});
			$('cc-heat-hold').append(box);
		}
	}
	function renderLinePrice(container, line, currency, decimals) {
		container.replaceChildren();
		if (!line) { container.textContent = '—'; return; }
		const discounted = Number(line.subtotal) > Number(line.total);
		if (discounted || (line.price_override && Number(line.subtotal) < Number(line.total))) {
			container.append(el('span', { class: 'cc-screen-reader' }, 'Original price: '), el('del', { class: 'cc-line-original' }, money(line.subtotal, currency, decimals)));
			container.append(el('span', { class: 'cc-line-discount-label' }, discounted ? 'Discounted price' : 'Adjusted price'));
		}
		container.append(el('span', { class: discounted ? 'cc-line-discounted' : '' }, money(line.total, currency, decimals)));
	}
	function itemPriceText(override, currency, decimals) {
		if (!override) return '';
		if (override.type === 'fixed') return `Unit price set to ${money(override.value, currency, decimals)}`;
		return `${override.value}% item discount`;
	}
	function matchingCatalogLine(candidate) {
		if (candidate.quantity_per_line || candidate.sold_individually) return undefined;
		const selections = (options) => JSON.stringify(Object.entries(options || {}).sort());
		const addons = selections(candidate.addons);
		const attributes = selections(candidate.attributes);
		return state.lines.find((line) => line !== candidate && !line.price_override && !line.quantity_per_line && !line.sold_individually && line.product_id === candidate.product_id && (line.variation_id || 0) === (candidate.variation_id || 0) && selections(line.addons) === addons && selections(line.attributes) === attributes);
	}
	function syncItemPriceFields() {
		const price = $('cc-item-price-value');
		const percent = $('cc-item-price-type').value === 'percent';
		const { currency, decimals } = state.priceEditor;
		$('cc-item-price-label').textContent = percent ? 'Discount (%)' : `Unit price (${currency})`;
		$('cc-item-price-help').textContent = percent ? 'The discount applies to the current product price after automatic discounts. Paid add-ons and cart discounts are calculated separately.' : 'Paid add-ons and cart discounts are calculated separately.';
		price.step = percent ? 'any' : String(10 ** -decimals);
		price.max = percent ? '100' : '99999999';
	}
	function openItemPrice(line) {
		if (state.order || $('cc-builder').disabled) return;
		state.priceEditor = {
			line,
			currency: state.quote?.currency || state.editingOrder?.currency || config.currency || config.square?.currency || 'USD',
			decimals: state.quote?.decimals ?? state.editingOrder?.review?.decimals ?? config.decimals ?? 2,
		};
		$('cc-item-price-product').textContent = line.name;
		$('cc-item-price-type').value = line.price_override?.type || 'percent';
		$('cc-item-price-value').value = line.price_override?.value ?? '';
		$('cc-item-price-value').setCustomValidity('');
		$('cc-item-price-reset').hidden = !line.price_override;
		syncItemPriceFields();
		$('cc-item-price-dialog').showModal();
		$('cc-item-price-type').focus();
	}
	function closeItemPrice(line = state.priceEditor?.line) {
		$('cc-item-price-dialog').close();
		state.priceEditor = null;
		$('cc-lines').children[state.lines.indexOf(line)]?.querySelector('.cc-line-edit-price').focus();
	}
	function applyItemPrice(override) {
		const editor = state.priceEditor;
		if (!editor || state.order || $('cc-builder').disabled || !state.lines.includes(editor.line)) return;
		const { line, currency, decimals } = editor;
		if (override && line.price_override?.type === override.type && Number(line.price_override.value) === Number(override.value)) { closeItemPrice(); return; }
		if (override) line.price_override = override;
		else delete line.price_override;
		const match = !override && matchingCatalogLine(line);
		if (match) {
			match.quantity = Number(match.quantity) + Number(line.quantity);
			state.lines.splice(state.lines.indexOf(line), 1);
			renderLines();
		} else {
			const row = $('cc-lines').children[state.lines.indexOf(line)];
			row.querySelector('.cc-line-price-adjustment').textContent = itemPriceText(override, currency, decimals);
			renderLinePrice(row.querySelector('.cc-line-price'));
		}
		closeItemPrice(match || line);
		changed();
	}
	function renderLines(quoted) {
		$('cc-lines').replaceChildren();
		const count = state.lines.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
		$('cc-item-count').textContent = `${count} item${count === 1 ? '' : 's'}`;
		if (!state.lines.length) {
			const empty = el('div', { class: 'cc-empty' });
			empty.append(el('span', { class: 'dashicons dashicons-cart', 'aria-hidden': 'true' }), el('p', {}, 'Add a product to start the order.'));
			$('cc-lines').append(empty);
			return;
		}
		state.lines.forEach((line, index) => {
			const quote = quoted?.[index];
			const row = el('div', { class: 'cc-line' });
			const name = el('div');
			name.append(el('span', { class: 'cc-line-name' }, quote?.name || line.name));
			const meta = quote?.meta || line.meta || [];
			meta.forEach((value) => name.append(el('span', { class: 'cc-line-meta' }, typeof value === 'string' ? value : `${value.name || value.key || value.label || ''}: ${value.value || value.display || ''}`)));
			const priceEdit = button('Edit price', () => openItemPrice(line), 'button-link cc-line-edit-price');
			priceEdit.setAttribute('aria-label', `Edit price for ${line.name}`);
			priceEdit.setAttribute('aria-haspopup', 'dialog');
			name.append(el('span', { class: 'cc-line-meta cc-line-price-adjustment' }, itemPriceText(line.price_override, undefined, config.decimals)), priceEdit);
			const quantityLabel = el('label', { class: 'cc-field' });
			quantityLabel.append(el('span', { class: 'cc-screen-reader' }, `Quantity for ${line.name}`));
			const quantity = el('input', { type: 'number', min: '1', max: line.quantity_per_line || '9999', step: '1', value: line.quantity, inputmode: 'numeric', disabled: Boolean(line.sold_individually || line.quantity_per_line === 1) });
			quantity.addEventListener('input', () => { line.quantity = Number(quantity.value); changed(); });
			quantity.addEventListener('change', () => {
				line.quantity = Number(quantity.value);
				if (!Number.isInteger(line.quantity) || line.quantity < 1) { line.quantity = 1; quantity.value = '1'; }
				changed();
			});
			quantityLabel.append(quantity);
			const remove = button('×', () => { state.lines.splice(index, 1); renderLines(); changed(); }, 'cc-remove');
			remove.setAttribute('aria-label', `Remove ${line.name}`);
			const price = el('span', { class: 'cc-line-price' });
			renderLinePrice(price, quote);
			row.append(name, quantityLabel, price, remove);
			$('cc-lines').append(row);
		});
	}
	async function searchCustomers() {
		const query = $('cc-customer-search').value.trim();
		const request = newRequest('customerSearch');
		$('cc-customer-results').replaceChildren();
		$('cc-customer-results').hidden = true;
		if (query.length < 2) { $('cc-customer-search-status').textContent = 'Enter at least two characters to search WooCommerce and Square.'; return; }
		$('cc-customer-search-status').textContent = 'Searching WooCommerce and Square…';
		try {
			const result = await api(`customers?q=${encodeURIComponent(query)}`, undefined, request.signal);
			if (!activeRequest('customerSearch', request) || query !== $('cc-customer-search').value.trim()) return;
			const customers = result.customers || [];
			const unavailable = Object.entries(result.providerStatuses || {}).filter(([, status]) => !['ok', 'ready', 'available', 'success', 'connected'].includes(status));
			$('cc-customer-search-status').textContent = `${customers.length ? `${customers.length} matching customer${customers.length === 1 ? '' : 's'}.` : 'No customers found. Add a new customer to continue.'}${unavailable.length ? ` ${unavailable.map(([provider, status]) => `${provider}: ${status}`).join('. ')}.` : ''}`;
			customers.forEach((customer) => {
				const choice = button('', () => selectCustomer(customer), 'cc-search-result');
				const info = el('span');
				info.append(el('strong', {}, customer.name || customer.email || 'Unnamed customer'), el('small', {}, [customer.email, customer.phone].filter(Boolean).join(' · ')));
				if (customer.ambiguous) info.append(el('small', {}, 'Multiple possible matches — select the correct profile.'));
				choice.append(info, badges(customer.sources, customer.roles));
				$('cc-customer-results').append(choice);
			});
			$('cc-customer-results').hidden = !customers.length;
		} catch (error) {
			if (error.name !== 'AbortError' && activeRequest('customerSearch', request)) $('cc-customer-search-status').textContent = error.message;
		}
	}
	async function selectCustomer(candidate) {
		cancelCustomerSearch();
		const request = newRequest('customerDetails');
		state.customerLoading = true;
		changed();
		$('cc-customer-search-status').textContent = 'Loading customer details…';
		try {
			const customer = await api(`customers/details?reference=${encodeURIComponent(candidate.reference)}`, undefined, request.signal);
			if (!activeRequest('customerDetails', request) || state.order || state.preparing) return;
			state.customer = { ...customer, reference: customer.reference || candidate.reference, sources: candidate.sources, roles: customer.roles || candidate.roles };
			revealCustomer();
			disclosure('billing', false);
			root.querySelectorAll('[data-contact]').forEach((node) => { node.value = customer[node.dataset.contact] || customer.billing?.[node.dataset.contact] || ''; });
			renderAddress('billing', customer.billing || {});
			renderAddress('shipping', customer.shipping || {});
			$('cc-selected-customer').replaceChildren(el('strong', {}, candidate.name || [customer.first_name, customer.last_name].filter(Boolean).join(' ')), badges(candidate.sources, customer.roles || candidate.roles));
			$('cc-selected-customer').hidden = false;
			$('cc-customer-results').hidden = true;
			$('cc-customer-search').value = '';
			$('cc-customer-search-status').textContent = 'Customer details filled in. Review before creating the order.';
			state.customerLoading = false;
			changed();
		} catch (error) {
			if (error.name !== 'AbortError' && activeRequest('customerDetails', request)) { $('cc-customer-search-status').textContent = error.message; state.customerLoading = false; changed(); }
		}
	}
	async function searchProducts() {
		const query = $('cc-product-search').value.trim();
		const request = newRequest('productSearch');
		$('cc-product-results').replaceChildren();
		$('cc-product-results').hidden = true;
		if (query.length < 2) { $('cc-product-search-status').textContent = 'Enter at least two characters. Ticket products are excluded.'; return; }
		$('cc-product-search-status').textContent = 'Searching products…';
		try {
			const result = catalog ? await catalog.search(query, request.signal) : await api(`products?q=${encodeURIComponent(query)}`, undefined, request.signal);
			if (!activeRequest('productSearch', request) || query !== $('cc-product-search').value.trim()) return;
			(result.products || []).forEach((product) => {
				const choice = button('', () => configureProduct(product), 'cc-search-result');
				const info = el('span');
				info.append(el('strong', {}, product.name), el('small', {}, [product.sku ? `SKU ${product.sku}` : '', product.stock_status === 'outofstock' ? 'Out of stock' : ''].filter(Boolean).join(' · ')));
				choice.append(info, el('span', {}, typeof product.price === 'object' ? money(product.price.min ?? product.price.minimum) : money(product.price)));
				choice.disabled = !result.cached && product.stock_status === 'outofstock';
				$('cc-product-results').append(choice);
			});
			$('cc-product-results').hidden = !result.products?.length;
			$('cc-product-search-status').textContent = result.products?.length ? `${result.products.length} matching products. Tickets are excluded.` : 'No available products match this search.';
		} catch (error) {
			if (error.name !== 'AbortError' && activeRequest('productSearch', request)) $('cc-product-search-status').textContent = error.message;
		}
	}
	async function configureProduct(product) {
		const request = newRequest('productConfig');
		$('cc-product-search-status').textContent = 'Loading product details…';
		$('cc-product-config').hidden = true;
		$('cc-product-config').replaceChildren();
		try {
			const details = await api(`products/${product.id}`, undefined, request.signal);
			if (!activeRequest('productConfig', request) || state.order || state.preparing) return;
			if (details.stock_status === 'outofstock') throw new Error(`${details.name || product.name} is out of stock.`);
			state.configProduct = details;
			$('cc-product-results').hidden = true;
			$('cc-product-search-status').textContent = 'Review the product details, then add it to the order.';
			const availableVariations = (details.variations || []).filter((item) => item.in_stock !== false);
			if (details.type === 'variable' && !availableVariations.length) throw new Error('No available product options remain for this product.');
			const box = $('cc-product-config');
			box.replaceChildren(el('h3', {}, details.name));
			box.hidden = false;
			const inputs = {};
			const attributes = {};
			let variation, onlyVariation;
			if (details.type === 'variable' || details.variations?.length) {
				const label = el('label', { class: 'cc-field', for: 'cc-config-variation' });
				label.append(el('span', {}, 'Product option (required)'));
				variation = el('select', { id: 'cc-config-variation', required: 'required' });
				onlyVariation = availableVariations.length === 1 ? availableVariations[0] : null;
				if (!onlyVariation) {
					variation.append(el('option', { value: '' }, 'Choose an option'));
					$('cc-product-search-status').textContent = 'Select a product option, then add it to the order.';
				}
				(details.variations || []).forEach((item) => variation.append(el('option', { value: item.id, disabled: item.in_stock === false }, `${item.label || Object.values(item.attributes || {}).join(' / ')} · ${money(item.price)}${item.in_stock === false ? ' · Out of stock' : ''}`)));
				if (onlyVariation) { variation.value = String(onlyVariation.id); label.hidden = true; }
				label.append(variation); box.append(label);
				const attributeBox = el('div');
				box.append(attributeBox);
				variation.addEventListener('change', () => {
					attributeBox.replaceChildren();
					Object.keys(attributes).forEach((key) => delete attributes[key]);
					const selected = details.variations.find((item) => String(item.id) === variation.value);
					Object.entries(details.attribute_options || {}).forEach(([key, attribute]) => {
						if (!selected || selected.attributes?.[key]) return;
						const attributeId = `cc-config-${key}`;
						const wrapper = el('label', { class: 'cc-field', for: attributeId });
						wrapper.append(el('span', {}, `${attribute.label} (required)`));
						const select = el('select', { id: attributeId, required: 'required' });
						if (attribute.values?.length !== 1) select.append(el('option', { value: '' }, `Choose ${attribute.label}`));
						(attribute.values || []).forEach((value) => select.append(el('option', { value }, value)));
						if (attribute.values?.length === 1) { select.value = attribute.values[0]; wrapper.hidden = true; }
						attributes[key] = select; wrapper.append(select); attributeBox.append(wrapper);
					});
				});
				if (onlyVariation) variation.dispatchEvent(new Event('change'));
			}
			(details.addons || []).forEach((addon, index) => {
				const id = `cc-addon-${index}`;
				const label = el('label', { class: 'cc-field', for: id });
				label.append(el('span', {}, `${addon.name}${addon.required ? ' (required)' : ''}${Number(addon.price) ? ` · +${money(addon.price)}` : ''}`));
				let input;
				if (['checkbox', 'checkboxes'].includes(addon.type)) {
					const group = el('fieldset', { id, class: 'cc-rate-group' });
					group.append(el('legend', {}, `${addon.name}${addon.required ? ' (required)' : ''}`));
					(addon.options || []).forEach((option, optionIndex) => {
						const row = el('label', { class: 'cc-radio' });
						const value = typeof option === 'object' ? option.value ?? optionIndex : option;
						row.append(el('input', { type: 'checkbox', value }), el('span', {}, typeof option === 'string' ? option : `${option.label || option.name}${Number(option.price) ? ` · +${money(option.price)}` : ''}`));
						group.append(row);
					});
					input = group;
				} else if (addon.options?.length || ['select', 'multiple_choice', 'radiobutton'].includes(addon.type)) {
					input = el('select', { id });
					input.append(el('option', { value: '' }, 'Choose an option'));
					(addon.options || []).forEach((option, optionIndex) => input.append(el('option', { value: typeof option === 'object' ? option.value ?? optionIndex : option }, typeof option === 'string' ? option : `${option.label || option.name}${Number(option.price) ? ` · +${money(option.price)}` : ''}`)));
				} else if (['custom_textarea', 'textarea'].includes(addon.type)) input = el('textarea', { id, rows: 3 });
				else input = el('input', { id, type: ['input_multiplier', 'custom_price', 'number'].includes(addon.type) ? 'number' : addon.type === 'email' ? 'email' : 'text' });
				if (addon.required && input.tagName !== 'FIELDSET') input.required = true;
				if (input.tagName === 'INPUT' && input.type === 'number') {
					input.min = addon.min !== '' && addon.min != null ? addon.min : '0';
					input.step = addon.type === 'input_multiplier' ? '1' : '0.01';
					if (addon.max !== '' && addon.max != null) input.max = addon.max;
				} else if (['INPUT', 'TEXTAREA'].includes(input.tagName)) {
					if (addon.min !== '' && addon.min != null) input.minLength = Number(addon.min);
					if (addon.max !== '' && addon.max != null) input.maxLength = Number(addon.max);
					if (addon.restrictions_type === 'email') input.type = 'email';
				}
				inputs[addon.key] = { input, addon };
				if (input.tagName === 'FIELDSET') box.append(input);
				else { label.append(input); box.append(label); }
			});
			const error = el('p', { class: 'cc-help', role: 'alert' });
			const actions = el('div', { class: 'cc-actions' });
			actions.append(button('Add to order', () => {
				if (variation && !variation.reportValidity()) return;
				const selectedAttributes = {};
				for (const [key, select] of Object.entries(attributes)) {
					if (!select.reportValidity()) return;
					selectedAttributes[key] = select.value;
				}
				const addons = {}, meta = [];
				for (const [key, entry] of Object.entries(inputs)) {
					const { input, addon } = entry;
					if (input.tagName === 'FIELDSET') {
						addons[key] = Array.from(input.querySelectorAll('input:checked'), (node) => node.value);
						if (addon.required && !addons[key].length) { error.textContent = `Choose an option for ${addon.name}.`; return; }
					} else {
						if (!input.reportValidity()) return;
						addons[key] = input.value;
					}
					if (String(addons[key])) meta.push(`${addon.name}: ${Array.isArray(addons[key]) ? addons[key].join(', ') : input.selectedOptions ? input.selectedOptions[0].textContent : addons[key]}`);
				}
				if (variation) meta.unshift(variation.selectedOptions[0].textContent);
				if (details.sold_individually && state.lines.some((line) => line.product_id === details.id)) { error.textContent = 'This product is sold individually and is already in the order.'; return; }
				const newLine = { product_id: details.id, variation_id: Number(variation?.value || 0), quantity: 1, addons, attributes: selectedAttributes, name: details.name, meta, sold_individually: details.sold_individually, quantity_per_line: details.quantity_per_line };
				const same = matchingCatalogLine(newLine);
				if (same) same.quantity++;
				else state.lines.push(newLine);
				box.hidden = true; box.replaceChildren();
				$('cc-product-search').value = '';
				$('cc-product-search-status').textContent = `${details.name} added to the order.`;
				renderLines(); changed(); $('cc-product-search').focus();
			}, 'button button-primary'), button('Cancel', () => { box.hidden = true; box.replaceChildren(); }));
			box.append(error, actions);
			if ((!variation || onlyVariation) && !details.addons?.length && Object.values(attributes).every((select) => Boolean(select.value))) actions.querySelector('button').click();
			else (onlyVariation ? box.querySelector('label:not([hidden]) input, label:not([hidden]) select, textarea') : variation || box.querySelector('input,select,textarea'))?.focus();
		} catch (error) {
			if (error.name !== 'AbortError' && activeRequest('productConfig', request)) $('cc-product-search-status').textContent = error.message;
		}
	}
	function lockBuilder(lock) {
		if (lock) invalidateUPSValidation();
		$('cc-builder').disabled = lock;
		root.querySelectorAll('gmp-place-autocomplete').forEach((widget) => { widget.disabled = lock; });
		syncUPSValidation();
	}
	async function prepareOrder() {
		if (state.preparing || state.order || state.customerLoading || state.draftBusy || state.pendingDraft) return;
		if (!state.pendingCreate && !$('cc-customer-email').reportValidity()) return;
		if (!state.pendingCreate && getInput().fulfillment.type === 'shipping' && (!$('cc-shipping-email').reportValidity() || !$('cc-shipping-phone').reportValidity())) return;
		state.preparing = true;
		syncNewOrderControls();
		lockBuilder(true);
		$('cc-create-order').disabled = true;
		$('cc-create-order').textContent = 'Preparing order…';
		message('cc-global-message', '');
		try {
			if (!state.pendingCreate) {
				const quote = state.quote;
				if (!quote?.can_submit || !state.quotePayload || state.quoteInput !== JSON.stringify(getInput())) throw Object.assign(new Error('Review the order details and calculate a current total before continuing.'), { status: 400 });
				state.pendingCreate = { input: state.quotePayload, quote_hash: quote.hash, request_id: uid(), ...(state.editingOrder ? { version: state.editingOrder.version } : state.draft ? { version: state.draft.version } : {}) };
			}
			const order = await api(state.editingOrder ? `orders/${state.editingOrder.order_id}/prepare` : state.draft ? `drafts/${state.draft.draft_id}/prepare` : 'orders', state.pendingCreate);
			if (!order?.order_id) throw new Error('The order response was incomplete. Retry to recover the same order.');
			state.order = order;
			state.editingOrder = null;
			state.draft = null;
			state.pendingCreate = null;
			const url = new URL(window.location.href);
			url.searchParams.delete('cc_draft');
			url.searchParams.set('cc_order', order.order_id);
			window.history.replaceState({}, '', url);
			showOrder();
		} catch (error) {
			const confirmedEditFailure = state.editingOrder && error.status === 409 && error.code === 'cart_cat_prepare_failed';
			const preserveRequest = !confirmedEditFailure && ['cart_cat_busy', 'cart_cat_request_busy', 'cart_cat_preparation_review', 'cart_cat_request_changed', 'cart_cat_prepare_failed', 'cart_cat_draft_preparing'].includes(error.code);
			if (error.status && error.status < 500 && !preserveRequest) { state.pendingCreate = null; lockBuilder(false); }
			else if (!state.pendingCreate) lockBuilder(false);
			if (error.code === 'cart_cat_stale_quote' || confirmedEditFailure) {
				lockBuilder(true);
				state.quote = null; state.quoteInput = ''; state.quotePayload = null;
				await refreshQuote();
			}
			message('cc-global-message', state.pendingCreate ? `${error.message} Retry below to recover this same order.` : error.message, state.pendingCreate ? 'warning' : 'error');
			if (error.data?.edit_url) $('cc-global-message').append(orderLink('Review the existing order in WooCommerce', error.data.edit_url, 'cc-order-link'));
			$('cc-create-order').disabled = !state.pendingCreate && !state.quote?.can_submit;
			$('cc-create-order').textContent = state.pendingCreate ? 'Retry preparing this order' : 'Prepare order';
		} finally {
			state.preparing = false;
			syncNewOrderControls();
			if (!state.order && !state.pendingCreate) lockBuilder(false);
		}
	}
	function orderLink(label, url, classes = '') {
		const link = el('a', { class: classes }, label);
		try {
			const destination = new URL(url, window.location.href);
			if (destination.origin === window.location.origin && ['https:', 'http:'].includes(destination.protocol)) link.href = destination.href;
		} catch (_) { /* Do not render unsafe provider URLs as order links. */ }
		return link;
	}
	function paymentNavigation() {
		syncNewOrderControls();
		const back = $('cc-back-to-details');
		back.hidden = Boolean(state.order?.paid || state.order?.expired);
		back.disabled = state.order?.can_edit === false || state.order?.payment?.can_cancel || state.paymentBusy || state.paymentChecking || state.paymentMounting || state.emailBusy;
		const explanation = $('cc-edit-status');
		explanation.textContent = state.order?.payment?.can_cancel ? 'Complete or cancel the applied payments before changing order details.' : state.order?.can_edit === false && !state.order?.paid && !state.order?.expired ? state.order.edit_message || 'This order cannot be changed here. Review it in WooCommerce.' : '';
		explanation.hidden = !explanation.textContent;
	}
	function hydrateOrder(order) {
		const input = order.input;
		if (!input) throw new Error('These order details cannot be restored here. Open the order in WooCommerce to review it.');
		revealCustomer();
		$('cc-somminar-order').checked = (input.order_type || order.review?.order_type) === 'somminar';
		$('cc-order-notes').value = input.order_notes ?? order.order_notes ?? '';
		$('cc-order-notes-visible').checked = input.order_notes_visible ?? order.order_notes_visible ?? false;
		setHoldMinutes(input.hold_minutes ?? order.hold?.minutes ?? holdDefault);
		state.customer = { ...(order.customer || {}), reference: input.customer?.reference };
		root.querySelectorAll('[data-contact]').forEach((node) => { node.value = input.customer?.[node.dataset.contact] || input.customer?.billing?.[node.dataset.contact] || ''; });
		renderAddress('billing', input.customer?.billing || {});
		renderAddress('shipping', input.customer?.shipping || {});
		state.lines = (input.lines || []).map((line, index) => ({ ...line, name: line.name || order.review?.lines?.[index]?.name || `Product #${line.product_id}`, meta: line.meta || order.review?.lines?.[index]?.meta || [] }));
		$('cc-discount-type').value = input.discount?.type || 'percent';
		$('cc-discount-amount').value = input.discount?.amount || '0';
		root.querySelectorAll('[name="cc-discount-mode"]').forEach((node) => { node.checked = node.value === (input.discount?.mode || 'add'); });
		root.querySelectorAll('[name="cc-fulfillment"]').forEach((node) => { node.checked = node.value === (input.fulfillment?.type || 'pickup'); });
		$('cc-shipping-panel').hidden = input.fulfillment?.type !== 'shipping';
		$('cc-selected-customer').replaceChildren();
		if (state.customer.name) $('cc-selected-customer').append(el('strong', {}, state.customer.name), badges(state.customer.sources, state.customer.roles));
		$('cc-selected-customer').hidden = !state.customer.name;
		renderLines(order.review?.lines);
		if (order.review) renderQuote(order.review);
		// Older orders can have saved choices without a detailed review snapshot.
		// Retain those values until the fresh quote supplies their visible controls.
		Object.entries(input.fulfillment?.rates || {}).forEach(([index, rate]) => {
			if (Array.from($('cc-rates').querySelectorAll('input[data-package]')).some((node) => node.dataset.package === index)) return;
			const retained = el('input', { type: 'radio', name: `cc-rate-${index}`, value: rate, checked: true, hidden: true });
			retained.dataset.package = index; $('cc-rates').append(retained);
		});
		if (input.fulfillment?.heat_hold_choice && !root.querySelector('[name="cc-heat-choice"]:checked')) $('cc-heat-hold').append(el('input', { type: 'radio', name: 'cc-heat-choice', value: input.fulfillment.heat_hold_choice, checked: true, hidden: true }));
	}
	function showEditor(order) {
		if (!order.input) { state.order = { ...order, can_edit: false }; showOrder(); return; }
		hydrateOrder(order);
		state.editingOrder = order;
		state.emailSent = false;
		state.order = null;
		state.quote = null; state.quoteInput = ''; state.quotePayload = null; state.pendingQuoteInput = '';
		state.paymentChecking = false; state.paymentBusy = false; state.cards = [];
		clearTimeout(state.paymentPoll);
		$('cc-builder').hidden = false;
		lockBuilder(false);
		$('cc-payment-panel').hidden = true;
		$('cc-create-order').hidden = false;
		$('cc-create-order').textContent = 'Prepare order';
		$('cc-prepare-help').hidden = false;
		$('cc-prepare-help').textContent = `Save changes to order #${order.number || order.order_id}, then continue to payment.`;
		$('cc-order-links').hidden = true;
		$('cc-order-state').textContent = `Editing order #${order.number || order.order_id}`;
		state.savedDraftFingerprint = draftFingerprint();
		syncDraftControls();
		message('cc-payment-message', '');
		message('cc-global-message', 'Update the order details, then prepare this same order again. Payment is paused while you make changes. If you already emailed a payment link, send an updated link after preparing these changes.');
		changed();
		$('cc-customer-heading').focus();
		root.scrollIntoView({ block: 'start' });
	}
	async function editOrder() {
		if (!state.order || state.order.paid || state.paymentBusy || state.paymentChecking || state.paymentMounting || state.emailBusy) return;
		setPaymentBusy(true);
		message('cc-payment-message', 'Opening order details…');
		try {
			const order = await api(`orders/${state.order.order_id}/edit`, { version: state.order.version });
			if (!order?.order_id || order.preparation !== 'editing') throw new Error('The order could not be opened for editing. Reload this order to check its status.');
			state.requests.cards?.abort();
			await state.paymentController?.destroy().catch(() => {});
			state.paymentController = null;
			showEditor(order);
		} catch (error) { message('cc-payment-message', error.message, 'error'); }
		finally { setPaymentBusy(false); }
	}
	function renderOrderReview(order) {
		const review = order.review || state.quote;
		const input = order.input || state.quotePayload;
		const box = $('cc-payment-review');
		box.replaceChildren();
		if (!input && !review) return;
		const customer = el('div', { class: 'cc-review-section cc-review-customer' });
		customer.append(el('h3', {}, 'Customer'));
		const name = review?.customer?.name || [input?.customer?.first_name, input?.customer?.last_name].filter(Boolean).join(' ');
		if (name) customer.append(el('p', { class: 'cc-review-name' }, name));
		const contact = [order.billing?.email || input?.customer?.email, input?.customer?.phone].filter(Boolean).join(' · ');
		if (contact) customer.append(el('p', {}, contact));
		const billing = el('div', { class: 'cc-address-summary' });
		addressLines(input?.customer?.billing || order.billing || {}).forEach((line) => billing.append(el('p', {}, line)));
		customer.append(billing);
		const products = el('div', { class: 'cc-review-section cc-review-products' });
		products.append(el('h3', {}, 'Products'));
		(review?.lines || state.lines).forEach((line) => {
			const row = el('div', { class: 'cc-review-line' });
			const label = el('div');
			label.append(el('strong', {}, `${line.quantity} × ${line.name}`));
			(line.meta || []).forEach((value) => label.append(el('span', { class: 'cc-line-meta' }, typeof value === 'string' ? value : `${value.name || value.key || value.label || ''}: ${value.value || value.display || ''}`)));
			const adjustment = itemPriceText(line.price_override, order.currency, review?.decimals ?? config.decimals);
			if (adjustment) label.append(el('span', { class: 'cc-line-meta cc-line-price-adjustment' }, adjustment));
			const price = el('span', { class: 'cc-line-price' });
			renderLinePrice(price, line, order.currency, review?.decimals);
			row.append(label, price); products.append(row);
		});
		const fulfillment = el('div', { class: 'cc-review-section cc-review-fulfillment' });
		const shipping = input?.fulfillment?.type === 'shipping';
		const somminar = (input?.order_type || review?.order_type) === 'somminar';
		fulfillment.append(el('h3', {}, somminar ? `SOMMinar™ ${shipping ? 'Shipment' : 'Pickup'}` : shipping ? 'Shipping' : 'Pickup at the winery'));
		if (shipping) {
			const address = el('div', { class: 'cc-address-summary' });
			const destination = input.customer.shipping || {};
			[[destination.first_name, destination.last_name].filter(Boolean).join(' '), ...addressLines(destination), destination.email, destination.phone].filter(Boolean).forEach((line) => address.append(el('p', {}, line)));
			fulfillment.append(address);
		}
		(review?.rates || []).forEach((pkg) => (pkg.rates || []).filter((rate) => rate.selected).forEach((rate) => fulfillment.append(el('p', {}, rate.label))));
		const heat = review?.heat_hold;
		const choice = heat?.choices?.find((option) => option.value === heat.choice);
		if (choice) fulfillment.append(el('p', { class: 'cc-help' }, choice.label));
		box.append(customer, products);
		if (input) box.append(fulfillment);
		if (order.hold && !order.paid) {
			const hold = el('div', { class: 'cc-review-section cc-review-hold' });
			hold.append(el('h3', {}, 'Stock hold'));
			const label = order.hold.expires_label;
			const checking = order.payment_status === 'checking';
			const text = label ? (checking ? `Stock remains reserved while payment is being checked. Original expiry: ${label}.` : order.hold.active ? `Stock reserved until ${label} (${holdDuration(order.hold.minutes)}).` : `Stock hold expiry: ${label}.`) : 'No stock hold expiry is recorded for this order.';
			hold.append(el('p', {}, text));
			box.append(hold);
		}
		const notes = order.order_notes ?? input?.order_notes;
		if (typeof notes === 'string' && notes.trim()) {
			const staff = el('div', { class: 'cc-review-section cc-review-notes' });
			const visible = order.order_notes_visible ?? input?.order_notes_visible ?? false;
			staff.append(el('h3', {}, visible ? 'Customer-visible notes' : 'Staff notes'), el('p', {}, notes));
			box.append(staff);
		}
	}
	function holdExpired(order) {
		if (!order || order.paid || ['paid', 'checking', 'partial'].includes(order.payment_status)) return false;
		return order.status === 'cancelled' || (['pending', 'failed'].includes(order.status) && Number(order.hold?.expires_at) > 0 && Number(order.hold.expires_at) * 1000 <= Date.now());
	}
	function expired() {
		state.order.expired = true;
		state.paymentBusy = false; state.paymentChecking = false;
		clearTimeout(state.paymentPoll);
		state.requests.cards?.abort();
		state.paymentController?.destroy().catch(() => {}); state.paymentController = null;
		$('cc-order-state').textContent = `Order #${state.order.number || state.order.order_id} · Expired`;
		$('cc-payment-heading').textContent = 'Order expired';
		$('cc-quote-status').textContent = 'The stock hold has ended. Start a new order to check current availability.';
		const box = el('div', { class: 'cc-expired-order' });
		box.append(el('p', {}, 'This unpaid order can no longer be paid. Start a new order to check current prices and stock.'));
		const actions = el('div', { class: 'cc-actions' });
		actions.append(orderLink('View order in WooCommerce', state.order.edit_url, 'button button-primary'), newOrderButton());
		box.append(actions); $('cc-payment-content').replaceChildren(box);
		message('cc-payment-message', '');
		paymentNavigation();
	}
	function showOrder() {
		syncDraftControls();
		const order = state.order;
		const blocked = order.preparation === 'editing';
		lockBuilder(true);
		$('cc-builder').hidden = true;
		$('cc-order-state').textContent = `Order #${order.number || order.order_id}`;
		$('cc-quote-status').textContent = blocked ? 'Payment is paused. Review this order in WooCommerce.' : 'Order prepared. Ready for payment.';
		$('cc-create-order').hidden = true;
		$('cc-prepare-help').hidden = true;
		$('cc-order-links').hidden = false;
		$('cc-order-links').replaceChildren(orderLink('View order in WooCommerce', order.edit_url, 'cc-order-link'));
		$('cc-payment-panel').hidden = false;
		if (order.review) renderQuote(order.review);
		else if (!state.quote) {
			const total = el('div', { class: 'cc-total' });
			total.append(el('dt', {}, 'Total'), el('dd', {}, money(order.total, order.currency)));
			$('cc-totals').replaceChildren(total);
		}
		renderOrderReview(order);
		paymentNavigation();
		if (order.paid || order.payment_status === 'paid') { paid(); return; }
		if (order.payment_status === 'checking') {
			$('cc-payment-content').replaceChildren(el('p', { id: 'cc-payment-checking-view' }, 'Payment is being checked. Stock remains reserved until the result is confirmed.'));
			renderApplied($('cc-payment-content'));
			startChecking();
		} else if (holdExpired(order)) {
			expired();
		} else if (blocked) {
			$('cc-payment-heading').textContent = 'Order needs review';
			$('cc-payment-content').replaceChildren(orderLink('Review order in WooCommerce', order.edit_url, 'button button-primary'));
			message('cc-payment-message', order.edit_message || 'The saved details could not be restored for editing. Review this order in WooCommerce. Payment remains paused.', 'warning');
		} else if (!order.payment?.can_cancel && order.payment_email?.accepted === true && order.payment_email.version === order.version) {
			renderEmailResult(order.payment_email.recipient);
		} else {
			state.emailSent = false;
			renderPayment();
		}
		$('cc-payment-heading').focus();
		root.scrollIntoView({ block: 'start' });
	}
	function renderPayment() {
		state.paymentController?.destroy().catch(() => {}); state.paymentController = null;
		const box = $('cc-payment-content');
		box.replaceChildren();
		renderApplied(box);
		if (Number(state.order.total) === 0) {
			box.append(el('p', {}, 'No payment is due. Complete this order to begin fulfillment.'));
			box.append(button('Complete free order', completeFree, 'button button-primary'));
			return;
		}
		const options = el('div', { class: 'cc-payment-options' });
		const email = el('div', { class: 'cc-payment-option' });
		email.append(el('h3', {}, 'Let the customer pay'), el('p', {}, 'Email the order with a secure link to complete payment.'));
		if (state.order.billing?.email) email.append(el('p', { class: 'cc-email-recipient' }, `To: ${state.order.billing.email}`));
		const emailButton = button(state.order.payment_email?.uncertain ? 'Review email status' : 'Send order email', openEmailSetup);
		emailButton.disabled = Boolean(state.order.payment?.can_cancel);
		email.append(emailButton);
		if (state.order.payment?.can_cancel) email.append(el('p', {class: 'cc-help'}, 'Complete or cancel the applied payments before sending an email.'));
		const manual = el('div', { class: 'cc-payment-option' });
		manual.append(el('h3', {}, 'Take payment now'), el('p', {}, config.square?.ready ? 'Enter the customer’s card using Square’s secure payment form.' : 'Square is not connected. You can email the customer a payment link.'));
		const manualButton = button('Manual card entry', mountManual);
		manualButton.disabled = !config.square?.ready;
		manual.append(manualButton);
		const giftButton = button('Use Square gift card', event => mountManual(event, 'gift_card'));
		giftButton.disabled = !config.square?.ready;
		manual.append(giftButton);
		const saved = el('div', { id: 'cc-saved-cards', class: 'cc-payment-option cc-saved-cards' });
		saved.append(el('h3', {}, 'Cards on file'), el('p', { class: 'cc-help', role: 'status' }, config.square?.ready ? 'Checking the selected customer’s saved cards…' : 'Connect Square to view this customer’s saved cards.'));
		options.append(email, manual, saved); box.append(options);
		box.append(el('div', { id: 'cc-manual-card', class: 'cc-payment-card-form', hidden: true }));
		if (config.square?.ready) loadCards();
	}
	function paymentAmount() { return state.order.payment?.remaining ?? state.order.total; }
	function renderApplied(box) {
		const payment = state.order.payment;
		if (!payment || (!payment.tenders.length && !payment.can_cancel)) return;
		const summary = el('div', {class: 'cc-applied-payments'});
		summary.append(el('h3', {}, 'Applied payments'));
		payment.tenders.forEach(tender => summary.append(el('p', {}, `${tender.kind === 'gift_card' ? 'Gift card' : 'Card'}${tender.last4 ? ` •••• ${tender.last4}` : ''}: ${money(tender.amount)} reserved`)));
		summary.append(el('strong', {}, `Remaining to pay: ${money(payment.remaining)}`));
		if (payment.can_cancel) {
			summary.append(el('p', {class: 'cc-help'}, `Complete this payment by ${new Date(payment.expires_at * 1000).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}. Unused authorizations will be cancelled.`));
			const cancel = button('Cancel applied payments', cancelPayments);
			cancel.dataset.ccCancelPayments = '1'; summary.append(cancel);
		}
		box.append(summary);
	}
	async function cancelPayments() {
		if (state.cancelBusy || state.order.paid || !state.order.payment?.can_cancel) return;
		state.cancelBusy = true; setPaymentBusy(true);
		try { paymentResult(await api(`orders/${state.order.order_id}/payment/cancel`, {version: state.order.version, payment_revision: state.order.payment.revision})); }
		catch (error) { startChecking(`Checking cancellation. ${error.message}`); }
		finally { state.cancelBusy = false; }
	}
	function renderEmailResult(recipient) {
		state.emailSent = true;
		state.requests.cards?.abort();
		state.paymentController?.destroy().catch(() => {});
		state.paymentController = null;
		const order = state.order;
		const accepted = `Payment email accepted for sending${recipient ? ` to ${recipient}` : ''}.`;
		$('cc-order-state').textContent = `Order #${order.number || order.order_id} · Awaiting payment`;
		$('cc-quote-status').textContent = 'Payment link sent. Awaiting customer payment.';
		const box = el('div', { class: 'cc-email-result' });
		box.append(el('h3', { id: 'cc-email-result-title', tabindex: '-1' }, 'Awaiting customer payment'), el('p', {}, accepted));
		const actions = el('div', { class: 'cc-actions' });
		actions.append(orderLink('View order in WooCommerce', order.edit_url, 'button button-primary'), newOrderButton(), button(order.payment_email?.uncertain ? 'Review email status' : 'Resend order email', openEmailSetup));
		box.append(actions);
		$('cc-payment-content').replaceChildren(box);
		message('cc-payment-message', '');
		return accepted;
	}
	function showEmailConfirmation(recipient) {
		const accepted = renderEmailResult(recipient);
		const order = state.order;
		$('cc-email-dialog-description').textContent = `${accepted} Order #${order.number || order.order_id} remains unpaid until the customer completes payment.`;
		const destination = orderLink('View order in WooCommerce', order.edit_url);
		if (destination.href) $('cc-email-view-order').href = destination.href;
		else $('cc-email-view-order').removeAttribute('href');
		$('cc-email-dialog').showModal();
		$('cc-email-dialog-title').focus();
	}
	function emailAttemptKey() { return `cart-cat-email-${state.order.order_id}`; }
	function saveEmailAttempt(attempt) {
		state.pendingEmail = attempt;
		if (!attempt) state.emailStatusUncertain = false;
		try {
			if (attempt) window.sessionStorage.setItem(emailAttemptKey(), JSON.stringify({ attempt, uncertain: state.emailStatusUncertain }));
			else window.sessionStorage.removeItem(emailAttemptKey());
		} catch (_) { /* The open page still retains the exact retry request. */ }
	}
	function restoreEmailAttempt() {
		let attempt = state.pendingEmail;
		try {
			const saved = JSON.parse(window.sessionStorage.getItem(emailAttemptKey()) || 'null');
			attempt = saved?.attempt || saved || attempt;
			state.emailStatusUncertain = saved?.uncertain === true;
		} catch (_) { /* Storage is optional. */ }
		if (attempt?.version !== state.order.version || !Number.isInteger(attempt.hold_minutes) || attempt.hold_minutes < 15 || attempt.hold_minutes > 10080 || typeof attempt.request_id !== 'string') attempt = null;
		saveEmailAttempt(attempt);
	}
	function syncEmailSetup() {
		const busy = state.emailBusy || state.paymentChecking || state.paymentBusy;
		$('cc-hold-duration').disabled = busy || Boolean(state.pendingEmail);
		$('cc-cancel-email-setup').disabled = state.emailBusy;
		$('cc-email-setup-back').disabled = busy || state.emailStatusUncertain || Boolean(state.pendingEmail);
		$('cc-email-continue').disabled = busy;
		$('cc-confirm-send-email').disabled = busy || state.emailStatusUncertain || !state.order?.billing?.email || state.order.paid;
		$('cc-confirm-send-email').textContent = state.emailBusy ? 'Sending…' : state.emailStatusUncertain ? 'Email status needs review' : state.pendingEmail ? 'Retry same email request' : 'Send payment email';
	}
	function emailSetupStep(confirm) {
		$('cc-email-hold-step').hidden = confirm;
		$('cc-email-recipient-step').hidden = !confirm;
		$('cc-email-continue').hidden = confirm;
		$('cc-email-setup-back').hidden = !confirm;
		$('cc-confirm-send-email').hidden = !confirm;
		$('cc-email-confirm-recipient').textContent = state.order.billing?.email || 'No billing email is saved for this order.';
		const unchanged = (state.order.payment_email?.accepted || state.pendingEmail) && holdMinutes() === Number(state.order.hold?.minutes);
		$('cc-email-confirm-hold').textContent = unchanged && state.order.hold?.expires_label ? `Stock remains reserved until ${state.order.hold.expires_label}.` : state.pendingEmail ? `Payment window: ${holdDuration(holdMinutes())}. This same confirmation request will be checked again without starting a new window.` : `Payment window: ${holdDuration(holdMinutes())}. It begins when sending is confirmed.`;
		syncEmailSetup();
	}
	function openEmailSetup(event) {
		if (!state.order || state.order.paid || state.paymentBusy || state.paymentChecking || state.emailBusy || state.paymentMounting || state.order.preparation === 'editing') return;
		if (holdExpired(state.order)) { expired(); return; }
		state.emailSetupTrigger = event?.currentTarget;
		restoreEmailAttempt();
		state.emailStatusUncertain ||= state.order.payment_email?.uncertain === true;
		setHoldMinutes(state.pendingEmail?.hold_minutes ?? state.order.hold?.minutes ?? holdDefault);
		message('cc-email-setup-message', state.emailStatusUncertain ? 'The email result is uncertain. Review the email log before sending again; this request remains preserved.' : state.pendingEmail ? 'Retry the same email request to confirm its result. The selected payment window is preserved.' : '', state.emailStatusUncertain ? 'warning' : '');
		emailSetupStep(Boolean(state.pendingEmail) || state.emailStatusUncertain);
		$('cc-email-setup-dialog').showModal();
		$('cc-email-setup-title').focus();
	}
	async function sendEmail() {
		if (!state.order || state.order.paid || state.paymentBusy || state.paymentChecking || state.emailBusy || state.paymentMounting || state.emailStatusUncertain || !$('cc-email-setup-dialog').open || $('cc-email-recipient-step').hidden) return;
		if (!state.order.billing?.email) { message('cc-email-setup-message', 'Add a billing email in the order details before sending.', 'error'); return; }
		if (!state.pendingEmail) saveEmailAttempt({ version: state.order.version, hold_minutes: holdMinutes(), request_id: uid() });
		state.emailBusy = true;
		setPaymentBusy(true);
		syncEmailSetup();
		message('cc-email-setup-message', 'Sending the order email…');
		try {
			const result = await api(`orders/${state.order.order_id}/email`, state.pendingEmail);
			if (result.email_status !== 'sent' || Number(result.order_id) !== Number(state.order.order_id) || result.version !== state.pendingEmail.version || result.payment_email?.accepted !== true || result.payment_email.version !== result.version) throw new Error('The email response did not confirm sending. Retry this same request to check its result.');
			state.order = { ...state.order, ...result };
			saveEmailAttempt(null);
			renderOrderReview(state.order);
			$('cc-email-setup-dialog').close();
			if (state.order.paid || state.order.payment_status === 'paid') showOrder();
			else showEmailConfirmation(result.payment_email.recipient || result.billing?.email || state.order.billing?.email);
		} catch (error) {
			if (error.data?.hold && Number.isFinite(Number(error.data.hold.expires_at))) state.order.hold = error.data.hold;
			if (error.data?.payment_email?.version === state.order.version) state.order.payment_email = error.data.payment_email;
			if (error.code === 'cart_cat_email_status_unavailable') {
				state.emailStatusUncertain = true;
				state.order.payment_email = { ...state.order.payment_email, uncertain: true };
				saveEmailAttempt(state.pendingEmail);
				if (state.emailSent) renderEmailResult(state.order.payment_email.recipient);
				else renderPayment();
			}
			renderOrderReview(state.order);
			updateHoldPreview();
			emailSetupStep(true);
			message('cc-email-setup-message', error.message, 'error');
		} finally {
			state.emailBusy = false;
			setPaymentBusy(false);
			syncEmailSetup();
		}
	}
	async function loadCards() {
		const request = newRequest('cards');
		const box = $('cc-saved-cards');
		box.replaceChildren(el('h3', {}, 'Cards on file'), el('p', { class: 'cc-help', role: 'status' }, 'Checking the selected customer’s saved cards…'));
		try {
			const result = await api(`orders/${state.order.order_id}/cards`, undefined, request.signal);
			if (!activeRequest('cards', request) || !state.order || state.order.paid || state.emailSent) return;
			state.cards = result.cards || [];
			if (!box) return;
			box.replaceChildren(el('h3', {}, 'Cards on file'));
			if (!state.cards.length) { box.append(el('p', { class: 'cc-help', role: 'status' }, result.message || 'No eligible saved cards were found for this customer.')); return; }
			box.append(el('p', { class: 'cc-help' }, 'Select the customer’s card and confirm the charge with their authorization.'));
			state.cards.forEach((card) => {
				const label = el('label', { class: 'cc-radio' });
				label.append(el('input', { type: 'radio', name: 'cc-saved-card', value: card.reference }), el('span', {}, `${card.brand} •••• ${card.last4} · ${card.expiry_month}/${card.expiry_year}`));
				box.append(label);
			});
			box.append(button(`Charge saved card · ${money(paymentAmount())}`, () => {
				const selected = box.querySelector('input:checked');
				if (!selected) { message('cc-payment-message', 'Select a card on file first.', 'error'); return; }
				pay({ source_type: 'card', card_reference: selected.value });
			}, 'button button-primary'));
			box.querySelectorAll('button,input').forEach((node) => { node.disabled = state.paymentBusy || state.paymentChecking; });
		} catch (error) {
			if (error.name !== 'AbortError' && activeRequest('cards', request)) box.replaceChildren(el('h3', {}, 'Cards on file'), el('p', { class: 'cc-help', role: 'status' }, `Saved cards could not be loaded. ${error.message}`));
		}
	}
	async function mountManual(event, method = 'token') {
		if (state.paymentBusy || state.paymentChecking || state.paymentMounting || state.emailSent || state.order.paid) return;
		const box = $('cc-manual-card');
		if (state.paymentController && state.paymentMethod === method) { box.hidden = false; return; }
		state.paymentMounting = true;
		paymentNavigation();
		await state.paymentController?.destroy().catch(() => {}); state.paymentController = null;
		state.paymentMethod = method;
		if (event?.currentTarget) event.currentTarget.disabled = true;
		box.hidden = false;
		const gift = method === 'gift_card';
		box.replaceChildren(el('h3', {}, gift ? 'Square gift card' : 'Enter card details'), el('p', { class: 'cc-help' }, gift ? 'Apply the available gift card funds, then pay any remaining amount by card. The number is entered securely with Square.' : 'The card details are entered securely with Square and are not saved on this website.'));
		const mount = el('div');
		box.append(mount);
		const control = button(gift ? 'Apply gift card' : `Charge card · ${money(paymentAmount())}`, async () => {
			if (state.paymentBusy || state.paymentChecking) return;
			setPaymentBusy(true);
			message('cc-payment-message', 'Checking card details…');
			try {
				const token = await state.paymentController.tokenize();
				await pay({ source_type: method, token }, true);
			} catch (error) { message('cc-payment-message', error.message || 'The card could not be verified.', 'error'); setPaymentBusy(false); }
		}, 'button button-primary');
		control.disabled = true;
		box.append(control);
		try {
			if (!window.CartCatPayment) throw new Error('The secure payment form is unavailable. Reload this order and try again.');
			const controller = await window.CartCatPayment.mount(mount, { config: config.square, amount: String(paymentAmount()), currency: state.order.currency, billing: state.order.billing || getInput().customer.billing, channel: 'staff', method });
			if (state.paymentChecking || state.order.paid || !mount.isConnected) { await controller.destroy(); return; }
			state.paymentController = controller;
			control.disabled = state.paymentBusy;
		} catch (error) { message('cc-payment-message', error.message, 'error'); }
		finally { state.paymentMounting = false; paymentNavigation(); if (event?.target) event.target.disabled = state.paymentBusy || state.paymentChecking; }
	}
	function setPaymentBusy(busy) {
		state.paymentBusy = busy;
		paymentNavigation();
		$('cc-payment-content').querySelectorAll('button,input').forEach((node) => { node.disabled = busy || state.paymentChecking; });
		if (state.order?.payment?.can_cancel) {
			$('cc-payment-content').querySelectorAll('.cc-payment-option:first-child button').forEach(node => { node.disabled = true; });
			const cancel = $('cc-payment-content').querySelector('[data-cc-cancel-payments]');
			if (cancel && state.paymentChecking) cancel.disabled = Boolean(state.cancelBusy);
		}
	}
	async function pay(source, alreadyBusy = false) {
		if (state.emailSent || state.paymentChecking || state.order.paid || (state.paymentBusy && !alreadyBusy)) return;
		setPaymentBusy(true);
		message('cc-payment-message', 'Processing payment. Keep this order open.');
		try {
			const result = await api(`orders/${state.order.order_id}/pay`, { ...source, version: state.order.version, request_id: uid(), payment_revision: state.order.payment?.revision || '' });
			paymentResult(result);
		} catch (error) {
			// The browser cannot know whether a failed response happened before or after a charge.
			startChecking(`The payment result needs checking. ${error.message}`);
		}
	}
	async function refreshEditability() {
		const id = state.order?.order_id;
		if (!id || state.order.paid || state.order.can_edit !== false) return;
		const request = newRequest('orderEditability');
		try {
			const current = await api(`orders/${id}`, undefined, request.signal);
			if (!activeRequest('orderEditability', request) || state.order?.order_id !== id || state.order.paid || state.paymentChecking) return;
			if (current?.order_id !== id || typeof current.can_edit !== 'boolean') throw new Error('The order response was incomplete.');
			if (current.paid) { paid(); return; }
			if (current.version !== state.order.version) {
				state.order.edit_message = 'This order has changed. Reload it to review the latest details.';
			} else {
				state.order.can_edit = current.can_edit;
				state.order.edit_message = current.edit_message;
			}
			paymentNavigation();
		} catch (error) {
			if (error.name === 'AbortError' || !activeRequest('orderEditability', request) || state.order?.order_id !== id) return;
			state.order.edit_message = 'Editing availability could not be refreshed. Reload this order to check its current status.';
			paymentNavigation();
		}
	}
	function paymentResult(result) {
		if (!result || Number(result.order_id) !== Number(state.order.order_id) || !['paid', 'declined', 'unpaid', 'partial', 'checking'].includes(result.status)) {
			startChecking('Checking payment with Square. This order remains locked until the payment result is confirmed.');
			return;
		}
		state.order.payment_status = result.status;
		const hadSplit = Boolean(state.order.payment);
		state.order.payment = result.payment || null;
		if (result.status === 'paid') { paid(result); return; }
		if (result.status === 'checking') { startChecking(result.message); return; }
		if (holdExpired(state.order)) { expired(); return; }
		state.paymentChecking = false;
		clearTimeout(state.paymentPoll);
		setPaymentBusy(false);
		if (result.payment || hadSplit) { state.emailSent = false; renderPayment(); paymentNavigation(); }
		else if ($('cc-payment-checking-view')) showOrder();
		refreshEditability();
		const defaultMessage = state.emailSent
			? (result.status === 'declined' ? 'The customer’s payment was declined. They can try again using the emailed link.' : '')
			: (result.status === 'declined' ? 'The card was declined. Choose a different payment method or email the customer.' : 'No payment was taken. You can try again.');
		message('cc-payment-message', result.message || (result.status === 'partial' ? `Gift card funds are reserved. Pay the remaining ${money(paymentAmount())} to complete the order.` : defaultMessage), result.status === 'declined' ? 'error' : 'warning');
	}
	function startChecking(text) {
		state.paymentChecking = true;
		state.order.payment_status = 'checking';
		if (state.order.payment && (!$('cc-payment-checking-view') || state.checkingRevision !== state.order.payment.revision)) {
			state.requests.cards?.abort();
			state.paymentController?.destroy().catch(() => {}); state.paymentController = null;
			$('cc-payment-content').replaceChildren(el('p', {id: 'cc-payment-checking-view'}, 'Payment is being checked. Stock remains reserved until the result is confirmed.'));
			renderApplied($('cc-payment-content'));
			state.checkingRevision = state.order.payment.revision;
		}
		setPaymentBusy(true);
		message('cc-payment-message', text || 'Checking payment with Square. Please wait before trying any other payment method.', 'warning');
		clearTimeout(state.paymentPoll);
		state.paymentPoll = setTimeout(checkPayment, 3000);
	}
	async function checkPayment() {
		if (!state.order || state.order.paid) return;
		try { paymentResult(await api(`orders/${state.order.order_id}/payment`)); }
		catch (_) { startChecking('Still checking payment with Square. This order remains locked against another charge.'); }
	}
	async function completeFree(event) {
		if (state.paymentBusy) return;
		setPaymentBusy(true);
		try {
			const result = await api(`orders/${state.order.order_id}/complete`, {});
			if (result.paid || result.status === 'paid' || ['processing', 'completed', 'heat-hold'].includes(result.status)) paid(result);
			else { state.order = { ...state.order, ...result }; showOrder(); }
		} catch (error) { message('cc-payment-message', error.message, 'error'); }
		finally { setPaymentBusy(false); }
	}
	function paid(result = {}) {
		state.order.paid = true;
		state.order.payment_status = 'paid';
		state.paymentBusy = false;
		state.paymentChecking = false;
		paymentNavigation();
		clearTimeout(state.paymentPoll);
		state.requests.cards?.abort();
		state.paymentController?.destroy().catch(() => {});
		state.paymentController = null;
		renderOrderReview(state.order);
		$('cc-order-state').textContent = `Order #${state.order.number || state.order.order_id} · Paid`;
		$('cc-order-state').className = 'cc-badge cc-badge-success';
		$('cc-quote-status').textContent = 'Payment complete.';
		$('cc-quote-dot').className = 'cc-dot is-ready';
		message('cc-payment-message', '');
		const box = el('div', { class: 'cc-payment-success' });
		box.append(el('span', { class: 'dashicons dashicons-yes-alt', 'aria-hidden': 'true' }), el('h3', {}, Number(state.order.total) === 0 ? 'Order completed' : 'Payment complete'), el('p', {}, `Order #${state.order.number || state.order.order_id} is ready for the next fulfillment step.`));
		const actions = el('div', { class: 'cc-actions' });
		actions.append(orderLink('View order', state.order.edit_url, 'button button-primary'));
		actions.append(newOrderButton());
		if (result.receipt_url) {
			try {
				const receipt = new URL(result.receipt_url);
				if (receipt.protocol === 'https:' && (receipt.hostname === 'squareup.com' || receipt.hostname.endsWith('.squareup.com'))) actions.append(el('a', { class: 'button', href: receipt.href, target: '_blank', rel: 'noopener noreferrer' }, 'Square receipt'));
			} catch (_) { /* A receipt is optional. */ }
		}
		box.append(actions); $('cc-payment-content').replaceChildren(box);
	}
	function initSearch(id, callback) {
		$(id).addEventListener('input', () => {
			const name = id === 'cc-customer-search' ? 'customerSearch' : 'productSearch';
			if (name === 'customerSearch') {
				state.customerSearchStarted ||= Boolean($(id).value.trim());
				$('cc-new-customer').hidden = !state.customerSearchStarted;
			}
			state.requests[name]?.abort();
			if (name === 'customerSearch' && state.customerLoading) { state.requests.customerDetails?.abort(); state.customerLoading = false; changed(); }
			clearTimeout(state.searchTimers[id]);
			state.searchTimers[id] = setTimeout(callback, name === 'productSearch' && catalog?.isReady() ? 16 : 300);
		});
	}
	setHoldMinutes(holdDefault);
	if (catalog) catalog.warm().catch(() => {});
	['billing', 'discount'].forEach((id) => $(`cc-toggle-${id}`).addEventListener('click', () => disclosure(id, $(`cc-toggle-${id}`).getAttribute('aria-expanded') !== 'true')));
	$('cc-hold-duration').addEventListener('input', updateHoldPreview);
	$('cc-hold-duration').addEventListener('focus', updateHoldPreview);
	renderAddress('billing'); renderAddress('shipping');
	initSearch('cc-customer-search', searchCustomers);
	initSearch('cc-product-search', searchProducts);
	$('cc-new-customer').addEventListener('click', () => {
		cancelCustomerSearch();
		state.requests.customerDetails?.abort();
		state.customerLoading = false;
		state.customer = {};
		revealCustomer();
		disclosure('billing', false);
		root.querySelectorAll('[data-contact]').forEach((node) => { node.value = ''; });
		renderAddress('billing'); renderAddress('shipping');
		$('cc-selected-customer').hidden = true;
		$('cc-customer-results').hidden = true;
		$('cc-customer-search').value = '';
		$('cc-customer-search-status').textContent = 'Enter the new customer’s details for this guest order.';
		$('cc-customer-first_name').focus(); changed();
	});
	$('cc-copy-billing').addEventListener('click', () => { renderAddress('shipping', { ...addressValues('billing'), first_name: $('cc-customer-first_name').value, last_name: $('cc-customer-last_name').value, email: $('cc-customer-email').value, phone: $('cc-customer-phone').value }); changed(); });
	$('cc-validate-ups').addEventListener('click', validateUPSAddress);
	$('cc-item-price-type').addEventListener('change', syncItemPriceFields);
	$('cc-item-price-value').addEventListener('input', (event) => event.target.setCustomValidity(''));
	$('cc-item-price-form').addEventListener('submit', (event) => {
		event.preventDefault();
		const price = $('cc-item-price-value');
		const value = price.valueAsNumber === 0 ? '0' : price.value;
		if (!/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/.test(value)) {
			price.setCustomValidity('Enter a decimal number.');
			price.reportValidity();
			return;
		}
		applyItemPrice({ type: $('cc-item-price-type').value, value });
	});
	$('cc-item-price-reset').addEventListener('click', () => applyItemPrice(null));
	$('cc-item-price-cancel').addEventListener('click', () => closeItemPrice());
	$('cc-item-price-dialog').addEventListener('cancel', (event) => { event.preventDefault(); closeItemPrice(); });
	$('cc-builder').addEventListener('change', (event) => {
		if (event.target.closest('#cc-product-config, .cc-address-search') || event.target.matches('#cc-customer-search, #cc-product-search')) return;
		if (event.target.name === 'cc-fulfillment') {
			$('cc-shipping-panel').hidden = event.target.value !== 'shipping';
			$('cc-rates').replaceChildren();
			$('cc-heat-hold').replaceChildren(); $('cc-heat-hold').hidden = true;
		}
		if (event.target.closest('#cc-billing-fields')) renderBillingSummary();
		changed();
	});
	$('cc-builder').addEventListener('input', (event) => {
		if (event.target.closest('#cc-billing-fields')) renderBillingSummary();
		if (event.target.matches('[data-contact], [data-address-key], #cc-discount-amount, #cc-order-notes')) changed();
	});
	$('cc-email-continue').addEventListener('click', () => { emailSetupStep(true); $('cc-confirm-send-email').focus(); });
	$('cc-email-setup-back').addEventListener('click', () => { if (!state.pendingEmail && !state.emailBusy) { emailSetupStep(false); $('cc-hold-duration').focus(); } });
	$('cc-confirm-send-email').addEventListener('click', sendEmail);
	$('cc-cancel-email-setup').addEventListener('click', () => { if (!state.emailBusy) $('cc-email-setup-dialog').close(); });
	$('cc-email-setup-dialog').addEventListener('cancel', (event) => { if (state.emailBusy) event.preventDefault(); });
	$('cc-email-setup-dialog').addEventListener('close', () => { if (!$('cc-email-dialog').open && state.emailSetupTrigger?.isConnected) state.emailSetupTrigger.focus(); });
	$('cc-email-new-order').addEventListener('click', startNewOrder);
	$('cc-close-email-dialog').addEventListener('click', () => $('cc-email-dialog').close());
	$('cc-email-dialog').addEventListener('close', () => $('cc-email-result-title')?.focus());
	$('cc-save-draft').addEventListener('click', saveDraft);
	$('cc-open-drafts').addEventListener('click', openDrafts);
	$('cc-new-draft').addEventListener('click', startNewOrder);
	$('cc-draft-title').addEventListener('input', syncDraftControls);
	$('cc-close-drafts').addEventListener('click', () => { if (!state.draftBusy) { $('cc-drafts-dialog').close(); $('cc-open-drafts').focus(); } });
	$('cc-drafts-dialog').addEventListener('cancel', (event) => { if (state.draftBusy) event.preventDefault(); });
	$('cc-draft-search').addEventListener('input', () => {
		state.requests.drafts?.abort(); clearTimeout(state.searchTimers.drafts);
		$('cc-draft-list').replaceChildren(el('p', { class: 'cc-help' }, 'Searching drafts…'));
		state.searchTimers.drafts = setTimeout(() => listDrafts(1), 250);
	});
	$('cc-drafts-prev').addEventListener('click', () => listDrafts(Math.max(1, state.draftPage - 1)));
	$('cc-drafts-next').addEventListener('click', () => listDrafts(state.draftPage + 1));
	window.addEventListener('beforeunload', (event) => {
		if (!state.navigating && (state.pendingDraft || (state.draft && (draftDirty() || state.pendingCreate)))) { event.preventDefault(); event.returnValue = ''; }
	});
	$('cc-create-order').addEventListener('click', prepareOrder);
	$('cc-back-to-details').addEventListener('click', editOrder);
	const existing = new URL(window.location.href).searchParams.get('cc_order');
	const existingDraft = new URL(window.location.href).searchParams.get('cc_draft');
	if (!existing) root.querySelectorAll('[data-contact]').forEach((field) => { field.value = ''; });
	state.savedDraftFingerprint = draftFingerprint();
	syncDraftControls();
	if (!existing && existingDraft && /^\d+$/.test(existingDraft)) loadDraft(Number(existingDraft), true);
	if (existing && /^\d+$/.test(existing)) {
		state.orderLoading = true; syncNewOrderControls();
		lockBuilder(true); $('cc-create-order').disabled = true;
		message('cc-global-message', 'Loading the prepared order…');
		api(`orders/${existing}`).then((order) => {
			if (!order?.order_id) throw new Error('The order could not be loaded.');
			message('cc-global-message', '');
			if (order.preparation === 'editing' && !holdExpired(order) && !['paid', 'checking'].includes(order.payment_status)) { showEditor(order); return; }
			state.order = order; showOrder();
			if (!order.paid && !order.expired && Number(order.total) > 0) startChecking('Checking this order’s payment status…');
		}).catch((error) => { message('cc-global-message', error.message, 'error'); }).finally(() => { state.orderLoading = false; syncNewOrderControls(); });
	}
})();
