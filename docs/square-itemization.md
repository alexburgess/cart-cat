# Square itemization — 0.1.10

Cart Cat now builds a Square catalog order for each new payment instead of sending one “WooCommerce order #…” custom amount. Version 0.1.10 is deployed on the main Stone Tower site after user authorization.

## Sale breakdown

- Woo variations use their SWS `square_product_id` variation link and the parent product's Square item link. Simple products use `square_product_id` plus `square_variation_id`; a simple product with only an item link must resolve to exactly one active Square variation. No SKU/name guessing or generic-sale fallback is used.
- Square catalog objects are retrieved and validated, with the variation version frozen. Woo's saved tax-exclusive line subtotal overrides the catalog price. Actual quantities are preserved. If a subtotal is not divisible into whole-cent unit prices, at most two lines with the same catalog variation preserve both quantity and subtotal exactly.
- The saved difference between Woo line subtotal and total becomes a fixed, product-scoped Square discount. Membership/case pricing already incorporated into Woo's subtotal remains the authoritative unit price.
- Each shipping line and positive fee becomes a separately named Square service charge. It does not contribute to product quantities. Free shipping/pickup stays zero-cost. Negative fees and unsupported quantities fail before payment.
- Taxes are native additive Square taxes, applied only to the corresponding product, shipping or fee. Tax-exempt products remain untaxed. Woo rate totals govern cent allocation when Woo stores fractional-cent line taxes. Saved nominal rates are retained whenever Square's rounding reproduces the collected tax; otherwise an effective rate reproduces those exact cents, with the original rate and collected amount retained in metadata. No tax is disguised as product revenue or an adjustment fee.
- Automatic Square catalog taxes and discounts are disabled. Both CalculateOrder and the actual CreateOrder response must match the saved product quantities, catalog IDs, subtotals, discounts, tax components, charges and grand total before CreatePayment is called.

Square accepts Orders API purchases of non-stock-managed catalog items even when their POS location visibility differs from the payment location. A read-only provider calculation verified this behavior. Stock-managed sales still require a location and tracking configuration compatible with SWS inventory.

## Payment and inventory recovery

The verified payload is saved in the payment attempt before Square order creation. An uncertain response reuses that exact payload and idempotency key, even if catalog links or prices subsequently change. Existing in-flight attempts without an itemization snapshot replay their original total-only payload. Already-paid transactions are not rewritten.

Square automatically deducts inventory for tracked catalog sales. Cart Cat records Woo's stock-reduction bookkeeping without subtracting those quantities a second time, then applies fresh absolute counts through SWS Pro's existing inventory handler. This also handles a Square stock webhook arriving before the payment response and SWS enabling Woo stock management during that webhook. Ordinary Woo orders, old total-only attempts, and free completions retain their existing stock path.

Tracked sales require SWS inbound stock synchronization, its supported stock helpers, a usable payment location, and unlocked per-product stock. Variations sharing parent-managed Woo stock are rejected. Immediate count refresh is backed by durable retries and a later fresh read. A refresh is only confirmed after the mapped Woo quantities match the fetched counts under SWS's location and stock-buffer rules. Repeated failure produces an order note requiring stock review.

**Returns:** Square's existing amount-only refund does not return catalog inventory. For these new Square-managed sales, selecting Woo's refund restock option records an explicit order note directing staff to restock the returned products in Square; it does not create a temporary Woo-only increase that the next Square webhook would erase. Financial refunds still use the existing payment-linked refund coordinator. Older orders retain their original refund/restock behavior.

## Verification

Local fixtures use real WooCommerce 11.0.1 orders with mocked Square transport and an independent integer-arithmetic Square calculator. They cover mixed/exempt tax, fractional-cent allocation, postage/fees, discounts, quantity splitting, missing/stale links, stock timing, SWS failures, refund boundaries, component mismatches, frozen retries and legacy payment recovery. Raw suite outputs are retained in private local test records.

All 14 suites passed on HPOS and legacy storage: **853 assertions per mode, 1,706 total**. The disposable store was restored to HPOS using Woo's native CLI. The payment JavaScript suite passed 77 assertions, and all 57 syntax checks and source whitespace checks passed. The final itemization, inventory and payment suites contain 104, 39 and 145 assertions respectively.

Read-only Square calculation previews passed for an existing order and a synthetic extension with separate taxable postage and fee lines. Both passed the candidate's full component validator against Square's real response. The probes blocked SQL writes, mail, credential refresh and every provider operation except catalog/location reads and CalculateOrder. Production order identifiers, amounts and raw provider evidence are excluded from this public source repository.

These previews do not prove an actual card capture, Square Dashboard reporting after settlement, or a real SWS inventory webhook. Actual capture, return and inventory-webhook verification remains outstanding. No production order, payment, refund or stock count was changed during implementation or read-only previews.

Provider contracts: [catalog line items](https://developer.squareup.com/docs/orders-api/create-orders), [service charges](https://developer.squareup.com/docs/orders-api/service-charges), [price adjustment rounding](https://developer.squareup.com/docs/orders-api/price-adjustments), [CalculateOrder](https://developer.squareup.com/reference/square/orders-api/calculate-order).
