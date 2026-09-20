<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

/** Presentation for the existing, access-checked Woo order-payment endpoint. */
final class Customer_Page {
	public static function register() {
		add_filter( 'wc_get_template', array( self::class, 'template' ), 30, 5 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 110 );
	}
	public static function authorized( $order ) {
		$key = isset( $_GET['key'] ) && is_scalar( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		return Payments::owns( $order ) && $key && hash_equals( $order->get_order_key(), $key ) && current_user_can( 'pay_for_order', $order->get_id() );
	}
	public static function template( $path, $name, $args, $template_path, $default_path ) {
		if ( 'checkout/form-pay.php' === $name && self::authorized( $args['order'] ?? false ) ) { return CART_CAT_PATH . 'templates/customer-pay.php'; }
		return $path;
	}
	public static function body_class( $classes ) {
		if ( self::authorized( Gateway::current_order() ) ) { $classes[] = 'cart-cat-order-pay'; }
		return $classes;
	}
	public static function assets() {
		if ( ! self::authorized( Gateway::current_order() ) ) { return; }
		wp_enqueue_style( 'cart-cat-customer-pay', CART_CAT_URL . 'assets/css/customer-pay.css', array(), CART_CAT_VERSION );
	}
}
