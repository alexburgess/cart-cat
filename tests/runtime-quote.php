<?php
/** Run using wp eval-file in a DISPOSABLE local WordPress installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Local fixture only.' ); }
require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php';
use Cart_Cat\Quote;
use Cart_Cat\Customers;
use Cart_Cat\Orders;
$GLOBALS['cc_assert_count'] = 0;
function cc_assert( $truth, $message ) { $GLOBALS['cc_assert_count']++; if ( ! $truth ) { throw new RuntimeException( $message ); } }
function cc_ok( $result ) { if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() ); } return $result; }
wp_set_current_user( 1 );
update_option( 'woocommerce_default_country', 'US:VA' );
update_option( 'woocommerce_store_postcode', '20175' );
update_option( 'woocommerce_store_city', 'Leesburg' );
update_option( 'woocommerce_store_address', '19925 Hogback Mountain Road' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_based_on', 'shipping' );
update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_allowed_countries', 'all' );
update_option( 'woocommerce_ship_to_countries', '' );
if ( ! in_array( 'wine-rates', WC_Tax::get_tax_class_slugs(), true ) ) { WC_Tax::create_tax_class( 'Wine Rates', 'wine-rates' ); }
update_option( 'woocommerce_pickup_location_settings', array( 'enabled' => 'yes', 'title' => 'Pickup', 'cost' => '0', 'tax_status' => 'taxable' ) );
update_option( 'pickup_location_pickup_locations', array( array( 'enabled' => true, 'name' => 'The Club Shop', 'details' => 'Gate 2', 'address' => array( 'country' => 'US', 'state' => 'VA', 'city' => 'Leesburg', 'postcode' => '20175', 'address_1' => '19925 Hogback Mountain Road' ) ) ) );
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rate_locations" );
$standard_rate = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'VA', 'tax_rate' => '6.0000', 'tax_rate_name' => 'Virginia', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'VA', 'tax_rate' => '6.0000', 'tax_rate_name' => 'Wine', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => 'wine-rates' ) );
WC_Cache_Helper::invalidate_cache_group( 'taxes' );
$ids = array();
try {
	$product = new WC_Product_Simple(); $product->set_name( 'Cart Cat Test Wine' ); $product->set_status( 'publish' ); $product->set_regular_price( '100' ); $product->set_tax_class( 'wine-rates' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 30 ); $product->save(); $ids[] = $product->get_id();
	cc_assert( 'wine-rates' === wc_get_product( $product->get_id() )->get_tax_class(), 'Wine product retains the registered wine tax class.' );
	$ticket = new WC_Product_Simple(); $ticket->set_name( 'Cart Cat Ticket' ); $ticket->set_status( 'publish' ); $ticket->set_regular_price( '10' ); $ticket->save(); update_post_meta( $ticket->get_id(), '_tribe_wooticket_for_event', 9999 ); $ids[] = $ticket->get_id();
	$address = array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'country' => 'US', 'state' => 'CA', 'city' => 'San Francisco', 'postcode' => '94103', 'address_1' => '123 Test Street' );
	$input = array( 'customer' => array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'buyer@example.test', 'phone' => '2025550100', 'billing' => $address, 'shipping' => $address ), 'lines' => array( array( 'product_id' => $product->get_id(), 'quantity' => 1, 'variation_id' => 0, 'addons' => array() ) ), 'discount' => array( 'type' => 'percent', 'amount' => 0, 'mode' => 'add' ), 'fulfillment' => array( 'type' => 'pickup' ) );
	$old = array( WC()->cart, WC()->session, WC()->customer );
	$original_shipping = WC()->shipping();
	$original_packages = $original_shipping->get_packages();
	$orders_before = count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) );
	$q = cc_ok( Quote::calculate( $input ) );
	cc_assert( '100.00' === $q['totals']['subtotal'] && '6.00' === $q['totals']['tax'] && '106.00' === $q['totals']['total'], 'Pickup uses winery wine tax despite CA address: ' . wp_json_encode( $q ) );
	cc_assert( $q['can_submit'], 'Pickup quote should submit.' );
	update_option( 'cart_cat_pickup_rate_id', 'pickup_location:99' );
	cc_assert( is_wp_error( Quote::calculate( $input ) ), 'Missing configured pickup must not substitute another location.' );
	delete_option( 'cart_cat_pickup_rate_id' );
	$product->set_virtual( true ); $product->save();
	$virtual = cc_ok( Quote::calculate( $input ) );
	cc_assert( '6.00' === $virtual['totals']['tax'] && ! $virtual['rates'], 'Taxable virtual pickup uses winery tax without shipping charge: ' . wp_json_encode( $virtual ) );
	$product->set_virtual( false ); $product->save();
	cc_assert( $old === array( WC()->cart, WC()->session, WC()->customer ) && 1 === get_current_user_id(), 'Quote must restore staff context.' );
	cc_assert( $original_shipping === WC()->shipping() && $original_packages === WC()->shipping()->get_packages(), 'Quote must restore the native shipping singleton and its staff packages.' );
	cc_assert( $q['hash'] === cc_ok( Quote::calculate( $input, true ) )['hash'], 'Quote hash must be stable.' );
	cc_assert( $orders_before === count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) ), 'Preview must never create orders.' );
	$input['discount']['amount'] = 10;
	$q = cc_ok( Quote::calculate( $input ) );
	cc_assert( '95.40' === $q['totals']['total'], 'Percentage before-tax total: ' . wp_json_encode( $q['totals'] ) );
	$input['discount'] = array( 'type' => 'fixed', 'amount' => 15, 'mode' => 'add' );
	$q = cc_ok( Quote::calculate( $input ) );
	cc_assert( '90.10' === $q['totals']['total'], 'Fixed before-tax discount.' );
	$input['discount']['amount'] = 101;
	cc_assert( is_wp_error( Quote::calculate( $input ) ), 'Excessive fixed discount blocked.' );
	$input['discount']['amount'] = 0;
	$input['lines'][0]['product_id'] = $ticket->get_id();
	cc_assert( is_wp_error( Quote::calculate( $input ) ), 'Ticket rejected.' );
	$input['lines'][0]['product_id'] = $product->get_id();
	$input['lines'][0]['quantity'] = 31;
	cc_assert( is_wp_error( Quote::calculate( $input ) ), 'Insufficient stock rejected.' );
	$input['lines'][0]['quantity'] = 1;
	$q = cc_ok( Quote::calculate( $input ) );
	$order = cc_ok( Orders::create( $input, $q['hash'], 'fixture-' . wp_generate_uuid4() ) );
	$saved = wc_get_order( $order['order_id'] );
	cc_assert( '106.00' === $saved->get_total(), 'Persisted order equals quote.' );
	cc_assert( 0 === $saved->get_customer_id(), 'Manual customer remains guest.' );
	$shipping_items = $saved->get_items( 'shipping' );
	$shipping = reset( $shipping_items );
	cc_assert( 'VA' === $shipping->get_meta( '_pickup_location_address' )['state'], 'Pickup metadata persists.' );
	$saved->calculate_taxes(); $saved->calculate_totals( false );
	cc_assert( '106.00' === $saved->get_total(), 'Native order recalculation retains winery pickup tax.' );
	cc_assert( ! $saved->is_paid(), 'Creating order must not pay.' );
	$saved->delete( true );
	// Deliberately different synthetic rates prove fixed discounts retain each tax class.
	WC_Tax::_update_tax_rate( $standard_rate, array( 'tax_rate' => '5.0000' ) ); WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	$merch = new WC_Product_Simple(); $merch->set_name( 'Cart Cat Mixed Tax Fixture' ); $merch->set_status( 'publish' ); $merch->set_regular_price( '50' ); $merch->save(); $ids[] = $merch->get_id();
	$input['lines'][] = array( 'product_id' => $merch->get_id(), 'quantity' => 1 );
	$input['discount'] = array( 'type' => 'fixed', 'amount' => 30, 'mode' => 'add' );
	$mixed = cc_ok( Quote::calculate( $input ) );
	$wine_line = $mixed['lines'][0]; $merch_line = $mixed['lines'][1];
	cc_assert( '30.00' === $mixed['totals']['discount'] && 120.0 === (float) $wine_line['total'] + (float) $merch_line['total'], 'Fixed discount is allocated across both product lines.' );
	cc_assert( wc_format_decimal( round( (float) $wine_line['total'] * 0.06, 2 ), 2 ) === $wine_line['tax'] && wc_format_decimal( round( (float) $merch_line['total'] * 0.05, 2 ), 2 ) === $merch_line['tax'], 'Each discounted line uses its own configured tax class: ' . wp_json_encode( $mixed ) );
	$mixed_saved = cc_ok( Orders::create( $input, $mixed['hash'], 'fixture-' . wp_generate_uuid4() ) );
	$mixed_order = wc_get_order( $mixed_saved['order_id'] );
	cc_assert( $mixed['totals']['tax'] === $mixed_order->get_total_tax() && $mixed['totals']['total'] === $mixed_order->get_total(), 'Mixed-class saved order tax and total equal the approved quote.' );
	$mixed_order->delete( true );
	WC_Tax::_update_tax_rate( $standard_rate, array( 'tax_rate' => '6.0000' ) ); WC_Cache_Helper::invalidate_cache_group( 'taxes' );

	$adjusted = $input;
	$adjusted['discount'] = array( 'type' => 'percent', 'amount' => 10, 'mode' => 'add' );
	$adjusted['lines'] = array(
		array( 'product_id' => $product->get_id(), 'quantity' => 2, 'price_override' => array( 'type' => 'percent', 'value' => '25.50' ) ),
		array( 'product_id' => $product->get_id(), 'quantity' => 1 ),
		array( 'product_id' => $merch->get_id(), 'quantity' => 2, 'price_override' => array( 'type' => 'fixed', 'value' => '12.35' ) ),
	);
	$adjusted_quote = cc_ok( Quote::calculate( $adjusted ) );
	cc_assert( $adjusted_quote['can_submit'] && count( $adjusted_quote['lines'] ) === 3, 'The same wine on an adjusted line and a standard line stays separate.' );
	cc_assert( array( '134.10', '90.00', '22.23' ) === array_column( $adjusted_quote['lines'], 'total' ), 'Per-item percent and custom unit prices respect quantity, then the cart-wide percentage applies once.' );
	cc_assert( '-126.30' === $adjusted_quote['totals']['price_adjustment'] && '27.37' === $adjusted_quote['totals']['discount'] && '14.78' === $adjusted_quote['totals']['tax'] && '261.11' === $adjusted_quote['totals']['total'], 'Adjusted merchandise, the cart coupon and tax reconcile to the grand total.' );
	cc_assert( $adjusted_quote['hash'] === cc_ok( Quote::calculate( $adjusted, true ) )['hash'], 'Requoting does not compound the adjustment.' );
	$changed_override = $adjusted;
	$changed_override['lines'][0]['price_override']['value'] = 20;
	$stale = Orders::create( $changed_override, $adjusted_quote['hash'], 'fixture-' . wp_generate_uuid4() );
	cc_assert( is_wp_error( $stale ) && 'cart_cat_stale_quote' === $stale->get_error_code(), 'The approved quote binds manual line pricing.' );
	foreach ( array( array(), true, array( 'type' => 'fixed', 'value' => '' ), array( 'type' => 'fixed', 'value' => -1 ), array( 'type' => 'fixed', 'value' => '1e100' ), array( 'type' => 'percent', 'value' => '100.01' ), array( 'type' => 'invalid', 'value' => 5 ) ) as $invalid_override ) {
		$malformed = $adjusted;
		$malformed['lines'][0]['price_override'] = $invalid_override;
		cc_assert( is_wp_error( Quote::calculate( $malformed ) ), 'Invalid overrides are rejected before checkout.' );
	}
	$changed_override['lines'][0]['price_override']['value'] = 100;
	$changed_override['lines'][2]['price_override']['value'] = 0;
	cc_assert( array( '0.00', '90.00', '0.00' ) === array_column( cc_ok( Quote::calculate( $changed_override ) )['lines'], 'total' ), 'Both 100% and an explicit zero price are valid.' );
	$changed_override['lines'][2]['price_override']['value'] = '55';
	$raised = cc_ok( Quote::calculate( $changed_override ) );
	cc_assert( '99.00' === $raised['lines'][2]['total'] && '10.00' === $raised['lines'][2]['price_adjustment'], 'A custom price may increase the unit price.' );

	$fractional = $adjusted;
	$fractional['discount']['amount'] = 0;
	$fractional['lines'] = array_fill( 0, 2, array( 'product_id' => $product->get_id(), 'quantity' => 1, 'price_override' => array( 'type' => 'percent', 'value' => '0.006' ) ) );
	$fractional_quote = cc_ok( Quote::calculate( $fractional ) );
	cc_assert( array( '99.99', '99.99' ) === array_column( $fractional_quote['lines'], 'total' ) && '-0.02' === $fractional_quote['totals']['price_adjustment'], 'The cart summary reflects Woo rounding fractional cents per line.' );

	$prepared = cc_ok( Orders::create( $adjusted, $adjusted_quote['hash'], 'fixture-' . wp_generate_uuid4() ) );
	$adjusted_order = wc_get_order( $prepared['order_id'] );
	try {
		$items = array_values( $adjusted_order->get_items() );
		cc_assert( array( 149.0, 100.0, 24.70 ) === array_map( static function ( $item ) { return (float) $item->get_subtotal(); }, $items ), 'Woo persists adjusted subtotals before the normal cart discount.' );
		cc_assert( $adjusted_quote['totals']['total'] === $adjusted_order->get_total() && $adjusted_quote['totals']['tax'] === $adjusted_order->get_total_tax(), 'Prepared total and taxes equal the approved quote.' );
		$displayed = Cart_Cat\Presentation::customer_pricing( $adjusted_order );
		$first_displayed_item = $displayed['lines'][ $items[0]->get_id() ];
		cc_assert( 200.0 === (float) $first_displayed_item['original'] && 134.10 === (float) $first_displayed_item['total'], 'Customer presentation recognizes the saved pre-adjustment and final prices.' );
		$editing = cc_ok( Orders::edit( $adjusted_order->get_id(), $prepared['version'] ) );
		$editable = $editing['input'];
		cc_assert( 'percent' === $editable['lines'][0]['price_override']['type'] && 25.5 === (float) $editable['lines'][0]['price_override']['value'] && 'fixed' === $editable['lines'][2]['price_override']['type'], 'Reopening the order restores the saved overrides.' );
		$editable['lines'][0]['price_override'] = null;
		$reset_quote = cc_ok( Quote::calculate( $editable ) );
		$reset_order = cc_ok( Orders::prepare( $adjusted_order->get_id(), $editable, $reset_quote['hash'], $editing['version'], 'fixture-' . wp_generate_uuid4() ) );
		$reopened = cc_ok( Orders::get( $adjusted_order->get_id() ) );
		cc_assert( count( $reset_quote['lines'] ) === 2 && '270.00' === $reset_quote['lines'][0]['total'] && $adjusted_order->get_id() === $reset_order['order_id'] && empty( $reopened['input']['lines'][0]['price_override'] ), 'Reset restores normal pricing and requotes the same order.' );
	} finally {
		$adjusted_order->delete( true );
	}

	// A tax-inclusive catalog price follows Woo's base-to-pickup tax conversion.
	WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => '5.0000', 'tax_rate_name' => 'Fixture base wine tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => 'wine-rates' ) );
	WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	update_option( 'woocommerce_default_country', 'US:CA' );
	update_option( 'woocommerce_prices_include_tax', 'yes' );
	try {
		$inclusive = $adjusted;
		$inclusive['lines'] = array( array( 'product_id' => $product->get_id(), 'quantity' => 1, 'price_override' => array( 'type' => 'fixed', 'value' => '84' ) ) );
		$inclusive['discount']['amount'] = 0;
		$inclusive_quote = cc_ok( Quote::calculate( $inclusive ) );
		cc_assert( '80.00' === $inclusive_quote['lines'][0]['total'] && '4.80' === $inclusive_quote['totals']['tax'] && '84.80' === $inclusive_quote['totals']['total'], 'The custom catalog price removes base tax before adding the pickup rate.' );
		cc_assert( '95.24' === $inclusive_quote['totals']['subtotal'] && '-15.24' === $inclusive_quote['totals']['price_adjustment'], 'The adjustment reconciles before tax even when the pickup rate differs from the base rate.' );
	} finally {
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_default_country', 'US:VA' );
	}
} finally { foreach ( $ids as $id ) { $p = wc_get_product( $id ); if ( $p ) { $p->delete( true ); } } }
echo 'Cart Cat quote/order runtime: ' . $GLOBALS['cc_assert_count'] . " assertions passed.\n";
