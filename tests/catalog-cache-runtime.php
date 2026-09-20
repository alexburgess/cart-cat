<?php
/** Disposable Woo product cache fixtures; no orders, customer data, emails or payments. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable local Woo runtime only.' ); }
if ( ! defined( 'CART_CAT_PATH' ) ) { require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php'; }
use Cart_Cat\Catalog;
$count = 0; $products = array(); $terms = array(); $user = 0; $actor = get_current_user_id(); $suffix = wp_generate_password( 8, false );
$assert = static function ( $okay, $message ) use ( &$count ) { ++$count; if ( ! $okay ) { throw new RuntimeException( $message ); } };
$make = static function ( $name, $type = 'simple', $price = '42' ) use ( &$products, $suffix ) {
	$product = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple(); $product->set_name( $name . ' ' . $suffix ); $product->set_status( 'publish' ); $product->set_regular_price( $price ); $product->save(); $products[] = $product->get_id(); return $product;
};
$all = static function () {
	$rows = array(); $page = 0; $revision = '';
	do { $result = Catalog::index( $page, $revision ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } $rows += array_column( $result['products'], null, 'id' ); $revision = $result['revision']; $page = $result['next_page']; } while ( null !== $page );
	return array( $rows, $revision, $result );
};
try {
	Catalog::register();
	$simple = $make( 'Cache Rosé' ); $simple->set_catalog_visibility( 'hidden' ); $simple->set_sku( 'CACHE-' . $suffix ); $simple->save();
	$ticket = $make( 'Cache Ticket' ); update_post_meta( $ticket->get_id(), '_tribe_wooticket_for_event', 123456 );
	$draft = $make( 'Cache Draft' ); $draft->set_status( 'draft' ); $draft->save();
	$term = wp_insert_term( 'Cache Wine ' . $suffix, 'product_cat', array( 'slug' => 'cache-wine-' . strtolower( $suffix ) ) ); $terms[] = $term['term_id'];
	$wine = $make( 'Cache Wine', 'variable' ); $attribute = new WC_Product_Attribute(); $attribute->set_name( 'pa_wine-servings' ); $attribute->set_options( array( 'glass', 'bottle-750ml' ) ); $attribute->set_variation( true ); $wine->set_attributes( array( $attribute ) ); $wine->set_category_ids( array( $term['term_id'] ) ); $wine->save();
	$variants = array();
	foreach ( array( 'glass' => '9', 'bottle-750ml' => '46' ) as $choice => $price ) {
		$variation = new WC_Product_Variation(); $variation->set_parent_id( $wine->get_id() ); $variation->set_status( 'publish' ); $variation->set_attributes( array( 'pa_wine-servings' => $choice ) ); $variation->set_regular_price( $price ); $variation->set_sku( strtoupper( $choice ) . '-' . $suffix ); $variation->save(); $products[] = $variation->get_id(); $variants[ $choice ] = $variation;
	}
	WC_Product_Variable::sync( $wine->get_id() ); wc_delete_product_transients( $wine->get_id() );
	$filtered_price = static function () { return '0.01'; }; add_filter( 'woocommerce_product_get_price', $filtered_price ); add_filter( 'woocommerce_product_variation_get_price', $filtered_price );
	$start = microtime( true ); $queries = get_num_queries(); list( $rows, $revision ) = $all(); $cold = array( 'ms' => round( ( microtime( true ) - $start ) * 1000, 2 ), 'queries' => get_num_queries() - $queries );
	$start = microtime( true ); $queries = get_num_queries(); list( $warm ) = $all(); $hot = array( 'ms' => round( ( microtime( true ) - $start ) * 1000, 2 ), 'queries' => get_num_queries() - $queries );
	remove_filter( 'woocommerce_product_get_price', $filtered_price ); remove_filter( 'woocommerce_product_variation_get_price', $filtered_price );
	$assert( isset( $rows[ $simple->get_id() ] ) && '42' === $rows[ $simple->get_id() ]['price'], 'Hidden product index stores catalog edit price, never filtered account pricing.' );
	$assert( ! isset( $rows[ $ticket->get_id() ] ) && ! isset( $rows[ $draft->get_id() ] ), 'Tickets and unpublished products are excluded.' );
	$assert( '46' === $rows[ $wine->get_id() ]['price'] && str_contains( $rows[ $wine->get_id() ]['search_terms'], 'BOTTLE-750ML-' ) && ! str_contains( $rows[ $wine->get_id() ]['search_terms'], 'GLASS-' ), 'Variable previews and SKU lookup use only allowed bottle variations.' );
	$assert( $rows === $warm && $hot['queries'] < $cold['queries'], 'Warm catalog rows are identical and avoid product hydration queries.' );
	$allowed = array( 'id', 'name', 'sku', 'type', 'price', 'stock_status', 'search_terms' );
	foreach ( $rows as $row ) { $assert( ! array_diff( array_keys( $row ), $allowed ), 'Cached rows contain catalog hints only.' ); }
	$assert( true === Catalog::index( 0, $revision, true )['not_modified'], 'Unchanged generations support a lightweight client refresh.' );
	update_post_meta( $simple->get_id(), '_price', '48' ); clean_post_cache( $simple->get_id() );
	list( $changed, $new_revision ) = $all(); $assert( $new_revision !== $revision && '48' === $changed[ $simple->get_id() ]['price'], 'Direct price metadata changes invalidate cached hints.' );
	$assert( is_wp_error( Catalog::index( 1, $revision ) ), 'A prior generation cannot be mixed into subsequent pages.' );
	$variants['bottle-750ml']->set_stock_status( 'outofstock' ); $variants['bottle-750ml']->save(); list( $changed ) = $all();
	$assert( 'outofstock' === $changed[ $wine->get_id() ]['stock_status'], 'Variation stock changes invalidate parent availability hints.' );
	$assert( false === Catalog::configuration( $wine->get_id() )['variations'][0]['in_stock'], 'Product configuration reads fresh variation stock.' );
	update_post_meta( $variants['bottle-750ml']->get_id(), '_tribe_wooticket_for_event', 123456 ); list( $changed ) = $all();
	$assert( ! isset( $changed[ $wine->get_id() ] ) && is_wp_error( Catalog::configuration( $wine->get_id() ) ), 'New variation ticket association removes a bottle-only wine from both index and fresh configuration.' );
	delete_post_meta( $variants['bottle-750ml']->get_id(), '_tribe_wooticket_for_event' ); update_post_meta( $wine->get_id(), '_tribe_wooticket_for_event', 123456 ); list( $changed ) = $all();
	$assert( ! isset( $changed[ $wine->get_id() ] ) && is_wp_error( Catalog::configuration( $variants['bottle-750ml']->get_id() ) ), 'Parent ticket association rejects direct variation configuration and cached parent hints.' );
	delete_post_meta( $wine->get_id(), '_tribe_wooticket_for_event' ); $wine->set_category_ids( array() ); $wine->save(); list( $changed, $new_revision ) = $all();
	$assert( isset( $changed[ $wine->get_id() ] ) && '9' === $changed[ $wine->get_id() ]['price'] && str_contains( $changed[ $wine->get_id() ]['search_terms'], 'GLASS-' ), 'Category changes invalidate wine-only Glass exclusions while preserving non-wine merchandise.' );
	$overflow = static function ( $posts, $query ) use ( $wine ) { return 'product' === $query->get( 'post_type' ) && 750 === $query->get( 'offset' ) ? array_fill( 0, 251, $wine->get_id() ) : $posts; };
	add_filter( 'posts_pre_query', $overflow, 10, 2 );
	try { $last = Catalog::index( Catalog::INDEX_MAX_PAGES - 1, $new_revision ); $assert( ! is_wp_error( $last ) && false === $last['complete'] && null === $last['next_page'] && 250 === count( $last['products'] ), 'A catalog larger than 1000 parents stops at its fixed page cap and advertises incomplete results.' ); }
	finally { remove_filter( 'posts_pre_query', $overflow, 10 ); }
	update_post_meta( $simple->get_id(), '_product_addons', array( array( 'name' => 'Recipient', 'type' => 'custom_text', 'required' => 1, 'position' => 0, 'field_name' => 'recipient', 'restrictions' => 0, 'options' => array() ) ) );
	$config = Catalog::configuration( $simple->get_id() ); $assert( is_wp_error( $config ) || ! empty( $config['addons'] ), 'Cached hints cannot bypass newly required product add-ons.' );
	$simple->set_status( 'draft' ); $simple->save(); list( $changed ) = $all(); $assert( ! isset( $changed[ $simple->get_id() ] ) && is_wp_error( Catalog::configuration( $simple->get_id() ) ), 'Unpublishing invalidates the index and fresh configuration.' );
	$assert( is_wp_error( Catalog::index( -1 ) ) && is_wp_error( Catalog::index( Catalog::INDEX_MAX_PAGES ) ), 'Index page bounds are enforced.' );
	$server = rest_get_server(); $request = new WP_REST_Request( 'GET', '/cart-cat/v1/products/index' ); wp_set_current_user( 0 );
	$assert( 403 === $server->dispatch( $request )->get_status(), 'Anonymous callers cannot download the product index.' );
	$user = wp_create_user( 'cc-cache-' . $suffix, wp_generate_password( 24 ), 'cc-cache-' . $suffix . '@example.test' ); get_userdata( $user )->set_role( 'administrator' ); wp_set_current_user( $user );
	$assert( 403 === $server->dispatch( $request )->get_status(), 'Staff product index still requires the REST nonce.' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); $response = $server->dispatch( $request );
	$assert( 200 === $response->get_status() && 'private, no-store' === $response->get_headers()['Cache-Control'], 'Authenticated staff index remains private and noncacheable by browsers/proxies.' );
	get_userdata( $user )->set_role( 'customer' ); wp_set_current_user( 0 ); wp_set_current_user( $user ); $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$assert( 403 === $server->dispatch( $request )->get_status(), 'A signed-in customer with a valid nonce still cannot access the staff catalog.' );
	echo 'Cart Cat catalog cache runtime: ' . $count . ' assertions passed. Cold=' . wp_json_encode( $cold ) . ' warm=' . wp_json_encode( $hot ) . "\n";
} finally {
	wp_set_current_user( $actor );
	foreach ( array_reverse( $products ) as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
	foreach ( $terms as $id ) { wp_delete_term( $id, 'product_cat' ); }
	if ( $user && ! is_wp_error( $user ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user ); }
	Catalog::flush_index_invalidation();
}
