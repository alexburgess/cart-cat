<?php
/** Run only in the disposable local Woo runtime. No Square network requests. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable local Woo runtime only.' ); }
require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php';

$assertions = 0; $order = null; $product = null; $request_key = '';
$old_user = get_current_user_id();
$staff = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $staff[0] );
$assert = static function ( $value, $message ) use ( &$assertions ) { $assertions++; if ( ! $value ) { throw new RuntimeException( $message ); } };
$ok = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; };
$config = array( 'ready' => true, 'application_id' => 'scope-app', 'location_id' => 'scope-location', 'environment' => 'sandbox', 'currency' => 'USD' );
$configuration = static function () use ( &$config ) { return $config; };
$calls = 0;
$transport = static function () use ( &$calls ) { $calls++; return new WP_Error( 'fixture_network', 'No provider request expected.' ); };
add_filter( 'cart_cat_square_public_config', $configuration, PHP_INT_MAX );
add_filter( 'cart_cat_square_transport', $transport, PHP_INT_MAX );
$option_names = array( 'cart_cat_pickup_rate_id', 'pickup_location_pickup_locations' );
$options = array(); foreach ( $option_names as $name ) { $options[ $name ] = get_option( $name, null ); }
try {
	update_option( 'cart_cat_pickup_rate_id', 'pickup_location:0' );
	update_option( 'pickup_location_pickup_locations', array( array( 'enabled' => true, 'name' => 'Scope fixture pickup', 'address' => array( 'country' => 'US', 'state' => 'VA', 'city' => 'Leesburg', 'postcode' => '20175', 'address_1' => '1 Fixture Lane' ) ) ) );
	$product = new WC_Product_Simple(); $product->set_name( 'Cart Cat connection scope fixture' ); $product->set_regular_price( '10.00' ); $product->set_tax_status( 'none' ); $product->set_virtual( true ); $product->save();
	$input = array( 'customer' => array( 'first_name' => 'Scope', 'last_name' => 'Fixture', 'email' => 'scope@example.test' ), 'lines' => array( array( 'product_id' => $product->get_id(), 'quantity' => 1 ) ), 'fulfillment' => array( 'type' => 'pickup' ) );
	$quote = $ok( Cart_Cat\Quote::calculate( $input ) );
	$assert( $quote['can_submit'] && ! isset( $quote['_square_scope'] ), 'Valid public quote hides internal connection scope.' );
	foreach ( array( 'application_id' => 'new-app', 'location_id' => 'new-location', 'environment' => 'production', 'currency' => 'CAD' ) as $field => $value ) {
		$old = $config[ $field ]; $config[ $field ] = $value;
		$changed = $ok( Cart_Cat\Quote::calculate( $input ) );
		$assert( $changed['hash'] !== $quote['hash'], 'Quote freezes ' . $field );
		$stale = Cart_Cat\Orders::create( $input, $quote['hash'], 'scope-stale-' . wp_generate_uuid4() );
		$assert( is_wp_error( $stale ) && 'cart_cat_stale_quote' === $stale->get_error_code(), 'Old quote cannot prepare after changing ' . $field );
		$config[ $field ] = $old;
	}
	$assert( $quote['hash'] === $ok( Cart_Cat\Quote::calculate( $input ) )['hash'], 'Restoring connection preserves the original quote hash.' );
	$request = 'scope-' . wp_generate_uuid4(); $request_key = 'cart_cat_request_' . hash( 'sha256', get_current_user_id() . ':' . $request );
	$created = $ok( Cart_Cat\Orders::create( $input, $quote['hash'], $request ) ); $order = wc_get_order( $created['order_id'] );
	$assert( $order->get_meta( '_cart_cat_square_scope' ) === Cart_Cat\Square::scope(), 'Prepared order retains quote connection scope.' );
	$assert( empty( $order->get_items( 'shipping' ) ) && is_array( $order->get_meta( '_cart_cat_tax_location' ) ), 'Virtual pickup retains winery tax location without a shipping line.' );
	$assert( true === Cart_Cat\Orders::validate_payment( $order ), 'Virtual pickup remains valid for payment.' );
	$snapshot = Cart_Cat\Payments::fingerprint( $order ); $scope = $order->get_meta( '_cart_cat_square_scope' ); $scope['location_id'] = 'mutated'; $order->update_meta_data( '_cart_cat_square_scope', $scope );
	$assert( $snapshot !== Cart_Cat\Payments::fingerprint( $order ), 'Order fingerprint freezes prepared connection scope.' );
	$assert( 0 === $calls, 'Connection binding uses no provider requests during quote or preparation.' );
	echo 'Cart Cat Square scope runtime passed: ' . $assertions . ' assertions; Woo ' . WC_VERSION . '; ' . WC_Data_Store::load( 'order' )->get_current_class_name() . ".\n";
} finally {
	if ( $order ) { wp_clear_scheduled_hook( 'cart_cat_expire_order', array( $order->get_id() ) ); $order->delete( true ); }
	if ( $product ) { $product->delete( true ); }
	if ( $request_key ) { delete_option( $request_key ); }
	foreach ( $options as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } }
	remove_filter( 'cart_cat_square_public_config', $configuration, PHP_INT_MAX ); remove_filter( 'cart_cat_square_transport', $transport, PHP_INT_MAX ); wp_set_current_user( $old_user );
}
