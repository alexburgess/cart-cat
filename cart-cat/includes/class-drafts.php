<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

/** Private staff workspaces, never Woo orders or stock reservations. */
final class Drafts {
	const POST_TYPE = 'cart_cat_draft';
	const PAGE_SIZE = 20;
	public static function register() { add_action( 'init', array( __CLASS__, 'register_type' ) ); }
	public static function register_type() {
		register_post_type( self::POST_TYPE, array( 'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true, 'show_ui' => false, 'show_in_rest' => false, 'rewrite' => false, 'query_var' => false, 'supports' => array(), 'can_export' => false, 'capabilities' => array( 'read_post' => 'manage_woocommerce', 'edit_post' => 'manage_woocommerce', 'delete_post' => 'manage_woocommerce', 'create_posts' => 'manage_woocommerce' ), 'map_meta_cap' => false ) );
	}
	private static function allowed() { return get_current_user_id() > 0 && current_user_can( 'manage_woocommerce' ); }
	private static function error( $code, $message, $status = 409, array $data = array() ) { return new \WP_Error( 'cart_cat_' . $code, $message, array_merge( array( 'status' => $status ), $data ) ); }
	private static function lock( string $key, callable $callback ) {
		global $wpdb;
		if ( ! self::allowed() ) { return self::error( 'forbidden', 'WooCommerce management permission is required.', 403 ); }
		$name = 'cc-draft-' . substr( hash( 'sha256', get_current_blog_id() . ':' . $key ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) { return self::error( 'draft_busy', 'This draft is being saved or prepared. Retry in a moment.' ); }
		try { return $callback(); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
	}
	private static function read( int $id ) {
		clean_post_cache( $id );
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'private' !== $post->post_status ) { return self::error( 'draft_not_found', 'This saved draft is no longer available.', 404 ); }
		$data = json_decode( $post->post_content, true );
		return is_array( $data ) && isset( $data['version'], $data['input'], $data['identity'] ) ? array( $post, $data ) : self::error( 'draft_unreadable', 'This draft needs review before it can be opened.' );
	}
	private static function write( int $id, array $data, string $slug = '' ) {
		$customer = $data['input']['customer'];
		$search = trim( ( $customer['first_name'] ?? '' ) . ' ' . ( $customer['last_name'] ?? '' ) . ' ' . ( $customer['email'] ?? '' ) );
		$post = array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'post_title' => $data['title'], 'post_excerpt' => $search, 'post_content' => wp_json_encode( $data ), 'post_author' => get_current_user_id() );
		if ( $id ) { $post['ID'] = $id; } else { $post['post_name'] = $slug; }
		$result = wp_insert_post( wp_slash( $post ), true );
		return is_wp_error( $result ) || ! $result ? self::error( 'draft_save_failed', 'The draft could not be saved. Retry the same request.', 503 ) : (int) $result;
	}
	private static function revision(): string { return bin2hex( random_bytes( 16 ) ); }
	private static function request_valid( $id ): bool { return is_string( $id ) && (bool) preg_match( '/^[a-zA-Z0-9-]{16,80}$/', $id ); }
	private static function text( $value, int $limit = 500, bool $multiline = false ): string {
		if ( ! is_scalar( $value ) ) { return ''; }
		$value = $multiline ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit, 'UTF-8' ) : substr( $value, 0, $limit );
	}
	/** Incomplete values are preserved as inert text; only Quote/Catalog can authorize preparation. */
	private static function input( $raw ) {
		if ( ! is_array( $raw ) || strlen( (string) wp_json_encode( $raw ) ) > 131072 || ! is_array( $raw['lines'] ?? array() ) || count( $raw['lines'] ?? array() ) > 100 ) { return self::error( 'draft_input', 'Save at most 100 product lines and 128 KB of draft details.', 422 ); }
		$order_type = array_key_exists( 'order_type', $raw ) ? $raw['order_type'] : 'regular';
		if ( ! in_array( $order_type, array( 'regular', 'somminar' ), true ) ) { return self::error( 'order_type', 'Choose a regular or SOMMinar order.', 422 ); }
		$out = array( 'customer' => array(), 'lines' => array(), 'order_type' => $order_type );
		$customer = is_array( $raw['customer'] ?? null ) ? $raw['customer'] : array();
		foreach ( array( 'first_name', 'last_name', 'email', 'phone' ) as $key ) { $out['customer'][ $key ] = self::text( $customer[ $key ] ?? '' ); }
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			$out['customer'][ $kind ] = array();
			foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ) as $key ) { $out['customer'][ $kind ][ $key ] = self::text( $customer[ $kind ][ $key ] ?? '' ); }
		}
		foreach ( $raw['lines'] ?? array() as $line ) {
			if ( ! is_array( $line ) ) { return self::error( 'draft_input', 'A draft product line is invalid.', 422 ); }
			$row = array( 'product_id' => max( 0, (int) ( $line['product_id'] ?? 0 ) ), 'variation_id' => max( 0, (int) ( $line['variation_id'] ?? 0 ) ), 'quantity' => self::text( $line['quantity'] ?? '', 30 ), 'attributes' => array(), 'addons' => array(), 'name' => self::text( $line['name'] ?? '', 250 ), 'meta' => array() );
			foreach ( array( 'attributes', 'addons' ) as $kind ) {
				$values = $line[ $kind ] ?? array();
				if ( ! is_array( $values ) || count( $values ) > 100 ) { return self::error( 'draft_input', 'A draft product has too many options.', 422 ); }
				foreach ( $values as $key => $value ) {
					$key = self::text( $key, 150 );
					if ( is_array( $value ) && 'addons' === $kind ) {
						if ( count( $value ) > 100 ) { return self::error( 'draft_input', 'A draft product has too many selections.', 422 ); }
						$row[ $kind ][ $key ] = array_map( static function ( $item ) { return self::text( $item, 2000, true ); }, array_values( $value ) );
					} else { $row[ $kind ][ $key ] = self::text( $value, 2000, true ); }
				}
			}
			foreach ( array_slice( is_array( $line['meta'] ?? null ) ? $line['meta'] : array(), 0, 50 ) as $meta ) {
				$row['meta'][] = is_array( $meta ) ? array( 'name' => self::text( $meta['name'] ?? $meta['key'] ?? $meta['label'] ?? '', 150 ), 'value' => self::text( $meta['value'] ?? $meta['display'] ?? '', 2000 ) ) : self::text( $meta, 2000 );
			}
			$out['lines'][] = $row;
		}
		$out['discount'] = array( 'type' => self::text( $raw['discount']['type'] ?? 'percent', 20 ), 'amount' => self::text( $raw['discount']['amount'] ?? '', 30 ), 'mode' => self::text( $raw['discount']['mode'] ?? 'add', 20 ) );
		$out['fulfillment'] = array( 'type' => self::text( $raw['fulfillment']['type'] ?? 'pickup', 20 ), 'rates' => array(), 'heat_hold_choice' => self::text( $raw['fulfillment']['heat_hold_choice'] ?? '', 30 ) );
		foreach ( array_slice( (array) ( $raw['fulfillment']['rates'] ?? array() ), 0, 20, true ) as $key => $rate ) { $out['fulfillment']['rates'][ self::text( $key, 20 ) ] = self::text( $rate, 200 ); }
		$out['hold_minutes'] = max( 15, min( 10080, (int) ( $raw['hold_minutes'] ?? Stock_Hold::config()['default_minutes'] ) ) );
		$out['order_notes'] = self::text( $raw['order_notes'] ?? '', 2000, true );
		$out['order_notes_visible'] = true === ( $raw['order_notes_visible'] ?? false );
		return $out;
	}
	private static function identity( array $raw ) {
		$reference = $raw['customer']['reference'] ?? '';
		if ( ! is_string( $reference ) ) { return self::error( 'customer_reference', 'Select the customer again before saving the draft.' ); }
		return Customers::identity_for_draft( $reference );
	}
	private static function summary( $post, array $data ): array {
		$customer = $data['input']['customer'];
		return array( 'draft_id' => (int) $post->ID, 'title' => $data['title'], 'customer_name' => trim( $customer['first_name'] . ' ' . $customer['last_name'] ), 'email' => $customer['email'], 'item_count' => count( $data['input']['lines'] ), 'updated_at' => $data['updated_at'], 'updated_by' => $data['updated_by'], 'version' => $data['version'], 'state' => $data['state'], 'order_id' => 'prepared' === $data['state'] ? (int) ( $data['order_id'] ?? 0 ) : 0 );
	}
	private static function detail( $post, array $data ): array {
		$result = self::summary( $post, $data );
		$result['input'] = $data['input'];
		$reference = Customers::reference_for_draft( $data['identity'] );
		$result['input']['customer']['reference'] = is_wp_error( $reference ) ? 'draft-customer-selection-required' : $reference;
		$result['customer_selection_required'] = is_wp_error( $reference );
		$result['warning'] = is_wp_error( $reference ) ? $reference->get_error_message() : '';
		$result['customer'] = array( 'name' => $result['customer_name'], 'roles' => array(), 'sources' => array() );
		$result['can_edit'] = 'draft' === $data['state'];
		$result['prepare_error'] = $data['prepare_error'] ?? null;
		if ( 'preparing' === $data['state'] && ! empty( $data['order_id'] ) ) {
			$order = Orders::load( (int) $data['order_id'] );
			if ( ! is_wp_error( $order ) ) { $result['review_order_id'] = (int) $data['order_id']; $result['edit_url'] = $order->get_edit_order_url(); }
		}
		return $result;
	}
	public static function search( string $query = '', int $page = 1 ) {
		if ( ! self::allowed() ) { return self::error( 'forbidden', 'WooCommerce management permission is required.', 403 ); }
		$page = max( 1, $page );
		$result = new \WP_Query( array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => self::PAGE_SIZE, 'paged' => $page, 's' => self::text( $query, 120 ), 'orderby' => array( 'modified' => 'DESC', 'ID' => 'DESC' ), 'perm' => '', 'no_found_rows' => false ) );
		$drafts = array();
		foreach ( $result->posts as $post ) {
			$data = json_decode( $post->post_content, true );
			if ( is_array( $data ) && isset( $data['input'], $data['version'] ) ) { $drafts[] = self::summary( $post, $data ); }
		}
		return array( 'drafts' => $drafts, 'page' => $page, 'total_pages' => (int) $result->max_num_pages, 'total' => (int) $result->found_posts );
	}
	public static function save( int $id, array $request ) {
		if ( ! self::request_valid( $request['request_id'] ?? null ) ) { return self::error( 'draft_request', 'Retry saving with a valid draft request.', 422 ); }
		$create_slug = 'cc-' . hash( 'sha256', get_current_user_id() . ':' . $request['request_id'] );
		return self::lock( $id ? (string) $id : $create_slug, static function () use ( $id, $request, $create_slug ) {
			$input = self::input( $request['input'] ?? null ); if ( is_wp_error( $input ) ) { return $input; }
			$identity = self::identity( $request['input'] ); if ( is_wp_error( $identity ) ) { return $identity; }
			$title = self::text( $request['title'] ?? '', 160 );
			if ( '' === $title ) { $title = trim( $input['customer']['first_name'] . ' ' . $input['customer']['last_name'] ) ?: 'Untitled draft'; }
			$fingerprint = hash( 'sha256', wp_json_encode( array( $input, $identity, $title ) ) );
			$receipt = hash( 'sha256', get_current_user_id() . ':' . $request['request_id'] );
			$existing = $id ? null : get_page_by_path( $create_slug, OBJECT, self::POST_TYPE );
			if ( $existing ) {
				$read = self::read( (int) $existing->ID ); if ( is_wp_error( $read ) ) { return $read; }
				list( $post, $data ) = $read;
				if ( ! hash_equals( $data['create_fingerprint'], $fingerprint ) ) { return self::error( 'draft_request_changed', 'This save request already belongs to different draft details.' ); }
				return $data['create_version'] === $data['version'] && 'draft' === $data['state'] ? self::detail( $post, $data ) : self::error( 'draft_changed', 'This draft changed after your original save. Reload its current details.', 409, array( 'draft_id' => (int) $post->ID ) );
			}
			$data = array();
			if ( $id ) {
				$read = self::read( $id ); if ( is_wp_error( $read ) ) { return $read; }
				list( $post, $data ) = $read;
				if ( isset( $data['receipts'][ $receipt ] ) ) {
					$accepted = $data['receipts'][ $receipt ];
					if ( ! hash_equals( $accepted['fingerprint'], $fingerprint ) ) { return self::error( 'draft_request_changed', 'This save request already belongs to different draft details.' ); }
					return $accepted['version'] === $data['version'] && 'draft' === $data['state'] ? self::detail( $post, $data ) : self::error( 'draft_changed', 'This draft changed after your original save. Reload its current details.', 409, array( 'draft_id' => $id ) );
				}
				if ( 'draft' !== $data['state'] ) { return self::error( 'draft_preparing', 'This draft has already entered order preparation. Reload its saved status.' ); }
				if ( ! is_string( $request['version'] ?? null ) || ! hash_equals( $data['version'], $request['version'] ) ) { return self::error( 'draft_changed', 'Another staff member changed this draft. Reload it before saving your changes.' ); }
			}
			$data = array_merge( $data, array( 'input' => $input, 'identity' => $identity, 'title' => $title, 'state' => 'draft', 'version' => self::revision(), 'updated_at' => gmdate( 'c' ), 'updated_by' => self::text( wp_get_current_user()->display_name, 150 ) ) );
			if ( ! $id ) { $data['create_fingerprint'] = $fingerprint; $data['create_version'] = $data['version']; }
			$data['receipts'][ $receipt ] = array( 'fingerprint' => $fingerprint, 'version' => $data['version'] );
			$data['receipts'] = array_slice( $data['receipts'], -20, null, true );
			$saved = self::write( $id, $data, $create_slug ); if ( is_wp_error( $saved ) ) { return $saved; }
			return self::detail( get_post( $saved ), $data );
		} );
	}
	/** Recover a crashed preparation using the order service's write-before-order ledger. */
	private static function recover( int $id, array &$data ) {
		if ( 'preparing' !== $data['state'] ) { return null; }
		$attempt = $data['attempt'];
		$record = get_option( 'cart_cat_request_' . Orders::request_key( $attempt['request_id'], 'draft:' . $id ), false );
		if ( false === $record ) {
			$data['state'] = 'draft'; unset( $data['attempt'], $data['prepare_error'] );
			return self::write( $id, $data );
		}
		$data['input'] = $attempt['input']; $data['identity'] = $attempt['identity'];
		$data['order_id'] = (int) ( $record['order_id'] ?? 0 );
		$result = Orders::create( array(), $attempt['quote_hash'], $attempt['request_id'], 'draft:' . $id );
		if ( ! is_wp_error( $result ) && ! empty( $result['order_id'] ) ) { $data['state'] = 'prepared'; $data['order_id'] = (int) $result['order_id']; unset( $data['prepare_error'] ); }
		else { $data['prepare_error'] = array( 'message' => is_wp_error( $result ) ? $result->get_error_message() : 'Order preparation needs review.', 'order_id' => (int) ( $record['order_id'] ?? 0 ) ); }
		$saved = self::write( $id, $data );
		return is_wp_error( $saved ) ? $saved : $result;
	}
	public static function get( int $id ) {
		return self::lock( (string) $id, static function () use ( $id ) {
			$read = self::read( $id ); if ( is_wp_error( $read ) ) { return $read; }
			list( $post, $data ) = $read;
			$recovered = self::recover( $id, $data );
			if ( is_wp_error( $recovered ) && 'cart_cat_draft_save_failed' === $recovered->get_error_code() ) { return $recovered; }
			return self::detail( $post, $data );
		} );
	}
	public static function delete( int $id, string $version ) {
		return self::lock( (string) $id, static function () use ( $id, $version ) {
			$read = self::read( $id ); if ( is_wp_error( $read ) ) { return $read; }
			list( $post, $data ) = $read;
			if ( ! hash_equals( $data['version'], $version ) ) { return self::error( 'draft_changed', 'Another staff member changed this draft. Reload it before deleting.' ); }
			if ( 'draft' !== $data['state'] ) { return self::error( 'draft_preparing', 'This draft entered order preparation and cannot be deleted here.' ); }
			// A tombstone preserves the create-request identity against a delayed retry.
			$data = array( 'version' => self::revision(), 'deleted' => true );
			$result = wp_insert_post( wp_slash( array( 'ID' => $id, 'post_status' => 'draft', 'post_title' => 'Deleted Cart Cat draft', 'post_excerpt' => '', 'post_content' => wp_json_encode( $data ) ) ), true );
			return is_wp_error( $result ) || ! $result ? self::error( 'draft_delete_failed', 'The draft could not be deleted. Retry in a moment.', 503 ) : array( 'draft_id' => $id, 'deleted' => true );
		} );
	}
	public static function prepare( int $id, array $request ) {
		return self::lock( (string) $id, static function () use ( $id, $request ) {
			$read = self::read( $id ); if ( is_wp_error( $read ) ) { return $read; }
			list( $post, $data ) = $read;
			$recovered = self::recover( $id, $data );
			if ( is_wp_error( $recovered ) && 'cart_cat_draft_save_failed' === $recovered->get_error_code() ) { return $recovered; }
			if ( 'prepared' === $data['state'] ) { return Orders::get( (int) $data['order_id'] ); }
			if ( 'preparing' === $data['state'] ) { return is_wp_error( $recovered ) ? $recovered : self::error( 'draft_preparing', 'This order preparation needs review. Reload the draft status.' ); }
			if ( is_wp_error( $recovered ) ) { return $recovered; }
			if ( ! is_string( $request['version'] ?? null ) || ! hash_equals( $data['version'], $request['version'] ) ) { return self::error( 'draft_changed', 'Another staff member changed this draft. Reload it before preparing.' ); }
			if ( ! self::request_valid( $request['request_id'] ?? null ) || ! is_string( $request['quote_hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $request['quote_hash'] ) ) { return self::error( 'draft_request', 'Refresh the quote before preparing this draft.', 422 ); }
			$input = self::input( $request['input'] ?? null ); if ( is_wp_error( $input ) ) { return $input; }
			$identity = self::identity( $request['input'] ); if ( is_wp_error( $identity ) ) { return $identity; }
			$data['state'] = 'preparing';
			$data['attempt'] = array( 'request_id' => 'draft-' . wp_generate_uuid4(), 'quote_hash' => $request['quote_hash'], 'input' => $input, 'identity' => $identity );
			$saved = self::write( $id, $data ); if ( is_wp_error( $saved ) ) { return $saved; }
			// Use the exact quoted input; sanitizing again would change its quote-bound hash.
			$result = Orders::create( $request['input'], $request['quote_hash'], $data['attempt']['request_id'], 'draft:' . $id );
			if ( ! is_wp_error( $result ) && ! empty( $result['order_id'] ) ) { $data['state'] = 'prepared'; $data['order_id'] = (int) $result['order_id']; $data['input'] = $input; $data['identity'] = $identity; }
			else {
				$record = get_option( 'cart_cat_request_' . Orders::request_key( $data['attempt']['request_id'], 'draft:' . $id ), false );
				if ( false === $record ) { $data['state'] = 'draft'; unset( $data['attempt'] ); }
				else { $data['input'] = $input; $data['identity'] = $identity; $data['order_id'] = (int) ( $record['order_id'] ?? 0 ); $data['prepare_error'] = array( 'message' => is_wp_error( $result ) ? $result->get_error_message() : 'Order preparation needs review.', 'order_id' => $data['order_id'] ); }
			}
			$saved = self::write( $id, $data );
			return is_wp_error( $saved ) ? $saved : $result;
		} );
	}
}
