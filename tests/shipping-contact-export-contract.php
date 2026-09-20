<?php
/** Run directly with PHP CLI. Exercises export hooks with synthetic orders; no bootstrap, network or writes. */
if ( PHP_SAPI !== 'cli' || defined( 'ABSPATH' ) ) { throw new RuntimeException( 'Standalone PHP CLI only.' ); }
define( 'ABSPATH', __DIR__ );
define( 'REST_REQUEST', true );
$GLOBALS['export_user'] = 37;
$GLOBALS['export_keys'] = array( 4, 5 );
$GLOBALS['export_webhooks'] = array( 1, 2, 3 );
$GLOBALS['export_available'] = true;
$GLOBALS['export_hooks'] = array();
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['export_hooks'][ $hook ] = array( $callback, $priority, $args ); }
function get_current_user_id() { return $GLOBALS['export_user']; }
function absint( $value ) { return abs( (int) $value ); }
function rest_get_url_prefix() { return 'wp-json'; }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wc_get_order( $id ) { return $GLOBALS['export_orders'][ $id ] ?? false; }
function wc_get_webhook( $id ) { return $GLOBALS['export_webhook_objects'][ $id ] ?? false; }
function vinoshipper_vole() {
	if ( ! $GLOBALS['export_available'] ) { throw new RuntimeException( 'Settings unavailable.' ); }
	return new class {
		public function settings() { return new class {
			public function shippo_api_key_ids() { return $GLOBALS['export_keys']; }
			public function shippo_webhook_ids() { return $GLOBALS['export_webhooks']; }
		}; }
		public function shippo_order_guard() { return new class {
			public function classify_order( $order ) { return $order->route; }
		}; }
	};
}
class WP { public $request = ''; public $query_vars = array(); }
class WP_REST_Request {
	public $method; public $route; public $headers = array(); public $params = array();
	public function __construct( $method, $route ) { $this->method = $method; $this->route = $route; }
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
}
class WP_REST_Response {
	public $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_data() { return $this->data; }
	public function set_data( $data ) { $this->data = $data; }
}
class WC_Validation { public static function is_phone( $phone ) { return ! preg_match( '/[^\s#0-9_+\/().-]/', $phone ); } }
class WC_Order_Item_Shipping {
	public $method;
	public function __construct( $method ) { $this->method = $method; }
	public function get_method_id() { return $this->method; }
}
class WC_Webhook {
	public $id; public $topic;
	public function __construct( $id, $topic ) { $this->id = $id; $this->topic = $topic; }
	public function get_id() { return $this->id; }
	public function get_topic() { return $this->topic; }
}
class WC_Order {
	public $meta = array( '_cart_cat_order' => '1', '_shipping_email' => 'recipient@example.test', '_cart_cat_input' => array( 'fulfillment' => array( 'type' => 'shipping' ) ) );
	public $billing = array( 'email' => 'buyer@example.test', 'phone' => '2025550101', 'first_name' => 'Buyer' );
	public $shipping = array( 'phone' => '2025550102', 'first_name' => 'Recipient', 'address_1' => '1 Shipping Lane' );
	public $route = 'shippo'; public $methods = array( 'ups' );
	public function get_id() { return 101; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function get_shipping_phone() { return $this->shipping['phone']; }
	public function get_items( $type ) { return array_map( static fn( $method ) => new WC_Order_Item_Shipping( $method ), $this->methods ); }
	public function payload() { return array( 'id' => 101, 'billing' => $this->billing, 'shipping' => $this->shipping, 'total' => '116.60' ); }
}
require __DIR__ . '/../cart-cat/includes/class-shipping-contact.php';
use Cart_Cat\Shipping_Contact;
$count = 0;
$assert = static function ( $condition, $message ) use ( &$count ) { $count++; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$wp = new WP();
$request = new WP_REST_Request( 'GET', '/wc/v3/orders/101' );
$order = new WC_Order();
$GLOBALS['export_orders'][101] = $order;
foreach ( array( 1 => 'order.created', 2 => 'order.updated', 3 => 'order.deleted', 9 => 'order.updated' ) as $id => $topic ) { $GLOBALS['export_webhook_objects'][ $id ] = new WC_Webhook( $id, $topic ); }
$export = static function ( $order, $key = 4, $request = null ) use ( $wp ) {
	$request = $request ?: new WP_REST_Request( 'GET', '/wc/v3/orders/101' );
	$wp->request = 'wp-json' . $request->get_route(); $wp->query_vars = array();
	Shipping_Contact::begin_request( null, null, $request );
	if ( null !== $key ) { Shipping_Contact::capture_api_key( false, $key, 37 ); }
	$response = Shipping_Contact::rest_order( new WP_REST_Response( $order->payload() ), $order, $request );
	Shipping_Contact::end_request( $response, null, $request );
	return $response->get_data();
};
Shipping_Contact::register();
$assert( 1 === $GLOBALS['export_hooks']['rest_pre_dispatch'][1], 'Request identity resets before Woo priority-10 authentication.' );
$assert( 3 === $GLOBALS['export_hooks']['woocommerce_disable_rest_api_access_log'][2], 'Only Woo authenticated key callback supplies consumer identity.' );
$assert( 4 === $GLOBALS['export_hooks']['woocommerce_webhook_payload'][2], 'Webhook hook receives Woo-owned resource and webhook IDs.' );
$before = serialize( $order ); $data = $export( $order );
$assert( 'recipient@example.test' === $data['billing']['email'] && 'recipient@example.test' === $data['shipping']['email'], 'Recognized Shippo key receives recipient email in both contact locations.' );
$assert( '2025550102' === $data['billing']['phone'] && '2025550102' === $data['shipping']['phone'], 'Recognized Shippo key receives recipient phone in both contact locations.' );
$assert( 'Buyer' === $data['billing']['first_name'] && 'Recipient' === $data['shipping']['first_name'] && '116.60' === $data['total'], 'Identity names, addresses and money remain untouched.' );
$assert( serialize( $order ) === $before, 'REST export never changes stored purchaser billing, contacts or metadata.' );
$assert( $data === $export( $order, 5 ), 'Each explicitly configured Shippo key is recognized.' );
$assert( $order->payload() === $export( $order, 99 ), 'Other integrations keep their original billing contact.' );
$assert( $order->payload() === $export( $order, null ), 'Anonymous or cookie-authenticated requests cannot inherit Shippo contact mapping.' );
$spoof = new WP_REST_Request( 'GET', '/wc/v3/orders/101' ); $spoof->headers = array( 'user-agent' => 'Shippo', 'x-wc-webhook-id' => 1 ); $spoof->params = array( 'consumer_key' => 4, 'user_id' => 37 );
$assert( $order->payload() === $export( $order, null, $spoof ), 'Shippo-looking request headers and parameters grant no export identity.' );
$GLOBALS['export_user'] = 99;
$assert( $order->payload() === $export( $order ), 'Authenticated key user must match the active user.' );
$GLOBALS['export_user'] = 37;
foreach ( array( 'POST', 'PUT', 'DELETE', 'OPTIONS' ) as $method ) { $assert( $order->payload() === $export( $order, 4, new WP_REST_Request( $method, '/wc/v3/orders/101' ) ), 'Only read requests receive recipient export: ' . $method ); }
$assert( $data === $export( $order, 4, new WP_REST_Request( 'HEAD', '/wc/v3/orders/101' ) ), 'HEAD shares the authorized read contract.' );
$assert( $data === $export( $order, 4, new WP_REST_Request( 'GET', '/wc/v3/orders' ) ), 'Recognized collection reads use recipient contacts.' );
foreach ( array( '/wc/v3/orders/101/notes', '/wc/v3/orders/batch', '/wc/v4/orders/101', '/cart-cat/v1/orders/101', '/wc/v3/orders/102' ) as $route ) { $assert( $order->payload() === $export( $order, 4, new WP_REST_Request( 'GET', $route ) ), 'Unrelated routes and mismatched order IDs retain the original payload: ' . $route ); }
$wp->request = 'wp-json/wc/v3/orders/101'; $wp->query_vars = array();
Shipping_Contact::begin_request( null, null, $request );
$assert( true === Shipping_Contact::capture_api_key( true, 4, 37 ), 'Identity capture preserves an existing access-log suppression decision.' );
$assert( false === Shipping_Contact::capture_api_key( false, 4, 37 ), 'Identity capture preserves native access logging when enabled.' );
$clone = clone $request;
$assert( $order->payload() === Shipping_Contact::rest_order( new WP_REST_Response( $order->payload() ), $order, $clone )->get_data(), 'A different request object cannot inherit the verified key.' );
$GLOBALS['export_keys'] = array();
$assert( $order->payload() === Shipping_Contact::rest_order( new WP_REST_Response( $order->payload() ), $order, $request )->get_data(), 'Revoked configuration is rechecked at export.' );
$GLOBALS['export_keys'] = array( 4, 5 );
Shipping_Contact::end_request( null, null, $request );
$assert( $order->payload() === Shipping_Contact::rest_order( new WP_REST_Response( $order->payload() ), $order, $request )->get_data(), 'Completed dispatch clears its captured identity.' );
$wp->request = 'wp-json/wc/v3/products';
Shipping_Contact::begin_request( null, null, $request ); Shipping_Contact::capture_api_key( false, 4, 37 );
$assert( $order->payload() === Shipping_Contact::rest_order( new WP_REST_Response( $order->payload() ), $order, $request )->get_data(), 'Internal order reads do not inherit an outer request for another resource.' );
$wp->request = ''; $wp->query_vars = array( 'rest_route' => '/wc/v3/orders/101' );
Shipping_Contact::begin_request( null, null, $request ); Shipping_Contact::capture_api_key( false, 4, 37 );
$assert( $data === Shipping_Contact::rest_order( new WP_REST_Response( $order->payload() ), $order, $request )->get_data(), 'The native query-string REST route is supported.' );
foreach ( array( 'pickup_location', 'local_pickup', 'legacy_local_pickup' ) as $method ) { $pickup = clone $order; $pickup->methods = array( $method ); $assert( $pickup->payload() === $export( $pickup ), 'Native pickup lines are never remapped: ' . $method ); }
$pickup = clone $order; $pickup->meta['_cart_cat_tax_location'] = array( 'country' => 'US' );
$assert( $pickup->payload() === $export( $pickup ), 'Saved pickup tax location prevents remapping.' );
$pickup = clone $order; $pickup->meta['_cart_cat_input']['fulfillment']['type'] = 'pickup';
$assert( $pickup->payload() === $export( $pickup ), 'Saved pickup intent prevents remapping.' );
$virtual = clone $order; $virtual->methods = array();
$assert( $virtual->payload() === $export( $virtual ), 'Virtual orders without physical shipping lines are not remapped.' );
$ordinary = clone $order; unset( $ordinary->meta['_cart_cat_order'] );
$assert( $ordinary->payload() === $export( $ordinary ), 'Non-Cart-Cat orders remain untouched.' );
foreach ( array( 'vinoshipper', 'unknown' ) as $route ) { $blocked = clone $order; $blocked->route = $route; $assert( $blocked->payload() === $export( $blocked ), 'Vole routing authority remains required: ' . $route ); }
$legacy = clone $order; unset( $legacy->meta['_shipping_email'] ); $legacy->shipping['phone'] = '';
$assert( $legacy->payload() === $export( $legacy ), 'Legacy orders without recipient contact retain both billing fallbacks.' );
$partial = clone $order; unset( $partial->meta['_shipping_email'] );
$assert( 'buyer@example.test' === $export( $partial )['billing']['email'] && '2025550102' === $export( $partial )['billing']['phone'], 'Missing recipient email preserves only its purchaser fallback.' );
$partial = clone $order; $partial->shipping['phone'] = '';
$assert( 'recipient@example.test' === $export( $partial )['billing']['email'] && '2025550101' === $export( $partial )['billing']['phone'], 'Missing recipient phone preserves only its purchaser fallback.' );
$invalid = clone $order; $invalid->meta['_shipping_email'] = array( 'invalid' ); $invalid->shipping['phone'] = 'not-a-number';
$assert( $invalid->payload() === $export( $invalid ), 'Malformed stored recipient contact does not replace valid purchaser fallback.' );
foreach ( array( 1, 2 ) as $id ) { $assert( $data === Shipping_Contact::webhook_order( $order->payload(), 'order', 101, $id ), 'Configured create/update webhook exports recipient contact.' ); }
$assert( serialize( $order ) === $before, 'Webhook export never saves changes to purchaser billing or metadata.' );
foreach ( array( 3, 9, 999 ) as $id ) { $assert( $order->payload() === Shipping_Contact::webhook_order( $order->payload(), 'order', 101, $id ), 'Deleted, unconfigured or unknown webhooks remain unchanged.' ); }
$assert( $order->payload() === Shipping_Contact::webhook_order( $order->payload(), 'customer', 101, 1 ), 'Order-looking data in another webhook resource is not remapped.' );
$assert( $order->payload() === Shipping_Contact::webhook_order( $order->payload(), 'order', 102, 1 ), 'Mismatched webhook order identity is not remapped.' );
unset( $GLOBALS['export_webhook_objects'][1] );
$assert( $order->payload() === Shipping_Contact::webhook_order( $order->payload(), 'order', 101, 1 ), 'Unresolvable configured webhooks cannot receive rewritten contact data.' );
$GLOBALS['export_available'] = false;
$assert( $order->payload() === $export( $order ), 'Unavailable Vole settings safely disable REST remapping.' );
$assert( $order->payload() === Shipping_Contact::webhook_order( $order->payload(), 'order', 101, 2 ), 'Unavailable Vole settings safely disable webhook remapping.' );
echo 'Cart Cat shipping contact export contract: ' . $count . " assertions passed.\n";
