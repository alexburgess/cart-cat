(function () {
	'use strict';
	let placesPromise = null;
	let nextId = 0;
	const timeoutMs = 15000;
	const unavailable = 'Google address search is unavailable. Enter the address manually below.';

	function bounded(promise) {
		return new Promise((resolve, reject) => {
			const timer = setTimeout(() => reject(new Error('Address search timed out.')), timeoutMs);
			Promise.resolve(promise).then(resolve, reject).finally(() => clearTimeout(timer));
		});
	}
	function mapsScript() {
		return Array.from(document.scripts).find((script) => {
			try {
				const url = new URL(script.src, window.location.href);
				return ['maps.googleapis.com', 'maps.google.com'].includes(url.hostname) && url.pathname === '/maps/api/js';
			} catch (_) { return false; }
		});
	}
	function mapsReady(apiKey) {
		if (typeof window.google?.maps?.importLibrary === 'function') return Promise.resolve();
		return new Promise((resolve, reject) => {
			let script = mapsScript();
			const ownScript = !script;
			if (ownScript) {
				script = document.createElement('script');
				const params = new URLSearchParams({ key: apiKey, v: 'weekly', loading: 'async', callback: 'CartCatAddress.mapsReady' });
				script.src = `https://maps.googleapis.com/maps/api/js?${params}`;
				script.async = true;
				script.nonce = document.querySelector('script[nonce]')?.nonce || '';
			}
			let settled = false;
			const finish = (error) => {
				if (settled) return;
				settled = true;
				clearInterval(poll);
				clearTimeout(timer);
				script.removeEventListener('error', failed);
				script.removeEventListener('load', check);
				window.CartCatAddress.mapsReady = () => {};
				if (error) reject(error); else resolve();
			};
			const check = () => { if (typeof window.google?.maps?.importLibrary === 'function') finish(); };
			const failed = () => finish(new Error('Google Maps could not load.'));
			const poll = setInterval(check, 50);
			const timer = setTimeout(failed, timeoutMs);
			script.addEventListener('error', failed);
			script.addEventListener('load', check);
			if (ownScript) {
				window.CartCatAddress.mapsReady = check;
				document.head.appendChild(script);
			}
			check();
		});
	}
	function loadPlaces(apiKey) {
		if (!placesPromise) {
			placesPromise = mapsReady(apiKey).then(() => bounded(window.google.maps.importLibrary('places'))).then((library) => {
				if (typeof library?.PlaceAutocompleteElement !== 'function') throw new Error('Address search is not supported.');
				return library;
			}).catch((error) => { placesPromise = null; throw error; });
		}
		return placesPromise;
	}
	function addressFromComponents(components) {
		if (!Array.isArray(components)) return null;
		const part = (type, short = false) => {
			const component = components.find((item) => Array.isArray(item?.types) && item.types.includes(type));
			return String((short ? component?.shortText : component?.longText) || component?.longText || '').trim();
		};
		const route = part('route');
		const country = part('country', true).toUpperCase();
		// A city, postcode, or country prediction alone is not a street address.
		if (!route || !/^[A-Z]{2}$/.test(country)) return null;
		const postcode = part('postal_code');
		const suffix = part('postal_code_suffix');
		return {
			address_1: [part('street_number'), route].filter(Boolean).join(' '),
			address_2: part('subpremise'),
			city: part('locality') || part('postal_town') || part('sublocality_level_1') || part('sublocality') || part('administrative_area_level_3'),
			state: part('administrative_area_level_1', true),
			postcode: postcode && suffix ? `${postcode}-${suffix}` : postcode,
			country,
		};
	}
	function mount(container, options = {}) {
		let disposed = false;
		let sequence = 0;
		let widget = null;
		const attached = () => !disposed && container.isConnected;
		const current = () => attached() && (!options.isCurrent || options.isCurrent());
		const snapshot = () => options.getAddressSnapshot ? options.getAddressSnapshot() : undefined;
		const status = document.createElement('p');
		status.className = 'cc-address-search-status cc-help';
		status.id = `cc-address-search-status-${++nextId}`;
		status.setAttribute('role', 'status');
		status.setAttribute('aria-live', 'polite');
		const message = (text) => { if (attached()) status.textContent = text; };
		container.appendChild(status);
		const cleanup = () => {
			disposed = true;
			sequence++;
			if (widget) {
				widget.removeEventListener('gmp-select', select);
				widget.removeEventListener('gmp-error', failed);
				widget.removeEventListener('input', changed);
				widget.remove();
			}
			status.remove();
		};
		const failed = () => { sequence++; message(unavailable); };
		const changed = () => { sequence++; };
		const select = async (event) => {
			if (!current()) return;
			const request = ++sequence;
			const before = snapshot();
			message('Finding the selected address…');
			try {
				const place = event.placePrediction?.toPlace();
				if (!place || typeof place.fetchFields !== 'function') throw new Error('No address selected.');
				await bounded(place.fetchFields({ fields: ['addressComponents'] }));
				if (!current() || request !== sequence) return;
				if (before !== snapshot()) {
					message('Your address edits were kept. Select a suggestion again to replace them.');
					return;
				}
				const address = addressFromComponents(place.addressComponents);
				if (!address || (country && address.country !== country)) {
					message('Choose a street address in the selected country, or enter the address manually below.');
					return;
				}
				options.onSelect(address);
				message('Address filled. Check the address and apartment or suite before continuing.');
			} catch (_) {
				if (request === sequence) message(unavailable);
			}
		};
		const countryValue = String(options.country || '').trim().toUpperCase();
		const country = /^[A-Z]{2}$/.test(countryValue) ? countryValue : '';
		const apiKey = String(options.apiKey || '').trim();
		if (!apiKey) { message('Enter the address manually below. Google address search has not been configured.'); return cleanup; }
		message('Loading Google address search… You can enter the address manually below.');
		loadPlaces(apiKey).then(({ PlaceAutocompleteElement }) => {
			if (!attached()) return;
			widget = new PlaceAutocompleteElement({ includedRegionCodes: country ? [country.toLowerCase()] : [] });
			widget.className = 'cc-address-search-input';
			widget.setAttribute('placeholder', 'Search for an address');
			widget.setAttribute('description', 'Search Google for an address to fill the fields below.');
			widget.setAttribute('aria-label', 'Search for an address with Google');
			widget.setAttribute('aria-describedby', status.id);
			widget.addEventListener('gmp-select', select);
			widget.addEventListener('gmp-error', failed);
			widget.addEventListener('input', changed);
			container.insertBefore(widget, status);
			if (options.onReady) options.onReady(widget);
			message('Choose a Google suggestion or enter the address below. Check the apartment or suite before continuing.');
		}).catch(() => message(unavailable));
		return cleanup;
	}
	window.CartCatAddress = { mount, mapsReady: () => {} };
})();
