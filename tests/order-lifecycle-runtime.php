<?php
/** Run through wp eval-file only against Cart Cat's disposable local Woo runtime. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable local Woo runtime only.' ); }
if ( ! defined( 'CART_CAT_PATH' ) ) { require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php'; }

use Cart_Cat\Orders;
use Cart_Cat\Payments;
use Cart_Cat\Payment_Lock;
use Cart_Cat\Quote;
use Cart_Cat\Stock_Hold;

$assertions = 0; $products = array(); $orders = array(); $request_keys = array(); $pricing_rules = array();
$old_user = get_current_user_id(); $old_post = $_POST;
$old_hold_minutes = get_option( 'woocommerce_hold_stock_minutes', false );
$old_case_rules = get_option( 'quantity_crab_rules', false ); $case_category = 0;
$suffix = substr( wp_generate_uuid4(), 0, 8 );
$staff = array_map( 'intval', get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) ) );
if ( ! $staff ) { throw new RuntimeException( 'Disposable runtime requires an administrator fixture.' ); }
wp_set_current_user( $staff[0] );
$assert = static function ( $value, $message ) use ( &$assertions ) { ++$assertions; if ( ! $value ) { throw new RuntimeException( $message ); } };
$ok = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; };
$is_error = static function ( $value, $code ) { return is_wp_error( $value ) && $value->get_error_code() === $code; };
$staff_notes = static function ( $id ) { return array_values( array_filter( wc_get_order_notes( array( 'order_id' => $id ) ), static function ( $note ) { return 0 === strpos( $note->content, 'Cart Cat staff note by ' ); } ) ); };
$ready = false; $mail_accept = false; $mail_calls = array(); $provider_calls = array(); $new_order_count = 0;
$config = static function () use ( &$ready ) { return array( 'ready' => $ready, 'application_id' => 'lifecycle-fixture-app', 'location_id' => 'lifecycle-fixture-location', 'environment' => 'sandbox', 'currency' => 'USD' ); };
$transport = static function ( $prior, $method, $path, $body ) use ( &$provider_calls ) {
	$provider_calls[] = array( $method, $path );
	if ( 'GET' === $method && '/v2/locations/lifecycle-fixture-location' === $path ) { return array( 'location' => array( 'id' => 'lifecycle-fixture-location', 'status' => 'ACTIVE', 'merchant_id' => 'lifecycle-fixture-merchant', 'currency' => 'USD' ) ); }
	return new WP_Error( 'fixture_provider_blocked', 'Lifecycle fixtures never submit provider operations.' );
};
$mailer = static function ( $callback, $email ) use ( &$mail_accept, &$mail_calls ) {
	return static function ( $to, $subject, $message, $headers, $attachments ) use ( &$mail_accept, &$mail_calls, $email ) {
		if ( 'customer_invoice' === $email->id ) { $mail_calls[] = array( 'to' => $to, 'message' => $message ); return $mail_accept; }
		return true;
	};
};
$new_order = static function () use ( &$new_order_count ) { ++$new_order_count; };
add_filter( 'cart_cat_square_public_config', $config, PHP_INT_MAX );
add_filter( 'cart_cat_square_transport', $transport, PHP_INT_MAX, 4 );
add_filter( 'woocommerce_mail_callback', $mailer, PHP_INT_MAX, 2 );
add_action( 'woocommerce_new_order', $new_order, PHP_INT_MAX );
$track_request = static function () use ( &$request_keys ) {
	$id = 'lifecycle-' . wp_generate_uuid4();
	$request_keys[] = 'cart_cat_request_' . hash( 'sha256', get_current_user_id() . ':' . $id );
	return $id;
};
$input_for = static function ( $product_id, $variation_id = 0 ) use ( $suffix ) {
	$address = array( 'first_name' => 'Lifecycle', 'last_name' => 'Fixture', 'country' => 'US', 'state' => 'CA', 'city' => 'San Francisco', 'postcode' => '94103', 'address_1' => '123 Test Street' );
	return array( 'customer' => array( 'first_name' => 'Lifecycle', 'last_name' => 'Fixture', 'email' => 'lifecycle-' . $suffix . '@example.test', 'billing' => $address, 'shipping' => $address ), 'lines' => array( array( 'product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => 1, 'addons' => array() ) ), 'discount' => array( 'type' => 'percent', 'amount' => 0, 'mode' => 'add' ), 'fulfillment' => array( 'type' => 'pickup' ) );
};
$prepare = static function ( $input ) use ( $ok, $track_request, &$orders ) {
	$quote = $ok( Quote::calculate( $input ) );
	$result = $ok( Orders::create( $input, $quote['hash'], $track_request() ) );
	$orders[] = $result['order_id'];
	return wc_get_order( $result['order_id'] );
};
$reserved = static function ( $id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(stock_quantity),0) FROM {$wpdb->prefix}wc_reserved_stock WHERE order_id=%d", $id ) );
};
$reservation_seconds = static function ( $id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT TIMESTAMPDIFF(SECOND,NOW(),MAX(expires)) FROM {$wpdb->prefix}wc_reserved_stock WHERE order_id=%d", $id ) );
};
try {
	update_option( 'woocommerce_hold_stock_minutes', 60 );
	foreach ( array( null, false, 123, array( 'private' ), str_repeat( 'a', 2001 ), str_repeat( 'a', 8001 ) ) as $invalid ) {
		$assert( $is_error( Quote::calculate( array( 'order_notes' => $invalid ) ), 'cart_cat_order_notes' ), 'Malformed or excessive private notes are rejected before quoting products.' );
	}
	$assert( 60 === Stock_Hold::config()['default_minutes'] && in_array( 10080, Stock_Hold::config()['steps'], true ), 'Hold config has the current default and seven-day maximum.' );
	foreach ( array( 0, 14, 10081, -60, 60.5, '60', null, array() ) as $invalid ) {
		$assert( $is_error( Quote::calculate( array( 'hold_minutes' => $invalid ) ), 'cart_cat_hold_minutes' ), 'Invalid hold values are rejected before quoting products.' );
	}
	update_option( 'woocommerce_hold_stock_minutes', 90 );
	$assert( 90 === Stock_Hold::config()['default_minutes'] && in_array( 90, Stock_Hold::config()['steps'], true ), 'A valid nonstandard Woo default is represented by the slider.' );
	update_option( 'woocommerce_hold_stock_minutes', 0 );
	$assert( 60 === Stock_Hold::config()['default_minutes'], 'Disabled global holds fall back to the Cart Cat default.' );
	update_option( 'woocommerce_hold_stock_minutes', 60 );
	$product = new WC_Product_Simple(); $product->set_name( 'Cart Cat lifecycle ' . $suffix ); $product->set_status( 'publish' ); $product->set_regular_price( '25' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 50 ); $product->save(); $products[] = $product->get_id();
	$input = $input_for( $product->get_id() );
	$input['order_notes'] = "  <b>Internal note " . $suffix . "</b>\r\nKeep this private.  ";
	$normalized_note = "Internal note " . $suffix . "\nKeep this private.";
	$quote = $ok( Quote::calculate( $input ) );
	$assert( $quote['can_submit'], 'Lifecycle fixture has a submittable pickup quote.' );
	$assert( 'regular' === $quote['order_type'], 'Unmarked orders default to regular.' );
	foreach ( array( 'club', '', null, false, array() ) as $invalid_type ) {
		$bad_type = $input; $bad_type['order_type'] = $invalid_type;
		$assert( $is_error( Quote::calculate( $bad_type ), 'cart_cat_order_type' ), 'Invalid types are rejected before preparation.' );
	}
	$somminar_input = $input; $somminar_input['order_type'] = 'somminar'; $somminar_quote = $ok( Quote::calculate( $somminar_input ) );
	$assert( $quote['hash'] !== $somminar_quote['hash'] && $quote['totals'] === $somminar_quote['totals'], 'SOMMinar type changes quote identity without changing prices.' );
	$assert( $is_error( Orders::create( $somminar_input, $quote['hash'], $track_request() ), 'cart_cat_stale_quote' ), 'A changed order type cannot use a prior approved quote.' );
	$somminar_order = $prepare( $somminar_input );
	$assert( 'somminar' === $somminar_order->get_meta( '_pupel_order_type' ) && 'somminar' === $ok( Orders::get( $somminar_order->get_id() ) )['input']['order_type'] && 'pending' === $somminar_order->get_status(), 'SOMMinar pickup persists its type and keeps the normal payment lifecycle.' );
	$paid_somminar = $somminar_order; $paid_somminar->payment_complete( 'fixture-somminar' );
	$assert( 'processing' === $paid_somminar->get_status() && 'somminar' === $paid_somminar->get_meta( '_pupel_order_type' ), 'Payment completion sends a physical SOMMinar order to Processing without losing its identity.' );
	$plain_notes_input = $input; $plain_notes_input['order_notes'] = $normalized_note;
	$assert( $quote['hash'] === $ok( Quote::calculate( $plain_notes_input ) )['hash'], 'Private notes are normalized before the quote hash, preserving multiline text while stripping markup.' );
	$changed_notes = $plain_notes_input; $changed_notes['order_notes'] .= "\nAnother private detail."; $changed_notes_quote = $ok( Quote::calculate( $changed_notes ) );
	$assert( $quote['hash'] !== $changed_notes_quote['hash'] && $quote['totals'] === $changed_notes_quote['totals'], 'A changed private note is bound by the approved quote without changing prices.' );
	$assert( $is_error( Orders::create( $changed_notes, $quote['hash'], $track_request() ), 'cart_cat_stale_quote' ), 'Create cannot save changed private notes against an older approved quote.' );
	$max_notes = $input; $max_notes['order_notes'] = str_repeat( 'é', 2000 );
	$assert( $ok( Quote::calculate( $max_notes ) )['can_submit'], 'The private note limit counts Unicode characters rather than bytes.' );
	$request = $track_request();
	$created = $ok( Orders::create( $input, $quote['hash'], $request ) ); $orders[] = $created['order_id'];
	$initial = wc_get_order( $created['order_id'] );
	$assert( 'pending' === $initial->get_status() && 'ready' === $initial->get_meta( '_cart_cat_preparation' ), 'Prepared order is pending and complete.' );
	$assert( 'regular' === $initial->get_meta( '_pupel_order_type' ) && 'regular' === $created['input']['order_type'], 'Regular orders explicitly retain their type.' );
	$initial_notes = $staff_notes( $initial->get_id() );
	$assert( $created['input']['order_notes'] === $normalized_note && $created['order_notes'] === $normalized_note, 'Prepared and reloaded staff input exposes the sanitized note snapshot.' );
	$assert( 1 === count( $initial_notes ) && "Cart Cat staff note by " . wp_get_current_user()->display_name . ":\n" . $normalized_note === $initial_notes[0]->content, 'Preparation writes exactly one attributed private Woo note.' );
	$private_comment = get_comment( $initial_notes[0]->id );
	$assert( wp_get_current_user()->display_name === $private_comment->comment_author && '1' !== (string) get_comment_meta( $private_comment->comment_ID, 'is_customer_note', true ) && '' === $initial->get_customer_note(), 'Woo retains the staff author while customer-visible notes remain empty.' );
	$assert( $quote['totals']['total'] === $initial->get_total(), 'Persisted invoice total equals approved quote.' );
	$assert( 1 === $reserved( $initial->get_id() ), 'Prepared order reserves its product quantity.' );
	$initial_deadline = (int) $initial->get_meta( '_cart_cat_expires_at' );
	$assert( 60 === $quote['hold_minutes'] && 60 === $created['hold']['minutes'] && $initial_deadline >= time() + 3595 && $initial_deadline <= time() + 3600, 'Create persists the selected hold and its absolute deadline.' );
	$assert( $created['hold']['active'] && $created['hold']['expires_label'] && 'unpaid' === $created['payment_status'] && 60 === $created['input']['hold_minutes'], 'Reload describes the active hold and normalized editable duration.' );
	$assert( $reservation_seconds( $initial->get_id() ) >= 3595 && $reservation_seconds( $initial->get_id() ) <= 3600, 'Native Woo inventory reservation uses the prepared deadline.' );
	$assert( ! $provider_calls && ! $mail_calls, 'Preparation does not contact Square or send invoice email.' );
	$before_count = $new_order_count;
	$repeat = $ok( Orders::create( $input, $quote['hash'], $request ) );
	$assert( $repeat['order_id'] === $created['order_id'] && $before_count === $new_order_count, 'Duplicate create returns the same order without another insert.' );
	$assert( 1 === count( wc_get_order( $repeat['order_id'] )->get_items() ) && 1 === $reserved( $initial->get_id() ), 'Duplicate create does not duplicate lines or reservation.' );
	$assert( $repeat['hold']['expires_at'] === $initial_deadline, 'Duplicate create preserves the original deadline.' );
	$assert( 1 === count( $staff_notes( $initial->get_id() ) ), 'Duplicate create never repeats the private Woo note.' );
	$longer = $input; $longer['hold_minutes'] = 2880; $longer_quote = $ok( Quote::calculate( $longer ) );
	$assert( $longer_quote['hash'] !== $quote['hash'] && $longer_quote['totals'] === $quote['totals'], 'The quote binds hold duration without changing prices.' );
	$assert( $is_error( Orders::create( $longer, $quote['hash'], $track_request() ), 'cart_cat_stale_quote' ), 'A changed hold cannot use an earlier quote approval.' );
	update_option( 'woocommerce_hold_stock_minutes', 0 ); $long_hold = $prepare( $longer );
	$assert( $reservation_seconds( $long_hold->get_id() ) >= 172795 && $reservation_seconds( $long_hold->get_id() ) <= 172800, 'A two-day native hold remains enabled when the global hold setting is zero.' );
	update_option( 'woocommerce_hold_stock_minutes', 60 );
	$long_hold->update_meta_data( '_cart_cat_expires_at', time() + 125 ); $long_hold->save(); wc_release_stock_for_order( $long_hold ); wc_reserve_stock_for_order( $long_hold );
	$assert( $reservation_seconds( $long_hold->get_id() ) >= 175 && $reservation_seconds( $long_hold->get_id() ) <= 180, 'A reserve refresh uses only the remaining deadline, rounded up to native minutes.' );
	$assert( false === apply_filters( 'woocommerce_cancel_unpaid_order', true, $long_hold ) && true === apply_filters( 'woocommerce_cancel_unpaid_order', true, new WC_Order() ), 'Global cancellation is vetoed only for Cart Cat, preserving the locked expiry owner.' );
	$before_count = $new_order_count;
	$changed = $input; $changed['lines'][0]['quantity'] = 2; $new_quote = $ok( Quote::calculate( $changed ) );
	$assert( $is_error( Orders::create( $changed, $new_quote['hash'], $request ), 'cart_cat_request_changed' ), 'An existing request cannot become a different approved quote.' );
	$stale_request = $track_request();
	$assert( $is_error( Orders::create( $changed, $quote['hash'], $stale_request ), 'cart_cat_stale_quote' ), 'Changed input with an old quote hash is rejected.' );
	$assert( false === get_option( end( $request_keys ), false ) && $before_count === $new_order_count, 'Stale quote creates neither durable request nor order.' );
	$product->set_regular_price( '26' ); $product->save();
	$assert( $is_error( Orders::create( $input, $quote['hash'], $track_request() ), 'cart_cat_stale_quote' ), 'Fresh preparation detects a catalog price change after preview.' );
	$product->set_regular_price( '25' ); $product->save();
	$assert( $initial->get_total() === wc_get_order( $initial->get_id() )->get_total(), 'Already prepared invoice remains fixed after catalog changes.' );

	// Exercise the actual Woo invoice email class with an in-process mail callback.
	$snapshot = Payments::fingerprint( wc_get_order( $initial->get_id() ) );
	$assert( $is_error( Orders::email( $initial->get_id() ), 'cart_cat_payment_unavailable' ) && ! $mail_calls, 'Invoice email requires a usable payment configuration.' );
	$ready = true;
	$assert( $is_error( Orders::email( $initial->get_id() ), 'cart_cat_email_failed' ), 'Actual invoice mail callback failure is reported.' );
	$assert( false === $ok( Orders::get( $initial->get_id() ) )['payment_email']['accepted'] && ! wc_get_order( $initial->get_id() )->get_meta( '_cart_cat_payment_email' ), 'A failed first email never marks the order as awaiting customer payment.' );
	$assert( 'pending' === wc_get_order( $initial->get_id() )->get_status() && $before_count === $new_order_count, 'Email failure retains the same unpaid order.' );
	$mail_accept = true;
	$sent = $ok( Orders::email( $initial->get_id() ) );
	$assert( $sent['order_id'] === $initial->get_id() && 'sent' === $sent['email_status'], 'Retry sends the saved invoice successfully.' );
	$accepted_email = $ok( Orders::get( $initial->get_id() ) )['payment_email'];
	$assert( true === $accepted_email['accepted'] && $input['customer']['email'] === $accepted_email['recipient'] && $initial->get_meta( '_cart_cat_version' ) === $accepted_email['version'] && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $accepted_email['accepted_at'] ), 'Email acceptance survives reload with the actual recipient, UTC time and current preparation version.' );
	$resent = $ok( Orders::email( $initial->get_id() ) );
	$assert( $resent['order_id'] === $initial->get_id() && $before_count === $new_order_count && 3 === count( $mail_calls ), 'Repeated email retries reuse one order.' );
	$assert( $input['customer']['email'] === $mail_calls[2]['to'], 'Invoice is addressed to the order customer.' );
	$assert( false !== strpos( html_entity_decode( $mail_calls[2]['message'], ENT_QUOTES ), $initial->get_order_key() ), 'Native invoice contains the saved order payment key.' );
	$assert( false === strpos( $mail_calls[2]['message'], 'Internal note ' . $suffix ) && false === strpos( $mail_calls[2]['message'], 'Keep this private.' ), 'Customer invoice HTML never exposes internal staff notes.' );
	$invoice = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'];
	$assert( false === strpos( $invoice->get_content_plain(), 'Internal note ' . $suffix ), 'Native plain-text invoice also excludes the private note.' );
	$assert( $snapshot === Payments::fingerprint( wc_get_order( $initial->get_id() ) ) && ! $provider_calls, 'Email success and failure leave payment snapshot fixed without provider traffic.' );
	$assert( $initial_deadline === $resent['hold']['expires_at'], 'Sending and resending payment email never extend the stock hold.' );
	$before_failed_resend = $ok( Orders::get( $initial->get_id() ) )['payment_email']; $mail_accept = false;
	$assert( $is_error( Orders::email( $initial->get_id() ), 'cart_cat_email_failed' ) && $before_failed_resend === $ok( Orders::get( $initial->get_id() ) )['payment_email'], 'A failed resend retains the successful email acceptance for this same order version.' );
	$mail_accept = true;
	$assert( $is_error( Orders::complete_free( $initial->get_id() ), 'cart_cat_requires_payment' ), 'Nonzero order cannot use free completion.' );

	// The final email confirmation owns its hold changes and durable, idempotent send attempt.
	$email_order = $prepare( $input ); $email_id = $email_order->get_id();
	$email_order->update_meta_data( '_cart_cat_expires_at', time() + 900 ); $email_order->save(); Stock_Hold::schedule( $email_order );
	$email_before = Stock_Hold::details( $email_order ); $email_snapshot = Payments::fingerprint( $email_order ); $email_calls_before = count( $mail_calls );
	$confirm = array( 'version' => $email_order->get_meta( '_cart_cat_version' ), 'hold_minutes' => 60, 'request_id' => 'email-confirm-' . wp_generate_uuid4() );
	$invalid_confirm = $confirm; unset( $invalid_confirm['request_id'] );
	$assert( $is_error( Orders::email( $email_id, $invalid_confirm ), 'cart_cat_email_request' ), 'The email wizard requires an explicit durable confirmation request.' );
	$invalid_confirm = $confirm; $invalid_confirm['hold_minutes'] = '60';
	$assert( $is_error( Orders::email( $email_id, $invalid_confirm ), 'cart_cat_hold_minutes' ), 'Email confirmation validates the same integer stock-hold bounds.' );
	$invalid_confirm = $confirm; $invalid_confirm['version'] = str_repeat( '0', 64 );
	$assert( $is_error( Orders::email( $email_id, $invalid_confirm ), 'cart_cat_order_changed' ), 'Email confirmation rejects a stale preparation version.' );
	$assert( $email_before === $ok( Orders::get( $email_id ) )['hold'] && count( $mail_calls ) === $email_calls_before, 'Invalid confirmation and read-only wizard previews never change holds or send mail.' );
	$lease = $ok( Payment_Lock::acquire( $email_id ) );
	try { $assert( $is_error( Orders::email( $email_id, $confirm ), 'cart_cat_payment_busy' ), 'Email hold confirmation shares the payment and expiry mutex.' ); }
	finally { Payment_Lock::release( $lease ); }
	$confirmed_email = $ok( Orders::email( $email_id, $confirm ) ); $confirmed_deadline = $confirmed_email['hold']['expires_at'];
	$assert( $confirmed_deadline >= time() + 3595 && $confirmed_deadline > $email_before['expires_at'] && 60 === $confirmed_email['input']['hold_minutes'], 'First email starts the chosen duration at final confirmation even when it matches the preparation default.' );
	$assert( $confirmed_deadline === (int) wp_next_scheduled( 'cart_cat_expire_order', array( $email_id ) ) && $reservation_seconds( $email_id ) >= 3595, 'Confirmed email duration reaches native inventory reservation and expiry scheduling.' );
	$assert( $email_snapshot === Payments::fingerprint( wc_get_order( $email_id ) ), 'Email hold changes leave the prepared financial fingerprint untouched.' );
	$confirmed_repeat = $ok( Orders::email( $email_id, $confirm ) );
	$assert( $confirmed_repeat['hold']['expires_at'] === $confirmed_deadline && count( $mail_calls ) === $email_calls_before + 1, 'An accepted request retry neither resends the email nor extends the stock hold.' );
	$changed_confirm = $confirm; $changed_confirm['hold_minutes'] = 120;
	$assert( $is_error( Orders::email( $email_id, $changed_confirm ), 'cart_cat_email_request_changed' ), 'An accepted email request ID cannot be reused with a different duration.' );
	$email_order = wc_get_order( $email_id ); $email_order->set_total( '999' ); $email_order->save();
	$assert( $is_error( Orders::email( $email_id, $confirm ), 'cart_cat_order_changed' ), 'Even an accepted request retry rejects changed financial order contents.' );
	$email_order->set_total( $confirmed_email['total'] ); $email_order->save();
	$resend_confirm = $confirm; $resend_confirm['request_id'] = 'email-resend-' . wp_generate_uuid4();
	$assert( $ok( Orders::email( $email_id, $resend_confirm ) )['hold']['expires_at'] === $confirmed_deadline && count( $mail_calls ) === $email_calls_before + 2, 'A deliberate resend with unchanged duration preserves the existing deadline.' );
	$changed_confirm['request_id'] = 'email-longer-' . wp_generate_uuid4(); $mail_accept = false;
	$failed_email = Orders::email( $email_id, $changed_confirm ); $failed_hold = $ok( Orders::get( $email_id ) )['hold'];
	$assert( $is_error( $failed_email, 'cart_cat_email_failed' ) && $failed_hold['expires_at'] >= time() + 7195 && 120 === $failed_hold['minutes'] && $failed_email->get_error_data()['hold'] === $failed_hold, 'A known mail failure keeps and returns the newly confirmed stock deadline.' );
	$mail_accept = true; $failed_retry = $ok( Orders::email( $email_id, $changed_confirm ) );
	$assert( $failed_hold['expires_at'] === $failed_retry['hold']['expires_at'], 'Retrying a known mail failure keeps its exact request deadline.' );
	$mail_count = count( $mail_calls ); $ok( Orders::email( $email_id, $changed_confirm ) );
	$assert( count( $mail_calls ) === $mail_count, 'The eventual accepted retry is also durably deduplicated.' );
	// Woo's own reserve exception deletes all existing rows; the surrounding transaction restores them.
	$rollback_confirm = $confirm; $rollback_confirm['request_id'] = 'email-stock-fail-' . wp_generate_uuid4(); $rollback_confirm['hold_minutes'] = 240;
	$prior_seconds = $reservation_seconds( $email_id );
	$fail_reservation = static function ( $quantity, $order ) use ( $email_id ) { if ( $order->get_id() === $email_id ) { throw new \Automattic\WooCommerce\Checkout\Helpers\ReserveStockException( 'fixture_stock_failure', 'Synthetic stock conflict.', 409 ); } return $quantity; };
	add_filter( 'woocommerce_order_item_quantity', $fail_reservation, PHP_INT_MAX, 2 );
	try { $hold_failure = Orders::email( $email_id, $rollback_confirm ); }
	finally { remove_filter( 'woocommerce_order_item_quantity', $fail_reservation, PHP_INT_MAX ); }
	$assert( $is_error( $hold_failure, 'cart_cat_stock_hold' ) && $failed_hold === $ok( Orders::get( $email_id ) )['hold'], 'Failed native reservation rolls back candidate hold metadata to its previous deadline.' );
	$rollback_reload = new WC_Order( $email_id ); $rollback_key = hash( 'sha256', get_current_user_id() . ':' . $rollback_confirm['request_id'] );
	$assert( 120 === (int) $rollback_reload->get_meta( '_cart_cat_hold_minutes' ) && 120 === $rollback_reload->get_meta( '_cart_cat_input' )['hold_minutes'] && ! isset( $rollback_reload->get_meta( '_cart_cat_email_requests' )[ $rollback_key ] ), 'Fresh order reads restore the prior input and remove the rolled-back email request from metadata caches.' );
	$assert( 1 === $reserved( $email_id ) && abs( $prior_seconds - $reservation_seconds( $email_id ) ) < 5 && $failed_hold['expires_at'] === (int) wp_next_scheduled( 'cart_cat_expire_order', array( $email_id ) ) && count( $mail_calls ) === $mail_count, 'A reserve failure preserves earlier stock rows and expiry scheduling without sending mail.' );
	$assert( $ok( Orders::email( $email_id, $rollback_confirm ) )['hold']['minutes'] === 240, 'The same confirmation request can safely retry after its reserve transaction rolled back.' );
	$email_order = wc_get_order( $email_id ); $email_order->update_meta_data( '_cart_cat_payment_attempt', array( 'state' => 'checking' ) ); $email_order->save();
	$checking_confirm = $confirm; $checking_confirm['request_id'] = 'email-checking-' . wp_generate_uuid4();
	$assert( $is_error( Orders::email( $email_id, $checking_confirm ), 'cart_cat_payment_checking' ), 'A checking payment blocks new email confirmations and hold changes.' );
	$email_order->delete_meta_data( '_cart_cat_payment_attempt' ); $email_order->save();
	$unknown_confirm = $confirm; $unknown_confirm['request_id'] = 'email-unknown-' . wp_generate_uuid4();
	$unknown_mailer = static function () { return static function () { throw new RuntimeException( 'Synthetic interruption during delivery.' ); }; };
	add_filter( 'woocommerce_mail_callback', $unknown_mailer, PHP_INT_MAX, 2 );
	try { $unknown_email = Orders::email( $email_id, $unknown_confirm ); }
	finally { remove_filter( 'woocommerce_mail_callback', $unknown_mailer, PHP_INT_MAX ); }
	$unknown_hold = $ok( Orders::get( $email_id ) )['hold']; $mail_count = count( $mail_calls );
	$assert( $is_error( $unknown_email, 'cart_cat_email_status_unavailable' ) && $unknown_email->get_error_data()['hold'] === $unknown_hold, 'An interrupted mail operation remains explicitly uncertain with its applied deadline visible.' );
	$assert( $is_error( Orders::email( $email_id, $unknown_confirm ), 'cart_cat_email_status_unavailable' ) && $unknown_hold === $ok( Orders::get( $email_id ) )['hold'] && $mail_count === count( $mail_calls ), 'An uncertain send retry does not duplicate delivery or restart the hold.' );
	$assert( true === $ok( Orders::get( $email_id ) )['payment_email']['uncertain'] && $is_error( Orders::email( $email_id ), 'cart_cat_email_status_unavailable' ), 'Reload exposes interrupted delivery and older empty-payload clients cannot duplicate it.' );
	$fresh_unknown = $unknown_confirm; $fresh_unknown['request_id'] = 'email-unknown-new-' . wp_generate_uuid4();
	$assert( $is_error( Orders::email( $email_id, $fresh_unknown ), 'cart_cat_email_status_unavailable' ), 'A new request ID cannot bypass a previously interrupted email attempt.' );
	$email_order = wc_get_order( $email_id ); $email_order->delete_meta_data( '_cart_cat_email_requests' ); $email_order->update_meta_data( '_cart_cat_expires_at', time() - 1 ); $email_order->save();
	$expired_confirm = $confirm; $expired_confirm['request_id'] = 'email-expired-' . wp_generate_uuid4();
	$assert( $is_error( Orders::email( $email_id, $expired_confirm ), 'cart_cat_order_expired' ), 'A late email confirmation cannot revive an expired stock hold.' );

	// Back to changes suspends payment while preserving the same order and native reservation.
	$edit_input = $input; $edit_order = $prepare( $edit_input ); $edit_id = $edit_order->get_id();
	$assert( true === $ok( Orders::email( $edit_id ) )['payment_email']['accepted'], 'The prepared order records successful invoice acceptance before editing.' );
	$old_version = $edit_order->get_meta( '_cart_cat_version' ); $old_key = $edit_order->get_order_key(); $before_count = $new_order_count;
	$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $edit_id ) ), 'content' );
	$assert( (bool) array_filter( $notes, static function ( $note ) { return 0 === strpos( $note, 'Prepared in Cart Cat by ' ) && false === strpos( $note, 'Manual discount' ) && false === strpos( $note, 'Role discount' ); } ), 'Zero manual discount and absent role pricing are omitted from preparation notes.' );
	$assert( $is_error( Orders::edit( $edit_id, str_repeat( '0', 64 ) ), 'cart_cat_order_changed' ), 'Edit rejects a stale order version.' );
	$lease = $ok( Payment_Lock::acquire( $edit_id ) );
	try {
		$assert( $is_error( Orders::edit( $edit_id, $old_version ), 'cart_cat_payment_busy' ), 'A competing payment lock blocks edit.' );
		$before_mail = count( $mail_calls );
		$assert( $is_error( Orders::email( $edit_id ), 'cart_cat_payment_busy' ) && $before_mail === count( $mail_calls ), 'Invoice rendering shares the edit/payment lock.' );
	} finally { Payment_Lock::release( $lease ); }
	$editing = $ok( Orders::edit( $edit_id, $old_version ) );
	$assert( $editing['order_id'] === $edit_id && 'editing' === $editing['preparation'] && $editing['can_edit'], 'Back to edit returns the same suspended order with editable data.' );
	$assert( 1 === $reserved( $edit_id ) && $before_count === $new_order_count, 'Entering edit retains stock and never inserts another order.' );
	$assert( $editing['input']['lines'][0]['name'] && $editing['input']['customer']['email'] === $input['customer']['email'] && $editing['review']['totals']['total'] === $edit_order->get_total(), 'Reload payload contains server-created line labels, customer data and saved totals.' );
	$assert( $editing['input']['order_notes'] === $normalized_note, 'Back to edit restores the current multiline internal note.' );
	$assert( 'editing' === $ok( Orders::edit( $edit_id, $old_version ) )['preparation'], 'Repeated Back to edit is idempotent.' );
	$before_provider = count( $provider_calls );
	$blocked = Payments::pay( $edit_id, array( 'source_type' => 'token', 'token' => 'cnon:lifecycle-fixture-only', 'version' => $old_version ) );
	$assert( $is_error( $blocked, 'cart_cat_not_ready' ) && $before_provider === count( $provider_calls ), 'An old customer payment page cannot charge while staff edit.' );
	$assert( $is_error( Orders::email( $edit_id ), 'cart_cat_not_payable' ), 'Suspended preparation cannot email a payable invoice.' );
	$edit_input = $editing['input']; $edit_input['lines'][0]['quantity'] = 2; $edit_input['discount']['amount'] = 10; $edit_input['hold_minutes'] = 1440;
	$edit_input['order_type'] = 'somminar';
	$edit_quote = $ok( Quote::calculate( $edit_input ) ); $edit_request = 'reprepare-' . wp_generate_uuid4();
	$assert( $is_error( Orders::prepare( $edit_id, $edit_input, $old_version, $old_version, $edit_request ), 'cart_cat_stale_quote' ), 'Reprepare rejects a stale preview without changing the suspended order.' );
	$prepared = $ok( Orders::prepare( $edit_id, $edit_input, $edit_quote['hash'], $old_version, $edit_request ) );
	$edited_order = wc_get_order( $edit_id );
	$assert( $prepared['order_id'] === $edit_id && $before_count === $new_order_count && 'ready' === $prepared['preparation'], 'Reprepare updates the existing order without another Woo insert.' );
	$assert( 'somminar' === $edited_order->get_meta( '_pupel_order_type' ) && 'somminar' === $prepared['input']['order_type'], 'Changing type during edit updates the same order and reload input.' );
	$assert( $old_version !== $prepared['version'] && $old_key !== $edited_order->get_order_key(), 'Every reprepare rotates the payment version and invalidates previously emailed guest links.' );
	$assert( false === $prepared['payment_email']['accepted'] && '' === $prepared['payment_email']['recipient'] && null === $prepared['payment_email']['accepted_at'] && $prepared['version'] === $prepared['payment_email']['version'], 'A new preparation version does not inherit an earlier accepted payment email.' );
	$assert( 1 === count( $edited_order->get_items() ) && 2 === $reserved( $edit_id ) && $edited_order->get_total() === $edit_quote['totals']['total'], 'Reprepare replaces lines and stock holds with exactly the new quote.' );
	$assert( Payments::fingerprint( $edited_order ) === $edited_order->get_meta( '_cart_cat_snapshot' ), 'Reprepared payment snapshot matches the saved order.' );
	$assert( 1440 === $prepared['hold']['minutes'] && $prepared['hold']['expires_at'] >= time() + 86395 && $reservation_seconds( $edit_id ) >= 86395, 'Reprepare starts the newly selected duration for the same order and native stock.' );
	$repeat_prepare = $ok( Orders::prepare( $edit_id, $edit_input, $edit_quote['hash'], $old_version, $edit_request ) );
	$assert( $repeat_prepare['version'] === $prepared['version'] && $before_count === $new_order_count && 2 === $reserved( $edit_id ), 'Retry of an uncertain reprepare returns the same final version without reapplying it.' );
	$assert( $repeat_prepare['hold']['expires_at'] === $prepared['hold']['expires_at'], 'Duplicate reprepare does not reset the deadline.' );
	$assert( 'somminar' === $repeat_prepare['input']['order_type'], 'Reprepare recovery retains the selected order type.' );
	$assert( 1 === count( $staff_notes( $edit_id ) ), 'Repreparing other fields and retrying it do not repeat an unchanged internal note.' );
	$assert( $is_error( Payments::pay( $edit_id, array( 'source_type' => 'token', 'token' => 'cnon:lifecycle-fixture-only', 'version' => $old_version ) ), 'cart_cat_order_changed' ), 'A customer form from the previous preparation cannot charge after reprepare.' );
	Orders::expire( $edit_id );
	$assert( 'pending' === wc_get_order( $edit_id )->get_status(), 'An old queued expiry cannot cancel a freshly reprepared order.' );
	$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $edit_id ) ), 'content' );
	$assert( (bool) array_filter( $notes, static function ( $note ) { return false !== strpos( $note, 'Manual discount was applied (10%).' ); } ), 'A real manual discount receives a readable preparation note.' );
	foreach ( array( 'creating_order', 'charging', 'checking', 'review', 'paid', 'unrecognized-state' ) as $state ) {
		$editing_order = wc_get_order( $edit_id ); $editing_order->update_meta_data( '_cart_cat_payment_attempt', array( 'state' => $state ) ); $editing_order->save();
		$assert( $is_error( Orders::edit( $edit_id, $prepared['version'] ), 'cart_cat_payment_checking' ), 'Non-final payment state ' . $state . ' blocks edits.' );
	}
	$editing_order = wc_get_order( $edit_id ); $editing_order->update_meta_data( '_cart_cat_payment_attempt', array( 'state' => 'declined', 'id' => 'fixture-declined', 'square_order_id' => 'fixture-old-square-order' ) ); $editing_order->update_meta_data( 'square_order_id', 'fixture-old-square-order' ); $editing_order->save();
	$declined_edit = $ok( Orders::edit( $edit_id, $prepared['version'] ) ); $editing_order = wc_get_order( $edit_id );
	$assert( ! $editing_order->get_meta( '_cart_cat_payment_attempt' ) && ! $editing_order->get_meta( 'square_order_id' ) && 'fixture-declined' === $editing_order->get_meta( '_cart_cat_payment_history' )[0]['id'], 'Editing a definitively declined attempt archives it and clears its active Square identity.' );
	$regular_again = $declined_edit['input']; $regular_again['order_type'] = 'regular'; $regular_quote = $ok( Quote::calculate( $regular_again ) );
	$regular_prepared = $ok( Orders::prepare( $edit_id, $regular_again, $regular_quote['hash'], $declined_edit['version'], 'regular-type-' . wp_generate_uuid4() ) );
	$assert( 'regular' === $regular_prepared['input']['order_type'] && 'regular' === wc_get_order( $edit_id )->get_meta( '_pupel_order_type' ), 'Turning off SOMMinar resets the existing order type without a replacement order.' );
	$legacy = $prepare( $input ); $legacy->delete_meta_data( '_cart_cat_input' ); $legacy->delete_meta_data( '_cart_cat_review' ); $legacy->save();
	$legacy_edit = $ok( Orders::edit( $legacy->get_id(), $legacy->get_meta( '_cart_cat_version' ) ) );
	$assert( (int) $legacy_edit['input']['lines'][0]['product_id'] === $product->get_id() && 'editing' === $legacy_edit['preparation'], 'Legacy simple product orders can safely resume without an old form snapshot.' );
	$assert( $legacy_edit['input']['order_notes'] === $normalized_note, 'Editable notes fall back to their dedicated snapshot when the full input is unavailable.' );
	$changed_stock_input = $legacy_edit['input']; $stock_quote = $ok( Quote::calculate( $changed_stock_input ) );
	$product->set_stock_quantity( 0 ); $product->save();
	$assert( $is_error( Orders::prepare( $legacy->get_id(), $changed_stock_input, $stock_quote['hash'], $legacy_edit['version'], 'stock-change-' . wp_generate_uuid4() ), 'cart_cat_product_stock' ), 'Reprepare revalidates stock after its displayed quote.' );
	$assert( 'editing' === wc_get_order( $legacy->get_id() )->get_meta( '_cart_cat_preparation' ), 'A stock-change failure keeps the same order paused for correction.' );
	$product->set_stock_quantity( 50 ); $product->save();
	// A failed native line write must leave a consistent, resumable attempted selection.
	$failure_order = $prepare( $input ); $failure_id = $failure_order->get_id(); $failure_key = $failure_order->get_order_key();
	$failure_edit = $ok( Orders::edit( $failure_id, $failure_order->get_meta( '_cart_cat_version' ) ) );
	$replacement = new WC_Product_Simple(); $replacement->set_name( 'Cart Cat failure recovery ' . $suffix ); $replacement->set_status( 'publish' ); $replacement->set_regular_price( '38' ); $replacement->save(); $products[] = $replacement->get_id();
	$attempted = $failure_edit['input']; $attempted['lines'] = array( array( 'product_id' => $replacement->get_id(), 'quantity' => 3, 'addons' => array() ) );
	$attempted['customer']['email'] = 'new-recipient-' . $suffix . '@example.test'; $attempted['customer']['billing']['address_1'] = '456 Changed Street'; $attempted['customer']['shipping']['address_1'] = '456 Changed Street';
	$attempted['order_notes'] = "Changed private note " . $suffix . "\nRetain through recovery.";
	$attempted_quote = $ok( Quote::calculate( $attempted ) );
	$fail_line = static function ( $item, $key, $values, $order ) use ( $failure_id ) { if ( $order->get_id() === $failure_id ) { throw new RuntimeException( 'Synthetic native line failure.' ); } };
	add_action( 'woocommerce_checkout_create_order_line_item', $fail_line, PHP_INT_MAX, 4 );
	try { $failed_prepare = Orders::prepare( $failure_id, $attempted, $attempted_quote['hash'], $failure_edit['version'], 'failure-recovery-' . wp_generate_uuid4() ); }
	finally { remove_action( 'woocommerce_checkout_create_order_line_item', $fail_line, PHP_INT_MAX ); }
	$assert( $is_error( $failed_prepare, 'cart_cat_prepare_failed' ), 'Native line failure is reported without another order.' );
	$failure_reload = $ok( Orders::get( $failure_id ) );
	$assert( 'editing' === $failure_reload['preparation'] && $failure_reload['can_edit'], 'Interrupted preparation remains paused and editable after reload.' );
	$assert( $attempted['customer']['email'] === $failure_reload['input']['customer']['email'] && '456 Changed Street' === $failure_reload['input']['customer']['billing']['address_1'] && $replacement->get_id() === (int) $failure_reload['input']['lines'][0]['product_id'] && 3 === (int) $failure_reload['input']['lines'][0]['quantity'], 'Reload restores the attempted customer, address, product and quantity together after a native write failure.' );
	$assert( $attempted_quote['totals']['total'] === $failure_reload['review']['totals']['total'] && $replacement->get_name() === $failure_reload['review']['lines'][0]['name'], 'The recovery review belongs to the same attempted selection.' );
	$assert( $failure_key !== wc_get_order( $failure_id )->get_order_key(), 'An old guest payment link is invalidated before failed changed-recipient preparation is saved.' );
	$assert( $failure_reload['input']['order_notes'] === $attempted['order_notes'] && $failure_reload['order_notes'] === $attempted['order_notes'] && 1 === count( $staff_notes( $failure_id ) ), 'Failed preparation preserves the attempted note for recovery without recording it as a completed preparation.' );
	$retry_quote = $ok( Quote::calculate( $failure_reload['input'] ) );
	$recovered = $ok( Orders::prepare( $failure_id, $failure_reload['input'], $retry_quote['hash'], $failure_reload['version'], 'failure-retry-' . wp_generate_uuid4() ) );
	$assert( $failure_id === $recovered['order_id'] && 'ready' === $recovered['preparation'] && $retry_quote['totals']['total'] === $recovered['total'], 'Recovery prepares the same order with the entire attempted selection.' );
	$assert( 2 === count( $staff_notes( $failure_id ) ) && '' === wc_get_order( $failure_id )->get_customer_note(), 'Successful recovery records the changed private note once and retains its earlier history.' );
	$notes_edit = $ok( Orders::edit( $failure_id, $recovered['version'] ) ); $cleared_input = $notes_edit['input']; $cleared_input['order_notes'] = ''; $cleared_quote = $ok( Quote::calculate( $cleared_input ) ); $clear_request = 'clear-notes-' . wp_generate_uuid4();
	$cleared = $ok( Orders::prepare( $failure_id, $cleared_input, $cleared_quote['hash'], $notes_edit['version'], $clear_request ) );
	$cleared_repeat = $ok( Orders::prepare( $failure_id, $cleared_input, $cleared_quote['hash'], $notes_edit['version'], $clear_request ) );
	$assert( '' === $cleared['input']['order_notes'] && '' === $cleared_repeat['order_notes'] && 2 === count( $staff_notes( $failure_id ) ), 'Clearing notes and retrying clears only the form snapshot, preserving existing private note history.' );
	if ( isset( $GLOBALS['Product_Addon_Cart'] ) ) {
		$personalized = new WC_Product_Simple(); $personalized->set_name( 'Cart Cat personalized ' . $suffix ); $personalized->set_status( 'publish' ); $personalized->set_regular_price( '15' ); $personalized->save(); $products[] = $personalized->get_id();
		update_post_meta( $personalized->get_id(), '_product_addons', array( array( 'id' => 1101, 'name' => 'Gift message', 'type' => 'custom_textarea', 'required' => 1, 'price' => 0, 'adjust_price' => 0, 'price_type' => 'flat_fee', 'restrictions' => 0, 'restrictions_type' => 'any_text', 'display' => 'select', 'options' => array(), 'position' => 0 ) ) );
		$personalized_config = $ok( Cart_Cat\Catalog::configuration( $personalized->get_id() ) ); $addon_key = $personalized_config['addons'][0]['key'];
		$personalized_input = $input_for( $personalized->get_id() ); $personalized_input['lines'][0]['addons'][ $addon_key ] = "Happy birthday!\nWith love,\nYour family";
		$personalized_order = $prepare( $personalized_input );
		$personalized_edit = $ok( Orders::edit( $personalized_order->get_id(), $personalized_order->get_meta( '_cart_cat_version' ) ) );
		$assert( $personalized_input['lines'][0]['addons'][ $addon_key ] === $personalized_edit['input']['lines'][0]['addons'][ $addon_key ], 'Saved multiline product personalization survives Back to edit exactly.' );
		$personalized_quote = $ok( Quote::calculate( $personalized_edit['input'] ) );
		$personalized_prepared = $ok( Orders::prepare( $personalized_order->get_id(), $personalized_edit['input'], $personalized_quote['hash'], $personalized_edit['version'], 'personalized-' . wp_generate_uuid4() ) );
		$assert( $personalized_input['lines'][0]['addons'][ $addon_key ] === $personalized_prepared['input']['lines'][0]['addons'][ $addon_key ], 'Multiline personalization survives reprepare and refresh without flattening newlines.' );
		$personalized_order = wc_get_order( $personalized_order->get_id() ); $personalized_order->delete_meta_data( '_cart_cat_input' ); $personalized_order->save();
		$assert( $is_error( Orders::edit( $personalized_order->get_id(), $personalized_order->get_meta( '_cart_cat_version' ) ), 'cart_cat_legacy_options' ), 'Legacy add-on orders refuse to guess recipient or personalization input.' );
		$assert( 'ready' === wc_get_order( $personalized_order->get_id() )->get_meta( '_cart_cat_preparation' ), 'Unsupported legacy reconstruction does not suspend or alter the saved order.' );
	}
	if ( class_exists( 'AF_C_S_P_Price' ) ) {
		$rule = wp_insert_post( array( 'post_type' => 'csp_rules', 'post_status' => 'publish', 'post_title' => 'Lifecycle Club Discount ' . $suffix, 'menu_order' => -1000 ) ); $pricing_rules[] = $rule;
		update_post_meta( $rule, 'csp_applied_on_products', array( $product->get_id() ) );
		update_post_meta( $rule, 'rrole_base_price_guest', serialize( array( 'discount_type' => 'percentage_decrease', 'discount_value' => 15 ) ) );
		$role_order = $prepare( $input );
		$names = $role_order->get_meta( '_cart_cat_role_discount_names' );
		$assert( array( 'Lifecycle Club Discount ' . $suffix ) === $names, 'The actual winning Addify rule title is stored, without unrelated customer roles.' );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $role_order->get_id() ) ), 'content' );
		$assert( (bool) array_filter( $notes, static function ( $note ) use ( $suffix ) { return false !== strpos( $note, 'Role discount was applied (Lifecycle Club Discount ' . $suffix . ').' ) && false === strpos( $note, 'Manual discount' ); } ), 'Preparation note identifies the applied role discount and omits zero manual discount.' );
		$replace_input = $input; $replace_input['discount']['mode'] = 'replace'; $replace_order = $prepare( $replace_input );
		$assert( array() === $replace_order->get_meta( '_cart_cat_role_discount_names' ), 'Replace role pricing does not record a role discount that was disabled.' );
		if ( class_exists( 'Quantity_Crab_Discounts' ) ) {
			$term = $ok( wp_insert_term( 'Lifecycle Case ' . $suffix, 'product_cat' ) ); $case_category = (int) $term['term_id'];
			$product->set_category_ids( array( $case_category ) ); $product->save();
			update_option( 'quantity_crab_rules', array( array( 'id' => 'lifecycle-case', 'name' => 'Lifecycle Case Discount', 'enabled' => true, 'category_ids' => array( $case_category ), 'include_simple' => true, 'threshold' => 2, 'default_rate' => 20, 'role_rates' => array( '__guest' => 20 ) ) ) );
			$case_input = $input; $case_input['lines'][0]['quantity'] = 2; $case_order = $prepare( $case_input );
			$assert( array() === $case_order->get_meta( '_cart_cat_role_discount_names' ) && array( 'Lifecycle Case Discount' ) === $case_order->get_meta( '_cart_cat_case_discount_names' ), 'A stronger case tier records its winning rule instead of the replaced Addify role rule.' );
			$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $case_order->get_id() ) ), 'content' );
			$assert( (bool) array_filter( $notes, static function ( $note ) { return false !== strpos( $note, 'Case discount was applied (Lifecycle Case Discount).' ) && false === strpos( $note, 'Role discount' ); } ), 'Case-discount preparation notes name the actual applied tier.' );
			if ( false === $old_case_rules ) { delete_option( 'quantity_crab_rules' ); } else { update_option( 'quantity_crab_rules', $old_case_rules ); }
			$product->set_category_ids( array() ); $product->save(); wp_delete_term( $case_category, 'product_cat' ); $case_category = 0;
		}
		wp_delete_post( $rule, true ); $pricing_rules = array();
	}

	$free_input = $input; $free_input['discount']['amount'] = 100;
	$free = $prepare( $free_input ); $before_provider = count( $provider_calls );
	$assert( '0.00' === $free->get_total(), 'Full product discount prepares a zero-total pickup order.' );
	$stock_before = (int) wc_get_product( $product->get_id() )->get_stock_quantity();
	$done = $ok( Orders::complete_free( $free->get_id() ) );
	$assert( 'paid' === $done['status'] && wc_get_order( $free->get_id() )->is_paid(), 'Staff can complete a zero-total order.' );
	$assert( $before_provider === count( $provider_calls ), 'Zero-total completion makes no Square request.' );
	$assert( $stock_before - 1 === (int) wc_get_product( $product->get_id() )->get_stock_quantity(), 'Zero-total completion reduces native stock once.' );
	$done_again = $ok( Orders::complete_free( $free->get_id() ) );
	$assert( 'paid' === $done_again['status'] && $stock_before - 1 === (int) wc_get_product( $product->get_id() )->get_stock_quantity(), 'Repeated zero-total completion never reduces stock twice.' );
	$assert( $is_error( Orders::email( $free->get_id() ), 'cart_cat_not_payable' ), 'Completed zero-total order has no payment invoice.' );
	$assert( $is_error( Orders::edit( $free->get_id(), $free->get_meta( '_cart_cat_version' ) ), 'cart_cat_not_editable' ), 'Paid orders cannot return to editable mode.' );

	$parent = new WC_Product_Variable(); $parent->set_name( 'Cart Cat lifecycle variation ' . $suffix ); $parent->set_status( 'publish' );
	$attribute = new WC_Product_Attribute(); $attribute->set_name( 'Size' ); $attribute->set_options( array( 'Bottle' ) ); $attribute->set_variation( true ); $parent->set_attributes( array( $attribute ) ); $parent->save(); $products[] = $parent->get_id();
	$variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); $variation->set_status( 'publish' ); $variation->set_regular_price( '25' ); $variation->set_attributes( array( 'size' => 'Bottle' ) ); $variation->save(); $products[] = $variation->get_id(); WC_Product_Variable::sync( $parent->get_id() );
	$variable_order = $prepare( $input_for( $parent->get_id(), $variation->get_id() ) );
	$assert( true === Orders::validate_payment( $variable_order ), 'Published variation and parent are initially payable.' );
	$parent->set_status( 'draft' ); $parent->save();
	$assert( 'publish' === wc_get_product( $variation->get_id() )->get_status(), 'Unpublishing the parent leaves the variation status published.' );
	$assert( $is_error( Orders::validate_payment( wc_get_order( $variable_order->get_id() ) ), 'cart_cat_product_changed' ), 'Unpublished parent blocks the pre-payment catalog validation.' );
	$before_provider = count( $provider_calls );
	$payment = Payments::pay( $variable_order->get_id(), array( 'source_type' => 'token', 'token' => 'cnon:lifecycle-fixture-only', 'version' => $variable_order->get_meta( '_cart_cat_version' ) ) );
	$assert( $is_error( $payment, 'cart_cat_product_changed' ) && ! wc_get_order( $variable_order->get_id() )->is_paid(), 'Real payment entry point refuses an unpublished variation parent.' );
	$recent_calls = array_slice( $provider_calls, $before_provider );
	$assert( ! array_filter( $recent_calls, static function ( $call ) { return 'POST' === $call[0]; } ), 'Unpublished parent never submits a Square order or charge.' );

	$expiring = $prepare( $input ); $expiring->update_meta_data( '_cart_cat_expires_at', time() - 1 ); $expiring->save();
	$before_mail = count( $mail_calls ); $before_provider = count( $provider_calls );
	$assert( $is_error( Orders::email( $expiring->get_id() ), 'cart_cat_order_expired' ) && count( $mail_calls ) === $before_mail, 'An expired hold rejects email before cron cancellation and sends nothing.' );
	$assert( $is_error( Payments::pay( $expiring->get_id(), array( 'source_type' => 'token', 'token' => 'cnon:lifecycle-fixture-only', 'version' => $expiring->get_meta( '_cart_cat_version' ) ) ), 'cart_cat_order_expired' ) && count( $provider_calls ) === $before_provider, 'An expired hold rejects a new charge before contacting Square.' );
	$assert( ! $ok( Orders::get( $expiring->get_id() ) )['hold']['active'], 'Reload marks an expired unpaid hold inactive even if cron is late.' );
	Orders::expire( $expiring->get_id() );
	$assert( 'cancelled' === wc_get_order( $expiring->get_id() )->get_status() && 0 === $reserved( $expiring->get_id() ), 'Unpaid order with no attempt expires and releases stock.' );
	$uncertain = $prepare( $input ); $uncertain->update_meta_data( '_cart_cat_payment_attempt', array( 'state' => 'checking' ) ); $uncertain->update_meta_data( '_cart_cat_expires_at', time() - 1 ); $uncertain->save();
	$before_mail = count( $mail_calls );
	$assert( $is_error( Orders::email( $uncertain->get_id() ), 'cart_cat_order_expired' ) && count( $mail_calls ) === $before_mail && ! Stock_Hold::expired( $uncertain ), 'A passed deadline blocks email while preserving unresolved-payment reconciliation eligibility.' );
	wp_clear_scheduled_hook( 'cart_cat_expire_order', array( $uncertain->get_id() ) );
	Orders::expire( $uncertain->get_id() );
	$assert( 'pending' === wc_get_order( $uncertain->get_id() )->get_status() && 1 === $reserved( $uncertain->get_id() ), 'Unresolved payment is not cancelled or released.' );
	$assert( $reservation_seconds( $uncertain->get_id() ) >= 895 && 'checking' === $ok( Orders::get( $uncertain->get_id() ) )['payment_status'] && ! Stock_Hold::expired( $uncertain ), 'Expiry retains a renewable native hold and checking state for an unresolved payment.' );
	$assert( false !== wp_next_scheduled( 'cart_cat_expire_order', array( $uncertain->get_id() ) ), 'Unresolved payment schedules another expiry check.' );
	$uncertain = wc_get_order( $uncertain->get_id() ); $uncertain->update_meta_data( '_cart_cat_payment_attempt', array( 'state' => 'declined' ) ); $uncertain->save(); Orders::expire( $uncertain->get_id() );
	$assert( 'cancelled' === wc_get_order( $uncertain->get_id() )->get_status() && 0 === $reserved( $uncertain->get_id() ), 'A definitively declined unpaid order can expire.' );
	Orders::expire( $free->get_id() );
	$assert( wc_get_order( $free->get_id() )->is_paid(), 'Expiry never cancels a completed order.' );
	$locked = $prepare( $input ); $lease = $ok( Payment_Lock::acquire( $locked->get_id() ) );
	try { Orders::expire( $locked->get_id() ); $assert( 'pending' === wc_get_order( $locked->get_id() )->get_status(), 'Expiry waits when payment holds the order mutex.' ); }
	finally { Payment_Lock::release( $lease ); }
	$assert( $staff[0] === get_current_user_id(), 'All lifecycle operations retain staff identity.' );
	$assert( $_POST === $old_post, 'All lifecycle operations retain request context.' );
	echo 'Cart Cat order lifecycle runtime: ' . $assertions . ' assertions passed on Woo ' . WC()->version . ".\n";
} finally {
	if ( false === $old_hold_minutes ) { delete_option( 'woocommerce_hold_stock_minutes' ); } else { update_option( 'woocommerce_hold_stock_minutes', $old_hold_minutes ); }
	foreach ( $orders as $id ) { wp_clear_scheduled_hook( 'cart_cat_expire_order', array( $id ) ); $order = wc_get_order( $id ); if ( $order ) { wc_release_stock_for_order( $order ); $order->delete( true ); } }
	foreach ( $request_keys as $key ) { delete_option( $key ); }
	foreach ( $pricing_rules as $id ) { wp_delete_post( $id, true ); }
	if ( $case_category ) { wp_delete_term( $case_category, 'product_cat' ); }
	if ( false === $old_case_rules ) { delete_option( 'quantity_crab_rules' ); } else { update_option( 'quantity_crab_rules', $old_case_rules ); }
	foreach ( array_reverse( $products ) as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
	remove_filter( 'cart_cat_square_public_config', $config, PHP_INT_MAX );
	remove_filter( 'cart_cat_square_transport', $transport, PHP_INT_MAX );
	remove_filter( 'woocommerce_mail_callback', $mailer, PHP_INT_MAX );
	remove_action( 'woocommerce_new_order', $new_order, PHP_INT_MAX );
	wp_set_current_user( $old_user );
}
