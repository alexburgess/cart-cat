<?php
/** Synthetic customer search regression; requires an explicitly disposable native fixture. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'CART_CAT_DISPOSABLE_TESTS' ) || ! CART_CAT_DISPOSABLE_TESTS || 0 !== strpos( DB_NAME, 'cart_cat_search_' ) || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Use only the isolated Cart Cat search fixture, never a copied customer database.' );
}
require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php';
use Cart_Cat\Customers;
global $wpdb;
$assertions = 0; $failures = array(); $users = array(); $other_site = 0; $created_table = false;
$old_user = get_current_user_id(); $suffix = strtolower( wp_generate_password( 8, false ) );
$table = $wpdb->prefix . 'square_woo_customers';
$sync_before = get_option( 'sws_customer_sync_state', null );
$assert = static function ( $ok, $message ) use ( &$assertions, &$failures ) { ++$assertions; if ( ! $ok ) { $failures[] = $message; } };
$ok = static function ( $result ) { if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() ); } return $result; };
$make_user = static function ( $login, $first, $last, $display = '', $role = 'customer' ) use ( &$users, $suffix, $ok ) {
	$id = $ok( wp_insert_user( array( 'user_login' => $login . '-' . $suffix, 'user_email' => $login . '-' . $suffix . '@example.test', 'user_pass' => wp_generate_password( 32 ), 'first_name' => $first, 'last_name' => $last, 'display_name' => $display ?: trim( $first . ' ' . $last ), 'role' => $role ) ) );
	$users[] = $id;
	update_user_meta( $id, 'billing_first_name', $first ); update_user_meta( $id, 'billing_last_name', $last );
	return $id;
};
$canonical = array( 'id' => 'fixture-canonical', 'given_name' => 'Rowan & Riley', 'family_name' => 'Redirectfixture', 'email_address' => 'square-search-' . $suffix . '@example.test' );
$mode = 'normal'; $calls = array();
$transport = static function ( $override, $method, $path, $body ) use ( &$mode, &$calls, &$canonical ) {
	$calls[] = array( $method, $path );
	if ( 'POST' === $method && '/v2/customers/bulk-retrieve' === $path ) {
		if ( 'outage' === $mode ) { return new WP_Error( 'fixture_outage', 'Synthetic outage.' ); }
		$responses = array();
		foreach ( $body['customer_ids'] as $id ) {
			$record = $canonical;
			if ( 'invalid' === $mode ) { $record['id'] = '../not-a-customer'; }
			$responses[ 'unrequested' === $mode ? 'not-requested' : $id ] = array( 'customer' => $record );
		}
		return array( 'responses' => $responses );
	}
	if ( 'GET' === $method && '/v2/customers/fixture-canonical' === $path ) { return array( 'customer' => $canonical ); }
	if ( 'POST' === $method && '/v2/customers/search' === $path ) { return array( 'customers' => array() ); }
	throw new RuntimeException( 'Unexpected provider operation: ' . $method . ' ' . $path );
};
$config = static function () { return array( 'ready' => true, 'application_id' => 'fixture-app', 'location_id' => 'fixture-location', 'environment' => 'sandbox', 'currency' => 'USD' ); };
add_filter( 'cart_cat_square_transport', $transport, PHP_INT_MAX, 4 );
add_filter( 'cart_cat_square_public_config', $config, PHP_INT_MAX );
try {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { throw new RuntimeException( 'Fixture refuses to replace an existing customer index.' ); }
	$wpdb->query( "CREATE TABLE `{$table}` (first_name varchar(192),last_name varchar(192),email varchar(255),phone varchar(64),source varchar(16),square_customer_id varchar(192)) " . $wpdb->get_charset_collate() );
	$created_table = true;
	update_option( 'sws_customer_sync_state', array( 'last_synced_at' => gmdate( 'c' ) ) );
	$staff = $make_user( 'cc-search-staff', 'Staff', 'Fixture', '', 'administrator' );
	wp_set_current_user( $staff );
	for ( $i = 0; $i < 45; ++$i ) {
		$make_user( sprintf( 'aa-joseph-%02d', $i ), 'Joseph', 'Decoy' );
		$make_user( sprintf( 'aa-murphy-%02d', $i ), 'Other', 'Murphy' );
	}
	$target = $make_user( 'zz-shared-target', 'Joseph & Janet', 'Murphy' );
	$assert( 90 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'aa-%'" ), 'More than 40 one-term decoys precede the shared account.' );
	foreach ( array( 'Joseph Murphy', 'Janet Murphy', 'murphy JOSEPH' ) as $query ) {
		$result = $ok( Customers::search( $query ) );
		$identity = 1 === count( $result['customers'] ) ? $ok( Customers::identity_for_draft( $result['customers'][0]['reference'] ) ) : array();
		$assert( $target === ( $identity['woo_id'] ?? 0 ), 'Full search finds the shared account: ' . $query );
	}
	$assert( ! $ok( Customers::search( 'Joseph Missingfixture' ) )['customers'], 'Every word must match the same account.' );
	$assert( count( $ok( Customers::search( 'Murphy' ) )['customers'] ) <= Customers::LIMIT, 'The directory result limit still applies after matching.' );
	update_user_meta( $target, 'first_name', 'Janet' ); update_user_meta( $target, 'billing_first_name', 'Janet' );
	$display = $ok( Customers::search( 'Joseph Murphy' ) );
	$assert( 1 === count( $display['customers'] ) && $target === $ok( Customers::identity_for_draft( $display['customers'][0]['reference'] ) )['woo_id'], 'All words can match the display name when billing and profile names differ.' );
	update_user_meta( $target, 'first_name', 'Joseph & Janet' ); update_user_meta( $target, 'billing_first_name', 'Joseph & Janet' );
	update_user_meta( $target, 'billing_phone', '+1 (202) 555-0147' );
	foreach ( array( 'Joseph 2025550147', '2025550147' ) as $query ) {
		$phone = $ok( Customers::search( $query ) );
		$assert( 1 === count( $phone['customers'] ) && $target === $ok( Customers::identity_for_draft( $phone['customers'][0]['reference'] ) )['woo_id'], 'Digit-only phone matching survives formatting: ' . $query );
	}
	$billing = $make_user( 'zz-billing-target', 'Profile', 'Alias', 'Profile Alias' );
	update_user_meta( $billing, 'billing_first_name', 'Camille' ); update_user_meta( $billing, 'billing_last_name', 'Billingfixture' );
	$billing_result = $ok( Customers::search( 'Camille Billingfixture' ) );
	$assert( 1 === count( $billing_result['customers'] ) && $billing === $ok( Customers::identity_for_draft( $billing_result['customers'][0]['reference'] ) )['woo_id'], 'Billing-only names participate before the limit.' );
	$literal = $make_user( 'zz-literal-target', 'Percent%_', 'Casefixture' );
	$make_user( 'zz-literal-decoy', 'PercentOther', 'Casefixture' );
	$literal_result = $ok( Customers::search( 'Percent%_ Casefixture' ) );
	$assert( 1 === count( $literal_result['customers'] ) && $literal === $ok( Customers::identity_for_draft( $literal_result['customers'][0]['reference'] ) )['woo_id'], 'SQL wildcard characters in names remain literal.' );
	$assert( 1 === count( get_users( array( 'include' => array( $staff ) ) ) ), 'Subsequent user queries are unaffected by the search filter.' );
	$interrupt = static function () { throw new RuntimeException( 'Synthetic query interruption.' ); };
	add_action( 'pre_user_query', $interrupt, 20 );
	try { Customers::search( 'Joseph Murphy' ); } catch ( RuntimeException $error ) { $assert( 'Synthetic query interruption.' === $error->getMessage(), 'The interrupted query propagates its original error.' ); }
	finally { remove_action( 'pre_user_query', $interrupt, 20 ); }
	$assert( 1 === count( get_users( array( 'include' => array( $staff ) ) ) ), 'The search filter is removed even when another query callback throws.' );
	if ( is_multisite() ) {
		$other_site = $ok( wpmu_create_blog( 'localhost', '/cc-other-' . $suffix . '/', 'Synthetic other site', $staff ) );
		switch_to_blog( $other_site );
		try { WC_Install::create_tables(); } finally { restore_current_blog(); }
		$outsider = $make_user( 'zz-other-site', 'Joseph & Janet', 'Murphy' );
		add_user_to_blog( $other_site, $outsider, 'customer' ); remove_user_from_blog( $outsider, get_current_blog_id() );
		$scoped = $ok( Customers::search( 'Joseph Murphy' ) );
		$assert( 1 === count( $scoped['customers'] ) && $target === $ok( Customers::identity_for_draft( $scoped['customers'][0]['reference'] ) )['woo_id'], 'Other-site accounts cannot enter the current site directory.' );
	}
	foreach ( array( 'fixture-old-one', 'fixture-old-two' ) as $alias ) {
		$wpdb->insert( $table, array( 'first_name' => 'Rowan & Riley', 'last_name' => 'Redirectfixture', 'email' => $canonical['email_address'], 'phone' => '', 'source' => 'Square', 'square_customer_id' => $alias ) );
	}
	$square_search = new ReflectionMethod( Customers::class, 'square_candidates' );
	foreach ( array( 'Rowan Redirectfixture', 'Riley Redirectfixture' ) as $query ) {
		$candidates = $square_search->invoke( null, $query, explode( ' ', strtolower( $query ) ) );
		$assert( 1 === count( $candidates['customers'] ) && 'fixture-canonical' === $candidates['customers'][0]['id'], 'Canonical merge responses are accepted and deduplicated: ' . $query );
		$result = $ok( Customers::search( $query ) );
		$assert( 1 === count( $result['customers'] ), 'Public search returns the canonical Square-only customer: ' . $query );
		if ( 1 === count( $result['customers'] ) ) {
			$resolved = $ok( Customers::resolve( array( 'reference' => $result['customers'][0]['reference'] ), true ) );
			$assert( 'fixture-canonical' === $resolved['square_id'] && 0 === $resolved['woo_id'], 'Selection uses the freshly verified canonical identity.' );
			$assert( empty( $result['customers'][0]['ambiguous'] ), 'Two aliases for one customer do not manufacture email ambiguity.' );
			$assert( false === strpos( wp_json_encode( $result ), 'fixture-canonical' ), 'Provider IDs stay behind opaque references.' );
		}
	}
	foreach ( array( 'unrequested', 'invalid' ) as $mode ) {
		$rejected = $square_search->invoke( null, 'Rowan Redirectfixture', array( 'rowan', 'redirectfixture' ) );
		$assert( ! $rejected['customers'], 'Unrequested keys and malformed returned IDs are rejected: ' . $mode );
	}
	$mode = 'outage';
	$outage = $square_search->invoke( null, 'Rowan Redirectfixture', array( 'rowan', 'redirectfixture' ) );
	$assert( ! $outage['customers'] && 'unavailable' === $outage['status'], 'Provider failures remain unavailable rather than accepted matches.' );
	$assert( ! array_filter( $calls, static function ( $call ) { return ! in_array( $call, array( array( 'POST', '/v2/customers/bulk-retrieve' ), array( 'POST', '/v2/customers/search' ), array( 'GET', '/v2/customers/fixture-canonical' ) ), true ); } ), 'The fixture performs only customer reads; no payments or customer writes.' );
	$assert( get_current_user_id() === $staff, 'Search and selection preserve staff authentication.' );
} finally {
	remove_filter( 'cart_cat_square_transport', $transport, PHP_INT_MAX ); remove_filter( 'cart_cat_square_public_config', $config, PHP_INT_MAX );
	wp_set_current_user( $old_user );
	if ( $created_table ) { $wpdb->query( "DROP TABLE `{$table}`" ); }
	if ( null === $sync_before ) { delete_option( 'sws_customer_sync_state' ); } else { update_option( 'sws_customer_sync_state', $sync_before ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) { require_once ABSPATH . 'wp-admin/includes/ms.php'; }
	foreach ( $users as $id ) { if ( is_multisite() ) { wpmu_delete_user( $id ); } else { wp_delete_user( $id ); } }
	if ( $other_site ) { wpmu_delete_blog( $other_site, true ); }
}
if ( $failures ) { throw new RuntimeException( count( $failures ) . ' failed search assertions: ' . implode( ' | ', $failures ) ); }
echo 'Cart Cat customer search runtime: ' . $assertions . ' assertions passed; Woo ' . WC_VERSION . '; multisite=' . ( is_multisite() ? 'yes' : 'no' ) . '; ' . WC_Data_Store::load( 'order' )->get_current_class_name() . ".\n";
