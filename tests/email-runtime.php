<?php
/** Render native invoices locally; the delivery callback never sends mail. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable local Woo runtime only.' ); }
require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php';
$count = 0; $deliveries = array(); $sent = array(); $hook_calls = array(); $accept = true;
$assert = static function ( $value, $message ) use ( &$count ) { ++$count; if ( ! $value ) { throw new RuntimeException( $message ); } };
$order = new WC_Order(); $order->set_status( 'pending' ); $order->set_currency( 'USD' ); $order->set_date_created( time() ); $order->set_order_key( 'wc_order_invoice_fixture' ); $order->set_billing_first_name( 'Email' ); $order->set_billing_email( 'invoice-fixture@example.test' ); $order->set_total( '40.00' ); $order->update_meta_data( '_cart_cat_order', '1' );
$line = new WC_Order_Item_Product(); $line->set_name( 'Saved invoice product' ); $line->set_quantity( 1 ); $line->set_subtotal( '40.00' ); $line->set_total( '40.00' ); $order->add_item( $line );
$email = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'];
$old_object = $email->object; $old_recipient = $email->recipient; $old_settings = $email->settings; $old_placeholders = $email->placeholders; $old_email_type = $email->email_type;
$brand_style = array( 'STW_Email_Branding_Integrations', 'woo_styles' ); $original_brand_priority = has_filter( 'woocommerce_email_styles', $brand_style );
if ( false !== $original_brand_priority ) { remove_filter( 'woocommerce_email_styles', $brand_style, $original_brand_priority ); }
$number = static function ( $number, $candidate ) use ( $order ) { return $candidate === $order ? 'CC-10984' : $number; };
$mail_callback = static function () use ( &$deliveries, &$accept ) { return static function ( $recipient, $subject, $message, $headers, $attachments ) use ( &$deliveries, &$accept ) { $deliveries[] = compact( 'recipient', 'subject', 'message', 'headers', 'attachments' ); return $accept; }; };
$watch_sent = static function ( $success, $id, $object ) use ( &$sent, $email ) { if ( $object === $email ) { $sent[] = array( $success, $id ); } };
$watch_hook = static function () use ( &$hook_calls ) { $hook_calls[] = current_filter(); };
$native_hooks = array( 'woocommerce_email_header', 'woocommerce_email_order_details', 'woocommerce_email_order_meta', 'woocommerce_email_customer_details', 'woocommerce_email_footer' );
$button_from = static function ( $html ) {
	$document = new DOMDocument(); $previous = libxml_use_internal_errors( true );
	try { $document->loadHTML( $html ); return ( new DOMXPath( $document ) )->query( '//a[contains(concat(" ",normalize-space(@class)," ")," cart-cat-invoice-payment ")]' ); }
	finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
};
add_filter( 'woocommerce_order_number', $number, 10, 2 );
add_filter( 'woocommerce_mail_callback', $mail_callback, PHP_INT_MAX );
add_action( 'woocommerce_email_sent', $watch_sent, 10, 3 );
foreach ( $native_hooks as $hook ) { add_action( $hook, $watch_hook, PHP_INT_MAX, 0 ); }
try {
	$email->object = $order; $email->recipient = $order->get_billing_email(); $email->settings['additional_content'] = 'Fixture additional content'; $email->email_type = 'html';
	$title = 'Complete Your Purchase (Order #CC-10984)';
	$assert( $title === $email->get_subject() && $title === $email->get_heading(), 'Subject and heading use the exact requested wording and formatted order number.' );
	$html = $email->get_content_html(); $buttons = $button_from( $html );
	$assert( 1 === $buttons->length && 'Pay for this order' === $buttons->item( 0 )->textContent, 'The native payment link is rendered once as the requested button.' );
	$button = $buttons->item( 0 );
	$assert( 'p' === $button->parentNode->nodeName && 'Pay for this order' === trim( $button->parentNode->textContent ), 'The payment button occupies its own paragraph without surrounding sentence text.' );
	$assert( $order->get_checkout_payment_url() === html_entity_decode( $button->getAttribute( 'href' ), ENT_QUOTES ), 'The button preserves the exact secure native Woo payment link.' );
	$assert( count( $native_hooks ) === count( array_intersect( $native_hooks, $hook_calls ) ) && str_contains( $html, 'Saved invoice product' ) && str_contains( $html, 'Fixture additional content' ), 'Native invoice hooks, order content and configured additional content remain present.' );
	$before_plain = count( $hook_calls ); $plain = $email->get_content_plain();
	$assert( str_contains( $plain, $title ) && str_contains( html_entity_decode( $plain, ENT_QUOTES ), $order->get_order_key() ) && ! str_contains( $plain, 'cart-cat-invoice-payment' ), 'Plain text retains the exact heading and native usable payment URL without HTML button markup.' );
	$styled = $email->style_inline( $html ); $styled_button = $button_from( $styled )->item( 0 );
	$assert( $styled_button && str_contains( $styled_button->getAttribute( 'style' ), 'inline-block' ) && str_contains( $styled_button->getAttribute( 'style' ), 'padding:' ), 'With branding unavailable, Woo inlines the scoped fallback button style.' );
	$ordinary = new WC_Order(); $ordinary->set_status( 'pending' ); $ordinary->set_total( '40' ); $ordinary->set_date_created( time() ); $ordinary->set_order_key( 'wc_order_ordinary_fixture' );
	$args_for = static function ( $candidate, $plain = false, $admin = false ) use ( $email ) { return array( 'order' => $candidate, 'email' => $email, 'plain_text' => $plain, 'sent_to_admin' => $admin ); };
	$assert( '/native.php' === Cart_Cat\Invoice::template( '/native.php', 'emails/customer-invoice.php', $args_for( $ordinary ), '', '' ) && 'Ordinary title' === Cart_Cat\Invoice::title( 'Ordinary title', $ordinary, $email ), 'Ordinary Woo invoices keep their native title and template.' );
	$assert( '/native.php' === Cart_Cat\Invoice::template( '/native.php', 'emails/plain/customer-invoice.php', $args_for( $order, true ), '', '' ) && '/native.php' === Cart_Cat\Invoice::template( '/native.php', 'emails/customer-invoice.php', $args_for( $order, false, true ), '', '' ), 'Plain-text and admin template contexts remain native.' );
	$other_email = new WC_Email_Customer_Processing_Order();
	$assert( 'Other title' === Cart_Cat\Invoice::title( 'Other title', $order, $other_email ) && 'Original CSS' === Cart_Cat\Invoice::styles( 'Original CSS', $other_email ), 'Other Woo email types receive no Cart Cat invoice title or button CSS.' );
	foreach ( array( 'processing', 'completed', 'cancelled', 'on-hold' ) as $status ) {
		$order->set_status( $status );
		$assert( 'Native nonpayable title' === Cart_Cat\Invoice::title( 'Native nonpayable title', $order, $email ) && '/native.php' === Cart_Cat\Invoice::template( '/native.php', 'emails/customer-invoice.php', $args_for( $order ), '', '' ), 'Nonpayable ' . $status . ' invoices preserve their native behavior.' );
	}
	$order->set_status( 'pending' ); $order->set_total( '0' );
	$assert( 'Zero total title' === Cart_Cat\Invoice::title( 'Zero total title', $order, $email ), 'Zero-total orders do not request a purchase payment.' );
	$order->set_total( '40' ); $order->set_status( 'failed' ); $failed = $email->get_content_html();
	$assert( str_contains( $failed, 'try your payment again' ) && 1 === $button_from( $failed )->length, 'A retryable failed invoice retains its failed-payment introduction and separate button.' );
	$order->set_status( 'pending' );
	$email->trigger( 0, $order );
	$assert( 1 === count( $deliveries ) && $title === $deliveries[0]['subject'] && $order->get_billing_email() === $deliveries[0]['recipient'] && array( true, 'customer_invoice' ) === $sent[0], 'Native invoice trigger retains its recipient and successful mail callback contract without sending mail.' );
	$accept = false; $email->trigger( 0, $order );
	$assert( 2 === count( $deliveries ) && array( false, 'customer_invoice' ) === $sent[1], 'Native delivery failure is still reported by the existing Woo callback.' );
	// Optional authoritative branding source: load presentation code only, never SMTP or send hooks.
	$branding = ! empty( $args[0] ) ? rtrim( $args[0], '/' ) : '';
	if ( $branding ) {
		if ( ! function_exists( 'stw_email_branding_design_tokens' ) ) { require_once $branding . '/includes/functions-email-branding.php'; }
		if ( ! class_exists( 'STW_Email_Branding_Integrations' ) ) { require_once $branding . '/includes/class-stw-email-branding-integrations.php'; }
		$brand_style = array( 'STW_Email_Branding_Integrations', 'woo_styles' );
		add_filter( 'woocommerce_email_styles', $brand_style, 20, 2 );
		try {
			$styled = apply_filters( 'woocommerce_mail_content', $email->style_inline( $email->get_content_html() ) ); $button = $button_from( $styled )->item( 0 ); $style = $button->getAttribute( 'style' );
			$settings = stw_email_branding_get_settings(); $tokens = stw_email_branding_design_tokens();
			$assert( str_contains( strtolower( $style ), strtolower( $settings['colors']['accent'] ) ) && str_contains( $style, $tokens['button_border'] . 'px solid' ) && str_contains( $style, 'uppercase' ) && str_contains( $style, 'border-radius: 0' ), 'Active Stone Tower button styling overrides the fallback with the canonical accent outline.' );
			$assert( str_contains( $style, $tokens['button_padding_y'] . 'px ' . $tokens['button_padding_x'] . 'px' ) && str_contains( $style, $tokens['button_tracking'] ), 'The button uses the active branding padding and tracking tokens.' );
			if ( ! empty( $args[1] ) || ! empty( $args[2] ) ) {
				// Unsaved representative pickup with both automatic role pricing and a manual discount.
				$line->set_name( '2025 Rosé — Bottle (750ml)' ); $line->set_quantity( 2 ); $line->set_subtotal( '81.60' ); $line->set_total( '73.44' ); $line->set_taxes( array( 'subtotal' => array( 98201 => '4.896' ), 'total' => array( 98201 => '4.4064' ) ) );
				$pickup = new WC_Order_Item_Shipping(); $pickup->set_method_id( 'pickup_location' ); $pickup->set_method_title( 'The Club Shop at Tower View Tasting Room' ); $pickup->add_meta_data( 'pickup_location', 'The Club Shop at Tower View Tasting Room' ); $pickup->add_meta_data( 'pickup_address', 'Gate 2, 19925 Hogback Mountain Road, Leesburg, VA 20175' ); $pickup->add_meta_data( 'pickup_details', 'Please collect your order from the club shop during normal opening hours.' ); $order->add_item( $pickup );
				$tax = new WC_Order_Item_Tax(); $tax->set_rate_id( 98201 ); $tax->set_rate_percent( 6 ); $tax->set_label( 'Virginia wine tax' ); $tax->set_tax_total( '4.41' ); $order->add_item( $tax );
				$order->set_discount_total( '8.16' ); $order->set_discount_tax( '0.49' ); $order->set_cart_tax( '4.41' ); $order->set_total( '77.85' );
				$order->update_meta_data( '_cart_cat_review', array( 'lines' => array( array( 'name' => $line->get_name(), 'quantity' => 2, 'subtotal' => '96.00', 'automatic_savings' => '14.40', 'total' => '73.44', 'tax' => '4.41' ) ), 'automatic_discounts' => array( array( 'kind' => 'role', 'label' => 'Estate Club Discount', 'amount' => '14.40' ) ), 'totals' => array( 'total' => '77.85' ) ) );
				$representative = apply_filters( 'woocommerce_mail_content', $email->style_inline( $email->get_content_html() ) );
				if ( ! empty( $args[1] ) ) { file_put_contents( $args[1], $representative ); }
				if ( ! empty( $args[2] ) ) {
					$email->email_type = 'plain';
					$plain_output = apply_filters( 'woocommerce_mail_content', $email->get_content() );
					$assert( ! preg_match( '/<!doctype|<html\b|<body\b|cart-cat-invoice-payment/i', $plain_output ), 'The final plain-text artifact contains no HTML document or payment-button markup.' );
					$assert( str_contains( $plain_output, $title ) && str_contains( $plain_output, 'Estate Club Discount:' ) && str_contains( $plain_output, 'Additional discount:' ), 'The final plain-text artifact preserves the exact heading and both named discounts.' );
					file_put_contents( $args[2], $plain_output );
					$email->email_type = 'html';
				}
			}
		} finally { remove_filter( 'woocommerce_email_styles', $brand_style, 20 ); }
		$disabled = $email->style_inline( $email->get_content_html() );
		$assert( str_contains( $button_from( $disabled )->item( 0 )->getAttribute( 'style' ), 'border-radius: 3px' ), 'Removing branding restores the scoped fallback without changing global styles.' );
	}
	echo 'Cart Cat invoice runtime: ' . $count . ' assertions passed' . ( $branding ? ' with active branding source' : ' (branding source not provided)' ) . ".\n";
} finally {
	if ( false !== $original_brand_priority ) { add_filter( 'woocommerce_email_styles', $brand_style, $original_brand_priority, 2 ); }
	$email->object = $old_object; $email->recipient = $old_recipient; $email->settings = $old_settings; $email->placeholders = $old_placeholders; $email->email_type = $old_email_type;
	remove_filter( 'woocommerce_order_number', $number, 10 ); remove_filter( 'woocommerce_mail_callback', $mail_callback, PHP_INT_MAX ); remove_action( 'woocommerce_email_sent', $watch_sent, 10 );
	foreach ( $native_hooks as $hook ) { remove_action( $hook, $watch_hook, PHP_INT_MAX ); }
}
