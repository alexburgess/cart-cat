# Testing and verification

Run the commands in the root README against a disposable WordPress/WooCommerce site with synthetic data, blocked real mail and intercepted provider transport. Never run the runtime fixtures on production. Licensed UI assets are required for complete packaging and visual checks.

## Version 0.1.15 verified Square link checks

`tests/customer-square-link-runtime.php` uses synthetic duplicate customer profiles and mocked Square transport in a disposable WordPress/WooCommerce site. It exercises search, details, native quoting/preparation and the actual saved-card response. Coverage includes agreeing stored links, canonical merge aliases, conflicting or malformed links, provider outages, changed provider email, stale explicit selections, unchanged prepared-order identity, opaque card references, unlinked-account fallback and Square-only selection. Run it under both legacy and HPOS storage with real HTTP and mail blocked.

The archived release validation reports **393 assertions per storage mode (786 total)** on WooCommerce 11.0.1: customer links 35, payments 145, order lifecycle 170, Square scope 15 and saved drafts 28. The regression fails against the original implementation. The production customer-class variant also passed the focused 35-assertion HPOS regression. These are the release checks, rather than a fresh native-runtime run.

On November 13, 2026, a read-only source comparison confirmed version 0.1.15 on Stone Tower production. The customer class in this checkout matches that installed implementation, including the membership-authority integration. The repository preserves its existing SOMMinar order feature, which is absent from the production snapshot. Source synchronization does not deploy code or resolve the existing customer-search candidate-limit and changed-ID issues.

A prepared order still containing an older Square profile requires staff to reselect the customer and prepare the order again. Deploying code alone does not rewrite saved order identities or prove that a real card charge will succeed.

## Version 0.1.11 checks

- Syntax and source whitespace: 71 checks passed.
- Google address helper: 74 assertions; recipient contact contract: 25; saved-draft contract: 48; Shippo export contract: 54; UPS validation contract: 62.
- Payment JavaScript: 77 assertions; catalog-cache JavaScript: 1,045.
- Native WordPress/WooCommerce draft fixtures: 26 assertions in each of legacy and HPOS order storage. Native contact export: 21 assertions in each storage mode.
- Native UPS adapter fixture: 27 assertions using the official provider's source with intercepted HTTP and synthetic configuration.
- Browser fixtures passed draft save/load/retry/conflict handling, address corrections and stale-response protection, recipient preservation, and existing payment/edit/email UI flows across desktop/tablet/mobile layouts as applicable.

The prior Square itemization suites passed 853 assertions per storage mode. These are historical results for the checked implementation and fixtures, not a claim that every environment or provider combination has been exercised.

## Provider acceptance

Before enabling a new installation, validate its configured integrations using controlled recipients and Square Sandbox:

1. Configure and restrict the Google browser API key; check real address suggestions and manual-entry fallback.
2. Check an actual UPS validation response and staff acceptance of a suggested address.
3. Confirm Shippo imports recipient email/phone correctly and verify the carrier's label output.
4. Exercise hosted-card/wallet tokenization, a sandbox payment and refund, and inventory ownership with the compatible SWS plugin.
5. Verify branded invoice delivery, customer access and payment-link expiry through a controlled email inbox.

Mocked browser/provider checks do not prove these external outcomes. No actual Google predictions, UPS validation request, Shippo import or printed shipping label was tested during the 0.1.11 release checks. Production verification inspected installed files, classes, hooks, route permissions and public responses; its probe made no order/draft, payment, stock, mail or external-provider changes.

Raw logs, screenshots and deployment evidence remain in the maintainer's private local records and are excluded from this public source repository.
