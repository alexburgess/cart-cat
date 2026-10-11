<?php
/**
 * Plugin Name: Cart Cat
 * Description: Prepare customer orders with WooCommerce pricing, shipping and Square payments.
 * Version: 0.1.14
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: cart-cat
 */
defined( 'ABSPATH' ) || exit;
define( 'CART_CAT_VERSION', '0.1.14' );
define( 'CART_CAT_PATH', plugin_dir_path( __FILE__ ) );
define( 'CART_CAT_URL', plugin_dir_url( __FILE__ ) );
spl_autoload_register( static function ( $class ) {
	if ( 0 !== strpos( $class, 'Cart_Cat\\' ) ) { return; }
	$name = substr( $class, 9 );
	if ( ! preg_match( '/^[A-Za-z_]+$/', $name ) ) { return; }
	$file = CART_CAT_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
	if ( is_file( $file ) ) { require_once $file; }
} );
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WooCommerce' ) ) { return; }
	Cart_Cat\Plugin::register();
}, 30 );
