# Testing and verification

Run the commands in the root README against a disposable WordPress/WooCommerce site with synthetic data, blocked real mail and intercepted provider transport. Never run the runtime fixtures on production. Licensed UI assets are required for complete packaging and visual checks.

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
