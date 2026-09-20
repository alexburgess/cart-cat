<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;
final class Plugin {
	public static function register() {
		Admin::register();
		Drafts::register();
		Shipping_Contact::register();
		Catalog::register();
		Payments::register();
		Square_Inventory::register();
		Stock_Hold::register();
		Invoice::register();
		Presentation::register();
		Customer_Page::register();
		add_action( 'rest_api_init', array( REST::class, 'register' ) );
		add_action( 'cart_cat_expire_order', array( Orders::class, 'expire' ) );
		add_filter( 'woocommerce_order_get_tax_location', static function ( $location, $order ) {
			if ( '1' !== $order->get_meta( '_cart_cat_order' ) ) { return $location; }
			$pickup = $order->get_meta( '_cart_cat_tax_location' );
			return is_array( $pickup ) && ! empty( $pickup['country'] ) ? array_intersect_key( $pickup, array_flip( array( 'country', 'state', 'postcode', 'city' ) ) ) : $location;
		}, PHP_INT_MAX, 2 );
	}
}
