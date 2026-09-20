<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

final class REST {
	public static function register() {
		$routes = array(
			array( '/drafts', 'GET', static function ( $r ) { return Drafts::search( (string) $r->get_param( 'q' ), (int) $r->get_param( 'page' ) ); } ),
			array( '/drafts', 'POST', static function ( $r ) { return Drafts::save( 0, (array) $r->get_json_params() ); } ),
			array( '/drafts/(?P<id>\d+)', 'GET', static function ( $r ) { return Drafts::get( (int) $r['id'] ); } ),
			array( '/drafts/(?P<id>\d+)', 'POST', static function ( $r ) { return Drafts::save( (int) $r['id'], (array) $r->get_json_params() ); } ),
			array( '/drafts/(?P<id>\d+)/delete', 'POST', static function ( $r ) { return Drafts::delete( (int) $r['id'], (string) $r->get_param( 'version' ) ); } ),
			array( '/drafts/(?P<id>\d+)/prepare', 'POST', static function ( $r ) { return Drafts::prepare( (int) $r['id'], (array) $r->get_json_params() ); } ),
			array( '/customers', 'GET', static function ( $r ) { return Customers::search( (string) $r->get_param( 'q' ) ); } ),
			array( '/customers/details', 'GET', static function ( $r ) { return Customers::details( (string) $r->get_param( 'reference' ) ); } ),
			array( '/products', 'GET', static function ( $r ) { return Catalog::search( (string) $r->get_param( 'q' ) ); } ),
			array( '/products/index', 'GET', static function ( $r ) { return Catalog::index( (int) $r->get_param( 'page' ), (string) $r->get_param( 'revision' ), '1' === (string) $r->get_param( 'check' ) ); } ),
			array( '/products/(?P<id>\d+)', 'GET', static function ( $r ) { return Catalog::configuration( (int) $r['id'] ); } ),
			array( '/quote', 'POST', static function ( $r ) { return Quote::calculate( (array) $r->get_json_params() ); } ),
			array( '/shipping/validate-address', 'POST', static function ( $r ) { $input = $r->get_json_params(); return Address_Validation::validate( is_array( $input ) ? ( $input['address'] ?? null ) : null ); } ),
			array( '/orders', 'POST', static function ( $r ) { return Orders::create( (array) $r->get_param( 'input' ), (string) $r->get_param( 'quote_hash' ), (string) $r->get_param( 'request_id' ) ); } ),
			array( '/orders/(?P<id>\d+)', 'GET', static function ( $r ) { return Orders::get( (int) $r['id'] ); } ),
			array( '/orders/(?P<id>\d+)/edit', 'POST', static function ( $r ) { return Orders::edit( (int) $r['id'], (string) $r->get_param( 'version' ) ); } ),
			array( '/orders/(?P<id>\d+)/prepare', 'POST', static function ( $r ) { return Orders::prepare( (int) $r['id'], (array) $r->get_param( 'input' ), (string) $r->get_param( 'quote_hash' ), (string) $r->get_param( 'version' ), (string) $r->get_param( 'request_id' ) ); } ),
			array( '/orders/(?P<id>\d+)/email', 'POST', static function ( $r ) { return Orders::email( (int) $r['id'], (array) $r->get_json_params() ); } ),
			array( '/orders/(?P<id>\d+)/cards', 'GET', static function ( $r ) { return Payments::cards( (int) $r['id'] ); } ),
			array( '/orders/(?P<id>\d+)/pay', 'POST', static function ( $r ) { return Payments::pay( (int) $r['id'], (array) $r->get_json_params(), 'staff' ); } ),
			array( '/orders/(?P<id>\d+)/payment', 'GET', static function ( $r ) { return Payments::status( (int) $r['id'] ); } ),
			array( '/orders/(?P<id>\d+)/complete', 'POST', static function ( $r ) { return Orders::complete_free( (int) $r['id'] ); } ),
		);
		foreach ( $routes as list( $path, $methods, $callback ) ) {
			register_rest_route( 'cart-cat/v1', $path, array( 'methods' => $methods, 'permission_callback' => array( self::class, 'permission' ), 'callback' => static function ( $request ) use ( $callback ) {
				$result = $callback( $request );
				if ( is_wp_error( $result ) ) { return $result; }
				$response = new \WP_REST_Response( $result );
				$response->header( 'Cache-Control', 'private, no-store' );
				return $response;
			} ) );
		}
	}
	public static function permission( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new \WP_Error( 'cart_cat_forbidden', 'WooCommerce management permission is required.', array( 'status' => 403 ) ); }
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) { return new \WP_Error( 'cart_cat_nonce', 'Your session expired. Reload Cart Cat.', array( 'status' => 403 ) ); }
		return true;
	}
}
