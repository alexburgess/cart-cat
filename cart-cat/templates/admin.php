<?php
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap cc-app" id="cart-cat-app">
	<header class="cc-header">
		<div><h1 class="cc-page-title"><span class="cc-heading-icon" aria-hidden="true"></span><?php esc_html_e( 'Cart Cat', 'cart-cat' ); ?></h1></div>
		<div class="cc-header-actions"><button type="button" class="button" id="cc-open-drafts"><?php esc_html_e( 'Saved drafts', 'cart-cat' ); ?></button><span class="cc-badge cc-badge-neutral" id="cc-order-state"><?php esc_html_e( 'New order', 'cart-cat' ); ?></span></div>
	</header>
	<noscript><div class="notice notice-error"><p><?php esc_html_e( 'Cart Cat needs JavaScript to calculate and create orders.', 'cart-cat' ); ?></p></div></noscript>
	<div id="cc-global-message" class="cc-message" role="status" aria-live="polite" hidden></div>
	<div class="cc-layout">
		<main class="cc-main">
			<fieldset id="cc-builder" class="cc-builder">
				<legend class="screen-reader-text"><?php esc_html_e( 'Customer order details', 'cart-cat' ); ?></legend>
				<section class="cc-card" aria-labelledby="cc-customer-heading">
					<div class="cc-section-heading"><h2 id="cc-customer-heading" tabindex="-1"><span class="cc-step" aria-hidden="true">1</span><?php esc_html_e( 'Customer', 'cart-cat' ); ?></h2></div>
					<label for="cc-customer-search"><?php esc_html_e( 'Find a customer', 'cart-cat' ); ?></label>
					<input id="cc-customer-search" type="search" class="cc-search" placeholder="<?php esc_attr_e( 'Search name, email or phone', 'cart-cat' ); ?>" autocomplete="off" data-1p-ignore="true" aria-controls="cc-customer-results" />
					<p class="cc-help" id="cc-customer-search-status" role="status"><?php esc_html_e( 'Search WooCommerce and Square.', 'cart-cat' ); ?></p>
					<div id="cc-customer-results" class="cc-search-results" hidden></div>
					<button type="button" class="button" id="cc-new-customer" hidden><?php esc_html_e( 'Add a new customer', 'cart-cat' ); ?></button>
					<div id="cc-customer-details" hidden>
					<div id="cc-selected-customer" class="cc-selected" hidden></div>
					<div class="cc-fields cc-contact-fields">
					<?php foreach ( array( 'first_name' => __( 'First name', 'cart-cat' ), 'last_name' => __( 'Last name', 'cart-cat' ), 'email' => __( 'Email', 'cart-cat' ), 'phone' => __( 'Phone', 'cart-cat' ) ) as $key => $label ) : ?>
						<label class="cc-field" for="cc-customer-<?php echo esc_attr( $key ); ?>"><span><?php echo esc_html( $label ); ?></span><input id="cc-customer-<?php echo esc_attr( $key ); ?>" data-contact="<?php echo esc_attr( $key ); ?>" type="<?php echo 'email' === $key ? 'email' : ( 'phone' === $key ? 'tel' : 'text' ); ?>" autocomplete="off" data-1p-ignore="true" /></label>
					<?php endforeach; ?>
					</div>
					<div class="cc-billing-address"><h3><?php esc_html_e( 'Billing address', 'cart-cat' ); ?></h3><div id="cc-billing-summary" class="cc-address-summary" aria-live="polite"></div><div class="cc-address-details"><button type="button" id="cc-toggle-billing" class="button cc-disclosure-button" aria-expanded="false" aria-controls="cc-billing-editor"><?php esc_html_e( 'Edit billing address', 'cart-cat' ); ?></button><div id="cc-billing-editor" hidden><div id="cc-billing-fields" class="cc-fields"></div></div></div></div>
					<p class="cc-help cc-customer-note"><?php esc_html_e( 'Changes here apply to this order only.', 'cart-cat' ); ?></p>
					</div>
				</section>
				<section class="cc-card" aria-labelledby="cc-products-heading">
					<div class="cc-section-heading"><h2 id="cc-products-heading"><span class="cc-step" aria-hidden="true">2</span><?php esc_html_e( 'Products', 'cart-cat' ); ?></h2><span class="cc-muted" id="cc-item-count"><?php esc_html_e( '0 items', 'cart-cat' ); ?></span></div>
					<label for="cc-product-search"><?php esc_html_e( 'Add products from the store', 'cart-cat' ); ?></label>
					<input id="cc-product-search" class="cc-search" type="search" placeholder="<?php esc_attr_e( 'Search product name or SKU', 'cart-cat' ); ?>" autocomplete="off" data-1p-ignore="true" aria-controls="cc-product-results" />
					<p class="cc-help" id="cc-product-search-status" role="status"><?php esc_html_e( 'Ticket products are excluded.', 'cart-cat' ); ?></p>
					<div id="cc-product-results" class="cc-search-results" hidden></div>
					<div id="cc-product-config" class="cc-product-config" hidden></div>
					<div id="cc-lines"><div class="cc-empty"><span class="dashicons dashicons-cart" aria-hidden="true"></span><p><?php esc_html_e( 'Add a product to start the order.', 'cart-cat' ); ?></p></div></div>
					<div class="cc-discount-details"><button type="button" id="cc-toggle-discount" class="button cc-disclosure-button" aria-expanded="false" aria-controls="cc-discount-panel"><?php esc_html_e( 'Add discount', 'cart-cat' ); ?></button><div id="cc-discount-panel" hidden>
						<div class="cc-discount-controls">
							<label class="cc-field" for="cc-discount-type"><span><?php esc_html_e( 'Discount type', 'cart-cat' ); ?></span><select id="cc-discount-type"><option value="percent"><?php esc_html_e( 'Percentage (%)', 'cart-cat' ); ?></option><option value="fixed"><?php esc_html_e( 'Fixed amount', 'cart-cat' ); ?></option></select></label>
							<label class="cc-field" for="cc-discount-amount"><span><?php esc_html_e( 'Amount', 'cart-cat' ); ?></span><input id="cc-discount-amount" type="number" min="0" step="0.01" value="0" inputmode="decimal" /></label>
						</div>
						<label class="cc-radio"><input type="radio" name="cc-discount-mode" value="add" checked /><span><?php esc_html_e( 'Add to the customer’s automatic role discount', 'cart-cat' ); ?></span></label>
						<label class="cc-radio"><input type="radio" name="cc-discount-mode" value="replace" /><span><?php esc_html_e( 'Replace the automatic role discount', 'cart-cat' ); ?></span></label>
						<p class="cc-help"><?php esc_html_e( 'Discounts apply to products before tax. The summary shows the final savings.', 'cart-cat' ); ?></p>
					</div></div>
				</section>
				<section class="cc-card" aria-labelledby="cc-delivery-heading">
					<div class="cc-section-heading"><h2 id="cc-delivery-heading"><span class="cc-step" aria-hidden="true">3</span><?php esc_html_e( 'Pickup or shipping', 'cart-cat' ); ?></h2></div>
					<div class="cc-fulfillment-options">
						<label class="cc-choice"><input type="radio" name="cc-fulfillment" value="pickup" checked /><span><strong><?php esc_html_e( 'Pickup at the winery', 'cart-cat' ); ?></strong><small><?php esc_html_e( 'Local pickup · Virginia tax', 'cart-cat' ); ?></small></span></label>
						<label class="cc-choice"><input type="radio" name="cc-fulfillment" value="shipping" /><span><strong><?php esc_html_e( 'Ship to the customer', 'cart-cat' ); ?></strong><small><?php esc_html_e( 'Store shipping rates and tax', 'cart-cat' ); ?></small></span></label>
					</div>
					<label class="cc-checkbox cc-somminar-pill" for="cc-somminar-order"><input type="checkbox" id="cc-somminar-order" name="somminar_order" /><span class="cc-icon cc-icon-graduation-cap fad fa-graduation-cap" aria-hidden="true"></span><span><?php esc_html_e( 'SOMMinar Order', 'cart-cat' ); ?></span></label>
					<div id="cc-shipping-panel" hidden><div class="cc-subheading"><h3><?php esc_html_e( 'Shipping address', 'cart-cat' ); ?></h3><button id="cc-copy-billing" type="button" class="button-link"><?php esc_html_e( 'Copy billing details', 'cart-cat' ); ?></button></div><p class="cc-help"><?php esc_html_e( 'Enter the recipient’s email and phone, even when shipping to someone other than the purchaser. Payment emails go to the purchaser.', 'cart-cat' ); ?></p><div id="cc-shipping-fields" class="cc-fields"></div>
						<div class="cc-ups-validation"><button id="cc-validate-ups" type="button" class="button" aria-describedby="cc-ups-help cc-ups-status"><?php esc_html_e( 'Validate with UPS', 'cart-cat' ); ?></button><p id="cc-ups-help" class="cc-help"><?php esc_html_e( 'Optional: check this address with UPS and review any suggested corrections.', 'cart-cat' ); ?></p><div id="cc-ups-status" class="cc-message" role="status" aria-live="polite" hidden></div><div id="cc-ups-candidates"></div></div>
					</div>
					<div id="cc-rates" aria-live="polite"></div>
					<div id="cc-heat-hold" hidden></div>
				</section>
				<section class="cc-card cc-order-notes" aria-labelledby="cc-order-notes-heading">
					<div class="cc-section-heading"><h2 id="cc-order-notes-heading"><?php esc_html_e( 'Order notes', 'cart-cat' ); ?></h2></div>
					<label class="cc-field" for="cc-order-notes"><span><?php esc_html_e( 'Order notes', 'cart-cat' ); ?></span><textarea id="cc-order-notes" rows="3" maxlength="2000" autocomplete="off" data-1p-ignore="true" aria-describedby="cc-order-notes-help"></textarea></label>
					<label class="cc-checkbox" for="cc-order-notes-visible"><input type="checkbox" id="cc-order-notes-visible" name="order_notes_visible" aria-describedby="cc-order-notes-help" /><span><?php esc_html_e( 'Show these notes to the customer', 'cart-cat' ); ?></span></label>
					<p class="cc-help" id="cc-order-notes-help"><?php esc_html_e( 'Notes are internal unless selected above. Customer-visible notes appear in order emails, order details, and the payment page.', 'cart-cat' ); ?></p>
				</section>
			</fieldset>
			<section id="cc-payment-panel" class="cc-card" aria-labelledby="cc-payment-heading" hidden><button id="cc-back-to-details" aria-describedby="cc-edit-status" type="button" class="button cc-back-button"><?php esc_html_e( 'Back to order details', 'cart-cat' ); ?></button><p id="cc-edit-status" class="cc-help" role="status" hidden></p><div class="cc-section-heading"><h2 id="cc-payment-heading" tabindex="-1"><span class="cc-step" aria-hidden="true">4</span><?php esc_html_e( 'Complete the order', 'cart-cat' ); ?></h2></div><div id="cc-payment-review" class="cc-payment-review"></div><div id="cc-payment-content"></div><div id="cc-payment-message" class="cc-message" role="status" aria-live="polite" hidden></div></section>
		</main>
		<aside class="cc-summary" aria-labelledby="cc-summary-heading">
			<section class="cc-card cc-summary-card"><div class="cc-section-heading"><h2 id="cc-summary-heading"><?php esc_html_e( 'Order summary', 'cart-cat' ); ?></h2><span class="cc-dot" id="cc-quote-dot" aria-hidden="true"></span></div>
				<p class="cc-help" id="cc-quote-status" role="status"><?php esc_html_e( 'Add products to calculate totals.', 'cart-cat' ); ?></p>
				<div id="cc-summary-customer" class="cc-summary-customer"></div><dl id="cc-totals" class="cc-totals"><div><dt><?php esc_html_e( 'Subtotal', 'cart-cat' ); ?></dt><dd>—</dd></div><div><dt><?php esc_html_e( 'Shipping', 'cart-cat' ); ?></dt><dd>—</dd></div><div><dt><?php esc_html_e( 'Tax', 'cart-cat' ); ?></dt><dd>—</dd></div><div class="cc-total"><dt><?php esc_html_e( 'Total', 'cart-cat' ); ?></dt><dd>—</dd></div></dl>
				<div id="cc-quote-notices" class="cc-quote-notices" aria-live="polite"></div>
				<button type="button" class="button button-primary button-hero cc-primary" id="cc-create-order" disabled><?php esc_html_e( 'Prepare order', 'cart-cat' ); ?></button><p id="cc-prepare-help" class="cc-help cc-summary-help"><?php esc_html_e( 'Next: email a payment link or take payment.', 'cart-cat' ); ?></p>
				<div id="cc-order-links" hidden></div>
				<div id="cc-draft-controls" class="cc-draft-controls">
					<label class="cc-field" for="cc-draft-title"><span><?php esc_html_e( 'Draft name (optional)', 'cart-cat' ); ?></span><input id="cc-draft-title" type="text" maxlength="160" autocomplete="off" data-1p-ignore="true" placeholder="<?php esc_attr_e( 'Customer or occasion', 'cart-cat' ); ?>" /></label>
					<div class="cc-actions"><button type="button" class="button" id="cc-save-draft"><?php esc_html_e( 'Save as draft', 'cart-cat' ); ?></button><button type="button" class="button-link" id="cc-new-draft" hidden><?php esc_html_e( 'Start a new draft', 'cart-cat' ); ?></button></div>
					<p id="cc-draft-status" class="cc-help" role="status" aria-live="polite"><?php esc_html_e( 'Save unfinished details for later. Stock is reserved only when you prepare the order.', 'cart-cat' ); ?></p>
				</div>
			</section>
			<p class="cc-footnote"><?php esc_html_e( 'Prices, discounts, shipping and tax are calculated by your WooCommerce store.', 'cart-cat' ); ?></p>
		</aside>
	</div>
	<dialog id="cc-drafts-dialog" class="cc-email-dialog cc-drafts-dialog" aria-labelledby="cc-drafts-heading">
		<button type="button" id="cc-close-drafts" class="cc-dialog-close" aria-label="<?php esc_attr_e( 'Close saved drafts', 'cart-cat' ); ?>">×</button>
		<h2 id="cc-drafts-heading" tabindex="-1"><?php esc_html_e( 'Saved drafts', 'cart-cat' ); ?></h2>
		<p class="cc-help"><?php esc_html_e( 'Drafts saved by all staff. No payment or stock hold starts until an order is prepared.', 'cart-cat' ); ?></p>
		<label class="cc-field" for="cc-draft-search"><span><?php esc_html_e( 'Find a draft', 'cart-cat' ); ?></span><input id="cc-draft-search" type="search" placeholder="<?php esc_attr_e( 'Search name, customer, email or product', 'cart-cat' ); ?>" autocomplete="off" data-1p-ignore="true" /></label>
		<div id="cc-drafts-message" class="cc-message" role="status" aria-live="polite" hidden></div>
		<div id="cc-draft-list" class="cc-draft-list" aria-live="polite"></div>
		<div class="cc-drafts-pagination"><button type="button" class="button" id="cc-drafts-prev" disabled><?php esc_html_e( 'Previous', 'cart-cat' ); ?></button><span id="cc-drafts-page"></span><button type="button" class="button" id="cc-drafts-next" disabled><?php esc_html_e( 'Next', 'cart-cat' ); ?></button></div>
	</dialog>
	<dialog id="cc-email-setup-dialog" class="cc-email-dialog cc-email-setup-dialog" aria-labelledby="cc-email-setup-title">
		<h2 id="cc-email-setup-title" tabindex="-1"><?php esc_html_e( 'Send order email', 'cart-cat' ); ?></h2>
		<div id="cc-email-hold-step">
			<section class="cc-stock-hold" aria-labelledby="cc-hold-heading">
				<h3 id="cc-hold-heading"><?php esc_html_e( 'Choose the payment window', 'cart-cat' ); ?></h3>
				<div class="cc-hold-label"><label for="cc-hold-duration"><?php esc_html_e( 'Reserve stock for', 'cart-cat' ); ?></label><output id="cc-hold-value" for="cc-hold-duration"></output></div>
				<input id="cc-hold-duration" type="range" min="0" max="0" step="1" value="0" autocomplete="off" data-1p-ignore="true" aria-describedby="cc-hold-help cc-hold-preview" />
				<div class="cc-hold-limits" aria-hidden="true"><span id="cc-hold-min"></span><span id="cc-hold-max"></span></div>
				<p id="cc-hold-help" class="cc-help"><?php esc_html_e( 'The payment window begins when you confirm sending. If it expires before payment, the unpaid order is cancelled and its stock is released. Resending with the same window keeps its existing expiry.', 'cart-cat' ); ?></p>
				<p id="cc-hold-preview" class="cc-help"></p>
			</section>
		</div>
		<div id="cc-email-recipient-step" hidden>
			<h3><?php esc_html_e( 'Confirm the email address', 'cart-cat' ); ?></h3>
			<p><?php esc_html_e( 'Send the payment link to:', 'cart-cat' ); ?></p><p id="cc-email-confirm-recipient" class="cc-email-recipient"></p>
			<p id="cc-email-confirm-hold"></p><p class="cc-help"><?php esc_html_e( 'To change this address, cancel and return to the order details.', 'cart-cat' ); ?></p>
		</div>
		<div id="cc-email-setup-message" class="cc-message" role="status" aria-live="polite" hidden></div>
		<div class="cc-actions"><button type="button" class="button" id="cc-cancel-email-setup"><?php esc_html_e( 'Cancel', 'cart-cat' ); ?></button><button type="button" class="button" id="cc-email-setup-back" hidden><?php esc_html_e( 'Back', 'cart-cat' ); ?></button><button type="button" class="button button-primary" id="cc-email-continue"><?php esc_html_e( 'Continue', 'cart-cat' ); ?></button><button type="button" class="button button-primary" id="cc-confirm-send-email" hidden><?php esc_html_e( 'Send payment email', 'cart-cat' ); ?></button></div>
	</dialog>
	<dialog id="cc-email-dialog" class="cc-email-dialog" aria-labelledby="cc-email-dialog-title" aria-describedby="cc-email-dialog-description">
		<button type="button" id="cc-close-email-dialog" class="cc-dialog-close" aria-label="<?php esc_attr_e( 'Close email confirmation', 'cart-cat' ); ?>">×</button>
		<span class="dashicons dashicons-yes-alt cc-dialog-success-icon" aria-hidden="true"></span>
		<h2 id="cc-email-dialog-title" tabindex="-1"><?php esc_html_e( 'Payment email sent', 'cart-cat' ); ?></h2>
		<p id="cc-email-dialog-description"></p>
		<p class="cc-email-pending"><?php esc_html_e( 'Awaiting customer payment', 'cart-cat' ); ?></p>
		<div class="cc-actions"><a id="cc-email-view-order" class="button button-primary"><?php esc_html_e( 'View order in WooCommerce', 'cart-cat' ); ?></a><button type="button" id="cc-email-new-order" data-new-order class="button"><?php esc_html_e( 'New Cart Cat order', 'cart-cat' ); ?></button></div>
	</dialog>
</div>
