<?php
/** Native Woo zones/REST plus installed UPS OAuth/validator source. Disposable localhost only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable localhost only.' ); }
if ( class_exists( 'WC_Shipping_UPS' ) ) { throw new RuntimeException( 'Use an isolated site without UPS active; this fixture supplies synthetic shipping configuration.' ); }
$source = getenv( 'CART_CAT_UPS_SOURCE' );
if ( ! $source || ! is_file( $source . '/includes/api/rest/class-address-validator.php' ) ) { throw new RuntimeException( 'CART_CAT_UPS_SOURCE must point to a source-only copy of WooCommerce UPS Shipping.' ); }
require_once $source . '/includes/api/class-abstract-address-validator.php';
require_once $source . '/includes/api/rest/class-address-validator.php';
require_once $source . '/includes/api/rest/class-oauth.php';

// Only method configuration is substituted. Discovery, authentication transport,
// validation requests, response parsing/cache and Cart Cat REST are real code.
if ( ! class_exists( 'WC_Shipping_UPS' ) ) { class WC_Shipping_UPS extends WC_Shipping_Method {
	public function __construct( $instance_id = 0 ) {
		$this->id = 'ups'; $this->instance_id = absint( $instance_id ); $this->supports = array( 'shipping-zones', 'instance-settings' );
		$this->enabled = 'yes'; $this->method_title = 'UPS fixture';
	}
	public function get_api_type(): string { return 'rest'; }
	public function is_destination_address_validation_enabled(): bool { return true; }
	public function country_supports_address_validation( $country ): bool { return in_array( $country, array( 'US', 'PR' ), true ); }
	public function get_ups_oauth(): WooCommerce\UPS\API\REST\OAuth { return new WooCommerce\UPS\API\REST\OAuth( 'fixture-client', 'fixture-secret' ); }
} }

$count = 0; $user_id = 0; $zone = null; $requests = array(); $mode = 'exact'; $new_orders = 0; $mail = 0; $cache_keys = array();
$original_user = get_current_user_id();
$original_objects = array( WC()->cart, WC()->customer, WC()->session );
$assert = static function ( $condition, $message ) use ( &$count ) { ++$count; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$methods = static function ( $methods ) { $methods['ups'] = WC_Shipping_UPS::class; return $methods; };
$order_event = static function () use ( &$new_orders ) { ++$new_orders; };
$mail_event = static function () use ( &$mail ) { ++$mail; return true; };
$oauth_cache = array();
foreach ( array( 'access_token', 'client_id', 'client_secret' ) as $key ) {
	$name = 'woocommerce_ups_oauth_' . $key; $oauth_cache[ $name ] = get_transient( $name ); delete_transient( $name );
}
$http = static function ( $pre, $args, $url ) use ( &$requests, &$mode ) {
	$requests[] = array( 'url' => $url, 'args' => $args );
	if ( 'https://onlinetools.ups.com/security/v1/oauth/token' === $url ) {
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'body' => wp_json_encode( array( 'access_token' => 'fixture-only-token', 'expires_in' => 3600 ) ) );
	}
	if ( 0 !== strpos( $url, 'https://onlinetools.ups.com/api/addressvalidation/v2/3' ) ) { throw new RuntimeException( 'Unexpected HTTP endpoint; no external transport is permitted.' ); }
	if ( 'timeout' === $mode ) { return new WP_Error( 'fixture_timeout', 'Private upstream error fixture-only-token' ); }
	if ( 'forbidden' === $mode ) { return array( 'response' => array( 'code' => 401, 'message' => 'Unauthorized' ), 'headers' => array(), 'body' => '{}' ); }
	if ( 'malformed' === $mode ) { return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'body' => '{broken' ); }
	$address = json_decode( $args['body'], true )['XAVRequest']['AddressKeyFormat'];
	$response = array( 'Response' => array( 'ResponseStatus' => array( 'Code' => '1', 'Description' => 'Success' ) ), 'AddressClassification' => array( 'Code' => '2', 'Description' => 'Residential' ) );
	if ( 'none' === $mode ) { $response['NoCandidatesIndicator'] = ''; }
	else {
		$response[ 'ambiguous' === $mode ? 'AmbiguousAddressIndicator' : 'ValidAddressIndicator' ] = '';
		if ( in_array( $mode, array( 'suggested', 'ambiguous' ), true ) ) { $address['AddressLine'][0] = '123 FIXTURE LN'; $address['PostcodeExtendedLow'] = '1234'; }
		if ( 'unit' === $mode ) { $address['AddressLine'] = array( $address['AddressLine'][0] ); }
		$response['Candidate'] = array( array( 'AddressKeyFormat' => $address ) );
	}
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'body' => wp_json_encode( array( 'XAVResponse' => $response ) ) );
};
add_filter( 'woocommerce_shipping_methods', $methods );
add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
add_action( 'woocommerce_new_order', $order_event, PHP_INT_MAX );
add_filter( 'pre_wp_mail', $mail_event, PHP_INT_MAX );
try {
	$user_id = wp_create_user( 'cc-ups-' . substr( wp_generate_uuid4(), 0, 8 ), wp_generate_password(), 'cc-ups-' . wp_generate_uuid4() . '@example.test' );
	if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
	( new WP_User( $user_id ) )->set_role( 'administrator' ); wp_set_current_user( $user_id );
	$zone = new WC_Shipping_Zone(); $zone->set_zone_name( 'Cart Cat UPS fixture' ); $zone->set_zone_order( -1000 ); $zone->add_location( 'US', 'country' ); $zone->add_location( 'PR', 'country' ); $zone->save();
	$zone->add_shipping_method( 'ups' ); WC_Cache_Helper::get_transient_version( 'shipping', true );
	$address = array( 'address_1' => '123 Fixture Lane', 'address_2' => '', 'city' => 'Leesburg', 'state' => 'VA', 'postcode' => '20175', 'country' => 'US' );
	$clear = static function ( $address ) use ( &$cache_keys ) {
		$address = array_intersect_key( $address, array_flip( array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) ) );
		if ( 'PR' === $address['country'] ) { $address['state'] = 'PR'; $address['country'] = 'US'; }
		$key = 'ups_rest_av_' . md5( wp_json_encode( $address ) ); $cache_keys[] = $key; delete_transient( $key );
	};
	$read = static function ( $address, $nonce = true ) {
		$request = new WP_REST_Request( 'POST', '/cart-cat/v1/shipping/validate-address' );
		$request->set_header( 'Content-Type', 'application/json' ); if ( $nonce ) { $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); }
		$request->set_body( wp_json_encode( array( 'address' => $address ) ) ); return rest_do_request( $request );
	};
	$clear( $address );
	$response = $read( $address + array( 'email' => 'recipient@example.test', 'phone' => '2025550100', 'first_name' => 'Recipient' ) );
	$assert( 200 === $response->get_status() && 'valid' === $response->get_data()['status'], 'Native authenticated route validates an exact address.' );
	$assert( 2 === count( $requests ), 'Native OAuth and UPS validator each make one intercepted request.' );
	$assert( 'Basic ' . base64_encode( 'fixture-client:fixture-secret' ) === $requests[0]['args']['headers']['Authorization'], 'Native OAuth uses synthetic configured credentials.' );
	$assert( 'Bearer fixture-only-token' === $requests[1]['args']['headers']['Authorization'], 'Native validator uses native OAuth token.' );
	$assert( ! str_contains( $requests[1]['args']['body'], 'recipient@example.test' ) && ! str_contains( $requests[1]['args']['body'], '2025550100' ) && ! str_contains( $requests[1]['args']['body'], 'Recipient' ), 'UPS receives postal fields only.' );
	$assert( 'private, no-store' === $response->get_headers()['Cache-Control'], 'REST address results cannot be publicly cached.' );
	$response = $read( $address );
	$assert( 'valid' === $response->get_data()['status'] && 2 === count( $requests ), 'Repeated address uses the native UPS cache without another call.' );
	$mode = 'suggested'; $clear( $address ); $data = $read( $address )->get_data();
	$assert( 'suggested' === $data['status'] && '123 FIXTURE LN' === $data['candidates'][0]['address_1'] && '20175-1234' === $data['candidates'][0]['postcode'], 'Native UPS correction preserves ZIP+4 for explicit review.' );
	$mode = 'unit'; $unit = $address; $unit['address_2'] = 'Apt 5'; $clear( $unit ); $data = $read( $unit )->get_data();
	$assert( 'suggested' === $data['status'] && 'Apt 5' === $data['candidates'][0]['address_2'], 'A unit omitted by UPS is retained for staff review, never silently erased.' );
	$mode = 'exact'; $pr = $address; $pr['country'] = 'PR'; $pr['state'] = 'PR'; $pr['city'] = 'San Juan'; $pr['postcode'] = '00901'; $clear( $pr ); $data = $read( $pr )->get_data();
	$assert( 'valid' === $data['status'], 'Native Puerto Rico normalization remains valid.' );
	$sent = json_decode( end( $requests )['args']['body'], true )['XAVRequest']['AddressKeyFormat'];
	$assert( 'US' === $sent['CountryCode'] && 'PR' === $sent['PoliticalDivision1'], 'Native validator maps Woo Puerto Rico to the UPS representation.' );
	foreach ( array( 'none' => 'unmatched', 'ambiguous' => 'suggested', 'timeout' => 'unavailable', 'forbidden' => 'unavailable', 'malformed' => 'unavailable' ) as $next => $expected ) {
		$mode = $next; $clear( $address ); $data = $read( $address )->get_data();
		$assert( $expected === $data['status'], 'Native provider outcome remains accurate: ' . $next );
		$assert( ! str_contains( wp_json_encode( $data ), 'fixture-only-token' ), 'Provider details do not leak: ' . $next );
	}
	$before = count( $requests ); $foreign = $address; $foreign['country'] = 'CA'; $foreign['state'] = 'ON'; $foreign['postcode'] = 'M5V 3L9';
	$assert( 'unsupported' === $read( $foreign )->get_data()['status'] && $before === count( $requests ), 'Unsupported countries never call UPS.' );
	$missing = $address; $missing['address_1'] = '';
	$assert( 400 === $read( $missing )->get_status() && $before === count( $requests ), 'Incomplete addresses fail before calling UPS.' );
	$assert( 403 === $read( $address, false )->get_status() && $before === count( $requests ), 'A missing REST nonce cannot invoke UPS.' );
	wp_set_current_user( 0 );
	$assert( 403 === $read( $address )->get_status() && $before === count( $requests ), 'Anonymous users cannot invoke UPS.' );
	$assert( 0 === $new_orders && 0 === $mail, 'Validation creates no Woo order and sends no mail.' );
	$assert( $original_objects === array( WC()->cart, WC()->customer, WC()->session ), 'Validation preserves existing Woo cart/customer/session objects.' );
	echo 'Cart Cat UPS address validation native runtime: ' . $count . " assertions passed; exact UPS 3.9.12 OAuth/validator source; external transport intercepted.\n";
} finally {
	remove_filter( 'woocommerce_shipping_methods', $methods ); remove_filter( 'pre_http_request', $http, PHP_INT_MAX ); remove_action( 'woocommerce_new_order', $order_event, PHP_INT_MAX ); remove_filter( 'pre_wp_mail', $mail_event, PHP_INT_MAX );
	foreach ( array_unique( $cache_keys ) as $key ) { delete_transient( $key ); }
	foreach ( $oauth_cache as $name => $value ) { delete_transient( $name ); if ( false !== $value ) { set_transient( $name, $value, HOUR_IN_SECONDS ); } }
	if ( $zone ) { $zone->delete(); WC_Cache_Helper::get_transient_version( 'shipping', true ); }
	wp_set_current_user( $original_user ); if ( is_int( $user_id ) && $user_id > 0 ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user_id ); }
}
