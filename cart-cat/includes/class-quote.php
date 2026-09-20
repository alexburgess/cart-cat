<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

final class Quote {
	public static function calculate( array $input, bool $fresh = false, bool $retain = false ) {
		return self::with_quote( $input, $fresh, static function ( $quote ) use ( $retain ) {
			if ( ! $retain ) { foreach ( array_keys( $quote ) as $key ) { if ( '_' === substr( $key, 0, 1 ) ) { unset( $quote[ $key ] ); } } }
			return $quote;
		} );
	}
	public static function with_quote( array $input, bool $fresh, callable $callback ) {
		$notes = array_key_exists( 'order_notes', $input ) ? $input['order_notes'] : '';
		if ( ! is_string( $notes ) || strlen( $notes ) > 8000 ) { return self::error( 'order_notes', 'Enter an order note of 2,000 characters or fewer.' ); }
		$notes = sanitize_textarea_field( str_replace( array( "\r\n", "\r" ), "\n", $notes ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $notes, 'UTF-8' ) : preg_match_all( '/./us', $notes );
		if ( false === $length || $length > 2000 ) { return self::error( 'order_notes', 'Enter an order note of 2,000 characters or fewer.' ); }
		$input['order_notes'] = $notes;
		$visible = array_key_exists( 'order_notes_visible', $input ) ? $input['order_notes_visible'] : false;
		if ( ! is_bool( $visible ) ) { return self::error( 'order_notes_visible', 'Choose whether the order note is visible to the customer.' ); }
		$input['order_notes_visible'] = $visible;
		$minutes = Stock_Hold::normalize( array_key_exists( 'hold_minutes', $input ) ? $input['hold_minutes'] : Stock_Hold::config()['default_minutes'] );
		if ( is_wp_error( $minutes ) ) { return $minutes; }
		$input['hold_minutes'] = $minutes;
		if ( empty( $input['lines'] ) || ! is_array( $input['lines'] ) || count( $input['lines'] ) > 100 ) { return self::error( 'products', 'Add between 1 and 100 product lines.' ); }
		$discount = (array) ( $input['discount'] ?? array() );
		$amount = $discount['amount'] ?? 0;
		if ( '' === $amount ) { $amount = 0; }
		if ( ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount < 0 || ! in_array( $discount['type'] ?? 'percent', array( 'percent', 'fixed' ), true ) || ! in_array( $discount['mode'] ?? 'add', array( 'add', 'replace' ), true ) ) { return self::error( 'discount', 'Enter a valid discount.' ); }
		$input['discount'] = array( 'type' => $discount['type'] ?? 'percent', 'amount' => wc_format_decimal( $amount ), 'mode' => $discount['mode'] ?? 'add' );
		if ( 'percent' === $input['discount']['type'] && (float) $amount > 100 ) { return self::error( 'discount', 'A percentage discount cannot exceed 100%.' ); }
		$type = $input['fulfillment']['type'] ?? 'pickup';
		if ( ! in_array( $type, array( 'pickup', 'shipping' ), true ) ) { return self::error( 'fulfillment', 'Choose pickup or shipping.' ); }
		$input['fulfillment']['type'] = $type;
		if ( class_exists( 'Quantity_Crab_Discounts' ) && ! defined( 'Quantity_Crab_Discounts::CUSTOMER_CONTEXT_VERSION' ) ) { return self::error( 'quantity_compatibility', 'Quantity Crab needs the Cart Cat compatibility update before orders can be calculated.' ); }
		$customer = Customers::resolve( (array) ( $input['customer'] ?? array() ), $fresh );
		if ( is_wp_error( $customer ) ) { return $customer; }
		return Context::run( $customer, $input, static function ( $cart ) use ( $input, $customer, $callback ) {
			foreach ( $input['lines'] as $line ) {
				if ( ! is_array( $line ) ) { return self::error( 'product', 'Invalid product selection.' ); }
				$result = Catalog::add_to_cart( $cart, $line );
				if ( is_wp_error( $result ) ) { return $result; }
			}
			$quote = self::build( $cart, $customer, $input );
			return is_wp_error( $quote ) ? $quote : $callback( $quote );
		} );
	}
	private static function build( $cart, array $customer, array $input ) {
		$notices = array();
		$pickup = 'pickup' === $input['fulfillment']['type'];
		$needs_shipping = $cart->needs_shipping();
		$chosen = (array) ( $input['fulfillment']['rates'] ?? array() );
		$pickup_id = '';
		if ( $pickup ) {
			$locations = (array) get_option( 'pickup_location_pickup_locations', array() );
			$configured_pickup = (string) get_option( 'cart_cat_pickup_rate_id', 'pickup_location:0' );
			foreach ( $locations as $index => $location ) {
				if ( 'pickup_location:' . $index !== $configured_pickup ) { continue; }
				if ( empty( $location['enabled'] ) ) { continue; }
				$address = (array) ( $location['address'] ?? array() );
				if ( 'US' !== ( $address['country'] ?? '' ) || 'VA' !== ( $address['state'] ?? '' ) ) { continue; }
				$pickup_id = 'pickup_location:' . $index;
				Context::pickup( array_merge( array( 'postcode' => '', 'city' => '' ), $address ) );
				break;
			}
			if ( ! $pickup_id ) { return self::error( 'pickup', 'The winery pickup location is unavailable. Check WooCommerce pickup settings.' ); }
			$chosen = array( $pickup_id );
			add_filter( 'woocommerce_shipping_zone_shipping_methods', '__return_empty_array', PHP_INT_MAX );
			add_filter( 'woocommerce_shipping_methods', static function () { return array( 'pickup_location' => '\\Automattic\\WooCommerce\\Blocks\\Shipping\\PickupLocation' ); }, PHP_INT_MAX );
		} elseif ( $needs_shipping ) {
			$address_error = self::validate_address( $customer['shipping'] ?? array() );
			if ( $address_error ) { $notices[] = $address_error; }
			if ( self::vinoshipper_required( $cart, $customer['shipping'] ?? array() ) ) { return self::error( 'vinoshipper', 'This shipment requires Vinoshipper verification. Complete it through the existing website checkout.', array( 'checkout_url' => wc_get_checkout_url() ) ); }
		}
		WC()->session->set( 'chosen_shipping_methods', $chosen );
		// Keep eligible provider rates and all existing package eligibility filters.
		add_filter( 'woocommerce_package_rates', static function ( $rates ) use ( $pickup, $pickup_id, $notices ) {
			foreach ( $rates as $key => $rate ) {
				$is_pickup = in_array( $rate->get_method_id(), array( 'pickup_location', 'local_pickup', 'legacy_local_pickup' ), true );
				if ( ( $pickup && $key !== $pickup_id ) || ( ! $pickup && ( $is_pickup || 'vinoshipper' === $rate->get_method_id() || $notices ) ) ) { unset( $rates[ $key ] ); }
			}
			return $rates;
		}, PHP_INT_MAX );
		$discount = $input['discount'];
		if ( (float) $discount['amount'] > 0 ) {
			add_filter( 'woocommerce_get_shop_coupon_data', static function ( $data, $code ) use ( $discount ) {
				return 'cart-cat-staff-discount' === $code ? array( 'id' => 0, 'code' => $code, 'discount_type' => 'percent' === $discount['type'] ? 'percent' : 'fixed_cart', 'amount' => $discount['amount'], 'individual_use' => false, 'virtual' => true ) : $data;
			}, 10, 2 );
			$cart->set_applied_coupons( array( 'cart-cat-staff-discount' ) );
		}
		$packing_problems = array();
		add_action( 'woocommerce_after_get_rates_for_package', static function ( $package, $method ) use ( &$packing_problems ) {
			if ( 'ups' !== $method->id || ! empty( $method->rates ) ) { return; }
			$fields = 'box_packing' === $method->get_option( 'packing_method', 'per_item' ) ? array( 'weight', 'length', 'width', 'height' ) : array( 'weight' );
			foreach ( $package['contents'] as $item ) {
				$product = $item['data']; if ( ! $product->needs_shipping() ) { continue; }
				$missing = array();
				foreach ( $fields as $field ) { $getter = 'get_' . $field; if ( ! (float) $product->$getter() ) { $missing[] = $field; } }
				if ( $missing ) { $packing_problems[] = sprintf( 'UPS cannot quote %s because its shipping %s are missing. Complete these fields in the product’s Shipping settings.', wp_strip_all_tags( $product->get_name() ), implode( ', ', $missing ) ); }
			}
		}, PHP_INT_MAX, 2 );
		$cart->calculate_totals();
		if ( 'fixed' === $discount['type'] && (float) $discount['amount'] > (float) $cart->get_subtotal() + 0.000001 ) { return self::error( 'discount', 'The discount cannot exceed the product subtotal.' ); }
		$rates = array();
		$packages = WC()->shipping()->get_packages();
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$shipping_available = ! $needs_shipping || (bool) $packages;
		foreach ( $packages as $index => $package ) {
			$options = array();
			foreach ( $package['rates'] as $id => $rate ) {
				$options[] = array( 'id' => $id, 'label' => wp_strip_all_tags( $rate->get_label() ), 'cost' => self::money( $rate->get_cost() ), 'selected' => ( $chosen[ $index ] ?? '' ) === $id );
			}
			if ( ! $options ) { $notices = array_merge( $notices, $packing_problems ?: array( 'No eligible shipping service is available for this address and order.' ) ); }
			if ( ! isset( $package['rates'][ $chosen[ $index ] ?? '' ] ) ) { $shipping_available = false; }
			if ( isset( $input['fulfillment']['rates'][ $index ] ) && ! isset( $package['rates'][ $input['fulfillment']['rates'][ $index ] ] ) ) { $notices[] = 'The selected shipping service is no longer available. Choose an available service.'; }
			$rates[] = array( 'package_index' => $index, 'rates' => $options );
		}
		if ( $needs_shipping && ! $packages ) { $notices[] = 'Shipping could not be calculated. Please try again.'; }
		// Contact completeness blocks preparation without hiding otherwise valid delivery rates.
		if ( ! $pickup && $needs_shipping ) {
			$notices = array_merge( $notices, self::validate_shipping_contact( $customer['shipping'] ?? array() ) );
		}
		$hold = self::heat_hold( $input );
		if ( $hold['required'] && ! in_array( $hold['choice'], array( 'hold', 'ship' ), true ) ) { $notices[] = 'Choose Heat Hold or Ship ASAP before preparing the order.'; }
		$lines = array(); $catalog_total = 0; $automatic = 0; $automatic_discounts = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$catalog = wc_get_price_excluding_tax( $item['data'], array( 'qty' => $item['quantity'], 'price' => $item['_cart_cat_catalog_unit'] ?? $item['data']->get_price( 'edit' ) ) );
			$catalog = max( $catalog, $item['line_subtotal'] );
			$savings = max( 0, $catalog - $item['line_subtotal'] );
			$catalog_total += $catalog; $automatic += $savings;
			self::collect_automatic_discounts( $automatic_discounts, $item, $savings );
			$meta = apply_filters( 'woocommerce_get_item_data', array(), $item );
			$lines[] = array( 'key' => $key, 'name' => $item['data']->get_name(), 'quantity' => $item['quantity'], 'subtotal' => self::money( $catalog ), 'total' => self::money( $item['line_total'] ), 'tax' => self::money( $item['line_tax'] ), 'automatic_savings' => self::money( $savings ), 'meta' => array_map( static function ( $m ) { return array( 'key' => wp_strip_all_tags( $m['key'] ?? $m['name'] ?? '' ), 'value' => wp_strip_all_tags( $m['display'] ?? $m['value'] ?? '' ) ); }, $meta ) );
		}
		$quote = array( 'currency' => get_woocommerce_currency(), 'decimals' => wc_get_price_decimals(), 'lines' => $lines, 'totals' => array( 'subtotal' => self::money( $catalog_total ), 'automatic_savings' => self::money( $automatic ), 'discount' => self::money( $cart->get_discount_total() ), 'shipping' => self::money( $cart->get_shipping_total() ), 'fees' => self::money( $cart->get_fee_total() ), 'tax' => self::money( $cart->get_total_tax() ), 'total' => self::money( $cart->get_total( 'edit' ) ) ), 'rates' => $rates, 'heat_hold' => $hold, 'customer' => array( 'name' => trim( ( $customer['first_name'] ?? '' ) . ' ' . ( $customer['last_name'] ?? '' ) ), 'roles' => $customer['roles'], 'sources' => $customer['sources'] ?? array() ), 'can_submit' => ! $notices, 'notices' => array_values( array_unique( $notices ) ) );
		$square_scope = Square::scope();
		$quote['automatic_discounts'] = self::format_automatic_discounts( $automatic_discounts, $automatic );
		$quote['hold_minutes'] = $input['hold_minutes'];
		$quote['shipping_status'] = ! $needs_shipping ? 'not_required' : ( $shipping_available ? 'available' : 'unavailable' );
		$quote['hash'] = hash_hmac( 'sha256', wp_json_encode( array( $input, $quote, $customer['woo_id'], $customer['roles'], $customer['square_id'] ?? '', $square_scope ) ), wp_salt( 'auth' ) );
		$quote['_square_scope'] = $square_scope;
		$quote['_cart'] = $cart; $quote['_customer'] = $customer; $quote['_input'] = $input; $quote['_packages'] = $packages; $quote['_chosen'] = $chosen;
		return $quote;
	}
	/** Attribute actual pre-tax savings to the native winning price rule. */
	private static function collect_automatic_discounts( array &$discounts, array $item, float $savings ): void {
		if ( $savings <= 0 ) { return; }
		$quantity = (array) ( $item['_quantity_crab_discount'] ?? array() );
		$case_applied = ! empty( $quantity['applied'] );
		// A stronger case price replaces the product's role price. Percentage
		// add-on savings remain attributable to role pricing because Crab preserves them.
		$role_savings = $case_applied ? min( $savings, wc_get_price_excluding_tax( $item['data'], array( 'qty' => $item['quantity'], 'price' => $item['_cart_cat_role_addon_savings_unit'] ?? 0 ) ) ) : $savings;
		foreach ( array(
			array( 'role', (array) ( $item['_cart_cat_role_discount_names'] ?? array() ), $role_savings, 'Automatic customer pricing' ),
			array( 'quantity', (array) ( $quantity['rules'] ?? array() ), $savings - $role_savings, 'Quantity discount' ),
		) as list( $kind, $names, $amount, $fallback ) ) {
			if ( $amount <= 0 ) { continue; }
			$names = array_values( array_unique( array_filter( array_map( static function ( $name ) { return wp_strip_all_tags( (string) $name ); }, $names ) ) ) );
			$label = $names ? implode( ', ', $names ) : $fallback;
			$key = $kind . ':' . $label;
			if ( ! isset( $discounts[ $key ] ) ) { $discounts[ $key ] = array( 'kind' => $kind, 'label' => $label, 'amount' => 0.0 ); }
			$discounts[ $key ]['amount'] += $amount;
		}
	}
	/** Allocate display cents so named savings always sum to Woo's rounded total. */
	private static function format_automatic_discounts( array $discounts, float $total ): array {
		$scale = 10 ** wc_get_price_decimals(); $allocated = 0; $remainders = array();
		foreach ( $discounts as $key => &$discount ) {
			$minor = $discount['amount'] * $scale;
			$discount['minor'] = (int) floor( $minor + 0.00000001 );
			$allocated += $discount['minor']; $remainders[ $key ] = $minor - $discount['minor'];
		}
		unset( $discount );
		arsort( $remainders, SORT_NUMERIC );
		$remaining = max( 0, (int) round( $total * $scale ) - $allocated );
		foreach ( array_keys( $remainders ) as $key ) {
			if ( $remaining <= 0 ) { break; }
			++$discounts[ $key ]['minor']; --$remaining;
		}
		$result = array();
		foreach ( $discounts as $discount ) {
			if ( $discount['minor'] > 0 ) { $result[] = array( 'kind' => $discount['kind'], 'label' => $discount['label'], 'amount' => self::money( $discount['minor'] / $scale ) ); }
		}
		return $result;
	}
	public static function vinoshipper_required( $cart, array $address ) {
		if ( ! class_exists( '\\VinoshipperVole\\Settings' ) ) { return false; }
		$settings = new \VinoshipperVole\Settings();
		if ( ! $settings->enabled() || 'US' !== ( $address['country'] ?? '' ) || ! in_array( $address['state'] ?? '', $settings->states(), true ) ) { return false; }
		$category = (int) $settings->get( 'wine_category_id' );
		$children = get_term_children( $category, 'product_cat' );
		$ids = array_merge( array( $category ), is_wp_error( $children ) ? array() : $children );
		foreach ( $cart->get_cart() as $item ) { if ( has_term( $ids, 'product_cat', $item['product_id'] ) ) { return true; } }
		return false;
	}
	private static function heat_hold( array $input ) {
		$result = array( 'required' => false, 'choices' => array(), 'choice' => $input['fulfillment']['heat_hold_choice'] ?? '' );
		if ( ! class_exists( 'Heat_Hold_Horse_Checkout' ) || 'shipping' !== $input['fulfillment']['type'] ) { return $result; }
		$settings = \Heat_Hold_Horse_Plugin::instance()->get_settings();
		$checkout = new \Heat_Hold_Horse_Checkout( new \Heat_Hold_Horse_Schedules(), static function () use ( $settings ) { return $settings; } );
		$context = $checkout->get_cart_extension_data();
		$result['required'] = ! empty( $context['required'] );
		foreach ( array( 'hold', 'ship' ) as $choice ) { $result['choices'][] = array( 'value' => $choice, 'label' => $settings[ $choice . '_label' ], 'description' => $settings[ $choice . '_description' ] ); }
		return $result;
	}
	private static function validate_shipping_contact( array $address ) {
		$notices = array();
		if ( ! is_email( $address['email'] ?? '' ) ) { $notices[] = 'Enter a valid shipping recipient email address.'; }
		$phone = $address['phone'] ?? '';
		if ( ! preg_match( '/[0-9]/', $phone ) || ! \WC_Validation::is_phone( $phone ) ) { $notices[] = 'Enter a valid shipping recipient phone number.'; }
		return $notices;
	}
	public static function validate_address( array $address ) {
		foreach ( array( 'country', 'address_1', 'city', 'postcode' ) as $key ) { if ( empty( $address[ $key ] ) ) { return 'Enter the complete shipping address to calculate delivery.'; } }
		$countries = WC()->countries->get_shipping_countries();
		if ( ! isset( $countries[ $address['country'] ] ) ) { return 'Shipping is not available to this country.'; }
		$states = WC()->countries->get_states( $address['country'] );
		if ( is_array( $states ) && $states && ! isset( $states[ $address['state'] ?? '' ] ) ) { return 'Select a valid shipping state.'; }
		if ( ! \WC_Validation::is_postcode( $address['postcode'], $address['country'] ) ) { return 'Enter a valid shipping postcode.'; }
		return '';
	}
	private static function money( $amount ) { return wc_format_decimal( $amount, wc_get_price_decimals() ); }
	private static function error( $code, $message, array $extra = array() ) { return new \WP_Error( 'cart_cat_' . $code, $message, array_merge( array( 'status' => 422 ), $extra ) ); }
}
