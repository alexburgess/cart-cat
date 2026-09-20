(function () {
  'use strict';
  let sdkPromise;
  let sdkEnvironment;
  async function sdk(environment) {
    if (sdkPromise && sdkEnvironment !== environment) throw new Error('Square environment changed. Reload this page.');
    if (sdkPromise) return sdkPromise;
    sdkEnvironment = environment;
    sdkPromise = new Promise((resolve, reject) => {
      const src = environment === 'sandbox' ? 'https://sandbox.web.squarecdn.com/v1/square.js' : 'https://web.squarecdn.com/v1/square.js';
      // WordPress appends ?ver= to SquareWooSync's shared SDK. Identify the
      // official endpoint without treating that cache version as another SDK.
      const squareScripts = Array.from(document.querySelectorAll('script[src]')).filter(script => {
        try {
          const url = new URL(script.src, document.baseURI);
          return !url.username && !url.password && url.pathname === '/v1/square.js' &&
            ['https://web.squarecdn.com', 'https://sandbox.web.squarecdn.com'].includes(url.origin);
        } catch (error) { return false; }
      });
      const origin = new URL(src).origin;
      if (squareScripts.some(script => new URL(script.src, document.baseURI).origin !== origin)) {
        reject(new Error('Square is configured for a different environment on this page. Reload this page.')); return;
      }
      const existing = squareScripts[0];
      if (window.Square && existing && typeof window.Square.payments === 'function') { resolve(window.Square); return; }
      if (window.Square) { reject(new Error('A different Square form is already loaded. Reload this page.')); return; }
      const script = existing || document.createElement('script');
      function finish(error) {
        window.clearTimeout(timer);
        script.removeEventListener('load', loaded);
        script.removeEventListener('error', failed);
        if (error) reject(error); else resolve(window.Square);
      }
      function loaded() {
        const ready = window.Square && typeof window.Square.payments === 'function';
        if (!ready && !existing) script.remove();
        finish(ready ? null : new Error('Square is unavailable.'));
      }
      function failed() {
        if (!existing) script.remove();
        finish(new Error('Square could not load. Please reload this page.'));
      }
      const timer = window.setTimeout(() => finish(new Error('Square could not load. Please reload this page.')), 20000);
      script.addEventListener('load', loaded, {once:true});
      script.addEventListener('error', failed, {once:true});
      if (!existing) { script.src = src; script.async = true; document.head.appendChild(script); }
    }).catch(error => {
      // A later mount can retry a failed load or join a script that was merely
      // slow. Keep any still-loading script in place to avoid duplicate SDKs.
      sdkPromise = undefined;
      throw error;
    });
    return sdkPromise;
  }
  function verificationDetails(options) {
    const b = options.billing || {};
    return {
      amount: String(options.amount), currencyCode: options.currency, intent: 'CHARGE',
      customerInitiated: true, sellerKeyedIn: options.channel !== 'customer',
      billingContact: {givenName:b.first_name || '',familyName:b.last_name || '',email:b.email || '',phone:b.phone || '',addressLines:[b.address_1,b.address_2].filter(Boolean),city:b.city || '',state:b.state || '',postalCode:b.postcode || '',countryCode:b.country || 'US'}
    };
  }
  function tokenResult(result) {
    if (['Cancel', 'CANCEL'].includes(result?.status)) {
      const error = new Error('Payment cancelled. Choose a payment option when you are ready.');
      error.cancelled = true; throw error;
    }
    if (!result || result.status !== 'OK' || !result.token) throw new Error('Payment details could not be secured. Check them and try again.');
    return result.token;
  }
  window.CartCatPayment = {
    async mount(element, options) {
      if (!element || !options.config || !options.config.ready || !window.isSecureContext) throw new Error('Square requires a ready connection and HTTPS.');
      const Square = await sdk(options.config.environment);
      const payments = Square.payments(options.config.application_id, options.config.location_id);
      const card = await payments.card();
      await card.attach(element);
      let busy = false;
      const wallets = [];
      return {
        async tokenize() {
          if (busy) throw new Error('Payment details are already being secured.');
          busy = true;
          try { return tokenResult(await card.tokenize(verificationDetails(options))); }
          finally { busy = false; }
        },
        async wallet(name, target) {
          if (options.channel !== 'customer' || !['applePay', 'googlePay'].includes(name) || !options.config.wallets?.[name]) throw new Error('Wallet unavailable.');
          const request = payments.paymentRequest({countryCode: options.config.country || 'US', currencyCode: options.currency, total: {amount: String(options.amount), label: 'Order total'}, requestBillingContact: false, requestShippingContact: false});
          const method = await payments[name](request);
          wallets.push(method);
          if (name === 'googlePay') await method.attach(target, {buttonColor: 'black', buttonType: 'long', buttonSizeMode: 'fill', buttonRadius: 2});
          return {
            async tokenize() {
              if (busy) throw new Error('Payment details are already being secured.');
              busy = true;
              try {
                // Call immediately from the wallet click; Apple Pay requires user activation.
                const token = tokenResult(await method.tokenize());
                const details = verificationDetails(options);
                const verification = await payments.verifyBuyer(token, {amount: details.amount, currencyCode: details.currencyCode, intent: details.intent, billingContact: details.billingContact});
                return {token, verification: verification?.token || ''};
              } finally { busy = false; }
            }
          };
        },
        async destroy() { await Promise.allSettled(wallets.map(wallet => wallet.destroy())); await card.destroy(); }
      };
    }
  };
  async function initializeOrderPay() {
    const root = document.querySelector('[data-cart-cat-payment]');
    if (!root || root.dataset.initialized) return;
    const form = root.closest('form');
    if (!form) return;
    root.dataset.initialized = '1';
    const submit = form.querySelector('[type="submit"]');
    if (root.dataset.checking === '1') { if (submit) submit.disabled = true; return; }
    const status = root.querySelector('[data-cart-cat-card-status]');
    const walletBox = root.querySelector('[data-cart-cat-wallets]');
    const tokenInput = root.querySelector('[name="cart_cat_token"]');
    const verificationInput = root.querySelector('[name="cart_cat_verification_token"]');
    const terms = form.querySelector('#terms');
    if (terms) terms.addEventListener('change', () => terms.setCustomValidity(''));
    let controller; let submitting = false; let secured = false;
    function busy(value) {
      if (submit) submit.disabled = value || !controller;
      if (walletBox) {
        walletBox.inert = value;
        walletBox.querySelectorAll('button').forEach(button => { button.disabled = value; });
        walletBox.setAttribute('aria-busy', String(value));
      }
    }
    function clearTokens() { tokenInput.value = ''; if (verificationInput) verificationInput.value = ''; }
    function valid() {
      if (terms) terms.setCustomValidity(terms.checked ? '' : 'Please read and accept the terms and conditions.');
      return form.reportValidity();
    }
    async function pay(method) {
      if (submitting || secured || !controller || !valid()) return;
      submitting = true; clearTokens(); busy(true);
      status.textContent = 'Securing payment details…';
      try {
        const result = await method.tokenize();
        if (!valid()) { status.textContent = 'Review the required details, then try again.'; submitting = false; busy(false); return; }
        tokenInput.value = typeof result === 'string' ? result : result.token;
        if (verificationInput) verificationInput.value = typeof result === 'string' ? '' : result.verification;
        // Leave the original native submit dispatch before requesting another.
        // An already-resolved SDK promise can otherwise hit HTML's re-entry guard.
        await new Promise(resolve => window.setTimeout(resolve, 0));
        secured = true;
        if (submit) submit.disabled = false;
        form.requestSubmit(submit || undefined);
        if (submit) submit.disabled = true;
      } catch (error) {
        clearTokens(); status.textContent = error.message; secured = false; submitting = false; busy(false);
      }
    }
    form.addEventListener('submit', function (event) {
      if (secured) return;
      event.preventDefault(); event.stopImmediatePropagation();
      if (controller) pay(controller);
    }, true);
    busy(true);
    try {
      controller = await window.CartCatPayment.mount(root.querySelector('[data-cart-cat-card]'), JSON.parse(root.dataset.options));
      status.textContent = 'Card details are sent securely to Square.';
      busy(false);
    } catch (error) { status.textContent = error.message; return; }
    if (!walletBox) return;
    // Wallet availability is optional. One unavailable wallet never prevents card payment.
    await Promise.allSettled(['applePay', 'googlePay'].map(async name => {
      const target = walletBox.querySelector(`[data-cart-cat-wallet="${name}"]`);
      if (!target) return;
      try {
        const method = await controller.wallet(name, target);
        target.addEventListener('click', event => { event.preventDefault(); pay(method); });
        target.hidden = false; walletBox.hidden = false;
        if (submitting) busy(true);
      } catch (_) { target.hidden = true; }
    }));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeOrderPay); else initializeOrderPay();
})();
