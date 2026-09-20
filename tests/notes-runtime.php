<?php
/** Customer-visible note lifecycle in disposable native Woo; no email or payment is sent. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable local Woo runtime only.' ); }
require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php';

use Cart_Cat\Customer_Page;
use Cart_Cat\Orders;
use Cart_Cat\Payments;
use Cart_Cat\Quote;

$assertions = 0; $orders = array(); $request_keys = array(); $product = null;
$old_user = get_current_user_id(); $old_get = $_GET; $suffix = substr( wp_generate_uuid4(), 0, 8 );
$staff = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $staff ) { throw new RuntimeException( 'Disposable runtime requires an administrator fixture.' ); }
$assert = static function ( $value, $message ) use ( &$assertions ) { ++$assertions; if ( ! $value ) { throw new RuntimeException( $message ); } };
$ok = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; };
$error_is = static function ( $value, $code ) { return is_wp_error( $value ) && $value->get_error_code() === $code; };
$staff_notes = static function ( $id ) { return array_values( array_filter( wc_get_order_notes( array( 'order_id' => $id ) ), static function ( $note ) { return 0 === strpos( $note->content, 'Cart Cat staff note by ' ); } ) ); };
$mail_calls = 0; $provider_calls = 0; $customer_notifications = 0;
$mail_block = static function () use ( &$mail_calls ) { ++$mail_calls; return true; };
$mail_callback = static function () use ( &$mail_calls ) { return static function () use ( &$mail_calls ) { ++$mail_calls; return true; }; };
$transport = static function () use ( &$provider_calls ) { ++$provider_calls; return new WP_Error( 'fixture_provider_blocked', 'Note fixtures never contact Square.' ); };
$notification = static function () use ( &$customer_notifications ) { ++$customer_notifications; };
$config = static function () { return array( 'ready' => false, 'application_id' => 'notes-fixture-app', 'location_id' => 'notes-fixture-location', 'environment' => 'sandbox', 'currency' => 'USD' ); };
add_filter( 'pre_wp_mail', $mail_block, PHP_INT_MAX );
add_filter( 'woocommerce_mail_callback', $mail_callback, PHP_INT_MAX );
add_filter( 'cart_cat_square_transport', $transport, PHP_INT_MAX );
add_filter( 'cart_cat_square_public_config', $config, PHP_INT_MAX );
add_action( 'woocommerce_new_customer_note', $notification, PHP_INT_MAX );
$request = static function () use ( &$request_keys ) { $id = 'notes-' . wp_generate_uuid4(); $request_keys[] = 'cart_cat_request_' . hash( 'sha256', get_current_user_id() . ':' . $id ); return $id; };
$create = static function ( $input ) use ( $ok, $request, &$orders ) {
	$quote = $ok( Quote::calculate( $input ) );
	$result = $ok( Orders::create( $input, $quote['hash'], $request() ) ); $orders[] = $result['order_id'];
	return $result;
};
$reprepare = static function ( $id, $input ) use ( $ok, $request ) {
	$current = $ok( Orders::get( $id ) ); $editing = $ok( Orders::edit( $id, $current['version'] ) );
	$quote = $ok( Quote::calculate( $input ) ); $key = $request();
	$result = $ok( Orders::prepare( $id, $input, $quote['hash'], $editing['version'], $key ) );
	$repeat = $ok( Orders::prepare( $id, $input, $quote['hash'], $editing['version'], $key ) );
	if ( $result['version'] !== $repeat['version'] ) { throw new RuntimeException( 'Note reprepare retry changed order version.' ); }
	return $result;
};
$render_pay = static function ( $order ) {
	$previous_user = get_current_user_id(); $previous_get = $_GET;
	try {
		wp_set_current_user( 0 ); $_GET['key'] = $order->get_order_key();
		ob_start(); wc_get_template( 'checkout/form-pay.php', array( 'order' => $order, 'available_gateways' => array(), 'order_button_text' => 'Pay for order' ) ); return ob_get_clean();
	} finally { $_GET = $previous_get; wp_set_current_user( $previous_user ); }
};
$invoice = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'];
$invoice_object = $invoice->object; $invoice_recipient = $invoice->recipient;
$render_emails = static function ( $order ) use ( $invoice ) {
	$invoice->object = $order; $invoice->recipient = $order->get_billing_email();
	$processing = new WC_Email_Customer_Processing_Order(); $processing->object = $order;
	return array( 'invoice_html' => $invoice->get_content_html(), 'invoice_plain' => $invoice->get_content_plain(), 'processing_html' => $processing->get_content_html(), 'processing_plain' => $processing->get_content_plain() );
};
$render_details = static function ( $order ) { ob_start(); wc_get_template( 'order/order-details.php', array( 'order_id' => $order->get_id(), 'show_downloads' => false ) ); return ob_get_clean(); };
try {
	wp_set_current_user( (int) $staff[0] );
	foreach ( array( null, 0, 1, 'true', 'false', 'yes', array(), array( true ) ) as $invalid ) {
		$assert( $error_is( Quote::calculate( array( 'order_notes_visible' => $invalid ) ), 'cart_cat_order_notes_visible' ), 'Only an explicit boolean can opt notes into customer visibility.' );
	}
	$product = new WC_Product_Simple(); $product->set_name( 'Cart Cat notes fixture ' . $suffix ); $product->set_status( 'publish' ); $product->set_regular_price( '25' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 30 ); $product->save();
	$address = array( 'first_name' => 'Notes', 'last_name' => 'Fixture', 'country' => 'US', 'state' => 'VA', 'city' => 'Leesburg', 'postcode' => '20175', 'address_1' => '123 Test Street' );
	$input = array( 'customer' => array( 'first_name' => 'Notes', 'last_name' => 'Fixture', 'email' => 'notes-' . $suffix . '@example.test', 'billing' => $address, 'shipping' => $address ), 'lines' => array( array( 'product_id' => $product->get_id(), 'quantity' => 1, 'addons' => array() ) ), 'discount' => array( 'type' => 'percent', 'amount' => 0, 'mode' => 'add' ), 'fulfillment' => array( 'type' => 'pickup' ), 'order_notes' => "  <b>Note " . $suffix . "</b>\r\nCollect together & call first.  " );
	$note = 'Note ' . $suffix . "\nCollect together & call first.";
	$quote = $ok( Quote::calculate( $input ) ); $private = $input; $private['order_notes_visible'] = false; $private_quote = $ok( Quote::calculate( $private ) );
	$assert( $quote['can_submit'] && $quote['hash'] === $private_quote['hash'], 'Omitted visibility normalizes to the same private quote.' );
	$shared = $input; $shared['order_notes_visible'] = true; $shared_quote = $ok( Quote::calculate( $shared ) );
	$assert( $shared_quote['hash'] !== $quote['hash'] && $shared_quote['totals'] === $quote['totals'], 'Sharing is approved by a new quote without changing financial totals.' );
	$assert( $error_is( Orders::create( $shared, $quote['hash'], $request() ), 'cart_cat_stale_quote' ), 'An old private quote cannot authorize customer-visible notes.' );
	$assert( ! str_contains( wp_json_encode( $quote ), 'Collect together' ), 'Public quote data does not expose the staff input snapshot.' );
	$key = $request(); $created = $ok( Orders::create( $input, $quote['hash'], $key ) ); $orders[] = $created['order_id']; $id = $created['order_id'];
	$order = wc_get_order( $id );
	$assert( false === $created['order_notes_visible'] && false === $created['input']['order_notes_visible'] && 'no' === $order->get_meta( '_cart_cat_order_notes_visible' ), 'The default private choice is saved and restored as a boolean.' );
	$assert( '' === $order->get_customer_note() && $note === $order->get_meta( '_cart_cat_order_notes' ) && 1 === count( $staff_notes( $id ) ), 'Private preparation keeps the sanitized snapshot and staff audit without a native customer note.' );
	$assert( ! str_contains( $render_pay( $order ), 'Note ' . $suffix ) && ! str_contains( $render_pay( $order ), 'cc-pay-order-notes' ), 'Private note text and its empty section are absent from the payment page.' );
	foreach ( $render_emails( $order ) as $kind => $body ) { $assert( ! str_contains( $body, 'Note ' . $suffix ), $kind . ' does not leak a private note.' ); }
	$assert( ! str_contains( $render_details( $order ), 'Note ' . $suffix ), 'Native customer order details do not expose private notes.' );
	$repeat = $ok( Orders::create( $input, $quote['hash'], $key ) );
	$assert( $id === $repeat['order_id'] && 1 === count( $staff_notes( $id ) ) && false === $repeat['order_notes_visible'], 'Create retry retains one order and one private audit note.' );

	$published = $reprepare( $id, $shared ); $order = wc_get_order( $id );
	$assert( true === $published['input']['order_notes_visible'] && true === $published['order_notes_visible'] && 'yes' === $order->get_meta( '_cart_cat_order_notes_visible' ), 'Shared visibility survives reprepare, retry and reload.' );
	$assert( $note === $order->get_customer_note() && 1 === count( $staff_notes( $id ) ), 'Sharing existing text sets native customer_note without duplicating private audit history.' );
	$before = Payments::fingerprint( $order ); $page = $render_pay( $order );
	if ( ! empty( $args[0] ) ) { file_put_contents( $args[0], $page ); }
	$assert( 1 === substr_count( $page, 'cc-pay-order-notes"' ) && 1 === substr_count( $page, 'Note ' . $suffix ) && str_contains( $page, 'Collect together &amp; call first.' ), 'The payment page renders shared multiline text once with HTML escaping.' );
	$assert( ! str_contains( $page, 'Cart Cat staff note by' ) && ! str_contains( $page, '<b>Note ' ), 'Only customer-facing text appears, without staff attribution or submitted markup.' );
	foreach ( $render_emails( $order ) as $kind => $body ) { $assert( 1 === substr_count( $body, 'Note ' . $suffix ) && ! str_contains( $body, 'Cart Cat staff note by' ), $kind . ' displays the native shared note exactly once.' ); }
	$assert( 1 === substr_count( $render_details( $order ), 'Note ' . $suffix ), 'Native customer order details display the shared note exactly once.' );
	$assert( $before === Payments::fingerprint( wc_get_order( $id ) ) && $published['total'] === $created['total'], 'Note sharing and all rendered views leave the financial snapshot and total unchanged.' );
	$edit = $ok( Orders::edit( $id, $published['version'] ) );
	$assert( true === $edit['input']['order_notes_visible'] && $note === $edit['input']['order_notes'], 'Back to edit restores the current shared text and checkbox.' );
	$restored = $reprepare( $id, $private ); $order = wc_get_order( $id );
	$assert( false === $restored['order_notes_visible'] && '' === $order->get_customer_note() && 1 === count( $staff_notes( $id ) ), 'Unchecking clears customer-facing text while preserving the private note and history.' );
	$assert( ! str_contains( $render_pay( $order ), 'Note ' . $suffix ), 'The current payment page removes a newly private note.' );
	foreach ( $render_emails( $order ) as $kind => $body ) { $assert( ! str_contains( $body, 'Note ' . $suffix ), $kind . ' removes the newly private note from later messages.' ); }

	$direct = $create( $shared ); $direct_id = $direct['order_id']; $direct_order = wc_get_order( $direct_id );
	$assert( $note === $direct_order->get_customer_note() && true === $direct['order_notes_visible'], 'Opting in during first preparation immediately saves the native customer note.' );
	$clear = $shared; $clear['order_notes'] = ''; $cleared = $reprepare( $direct_id, $clear );
	$assert( true === $cleared['order_notes_visible'] && '' === wc_get_order( $direct_id )->get_customer_note() && 1 === count( $staff_notes( $direct_id ) ), 'Clearing checked notes removes current customer text without deleting staff history.' );
	$assert( ! str_contains( $render_pay( wc_get_order( $direct_id ) ), 'cc-pay-order-notes' ), 'An empty checked note does not leave a blank customer section.' );

	// Legacy private notes are not inferred to be customer-visible; dedicated metadata restores new orders.
	$legacy = $create( $private ); $legacy_order = wc_get_order( $legacy['order_id'] );
	$legacy_order->delete_meta_data( '_cart_cat_input' ); $legacy_order->delete_meta_data( '_cart_cat_order_notes_visible' ); $legacy_order->save();
	$legacy_reload = $ok( Orders::get( $legacy['order_id'] ) );
	$assert( false === $legacy_reload['order_notes_visible'] && false === $legacy_reload['input']['order_notes_visible'] && $note === $legacy_reload['input']['order_notes'] && '' === $legacy_order->get_customer_note(), 'Legacy snapshot fallback keeps old staff notes private by default.' );
	$shared_fallback = $create( $shared ); $fallback_order = wc_get_order( $shared_fallback['order_id'] ); $fallback_order->delete_meta_data( '_cart_cat_input' ); $fallback_order->save();
	$fallback = $ok( Orders::get( $fallback_order->get_id() ) );
	$assert( true === $fallback['order_notes_visible'] && true === $fallback['input']['order_notes_visible'] && $note === $fallback['input']['order_notes'], 'Dedicated saved visibility survives a missing editable input snapshot.' );

	// A native line-write failure must still remove the previously shared text before recovery.
	$fail_id = $fallback_order->get_id(); $editing = $ok( Orders::edit( $fail_id, $fallback['version'] ) );
	$attempted = $editing['input']; $attempted['order_notes_visible'] = false; $attempted['order_notes'] = 'Recovered private note ' . $suffix;
	$attempted_quote = $ok( Quote::calculate( $attempted ) );
	$fail_line = static function ( $item, $key, $values, $candidate ) use ( $fail_id ) { if ( $candidate->get_id() === $fail_id ) { throw new RuntimeException( 'Synthetic note recovery line failure.' ); } };
	add_action( 'woocommerce_checkout_create_order_line_item', $fail_line, PHP_INT_MAX, 4 );
	try { $failed = Orders::prepare( $fail_id, $attempted, $attempted_quote['hash'], $editing['version'], $request() ); }
	finally { remove_action( 'woocommerce_checkout_create_order_line_item', $fail_line, PHP_INT_MAX ); }
	$reload = $ok( Orders::get( $fail_id ) ); $failed_order = wc_get_order( $fail_id );
	$assert( $error_is( $failed, 'cart_cat_prepare_failed' ) && 'editing' === $reload['preparation'] && false === $reload['input']['order_notes_visible'], 'Failed reprepare retains the attempted private choice for recovery.' );
	$assert( '' === $failed_order->get_customer_note() && 1 === count( $staff_notes( $fail_id ) ), 'Failed reprepare removes old customer text without prematurely writing the new private audit.' );
	$recovered = $reprepare( $fail_id, $reload['input'] );
	$assert( false === $recovered['order_notes_visible'] && '' === wc_get_order( $fail_id )->get_customer_note() && 2 === count( $staff_notes( $fail_id ) ), 'Recovery saves the changed private audit exactly once without restoring customer visibility.' );
	foreach ( $staff_notes( $fail_id ) as $audit ) { $assert( '1' !== (string) get_comment_meta( $audit->id, 'is_customer_note', true ), 'Staff audit history remains private in native Woo.' ); }

	// Direct native customer notes still use escaping, and unauthorized payment keys disclose no note.
	$direct_order = wc_get_order( $direct_id ); $direct_order->set_customer_note( '<img src=x onerror=alert(1)> Escaping & fixture' );
	$escaped = $render_pay( $direct_order );
	$assert( str_contains( $escaped, '&lt;img src=x onerror=alert(1)&gt; Escaping &amp; fixture' ) && ! str_contains( $escaped, '<img src=x' ), 'Customer page escapes native note text even when another trusted writer supplied markup.' );
	wp_set_current_user( 0 ); $_GET['key'] = 'incorrect'; ob_start(); $order = $direct_order; $available_gateways = array(); $order_button_text = 'Pay'; include CART_CAT_PATH . 'templates/customer-pay.php'; $unauthorized = ob_get_clean(); wp_set_current_user( (int) $staff[0] );
	$assert( ! Customer_Page::authorized( $direct_order ) && '' === $unauthorized, 'The template access guard prevents shared notes from leaking under an incorrect key.' );
	$assert( 0 === $customer_notifications && 0 === $mail_calls && 0 === $provider_calls, 'Preparing, editing, retrying and rendering notes sends no customer-note notification, email or provider operation.' );
	echo 'Cart Cat notes runtime: ' . $assertions . " assertions passed.\n";
} finally {
	$invoice->object = $invoice_object; $invoice->recipient = $invoice_recipient;
	foreach ( $orders as $id ) { wp_clear_scheduled_hook( 'cart_cat_expire_order', array( $id ) ); $order = wc_get_order( $id ); if ( $order ) { wc_release_stock_for_order( $order ); $order->delete( true ); } }
	foreach ( $request_keys as $key ) { delete_option( $key ); }
	if ( $product && $product->get_id() ) { $product->delete( true ); }
	remove_filter( 'pre_wp_mail', $mail_block, PHP_INT_MAX ); remove_filter( 'woocommerce_mail_callback', $mail_callback, PHP_INT_MAX );
	remove_filter( 'cart_cat_square_transport', $transport, PHP_INT_MAX ); remove_filter( 'cart_cat_square_public_config', $config, PHP_INT_MAX );
	remove_action( 'woocommerce_new_customer_note', $notification, PHP_INT_MAX );
	$_GET = $old_get; wp_set_current_user( $old_user );
}
