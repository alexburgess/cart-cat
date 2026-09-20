'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const code = fs.readFileSync(require('node:path').join(__dirname, '../cart-cat/assets/js/payment.js'), 'utf8');
const endpoint = env => 'https://' + (env === 'sandbox' ? 'sandbox.' : '') + 'web.squarecdn.com/v1/square.js';
let assertions = 0;
function equal(actual, expected) { assertions++; assert.equal(actual, expected); }
async function rejects(promise, pattern) { assertions++; await assert.rejects(promise, pattern); }
function harness({urls = [], loaded = true} = {}) {
  const scripts = [], appended = [], timers = new Map();
  let details, destroys = 0, attachCount = 0, timerID = 0;
  const walletState = {requests: [], attached: [], calls: 0, verified: [], mode: "success", verification: {token: "verify:fixture"}, destroyed: 0};
  const wallet = {attach: async (target, options) => walletState.attached.push(options), destroy: async () => walletState.destroyed++, tokenize: async () => { walletState.calls++; if (walletState.mode === "pending") await new Promise(resolve => { walletState.resolve = resolve; }); return walletState.mode === "cancel" ? {status:"Cancel"} : {status:"OK",token:"cnon:wallet"}; }};
  const card = {
    attach: async () => { attachCount++; },
    tokenize: async data => { details = data; return {status: 'OK', token: 'cnon:test'}; },
    destroy: async () => { destroys++; }
  };
  const square = {payments: () => ({card: async () => card, paymentRequest: request => { walletState.requests.push(request); return request; }, applePay: async () => wallet, googlePay: async () => wallet, verifyBuyer: async (token, data) => { walletState.verified.push({token,data}); if (walletState.mode === "verify-fail") throw new Error("Verification failed"); return walletState.verification; }})};
  const window = {
    isSecureContext: true,
    setTimeout: callback => { const id = ++timerID; timers.set(id, callback); return id; },
    clearTimeout: id => timers.delete(id)
  };
  if (loaded) window.Square = square;
  function script(src = '') {
    const listeners = new Map();
    return {
      src,
      addEventListener: (event, fn) => { if (!listeners.has(event)) listeners.set(event, new Set()); listeners.get(event).add(fn); },
      removeEventListener: (event, fn) => listeners.get(event)?.delete(fn),
      emit: event => { for (const fn of Array.from(listeners.get(event) || [])) fn(); },
      listenerCount: () => Array.from(listeners.values()).reduce((sum, group) => sum + group.size, 0),
      remove() { const index = scripts.indexOf(this); if (index >= 0) scripts.splice(index, 1); }
    };
  }
  for (const src of urls) scripts.push(script(src));
  const document = {
    readyState: 'complete', baseURI: 'https://store.example/checkout/order-pay/1/',
    querySelector: () => null,
    querySelectorAll: selector => selector === 'script[src]' ? scripts : [],
    createElement: () => script(),
    head: {appendChild: element => { scripts.push(element); appended.push(element); }}
  };
  vm.runInNewContext(code, {window, document, URL, Error, Promise, JSON});
  const options = {
    config: {ready: true, environment: 'sandbox', application_id: 'app', location_id: 'loc'},
    amount: '12.50', currency: 'USD', billing: {first_name: 'Test'}, channel: 'staff'
  };
  return {
    window, square, scripts, appended, timers, options, walletState,
    mount: overrides => window.CartCatPayment.mount({}, {...options, ...overrides}),
    state: () => ({details, destroys, attachCount}),
    timeout: () => { for (const fn of Array.from(timers.values())) fn(); }
  };
}
(async () => {
  // Existing SquareWooSync SDK URLs are versioned by WordPress. Both official
  // environments must reuse those URLs without injecting another Square SDK.
  for (const environment of ['sandbox', 'production']) {
    for (const suffix of ['', '?ver=10.4.0', '?ver=10.4.0#sdk']) {
      const h = harness({urls: [endpoint(environment) + suffix]});
      await h.mount({config: {...h.options.config, environment}});
      equal(h.state().attachCount, 1);
      equal(h.appended.length, 0);
    }
  }
  const h = harness({urls: [endpoint('sandbox') + '?ver=10.4.0']});
  const controller = await h.mount();
  equal(await controller.tokenize(), 'cnon:test');
  equal(h.state().details.amount, '12.50');
  equal(h.state().details.customerInitiated, true);
  equal(h.state().details.sellerKeyedIn, true);
  equal(h.state().details.intent, 'CHARGE');
  await controller.destroy();
  equal(h.state().destroys, 1);
  equal(h.state().attachCount, 1);
  const customer = await h.mount({channel: 'customer'});
  await customer.tokenize();
  equal(h.state().details.customerInitiated, true);
  equal(h.state().details.sellerKeyedIn, false);
  await rejects(h.mount({config: {...h.options.config, environment: 'production'}}), /environment changed/);
  h.window.isSecureContext = false;
  await rejects(h.mount(), /HTTPS/);

  // Never reuse a different environment, including a conflicting script that
  // is still loading or a page that accidentally includes both SDKs.
  for (const loaded of [true, false]) {
    for (const urls of [[endpoint('production') + '?ver=10.4.0'], [endpoint('sandbox'), endpoint('production')]]) {
      const wrong = harness({urls, loaded});
      await rejects(wrong.mount(), /different environment/);
      equal(wrong.appended.length, 0);
    }
  }
  for (const urls of [[], ['https://web.squarecdn.com.attacker.example/v1/square.js'], ['https://sandbox.web.squarecdn.com/other.js'], ['http://sandbox.web.squarecdn.com/v1/square.js']]) {
    const unknown = harness({urls});
    await rejects(unknown.mount(), /different Square form/);
    equal(unknown.appended.length, 0);
  }

  // Reuse an in-flight SDK and share its promise across concurrent mounts.
  const pending = harness({urls: [endpoint('sandbox') + '?ver=10.4.0'], loaded: false});
  const first = pending.mount(), second = pending.mount();
  equal(pending.appended.length, 0);
  equal(pending.timers.size, 1);
  pending.window.Square = pending.square;
  pending.scripts[0].emit('load');
  await Promise.all([first, second]);
  equal(pending.state().attachCount, 2);
  equal(pending.timers.size, 0);
  equal(pending.scripts[0].listenerCount(), 0);

  // When no SDK exists, load the canonical endpoint once. An explicit network
  // failure removes our failed tag and permits a later mount to retry.
  const fresh = harness({loaded: false});
  const failure = rejects(fresh.mount(), /could not load/);
  equal(fresh.appended.length, 1);
  equal(fresh.scripts[0].src, endpoint('sandbox'));
  fresh.scripts[0].emit('error');
  await failure;
  equal(fresh.scripts.length, 0);
  equal(fresh.timers.size, 0);
  const retry = fresh.mount();
  equal(fresh.appended.length, 2);
  fresh.window.Square = fresh.square;
  fresh.scripts[0].emit('load');
  await retry;
  equal(fresh.state().attachCount, 1);

  // Timing out does not remove or duplicate a still-loading SDK. A later mount
  // waits on the same script, including when another plugin created it.
  const slow = harness({urls: [endpoint('sandbox') + '?ver=10.4.0'], loaded: false});
  const timeout = rejects(slow.mount(), /could not load/);
  slow.timeout();
  await timeout;
  equal(slow.scripts[0].listenerCount(), 0);
  const slowRetry = slow.mount();
  equal(slow.appended.length, 0);
  equal(slow.scripts.length, 1);
  slow.window.Square = slow.square;
  slow.scripts[0].emit('load');
  await slowRetry;
  equal(slow.state().attachCount, 1);
  equal(slow.timers.size, 0);
  const wallets = harness({urls: [endpoint('sandbox')]});
  const enabled = {...wallets.options.config, country: 'US', wallets: {applePay: true, googlePay: true}};
  const shopper = await wallets.mount({config: enabled, channel: 'customer', amount: '91.37'});
  const apple = await shopper.wallet('applePay', {}), google = await shopper.wallet('googlePay', {});
  equal(wallets.walletState.requests.length, 2);
  equal(wallets.walletState.requests[0].total.amount, '91.37');
  equal(wallets.walletState.requests[0].currencyCode, 'USD');
  equal(wallets.walletState.requests[0].requestShippingContact, false);
  equal(wallets.walletState.requests[0].requestBillingContact, false);
  equal(wallets.walletState.attached[0].buttonSizeMode, 'fill');
  equal((await apple.tokenize()).token, 'cnon:wallet');
  equal(wallets.walletState.verified[0].data.amount, '91.37');
  equal(wallets.walletState.verified[0].data.intent, 'CHARGE');
  equal((await google.tokenize()).verification, 'verify:fixture');
  wallets.walletState.verification = null;
  equal((await apple.tokenize()).verification, '');
  wallets.walletState.mode = 'cancel';
  await rejects(apple.tokenize(), /cancelled/);
  wallets.walletState.mode = 'verify-fail';
  await rejects(google.tokenize(), /Verification failed/);
  wallets.walletState.mode = 'success';
  equal(await shopper.tokenize(), 'cnon:test');
  wallets.walletState.mode = 'pending';
  const waiting = apple.tokenize();
  equal(wallets.walletState.calls, 6); // Wallet tokenization starts synchronously on click.
  await rejects(shopper.tokenize(), /already being secured/);
  await rejects(google.tokenize(), /already being secured/);
  wallets.walletState.mode = 'success'; wallets.walletState.resolve(); await waiting;
  await shopper.destroy(); equal(wallets.walletState.destroyed, 2);
  const staffWallets = await wallets.mount({config: enabled});
  await rejects(staffWallets.wallet('applePay', {}), /unavailable/);
  const disabledWallets = await wallets.mount({channel: 'customer'});
  await rejects(disabledWallets.wallet('googlePay', {}), /unavailable/);
  console.log(`Cart Cat payment JS tests passed (${assertions} assertions; SDK mocked).`);
})().catch(error => { console.error(error); process.exitCode = 1; });
