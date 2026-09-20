<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;
/** Prevent WC_Cart from lazily loading the staff session during a preview. */
final class Cart extends \WC_Cart {
	public function get_cart() { return array_filter( $this->get_cart_contents() ); }
	public function needs_shipping() {
		foreach ( $this->get_cart() as $item ) { if ( $item['data']->needs_shipping() ) { return true; } }
		return false;
	}
	public function show_shipping() { return $this->needs_shipping(); }
}
