<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

final class Context {
	private static $active = null;
	public static function current() { return self::$active; }
	public static function run( array $customer, array $input, callable $callback ) {
		global $wp_filter;
		if ( null !== self::$active ) { return new \WP_Error( 'cart_cat_nested_quote', 'A calculation is already in progress.' ); }
		$woo = WC();
		$old = array( $woo->cart, $woo->customer, $woo->session );
		// WC()->shipping() reads this singleton, not a property on WC(). Woo has no setter.
		// Swap the complete instance so package/method caches cannot leak across carts.
		$shipping_instance = new \ReflectionProperty( \WC_Shipping::class, '_instance' );
		$shipping_instance->setAccessible( true );
		$old_shipping = $shipping_instance->getValue();
		$hooks = array();
		foreach ( $wp_filter as $key => $hook ) { $hooks[ $key ] = is_object( $hook ) ? clone $hook : $hook; }
		try {
			self::$active = array( 'customer' => $customer, 'input' => $input, 'pickup_address' => null );
			// Remove existing cart/session callbacks, including persistent cart writers.
			foreach ( $wp_filter as $tag => $hook ) {
				foreach ( $hook->callbacks as $priority => $callbacks ) {
					foreach ( $callbacks as $entry ) {
						$fn = $entry['function'];
						$owner = is_array( $fn ) ? $fn[0] : null;
						$class = is_object( $owner ) ? get_class( $owner ) : (string) ( is_string( $owner ) ? $owner : '' );
						$is_cart = is_object( $owner ) && ( $owner instanceof \WC_Cart || $owner instanceof \WC_Cart_Session );
						$is_pricing = in_array( $tag, array( 'woocommerce_before_calculate_totals', 'woocommerce_product_get_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_variation_get_regular_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_sale_price' ), true ) && ( 0 === strpos( $class, 'AF_C' ) || 'STW_Guest_Member_Pricing' === $class );
						if ( $is_cart || $is_pricing ) { remove_filter( $tag, $fn, $priority ); }
					}
				}
			}
			$woo->session = new Session();
			$woo->customer = new \WC_Customer( (int) $customer['woo_id'] );
			foreach ( array( 'billing', 'shipping' ) as $type ) {
				foreach ( (array) ( $customer[ $type ] ?? array() ) as $field => $value ) {
					$setter = 'set_' . $type . '_' . $field;
					if ( is_callable( array( $woo->customer, $setter ) ) ) { $woo->customer->$setter( $value ); }
				}
			}
			$woo->customer->set_calculated_shipping( true );
			$shipping_instance->setValue( null, new \WC_Shipping() );
			add_filter( 'woocommerce_cart_session_initialize', '__return_false', PHP_INT_MAX );
			$woo->cart = new Cart();
			foreach ( array( 'woocommerce_add_to_cart', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored' ) as $tag ) {
				remove_action( $tag, array( $woo->cart, 'calculate_totals' ), 20 );
			}
			add_filter( 'woocommerce_cart_ready_to_calc_shipping', '__return_true', PHP_INT_MAX );
			add_filter( 'quantity_crab_customer_roles', static function () use ( $customer ) { return $customer['roles']; }, PHP_INT_MAX );
			add_filter( 'quantity_crab_customer_is_guest', static function () use ( $customer ) { return $customer['is_guest']; }, PHP_INT_MAX );
			add_filter( 'quantity_crab_role_price_baseline', static function ( $price, $catalog, $item ) { return $item['_cart_cat_role_unit'] ?? $catalog; }, PHP_INT_MAX, 3 );
			add_filter( 'woocommerce_customer_taxable_address', static function ( $address ) {
				$p = self::$active['pickup_address'];
				return $p ? array( $p['country'], $p['state'], $p['postcode'], $p['city'] ) : $address;
			}, PHP_INT_MAX );
			// Pricing is reconstructed each pass; Quantity Crab then applies its normal tiers.
			add_action( 'woocommerce_before_calculate_totals', array( self::class, 'price_lines' ), 1001 );
			return $callback( $woo->cart, $customer );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'cart_cat_calculation_failed', 'The order could not be calculated. Check the selected products and try again.', array( 'status' => 422 ) );
		} finally {
			list( $woo->cart, $woo->customer, $woo->session ) = $old;
			$shipping_instance->setValue( null, $old_shipping );
			$wp_filter = $hooks;
			self::$active = null;
		}
	}
	public static function pickup( array $address ) { self::$active['pickup_address'] = $address; }
	public static function price_lines( $cart ) {
		if ( ! self::$active || $cart !== WC()->cart ) { return; }
		$customer = self::$active['customer'];
		$replace = 'replace' === ( self::$active['input']['discount']['mode'] ?? 'add' );
		$engine = class_exists( 'AF_C_S_P_Price' ) ? new Role_Pricing() : null;
		foreach ( $cart->cart_contents as $key => &$line ) {
			$source = wc_get_product( $line['variation_id'] ?: $line['product_id'] );
			$base = (float) $source->get_price( 'edit' );
			$price = $base;
			if ( $engine ) { $engine->role_discount_names = array(); }
			if ( $engine && ! $replace ) {
				$user = ! empty( $customer['woo_id'] ) ? get_user_by( 'id', $customer['woo_id'] ) : false;
				if ( $user ) {
					$user = clone $user;
					$user->roles = $customer['roles'];
					$price = $engine->get_price_of_product( $source, $user, $customer['roles'][0] ?? 'customer', $line['quantity'] );
				} else {
					// Call the role method directly: get_price_of_product(false,'guest') falls back to STAFF.
					$price = false;
					foreach ( $customer['roles'] ?: array( 'guest' ) as $role ) {
						$price = $engine->get_role_price_of_product( $source, $role, $line['quantity'] );
						if ( false !== $price ) { break; }
					}
				}
				$price = false !== $price && is_numeric( $price ) ? (float) $price : $base;
			}
			$line['_cart_cat_role_unit'] = $price;
			$line['_cart_cat_role_discount_names'] = $engine && $price < $base ? $engine->role_discount_names : array();
			$addon_base = $addon_price = 0.0;
			foreach ( (array) ( $line['addons'] ?? array() ) as $addon ) {
				$amount = (float) ( $addon['price'] ?? 0 );
				$type = $addon['price_type'] ?? 'quantity_based';
				$addon_base += 'percentage_based' === $type ? $base * $amount / 100 : ( 'flat_fee' === $type ? $amount / $line['quantity'] : $amount );
				$addon_price += 'percentage_based' === $type ? $price * $amount / 100 : ( 'flat_fee' === $type ? $amount / $line['quantity'] : $amount );
			}
			$line['_cart_cat_catalog_unit'] = $base + $addon_base;
			// Quantity Crab preserves the upstream modifier price when its case
			// tier wins. Any role savings on a percentage add-on still belong to Addify.
			$line['_cart_cat_role_addon_savings_unit'] = max( 0.0, $addon_base - $addon_price );
			$line['data']->set_price( $price + $addon_price );
		}
		unset( $line );
	}
}
