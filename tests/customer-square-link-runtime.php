<?php
/** Native WP/Woo regression: synthetic identities and intercepted Square transport only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable local Woo runtime only.' ); }
require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php';
use Cart_Cat\Customers;
use Cart_Cat\Orders;
use Cart_Cat\Payments;
use Cart_Cat\Quote;
use Cart_Cat\Square;
$assertions = 0; $users = array(); $orders = array(); $product = null; $requests = array();
$old_user = get_current_user_id();
$assert = static function ( $ok, $message ) use ( &$assertions ) { ++$assertions; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$ok = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; };
$error = static function ( $value, $code ) { return is_wp_error( $value ) && 'cart_cat_' . $code === $value->get_error_code(); };
$suffix = strtolower( wp_generate_password( 10, false ) );
$email = 'cc-link-' . $suffix . '@example.test';
$config = array( 'ready' => true, 'application_id' => 'link-app', 'location_id' => 'link-location', 'environment' => 'sandbox', 'currency' => 'USD' );
$configuration = static function () use ( &$config ) { return $config; };
add_filter( 'cart_cat_square_public_config', $configuration, PHP_INT_MAX );
$record = static function ( $id, $email ) { return array( 'id' => $id, 'given_name' => 'Fixture', 'family_name' => 'Member', 'email_address' => $email ); };
$records = array( 'linked' => $record( 'linked', $email ), 'duplicate' => $record( 'duplicate', $email ), 'alias' => $record( 'linked', $email ), 'other' => $record( 'other', 'other@example.test' ) );
$search_ids = array( 'duplicate', 'linked' ); $calls = array(); $outage = '';
$transport = static function ( $override, $method, $path, $body ) use ( &$records, &$search_ids, &$calls, &$outage, $record ) {
	$calls[] = array( $method, $path );
	if ( '/v2/customers/search' === $path && 'POST' === $method ) { return array( 'customers' => array_values( array_intersect_key( $records, array_flip( $search_ids ) ) ) ); }
	if ( 'GET' === $method && 0 === strpos( $path, '/v2/customers/' ) ) {
		$id = rawurldecode( substr( $path, 14 ) );
		return $id === $outage || ! isset( $records[ $id ] ) ? new WP_Error( 'fixture_unavailable', 'Synthetic outage' ) : array( 'customer' => $records[ $id ] );
	}
	if ( 'GET' === $method && 0 === strpos( $path, '/v2/cards?' ) ) {
		parse_str( wp_parse_url( $path, PHP_URL_QUERY ), $query );
		if ( 'linked' !== ( $query['customer_id'] ?? '' ) ) { return array( 'cards' => array( array( 'id' => 'ccof:expired', 'customer_id' => 'duplicate', 'enabled' => true, 'exp_month' => 1, 'exp_year' => 2020 ) ) ); }
		return array( 'cards' => array( array( 'id' => 'ccof:primary', 'customer_id' => 'linked', 'enabled' => true, 'exp_month' => 4, 'exp_year' => 2099, 'card_brand' => 'MASTERCARD', 'last_4' => '4242' ), array( 'id' => 'ccof:second', 'customer_id' => 'alias', 'enabled' => true, 'exp_month' => 7, 'exp_year' => 2099, 'card_brand' => 'VISA', 'last_4' => '1111' ) ) );
	}
	throw new RuntimeException( 'Unexpected provider operation: ' . $method . ' ' . $path );
};
add_filter( 'cart_cat_square_transport', $transport, PHP_INT_MAX, 4 );
$option_names = array( 'cart_cat_pickup_rate_id', 'pickup_location_pickup_locations' ); $options = array(); foreach ( $option_names as $name ) { $options[ $name ] = get_option( $name, null ); }
try {
	foreach ( array( 'staff', 'customer' ) as $kind ) {
		$id = $ok( wp_create_user( 'cc-link-' . $kind . '-' . $suffix, wp_generate_password( 24 ), 'customer' === $kind ? $email : 'cc-link-staff-' . $suffix . '@example.test' ) );
		$users[ $kind ] = $id; get_userdata( $id )->set_role( 'staff' === $kind ? 'administrator' : 'customer' );
	}
	wp_set_current_user( $users['staff'] ); $uid = $users['customer'];
	update_user_meta( $uid, 'billing_first_name', 'Local' ); update_user_meta( $uid, 'billing_last_name', 'Member' );
	update_user_meta( $uid, 'square_customer_id', 'linked' ); update_user_meta( $uid, '_stw_square_role_customer_id_1', 'linked' );
	$issuer = new ReflectionMethod( Customers::class, 'issue_reference' );
	$ref = $issuer->invoke( null, $uid, '' );
	$records['linked']['phone_number'] = '+15555550123'; $records['duplicate']['phone_number'] = '+15555550123';
	$phone_search = $ok( Customers::search( '55550123' ) );
	$assert( 1 === count( $phone_search['customers'] ) && 'Local Member' === $phone_search['customers'][0]['name'], 'Square-only search matches still select the linked Woo account when Woo fields do not match the query.' );
	$search = $ok( Customers::search( $email ) );
	$assert( 1 === count( $search['customers'] ), 'Duplicate same-email Square records collapse to the account selection when it has a stored link.' );
	$assert( 'Local Member' === $search['customers'][0]['name'], 'The local account remains the customer shown to staff.' );
	$assert( false === strpos( wp_json_encode( $search ), 'duplicate' ) && false === strpos( wp_json_encode( $search ), 'linked' ), 'Search exposes no Square provider IDs.' );
	$calls = array(); $resolved = $ok( Customers::resolve( array( 'reference' => $search['customers'][0]['reference'] ), true ) );
	$assert( 'linked' === $resolved['square_id'] && $uid === $resolved['woo_id'], 'The account selection resolves to its verified Square link despite duplicate email matches.' );
	$assert( array( array( 'GET', '/v2/customers/linked' ) ) === $calls, 'Identical stored links need one fresh RetrieveCustomer and no email search.' );
	$details = $ok( Customers::details( $ref ) );
	$assert( $details['square_linked'] && ! isset( $details['square_id'] ), 'Details report the verified link without exposing its provider ID.' );
	$stale = $issuer->invoke( null, $uid, 'duplicate' );
	$assert( $error( Customers::resolve( array( 'reference' => $stale ), true ), 'customer_square_conflict' ), 'A previously selected duplicate cannot override the verified account link.' );
	$explicit = $issuer->invoke( null, $uid, 'linked' );
	$assert( 'linked' === $ok( Customers::resolve( array( 'reference' => $explicit ), true ) )['square_id'], 'An explicit matching selection remains usable.' );
	update_user_meta( $uid, '_stw_square_role_customer_id_1', 'alias' );
	$assert( 'linked' === $ok( Customers::resolve( array( 'reference' => $ref ), true ) )['square_id'], 'Stored aliases that freshly resolve to the same canonical profile agree.' );
	$alias_ref = $issuer->invoke( null, $uid, 'alias' );
	$assert( 'linked' === $ok( Customers::resolve( array( 'reference' => $alias_ref ), true ) )['square_id'], 'A selected real merge alias resolves to the verified canonical profile.' );
	$records['linked']['email_address'] = 'changed@example.test';
	$assert( 'linked' === $ok( Customers::resolve( array( 'reference' => $ref ), true ) )['square_id'], 'A changed provider email does not replace a verified account identity.' );
	$records['linked']['email_address'] = $email;
	update_user_meta( $uid, '_stw_square_role_customer_id_1', 'other' );
	$assert( $error( Customers::resolve( array( 'reference' => $ref ), true ), 'customer_square_conflict' ), 'Conflicting stored account links fail closed.' );
	update_user_meta( $uid, '_stw_square_role_customer_id_1', 'alias' ); $outage = 'alias'; $calls = array();
	$assert( $error( Customers::resolve( array( 'reference' => $ref ), true ), 'customer_square_unavailable' ), 'An unavailable stored alias blocks fallback to an email match.' );
	$assert( ! in_array( array( 'POST', '/v2/customers/search' ), $calls, true ), 'A failed verified link never falls back to email discovery.' );
	$outage = '';
	foreach ( array( '../invalid', array( 'linked' ) ) as $invalid ) {
		update_user_meta( $uid, 'square_customer_id', $invalid ); $calls = array();
		$assert( $error( Customers::resolve( array( 'reference' => $ref ), true ), 'customer_square_link' ) && ! $calls, 'Malformed stored links fail before provider I/O.' );
	}
	delete_user_meta( $uid, 'square_customer_id' ); update_user_meta( $uid, '_stw_square_role_customer_id_1', 'alias' );
	$assert( 'linked' === $ok( Customers::resolve( array( 'reference' => $ref ), true ) )['square_id'], 'The existing role-reconciler link is sufficient when the main link is absent.' );
	delete_user_meta( $uid, '_stw_square_role_customer_id_1' );
	$ambiguous = $ok( Customers::resolve( array( 'reference' => $ref ), true ) );
	$assert( '' === $ambiguous['square_id'] && 'ambiguous' === $ambiguous['square_status'], 'Unlinked accounts retain ambiguous-email protection.' );
	$search_ids = array( 'linked' );
	$assert( 'linked' === $ok( Customers::resolve( array( 'reference' => $ref ), true ) )['square_id'], 'A genuinely unlinked account retains the unique exact-email fallback.' );
	$search_ids = array( 'duplicate', 'linked' );
	$guest_ref = $issuer->invoke( null, 0, 'duplicate' );
	$assert( 'duplicate' === $ok( Customers::resolve( array( 'reference' => $guest_ref ), true ) )['square_id'], 'A deliberately selected Square-only customer remains independent.' );
	$typed = $ok( Customers::resolve( array( 'email' => $email, 'woo_id' => $uid, 'square_id' => 'linked' ), true ) );
	$assert( 0 === $typed['woo_id'] && '' === $typed['square_id'], 'Typed email and client-supplied IDs cannot select an account.' );
	update_user_meta( $uid, 'square_customer_id', 'linked' ); update_user_meta( $uid, '_stw_square_role_customer_id_1', 'linked' );
	update_option( 'cart_cat_pickup_rate_id', 'pickup_location:0' );
	update_option( 'pickup_location_pickup_locations', array( array( 'enabled' => true, 'name' => 'Link fixture pickup', 'address' => array( 'country' => 'US', 'state' => 'VA', 'city' => 'Leesburg', 'postcode' => '20175', 'address_1' => '1 Fixture Lane' ) ) ) );
	$product = new WC_Product_Simple(); $product->set_name( 'Verified link fixture' ); $product->set_regular_price( '10.00' ); $product->set_tax_status( 'none' ); $product->set_virtual( true ); $product->save();
	$input = array( 'customer' => array( 'reference' => $ref ), 'lines' => array( array( 'product_id' => $product->get_id(), 'quantity' => 1 ) ), 'fulfillment' => array( 'type' => 'pickup' ) );
	$quote = $ok( Quote::calculate( $input ) );
	$assert( $quote['can_submit'], 'A verified linked account can receive a native Woo quote.' );
	$request = 'link-' . wp_generate_uuid4(); $requests[] = Orders::request_key( $request );
	$saved = $ok( Orders::create( $input, $quote['hash'], $request ) ); $orders[] = $saved['order_id'];
	$order = wc_get_order( $saved['order_id'] );
	$assert( 'linked' === $order->get_meta( '_cart_cat_square_customer_id' ), 'Preparation freezes the verified canonical customer on the actual Woo order.' );
	$cards = $ok( Payments::cards( $order->get_id() ) );
	$assert( 2 === count( $cards['cards'] ) && array( '4242', '1111' ) === array_column( $cards['cards'], 'last4' ), 'Cards on file includes both enabled unexpired cards of the verified account, including a merged card owner.' );
	$assert( false === strpos( wp_json_encode( $cards ), 'ccof:' ), 'Card references stay opaque in the staff response.' );
	// Reproduce an unpaid order prepared before this fix, then use the normal edit flow.
	$order->update_meta_data( '_cart_cat_square_customer_id', 'duplicate' );
	$order->update_meta_data( '_cart_cat_snapshot', Payments::fingerprint( $order ) ); $order->save();
	$assert( ! $ok( Payments::cards( $order->get_id() ) )['cards'], 'An old duplicate-linked order reproduces the empty saved-card list.' );
	$old_version = $order->get_meta( '_cart_cat_version' ); $old_key = $order->get_order_key();
	$editing = $ok( Orders::edit( $order->get_id(), $old_version ) );
	$assert( $error( Quote::calculate( $editing['input'] ), 'customer_square_conflict' ), 'The old order selection requires explicit customer reselection before a fresh quote.' );
	$corrected_input = $editing['input']; $corrected_input['customer']['reference'] = $ok( Customers::search( $email ) )['customers'][0]['reference'];
	$corrected_quote = $ok( Quote::calculate( $corrected_input ) );
	$prepared = $ok( Orders::prepare( $order->get_id(), $corrected_input, $corrected_quote['hash'], $old_version, 'link-reprepare-' . wp_generate_uuid4() ) );
	$order = wc_get_order( $order->get_id() );
	$assert( $saved['order_id'] === $prepared['order_id'] && 'linked' === $order->get_meta( '_cart_cat_square_customer_id' ), 'Normal staff reselection/repreparation corrects the same existing order without creating a second order.' );
	$assert( $old_version !== $prepared['version'] && $old_key !== $order->get_order_key(), 'Correcting the order rotates its payment version and old emailed access key.' );
	$assert( 2 === count( $ok( Payments::cards( $order->get_id() ) )['cards'] ), 'Both valid cards appear after the existing order is prepared with the verified account.' );
	$snapshot = Payments::fingerprint( $order );
	update_user_meta( $uid, 'square_customer_id', 'other' ); update_user_meta( $uid, '_stw_square_role_customer_id_1', 'other' );
	$assert( $error( Customers::resolve( array( 'reference' => Customers::reference_for_order( $order ) ), true ), 'customer_square_conflict' ), 'An old prepared-order selection cannot silently follow a changed account link.' );
	$assert( $snapshot === Payments::fingerprint( wc_get_order( $order->get_id() ) ), 'Customer resolution never rewrites a prepared payment identity or snapshot.' );
	$assert( get_current_user_id() === $users['staff'], 'Selection, quoting and card reads preserve staff authentication.' );
	$assert( ! array_filter( $calls, static function ( $call ) { return 'GET' !== $call[0] && array( 'POST', '/v2/customers/search' ) !== $call; } ), 'All Square operations are customer/card reads; no payment or customer write occurs.' );
	echo 'Cart Cat verified Square link runtime: ' . $assertions . ' assertions passed; Woo ' . WC_VERSION . '; ' . WC_Data_Store::load( 'order' )->get_current_class_name() . ".\n";
} finally {
	foreach ( $orders as $id ) { wp_clear_scheduled_hook( 'cart_cat_expire_order', array( $id ) ); $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	foreach ( $requests as $key ) { delete_option( 'cart_cat_request_' . $key ); }
	if ( $product ) { $product->delete( true ); }
	foreach ( $users as $id ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $id ); }
	foreach ( $options as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } }
	remove_filter( 'cart_cat_square_transport', $transport, PHP_INT_MAX ); remove_filter( 'cart_cat_square_public_config', $configuration, PHP_INT_MAX ); wp_set_current_user( $old_user );
}
