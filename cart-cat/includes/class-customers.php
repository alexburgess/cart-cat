<?php
/** Staff customer directory and server-owned customer identity. */
namespace Cart_Cat;

defined( 'ABSPATH' ) || exit;

final class Customers {

	const REFERENCE_TTL = 1800;
	const LIMIT = 20;

	/** Search both directories. Mirror data is only a candidate index. */
	public static function search( string $query ) {
		if ( ! self::authorized() ) {
			return self::error( 'customer_access', 'You cannot look up customers.', 403 );
		}
		$query = self::text( $query );
		if ( strlen( $query ) < 2 ) {
			return array( 'customers' => array(), 'providerStatuses' => array( 'woocommerce' => 'available', 'square' => 'idle' ) );
		}
		if ( strlen( $query ) > 120 ) {
			return self::error( 'customer_query', 'Use a shorter customer search.' );
		}
		$bucket = 'cart_cat_customer_search_' . get_current_user_id() . '_' . (int) floor( time() / 60 );
		$count = (int) get_transient( $bucket );
		if ( $count >= 30 ) {
			return self::error( 'customer_rate_limit', 'Please wait a moment before searching again.', 429 );
		}
		set_transient( $bucket, $count + 1, 90 );
		$terms = preg_split( '/\s+/u', self::lower( $query ), -1, PREG_SPLIT_NO_EMPTY );
		$terms = is_array( $terms ) ? array_slice( $terms, 0, 6 ) : array( self::lower( $query ) );
		$users = self::woo_candidates( $query, $terms );
		$square = self::square_candidates( $query, $terms );
		$rows = array();
		$woo_rows = array();
		foreach ( $users as $user ) {
			$woo_rows[ $user->ID ] = count( $rows );
			$rows[] = self::summary( (int) $user->ID, '', self::woo_fields( $user ), array( 'woocommerce' ), self::local_roles( $user ) );
		}
		$email_counts = array();
		foreach ( $square['customers'] as $record ) {
			$email = self::email( $record['email_address'] ?? '' );
			if ( '' !== $email ) {
				$email_counts[ $email ] = 1 + ( $email_counts[ $email ] ?? 0 );
			}
		}
		foreach ( $square['customers'] as $record ) {
			$email = self::email( $record['email_address'] ?? '' );
			$woo_id = 0;
			// An explicit Square selection may also attach the unique local account.
			// Never collapse two Square profiles into a single selectable identity.
			if ( '' !== $email ) {
				$user = get_user_by( 'email', $email );
				if ( $user instanceof \WP_User && self::user_on_site( $user ) ) {
					$woo_id = (int) $user->ID;
				}
			}
			$fields = self::square_fields( $record );
			$roles = self::square_roles( $record );
			if ( $woo_id ) {
				$fields = self::prefer_woo( $fields, self::woo_fields( $user ) );
				$local = self::local_roles( $user );
				$roles = self::combine_roles( $local, $roles );
				if ( ( $email_counts[ $email ] ?? 0 ) === 1 && isset( $woo_rows[ $woo_id ] ) ) {
					unset( $rows[ $woo_rows[ $woo_id ] ] );
				}
			}
			$row = self::summary( $woo_id, (string) $record['id'], $fields, $woo_id ? array( 'woocommerce', 'square' ) : array( 'square' ), $roles );
			$row['ambiguous'] = '' !== $email && ( $email_counts[ $email ] ?? 0 ) > 1;
			$rows[] = $row;
		}
		return array( 'customers' => array_slice( $rows, 0, 40 ), 'providerStatuses' => array( 'woocommerce' => 'available', 'square' => $square['status'] ) );
	}

	/** Only reference and display-safe fields leave this boundary. */
	public static function details( string $reference ) {
		$resolved = self::resolve( array( 'reference' => $reference ), true );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$resolved['square_linked'] = '' !== $resolved['square_id'];
		unset( $resolved['square_id'] );
		return $resolved;
	}

	/** Reissue the exact saved identity for staff resuming an order in a new session. */
	public static function reference_for_order( $order ) {
		if ( ! self::authorized() || ! Payments::owns( $order ) ) { return self::error( 'customer_access', 'You cannot look up this order customer.', 403 ); }
		$square_id = (string) $order->get_meta( '_cart_cat_square_customer_id' );
		if ( $square_id && $order->get_meta( '_cart_cat_square_scope' ) !== Square::scope() ) { return self::error( 'customer_reference_scope', 'The Square connection changed. Restore the original connection before editing this order.', 409 ); }
		return $order->get_customer_id() || $square_id ? self::issue_reference( (int) $order->get_customer_id(), $square_id ) : '';
	}

	/** Capture only the verified reference identity; saving a draft never queries providers. */
	public static function identity_for_draft( string $reference ) {
		if ( ! self::authorized() ) { return self::error( 'customer_access', 'You cannot save this customer.', 403 ); }
		if ( '' === $reference ) { return array( 'woo_id' => 0, 'square_id' => '', 'square_scope' => Square::scope() ); }
		$data = self::read_reference( $reference );
		return is_wp_error( $data ) ? $data : array_intersect_key( $data, array_flip( array( 'woo_id', 'square_id', 'square_scope' ) ) );
	}
	/** Only call with trusted identity read from a private server-side draft. No provider I/O. */
	public static function reference_for_draft( array $identity ) {
		if ( ! self::authorized() ) { return self::error( 'customer_access', 'You cannot load this customer.', 403 ); }
		$woo_id = (int) ( $identity['woo_id'] ?? 0 ); $square_id = (string) ( $identity['square_id'] ?? '' );
		if ( ! $woo_id && ! $square_id ) { return ''; }
		if ( ( $identity['square_scope'] ?? null ) !== Square::scope() ) { return self::error( 'customer_reference_scope', 'The Square connection changed. Select the customer again before preparing this draft.', 409 ); }
		if ( $woo_id ) {
			$user = get_userdata( $woo_id );
			if ( ! $user instanceof \WP_User || ! self::user_on_site( $user ) ) { return self::error( 'customer_changed', 'The saved customer is unavailable. Select the customer again before preparing this draft.', 409 ); }
		}
		return self::issue_reference( $woo_id, $square_id );
	}

	/** Fresh identity, roles and order-only contact overrides; never logs in as a customer. */
	public static function resolve( array $input, bool $fresh = false ) {
		if ( ! self::authorized() ) {
			return self::error( 'customer_access', 'You cannot look up customers.', 403 );
		}
		$reference = isset( $input['reference'] ) && is_string( $input['reference'] ) ? $input['reference'] : '';
		$identity = array( 'woo_id' => 0, 'square_id' => '' );
		if ( '' !== $reference ) {
			$identity = self::read_reference( $reference );
			if ( is_wp_error( $identity ) ) {
				return $identity;
			}
		}
		$fields = self::blank_fields();
		$roles = array();
		$source = 'none';
		$membership_status = 'not_member';
		$sources = array();
		$user = null;
		if ( ! empty( $identity['woo_id'] ) ) {
			if ( $fresh && function_exists( 'clean_user_cache' ) ) {
				clean_user_cache( (int) $identity['woo_id'] );
			}
			$user = get_userdata( (int) $identity['woo_id'] );
			if ( ! $user instanceof \WP_User || ! self::user_on_site( $user ) ) {
				return self::error( 'customer_changed', 'This customer is no longer available. Look them up again.', 409 );
			}
			$fields = self::woo_fields( $user );
			$roles = self::local_roles( $user );
			$sources[] = 'woocommerce';
			$source = self::has_club_roles( $roles ) ? 'wordpress' : 'none';
		}
		$square_record = null;
		$square_status = 'unlinked';
		if ( '' !== (string) $identity['square_id'] ) {
			$response = Square::request( 'GET', '/v2/customers/' . rawurlencode( $identity['square_id'] ) );
			if ( is_wp_error( $response ) || ! isset( $response['customer']['id'] ) || (string) $response['customer']['id'] !== $identity['square_id'] ) {
				return self::error( 'customer_square_unavailable', 'Square could not verify the selected customer. Please retry.', 503 );
			}
			$square_record = $response['customer'];
		} elseif ( $user && '' !== self::email( $user->user_email ) ) {
			// A Woo lookup can reveal cards only after a complete unique exact-email match.
			$match = self::square_exact_email( self::email( $user->user_email ) );
			$square_status = $match['status'];
			$square_record = $match['customer'];
			if ( 'unavailable' === $square_status && $fresh && ! self::has_club_roles( $roles ) ) {
				return self::error( 'customer_membership_unavailable', 'Square membership could not be checked. Please retry before creating this order.', 503 );
			}
		}
		if ( is_array( $square_record ) ) {
			$identity['square_id'] = (string) $square_record['id'];
			$fields = $user ? self::prefer_woo( self::square_fields( $square_record ), $fields ) : self::square_fields( $square_record );
			$sources[] = 'square';
			$square_status = 'linked';
			if ( ! self::has_club_roles( $roles ) ) {
				$square_roles = self::square_roles( $square_record );
				$roles = array_values( array_unique( array_merge( $roles, $square_roles ) ) );
				$source = $square_roles ? 'square_group' : $source;
			}
		}
		if ( self::has_club_roles( $roles ) ) {
			$membership_status = 'eligible';
		} elseif ( 'ambiguous' === $square_status ) {
			$membership_status = 'ambiguous';
		}
		foreach ( array( 'first_name', 'last_name', 'email', 'phone' ) as $key ) {
			if ( array_key_exists( $key, $input ) && is_scalar( $input[ $key ] ) ) {
				$fields[ $key ] = 'email' === $key ? self::email( $input[ $key ] ) : self::text( $input[ $key ] );
			}
		}
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			if ( isset( $input[ $kind ] ) && is_array( $input[ $kind ] ) ) {
				foreach ( array_keys( $fields[ $kind ] ) as $key ) {
					if ( array_key_exists( $key, $input[ $kind ] ) && is_scalar( $input[ $kind ][ $key ] ) ) {
						$fields[ $kind ][ $key ] = 'email' === $key ? self::email( $input[ $kind ][ $key ] ) : self::text( $input[ $kind ][ $key ] );
					}
				}
			}
			$fields[ $kind ]['country'] = strtoupper( $fields[ $kind ]['country'] );
			$fields[ $kind ]['state'] = strtoupper( $fields[ $kind ]['state'] );
		}
		$fields['billing']['email'] = $fields['email'];
		$fields['billing']['phone'] = $fields['phone'];
		$fields['billing']['first_name'] = $fields['first_name'];
		$fields['billing']['last_name'] = $fields['last_name'];
		return array_merge( $fields, array(
			'reference' => $reference, 'woo_id' => (int) $identity['woo_id'], 'square_id' => (string) $identity['square_id'],
			'roles' => array_values( $roles ), 'is_guest' => empty( $identity['woo_id'] ), 'sources' => array_values( array_unique( $sources ) ),
			'name' => trim( $fields['first_name'] . ' ' . $fields['last_name'] ), 'membership_status' => $membership_status,
			'membership_source' => $source, 'square_status' => $square_status,
		) );
	}

	private static function woo_candidates( $query, $terms ) {
		$found = array();
		$base = array( 'number' => 40, 'blog_id' => get_current_blog_id() );
		$queries = array( array_merge( $base, array( 'search' => '*' . $query . '*', 'search_columns' => array( 'user_login', 'user_email', 'display_name' ) ) ) );
		$meta = array( 'relation' => 'OR' );
		foreach ( $terms as $term ) {
			foreach ( array( 'first_name', 'last_name', 'billing_email', 'billing_phone' ) as $key ) {
				$meta[] = array( 'key' => $key, 'value' => $term, 'compare' => 'LIKE' );
			}
		}
		$queries[] = array_merge( $base, array( 'meta_query' => $meta ) );
		foreach ( $queries as $args ) {
			foreach ( get_users( $args ) as $user ) {
				$fields = self::woo_fields( $user );
				if ( self::matches( $fields, $terms, (string) $user->display_name ) ) {
					$found[ $user->ID ] = $user;
				}
			}
		}
		return array_slice( array_values( $found ), 0, self::LIMIT );
	}

	/** Square supports email/phone search; names use SWS's existing local index. */
	private static function square_candidates( $query, $terms ) {
		$records = array();
		$mirror = self::mirror_ids( $terms );
		$status = $mirror['available'] ? ( $mirror['partial'] ? 'partial' : 'available' ) : 'unavailable';
		if ( $mirror['ids'] ) {
			$response = Square::request( 'POST', '/v2/customers/bulk-retrieve', array( 'customer_ids' => $mirror['ids'] ) );
			if ( is_wp_error( $response ) || ! isset( $response['responses'] ) || ! is_array( $response['responses'] ) ) {
				return array( 'customers' => array(), 'status' => 'unavailable' );
			}
			foreach ( $response['responses'] as $id => $entry ) {
				if ( in_array( (string) $id, $mirror['ids'], true ) && isset( $entry['customer']['id'] ) && (string) $entry['customer']['id'] === (string) $id ) {
					$records[ $id ] = $entry['customer'];
				}
			}
		}
		$digits = preg_replace( '/\D/', '', $query );
		$filter = false !== strpos( $query, '@' ) ? 'email_address' : ( strlen( $digits ) >= 4 ? 'phone_number' : '' );
		if ( '' !== $filter ) {
			$response = Square::request( 'POST', '/v2/customers/search', array( 'limit' => self::LIMIT, 'query' => array( 'filter' => array( $filter => array( 'fuzzy' => $query ) ) ) ) );
			if ( is_wp_error( $response ) ) {
				$status = 'unavailable';
			} else {
				$status = empty( $response['cursor'] ) ? 'available' : 'partial';
				foreach ( (array) ( $response['customers'] ?? array() ) as $record ) {
					if ( is_array( $record ) && ! empty( $record['id'] ) ) {
						$records[ (string) $record['id'] ] = $record;
					}
				}
			}
		}
		$records = array_filter( $records, static function ( $record ) use ( $terms ) { return self::matches( self::square_fields( $record ), $terms ); } );
		return array( 'customers' => array_slice( array_values( $records ), 0, self::LIMIT ), 'status' => $status );
	}

	private static function mirror_ids( $terms ) {
		global $wpdb;
		$empty = array( 'ids' => array(), 'available' => false, 'partial' => true );
		$table = $wpdb->prefix . 'square_woo_customers';
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return $empty;
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
		if ( array_diff( array( 'first_name', 'last_name', 'email', 'phone', 'source', 'square_customer_id' ), (array) $columns ) ) {
			return $empty;
		}
		$where = array();
		$args = array();
		foreach ( $terms as $term ) {
			$where[] = '(LOWER(first_name) LIKE %s OR LOWER(last_name) LIKE %s OR LOWER(email) LIKE %s OR LOWER(phone) LIKE %s)';
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			array_push( $args, $like, $like, $like, $like );
		}
		$sql = "SELECT DISTINCT square_customer_id FROM `{$table}` WHERE source IN ('Square','Both') AND square_customer_id <> '' AND " . implode( ' AND ', $where ) . ' LIMIT 21';
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		$sync = get_option( 'sws_customer_sync_state', array() );
		$last_sync = is_array( $sync ) ? strtotime( (string) ( $sync['last_synced_at'] ?? '' ) ) : false;
		return array( 'ids' => array_slice( array_map( 'strval', (array) $ids ), 0, self::LIMIT ), 'available' => true, 'partial' => count( (array) $ids ) > self::LIMIT || ! $last_sync || $last_sync < time() - 86400 );
	}

	private static function square_exact_email( $email ) {
		$response = Square::request( 'POST', '/v2/customers/search', array( 'limit' => 3, 'query' => array( 'filter' => array( 'email_address' => array( 'exact' => $email ) ) ) ) );
		if ( is_wp_error( $response ) || ( isset( $response['customers'] ) && ! is_array( $response['customers'] ) ) ) {
			return array( 'status' => 'unavailable', 'customer' => null );
		}
		$matches = (array) ( $response['customers'] ?? array() );
		if ( count( $matches ) > 1 || ! empty( $response['cursor'] ) ) {
			return array( 'status' => 'ambiguous', 'customer' => null );
		}
		$record = reset( $matches );
		if ( ! $record ) {
			return array( 'status' => 'not_found', 'customer' => null );
		}
		if ( empty( $record['id'] ) || $email !== self::email( $record['email_address'] ?? '' ) ) {
			return array( 'status' => 'unavailable', 'customer' => null );
		}
		$response = Square::request( 'GET', '/v2/customers/' . rawurlencode( $record['id'] ) );
		if ( is_wp_error( $response ) || ( $response['customer']['id'] ?? '' ) !== $record['id'] || $email !== self::email( $response['customer']['email_address'] ?? '' ) ) {
			return array( 'status' => 'unavailable', 'customer' => null );
		}
		return array( 'status' => 'linked', 'customer' => $response['customer'] );
	}

	/** Active local club role wins; other server-assigned roles remain available to Addify. */
	private static function local_roles( $user ) {
		$club = self::club_roles();
		$roles = array();
		foreach ( (array) $user->roles as $role ) {
			$role = sanitize_key( $role );
			if ( isset( $club[ $role ] ) ) {
				$status = (string) get_user_meta( $user->ID, 'oncloudwine_' . $role . '_status', true );
				if ( '' !== $status && 'active' !== $status ) {
					continue;
				}
			}
			if ( '' !== $role ) {
				$roles[] = $role;
			}
		}
		return array_values( array_unique( $roles ) );
	}

	private static function square_roles( $record ) {
		if ( ! class_exists( '\STW_Square_Role_Reconciler' ) || ! method_exists( '\STW_Square_Role_Reconciler', 'status' ) || ! method_exists( '\STW_Square_Role_Reconciler', 'planRoleChanges' ) ) {
			return array();
		}
		try {
			$status = \STW_Square_Role_Reconciler::status();
			if ( empty( $status['enabled'] ) || empty( $status['square_token_configured'] ) || ( isset( $status['target_site'] ) && ! $status['target_site'] ) ) {
				return array();
			}
			$groups = isset( $record['group_ids'] ) && is_array( $record['group_ids'] ) ? array_filter( $record['group_ids'], 'is_string' ) : array();
			$plan = \STW_Square_Role_Reconciler::planRoleChanges( array(), $groups );
			$club = self::club_roles();
			$allowed = array();
			foreach ( (array) ( $status['managed_group_roles'] ?? array() ) as $id => $role ) {
				if ( in_array( (string) $id, $groups, true ) && isset( $club[ $role ] ) && in_array( $role, (array) ( $plan['desired_roles'] ?? array() ), true ) ) {
					$allowed[] = sanitize_key( $role );
				}
			}
			return array_values( array_unique( $allowed ) );
		} catch ( \Throwable $error ) {
			return array();
		}
	}

	private static function combine_roles( $local, $square ) { return self::has_club_roles( $local ) ? $local : array_values( array_unique( array_merge( $local, $square ) ) ); }
	private static function has_club_roles( $roles ) { return (bool) array_intersect( $roles, array_keys( self::club_roles() ) ); }
	private static function club_roles() { $settings = get_option( 'oncloudwine_settings', array() ); return isset( $settings['club_roles'] ) && is_array( $settings['club_roles'] ) ? $settings['club_roles'] : array(); }
	private static function user_on_site( $user ) { return ! is_multisite() || is_user_member_of_blog( $user->ID, get_current_blog_id() ); }
	private static function authorized() { return get_current_user_id() > 0 && current_user_can( 'manage_woocommerce' ); }
	private static function binding() { return hash_hmac( 'sha256', get_current_blog_id() . ':' . get_current_user_id() . ':' . wp_get_session_token(), wp_salt( 'auth' ) ); }
	private static function issue_reference( $woo_id, $square_id ) {
		$ref = bin2hex( random_bytes( 24 ) );
		set_transient( 'cart_cat_customer_' . hash( 'sha256', $ref ), array( 'binding' => self::binding(), 'woo_id' => $woo_id, 'square_id' => $square_id, 'square_scope' => Square::scope() ), self::REFERENCE_TTL );
		return $ref;
	}
	private static function read_reference( $ref ) {
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $ref ) ) {
			return self::error( 'customer_reference', 'Look up and select this customer again.', 409 );
		}
		$data = get_transient( 'cart_cat_customer_' . hash( 'sha256', $ref ) );
		if ( ! is_array( $data ) || ! isset( $data['binding'] ) || ! hash_equals( self::binding(), $data['binding'] ) ) {
			return self::error( 'customer_reference', 'This customer selection expired. Look them up again.', 409 );
		}
		if ( ( $data['square_scope'] ?? null ) !== Square::scope() ) {
			return self::error( 'customer_reference_scope', 'The Square connection changed. Look up and select this customer again.', 409 );
		}
		return $data;
	}
	private static function summary( $woo_id, $square_id, $fields, $sources, $roles ) {
		return array( 'reference' => self::issue_reference( $woo_id, $square_id ), 'name' => trim( $fields['first_name'] . ' ' . $fields['last_name'] ) ?: $fields['email'], 'email' => $fields['email'], 'phone' => $fields['phone'], 'sources' => $sources, 'roles' => array_values( $roles ) );
	}
	private static function blank_fields() {
		$address = array_fill_keys( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ), '' );
		return array( 'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'billing' => $address, 'shipping' => $address );
	}
	private static function woo_fields( $user ) {
		$data = self::blank_fields();
		$data['first_name'] = self::text( get_user_meta( $user->ID, 'billing_first_name', true ) ?: get_user_meta( $user->ID, 'first_name', true ) );
		$data['last_name'] = self::text( get_user_meta( $user->ID, 'billing_last_name', true ) ?: get_user_meta( $user->ID, 'last_name', true ) );
		$data['email'] = self::email( get_user_meta( $user->ID, 'billing_email', true ) ?: $user->user_email );
		$data['phone'] = self::text( get_user_meta( $user->ID, 'billing_phone', true ) );
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			foreach ( array_keys( $data[ $kind ] ) as $key ) {
				$value = get_user_meta( $user->ID, $kind . '_' . $key, true );
				$data[ $kind ][ $key ] = 'email' === $key ? self::email( $value ) : self::text( $value );
			}
		}
		return $data;
	}
	private static function square_fields( $record ) {
		$data = self::blank_fields();
		$data['first_name'] = self::text( $record['given_name'] ?? '' );
		$data['last_name'] = self::text( $record['family_name'] ?? '' );
		$data['email'] = self::email( $record['email_address'] ?? '' );
		$data['phone'] = self::text( $record['phone_number'] ?? '' );
		$address = is_array( $record['address'] ?? null ) ? $record['address'] : array();
		foreach ( array( 'billing', 'shipping' ) as $kind ) {
			$data[ $kind ] = array_merge( $data[ $kind ], array( 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'company' => self::text( $record['company_name'] ?? '' ) ) );
			foreach ( array( 'address_1' => 'address_line_1', 'address_2' => 'address_line_2', 'city' => 'locality', 'state' => 'administrative_district_level_1', 'postcode' => 'postal_code', 'country' => 'country' ) as $key => $square_key ) {
				$data[ $kind ][ $key ] = self::text( $address[ $square_key ] ?? '' );
			}
		}
		return $data;
	}
	private static function prefer_woo( $square, $woo ) {
		foreach ( $woo as $key => $value ) {
			if ( is_array( $value ) ) {
				$square[ $key ] = self::prefer_woo( $square[ $key ], $value );
			} elseif ( '' !== $value ) {
				$square[ $key ] = $value;
			}
		}
		return $square;
	}
	private static function matches( $fields, $terms, $extra = '' ) {
		$text = self::lower( implode( ' ', array( $fields['first_name'], $fields['last_name'], $fields['email'], $fields['phone'], $extra ) ) );
		foreach ( $terms as $term ) {
			if ( false === strpos( $text, $term ) ) {
				// Ignore phone formatting only for a digit-only term.
				if ( ! ctype_digit( $term ) || false === strpos( preg_replace( '/\D/', '', $fields['phone'] ), $term ) ) {
					return false;
				}
			}
		}
		return true;
	}
	private static function text( $value ) { return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : ''; }
	private static function email( $value ) { return strtolower( sanitize_email( self::text( $value ) ) ); }
	private static function lower( $value ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value ); }
	private static function error( $code, $message, $status = 400 ) { return new \WP_Error( 'cart_cat_' . $code, $message, array( 'status' => $status ) ); }
}
