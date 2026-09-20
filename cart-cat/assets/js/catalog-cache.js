(function () {
	'use strict';
	const normalize = (value) => String(value || '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().replace(/\s+/g, ' ').trim();
	window.CartCatCatalog = {
		create(api) {
			let products = [], revision = '', complete = false, expires = 0, pending = null, retryAfter = 0;
			async function fetchPage(path) {
				const controller = new AbortController();
				const timeout = setTimeout(() => controller.abort(), 10000);
				try { return await api(path, undefined, controller.signal); }
				finally { clearTimeout(timeout); }
			}
			function warm() {
				if (pending) return pending;
				if (Date.now() < expires || Date.now() < retryAfter) return Promise.resolve();
				pending = (async () => {
					try {
						let page = await fetchPage(`products/index${revision ? `?check=1&revision=${encodeURIComponent(revision)}` : ''}`);
						if (page.not_modified && page.revision === revision) { expires = Date.now() + 60000; return; }
						const nextProducts = [], seen = new Set(), currentRevision = page.revision;
						let partial = false;
						for (let index = 0; index < 4; index++) {
							if (!Array.isArray(page.products) || !currentRevision || page.revision !== currentRevision || page.products.length > 250) throw new Error('Invalid catalog index.');
							for (const product of page.products) {
								if (!Number.isInteger(product.id) || product.id < 1 || typeof product.name !== 'string') throw new Error('Invalid catalog product.');
								if (!seen.has(product.id)) { seen.add(product.id); nextProducts.push({ ...product, _search: normalize(`${product.name} ${product.sku || ''} ${product.search_terms || ''} ${product.id}`) }); }
							}
							partial = partial || Boolean(page.partial);
							if (page.next_page === null) break;
							if (page.next_page !== index + 1 || index === 3) throw new Error('Invalid catalog page.');
							page = await fetchPage(`products/index?page=${page.next_page}&revision=${encodeURIComponent(currentRevision)}`);
						}
						products = nextProducts; revision = currentRevision; complete = page.complete === true && !partial; expires = Date.now() + 60000;
					} catch (_) {
						// A partial/outdated index never masquerades as an empty or authoritative result.
						products = []; revision = ''; complete = false; expires = 0; retryAfter = Date.now() + 15000;
					} finally { pending = null; }
				})();
				return pending;
			}
			function isReady() { return complete && Date.now() < expires; }
			async function search(query, signal) {
				if (signal?.aborted) throw new DOMException('Search canceled.', 'AbortError');
				if (isReady()) {
					const term = normalize(query), words = term.split(' ').filter(Boolean);
					if (term.length > 120) return api(`products?q=${encodeURIComponent(query)}`, undefined, signal);
					const ranked = products.filter((product) => words.every((word) => product._search.includes(word)));
					const rank = (product) => normalize(product.sku) === term ? 0 : normalize(product.name).startsWith(term) ? 1 : 2;
					ranked.sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name));
					return { cached: true, products: ranked.slice(0, 30).map(({ _search, search_terms, ...product }) => product) };
				}
				warm();
				return api(`products?q=${encodeURIComponent(query)}`, undefined, signal);
			}
			return { warm, search, isReady };
		}
	};
})();
