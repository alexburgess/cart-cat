<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

/** Present recipient contacts to Shippo's Woo importer without changing purchaser billing. */
final class Shipping_Contact {
	private static $request = null;
	private static $key_id = 0;
	private static $user_id = 0;

	public static function register() {
		add_filter( 'rest_pre_dispatch', array( self::class, 'begin_request' ), 1, 3 );
		add_filter( 'woocommerce_disable_rest_api_access_log', array( self::class, 'capture_api_key' ), PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( self::class, 'rest_order' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'end_request' ), PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_webhook_payload', array( self::class, 'webhook_order' ), PHP_INT_MAX, 4 );
	}

	/** Reset before Woo authenticates the key for this exact REST dispatch. */
	public static function begin_request( $result, $server, $request ) {
		self::$request = self::primary_order_request( $request ) ? $request : null;
		self::$key_id = 0;
		self::$user_id = 0;
		return $result;
	}

	/** Woo calls this only after checking the authenticated API key's permissions. */
	public static function capture_api_key( $disabled, $key_id, $user_id ) {
		self::$key_id = 0;
		self::$user_id = 0;
		if ( self::$request && (int) $user_id > 0 && (int) $user_id === get_current_user_id() && in_array( (int) $key_id, self::configured_ids( 'shippo_api_key_ids' ), true ) ) {
			self::$key_id = (int) $key_id;
			self::$user_id = (int) $user_id;
		}
		return $disabled;
	}

	public static function end_request( $response, $server, $request ) {
		if ( self::$request === $request ) { self::$request = null; self::$key_id = 0; self::$user_id = 0; }
		return $response;
	}

	public static function rest_order( $response, $order, $request ) {
		if ( ! self::$key_id || self::$request !== $request || self::$user_id !== get_current_user_id() || ! self::primary_order_request( $request ) || ! in_array( self::$key_id, self::configured_ids( 'shippo_api_key_ids' ), true ) || ! $response instanceof \WP_REST_Response ) { return $response; }
		if ( preg_match( '#/orders/([1-9][0-9]*)/?\z#', $request->get_route(), $match ) && ( ! $order instanceof \WC_Order || (int) $match[1] !== $order->get_id() ) ) { return $response; }
		$data = $response->get_data();
		if ( ! is_array( $data ) ) { return $response; }
		$response->set_data( self::export_contacts( $data, $order ) );
		return $response;
	}

	/** The webhook ID is passed by Woo, never read from request headers or parameters. */
	public static function webhook_order( $payload, $resource, $resource_id, $webhook_id ) {
		if ( 'order' !== $resource || ! is_array( $payload ) || ! is_numeric( $resource_id ) || (int) $resource_id <= 0 || (int) ( $payload['id'] ?? 0 ) !== (int) $resource_id || ! in_array( (int) $webhook_id, self::configured_ids( 'shippo_webhook_ids' ), true ) ) { return $payload; }
		$webhook = wc_get_webhook( (int) $webhook_id );
		if ( ! $webhook instanceof \WC_Webhook || $webhook->get_id() !== (int) $webhook_id || ! in_array( $webhook->get_topic(), array( 'order.created', 'order.updated' ), true ) ) { return $payload; }
		return self::export_contacts( $payload, wc_get_order( (int) $resource_id ) );
	}

	private static function primary_order_request( $request ) {
		global $wp;
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || ! $request instanceof \WP_REST_Request || ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) || ! preg_match( '#\A/wc/v[1-3]/orders(?:/[1-9][0-9]*)?/?\z#', $request->get_route() ) || ! $wp instanceof \WP ) { return false; }
		// Internal REST calls (including webhook payload builds) must not inherit an outer key.
		$actual_route = $wp->query_vars['rest_route'] ?? $wp->request;
		$actual_route = trim( (string) $actual_route, '/' );
		$prefix = trim( rest_get_url_prefix(), '/' ) . '/';
		if ( 0 === strpos( $actual_route, $prefix ) ) { $actual_route = substr( $actual_route, strlen( $prefix ) ); }
		return $actual_route === trim( $request->get_route(), '/' );
	}

	private static function configured_ids( $method ) {
		try {
			$plugin = function_exists( 'vinoshipper_vole' ) ? vinoshipper_vole() : null;
			$settings = is_object( $plugin ) && is_callable( array( $plugin, 'settings' ) ) ? $plugin->settings() : null;
			if ( ! is_object( $settings ) || ! is_callable( array( $settings, $method ) ) ) { return array(); }
			return array_values( array_unique( array_filter( array_map( 'absint', (array) $settings->$method() ) ) ) );
		} catch ( \Throwable $error ) { return array(); }
	}

	private static function export_contacts( array $data, $order ) {
		if ( ! $order instanceof \WC_Order || '1' !== $order->get_meta( '_cart_cat_order' ) || ! empty( $order->get_meta( '_cart_cat_tax_location' ) ) ) { return $data; }
		if ( isset( $data['id'] ) && (int) $data['id'] !== $order->get_id() ) { return $data; }
		$input = $order->get_meta( '_cart_cat_input' );
		if ( is_array( $input ) && 'pickup' === ( $input['fulfillment']['type'] ?? '' ) ) { return $data; }
		$shipping = $order->get_items( 'shipping' );
		if ( ! $shipping ) { return $data; }
		foreach ( $shipping as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Shipping || in_array( $item->get_method_id(), array( 'pickup_location', 'local_pickup', 'legacy_local_pickup' ), true ) ) { return $data; }
		}
		try {
			$plugin = function_exists( 'vinoshipper_vole' ) ? vinoshipper_vole() : null;
			$guard = is_object( $plugin ) && is_callable( array( $plugin, 'shippo_order_guard' ) ) ? $plugin->shippo_order_guard() : null;
			if ( ! is_object( $guard ) || ! is_callable( array( $guard, 'classify_order' ) ) || 'shippo' !== $guard->classify_order( $order ) ) { return $data; }
		} catch ( \Throwable $error ) { return $data; }
		$email = $order->get_meta( '_shipping_email' );
		$phone = $order->get_shipping_phone();
		$contacts = array();
		if ( is_string( $email ) && is_email( $email ) ) { $contacts['email'] = $email; }
		if ( is_string( $phone ) && preg_match( '/[0-9]/', $phone ) && \WC_Validation::is_phone( $phone ) ) { $contacts['phone'] = $phone; }
		// Cover importers that fall back to billing contacts as well as those that
		// prefer shipping contacts. These response copies never update the Woo order.
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			if ( isset( $data[ $kind ] ) && is_array( $data[ $kind ] ) ) { $data[ $kind ] = array_merge( $data[ $kind ], $contacts ); }
		}
		return $data;
	}
}
