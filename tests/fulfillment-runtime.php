<?php
/** Disposable local WordPress only. Uses native flat-rate as a deterministic carrier fixture. */
if ( ! defined( 'WP_CLI' ) || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Local fixture only.' ); }
use Cart_Cat\Quote;
use Cart_Cat\Orders;
wp_set_current_user( 1 );
$GLOBALS['cc_fulfillment_count'] = 0;
function cc_fulfill( $value, $message ) { $GLOBALS['cc_fulfillment_count']++; if ( ! $value ) { throw new RuntimeException( $message ); } }
function cc_fq( $input ) { $q = Quote::calculate( $input ); if ( is_wp_error( $q ) ) { throw new RuntimeException( $q->get_error_message() ); } return $q; }
$term = wp_insert_term( 'Cart Cat Fixture Wine ' . wp_generate_uuid4(), 'product_cat' );
$zone = new WC_Shipping_Zone(); $zone->set_zone_name( 'Cart Cat fixture shipping' ); $zone->set_zone_order( -1 ); $zone->add_location( 'US', 'country' ); $zone->save();
$instance = $zone->add_shipping_method( 'flat_rate' );
update_option( 'woocommerce_flat_rate_' . $instance . '_settings', array( 'title' => 'Fixture UPS equivalent', 'tax_status' => 'taxable', 'cost' => '10' ) );
WC_Cache_Helper::get_transient_version( 'shipping', true );
$settings = get_option( 'heat_hold_horse_settings' );
update_option( 'heat_hold_horse_settings', array_merge( Heat_Hold_Horse_Plugin::default_settings(), array( 'wine_category_id' => $term['term_id'] ) ) );
$product = new WC_Product_Simple(); $product->set_name( 'Cart Cat Shipping Wine' ); $product->set_status( 'publish' ); $product->set_regular_price( 100 ); $product->set_tax_class( 'wine-rates' ); $product->set_category_ids( array( $term['term_id'] ) ); $product->save();
$period_id = 0; $order_id = 0;
$vole_original = get_option( 'vinoshipper_vole_settings', null );
try {
	$address = array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'country' => 'US', 'state' => 'VA', 'city' => 'Leesburg', 'postcode' => '20175', 'address_1' => '123 Test Lane' );
	$shipping = array_merge( $address, array( 'first_name' => 'Shipping', 'last_name' => 'Recipient', 'email' => 'recipient@example.test', 'phone' => '+1 (202) 555-0102' ) );
	$input = array( 'customer' => array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'shipping@example.test', 'phone' => '2025550101', 'billing' => $address, 'shipping' => $shipping ), 'lines' => array( array( 'product_id' => $product->get_id(), 'quantity' => 1 ) ), 'discount' => array( 'type' => 'percent', 'amount' => 0, 'mode' => 'add' ), 'fulfillment' => array( 'type' => 'shipping', 'rates' => array( 'flat_rate:' . $instance ) ) );
	$q = cc_fq( $input );
	cc_fulfill( 'available' === $q['shipping_status'], 'Valid rate has available shipping status.' );
	cc_fulfill( $q['can_submit'] && '10.00' === $q['totals']['shipping'] && '6.60' === $q['totals']['tax'] && '116.60' === $q['totals']['total'], 'Native shipping/tax total: ' . wp_json_encode( $q ) );
	$without_contact = $input; unset( $without_contact['customer']['shipping']['email'], $without_contact['customer']['shipping']['phone'] );
	$missing_contact = cc_fq( $without_contact );
	cc_fulfill( ! $missing_contact['can_submit'] && 'available' === $missing_contact['shipping_status'] && $q['totals'] === $missing_contact['totals'], 'Missing recipient contact blocks preparation but retains available delivery rates and totals.' );
	cc_fulfill( in_array( 'Enter a valid shipping recipient email address.', $missing_contact['notices'], true ) && in_array( 'Enter a valid shipping recipient phone number.', $missing_contact['notices'], true ), 'Missing contact notices identify both recipient fields.' );
	$invalid_contact = $input; $invalid_contact['customer']['shipping']['email'] = 'not-an-email'; $invalid_contact['customer']['shipping']['phone'] = 'not-a-number';
	cc_fulfill( ! cc_fq( $invalid_contact )['can_submit'], 'Malformed recipient contact cannot submit.' );
	$changed_contact = $input; $changed_contact['customer']['shipping']['email'] = 'another-recipient@example.test';
	cc_fulfill( $q['hash'] !== cc_fq( $changed_contact )['hash'], 'Recipient email is bound to the confirmed quote.' );
	$blocked = Orders::create( $without_contact, $missing_contact['hash'], 'missing-contact-' . wp_generate_uuid4() );
	cc_fulfill( is_wp_error( $blocked ) && 'cart_cat_stale_quote' === $blocked->get_error_code(), 'Server preparation rejects incomplete recipient contact even with its current quote hash.' );
	$input['customer']['shipping']['state'] = 'CA'; $input['customer']['shipping']['postcode'] = '94103';
	$q = cc_fq( $input ); cc_fulfill( '0.00' === $q['totals']['tax'] && '110.00' === $q['totals']['total'], 'Shipping tax follows destination.' );
	$input['customer']['shipping']['postcode'] = '';
	$q = cc_fq( $input ); cc_fulfill( ! $q['can_submit'], 'Incomplete address cannot submit.' );
	$input['customer']['shipping'] = $shipping;
	$input['fulfillment']['rates'][0] = 'flat_rate:999999';
	$q = cc_fq( $input ); cc_fulfill( ! $q['can_submit'], 'Stale rate selection cannot submit.' );
	$input['fulfillment']['rates'][0] = 'flat_rate:' . $instance;
	$hide_rates = static function () { return array(); };
	add_filter( 'woocommerce_package_rates', $hide_rates, 999999 );
	$q = cc_fq( $input ); cc_fulfill( ! $q['can_submit'] && 'unavailable' === $q['shipping_status'], 'Provider rate failure cannot become free shipping.' );
	remove_filter( 'woocommerce_package_rates', $hide_rates, 999999 );
	$ups_fixture = static function ( $package, $method ) {
		if ( 'flat_rate' !== $method->id ) { return; }
		$ups = new class extends WC_Shipping_Method { public function __construct() { $this->id = 'ups'; $this->settings = array( 'packing_method' => 'box_packing' ); } };
		do_action( 'woocommerce_after_get_rates_for_package', $package, $ups );
	};
	add_action( 'woocommerce_before_get_rates_for_package', $ups_fixture, 10, 2 );
	add_filter( 'woocommerce_package_rates', $hide_rates, 999999 );
	$q = cc_fq( $input );
	cc_fulfill( ! $q['can_submit'] && false !== strpos( implode( ' ', $q['notices'] ), 'shipping weight, length, width, height are missing' ), 'UPS missing product package data is explained.' );
	remove_filter( 'woocommerce_package_rates', $hide_rates, 999999 );
	$q = cc_fq( $input );
	cc_fulfill( $q['can_submit'] && 'available' === $q['shipping_status'], 'UPS packing diagnostics do not block other eligible carriers.' );
	remove_action( 'woocommerce_before_get_rates_for_package', $ups_fixture, 10 );
	$product->set_virtual( true ); $product->save();
	$q = cc_fq( $without_contact );
	cc_fulfill( $q['can_submit'] && 'not_required' === $q['shipping_status'], 'Virtual-only orders do not claim unavailable shipping.' );
	$product->set_virtual( false ); $product->save();
	$period_id = wp_insert_post( array( 'post_type' => Heat_Hold_Horse_Schedules::POST_TYPE, 'post_title' => 'Fixture Warm Weather', 'post_status' => 'publish', 'meta_input' => array( Heat_Hold_Horse_Schedules::META_START => time() - 3600, Heat_Hold_Horse_Schedules::META_END => time() + 86400, Heat_Hold_Horse_Schedules::META_PAUSED => 'no', Heat_Hold_Horse_Schedules::META_STATES => array( 'VA' ) ) ) );
	$q = cc_fq( $input ); cc_fulfill( $q['heat_hold']['required'] && ! $q['can_submit'], 'Heat Hold choice required.' );
	$input['fulfillment']['heat_hold_choice'] = 'hold';
	$q = cc_fq( $input ); cc_fulfill( $q['can_submit'], 'Heat Hold choice makes order ready.' );
	$result = Orders::create( $input, $q['hash'], 'fulfillment-' . wp_generate_uuid4() );
	cc_fulfill( ! is_wp_error( $result ), 'Heat Hold order creates: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
	$order_id = $result['order_id']; $order = wc_get_order( $order_id );
	cc_fulfill( 'hold' === $order->get_meta( '_hhh_shipping_preference' ) && 'yes' === $order->get_meta( '_hhh_choice_was_required' ), 'Native Heat Hold metadata stored.' );
	cc_fulfill( 'recipient@example.test' === $order->get_meta( '_shipping_email' ) && '+1 (202) 555-0102' === $order->get_shipping_phone(), 'Recipient email and native shipping phone persist on the order.' );
	cc_fulfill( 'shipping@example.test' === $order->get_billing_email() && '2025550101' === $order->get_billing_phone(), 'Shipping recipient contact never replaces the purchaser billing contact.' );
	cc_fulfill( 'recipient@example.test' === $result['shipping']['email'] && '+1 (202) 555-0102' === $result['input']['customer']['shipping']['phone'], 'Order read and editable input restore both recipient contacts.' );
	$before_contact_change = Cart_Cat\Payments::fingerprint( $order );
	$order->update_meta_data( '_shipping_email', 'external-recipient@example.test' );
	cc_fulfill( $before_contact_change !== Cart_Cat\Payments::fingerprint( $order ), 'Payment fingerprint detects an externally changed recipient email.' );
	$order->update_meta_data( '_shipping_email', 'recipient@example.test' );
	$order->set_shipping_phone( '2025550199' );
	cc_fulfill( $before_contact_change !== Cart_Cat\Payments::fingerprint( $order ), 'Payment fingerprint detects an externally changed recipient phone.' );
	$order->set_shipping_phone( '+1 (202) 555-0102' );
	$edited = Orders::edit( $order_id, $result['version'] );
	cc_fulfill( ! is_wp_error( $edited ) && 'recipient@example.test' === $edited['input']['customer']['shipping']['email'], 'Entering edit preserves the existing shipping recipient.' );
	$input['customer']['shipping']['email'] = 'updated-recipient@example.test';
	$input['customer']['shipping']['phone'] = '2025550103';
	$q = cc_fq( $input );
	$prepared = Orders::prepare( $order_id, $input, $q['hash'], $edited['version'], 'shipping-contact-' . wp_generate_uuid4() );
	cc_fulfill( ! is_wp_error( $prepared ), 'Recipient contact changes can reprepare the same order.' );
	$order = wc_get_order( $order_id );
	cc_fulfill( 'updated-recipient@example.test' === $order->get_meta( '_shipping_email' ) && '2025550103' === $order->get_shipping_phone() && 'updated-recipient@example.test' === $prepared['input']['customer']['shipping']['email'], 'Same-order reprepare stores and reloads updated recipient contact.' );
	$input['fulfillment']['type'] = 'pickup'; unset( $input['fulfillment']['rates'] );
	unset( $input['customer']['shipping']['email'], $input['customer']['shipping']['phone'] );
	$q = cc_fq( $input ); cc_fulfill( ! $q['heat_hold']['required'] && '106.00' === $q['totals']['total'], 'Pickup clears shipping hold and destination tax.' );
	cc_fulfill( $q['can_submit'], 'Pickup does not require shipping recipient contact.' );
	$vole_source = $args[0] ?? '';
	if ( $vole_source && is_file( $vole_source . '/includes/class-settings.php' ) ) {
		require_once $vole_source . '/includes/class-crypto.php';
		require_once $vole_source . '/includes/class-settings.php';
		update_option( 'vinoshipper_vole_settings', array( 'enabled' => true, 'states' => array( 'AK' ), 'wine_category_id' => $term['term_id'] ) );
		$input['customer']['shipping']['state'] = 'AK'; $input['customer']['shipping']['postcode'] = '99501';
		$input['fulfillment']['type'] = 'shipping';
		$blocked = Quote::calculate( $input );
		cc_fulfill( is_wp_error( $blocked ) && 'cart_cat_vinoshipper' === $blocked->get_error_code(), 'Real Vole configuration blocks routed wine before rates/payment.' );
		$input['fulfillment']['type'] = 'pickup';
		$q = cc_fq( $input ); cc_fulfill( $q['can_submit'] && '106.00' === $q['totals']['total'], 'Vole-state billing does not block winery pickup.' );
	}
} finally {
	if ( $order_id ) { wc_get_order( $order_id )->delete( true ); }
	if ( $period_id ) { wp_delete_post( $period_id, true ); }
	$product->delete( true ); $zone->delete(); delete_option( 'woocommerce_flat_rate_' . $instance . '_settings' );
	wp_delete_term( $term['term_id'], 'product_cat' ); update_option( 'heat_hold_horse_settings', $settings );
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	if ( null === $vole_original ) { delete_option( 'vinoshipper_vole_settings' ); } else { update_option( 'vinoshipper_vole_settings', $vole_original ); }
}
echo 'Cart Cat fulfillment runtime: ' . $GLOBALS['cc_fulfillment_count'] . " assertions passed.\n";
