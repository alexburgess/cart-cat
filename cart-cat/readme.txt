=== Cart Cat ===
Requires at least: 6.5
Requires PHP: 8.1
Requires Plugins: woocommerce
Tested up to: 7.1
Stable tag: 0.1.11

Prepare WooCommerce customer orders with store pricing, fulfillment, tax, and Square payment.

== Description ==
Cart Cat is a staff-only order preparation screen for WooCommerce administrators and shop managers. It supports customer lookup from Woo and Square, non-ticket products and required add-ons, role/case pricing, one manual product discount, winery pickup, eligible shipping, invoices, and Square card payment.

Install the supplied Quantity Crab and SquareWooSync compatibility changes before enabling payments. Use HTTPS and validate in a Square Sandbox staging environment before production release. Full installation and validation notes are included in the delivery bundle.

== Changelog ==
= 0.1.11 =
Save unfinished orders as shared, searchable drafts and reopen them without reserving stock. Add Google address search and optional UPS address validation with reviewed corrections. Capture separate shipping-recipient email and phone, retain purchaser billing for payment emails, and supply recipient contacts to recognized Shippo exports. Preserve one-order preparation and safe retries across draft edits and staff sessions.

= 0.1.10 =
Link new Square sales to the SWS product catalog, preserve product quantities and discounts, and separate tax, postage and fees. Verify exact order components before charging and coordinate tracked stock through SWS without a duplicate Woo deduction. Existing paid sales and in-flight payment attempts retain their original behavior.

= 0.1.9 =
Simplify customer provider badges and quantity fields, add pickup/shipping icons, and hide complimentary pickup prices in delivery choices. Prepare with the default stock hold; choose an email hold before confirming the recipient. Add an explicit option to show order notes to the customer, preserving private note history.

= 0.1.8 =
Add private staff order notes, a search-first customer flow with optional billing, button-style billing and discount controls, a plum-to-rose logo and scoped Font Awesome field/provider icons. Preload a staff-only product index for faster search while validating current configuration, stock and prices before adding and preparing. Remove the header New order button; retain post-completion new-order actions.

= 0.1.7 =
Match customer payment thumbnails to their white cards. Show named discounts in payment invoices, use a separate branded payment button, and title payment requests Complete Your Purchase (Order #XXXX).

= 0.1.6 =
Add a per-order stock-hold slider from 15 minutes to 7 days, with saved payment deadlines, consistent native stock reservations and clear expiry messages. Email resends keep the original deadline.

= 0.1.5 =
Center the customer payment page, display original prices and named savings, and offer supported Apple Pay/Google Pay wallets through the shared Square service. Confirm accepted payment emails in a success dialog, preserve the awaiting-payment state when reopened, and add clear New order controls.

= 0.1.4 =
Suppress 1Password suggestions on staff customer-entry, address and product-option fields while preserving normal typing, lookup prefill and email validation.

= 0.1.3 =
Name Addify and Quantity Crab savings, remove obsolete option prompts and the admin payment-page link, group saved cards with the payment choices, and style customer payment with product images and clearer pickup details. Hide the internal payment-method label in customer totals and emails.

= 0.1.2 =
Separate payment review with Back to edit the same unpaid order, exclude wine Glass variations, select sole options, distinguish discounted prices, explain missing UPS package data, verify merged Square customer cards, and name applied discounts in order notes.

= 0.1.1 =
Reduce redundant quote refreshes, display billing addresses with an edit control, clarify discount and email controls, and reuse the existing Square card SDK on customer payment pages.

= 0.1.0 =
Initial local release candidate.
