<?php
/** Standalone service contracts: no WordPress, database, network, stock, email, or payment. */
namespace {
	if ( PHP_SAPI !== 'cli' || defined( 'ABSPATH' ) ) { throw new \RuntimeException( 'Run this fixture directly with PHP CLI.' ); }
	define( 'ABSPATH', __DIR__ ); define( 'OBJECT', 'OBJECT' );
	$GLOBALS['draft_posts'] = $GLOBALS['draft_options'] = $GLOBALS['draft_refs'] = array();
	$GLOBALS['draft_actor'] = 7; $GLOBALS['draft_allowed'] = true; $GLOBALS['draft_insert_fail'] = false;
	class WP_Error {
		public function __construct( private $code = '', private $message = '', private $data = array() ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	class WP_User { public function __construct( public $ID ) {} }
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function get_current_user_id() { return $GLOBALS['draft_actor']; }
	function get_current_blog_id() { return 1; }
	function current_user_can( $cap ) { return $GLOBALS['draft_allowed']; }
	function wp_get_current_user() { return (object) array( 'display_name' => 'Staff ' . get_current_user_id() ); }
	function wp_get_session_token() { return 'session-' . get_current_user_id(); }
	function wp_salt( $kind ) { return 'draft-contract-salt'; }
	function get_userdata( $id ) { return 55 === $id ? new WP_User( 55 ) : false; }
	function is_multisite() { return false; }
	function get_option( $key, $default = false ) { return $GLOBALS['draft_options'][ $key ] ?? $default; }
	function set_transient( $key, $value, $ttl ) { $GLOBALS['draft_refs'][ $key ] = $value; }
	function get_transient( $key ) { return $GLOBALS['draft_refs'][ $key ] ?? false; }
	function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
	function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : addslashes( $v ); }
	function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
	function clean_post_cache( $id ) {}
	function get_post( $id ) { return isset( $GLOBALS['draft_posts'][ $id ] ) ? clone $GLOBALS['draft_posts'][ $id ] : null; }
	function get_page_by_path( $slug, $kind, $type ) { foreach ( $GLOBALS['draft_posts'] as $post ) { if ( $post->post_name === $slug && $post->post_type === $type ) { return clone $post; } } return null; }
	function wp_insert_post( $values, $error = false ) {
		if ( $GLOBALS['draft_insert_fail'] ) { return new WP_Error( 'fixture_write', 'Synthetic storage failure' ); }
		$values = array_map( 'stripslashes', $values );
		$id = (int) ( $values['ID'] ?? count( $GLOBALS['draft_posts'] ) + 1 );
		$old = isset( $GLOBALS['draft_posts'][ $id ] ) ? (array) $GLOBALS['draft_posts'][ $id ] : array();
		$GLOBALS['draft_posts'][ $id ] = (object) array_merge( $old, $values, array( 'ID' => $id ) );
		return $id;
	}
	function add_action( $hook, $fn ) { $GLOBALS['draft_hooks'][ $hook ][] = $fn; }
	function register_post_type( $name, $args ) { $GLOBALS['draft_type'] = $args; }
	class WP_Query {
		public $posts; public $found_posts; public $max_num_pages;
		public function __construct( $args ) {
			$rows = array_filter( $GLOBALS['draft_posts'], static function ( $p ) use ( $args ) { return $p->post_type === $args['post_type'] && $p->post_status === $args['post_status'] && ( '' === $args['s'] || str_contains( $p->post_title . $p->post_excerpt, $args['s'] ) ); } );
			$this->found_posts = count( $rows ); $this->max_num_pages = (int) ceil( count( $rows ) / $args['posts_per_page'] );
			$this->posts = array_slice( array_reverse( $rows ), ( $args['paged'] - 1 ) * $args['posts_per_page'], $args['posts_per_page'] );
		}
	}
	$wpdb = new class {
		public $busy = false; public $locks = array();
		public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
		public function get_var( $query ) {
			list( $sql, $args ) = $query; $key = $args[0];
			if ( str_contains( $sql, 'GET_LOCK' ) ) { if ( $this->busy || isset( $this->locks[ $key ] ) ) { return '0'; } $this->locks[ $key ] = true; return '1'; }
			unset( $this->locks[ $key ] ); return '1';
		}
	};
}
namespace Cart_Cat {
	class Square { public static $scope = array( 'application_id' => 'fixture', 'location_id' => 'location', 'environment' => 'sandbox', 'currency' => 'USD' ); public static function scope() { return self::$scope; } public static function request() { throw new \RuntimeException( 'Drafts must never call a provider.' ); } }
	class Stock_Hold { public static function config() { return array( 'default_minutes' => 60 ); } }
	class Orders {
		public static $creates = 0; public static $mode = 'success'; public static $orders = array();
		public static function request_key( $request, $scope = '' ) { return hash( 'sha256', ( $scope ?: get_current_user_id() ) . ':' . $request ); }
		public static function get( $id ) { return self::$orders[ $id ] ?? new \WP_Error( 'missing_order', 'Missing order' ); }
		public static function load( $id ) { return new class( $id ) { public function __construct( private $id ) {} public function get_edit_order_url() { return 'https://example.test/wp-admin/order/' . $this->id; } }; }
		public static function create( $input, $hash, $request, $scope = '' ) {
			$key = 'cart_cat_request_' . self::request_key( $request, $scope );
			$record = get_option( $key, false );
			if ( $record ) { return isset( self::$orders[ $record['order_id'] ] ) ? self::$orders[ $record['order_id'] ] : new \WP_Error( 'cart_cat_preparation_review', 'Review interrupted order' ); }
			if ( 'stale' === self::$mode ) { return new \WP_Error( 'cart_cat_stale_quote', 'Refresh totals' ); }
			self::$creates++;
			$GLOBALS['draft_options'][ $key ] = array( 'order_id' => 800 + self::$creates, 'hash' => $hash );
			if ( 'interrupted' === self::$mode ) { return new \WP_Error( 'cart_cat_prepare_failed', 'Order needs review' ); }
			$result = array( 'order_id' => 800 + self::$creates, 'status' => 'pending', 'preparation' => 'ready', 'version' => $hash );
			self::$orders[ $result['order_id'] ] = $result;
			if ( 'saved_then_lost' === self::$mode ) { throw new \RuntimeException( 'Lost response after order save' ); }
			return $result;
		}
	}
}
namespace {
	use Cart_Cat\Drafts; use Cart_Cat\Customers; use Cart_Cat\Orders; use Cart_Cat\Square;
	require __DIR__ . '/../cart-cat/includes/class-customers.php'; require __DIR__ . '/../cart-cat/includes/class-drafts.php';
	$count = 0;
	$assert = static function ( $value, $message ) use ( &$count ) { ++$count; if ( ! $value ) { throw new \RuntimeException( $message ); } };
	$ok = static function ( $v ) { if ( is_wp_error( $v ) ) { throw new \RuntimeException( $v->get_error_code() . ': ' . $v->get_error_message() ); } return $v; };
	$is_error = static function ( $v, $code ) { return is_wp_error( $v ) && $v->get_error_code() === $code; };
	$reference = ( new \ReflectionMethod( Customers::class, 'issue_reference' ) )->invoke( null, 55, 'square-customer-55' );
	$input = array( 'customer' => array( 'reference' => $reference, 'first_name' => 'Saved', 'last_name' => 'Customer', 'email' => 'unfinished@', 'woo_id' => 999, 'roles' => array( 'administrator' ), 'billing' => array( 'address_1' => '<b>1 Draft Lane</b>' ), 'shipping' => array( 'email' => 'recipient@example.test', 'phone' => '2025550102' ) ), 'lines' => array( array( 'product_id' => 123, 'quantity' => '', 'name' => '<b>Display wine</b>', 'addons' => array( 'message' => "O'Reilly\\notes\nnext line" ), 'price' => 0 ) ) );
	$input['order_type'] = 'somminar';
	$save = array( 'input' => $input, 'title' => 'Special shipment', 'request_id' => 'draft-create-request-0001' );
	Drafts::register_type();
	$assert( false === $GLOBALS['draft_type']['public'] && false === $GLOBALS['draft_type']['show_in_rest'], 'Draft storage is private and not exposed in native REST.' );
	$d = $ok( Drafts::save( 0, $save ) ); $id = $d['draft_id'];
	$assert( 'draft' === $d['state'] && $d['can_edit'], 'Incomplete form saves as editable draft.' );
	$assert( 'somminar' === $d['input']['order_type'] && 'somminar' === $ok( Drafts::get( $id ) )['input']['order_type'], 'SOMMinar order type survives draft save and reload.' );
	foreach ( array( 'club', 'SOMMinar', '', null, false, array() ) as $invalid_type ) {
		$bad_type = $save; $bad_type['input']['order_type'] = $invalid_type;
		$assert( $is_error( Drafts::save( 0, $bad_type ), 'cart_cat_order_type' ), 'Unrecognized order types cannot be saved in a draft.' );
	}
	$assert( 'unfinished@' === $d['input']['customer']['email'] && '' === $d['input']['lines'][0]['quantity'], 'Incomplete email and quantity survive saving.' );
	$assert( '1 Draft Lane' === $d['input']['customer']['billing']['address_1'] && 'Display wine' === $d['input']['lines'][0]['name'], 'Display and address fields are sanitized.' );
	$assert( ! isset( $d['input']['customer']['woo_id'], $d['input']['customer']['roles'], $d['input']['lines'][0]['price'] ), 'Client identity/roles/prices do not enter saved input.' );
	$assert( 0 === Orders::$creates && array() === $GLOBALS['draft_options'], 'Saving does not create Woo orders or stock holds.' );
	$raw = json_decode( get_post( $id )->post_content, true );
	$assert( 55 === $raw['identity']['woo_id'] && 'square-customer-55' === $raw['identity']['square_id'], 'Identity comes only from verified reference.' );
	$assert( ! str_contains( wp_json_encode( $d ), 'square-customer-55' ), 'Provider identity never leaves the draft API.' );
	$assert( "O'Reilly\\notes\nnext line" === $ok( Drafts::get( $id ) )['input']['lines'][0]['addons']['message'], 'JSON is slashed safely for native WordPress persistence.' );
	$assert( $id === $ok( Drafts::save( 0, $save ) )['draft_id'] && 1 === count( $GLOBALS['draft_posts'] ), 'Lost initial save response recovers the same draft.' );
	$changed = $save; $changed['title'] = 'Different';
	$assert( $is_error( Drafts::save( 0, $changed ), 'cart_cat_draft_request_changed' ), 'A reused save ID cannot change its payload.' );
	$update = array( 'input' => $d['input'], 'title' => 'Updated draft', 'version' => $d['version'], 'request_id' => 'draft-update-request-0001' );
	$updated = $ok( Drafts::save( $id, $update ) );
	$assert( $updated['version'] !== $d['version'], 'Saving rotates optimistic version.' );
	$assert( 'somminar' === $updated['input']['order_type'], 'Draft updates retain the selected type.' );
	$assert( $updated['version'] === $ok( Drafts::save( $id, $update ) )['version'], 'An update retry does not change version.' );
	$assert( $is_error( Drafts::save( 0, $save ), 'cart_cat_draft_changed' ), 'A create retry after later edits cannot grant the newer revision to an old form.' );
	$newer = $update; $newer['input'] = $updated['input']; $newer['version'] = $updated['version']; $newer['request_id'] = 'newer-update-request-001';
	$updated = $ok( Drafts::save( $id, $newer ) );
	$assert( $is_error( Drafts::save( $id, $update ), 'cart_cat_draft_changed' ), 'An old successful update retry conflicts after an intervening save.' );
	$stale = $update; $stale['request_id'] = 'draft-stale-update-0001';
	$assert( $is_error( Drafts::save( $id, $stale ), 'cart_cat_draft_changed' ), 'Stale edits cannot overwrite a newer save.' );
	$GLOBALS['draft_actor'] = 8;
	$other = $ok( Drafts::get( $id ) );
	$assert( ! $other['customer_selection_required'] && $other['input']['customer']['reference'] !== $updated['input']['customer']['reference'], 'Another staff session receives its own current customer reference.' );
	$assert( 55 === $ok( Customers::identity_for_draft( $other['input']['customer']['reference'] ) )['woo_id'], 'Cross-staff reopening preserves trusted customer identity.' );
	$assert( $is_error( Customers::identity_for_draft( $updated['input']['customer']['reference'] ), 'cart_cat_customer_reference' ), 'Another staff token cannot be replayed directly.' );
	$assert( 1 === $ok( Drafts::search( 'Updated' ) )['total'], 'Shared draft search includes another author.' );
	Square::$scope['location_id'] = 'changed'; $blocked = $ok( Drafts::get( $id ) );
	$assert( $blocked['customer_selection_required'] && '' !== $blocked['input']['customer']['reference'] && $blocked['warning'], 'Changed Square scope preserves fields but fails closed on identity.' );
	$assert( 'unfinished@' === $blocked['input']['customer']['email'], 'A stale customer identity never discards form data.' );
	Square::$scope['location_id'] = 'location';
	$wpdb->busy = true; $assert( $is_error( Drafts::get( $id ), 'cart_cat_draft_busy' ), 'Concurrent operation returns recoverable busy error.' ); $wpdb->busy = false;
	$GLOBALS['draft_allowed'] = false; $assert( $is_error( Drafts::search(), 'cart_cat_forbidden' ) && $is_error( Drafts::get( $id ), 'cart_cat_forbidden' ), 'Direct service access also requires staff capability.' ); $GLOBALS['draft_allowed'] = true;
	$guest = array( 'input' => array( 'customer' => array( 'woo_id' => 55, 'square_id' => 'attacker' ), 'lines' => array() ), 'request_id' => 'guest-create-request-0001' );
	$g = $ok( Drafts::save( 0, $guest ) ); $graw = json_decode( get_post( $g['draft_id'] )->post_content, true );
	$assert( 0 === $graw['identity']['woo_id'] && '' === $graw['identity']['square_id'], 'Unreferenced client IDs stay guest.' );
	$assert( 'regular' === $g['input']['order_type'], 'Older and unmarked drafts default to regular orders.' );
	$assert( $is_error( Drafts::delete( $g['draft_id'], 'stale' ), 'cart_cat_draft_changed' ), 'Deletion checks optimistic version.' );
	$assert( $ok( Drafts::delete( $g['draft_id'], $g['version'] ) )['deleted'], 'Unused draft can be deleted.' );
	$assert( $is_error( Drafts::get( $g['draft_id'] ), 'cart_cat_draft_not_found' ), 'Deleted content cannot be reopened.' );
	$assert( $is_error( Drafts::save( 0, $guest ), 'cart_cat_draft_not_found' ), 'A delayed initial save cannot resurrect a deleted draft.' );
	$assert( ! str_contains( get_post( $g['draft_id'] )->post_content, 'attacker' ), 'Deletion erases customer and product content.' );
	$other = $ok( Drafts::get( $id ) );
	$prepare = array( 'input' => $other['input'], 'version' => $other['version'], 'quote_hash' => str_repeat( 'a', 64 ), 'request_id' => 'draft-prepare-request-01' );
	Orders::$mode = 'stale';
	$prepare['input']['customer']['first_name'] = 'Unsaved preparation edit';
	$assert( $is_error( Drafts::prepare( $id, $prepare ), 'cart_cat_stale_quote' ), 'Stale quotes return without creating an order.' );
	$assert( 'draft' === $ok( Drafts::get( $id ) )['state'] && 0 === Orders::$creates, 'Pre-creation validation failure restores editable draft.' );
	$assert( 'Saved' === $ok( Drafts::get( $id ) )['input']['customer']['first_name'], 'A failed preparation preserves the last saved draft contents and revision.' );
	Orders::$mode = 'saved_then_lost';
	try { Drafts::prepare( $id, $prepare ); } catch ( \RuntimeException $error ) { $assert( 'Lost response after order save' === $error->getMessage(), 'Fixture simulates crash after order success.' ); }
	$assert( array() === $wpdb->locks, 'The draft lock releases after an exception.' );
	$GLOBALS['draft_actor'] = 9; Orders::$mode = 'success';
	$GLOBALS['draft_insert_fail'] = true;
	$assert( $is_error( Drafts::get( $id ), 'cart_cat_draft_save_failed' ), 'Failed recovery persistence is reported rather than claiming the draft was consumed.' );
	$assert( $is_error( Drafts::prepare( $id, $prepare ), 'cart_cat_draft_save_failed' ) && 1 === Orders::$creates, 'Preparation recovery also reports persistence failure without creating another order.' );
	$GLOBALS['draft_insert_fail'] = false;
	$recovered = $ok( Drafts::get( $id ) );
	$assert( 'prepared' === $recovered['state'] && 801 === $recovered['order_id'], 'Another staff member recovers successful preparation after lost response.' );
	$prepare['request_id'] = 'different-prepare-request'; $prepare['quote_hash'] = str_repeat( 'b', 64 );
	$assert( 801 === $ok( Drafts::prepare( $id, $prepare ) )['order_id'] && 1 === Orders::$creates, 'Conflicting retry IDs and quote hashes cannot create a second order.' );
	$assert( $is_error( Drafts::delete( $id, $recovered['version'] ), 'cart_cat_draft_preparing' ), 'Prepared draft cannot be removed to disguise its order.' );
	$locked = $ok( Drafts::save( 0, array( 'input' => array(), 'request_id' => 'interrupted-create-0001' ) ) );
	$pending = array( 'input' => $locked['input'], 'version' => $locked['version'], 'quote_hash' => str_repeat( 'c', 64 ), 'request_id' => 'interrupted-prepare-001' );
	Orders::$mode = 'interrupted';
	$assert( $is_error( Drafts::prepare( $locked['draft_id'], $pending ), 'cart_cat_prepare_failed' ), 'Interrupted order build stays recoverable.' );
	$stuck = $ok( Drafts::get( $locked['draft_id'] ) );
	$assert( 'preparing' === $stuck['state'] && ! $stuck['can_edit'] && 802 === $stuck['prepare_error']['order_id'], 'Partial order record locks draft with review context.' );
	$assert( 0 === $stuck['order_id'] && 802 === $stuck['review_order_id'] && str_contains( $stuck['edit_url'], '802' ), 'Interrupted order exposes a review link without presenting a payable prepared order.' );
	$assert( $is_error( Drafts::prepare( $locked['draft_id'], $pending ), 'cart_cat_preparation_review' ) && 2 === Orders::$creates, 'Partial order cannot trigger a replacement order.' );
	$assert( $is_error( Drafts::save( $locked['draft_id'], array( 'input' => $stuck['input'], 'version' => $stuck['version'], 'request_id' => 'blocked-save-request-001' ) ), 'cart_cat_draft_preparing' ), 'Uncertain preparation cannot be overwritten by a save.' );
	for ( $i = 0; $i < 22; $i++ ) { $ok( Drafts::save( 0, array( 'input' => array(), 'title' => 'Paging fixture ' . $i, 'request_id' => 'paging-fixture-request-' . $i ) ) ); }
	$page = $ok( Drafts::search( 'Paging fixture', 2 ) );
	$assert( 22 === $page['total'] && 2 === $page['total_pages'] && 2 === count( $page['drafts'] ), 'Shared draft search is paginated.' );
	$bad = array( 'input' => array( 'lines' => array_fill( 0, 101, array() ) ), 'request_id' => 'oversized-save-request-1' );
	$assert( $is_error( Drafts::save( 0, $bad ), 'cart_cat_draft_input' ), 'Oversized draft schemas are rejected.' );
	$GLOBALS['draft_insert_fail'] = true;
	$assert( $is_error( Drafts::save( 0, array( 'input' => array(), 'request_id' => 'write-failure-request-01' ) ), 'cart_cat_draft_save_failed' ), 'Storage failure is explicit and retryable.' );
	echo 'Cart Cat drafts contract: ' . $count . " assertions passed.\n";
}
