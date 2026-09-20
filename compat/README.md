# SquareWooSync payment ownership extension

Cart Cat takes payment only when `SWS_EXTERNAL_PAYMENT_OWNER_API >= 1` is present. The supplied patch adds two filter contracts without changing normal SWS behavior when no filter claims an order:

- `sws_external_payment_owner(bool $managed, WC_Order $order, string $operation)` protects order creation/tender sync, direct SWS card charges, refund mirroring and inbound status/refund projection.
- `sws_external_square_order_owner(bool $managed, array $square_order)` protects the first import, including a webhook received before Woo's canonical `square_order_id` has been persisted.

Cart Cat claims only its own orders. Woo remains the physical-order fulfillment and inventory authority. Ordinary SWS stock synchronization and Woo-to-Square fulfillment status updates remain registered. Cart Cat uses a custom-amount Square Order (no Square catalog inventory deduction), stores canonical `square_order_id`, and handles linked payments/refunds through its own gateway. External refunds initiated directly in Square require review in Woo; SWS must not fabricate a second refund record.

Build a **separate** compatible plugin from the exact approved SWS source:

```sh
python3 compat/build-sws-compat.py --source /path/to/squarewoosync-pro --output /tmp/squarewoosync-cart-cat --patch /tmp/squarewoosync-cart-cat.patch
```

The command refuses an existing/in-place output or unexpected source shape. It produces a before/after SHA-256 manifest and retains all existing source fixes. The packaged patch was generated from the exact live SquareWooSync **10.4.0** source retrieved on September 10, 2026. `sws-external-payment-owner-manifest.json` records the verified source and output hashes for its eight changed files; all eight output files passed PHP lint. Review/update anchors and hashes for any other source revision, including another build with the same version number. A vendor replacement that removes the marker disables new Cart Cat payments. Do not simply define the marker separately: all eight files in the manifest form the compatibility boundary.

Square webhook: configure `https://YOUR-SITE/wp-json/cart-cat/v1/square/webhook` for payment and refund updates. Set `CART_CAT_SQUARE_WEBHOOK_SIGNATURE_KEY` in server configuration (or the private `cart_cat_square_webhook_signature_key` option). The endpoint verifies the exact notification URL/body signature and schedules provider re-fetches; it never trusts event status alone. Action Scheduler/WordPress cron also reconcile ambiguous attempts when browser requests end.

Each reconciliation of an unchanged, unresolved payment refreshes Woo's native stock reservation while holding the payment lock. It does not reduce inventory or explicitly release holds. A failed refresh records one inventory-review note and still checks the existing Square payment; an already captured payment must be recorded even if inventory changed. Scheduled backoff is capped at half the configured native hold duration. This remains subject to Woo's hold-stock setting and filters: disabled holds stay disabled, and a scheduler outage longer than the hold duration can let reservations expire. Cart Cat does not promise an indefinite stock hold or start a replacement charge when a hold expires.

This directory contains packaging inputs only. Building the copy does not install or deploy it.

## Quantity Crab selected-customer extension

Cart Cat must distinguish a selected guest from the authenticated staff member when Quantity Crab chooses its guest/case rules. The extension adds `Quantity_Crab_Discounts::CUSTOMER_CONTEXT_VERSION = 1` and the `quantity_crab_customer_is_guest` filter. Outside an isolated Cart Cat calculation, its default is unchanged. Existing role and baseline hooks remain in use; staff roles are never changed.

Apply the extension to a staged copy of Quantity Crab **1.0.4**:

```sh
cp -R /approved/source/quantity-crab /staged/quantity-crab
php compat/quantity-crab-context.php /staged/quantity-crab --check
php compat/quantity-crab-context.php /staged/quantity-crab
php -l /staged/quantity-crab/includes/class-quantity-crab-discounts.php
```

The patcher requires the expected source anchors, saves the original file alongside the staged file, and uses an atomic replacement. Keep its `.cart-cat-original-*` backup in release evidence, outside the installed plugin package. If Quantity Crab is active without this marker, Cart Cat blocks calculations rather than using staff guest/member status. This changes one PHP file; no Quantity Crab settings or tiers are changed. `quantity-crab-context.patch` and `quantity-crab-context-manifest.json` contain the reviewed one-file delta and before/after hashes.

## Release and rollback boundary

Deploy the reviewed compatible plugin files and Cart Cat together only after staging validation. Preserve complete backups and the recorded before/after hashes. A vendor update can remove either extension; rebase the changes against that exact source rather than adding a marker alone. Disabling Cart Cat removes its UI and payment gateway, but existing unpaid Cart Cat payment links then cannot accept payment. Reconcile uncertain payment/refund attempts before any rollback, and keep their Woo orders and metadata intact. Never delete and recreate an uncertain payment order as a recovery procedure.
