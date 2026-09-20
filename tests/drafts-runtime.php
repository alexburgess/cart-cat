<?php
/** Actual WordPress private-post persistence and Woo preparation. Disposable localhost only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable localhost only.' ); }
use Cart_Cat\Drafts;
use Cart_Cat\Customers;
use Cart_Cat\Orders;
use Cart_Cat\Quote;

$count = 0; $drafts = array(); $users = array(); $orders = array(); $product = null; $zone = null;
$old_actor = get_current_user_id(); $suffix = substr( wp_generate_uuid4(), 0, 8 );
$old_tax = get_option( 'woocommerce_calc_taxes', false );
$assert = static function ( $value, $message ) use ( &$count ) { ++$count; if ( ! $value ) { throw new RuntimeException( $message ); } };
$ok = static function ( $v ) { if ( is_wp_error( $v ) ) { throw new RuntimeException( $v->get_error_code() . ': ' . $v->get_error_message() ); } return $v; };
$error = static function ( $v, $code ) { return is_wp_error( $v ) && $v->get_error_code() === $code; };
$config = static function () { return array( 'ready' => false, 'application_id' => 'draft-fixture', 'location_id' => 'draft-location', 'environment' => 'sandbox', 'currency' => 'USD' ); };
$network = static function () { throw new RuntimeException( 'Draft runtime must never contact a provider.' ); };
$scope_change = static function () { return array( 'ready' => false, 'application_id' => 'different-draft-fixture', 'location_id' => 'draft-location', 'environment' => 'sandbox', 'currency' => 'USD' ); };
$new_orders = 0; $order_event = static function () use ( &$new_orders ) { ++$new_orders; };
add_filter( 'cart_cat_square_public_config', $config, PHP_INT_MAX );
add_filter( 'cart_cat_square_transport', $network, PHP_INT_MAX, 4 );
add_action( 'woocommerce_new_order', $order_event, PHP_INT_MAX );
try {
	foreach ( array( 'staff-a', 'staff-b', 'customer' ) as $label ) {
		$id = $ok( wp_create_user( 'cc-draft-' . $label . '-' . $suffix, wp_generate_password(), 'cc-draft-' . $label . '-' . $suffix . '@example.test' ) );
		$users[] = $id; ( new WP_User( $id ) )->set_role( 'customer' === $label ? 'customer' : 'administrator' );
	}
	wp_set_current_user( $users[0] );
	Drafts::register_type(); $type = get_post_type_object( Drafts::POST_TYPE );
	$assert( ! $type->public && ! $type->publicly_queryable && ! $type->show_in_rest && ! $type->show_ui && ! $type->can_export, 'Drafts stay off public/native REST/UI/export surfaces.' );
	$reference = ( new ReflectionMethod( Customers::class, 'issue_reference' ) )->invoke( null, $users[2], '' );
	$input = array( 'customer' => array( 'reference' => $reference, 'first_name' => 'Native', 'last_name' => 'Draft', 'email' => 'incomplete@', 'billing' => array( 'address_1' => '<b>Saved street</b>' ), 'shipping' => array( 'email' => 'recipient@example.test', 'phone' => '2025550111' ) ), 'lines' => array( array( 'product_id' => 777, 'quantity' => '', 'name' => 'Unsaved wine', 'addons' => array( 'message' => "O'Reilly\\gift\nsecond line" ) ) ), 'order_notes' => "Private note\ncontinued", 'order_notes_visible' => false );
	$request = array( 'input' => $input, 'title' => 'Native draft fixture ' . $suffix, 'request_id' => 'native-create-' . wp_generate_uuid4() );
	$d = $ok( Drafts::save( 0, $request ) ); $drafts[] = $d['draft_id'];
	$assert( 'private' === get_post_status( $d['draft_id'] ) && Drafts::POST_TYPE === get_post_type( $d['draft_id'] ), 'Actual storage is a private custom post, never an order.' );
	$assert( 0 === $new_orders, 'Saving a partial draft creates no Woo orders.' );
	$loaded = $ok( Drafts::get( $d['draft_id'] ) );
	$assert( "O'Reilly\\gift\nsecond line" === $loaded['input']['lines'][0]['addons']['message'], 'Native SQL persistence preserves JSON slashes and multiline options.' );
	$assert( 'Saved street' === $loaded['input']['customer']['billing']['address_1'] && 'incomplete@' === $loaded['input']['customer']['email'], 'Draft stores sanitized and incomplete input.' );
	$assert( $d['draft_id'] === $ok( Drafts::save( 0, $request ) )['draft_id'], 'An initial save retry reopens exactly the same native post.' );
	wp_set_current_user( $users[1] );
	$other = $ok( Drafts::get( $d['draft_id'] ) );
	$assert( (int) $users[2] === $ok( Customers::identity_for_draft( $other['input']['customer']['reference'] ) )['woo_id'], 'Second staff member receives a valid freshly bound saved identity.' );
	$assert( $error( Customers::identity_for_draft( $loaded['input']['customer']['reference'] ), 'cart_cat_customer_reference' ), 'Original staff reference cannot be reused by another staff member.' );
	$results = $ok( Drafts::search( 'Native draft fixture ' . $suffix ) );
	$assert( 1 === $results['total'] && $d['draft_id'] === $results['drafts'][0]['draft_id'], 'Native WP query shares private drafts across authorized staff.' );
	$save = array( 'input' => $other['input'], 'title' => $other['title'], 'version' => $other['version'], 'request_id' => 'native-update-' . wp_generate_uuid4() );
	$save['input']['customer']['last_name'] = 'Updated';
	$updated = $ok( Drafts::save( $d['draft_id'], $save ) );
	$assert( $updated['version'] !== $other['version'], 'A real persisted update rotates revision.' );
	$stale = $save; $stale['request_id'] = 'native-stale-' . wp_generate_uuid4();
	$assert( $error( Drafts::save( $d['draft_id'], $stale ), 'cart_cat_draft_changed' ), 'Optimistic revision prevents stale writes in native storage.' );
	add_filter( 'cart_cat_square_public_config', $scope_change, PHP_INT_MAX );
	$changed = $ok( Drafts::get( $d['draft_id'] ) );
	$assert( $changed['customer_selection_required'] && 'Updated' === $changed['input']['customer']['last_name'] && '' !== $changed['input']['customer']['reference'], 'Changed connection preserves fields and blocks silent guest conversion.' );
	remove_filter( 'cart_cat_square_public_config', $scope_change, PHP_INT_MAX );
	wp_set_current_user( $users[2] );
	$assert( $error( Drafts::get( $d['draft_id'] ), 'cart_cat_forbidden' ), 'Ordinary customer cannot open a private draft.' );
	wp_set_current_user( $users[1] );
	$delete = $ok( Drafts::save( 0, array( 'input' => array(), 'request_id' => 'native-delete-' . wp_generate_uuid4() ) ) ); $drafts[] = $delete['draft_id'];
	$assert( $ok( Drafts::delete( $delete['draft_id'], $delete['version'] ) )['deleted'], 'Native draft deletion succeeds.' );
	$assert( $error( Drafts::get( $delete['draft_id'] ), 'cart_cat_draft_not_found' ), 'Deleted native draft is unavailable.' );
	$assert( ! str_contains( get_post( $delete['draft_id'] )->post_content, 'customer' ), 'Deletion wipes customer input while retaining request tombstone.' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	$zone = new WC_Shipping_Zone(); $zone->set_zone_name( 'Cart Cat draft fixture ' . $suffix ); $zone->set_zone_order( -100 ); $zone->add_location( 'US', 'country' ); $zone->save();
	$instance = $zone->add_shipping_method( 'flat_rate' ); update_option( 'woocommerce_flat_rate_' . $instance . '_settings', array( 'enabled' => 'yes', 'title' => 'Draft fixture shipping', 'cost' => '5', 'tax_status' => 'none' ) );
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	$product = new WC_Product_Simple(); $product->set_name( 'Draft fixture wine ' . $suffix ); $product->set_status( 'publish' ); $product->set_regular_price( '20' ); $product->set_tax_status( 'none' ); $product->set_manage_stock( true ); $product->set_stock_quantity( 10 ); $product->save();
	$address = array( 'first_name' => 'Native', 'last_name' => 'Recipient', 'email' => 'native-recipient@example.test', 'phone' => '2025550111', 'address_1' => '123 Fixture Lane', 'address_2' => '', 'city' => 'Leesburg', 'state' => 'VA', 'country' => 'US', 'postcode' => '20175' );
	$full = array( 'customer' => array( 'reference' => '', 'first_name' => 'Native', 'last_name' => 'Buyer', 'email' => 'native-buyer@example.test', 'phone' => '2025550100', 'billing' => $address, 'shipping' => $address ), 'lines' => array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 2, 'attributes' => array(), 'addons' => array() ) ), 'discount' => array( 'type' => 'percent', 'amount' => 0, 'mode' => 'add' ), 'fulfillment' => array( 'type' => 'shipping', 'rates' => array( 'flat_rate:' . $instance ), 'heat_hold_choice' => '' ), 'hold_minutes' => 60, 'order_notes' => '', 'order_notes_visible' => false );
	$prepared_draft = $ok( Drafts::save( 0, array( 'input' => $full, 'request_id' => 'native-ready-' . wp_generate_uuid4() ) ) ); $drafts[] = $prepared_draft['draft_id'];
	$assert( 0 === $new_orders && 10 === wc_get_product( $product->get_id() )->get_stock_quantity(), 'Saving a complete draft still creates no order or stock deduction.' );
	$quote = $ok( Quote::calculate( $full ) ); $assert( $quote['can_submit'], 'Native current Woo shipping quote can prepare.' );
	$prepare_request = array( 'input' => $full, 'version' => $prepared_draft['version'], 'quote_hash' => $quote['hash'], 'request_id' => 'native-prepare-' . wp_generate_uuid4() );
	$result = $ok( Drafts::prepare( $prepared_draft['draft_id'], $prepare_request ) ); $orders[] = $result['order_id'];
	$assert( 1 === $new_orders && 'pending' === $result['status'], 'Only explicit preparation creates one pending Woo order.' );
	$saved_order = wc_get_order( $result['order_id'] );
	$assert( '2025550111' === $saved_order->get_shipping_phone() && 'native-recipient@example.test' === $saved_order->get_meta( '_shipping_email' ), 'Actual Woo storage preserves recipient phone and email.' );
	$assert( '2025550100' === $saved_order->get_billing_phone() && 'native-buyer@example.test' === $saved_order->get_billing_email(), 'Recipient contact remains independent of the purchaser billing contact.' );
	$reopened_order = $ok( Orders::get( $result['order_id'] ) );
	$assert( '2025550111' === $reopened_order['input']['customer']['shipping']['phone'] && 'native-recipient@example.test' === $reopened_order['input']['customer']['shipping']['email'], 'Reopened editable order input roundtrips both saved recipient fields.' );
	$assert( '2025550100' === $reopened_order['input']['customer']['phone'] && 'native-buyer@example.test' === $reopened_order['input']['customer']['email'], 'Reopened editable order input retains independent purchaser contact.' );
	$record = json_decode( get_post( $prepared_draft['draft_id'] )->post_content, true );
	$request_key = 'cart_cat_request_' . Orders::request_key( $record['attempt']['request_id'], 'draft:' . $prepared_draft['draft_id'] );
	$assert( (int) $result['order_id'] === (int) get_option( $request_key )['order_id'], 'Durable order request is scoped to draft rather than staff.' );
	wp_set_current_user( $users[0] ); $prepare_request['request_id'] = 'native-other-staff-' . wp_generate_uuid4();
	$again = $ok( Drafts::prepare( $prepared_draft['draft_id'], $prepare_request ) );
	$assert( $result['order_id'] === $again['order_id'] && 1 === $new_orders, 'Cross-staff conflicting retry recovers one native order.' );
	$record['state'] = 'preparing'; unset( $record['order_id'] );
	wp_update_post( wp_slash( array( 'ID' => $prepared_draft['draft_id'], 'post_content' => wp_json_encode( $record ) ) ) );
	$recover = $ok( Drafts::get( $prepared_draft['draft_id'] ) );
	$assert( 'prepared' === $recover['state'] && $result['order_id'] === $recover['order_id'] && 1 === $new_orders, 'A lost final draft write recovers from the actual order ledger without duplicating.' );
	delete_option( $request_key );
	echo 'Cart Cat drafts native runtime: ' . $count . ' assertions passed; Woo ' . WC_VERSION . '; ' . WC_Data_Store::load( 'order' )->get_current_class_name() . ".\n";
} finally {
	remove_filter( 'cart_cat_square_public_config', $config, PHP_INT_MAX ); remove_filter( 'cart_cat_square_public_config', $scope_change, PHP_INT_MAX ); remove_filter( 'cart_cat_square_transport', $network, PHP_INT_MAX ); remove_action( 'woocommerce_new_order', $order_event, PHP_INT_MAX );
	foreach ( $orders as $id ) { $order = wc_get_order( $id ); if ( $order ) { wc_release_stock_for_order( $order ); wp_clear_scheduled_hook( 'cart_cat_expire_order', array( $id ) ); $order->delete( true ); } }
	foreach ( $drafts as $id ) { wp_delete_post( $id, true ); }
	if ( $product ) { $product->delete( true ); }
	if ( $zone ) { foreach ( $zone->get_shipping_methods() as $method ) { delete_option( 'woocommerce_' . $method->id . '_' . $method->instance_id . '_settings' ); } $zone->delete(); WC_Cache_Helper::get_transient_version( 'shipping', true ); }
	if ( false === $old_tax ) { delete_option( 'woocommerce_calc_taxes' ); } else { update_option( 'woocommerce_calc_taxes', $old_tax ); }
	wp_set_current_user( $old_actor ); require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
}
