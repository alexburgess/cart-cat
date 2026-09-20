<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

/** Observe Addify's winning role rule without duplicating its pricing decisions. */
final class Role_Pricing extends \AF_C_S_P_Price {
	public $role_discount_names = array();
	public function get_role_price_of_product( $product, $user_role, $cart_qty ) {
		$candidate = null;
		$watch = static function ( $value, $object_id, $meta_key ) use ( &$candidate, $product, $user_role ) {
			if ( 'rrole_base_price_' . $user_role === $meta_key ) { $candidate = array( 'rule' => (int) $object_id, 'role' => $user_role ); }
			elseif ( '_role_base_price_' . $user_role === $meta_key && $product instanceof \WC_Product && $product->get_id() === (int) $object_id ) { $candidate = array( 'rule' => 0, 'role' => $user_role ); }
			return $value;
		};
		add_filter( 'get_post_metadata', $watch, PHP_INT_MAX, 3 );
		try { $price = parent::get_role_price_of_product( $product, $user_role, $cart_qty ); }
		finally { remove_filter( 'get_post_metadata', $watch, PHP_INT_MAX ); }
		if ( false !== $price && is_numeric( $price ) && $candidate ) {
			$roles = wp_roles()->roles;
			$name = $candidate['rule'] ? get_the_title( $candidate['rule'] ) : ( $roles[ $candidate['role'] ]['name'] ?? $candidate['role'] );
			$this->role_discount_names = array( wp_strip_all_tags( (string) $name ) );
		}
		return $price;
	}
}
