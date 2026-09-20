<?php
/** Real Addify / Quantity Crab / Product Add-ons tests; disposable WP database only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable localhost fixture only.' ); }
if ( ! defined( 'CART_CAT_PATH' ) ) { require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php'; }

$count = 0;
$assert = static function ( $test, $message ) use ( &$count ) { ++$count; if ( ! $test ) { throw new RuntimeException( $message ); } };
$ok = static function ( $result ) { if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() ); } return $result; };
$old_actor = get_current_user_id();
$old_rules = get_option( 'quantity_crab_rules', false );
$old_price_mode = get_option( 'csp_sel_price_apply', false );
$ids = array(); $users = array(); $suffix = strtolower( wp_generate_password( 10, false ) ); $category_id = 0;
$transport = static function ( $override, $method, $path ) { return '/v2/customers/search' === $path ? array( 'customers' => array() ) : new WP_Error( 'fixture_unexpected', 'Unexpected Square lookup in pricing fixture.' ); };
add_filter( 'cart_cat_square_transport', $transport, 10, 3 );

try {
	foreach ( array( 'cc_ten', 'cc_twenty' ) as $role ) { add_role( $role, $role ); }
	foreach ( array( 'staff', 'ten', 'multi' ) as $kind ) {
		$id = wp_create_user( 'cc-price-' . $kind . '-' . $suffix, wp_generate_password( 24 ), 'cc-price-' . $kind . '-' . $suffix . '@example.test' );
		$ok( $id ); $users[ $kind ] = $id; $user = get_userdata( $id ); $user->set_role( 'staff' === $kind ? 'administrator' : 'cc_ten' );
		if ( 'multi' === $kind ) { $user->add_role( 'cc_twenty' ); }
	}
	wp_set_current_user( $users['staff'] );
	$issuer = new ReflectionMethod( Cart_Cat\Customers::class, 'issue_reference' );
	$refs = array( 'ten' => $issuer->invoke( null, $users['ten'], '' ), 'multi' => $issuer->invoke( null, $users['multi'], '' ) );
	$category = wp_insert_term( 'Cart Cat Pricing ' . $suffix, 'product_cat' ); $ok( $category ); $category_id = $category['term_id'];
	$product = new WC_Product_Simple(); $product->set_name( 'Cart Cat Pricing ' . $suffix ); $product->set_status( 'publish' ); $product->set_regular_price( '100' ); $product->set_stock_status( 'instock' ); $product->set_category_ids( array( $category_id ) ); $product->save(); $ids[] = $product->get_id();
	foreach ( array( 'cc_ten' => 10, 'cc_twenty' => 20, 'guest' => 5, 'administrator' => 60 ) as $role => $amount ) {
		update_post_meta( $product->get_id(), '_role_base_price_' . $role, serialize( array( 'discount_type' => 'percentage_decrease', 'discount_value' => $amount ) ) );
	}
	$club_name = 'Configured Club Discount ' . $suffix;
	$club_rule = wp_insert_post( array( 'post_type' => 'csp_rules', 'post_status' => 'publish', 'post_title' => $club_name, 'menu_order' => -1000 ) ); $ids[] = $club_rule;
	update_post_meta( $club_rule, 'csp_applied_on_products', array( $product->get_id() ) );
	update_post_meta( $club_rule, 'rrole_base_price_cc_ten', serialize( array( 'discount_type' => 'percentage_decrease', 'discount_value' => 10 ) ) );
	delete_post_meta( $product->get_id(), '_role_base_price_cc_ten' );
	update_option( 'csp_sel_price_apply', 'sale_price' );
	$case_rules = array( array( 'id' => 'cc-case', 'name' => 'Test Case', 'enabled' => true, 'category_ids' => array( $category_id ), 'include_simple' => true, 'threshold' => 12, 'default_rate' => 8, 'role_rates' => array( 'cc_ten' => 20, 'cc_twenty' => 25, '__guest' => 15, 'administrator' => 60 ) ) );
	update_option( 'quantity_crab_rules', $case_rules );
	$address = array( 'first_name' => 'Synthetic', 'last_name' => 'Buyer', 'country' => 'US', 'state' => 'VA', 'city' => 'Leesburg', 'postcode' => '20175', 'address_1' => '19925 Hogback Mountain Road' );
	$base_input = array( 'customer' => array( 'first_name' => 'Synthetic', 'last_name' => 'Buyer', 'email' => 'cc-price@example.test', 'phone' => '2025550101', 'billing' => $address, 'shipping' => $address ), 'lines' => array( array( 'product_id' => $product->get_id(), 'quantity' => 1 ) ), 'discount' => array( 'type' => 'percent', 'amount' => 0, 'mode' => 'add' ), 'fulfillment' => array( 'type' => 'pickup' ) );
	$quote = static function ( $who, $quantity = 1, $discount = null, $addons = null ) use ( $base_input, $refs, $ok, $assert, $users ) {
		$input = $base_input;
		if ( isset( $refs[ $who ] ) ) { $input['customer']['reference'] = $refs[ $who ]; }
		$input['lines'][0]['quantity'] = $quantity;
		if ( null !== $discount ) { $input['discount'] = $discount; }
		if ( null !== $addons ) { $input['lines'][0]['addons'] = $addons; }
		$q = $ok( Cart_Cat\Quote::calculate( $input ) );
		$assert( $q['can_submit'], 'Pricing quote is ready to submit: ' . wp_json_encode( $q['notices'] ) );
		$assert( get_current_user_id() === $users['staff'], 'Quote retains the staff account.' );
		$assert( wc_format_decimal( array_sum( array_column( $q['automatic_discounts'], 'amount' ) ), 2 ) === $q['totals']['automatic_savings'], 'Named discounts exactly reconcile to the rounded automatic saving.' );
		return array( $q, $input );
	};
	list( $guest, $guest_input ) = $quote( 'guest' );
	$assert( '95.00' === $guest['lines'][0]['total'], 'Anonymous Addify 5% price is used instead of staff 60%.' );
	list( $member, $member_input ) = $quote( 'ten' );
	$assert( '90.00' === $member['lines'][0]['total'] && '10.00' === $member['totals']['automatic_savings'], 'Selected Woo role gets native 10% price.' );
	$assert( array( array( 'kind' => 'role', 'label' => $club_name, 'amount' => '10.00' ) ) === $member['automatic_discounts'], 'The summary identifies the actual configured Addify rule, not the customer role slug.' );
	list( $multi ) = $quote( 'multi' );
	$assert( '90.00' === $multi['lines'][0]['total'], 'Multiple local roles follow Addify first-applicable-role precedence.' );
	list( $below ) = $quote( 'ten', 11 );
	$assert( '990.00' === $below['lines'][0]['total'], 'Case pricing does not apply below threshold.' );
	list( $case, $case_input ) = $quote( 'ten', 12 );
	$assert( '960.00' === $case['lines'][0]['total'], 'Member case tier replaces role percentage without compounding.' );
	$assert( array( array( 'kind' => 'quantity', 'label' => 'Test Case', 'amount' => '240.00' ) ) === $case['automatic_discounts'], 'A stronger Quantity Crab rule receives the full saving without also claiming the replaced role discount.' );
	list( $guest_case, $guest_case_input ) = $quote( 'guest', 12 );
	$assert( '1020.00' === $guest_case['lines'][0]['total'], 'Guest case override is applied despite the authenticated staff actor.' );
	$assert( 'quantity' === $guest_case['automatic_discounts'][0]['kind'] && '180.00' === $guest_case['automatic_discounts'][0]['amount'], 'Guest Quantity Crab savings use the guest tier, not the staff tier.' );
	list( $multi_case ) = $quote( 'multi', 12 );
	$assert( '900.00' === $multi_case['lines'][0]['total'], 'Quantity Crab chooses the strongest effective role tier.' );
	list( $manual_add ) = $quote( 'ten', 1, array( 'type' => 'percent', 'amount' => 10, 'mode' => 'add' ) );
	$assert( '81.00' === $manual_add['lines'][0]['total'] && '9.00' === $manual_add['totals']['discount'], 'Manual percentage adds after automatic role discount.' );
	$assert( $manual_add['automatic_discounts'] === $member['automatic_discounts'], 'Manual discounts remain separate from the named automatic discount amount.' );
	list( $manual_replace ) = $quote( 'ten', 1, array( 'type' => 'percent', 'amount' => 10, 'mode' => 'replace' ) );
	$assert( '90.00' === $manual_replace['lines'][0]['total'] && '0.00' === $manual_replace['totals']['automatic_savings'], 'Replace-role mode replaces the role discount.' );
	$assert( array() === $manual_replace['automatic_discounts'], 'Replace-role mode removes the disabled Addify rule from the summary.' );
	list( $replace_case ) = $quote( 'ten', 12, array( 'type' => 'percent', 'amount' => 5, 'mode' => 'replace' ) );
	$assert( '912.00' === $replace_case['lines'][0]['total'], 'Replace-role retains Quantity Crab then applies the manual discount.' );
	$assert( array( array( 'kind' => 'quantity', 'label' => 'Test Case', 'amount' => '240.00' ) ) === $replace_case['automatic_discounts'] && '48.00' === $replace_case['totals']['discount'], 'Replace-role keeps the case rule name and separates the additional manual amount.' );
	list( $fixed ) = $quote( 'ten', 1, array( 'type' => 'fixed', 'amount' => 10, 'mode' => 'add' ) );
	$assert( '80.00' === $fixed['lines'][0]['total'], 'Fixed order discount follows automatic pricing.' );
	$assert( $member['hash'] === $ok( Cart_Cat\Quote::calculate( $member_input, true ) )['hash'] && $case['hash'] === $ok( Cart_Cat\Quote::calculate( $case_input, true ) )['hash'], 'Fresh repeat preserves both member and case quote hashes.' );

	// Compare the real ordinary Woo cart callbacks with the prepared staff quote.
	$ordinary = static function ( $user_id, $quantity, $additional_lines = array() ) use ( $product, $assert, $ok ) {
		global $wp_filter, $wp_actions;
		$old_wc = array( WC()->cart, WC()->customer, WC()->session, WC()->shipping ); $old_user = get_current_user_id(); $old_actions = $wp_actions;
		$hooks = array(); foreach ( $wp_filter as $tag => $hook ) { $hooks[ $tag ] = clone $hook; }
		try {
			wp_set_current_user( $user_id );
			foreach ( $wp_filter as $tag => $hook ) { foreach ( $hook->callbacks as $priority => $callbacks ) { foreach ( $callbacks as $entry ) {
				$fn = $entry['function']; $owner = is_array( $fn ) ? $fn[0] : null;
				if ( is_object( $owner ) && ( $owner instanceof WC_Cart || $owner instanceof WC_Cart_Session || ( 'woocommerce_before_calculate_totals' === $tag && $owner instanceof AF_C_S_P_Main ) ) ) { remove_filter( $tag, $fn, $priority ); }
			} } }
			WC()->session = new Cart_Cat\Session(); WC()->customer = new WC_Customer( $user_id ); WC()->shipping = new WC_Shipping();
			add_filter( 'woocommerce_cart_session_initialize', '__return_false', PHP_INT_MAX );
			WC()->cart = new Cart_Cat\Cart(); remove_action( 'woocommerce_add_to_cart', array( WC()->cart, 'calculate_totals' ), 20 );
			// Refresh Addify's rule reader after fixture setup; this is the unmodified vendor callback.
			new AF_C_S_P_Main();
			$key = $ok( Cart_Cat\Catalog::add_to_cart( WC()->cart, array( 'product_id' => $product->get_id(), 'quantity' => $quantity ) ) );
			foreach ( $additional_lines as $line ) { $ok( Cart_Cat\Catalog::add_to_cart( WC()->cart, $line ) ); }
			$wp_actions['woocommerce_before_calculate_totals'] = 0;
			WC()->cart->calculate_totals();
			return wc_format_decimal( array_sum( array_column( WC()->cart->get_cart(), 'line_total' ) ), 2 );
		} finally { list( WC()->cart, WC()->customer, WC()->session, WC()->shipping ) = $old_wc; wp_set_current_user( $old_user ); $wp_filter = $hooks; $wp_actions = $old_actions; }
	};
	$assert( $ordinary( $users['ten'], 1 ) === $member['lines'][0]['total'], 'Member quote equals ordinary checkout product total.' );
	$assert( $ordinary( 0, 1 ) === $guest['lines'][0]['total'], 'Guest quote equals ordinary checkout product total.' );
	$assert( $ordinary( $users['ten'], 12 ) === $case['lines'][0]['total'], 'Member case quote equals ordinary checkout product total.' );
	$assert( $ordinary( 0, 12 ) === $guest_case['lines'][0]['total'], 'Guest case quote equals ordinary checkout product total.' );
	$weaker_rules = $case_rules; $weaker_rules[0]['role_rates']['cc_ten'] = 5; update_option( 'quantity_crab_rules', $weaker_rules );
	list( $better_role ) = $quote( 'ten', 12 );
	$assert( '1080.00' === $better_role['lines'][0]['total'] && array( array( 'kind' => 'role', 'label' => $club_name, 'amount' => '120.00' ) ) === $better_role['automatic_discounts'], 'A weaker case tier keeps the better Addify price and its actual rule label.' );
	$assert( $ordinary( $users['ten'], 12 ) === $better_role['lines'][0]['total'], 'Better-existing role pricing matches regular checkout.' );
	$weaker_rules[0]['role_rates']['cc_ten'] = 10; update_option( 'quantity_crab_rules', $weaker_rules );
	list( $equal_role ) = $quote( 'ten', 12 );
	$assert( $equal_role['automatic_discounts'] === $better_role['automatic_discounts'], 'An equal case tier does not claim a second discount that it never applied.' );
	update_option( 'quantity_crab_rules', $case_rules );
	$other = new WC_Product_Simple(); $other->set_name( 'Cart Cat Role Only ' . $suffix ); $other->set_status( 'publish' ); $other->set_regular_price( '100' ); $other->save(); $ids[] = $other->get_id();
	update_post_meta( $club_rule, 'csp_applied_on_products', array( $product->get_id(), $other->get_id() ) );
	$mixed_input = $base_input; $mixed_input['customer']['reference'] = $refs['ten']; $mixed_input['lines'][0]['quantity'] = 12; $mixed_input['lines'][] = array( 'product_id' => $other->get_id(), 'quantity' => 1 );
	$mixed = $ok( Cart_Cat\Quote::calculate( $mixed_input ) );
	$assert( array( array( 'kind' => 'quantity', 'label' => 'Test Case', 'amount' => '240.00' ), array( 'kind' => 'role', 'label' => $club_name, 'amount' => '10.00' ) ) === $mixed['automatic_discounts'] && '250.00' === $mixed['totals']['automatic_savings'], 'Mixed eligible and excluded products split their actual case and role savings without double counting.' );
	$assert( $ordinary( $users['ten'], 12, array( array( 'product_id' => $other->get_id(), 'quantity' => 1 ) ) ) === wc_format_decimal( array_sum( array_column( $mixed['lines'], 'total' ) ), 2 ), 'Mixed case and role pricing matches the ordinary checkout total.' );
	$other->set_category_ids( array( $category_id ) ); $other->save();
	$mixed_input['lines'][0]['quantity'] = 6; $mixed_input['lines'][1]['quantity'] = 6; $mixed = $ok( Cart_Cat\Quote::calculate( $mixed_input ) );
	$assert( array( array( 'kind' => 'quantity', 'label' => 'Test Case', 'amount' => '240.00' ) ) === $mixed['automatic_discounts'], 'Native Quantity Crab counts the case threshold across eligible product lines and groups its savings once.' );
	$assert( $ordinary( $users['ten'], 6, array( array( 'product_id' => $other->get_id(), 'quantity' => 6 ) ) ) === wc_format_decimal( array_sum( array_column( $mixed['lines'], 'total' ) ), 2 ), 'A mixed-product case agrees with ordinary checkout.' );
	$legacy_review = $member; unset( $legacy_review['automatic_discounts'] ); $legacy = new WC_Order(); $legacy->update_meta_data( '_cart_cat_review', $legacy_review ); $legacy->update_meta_data( '_cart_cat_role_discount_names', array( $club_name ) );
	$review_method = new ReflectionMethod( Cart_Cat\Orders::class, 'review' ); $restored_review = $review_method->invoke( null, $legacy );
	$assert( $member['automatic_discounts'] === $restored_review['automatic_discounts'], 'Older prepared snapshots recover the recorded Addify name without recalculating their amounts.' );

	// Native Product Add-ons data must survive role and case price reconstruction.
	$addon = array( 'id' => 777, 'name' => 'Gift note', 'type' => 'custom_text', 'required' => 1, 'price' => 5, 'adjust_price' => 1, 'price_type' => 'quantity_based', 'restrictions' => 0, 'restrictions_type' => 'any_text', 'display' => 'select', 'options' => array(), 'position' => 0 );
	update_post_meta( $product->get_id(), '_product_addons', array( $addon ) );
	$config = $ok( Cart_Cat\Catalog::configuration( $product->get_id() ) ); $addons = array( $config['addons'][0]['key'] => 'Happy birthday' );
	list( $addon_quote, $addon_input ) = $quote( 'ten', 1, null, $addons );
	$assert( '95.00' === $addon_quote['lines'][0]['total'] && '105.00' === $addon_quote['totals']['subtotal'], 'Role discount preserves a quantity-priced add-on.' );
	list( $addon_case, $addon_case_input ) = $quote( 'ten', 12, null, $addons );
	$assert( '1020.00' === $addon_case['lines'][0]['total'], 'Case discount preserves the full five-dollar per-bottle add-on.' );
	$assert( $addon_quote['hash'] === $ok( Cart_Cat\Quote::calculate( $addon_input, true ) )['hash'] && $addon_case['hash'] === $ok( Cart_Cat\Quote::calculate( $addon_case_input, true ) )['hash'], 'Repeated add-on quotes do not compound or erase modifiers.' );
	$addon['price'] = 12; $addon['price_type'] = 'flat_fee'; update_post_meta( $product->get_id(), '_product_addons', array( $addon ) );
	list( $flat ) = $quote( 'ten', 2, null, $addons );
	$assert( '192.00' === $flat['lines'][0]['total'], 'Flat add-on is charged once for the product line.' );
	$addon['price'] = 10; $addon['price_type'] = 'percentage_based'; update_post_meta( $product->get_id(), '_product_addons', array( $addon ) );
	list( $percent ) = $quote( 'ten', 1, null, $addons );
	$assert( '99.00' === $percent['lines'][0]['total'], 'Percentage add-on uses the effective role product price.' );
	list( $percent_case ) = $quote( 'ten', 12, null, $addons );
	$assert( '1068.00' === $percent_case['lines'][0]['total'] && array( array( 'kind' => 'role', 'label' => $club_name, 'amount' => '12.00' ), array( 'kind' => 'quantity', 'label' => 'Test Case', 'amount' => '240.00' ) ) === $percent_case['automatic_discounts'], 'Case pricing preserves the discounted percentage add-on and assigns its remaining role saving separately.' );
	delete_post_meta( $product->get_id(), '_product_addons' );
	$product->set_sale_price( '80' ); $product->save();
	list( $sale_case ) = $quote( 'ten', 12 );
	$assert( '768.00' === $sale_case['lines'][0]['total'] && '192.00' === $sale_case['automatic_discounts'][0]['amount'], 'Native case pricing and its named saving use the active catalog sale price.' );
	$assert( $ordinary( $users['ten'], 12 ) === $sale_case['lines'][0]['total'], 'Catalog sale plus case pricing matches ordinary checkout.' );
	echo 'Cart Cat pricing runtime passed: ' . $count . " assertions.\n";
} finally {
	remove_filter( 'cart_cat_square_transport', $transport, 10 );
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
	if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	foreach ( array( 'cc_ten', 'cc_twenty' ) as $role ) { remove_role( $role ); }
	if ( $category_id ) { wp_delete_term( $category_id, 'product_cat' ); }
	if ( false === $old_rules ) { delete_option( 'quantity_crab_rules' ); } else { update_option( 'quantity_crab_rules', $old_rules ); }
	if ( false === $old_price_mode ) { delete_option( 'csp_sel_price_apply' ); } else { update_option( 'csp_sel_price_apply', $old_price_mode ); }
	wp_set_current_user( $old_actor );
}
