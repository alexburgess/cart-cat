# Cart Cat 0.1.15

Version 0.1.15 uses verified WooCommerce-to-Square customer links for saved cards and preserves the installed membership-authority integration. The customer implementation was compared with Stone Tower production on November 13, 2026. This repository also retains the SOMMinar order option from versions 0.1.13–0.1.14, which is absent from that production installation. See [Testing](docs/testing.md) for validation boundaries.

Version 0.1.11 adds saved drafts, Google address search, optional UPS address validation, and separate shipping-recipient contacts with a scoped Shippo export bridge. The existing Square catalog itemization, taxes, postage, discounts and inventory coordination remain in place; see [Square itemization](docs/square-itemization.md).

Cart Cat adds a focused **Cart Cat** screen to the WordPress admin for staff with `manage_woocommerce`. It builds a WooCommerce order using the selected customer's pricing, winery pickup or eligible direct shipping, and an optional before-tax product discount. Staff can then send the normal Woo customer invoice, enter a card using Square-hosted fields, choose a verified saved Square card, or complete a zero-total order.

Version 0.1.11 was deployed to Stone Tower on September 18, 2026. Saved drafts, recipient contacts and UPS validation are ready for staff testing. Google address search requires a browser API key in Cart Cat Settings. See [Testing](docs/testing.md) for checks and provider evidence boundaries.

## Version 0.1.11 features

Billing and shipping address forms support Google Places address search, with editable manual fields retained. An administrator can configure **Cart Cat → Settings → Google Maps browser API key**. Use a website-restricted browser key with **Maps JavaScript API**, **Places API (New)**, and Google Cloud billing enabled. Only the typed address search is sent to Google; purchaser and recipient contacts stay in the order form. [Google setup](https://developers.google.com/maps/documentation/javascript/get-api-key) · [Places widget](https://developers.google.com/maps/documentation/javascript/place-autocomplete-new) · [Key restrictions](https://developers.google.com/maps/api-security-best-practices).

**Validate with UPS** checks the shipping address through the existing WooCommerce UPS Shipping connection and its native address validator/cache. It is an optional staff action for US/Puerto Rico addresses, independent of Google search. UPS corrections are displayed for review and applied only with **Use suggested address**, preserving recipient contact and refreshing shipping/tax totals. An apartment or suite omitted by UPS is retained with a review warning. Editing the address or changing customers/drafts clears the old result. Provider failures are shown as unavailable, never as a successful validation. The matching shipping zone must have an enabled REST UPS method with destination validation enabled; no separate Cart Cat UPS credentials are needed. [WooCommerce UPS setup](https://woocommerce.com/document/ups-shipping-method/).

Physical shipping now requires a separate **Recipient email** and **Recipient phone**. **Copy billing details** explicitly copies the purchaser's contact and address; staff can then change the recipient independently. The payment review and reopened editor retain these details. Recipient phone uses Woo's native shipping-phone field; recipient email is saved as `_shipping_email` order metadata. Payment emails continue to use the purchaser's billing email. No customer profile changes are made.

**Save as draft** stores unfinished orders without creating a Woo order, sending email, taking payment or reserving stock. Optionally name a draft; otherwise the customer name is used. **Saved drafts** opens a shared, searchable, paginated staff list with customer details and last edit information. Load, update, delete, or start another draft from this interface. Drafts have no stock-hold deadline and do not auto-expire. Saving is explicit: changes made after the last save remain unsaved until saved again.

Loading a draft restores purchaser and recipient contact, addresses, product choices/add-ons/quantities, discount, delivery choices and notes, then obtains current Woo pricing and availability. Incomplete details can be saved. Preparing requires the normal current quote and validation; it converts the draft to one Woo order and only then begins its stock hold. Prepared entries open their existing order and cannot produce another order on a retry. Conflicting staff edits require reloading rather than silently overwriting each other. Existing prepared orders still use **Back to order details** and cannot be turned into a new draft through the save control.

The Shippo contact bridge supplies recipient email/phone to the recognized Shippo Woo API/webhook exports for Cart Cat physical shipments. It uses the existing Vinoshipper Vole configured Shippo API-key/webhook IDs and positive shipping classification, and affects only exported response copies. Stored purchaser billing, payment emails, invoices and unrelated integrations retain the purchaser contact. Missing recipient fields on old orders retain existing fallback. Actual printing is controlled by Shippo/carrier label formats; provider import and printed output still need end-to-end verification after deployment.

## Repository files

- `cart-cat/`: plugin source; commercially licensed icon assets are supplied separately as described below.
- `scripts/`: syntax checks, runtime test runner and package builder. Building generates `dist/cart-cat-0.1.11.zip` and `dist/cart-cat-0.1.11-delivery.zip` locally.
- `compat/`: narrowly scoped Quantity Crab and SquareWooSync changes. Both are required when those plugins are active.
- `tests/`: disposable WordPress runtime fixtures, browser fixture, and hosted-card SDK contract tests.

Generated packages, production release records, raw test evidence, screenshots and the private `VALIDATION.md` are intentionally excluded from Git. The public testing summary contains no customer or payment records.

## Licensed assets

The public repository excludes the commercial Font Awesome assets used by the installed UI. Before packaging or running visual checks, supply these files from an authorized licensed copy of Cart Cat:

- `cart-cat/assets/icon.svg`
- `cart-cat/assets/fonts/fa-duotone-900.woff2`
- `cart-cat/assets/fonts/custom-icons.woff2`

The builder checks that all three files are present. Keep their license notices; do not commit them to this public repository. See [the asset notice](cart-cat/assets/fonts/README.txt). Local installations and existing release packages retain their assets.

## Installation on a staging site

1. Start with a staging copy of the current store and back up the affected plugins and database. Use synthetic customers, an email catcher, and Square Sandbox. Never point the test fixtures at production.
2. Apply the Quantity Crab customer-context change to a **staged plugin copy**, and build the matching SquareWooSync companion copy as described in [compat/README.md](compat/README.md). Review their changed-file manifests. Install those compatible copies alongside Cart Cat.
3. Supply the licensed assets above and run `python3 scripts/build.py`, then upload `dist/cart-cat-0.1.11.zip` in WordPress and activate it. WooCommerce must be active. This build was exercised with PHP 8.3, WooCommerce 11.0.1, Addify Role Based Pricing 2.2.4, Product Add-ons 8.4.0, Quantity Crab 1.0.4, and Heat Hold Horse 1.0.2.
4. Confirm the existing Woo tax, shipping, membership mapping, Square, and Heat Hold settings. Cart Cat reads these settings; it does not create tax tables, change membership roles, or configure carriers.
5. Use HTTPS and connect SquareWooSync to the intended **sandbox** merchant/location. Cart Cat verifies the active location, merchant, currency, and environment and requires the complete SWS compatibility extension before new payments are enabled. Configure its signed webhook as described in the compatibility guide and ensure scheduled jobs run.
6. Run the outstanding sandbox and email delivery checks in [Testing](docs/testing.md). Do not enable live use until they pass.

The winery pickup rate defaults to `pickup_location:0`, the configured Club Shop at Tower View Tasting Room. Its saved Woo pickup address supplies Virginia tax location; product tax classes, including `wine-rates`, supply tax rules. If the actual location ID changes, set the private WordPress option `cart_cat_pickup_rate_id` to that exact rate. A missing/disabled/non-Virginia location blocks preparation.

Square credentials are reused through SquareWooSync's server client. They are never shipped in this plugin, exposed to the browser, or included in the delivery bundle. New card details go directly to Square's hosted fields; WordPress receives a short-lived payment token. The supplied icon retains its existing Font Awesome commercial-license notice.

## Staff workflow

1. Search for a Woo or Square customer. Once a search starts, **Add a new customer** opens manual contact entry. Billing is optional behind **Add billing address**; a loaded address stays visible with an **Edit billing address** button. Explicit customer selection links existing Woo accounts; unmatched entries remain guests. Contact/address edits apply only to this order.
2. Add products and variations, including required add-ons. Gift Membership requires recipient name, email, and phone and keeps each recipient on a separate line. Ticket associations on products or their parents and wine Glass variations are rejected. A sole remaining variation is selected automatically; required add-ons still need completion.
3. Review automatic pricing under the actual Addify and Quantity Crab rule names. Quantity Crab applies its configured best-price rules. The optional cart-wide percentage or fixed discount applies to the product subtotal. It can be combined with role pricing or replace the role adjustment. Quantity tiers and catalog sale prices remain active.

   To adjust one cart line, choose **Edit price** on that product. Choose **Percentage discount** to reduce its current automatic unit price, or **Set unit price** to enter the price per unit. Add-ons and any cart-wide discount still apply. Use **Reset price** to restore automatic pricing. Saving a draft or preparing an order preserves the item adjustment.
4. Choose pickup or enter a shipping address and select an available service. Vinoshipper destinations are directed to the existing checkout. Missing rates and required Heat Hold choices block preparation.
5. Add optional **Internal notes**. They stay private unless **Show these notes to the customer** is checked. Shared text appears on the customer payment page and in native Woo order emails; preparation itself does not send a separate note email. Private audit history remains available to staff. Preparing a new order uses the configured default stock hold (currently one hour).
6. Prepare the order to open the separate payment review screen. **Back to order details** pauses payment while editing the same unpaid order. Preparing again refreshes its totals and payment link; send the updated link if one was previously emailed. Then email the Woo payment link, enter a card, select an eligible card on file, or complete a free order. Sending email first opens **Stock hold**, then a recipient confirmation. Final confirmation applies the chosen hold and sends the invoice. Cancel leaves the existing hold untouched. The first email starts its selected duration at confirmation; unchanged resends and retries preserve that deadline, while an explicitly changed duration sets a new one. An accepted email opens a success dialog with **View order in WooCommerce** and **New Cart Cat order** actions. Dismissing it leaves an awaiting-payment view without charge controls, including after a reload. Repreparing an edited order requires sending its updated payment link. Email retry uses the same order. Completion and email-confirmation **New Cart Cat order** actions open a blank form. Staff and customer payments use the same lock.

When an unpaid hold expires, Cart Cat cancels the order and releases reserved stock. Scheduled cancellation may run shortly after the deadline; new payments and payment emails are blocked at the saved deadline. Editing and preparing an unpaid order again starts a new selected hold and invalidates its old email link. Other checkout orders keep the store-wide Woo setting.

If payment says **Checking payment**, wait for reconciliation. Do not create another order or charge the same customer separately. A changed prepared order fails payment validation and requires staff review. Native Woo refunds use the linked Square payment; direct external Square refunds require review rather than silently creating another Woo refund.

The product index preloads in the background and serves quick name/SKU searches in memory. It contains catalog hints only, with no customer-specific pricing. Current product options, stock and pricing are retrieved when selecting an item, and the Woo quote remains authoritative. Incomplete or expired indexes use normal server search. The index is restricted to staff with the existing capability and nonce checks.

Font Awesome field and provider icons reuse the existing licensed Book It Beagle fonts, hosted locally and scoped to Cart Cat. The commercial license is retained.

## Design and integration

The screen follows the existing native WordPress/Heat Hold Horse card and control conventions, with a sticky summary on wide screens and a stacked layout on narrow screens. Services separately handle the customer directory, catalog, isolated Woo cart calculations, order creation, and Square payments. See [CONTRACTS.md](CONTRACTS.md) for endpoint and component contracts.

Quotes create no orders. Cart calculations preserve staff authentication and restore Woo cart/customer/session state. The server resolves identity, roles, product prices, add-ons, rates, cards, and final amounts. Prepared orders use Woo CRUD and work with both legacy storage and HPOS. Prepared orders retain their original Square application, location, environment and currency; changing that connection blocks new payment and card lookup until reviewed. Durable order requests prevent duplicate creation; durable payment/refund attempts and MySQL advisory locks prevent competing submissions. Provider timeouts reconcile against canonical Square identifiers rather than authorizing another charge.

Cart Cat does not create customer accounts, save newly entered cards, or place Vinoshipper orders. Saved drafts do not reserve stock. Existing Woo order administration remains available through the result link. Ordinary site checkout does not offer the Cart Cat gateway. Cart Cat customer payment links use a scoped cart-style view with product images, website typography, and a separate saved pickup location. Customer emails and totals hide the internal gateway name; payment processing still uses the same Square service. The customer page and Cart Cat customer invoices display original and discounted line prices and named savings from the saved order snapshot. Invoice formatting uses the site’s existing email branding and leaves the order’s recorded amounts unchanged. Apple Pay and Google Pay appear only when enabled in the existing Square gateway and supported by the browser/device. The wallet request uses the prepared total and does not collect or change fulfillment details. Wallet verification and card payment share the existing server order lock and reconciliation path.

## Build and checks

```sh
python3 scripts/check.py
python3 scripts/build.py
node tests/payment-js-test.js
node tests/catalog-cache-js-test.js
node tests/address-autocomplete-js-test.js
php tests/shipping-contact-contract.php
php tests/shipping-contact-export-contract.php
php tests/drafts-contract.php
php tests/address-validation-contract.php
```

Runtime fixtures require a disposable WordPress install with the listed dependencies and the Quantity Crab compatibility extension:

```sh
python3 scripts/runtime-tests.py --wp-root /absolute/path/to/disposable-wordpress \
  --vole-source /absolute/path/to/vinoshipper-vole
```

Use `--php` and `--wp` for explicit PHP and WP-CLI paths. Pass `--email-branding-source /absolute/path/to/stone-tower-email-branding` to also verify the canonical branded invoice button. Run once with Woo legacy order storage, then again with HPOS; the runner reports the actual datastore and stops at the first failure. Switch storage using Woo's normal sync/storage tools between runs. Tests create/delete fixtures and change local test settings. They must never run against real customer/order data.

The new draft/contact export native fixtures can run separately in a disposable localhost WordPress with WooCommerce and Cart Cat active. The export fixture requires Vinoshipper Vole to be inactive because it substitutes only that plugin's configuration and routing. Block external HTTP and mail in the fixture site, then run in each Woo storage mode:

```sh
wp --path=/absolute/path/to/disposable-wordpress eval-file tests/drafts-runtime.php
wp --path=/absolute/path/to/disposable-wordpress eval-file tests/shipping-contact-export-runtime.php
```

`tests/address-validation-runtime.php` additionally accepts `CART_CAT_UPS_SOURCE=/absolute/path/to/source-only-ups-plugin`. Run it in a disposable site with UPS inactive: it loads the actual UPS OAuth and address-validator classes, supplies synthetic shipping configuration, and intercepts every provider request. It never needs production UPS credentials.

For browser fixture testing, install Playwright outside the delivery plugin and use an installed Chrome browser:

```sh
CART_CAT_PLAYWRIGHT=/absolute/path/to/node_modules/playwright node tests/ui-smoke.cjs
CART_CAT_PLAYWRIGHT=/absolute/path/to/node_modules/playwright node tests/drafts-ui.cjs
CART_CAT_PLAYWRIGHT=/absolute/path/to/node_modules/playwright node tests/ups-address-ui.cjs
CART_CAT_PLAYWRIGHT=/absolute/path/to/node_modules/playwright node tests/payment-page-js.cjs
```

The UI fixture simulates REST responses. Runtime Square fixtures simulate provider responses and do not prove real tokenization, sandbox capture/refund, UPS quotes, or inbox delivery. See [Testing](docs/testing.md) for those boundaries.
