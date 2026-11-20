# Cart Cat implementation contracts

All PHP classes use namespace `Cart_Cat`, class-per-file `includes/class-{kebab}.php`, and static methods unless a Woo superclass requires instances. Domain errors return `WP_Error`; REST adapter converts them. Money is calculated server-side in Woo units; provider money uses integer minor units.

Square itemization (0.1.10): `Square_Order::prepare()` resolves SWS catalog links and validates a read-only calculation preview; its versioned result is frozen under the payment attempt's `itemization` key. `Square_Order::verify()` checks the actual created order before payment. `Square_Inventory` coordinates tracked catalog counts with SWS and native Woo stock bookkeeping. See [the itemization contract and evidence](docs/square-itemization.md), including the explicit Square restock workflow for returned tracked products. Existing in-flight attempts retain their previous payload.

## Builder input

`{customer: {reference?: opaque, first_name, last_name, email, phone, billing: WooAddress, shipping: WooAddress}, lines: [{product_id, variation_id: 0, quantity, addons: {fieldKey: value}, price_override?: {type: percent|fixed, value: decimal}}], discount: {type: percent|fixed, amount, mode: add|replace}, fulfillment: {type: pickup|shipping, rates: {packageIndex: rateId}, heat_hold_choice: hold|ship}, hold_minutes: integer, order_notes?: string, order_notes_visible?: boolean}`.

Omit `price_override` or send `null` for automatic pricing. A percentage from 0 to 100 reduces the effective product unit price after customer and quantity pricing. A fixed value sets the product unit price in the store currency using WooCommerce’s catalog tax settings and may be zero. Both apply to all units on the line, before paid add-ons and the cart-wide discount. Normalized overrides are included in quote hashes and stored in editable draft and prepared-order input. Default-priced and adjusted lines stay separate.

Stock hold input is an integer from 15 through 10080 minutes (7 days). An omitted value defaults to the current Woo hold-stock setting when in that range, otherwise 60 minutes. It is normalized before quote hashing. The slider uses Book It Beagle’s 15/30/45-minute, hourly, 2-day, 3-day and 7-day stops.

Order notes are normalized multiline text with a 2,000-character limit. A strict boolean order_notes_visible defaults false; both fields are quote-bound. Private staff audit history is retained. Only explicit opt-in populates Woo customer_note for native emails/order details and the scoped customer payment page; preparation never sends a standalone note notification. Unchecking or clearing removes current customer-facing text without deleting private history. Unchanged preparation retries are deduplicated.

WooAddress keys: first_name,last_name,company,address_1,address_2,city,state,postcode,country. Billing and shipping include email,phone. Physical shipping requires a valid recipient email and phone, separately from purchaser contact; incomplete contact returns quote notices with can_submit=false. Recipient phone uses native Woo shipping_phone; recipient email uses _shipping_email order metadata and is restored into the editable order input. Payment-email delivery continues to use billing_email. Blank new customer is guest. Only Customers::resolve can provide trusted woo_id, roles, square_id.

## Service boundaries

- Customers::search(string): `{customers:[{reference,name,email,phone,sources:[],roles:[],ambiguous?:bool}],providerStatuses:{woocommerce:string,square:string}}` or WP_Error.
- Customers::details(string reference): customer fields above plus reference, woo_id, roles, square_linked; NEVER Square provider ID in browser.
- Customers::resolve(array customer,bool fresh=false): trusted customer fields + woo_id,roles,is_guest,square_id. Form contact/address overrides affect this order only. Never accept identity/roles/provider IDs from input.
- Catalog::search(string): `{products:[{id,name,sku,type,price,stock_status,requires_configuration}]}`.
- Catalog::index(page, revision, check): staff-only paginated base-price catalog hints, 250 products per page and at most four pages. Five-minute server snapshots invalidate on product/stock/ticket edits; a complete browser index is fresh for one minute. Partial/expired/failed snapshots fall back to server search. Selection always retrieves fresh configuration; quote/create revalidates the product.
- Catalog::configuration(int): `{id,name,type,variations:[{id,label,attributes,price,in_stock}],addons:[{key,name,type,required,options:[],price}],sold_individually}`.
- Catalog::add_to_cart(WC_Cart,array line): string cart key or WP_Error; enforce ticket/product/variation/stock/add-on checks and preserve add-on data/metadata via native hooks.
- Quote::calculate(array input,bool fresh=false,bool retain=false): server quote + hash. If retain=true return internal `_cart`, `_customer`, `_input`, `_packages`, `_chosen`, `_square_scope`; REST must strip these. Quoting never persists Woo orders or customer/session changes.
- Orders::create(array input,string quote_hash,string request_id): existing/new `{order_id,number,status,total,currency,payment_url,edit_url,version,paid}` or WP_Error, idempotently. Orders::email(int), Orders::get(int), Orders::complete_free(int). Orders::get includes `preparation`, `can_edit`, `edit_message`, sanitized `input` with a freshly issued server-owned customer reference, saved `review`, and `hold:{minutes,expires_at,expires_label,active}`. Orders::edit(id,version) pauses payment under the shared lock; Orders::prepare(id,input,quote_hash,version,request_id) requotes and replaces the same order, rotates its version/key, and renews native stock holds. Unknown payment attempts block edits; declined attempts are archived.
- Square::public_config(): `{ready,application_id,location_id,environment,currency}`; connection configuration is read from SquareWooSync. Square::scope() returns only the public application/location/environment/currency fields, which bind customer references, quotes and prepared orders. Square::request(string method,string path,array body=[]): decoded array or WP_Error. No credentials in errors/logs.
- Payments::cards(int order_id): `{cards:[{reference,brand,last4,expiry_month,expiry_year}],message?:string}` or WP_Error.
- Payments::pay(int order_id,array request,string channel='staff'): request `{source_type:token|card,token?:...,card_reference?:...,version:...}`; returns `{order_id,status:paid|declined|checking|unpaid,message?,receipt_url?}`. All payment attempts go through shared coordinator. Payments::status(int): same result. Payment backend verifies staff nonce/cap through REST; gateway verifies Woo order-pay access.
- `cart_cat_square` Gateway uses Payments for customer order-pay and linked refunds; only available for Cart Cat orders.

## REST shape

Namespace cart-cat/v1; GET /customers?q=; GET /customers/details?reference=; GET /products?q=; GET /products/index?page=&revision=&check=; GET /products/{id}; POST /quote (builder input); POST /orders `{input,quote_hash,request_id}`; GET /orders/{id}; POST /orders/{id}/edit `{version}`; POST /orders/{id}/prepare `{input,quote_hash,version,request_id}`; POST /orders/{id}/email `{version,hold_minutes,request_id}`; GET /orders/{id}/cards; POST /orders/{id}/pay; GET /orders/{id}/payment; POST /orders/{id}/complete. All staff endpoints require manage_woocommerce and wp_rest nonce.

Quote public output: `{hash,hold_minutes,currency,decimals,lines:[{key,name,quantity,subtotal,total,tax,automatic_savings,price_override?,price_adjustment?,meta:[]}],totals:{subtotal,automatic_savings,price_adjustment?,discount,shipping,fees,tax,total},shipping_status:available|unavailable|not_required,rates:[{package_index,rates:[{id,label,cost,selected}]}],heat_hold:{required,choices:[{value,label,description}],choice},customer:{name,roles:[],sources:[]},can_submit,notices:[]}`. Monetary values are decimal strings. `price_adjustment` is a signed, tax-exclusive change to the line or cart product subtotal after automatic savings and before the cart-wide discount. Incomplete fulfillment returns quote with can_submit=false/notices/rates when possible; malformed carts return WP_Error. UI must re-quote before create and retain same request_id for retries.

## UI bootstrap

Admin::register() adds admin menu/enqueue hooks. Render plugin-only assets; `window.CartCat` = `{restUrl,nonce,square:Square::public_config(),hold:Stock_Hold::config(),addressSearch:{apiKey}}`. Admin owns templates/admin.php and admin.css,menu-icon.css,admin.js. Payment agent owns gateway template and payment.js; admin can call `window.CartCatPayment.mount(element, {config,amount,currency,billing,channel})` -> Promise of controller `{tokenize():Promise<string>,destroy():Promise<void>}`. Config is public Square config. Admin calls REST pay itself. Load payment.js before admin.js, and Square SDK lazily using config environment.

Runtime fixtures remain local-only and never mutate production. Paired dependency patches live in compat/, never modify sibling repositories. The initial distribution was 0.1.0; review fixes were published as 0.1.1, then 0.1.2 under the user’s release authorization.

Saved cards attached to old Square profile IDs require a fresh RetrieveCustomer response whose canonical ID exactly matches the selected customer. This check runs again before a charge; email matches alone never establish ownership. Reprepare rotates the secure order key so previously emailed links cannot expose a changed customer. Email rendering, edit, preparation, expiry, and payments share the order lock.


## Verified customer Square links

For an existing Woo account, `square_customer_id` and `_stw_square_role_customer_id_1` take precedence over email discovery. Each distinct nonempty stored ID is retrieved afresh from the current Square connection; merge aliases must resolve to the same canonical customer. Invalid, unavailable or conflicting stored links fail closed without an email fallback. An older explicit selection for a different profile returns HTTP 409 and requires customer reselection.

Search keeps the Woo account selection when same-email Square results are duplicates of a linked account. Selecting it resolves the stored links rather than attaching an arbitrary duplicate. Accounts without stored links retain unique exact-email matching and ambiguity protection; explicitly selected Square-only customers remain supported. Provider IDs stay server-side.

Preparation freezes the verified canonical customer in the existing order snapshot. Reads do not migrate a prepared order or change its payment identity. To correct an existing unpaid order, staff must use Back to order details, select the customer again, review a fresh quote and prepare the same order through the normal locked flow. Uncertain or completed payments retain their existing edit restrictions.

## Per-order stock holds

`Stock_Hold::config()` supplies `{default_minutes,steps,timezone}` for the staff slider. Preparing or repreparing stores that order’s duration and absolute expiry. The deadline is set before native stock reservation; subsequent reservations use remaining time rather than restarting the full duration. Email confirmation may set a new duration under the same payment lock. The first confirmed email starts that selected duration at confirmation. Subsequent confirmations with unchanged duration and retries keep the applied deadline; an explicitly different duration replaces it. Merely opening/cancelling the dialog, reads and reloads never extend it. Request identities prevent duplicate accepted sends, payload changes on retries, and repeated deadline extension. Known send failure retains its applied hold; interrupted sends require status review rather than an automatic duplicate email. Expiry is checked under the shared payment lock. New payments and payment emails are rejected after the deadline even if scheduled cancellation has not run yet. An uncertain or captured payment remains protected while reconciliation keeps its stock reservation alive. Ordinary Woo checkout retains its own settings and cancellation behavior.

Older orders retain their existing scheduled expiry behavior. Their duration is made explicit only when staff prepare the order again. New expiry notes identify the selected duration instead of attributing every hold to the global Woo setting.


## Google address search

`cart_cat_google_maps_browser_key` is a dedicated website-restricted browser API key configured through Cart Cat Settings using WordPress settings capability/nonce checks. It is exposed only in the staff builder bootstrap and never reused from a server credential. `address-autocomplete.js` loads the Places (New) widget and fetches only address components upon explicit selection. Manual entry remains available after missing configuration or provider failure. Country restriction follows the selected form country. Address selection updates only address fields, clears obsolete unit/locality fields, preserves names and contact data, and requotes through the normal authoritative Woo flow. Detached, hidden, locked, superseded, or manually edited forms reject late lookup results. No address result is treated as shipping eligibility or rate validation.


## Saved drafts

Drafts are private `cart_cat_draft` posts, excluded from public queries, public REST, WordPress export and native order lifecycles. Staff with `manage_woocommerce` share the list; all endpoints also require the existing REST nonce. No Woo order, reservation, provider request or mail is created by ordinary draft saving/loading. Incomplete form values are sanitized as inert bounded text. Product name/meta display hints are untrusted presentation only; Quote/Catalog revalidate authority when preparing.

Routes: GET `/drafts?q=&page=`; POST `/drafts` `{input,title,request_id}`; GET `/drafts/{id}`; POST `/drafts/{id}` `{input,title,version,request_id}`; POST `/drafts/{id}/delete` `{version}`; POST `/drafts/{id}/prepare` `{input,quote_hash,version,request_id}`. Lists return `{drafts,page,total_pages,total}` with 20 entries per page. Detail includes `{draft_id,title,version,input,customer,state,can_edit,warning,customer_selection_required,order_id}`. Draft state is `draft`, `preparing`, or `prepared`. A prepared draft opens its existing order; interrupted preparation exposes a staff review link, never a new charge path.

Identity is read from a verified staff/session-bound customer reference, stored only in server data, and reissued for the current staff member on load. Client-supplied Woo/Square IDs/roles are ignored. Missing customers or changed Square scope require explicit customer reselection; incomplete form fields remain recoverable. Save responses may rotate the customer reference, so the UI invalidates the old quote accordingly.

MySQL advisory locks serialize each draft's save/delete/prepare. Optimistic versions reject lost updates. Create/update request receipts bind both payload and accepted revision/state; retrying an old successful request after another edit cannot silently adopt the newer version. Deleted draft tombstones retain only request identity while discarding contact content. Preparation uses a durable server-owned request scope in Orders::create; retries and crash recovery return the same Woo order across staff sessions. Failures before any order ledger preserve the last explicitly saved draft details. Browser changes are saved explicitly, and navigation warns before discarding unsaved draft changes.

## Shippo recipient contact export

`Shipping_Contact` listens to Woo's authenticated API-key callback and binds the configured Vole Shippo key ID to the exact primary GET/HEAD order REST request and current API user. It does not trust user-agent, headers or caller-provided IDs. Configured server-owned create/update webhook IDs use the matching native webhook payload hook. Only Cart Cat orders with physical shipping lines and Vole's positive `shippo` classification receive the bridge.

For these exports, valid `_shipping_email` and native shipping phone are copied into both `shipping` and export-only `billing` contact objects, covering importers that fall back to billing contact. The Woo order is not modified. Other consumers, pickups, virtual orders, Vinoshipper routes and missing/unrecognized configuration retain their original responses. Missing/invalid recipient fields retain legacy fallback independently. This guarantees the scoped Woo export shape, not Shippo's eventual printed label layout.

## UPS address validation

POST `/shipping/validate-address` accepts `{address:{address_1,address_2,city,state,postcode,country}}` with the existing staff capability and REST nonce. It returns `{status:valid|suggested|unmatched|unsupported|unavailable,message,candidates:[postal address]}`; incomplete or malformed input returns HTTP400. Only the six postal fields reach the provider. Unsupported countries, missing configuration, failed authentication, transport failures, malformed or contradictory UPS results cannot become a positive validation.

`Address_Validation` discovers the matching Woo zone's enabled official `WC_Shipping_UPS` REST instance with destination validation enabled. It reuses that instance's server-side OAuth and native `WooCommerce\UPS\API\REST\Address_Validator`, including its normal transient cache. It avoids the checkout-only wrapper and checkout notices, creates no order, and does not change customer/cart/session data. Normal OAuth renewal and provider cache persistence remain owned by the UPS extension.

Suggestions require explicit staff acceptance. ZIP+4 and multiple returned candidates are preserved; PR remains in Woo country form; entered or suggested PO boxes are rejected. An omitted apartment/suite is kept with a review warning, not marked verified. Google search and manual editing remain available. Address changes, customer/draft replacement, fulfillment switches and builder locks invalidate pending or displayed UPS results. Applying a suggestion preserves recipient identity/contact and requotes rates/taxes through the existing flow. Validation is advisory, separate from order preparation and shipping eligibility, and is not a delivery guarantee.
