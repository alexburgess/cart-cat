/* Native customer-payment wiring with a mocked Square SDK. Never contacts a payment provider. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.CART_CAT_PLAYWRIGHT || require.resolve('playwright'));
const base = path.resolve(__dirname, '..');
const options = {config: {ready:true, environment:'sandbox', application_id:'fixture', location_id:'fixture', country:'US', wallets:{applePay:true,googlePay:true}},amount:'42.17',currency:'USD',billing:{email:'fixture@example.test'},channel:'customer'};
const markup = execFileSync('php', ['-r', `define('ABSPATH',true);function esc_attr($v){return htmlspecialchars($v);}function wp_json_encode($v){return json_encode($v);}$checking=false;$config=json_decode(${JSON.stringify(JSON.stringify(options))},true);$order=new class{function get_meta($k,$v=true){return 'version-fixture';}};include ${JSON.stringify(path.join(base,'cart-cat/templates/payment.php'))};`], {encoding:'utf8'});
const posts = [];
const sdk = `window.Fixture={tokens:0,verifications:0,cards:0,mode:'success',rejectWallet:false};window.Square={payments:()=>({card:async()=>({attach:async el=>{el.textContent='Mock secure card fields';Fixture.cards++},tokenize:async()=>{Fixture.tokens++;return{status:'OK',token:'cnon:card'}},destroy:async()=>{}}),paymentRequest:r=>r,applePay:async r=>{if(Fixture.rejectWallet)throw Error('Unsupported');return makeWallet(r)},googlePay:async r=>{if(Fixture.rejectWallet)throw Error('Unsupported');return {...makeWallet(r),attach:async el=>{el.innerHTML='<button type="button">Google Pay</button>'}}},verifyBuyer:async()=>{Fixture.verifications++;if(Fixture.mode==='verify-fail')throw Error('Verification failed');return{token:'verify:wallet'}}})};function makeWallet(r){return{tokenize:async()=>{Fixture.tokens++;Fixture.amount=r.total.amount;Fixture.shipping=r.requestShippingContact;if(Fixture.mode==='pending')await new Promise(resolve=>{Fixture.resolve=resolve});return Fixture.mode==='cancel'?{status:'Cancel'}:{status:'OK',token:'cnon:wallet'}},destroy:async()=>{}}}`;
const server = http.createServer(async (req,res) => {
 if(req.url==='/payment.js'){res.setHeader('Content-Type','text/javascript');res.end(fs.readFileSync(path.join(base,'cart-cat/assets/js/payment.js')));return;}
 if(req.method==='POST'){let body='';for await(const chunk of req)body+=chunk;posts.push(Object.fromEntries(new URLSearchParams(body)));res.end('<h1>Mock payment submitted</h1>');return;}
 const checking=req.url.includes('checking');
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><html><head><title>Cart Cat payment wiring fixture</title><script src="https://sandbox.web.squarecdn.com/v1/square.js?ver=fixture"></script></head><body><form id="order_review" method="post"><input type="hidden" name="payment_method" value="cart_cat_square">${checking?markup.replace('data-checking="0"','data-checking="1"'):markup}<label><input type="checkbox" id="terms" name="terms">Accept terms</label><button type="submit" id="place_order">Pay $42.17</button></form>${req.url.includes('unsupported')?'<script>Fixture.rejectWallet=true</script>':''}<script src="/payment.js"></script></body></html>`);
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const origin=`http://127.0.0.1:${server.address().port}`;
 const browser=await chromium.launch({headless:true,channel:'chrome'});const errors=[];
 try{
 const page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));await page.route('https://sandbox.web.squarecdn.com/**',route=>route.fulfill({contentType:'text/javascript',body:sdk}));
 await page.goto(origin);const apple=page.getByRole('button',{name:'Pay with Apple Pay'});const google=page.getByRole('button',{name:'Google Pay',exact:true});
 await apple.waitFor();await google.waitFor();assert(await page.locator('#place_order').isEnabled());
 await apple.click();assert.equal(await page.evaluate(()=>Fixture.tokens),0,'Terms must be accepted before any wallet tokenization');
 await page.locator('#terms').check();await page.evaluate(()=>Fixture.mode='verify-fail');await google.click();await page.getByText('Verification failed',{exact:true}).waitFor();assert.equal(posts.length,0);assert(await page.locator('#place_order').isEnabled());
 await page.evaluate(()=>Fixture.mode='cancel');await apple.click();await page.getByText(/Payment cancelled/).waitFor();assert.equal(await page.locator('[name=cart_cat_token]').inputValue(),'');
 await page.evaluate(()=>Fixture.mode='pending');await apple.click();await page.waitForFunction(()=>!!Fixture.resolve);assert(await page.locator('#place_order').isDisabled());assert(await google.isDisabled());
 await page.evaluate(()=>document.querySelector('#order_review').dispatchEvent(new Event('submit',{cancelable:true,bubbles:true})));assert.equal(await page.evaluate(()=>Fixture.tokens),3,'Concurrent card submit does not tokenize again');
 assert.equal(await page.evaluate(()=>Fixture.amount),'42.17');assert.equal(await page.evaluate(()=>Fixture.shipping),false);
 await page.evaluate(()=>{Fixture.mode='success';Fixture.resolve()});await page.getByRole('heading',{name:'Mock payment submitted'}).waitFor();assert.equal(posts.length,1);assert.equal(posts[0].cart_cat_token,'cnon:wallet');assert.equal(posts[0].cart_cat_verification_token,'verify:wallet');assert.equal(posts[0].cart_cat_version,'version-fixture');
 await page.goto(origin+'/?unsupported');await page.waitForFunction(()=>!document.querySelector('#place_order').disabled);assert(await page.locator('[data-cart-cat-wallets]').isHidden());await page.locator('#terms').check();await page.locator('#place_order').click();await page.getByRole('heading',{name:'Mock payment submitted'}).waitFor();assert.equal(posts.length,2);assert.equal(posts[1].cart_cat_token,'cnon:card');assert.equal(posts[1].cart_cat_verification_token,'');
 await page.goto(origin+'/?checking');assert(await page.locator('#place_order').isDisabled());assert.equal(await page.evaluate(()=>Fixture.cards),0,'Unknown payment never mounts another payment method');assert(await page.locator('[data-cart-cat-wallets]').isHidden());assert.deepEqual(errors,[]);
 console.log('PASS: wallet availability, exact prepared amount, fixed shipping, terms, cancellation, verification failure, shared card/wallet guard, one native POST with verification token, card fallback, checking state and console health.');
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
