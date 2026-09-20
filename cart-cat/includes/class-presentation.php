<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

/** Customer-facing labels only; the saved shipping and payment data stays authoritative. */
final class Presentation {
	private static $invoice_stack = array();
	public static function register() {
		add_filter( 'woocommerce_get_order_item_totals', array( self::class, 'totals' ), 30, 3 );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'email_pickup' ), 30, 4 );
		add_filter( 'woocommerce_email_order_items_args', array( self::class, 'email_images' ), 30 );
		add_action( 'woocommerce_email_order_details', array( self::class, 'invoice_begin' ), -100, 4 );
		add_action( 'woocommerce_email_order_details', array( self::class, 'invoice_end' ), PHP_INT_MAX );
		add_filter( 'woocommerce_order_formatted_line_subtotal', array( self::class, 'invoice_line_price' ), 30, 3 );
	}
	public static function is_pickup( $order ) {
		if ( ! $order instanceof \WC_Order ) { return false; }
		$shipping = $order->get_items( 'shipping' );
		if ( ! $shipping ) { return false; }
		foreach ( $shipping as $item ) {
			if ( ! in_array( $item->get_method_id(), array( 'pickup_location', 'local_pickup', 'legacy_local_pickup' ), true ) ) { return false; }
		}
		return true;
	}
	public static function pickup_locations( $order ) {
		if ( ! self::is_pickup( $order ) ) { return array(); }
		$locations = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$name = self::text( $item->get_meta( 'pickup_location' ) );
			$address = self::text( $item->get_meta( 'pickup_address' ) );
			if ( '' === $name ) { $name = self::text( $item->get_name() ); }
			if ( '' === $address ) {
				$structured = $item->get_meta( '_pickup_location_address' );
				if ( is_array( $structured ) && $structured ) { $address = self::text( WC()->countries->get_formatted_address( $structured, ', ' ) ); }
			}
			$location = array( 'name' => $name, 'address' => $address, 'details' => self::text( $item->get_meta( 'pickup_details' ) ) );
			if ( ! in_array( $location, $locations, true ) ) { $locations[] = $location; }
		}
		return $locations;
	}
	public static function totals( $totals, $order, $tax_display ) {
		if ( ! is_array( $totals ) || ! Payments::owns( $order ) ) { return $totals; }
		unset( $totals['payment_method'] );
		if ( self::is_pickup( $order ) && isset( $totals['shipping'] ) ) {
			$amount = (float) $order->get_shipping_total();
			$tax_display = $tax_display ?: get_option( 'woocommerce_tax_display_cart', 'excl' );
			if ( 'incl' === $tax_display ) { $amount += (float) $order->get_shipping_tax(); }
			$totals['shipping']['label'] = 'Pickup:';
			$totals['shipping']['value'] = 0.0 === $amount ? 'Complimentary' : wc_price( $amount, array( 'currency' => $order->get_currency() ) );
			unset( $totals['shipping']['meta'] );
		}
		return null !== self::invoice_frame( $order ) ? self::customer_pricing( $order, $tax_display, $totals )['totals'] : $totals;
	}
	/** Received native totals make invoice filters recursion-safe; an empty array requests line prices only. */
	public static function customer_pricing( $order, $tax_display = '', $native_totals = null ) {
		$tax_display = $tax_display ?: get_option( 'woocommerce_tax_display_cart', 'excl' );
		$inclusive = 'incl' === $tax_display;
		$review = self::matching_review( $order );
		// Older orders may lack historical rate percentages. Keep native prices if tax is unprovable.
		if ( $inclusive && $review ) {
			foreach ( array_values( $order->get_items() ) as $index => $item ) {
				$original = (float) $review['lines'][ $index ]['subtotal'];
				if ( $original > (float) $item->get_subtotal() && null === self::original_tax( $order, $item, $original ) ) { $review = array(); break; }
			}
		}
		$lines = array(); $original_subtotal = 0.0; $prepared_subtotal = 0.0; $original_tax = 0.0; $prepared_tax = 0.0; $index = 0;
		$round_at_subtotal = 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' );
		foreach ( $order->get_items() as $item_id => $item ) {
			$subtotal = (float) $item->get_subtotal();
			$original = $review ? max( $subtotal, (float) $review['lines'][ $index ]['subtotal'] ) : $subtotal;
			$tax = (float) $item->get_subtotal_tax();
			$catalog_tax = $original > $subtotal ? self::original_tax( $order, $item, $original ) : $tax;
			$original_subtotal += $original; $prepared_subtotal += $subtotal;
			$original_tax += $round_at_subtotal ? (float) $catalog_tax : wc_round_tax_total( (float) $catalog_tax );
			$prepared_tax += $round_at_subtotal ? $tax : wc_round_tax_total( $tax );
			$before = $original + ( $inclusive ? (float) $catalog_tax : 0 );
			$after = (float) $item->get_total() + ( $inclusive ? (float) $item->get_total_tax() : 0 );
			$lines[ $item_id ] = array( 'original' => $before, 'total' => $after, 'discounted' => self::money( $before ) > self::money( $after ), 'original_html' => wc_price( $before, array( 'currency' => $order->get_currency() ) ), 'total_html' => wc_price( $after, array( 'currency' => $order->get_currency() ) ) );
			++$index;
		}
		$display_original = self::money( $original_subtotal + ( $inclusive ? wc_round_tax_total( $original_tax ) : 0 ) );
		$display_prepared = self::money( $prepared_subtotal + ( $inclusive ? wc_round_tax_total( $prepared_tax ) : 0 ) );
		$automatic = $display_original - $display_prepared;
		$totals = null === $native_totals ? $order->get_order_item_totals( $tax_display ) : $native_totals;
		if ( self::money( $automatic ) > 0 && isset( $totals['cart_subtotal'] ) ) {
			$totals['cart_subtotal']['value'] = wc_price( $display_original, array( 'currency' => $order->get_currency() ) );
			if ( ! $inclusive && $order->get_prices_include_tax() && wc_tax_enabled() ) { $totals['cart_subtotal']['value'] .= ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>'; }
			$discount = array( 'type' => 'discount', 'label' => self::automatic_label( $order, $review ) . ':', 'value' => '&minus;' . wc_price( $automatic, array( 'currency' => $order->get_currency() ) ) );
			$totals = array_slice( $totals, 0, 1, true ) + array( 'cart_cat_automatic_discount' => $discount ) + array_slice( $totals, 1, null, true );
		}
		if ( isset( $totals['discount'] ) && self::money( $automatic ) > 0 ) { $totals['discount']['label'] = 'Additional discount:'; }
		return array( 'lines' => $lines, 'totals' => $totals );
	}
	/** An inactive nested email masks the outer invoice until its own rendering finishes. */
	public static function invoice_begin( $order, $sent_to_admin, $plain_text, $email ): void {
		self::$invoice_stack[] = array( 'order' => $order, 'plain' => (bool) $plain_text, 'active' => ! $sent_to_admin && Payments::owns( $order ) && $email instanceof \WC_Email && $email->is_customer_email() && 'customer_invoice' === $email->id );
	}
	public static function invoice_end(): void { array_pop( self::$invoice_stack ); }
	private static function invoice_frame( $order ) {
		if ( ! doing_action( 'woocommerce_email_order_details' ) || ! self::$invoice_stack ) { return null; }
		$index = count( self::$invoice_stack ) - 1; $frame = self::$invoice_stack[ $index ];
		if ( ! $frame['active'] || $frame['order'] !== $order ) { return null; }
		return $index;
	}
	public static function invoice_line_price( $subtotal, $item, $order ) {
		$index = self::invoice_frame( $order );
		if ( null === $index ) { return $subtotal; }
		if ( ! isset( self::$invoice_stack[ $index ]['lines'] ) ) { self::$invoice_stack[ $index ]['lines'] = self::customer_pricing( $order, '', array() )['lines']; }
		$price = null;
		foreach ( $order->get_items() as $item_id => $current ) { if ( $current === $item || ( $item->get_id() && $current->get_id() === $item->get_id() ) ) { $price = self::$invoice_stack[ $index ]['lines'][ $item_id ] ?? null; break; } }
		if ( ! $price || ! $price['discounted'] ) { return $subtotal; }
		if ( self::$invoice_stack[ $index ]['plain'] ) {
			return sprintf( 'Originally %s; after discounts %s', self::text( $price['original_html'] ), self::text( $price['total_html'] ) );
		}
		return '<del class="cc-email-original-price" style="display:block;color:#777;font-weight:normal;">' . $price['original_html'] . '</del><span class="cc-email-discounted-price" style="color:#257344;">' . $price['total_html'] . '</span>';
	}
	/** Old reviews preserve cart order but not item IDs: require every frozen line to agree. */
	private static function matching_review( $order ) {
		if ( ! Payments::owns( $order ) ) { return array(); }
		$review = $order->get_meta( '_cart_cat_review' ); $items = array_values( $order->get_items() );
		if ( ! is_array( $review ) || ! isset( $review['lines'], $review['totals']['total'] ) || ! is_array( $review['lines'] ) || count( $review['lines'] ) !== count( $items ) || self::money( $review['totals']['total'] ) !== self::money( $order->get_total() ) ) { return array(); }
		foreach ( $items as $index => $item ) {
			$line = $review['lines'][ $index ] ?? array();
			foreach ( array( 'subtotal', 'total', 'tax', 'automatic_savings', 'quantity' ) as $key ) { if ( ! isset( $line[ $key ] ) || ! is_numeric( $line[ $key ] ) || ! is_finite( (float) $line[ $key ] ) || (float) $line[ $key ] < 0 ) { return array(); } }
			if ( (string) ( $line['name'] ?? '' ) !== $item->get_name() || (float) $line['quantity'] !== (float) $item->get_quantity() || self::money( $line['total'] ) !== self::money( $item->get_total() ) || self::money( $line['tax'] ) !== self::money( $item->get_total_tax() ) || abs( (float) $line['subtotal'] - (float) $line['automatic_savings'] - (float) $item->get_subtotal() ) > 0.011 ) { return array(); }
		}
		return $review;
	}
	/** Use only rates frozen on the order and selected on this line, never today's tax table. */
	private static function original_tax( $order, $item, $original ) {
		$taxes = $item->get_taxes(); $ids = array_keys( $taxes['subtotal'] ?? array() );
		if ( ! $ids ) { return (float) $item->get_subtotal_tax() > 0 ? null : 0.0; }
		$rates = array();
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			if ( ! in_array( $tax->get_rate_id(), $ids, false ) ) { continue; }
			$percent = $tax->get_rate_percent();
			if ( null === $percent || ! is_numeric( $percent ) ) { return null; }
			$rates[ $tax->get_rate_id() ] = array( 'rate' => $percent, 'compound' => $tax->is_compound() ? 'yes' : 'no' );
		}
		if ( count( $rates ) !== count( $ids ) ) { return null; }
		$amounts = \WC_Tax::calc_tax( $original, $rates, false );
		return array_sum( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ? $amounts : array_map( 'wc_round_tax_total', $amounts ) );
	}
	private static function automatic_label( $order, $review ) {
		$names = array();
		foreach ( (array) ( $review['automatic_discounts'] ?? array() ) as $discount ) { if ( (float) ( $discount['amount'] ?? 0 ) > 0 ) { $names[] = self::text( $discount['label'] ?? '' ); } }
		if ( ! array_filter( $names ) ) { $names = array_map( array( self::class, 'text' ), array_merge( (array) $order->get_meta( '_cart_cat_role_discount_names' ), (array) $order->get_meta( '_cart_cat_case_discount_names' ) ) ); }
		return implode( ' / ', array_unique( array_filter( $names ) ) ) ?: 'Customer discount';
	}
	private static function money( $amount ) { return (float) wc_format_decimal( $amount, wc_get_price_decimals() ); }
	public static function email_pickup( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin || ! Payments::owns( $order ) || ! $email instanceof \WC_Email || ! $email->is_customer_email() ) { return; }
		$locations = self::pickup_locations( $order );
		if ( ! $locations ) { return; }
		if ( $plain_text ) {
			echo "\nPickup at the winery\n";
			foreach ( $locations as $location ) { echo implode( "\n", array_filter( $location, static function ( $value ) { return '' !== $value; } ) ) . "\n\n"; }
			return;
		}
		echo '<h2>Pickup at the winery</h2>';
		foreach ( $locations as $location ) {
			echo '<p>';
			if ( '' !== $location['name'] ) { echo '<strong>' . esc_html( $location['name'] ) . '</strong>'; }
			if ( '' !== $location['address'] ) { echo ( '' !== $location['name'] ? '<br>' : '' ) . nl2br( esc_html( $location['address'] ) ); }
			if ( '' !== $location['details'] ) { echo ( '' !== $location['name'] || '' !== $location['address'] ? '<br>' : '' ) . nl2br( esc_html( $location['details'] ) ); }
			echo '</p>';
		}
	}
	public static function email_images( $args ) {
		if ( ! is_array( $args ) || ! Payments::owns( $args['order'] ?? null ) || ! empty( $args['sent_to_admin'] ) || ! empty( $args['plain_text'] ) ) { return $args; }
		$args['show_image'] = true; $args['image_size'] = array( 64, 64 );
		return $args;
	}
	private static function text( $value ) {
		if ( ! is_scalar( $value ) ) { return ''; }
		$text = preg_replace( '/<br\s*\/?\s*>/i', "\n", (string) $value );
		return trim( sanitize_textarea_field( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) ) );
	}
}
