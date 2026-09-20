<?php
/** Native Woo REST/authentication + webhook fixture, restricted to disposable localhost. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable localhost only.' ); }
if ( function_exists( 'vinoshipper_vole' ) ) { throw new RuntimeException( 'This fixture needs an isolated process without an active Vole plugin.' ); }
if ( ! defined( 'REST_REQUEST' ) ) { define( 'REST_REQUEST', true ); }

// Only Vole configuration/routing is substituted. Woo authentication, REST, CRUD,
// address serialization, webhook generation and Cart Cat filters are native.
if ( ! function_exists( 'vinoshipper_vole' ) ) { function vinoshipper_vole() {
	return new class {
		public function settings() { return new class {
			public function shippo_api_key_ids() { return $GLOBALS['cc_export_native_keys']; }
			public function shippo_webhook_ids() { return $GLOBALS['cc_export_native_webhooks']; }
		}; }
		public function shippo_order_guard() { return new class {
			public function classify_order( $order ) { return $order->get_meta( '_cc_export_route' ) ?: 'shippo'; }
		}; }
	};
} }

global $wpdb, $wp;
$GLOBALS['cc_export_native_keys'] = array();
$GLOBALS['cc_export_native_webhooks'] = array();
$count = 0; $user_id = 0; $order = null; $product = null; $keys = array(); $webhooks = array();
$assert = static function ( $condition, $message ) use ( &$count ) { $count++; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$suffix = substr( wp_generate_uuid4(), 0, 8 );
$original_user = get_current_user_id(); $original_server = $_SERVER; $original_get = $_GET; $original_request = $wp->request; $original_query = $wp->query_vars;
$auth = WC_REST_Authentication::instance(); $original_auth = array();
foreach ( array( 'user', 'error', 'auth_method' ) as $property ) { $reflection = new ReflectionProperty( $auth, $property ); $original_auth[ $property ] = $reflection->getValue( $auth ); }
$reset_auth = static function () use ( $auth ) {
	foreach ( array( 'user' => null, 'error' => null, 'auth_method' => '' ) as $property => $value ) { ( new ReflectionProperty( $auth, $property ) )->setValue( $auth, $value ); }
	unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'] );
	$_GET = array();
};
$trace = array();
$trace_reset = static function ( $result, $server, $request ) use ( &$trace ) {
	$trace[] = 'reset'; return $result;
};
$trace_key = static function ( $disabled, $key_id, $user_id ) use ( &$trace ) {
	$trace[] = 'authenticated'; return $disabled;
};
$trace_serialization = static function ( $response, $order, $request ) use ( &$trace ) {
	$trace[] = 'serialized'; return $response;
};
add_filter( 'rest_pre_dispatch', $trace_reset, 2, 3 );
add_filter( 'woocommerce_disable_rest_api_access_log', $trace_key, PHP_INT_MAX, 3 );
add_filter( 'woocommerce_rest_prepare_shop_order_object', $trace_serialization, PHP_INT_MAX, 3 );
try {
	$user_id = wp_insert_user( array( 'user_login' => 'cc-export-' . $suffix, 'user_pass' => wp_generate_password( 32, true ), 'user_email' => 'cc-export-' . $suffix . '@example.test', 'role' => 'administrator' ) );
	$assert( ! is_wp_error( $user_id ), 'Synthetic API user created.' );
	foreach ( array( 'shippo', 'other' ) as $kind ) {
		$key = 'ck_' . wc_rand_hash(); $secret = 'cs_' . wc_rand_hash();
		$inserted = $wpdb->insert( $wpdb->prefix . 'woocommerce_api_keys', array( 'user_id' => $user_id, 'description' => 'Cart Cat export fixture ' . $suffix . ' ' . $kind, 'permissions' => 'read', 'consumer_key' => wc_api_hash( $key ), 'consumer_secret' => $secret, 'truncated_key' => substr( $key, -7 ) ) );
		$assert( 1 === $inserted, 'Synthetic read-only API key created.' );
		$keys[ $kind ] = array( 'id' => (int) $wpdb->insert_id, 'key' => $key, 'secret' => $secret );
	}
	$GLOBALS['cc_export_native_keys'] = array( $keys['shippo']['id'] );
	foreach ( array( 'shippo', 'other' ) as $kind ) {
		$webhook = new WC_Webhook(); $webhook->set_name( 'Cart Cat export fixture ' . $suffix . ' ' . $kind ); $webhook->set_topic( 'order.updated' ); $webhook->set_status( 'paused' ); $webhook->set_user_id( $user_id ); $webhook->set_api_version( 'wp_api_v3' ); $webhook->set_delivery_url( 'https://example.invalid/cart-cat-export-' . $suffix ); $webhook->set_secret( wp_generate_password( 32, true ) ); $webhook->save(); $webhooks[ $kind ] = $webhook;
	}
	$GLOBALS['cc_export_native_webhooks'] = array( $webhooks['shippo']->get_id() );
	$product = new WC_Product_Simple(); $product->set_name( 'Cart Cat export fixture ' . $suffix ); $product->set_regular_price( '100' ); $product->set_status( 'publish' ); $product->save();
	$order = new WC_Order(); $order->set_status( 'pending' ); $order->set_created_via( 'cart_cat' ); $order->set_billing_first_name( 'Purchaser' ); $order->set_billing_email( 'buyer-' . $suffix . '@example.test' ); $order->set_billing_phone( '2025550101' ); $order->set_shipping_first_name( 'Recipient' ); $order->set_shipping_phone( '2025550102' ); $order->set_shipping_address_1( '1 Fixture Lane' ); $order->set_shipping_city( 'Leesburg' ); $order->set_shipping_state( 'VA' ); $order->set_shipping_country( 'US' ); $order->set_shipping_postcode( '20175' );
	$order->update_meta_data( '_shipping_email', 'recipient-' . $suffix . '@example.test' ); $order->update_meta_data( '_cart_cat_order', '1' ); $order->update_meta_data( '_cart_cat_input', array( 'fulfillment' => array( 'type' => 'shipping' ) ) ); $order->add_product( $product, 1 );
	$shipping = new WC_Order_Item_Shipping(); $shipping->set_method_id( 'ups' ); $shipping->set_method_title( 'Fixture UPS Ground' ); $shipping->set_total( '10' ); $order->add_item( $shipping ); $order->calculate_totals( false ); $order->save();
	$order_id = $order->get_id(); $buyer = $order->get_billing_email(); $recipient = $order->get_meta( '_shipping_email' );
	$server = rest_get_server();
	$read = static function ( $kind, $route = null, $spoof = false ) use ( $auth, $keys, $user_id, $order_id, $server, $reset_auth, $assert, &$trace, $wp ) {
		$reset_auth(); $trace = array();
		$route = $route ?: '/wc/v3/orders/' . $order_id;
		$wp->request = 'wp-json' . $route; $wp->query_vars['rest_route'] = $route;
		$_SERVER['REQUEST_URI'] = '/wp-json' . $route; $_SERVER['HTTPS'] = 'on'; $_SERVER['REQUEST_METHOD'] = 'GET';
		wp_set_current_user( 0 );
		if ( isset( $keys[ $kind ] ) ) {
			$_SERVER['PHP_AUTH_USER'] = $keys[ $kind ]['key']; $_SERVER['PHP_AUTH_PW'] = $keys[ $kind ]['secret'];
			$authenticated = $auth->authenticate( false );
			$assert( $user_id === (int) $authenticated, 'Native Woo verifies synthetic API credentials.' );
			wp_set_current_user( $authenticated );
		} elseif ( 'cookie' === $kind ) { wp_set_current_user( $user_id ); }
		$request = new WP_REST_Request( 'GET', $route );
		if ( $spoof ) { $request->set_header( 'User-Agent', 'Shippo' ); $request->set_header( 'X-WC-Webhook-ID', (string) $GLOBALS['cc_export_native_webhooks'][0] ); $request->set_param( 'consumer_key', (string) $keys['shippo']['id'] ); }
		$response = rest_do_request( $request );
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), $server, $request );
		return $response;
	};
	$response = $read( 'shippo' ); $data = $response->get_data();
	$assert( 200 === $response->get_status(), 'Native authenticated order read succeeds: ' . wp_json_encode( $data['code'] ?? '' ) );
	$assert( array( 'reset', 'authenticated', 'serialized' ) === $trace, 'Native dispatch resets at priority 1 before Woo priority 10 captures identity, then serializes.' );
	$assert( $recipient === $data['billing']['email'] && $recipient === $data['shipping']['email'], 'Native recognized REST export supplies recipient email in both address objects.' );
	$assert( '2025550102' === $data['billing']['phone'] && '2025550102' === $data['shipping']['phone'], 'Native recognized REST export supplies recipient phone in both address objects.' );
	$assert( 'Purchaser' === $data['billing']['first_name'] && 'Recipient' === $data['shipping']['first_name'], 'Export preserves purchaser and recipient names.' );
	$other = $read( 'other' )->get_data();
	$assert( $buyer === $other['billing']['email'] && '2025550101' === $other['billing']['phone'] && ! isset( $other['shipping']['email'] ), 'Another genuinely authenticated API key receives original billing contacts.' );
	$cookie = $read( 'cookie', null, true )->get_data();
	$assert( $buyer === $cookie['billing']['email'] && ! isset( $cookie['shipping']['email'] ), 'Spoofed Shippo headers and numeric consumer-key parameter cannot remap a staff cookie read.' );
	$anonymous = $read( 'anonymous', null, true );
	$assert( 401 === $anonymous->get_status(), 'Spoofed anonymous Shippo request stays unauthorized.' );
	$fresh = wc_get_order( $order_id );
	$assert( $buyer === $fresh->get_billing_email() && '2025550101' === $fresh->get_billing_phone() && $recipient === $fresh->get_meta( '_shipping_email' ), 'Freshly loaded native order retains purchaser billing after every export.' );
	$reset_auth(); wp_set_current_user( $user_id ); $wp->request = ''; $wp->query_vars = array();
	$payload = $webhooks['shippo']->build_payload( $order_id );
	$assert( $recipient === $payload['billing']['email'] && $recipient === $payload['shipping']['email'] && '2025550102' === $payload['billing']['phone'], 'Native Woo build_payload exports recipient contacts for the configured Shippo webhook.' );
	$payload = $webhooks['other']->build_payload( $order_id );
	$assert( $buyer === $payload['billing']['email'] && '2025550101' === $payload['billing']['phone'] && ! isset( $payload['shipping']['email'] ), 'Native unrelated webhook preserves purchaser contacts.' );
	$shipping->set_method_id( 'pickup_location' ); $shipping->save();
	$pickup = $read( 'shippo' )->get_data();
	$assert( $buyer === $pickup['billing']['email'] && '2025550101' === $pickup['billing']['phone'], 'Native pickup shipping lines do not receive recipient remapping.' );
	$shipping->set_method_id( 'ups' ); $shipping->save();
	$fresh = wc_get_order( $order_id ); $fresh->delete_meta_data( '_shipping_email' ); $fresh->set_shipping_phone( '' ); $fresh->save();
	$legacy = $read( 'shippo' )->get_data();
	$assert( $buyer === $legacy['billing']['email'] && '2025550101' === $legacy['billing']['phone'] && ! isset( $legacy['shipping']['email'] ), 'Legacy native order with no recipient contacts retains billing fallback.' );
	$assert( 1 === has_filter( 'rest_pre_dispatch', array( Cart_Cat\Shipping_Contact::class, 'begin_request' ) ) && 10 === has_filter( 'rest_pre_dispatch', array( $auth, 'check_user_permissions' ) ), 'Registered priority ordering matches the tested native execution.' );
} finally {
	remove_filter( 'rest_pre_dispatch', $trace_reset, 2 ); remove_filter( 'woocommerce_disable_rest_api_access_log', $trace_key, PHP_INT_MAX ); remove_filter( 'woocommerce_rest_prepare_shop_order_object', $trace_serialization, PHP_INT_MAX );
	$reset_auth();
	foreach ( $original_auth as $property => $value ) { ( new ReflectionProperty( $auth, $property ) )->setValue( $auth, $value ); }
	$_SERVER = $original_server; $_GET = $original_get; $wp->request = $original_request; $wp->query_vars = $original_query;
	foreach ( $webhooks as $webhook ) { $webhook->delete( true ); }
	if ( $order instanceof WC_Order && $order->get_id() ) { wc_get_order( $order->get_id() )->delete( true ); }
	if ( $product instanceof WC_Product && $product->get_id() ) { $product->delete( true ); }
	foreach ( $keys as $key ) { $wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key['id'] ), array( '%d' ) ); }
	if ( is_int( $user_id ) && $user_id > 0 ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user_id ); }
	wp_set_current_user( $original_user );
}
echo 'Cart Cat shipping contact export native runtime: ' . $count . " assertions passed.\n";
