<?php
/** Run via wp eval-file on the disposable Cart Cat runtime only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'This fixture is restricted to a disposable local Woo runtime.' );
}
if ( ! defined( 'CART_CAT_PATH' ) ) { require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php'; }

if ( ! class_exists( 'STW_Square_Role_Reconciler' ) ) {
	class STW_Square_Role_Reconciler {
		public static function status() { return array( 'enabled' => true, 'square_token_configured' => true, 'target_site' => true, 'managed_group_roles' => array( 'test-club-group' => 'cart_cat_member' ) ); }
		public static function planRoleChanges( $roles, $groups ) { return array( 'desired_roles' => in_array( 'test-club-group', $groups, true ) ? array( 'cart_cat_member', 'administrator' ) : array() ); }
	}
}

$assertions = 0;
$assert = static function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$products = array();
$terms = array();
$users = array();
$old_actor = get_current_user_id();
$old_options = get_option( 'oncloudwine_settings', false );
$old_cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
$suffix = strtolower( wp_generate_password( 10, false ) );
$local_email = 'cartcat-local-' . $suffix . '@example.test';
$guest_email = 'cartcat-guest-' . $suffix . '@example.test';
$fail_square = false;
$duplicate = false;
$records = array(
	'cc-square-local' => array( 'id' => 'cc-square-local', 'given_name' => 'SquareName', 'family_name' => 'Member', 'email_address' => $local_email, 'phone_number' => '+15551234567', 'group_ids' => array( 'test-club-group', 'unmanaged-admin' ), 'address' => array( 'address_line_1' => 'Square Street', 'locality' => 'Square City', 'country' => 'US', 'administrative_district_level_1' => 'VA', 'postal_code' => '20175' ) ),
	'cc-square-guest' => array( 'id' => 'cc-square-guest', 'given_name' => 'Guest', 'family_name' => 'Member', 'email_address' => $guest_email, 'phone_number' => '+15557654321', 'group_ids' => array( 'test-club-group', 'unmanaged-admin' ) ),
);
$transport = static function ( $override, $method, $path, $body ) use ( &$records, &$fail_square, &$duplicate ) {
	if ( $fail_square ) { return new WP_Error( 'fixture_unavailable', 'Fixture outage' ); }
	if ( '/v2/customers/search' === $path ) {
		$filters = $body['query']['filter'];
		$filter = reset( $filters );
		$term = strtolower( (string) reset( $filter ) );
		$hits = array_values( array_filter( $records, static function ( $record ) use ( $term ) { return false !== strpos( strtolower( $record['email_address'] ), $term ); } ) );
		if ( $duplicate && count( $hits ) === 1 ) { $copy = $hits[0]; $copy['id'] = 'cc-square-duplicate'; $hits[] = $copy; }
		return array( 'customers' => $hits );
	}
	$id = rawurldecode( substr( $path, strlen( '/v2/customers/' ) ) );
	return isset( $records[ $id ] ) ? array( 'customer' => $records[ $id ] ) : new WP_Error( 'fixture_unknown', 'Unexpected fixture request' );
};
add_filter( 'cart_cat_square_transport', $transport, 10, 4 );

try {
	add_role( 'cart_cat_member', 'Cart Cat Test Member' );
	$settings = is_array( $old_options ) ? $old_options : array();
	$settings['club_roles']['cart_cat_member'] = array( 'label' => 'Test Club' );
	update_option( 'oncloudwine_settings', $settings );
	foreach ( array( 'staff', 'other', 'customer' ) as $kind ) {
		$id = wp_create_user( 'cc-' . $kind . '-' . $suffix, wp_generate_password( 24 ), 'customer' === $kind ? $local_email : 'cc-' . $kind . '-' . $suffix . '@example.test' );
		$assert( ! is_wp_error( $id ), 'Fixture user creates successfully.' );
		$users[ $kind ] = $id;
		$user = get_userdata( $id );
		$user->set_role( 'customer' === $kind ? 'customer' : 'administrator' );
	}
	$customer = get_userdata( $users['customer'] );
	$customer->add_role( 'cart_cat_member' );
	update_user_meta( $customer->ID, 'oncloudwine_cart_cat_member_status', 'inactive' );
	update_user_meta( $customer->ID, 'billing_first_name', 'WooName' );
	update_user_meta( $customer->ID, 'billing_last_name', 'Member' );
	update_user_meta( $customer->ID, 'billing_address_1', 'Woo Street' );
	update_user_meta( $customer->ID, 'shipping_city', 'Woo Ship City' );
	update_user_meta( $customer->ID, 'shipping_email', 'Woo-Recipient@example.test' );
	update_user_meta( $customer->ID, 'shipping_phone', '+1 (202) 555-0104' );
	wp_set_current_user( $users['staff'] );

	$search = Cart_Cat\Customers::search( $local_email );
	$assert( ! is_wp_error( $search ) && count( $search['customers'] ) >= 1, 'Woo and Square are searched.' );
	$assert( false === strpos( wp_json_encode( $search ), 'cc-square-local' ), 'Search never emits a Square customer ID.' );
	$local_ref = $search['customers'][0]['reference'];
	// A plain Woo-only selection independently tests exact-email linking.
	$issuer = new ReflectionMethod( Cart_Cat\Customers::class, 'issue_reference' );
	$local_ref = $issuer->invoke( null, $users['customer'], '' );
	$details = Cart_Cat\Customers::details( $local_ref );
	$assert( ! is_wp_error( $details ) && ! isset( $details['square_id'] ) && $details['square_linked'], 'Details link the exact unique Square profile without exposing its ID.' );
	$assert( 'WooName' === $details['first_name'] && 'Woo Street' === $details['billing']['address_1'] && 'Woo Ship City' === $details['shipping']['city'], 'Woo fields win over Square prefill.' );
	$assert( 'woo-recipient@example.test' === $details['shipping']['email'] && '+1 (202) 555-0104' === $details['shipping']['phone'], 'Existing Woo shipping recipient contact is available for prefill.' );
	$resolved = Cart_Cat\Customers::resolve( array( 'reference' => $local_ref, 'first_name' => 'Order Only', 'roles' => array( 'administrator' ), 'woo_id' => $users['staff'], 'square_id' => 'attacker' ), true );
	$assert( ! is_wp_error( $resolved ) && $resolved['woo_id'] === $users['customer'] && 'cc-square-local' === $resolved['square_id'], 'Submitted identity IDs are ignored.' );
	$assert( 'Order Only' === $resolved['first_name'] && 'WooName' === get_user_meta( $customer->ID, 'billing_first_name', true ), 'Contact overrides affect this order only.' );
	$recipient = Cart_Cat\Customers::resolve( array( 'reference' => $local_ref, 'shipping' => array( 'email' => ' Recipient@example.test ', 'phone' => '<b>2025550105</b>' ) ) );
	$assert( ! is_wp_error( $recipient ) && 'recipient@example.test' === $recipient['shipping']['email'] && '2025550105' === $recipient['shipping']['phone'], 'Order recipient contact is sanitized separately from purchaser contact.' );
	$assert( $resolved['email'] === $recipient['email'] && 'Woo-Recipient@example.test' === get_user_meta( $customer->ID, 'shipping_email', true ) && '+1 (202) 555-0104' === get_user_meta( $customer->ID, 'shipping_phone', true ), 'Recipient overrides preserve billing email and stored customer profile.' );
	$assert( in_array( 'cart_cat_member', $resolved['roles'], true ) && ! in_array( 'administrator', $resolved['roles'], true ) && 'square_group' === $resolved['membership_source'], 'Only the exact managed Square group grants the inactive local customer a current club role.' );
	$assert( get_current_user_id() === $users['staff'], 'Customer resolution preserves staff authentication.' );
	wp_set_current_user( $users['other'] );
	$assert( is_wp_error( Cart_Cat\Customers::details( $local_ref ) ), 'A different staff actor cannot use a customer reference.' );
	wp_set_current_user( $users['staff'] );
	$_COOKIE[ LOGGED_IN_COOKIE ] = 'fixture|9999999999|another-login|signature';
	$assert( is_wp_error( Cart_Cat\Customers::details( $local_ref ) ), 'A different login session cannot use a customer reference.' );
	if ( null === $old_cookie ) { unset( $_COOKIE[ LOGGED_IN_COOKIE ] ); } else { $_COOKIE[ LOGGED_IN_COOKIE ] = $old_cookie; }
	$guest_search = Cart_Cat\Customers::search( $guest_email );
	$guest_ref = $guest_search['customers'][0]['reference'];
	$guest = Cart_Cat\Customers::resolve( array( 'reference' => $guest_ref ), true );
	$assert( $guest['is_guest'] && 0 === $guest['woo_id'] && array( 'cart_cat_member' ) === $guest['roles'], 'Selected Square-only member receives role pricing without a Woo account.' );
	$new_guest = Cart_Cat\Customers::resolve( array( 'email' => $guest_email, 'roles' => array( 'administrator' ), 'square_id' => 'cc-square-guest' ) );
	$assert( $new_guest['is_guest'] && array() === $new_guest['roles'] && '' === $new_guest['square_id'], 'Unselected typed email cannot inject a customer identity or membership.' );
	$fail_square = true;
	$assert( is_wp_error( Cart_Cat\Customers::resolve( array( 'reference' => $guest_ref ), true ) ), 'Fresh Square failure blocks use of a previously selected profile.' );
	$fail_square = false;
	$duplicate = true;
	$ambiguous = Cart_Cat\Customers::resolve( array( 'reference' => $local_ref ), true );
	$assert( ! is_wp_error( $ambiguous ) && '' === $ambiguous['square_id'] && 'ambiguous' === $ambiguous['membership_status'], 'Duplicate exact-email profiles do not silently link a card owner or grant roles.' );
	$duplicate = false;
	update_user_meta( $customer->ID, 'oncloudwine_cart_cat_member_status', 'active' );
	$active = Cart_Cat\Customers::resolve( array( 'reference' => $local_ref ), true );
	$assert( 'wordpress' === $active['membership_source'], 'Current active local role takes precedence over Square groups.' );

	$make_product = static function ( $name, $price = '10', $type = 'simple' ) use ( &$products, $suffix ) {
		$product = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();
		$product->set_name( $name . ' ' . $suffix );
		$product->set_status( 'publish' );
		if ( 'simple' === $type ) { $product->set_regular_price( $price ); }
		$product->save();
		$products[] = $product->get_id();
		return $product;
	};
	$simple = $make_product( 'Cart Cat Stock Test' );
	$simple->set_manage_stock( true ); $simple->set_stock_quantity( 3 ); $simple->save();
	$ticket = $make_product( 'Cart Cat Ticket Test' );
	update_post_meta( $ticket->get_id(), '_tribe_wooticket_for_event', 999999 );
	$gift = $make_product( 'Cart Cat Gift' );
	$gift->set_slug( 'gift-membership' ); $gift->save();
	$addon_definitions = array();
	foreach ( array( 'Recipients Name', 'Recipients Phone Number', 'Recipients Email Address' ) as $index => $name ) {
		$addon_definitions[] = array( 'id' => 101 + $index, 'name' => $name, 'type' => 'custom_text', 'required' => 1, 'price' => 0, 'adjust_price' => 0, 'price_type' => 'flat_fee', 'restrictions' => 0, 'restrictions_type' => 'any_text', 'display' => 'select', 'options' => array(), 'position' => $index );
	}
	update_post_meta( $gift->get_id(), '_product_addons', $addon_definitions );
	$configuration = Cart_Cat\Catalog::configuration( $gift->get_id() );
	$assert( count( $configuration['addons'] ) === 3 && 1 === $configuration['quantity_per_line'], 'Gift config exposes three native required fields and a one-gift line.' );
	$assert( is_wp_error( Cart_Cat\Catalog::configuration( $ticket->get_id() ) ), 'Ticket configuration is rejected server-side.' );
	$search = Cart_Cat\Catalog::search( $suffix );
	$assert( ! in_array( $ticket->get_id(), array_column( $search['products'], 'id' ), true ), 'Ticket products never appear in catalog search.' );
	$values = array_combine( array_column( $configuration['addons'], 'key' ), array( "O'Brien Recipient", '5551234567', 'recipient@example.test' ) );
	$input = array( 'discount' => array( 'mode' => 'add' ) );
	$cart_customer = array( 'woo_id' => 0, 'roles' => array(), 'is_guest' => true, 'billing' => array(), 'shipping' => array() );
	$run = static function () use ( $assert, $simple, $gift, $ticket, $values ) {
		return Cart_Cat\Context::run( array( 'woo_id' => 0, 'roles' => array(), 'is_guest' => true, 'billing' => array(), 'shipping' => array() ), array(), static function ( $cart ) use ( $assert, $simple, $gift, $ticket, $values ) {
			$_POST = array( 'staff_form' => 'preserve me' );
			$_GET['order_again'] = 999;
			$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $ticket->get_id(), 'quantity' => 1 ) ) ), 'Direct ticket IDs are rejected on add.' );
			$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $simple->get_id(), 'quantity' => 4 ) ) ), 'Stock is enforced.' );
			$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $gift->get_id(), 'quantity' => 1, 'addons' => array() ) ) ), 'Missing gift recipient data blocks adding.' );
			$key = Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $gift->get_id(), 'quantity' => 1, 'addons' => $values ) );
			$assert( is_string( $key ), 'Native gift add succeeds.' );
			$assert( array( 'staff_form' => 'preserve me' ) === $_POST && 999 === $_GET['order_again'], 'Product validation restores unrelated request globals.' );
			$line = $cart->get_cart()[ $key ];
			$assert( 3 === count( $line['addons'] ) && "O'Brien Recipient" === $line['addons'][0]['value'], 'Native add-on cart metadata preserves all fields and literal apostrophes.' );
			$item = new WC_Order_Item_Product(); $item->set_product( wc_get_product( $gift->get_id() ) );
			do_action( 'woocommerce_checkout_create_order_line_item', $item, $key, $line, new WC_Order() );
			$assert( "O'Brien Recipient" === $item->get_meta( 'Recipients Name' ) && 'recipient@example.test' === $item->get_meta( 'Recipients Email Address' ), 'Native checkout hook preserves recipient order metadata.' );
			$second = Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $gift->get_id(), 'quantity' => 1, 'addons' => $values ) );
			$assert( is_string( $second ) && $second !== $key && 2 === count( $cart->get_cart() ), 'Separate gifts stay separate deterministic lines.' );
			$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $gift->get_id(), 'quantity' => 1, 'addons' => array_merge( $values, array( 'injected-price' => '-100' ) ) ) ) ), 'Unknown add-on fields cannot inject prices.' );
			return array_keys( $cart->get_cart() );
		} );
	};
	$keys = $run();
	$assert( ! is_wp_error( $keys ), 'Isolated catalog runtime completes.' );
	$assert( $keys === $run(), 'Repeated previews produce identical native cart keys.' );
	$variable = $make_product( 'Cart Cat Variable', '10', 'variable' );
	$attribute = new WC_Product_Attribute(); $attribute->set_name( 'Size' ); $attribute->set_options( array( 'Small', 'Large' ) ); $attribute->set_variation( true );
	$variable->set_attributes( array( $attribute ) ); $variable->save();
	$variation = new WC_Product_Variation(); $variation->set_parent_id( $variable->get_id() ); $variation->set_status( 'publish' ); $variation->set_attributes( array( 'size' => 'Small' ) ); $variation->set_regular_price( '12' ); $variation->save(); $products[] = $variation->get_id();
	$any_variation = new WC_Product_Variation(); $any_variation->set_parent_id( $variable->get_id() ); $any_variation->set_status( 'publish' ); $any_variation->set_attributes( array( 'size' => '' ) ); $any_variation->set_regular_price( '14' ); $any_variation->save(); $products[] = $any_variation->get_id();
	WC_Product_Variable::sync( $variable->get_id() ); wc_delete_product_transients( $variable->get_id() );
	$variable_configuration = Cart_Cat\Catalog::configuration( $variable->get_id() );
	$assert( count( $variable_configuration['variations'] ) === 2 && isset( $variable_configuration['attribute_options']['attribute_size'] ), 'Variable config includes purchasable choices and any-value attribute options.' );
	$result = Cart_Cat\Context::run( $cart_customer, $input, static function ( $cart ) use ( $assert, $variable, $variation, $any_variation, $simple ) {
		$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $variable->get_id() ) ) ), 'Variable parent requires a chosen variation.' );
		$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $simple->get_id(), 'variation_id' => $variation->get_id() ) ) ), 'Variation from another parent is rejected.' );
		$assert( is_string( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $variable->get_id(), 'variation_id' => $variation->get_id() ) ) ), 'Explicit valid variation uses native add_to_cart.' );
		$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $variable->get_id(), 'variation_id' => $any_variation->get_id() ) ) ), 'Any-value variation requires an attribute choice.' );
		$assert( is_string( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $variable->get_id(), 'variation_id' => $any_variation->get_id(), 'attributes' => array( 'attribute_size' => 'Large' ) ) ) ), 'Any-value variation accepts a valid native attribute choice.' );
		return true;
	} );
	$assert( true === $result, 'Variation runtime succeeds.' );
	$wine_term = get_term_by( 'slug', 'wine', 'product_cat' );
	if ( ! $wine_term ) {
		$created_term = wp_insert_term( 'Wine', 'product_cat', array( 'slug' => 'wine' ) );
		$assert( ! is_wp_error( $created_term ), 'Wine category fixture creates successfully.' );
		$terms[] = $created_term['term_id'];
		$wine_term = get_term( $created_term['term_id'], 'product_cat' );
	}
	$child_term = wp_insert_term( 'Cart Cat Category ' . $suffix, 'product_cat', array( 'parent' => $wine_term->term_id ) );
	$assert( ! is_wp_error( $child_term ), 'Wine descendant fixture creates successfully.' );
	$terms[] = $child_term['term_id'];
	$make_wine = static function ( $name, $attribute_name, $choices, $is_wine = true ) use ( $make_product, &$products, $child_term ) {
		$parent = $make_product( $name, '10', 'variable' );
		$attribute = new WC_Product_Attribute(); $attribute->set_name( $attribute_name ); $attribute->set_options( array( 'glass', 'bottle-750ml' ) ); $attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		if ( $is_wine ) { $parent->set_category_ids( array( $child_term['term_id'] ) ); }
		$parent->set_catalog_visibility( 'hidden' );
		$parent->save();
		$children = array();
		foreach ( $choices as $choice => $price ) {
			$child = new WC_Product_Variation(); $child->set_parent_id( $parent->get_id() ); $child->set_status( 'publish' ); $child->set_attributes( array( $attribute_name => $choice ) ); $child->set_regular_price( (string) $price ); $child->save();
			$products[] = $child->get_id(); $children[ $choice ] = $child->get_id();
		}
		WC_Product_Variable::sync( $parent->get_id() ); wc_delete_product_transients( $parent->get_id() );
		return array( $parent->get_id(), $children );
	};
	list( $wine_id, $wine_variants ) = $make_wine( 'Cart Cat Wine Servings', 'pa_wine-servings', array( 'glass' => 12, 'bottle-750ml' => 40 ) );
	update_post_meta( $wine_id, '_product_addons', array( $addon_definitions[0] ) );
	list( $option_id, $option_variants ) = $make_wine( 'Cart Cat Fallback Wine', 'pa_option', array( 'glass' => 11, '' => 42 ) );
	list( $glass_only_id ) = $make_wine( 'Cart Cat Glass Only', 'pa_wine-servings', array( 'glass' => 10 ) );
	list( $merchandise_id, $merchandise_variants ) = $make_wine( 'Cart Cat Glass Merchandise', 'pa_option', array( 'glass' => 15 ), false );
	$wine_config = Cart_Cat\Catalog::configuration( $wine_id );
	$wine_addons = array( $wine_config['addons'][0]['key'] => 'Bottle Recipient' );
	$assert( array( $wine_variants['bottle-750ml'] ) === array_column( $wine_config['variations'], 'id' ), 'Wine Servings Glass is excluded and the sole bottle remains available for automatic selection.' );
	$assert( 1 === count( $wine_config['addons'] ) && $wine_config['addons'][0]['required'], 'The sole remaining bottle variation still exposes required product add-ons.' );
	$assert( array( 'bottle-750ml' ) === $wine_config['attribute_options']['attribute_pa_wine-servings']['values'], 'Glass is removed from Wine Servings attribute choices.' );
	$assert( is_wp_error( Cart_Cat\Catalog::configuration( $wine_variants['glass'] ) ), 'Direct Glass configuration is rejected.' );
	$option_config = Cart_Cat\Catalog::configuration( $option_id );
	$assert( array( $option_variants[''] ) === array_column( $option_config['variations'], 'id' ) && array( 'bottle-750ml' ) === $option_config['attribute_options']['attribute_pa_option']['values'], 'Fallback Option excludes Glass and offers only allowed wildcard attribute values.' );
	$assert( is_wp_error( Cart_Cat\Catalog::configuration( $glass_only_id ) ), 'A wine with only Glass variations cannot be configured.' );
	$merchandise_config = Cart_Cat\Catalog::configuration( $merchandise_id );
	$assert( array( $merchandise_variants['glass'] ) === array_column( $merchandise_config['variations'], 'id' ) && in_array( 'glass', $merchandise_config['attribute_options']['attribute_pa_option']['values'], true ), 'Non-wine Glass merchandise remains available.' );
	$wine_search = Cart_Cat\Catalog::search( $suffix );
	$search_products = array_column( $wine_search['products'], null, 'id' );
	$assert( isset( $search_products[ $wine_id ] ) && 40.0 === (float) $search_products[ $wine_id ]['price'], 'Hidden wine search displays the allowed bottle price instead of the cheaper Glass price.' );
	$assert( 1 === $search_products[ $option_id ]['available_variation_count'] && false === $search_products[ $option_id ]['requires_configuration'], 'A sole wildcard option with one allowed value is marked ready for automatic selection.' );
	$assert( 1 === $search_products[ $wine_id ]['available_variation_count'] && true === $search_products[ $wine_id ]['requires_configuration'], 'Search still requires configuration for the sole bottle when it has product add-ons.' );
	$assert( ! isset( $search_products[ $glass_only_id ] ) && isset( $search_products[ $merchandise_id ] ), 'Search omits Glass-only wines but retains Glass merchandise.' );
	$assert( count( wc_get_product( $wine_id )->get_children() ) === 2 && 12.0 === (float) wc_get_product( $wine_variants['glass'] )->get_price( 'edit' ), 'Cart Cat leaves the stored Glass variation and price intact.' );
	$result = Cart_Cat\Context::run( $cart_customer, $input, static function ( $cart ) use ( $assert, $wine_id, $wine_variants, $wine_addons, $option_id, $option_variants, $merchandise_id, $merchandise_variants ) {
		$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $wine_variants['glass'] ) ) ), 'Direct Glass variation IDs cannot bypass server validation.' );
		$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $wine_id, 'variation_id' => $wine_variants['glass'] ) ) ), 'Parent plus Glass variation IDs cannot bypass server validation.' );
		$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $option_id, 'variation_id' => $option_variants[''], 'attributes' => array( 'attribute_pa_option' => 'Glass' ) ) ) ), 'Submitted wildcard Glass choices are rejected after normalization.' );
		$assert( is_wp_error( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $wine_id, 'variation_id' => $wine_variants['bottle-750ml'] ) ) ), 'Automatically selected bottle variations cannot bypass required add-ons.' );
		$assert( is_string( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $wine_id, 'variation_id' => $wine_variants['bottle-750ml'], 'addons' => $wine_addons ) ) ), 'The remaining fixed bottle variation still passes native cart validation with required add-ons.' );
		$assert( is_string( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $option_id, 'variation_id' => $option_variants[''], 'attributes' => array( 'attribute_pa_option' => 'bottle-750ml' ) ) ) ), 'A remaining non-Glass wildcard choice still passes native cart validation.' );
		$assert( is_string( Cart_Cat\Catalog::add_to_cart( $cart, array( 'product_id' => $merchandise_id, 'variation_id' => $merchandise_variants['glass'] ) ) ), 'Non-wine Glass merchandise can still be added.' );
		return true;
	} );
	$assert( true === $result, 'Glass exclusion runtime succeeds.' );
	update_post_meta( $variable->get_id(), '_tribe_wooticket_for_event', 999999 );
	$assert( is_wp_error( Cart_Cat\Catalog::configuration( $variation->get_id() ) ), 'Ticket parent also excludes its variations.' );
	$assert( get_current_user_id() === $users['staff'], 'Catalog operations preserve staff identity.' );
	echo 'Cart Cat customer/catalog runtime passed: ' . $assertions . " assertions.\n";
} finally {
	remove_filter( 'cart_cat_square_transport', $transport, 10 );
	foreach ( $products as $id ) { wp_delete_post( $id, true ); }
	foreach ( array_reverse( $terms ) as $id ) { wp_delete_term( $id, 'product_cat' ); }
	if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	remove_role( 'cart_cat_member' );
	if ( false === $old_options ) { delete_option( 'oncloudwine_settings' ); } else { update_option( 'oncloudwine_settings', $old_options ); }
	wp_set_current_user( $old_actor );
	if ( null === $old_cookie ) { unset( $_COOKIE[ LOGGED_IN_COOKIE ] ); } else { $_COOKIE[ LOGGED_IN_COOKIE ] = $old_cookie; }
}
