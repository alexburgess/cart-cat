<?php
/** WooCommerce products with native variation and Product Add-ons validation. */
namespace Cart_Cat;

defined( 'ABSPATH' ) || exit;

final class Catalog {
	const INDEX_PAGE_SIZE = 250;
	const INDEX_MAX_PAGES = 4;
	const INDEX_TTL = 300;
	private static $index_dirty = false;
	private static $index_shutdown = false;

	public static function register() {
		foreach ( array( 'save_post_product', 'save_post_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_delete_product_transients', 'tribe_tickets_save_post' ) as $hook ) { add_action( $hook, array( self::class, 'invalidate_index' ), 20, 0 ); }
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) { add_action( $hook, array( self::class, 'index_meta_changed' ), 20, 2 ); }
		foreach ( array( 'before_delete_post', 'trashed_post', 'untrashed_post', 'set_object_terms' ) as $hook ) { add_action( $hook, array( self::class, 'index_post_changed' ), 20 ); }
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) { add_action( $hook, array( self::class, 'index_term_changed' ), 20, 3 ); }
	}
	public static function invalidate_index(): void {
		if ( ! self::$index_dirty ) { self::$index_dirty = true; update_option( 'cart_cat_catalog_revision', wp_generate_uuid4(), false ); }
		if ( ! self::$index_shutdown ) { self::$index_shutdown = true; add_action( 'shutdown', array( self::class, 'flush_index_invalidation' ), PHP_INT_MAX ); }
	}
	/** Invalidate promptly and once after all metadata is saved, without a write for every field. */
	public static function flush_index_invalidation(): void {
		if ( self::$index_dirty ) { self::$index_dirty = false; update_option( 'cart_cat_catalog_revision', wp_generate_uuid4(), false ); }
	}
	public static function index_meta_changed( $meta_id, $post_id ): void { self::index_post_changed( $post_id ); }
	public static function index_post_changed( $post_id ): void {
		if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) { self::invalidate_index(); }
	}
	public static function index_term_changed( $term_id, $tt_id, $taxonomy ): void {
		if ( in_array( $taxonomy, array( 'product_cat', 'product_type' ), true ) || 0 === strpos( (string) $taxonomy, 'pa_' ) ) { self::invalidate_index(); }
	}
	private static function index_revision(): string {
		return md5( CART_CAT_VERSION . ':' . get_option( 'cart_cat_catalog_revision', '0' ) . ':' . floor( time() / self::INDEX_TTL ) );
	}
	/** Staff-only catalog hints. No account context, role pricing, add-ons, or cart data is cached. */
	public static function index( int $page = 0, string $revision = '', bool $check = false ) {
		if ( $page < 0 || $page >= self::INDEX_MAX_PAGES ) { return self::error( 'catalog_page', 'Use a valid catalog page.' ); }
		self::flush_index_invalidation();
		$current = self::index_revision();
		if ( $check && 0 === $page && hash_equals( $current, $revision ) ) { return array( 'revision' => $current, 'not_modified' => true, 'max_age' => 60 ); }
		if ( $page > 0 && ! hash_equals( $current, $revision ) ) { return self::error( 'catalog_changed', 'The catalog changed while loading. Search again.', 409 ); }
		$key = 'cart_cat_catalog_' . $current . '_' . $page;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) { return $cached; }
		$query = new \WP_Query( array(
			'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => self::INDEX_PAGE_SIZE + 1, 'offset' => $page * self::INDEX_PAGE_SIZE,
			'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true, 'ignore_sticky_posts' => true,
			'tax_query' => array( 'relation' => 'OR', array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => array( 'simple', 'variable' ) ), array( 'taxonomy' => 'product_type', 'operator' => 'NOT EXISTS' ) ),
			'meta_query' => array( 'relation' => 'OR', array( 'key' => '_tribe_wooticket_for_event', 'compare' => 'NOT EXISTS' ), array( 'key' => '_tribe_wooticket_for_event', 'value' => array( '', '0' ), 'compare' => 'IN' ) ),
		) );
		$more = count( $query->posts ) > self::INDEX_PAGE_SIZE; $partial = false; $products = array();
		foreach ( array_slice( $query->posts, 0, self::INDEX_PAGE_SIZE ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! self::eligible( $product ) ) { continue; }
			$price = $product->get_price( 'edit' ); $stock = $product->get_stock_status( 'edit' ); $terms = array( $product->get_name( 'edit' ), $product->get_sku( 'edit' ) );
			if ( $product->is_type( 'variable' ) ) {
				$children = $product->get_children();
				if ( count( $children ) > 100 ) { $partial = true; continue; }
				$prices = array(); $in_stock = false; $options = self::attribute_options( $product );
				foreach ( $children as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( ! self::eligible( $child ) || '' === $child->get_price( 'edit' ) ) { continue; }
					foreach ( $child->get_variation_attributes() as $attribute => $value ) { if ( '' === $value && empty( $options[ $attribute ]['values'] ) ) { continue 2; } }
					$prices[] = (float) $child->get_price( 'edit' ); $in_stock = $in_stock || 'outofstock' !== $child->get_stock_status( 'edit' );
					$terms[] = $child->get_sku( 'edit' );
				}
				if ( ! $prices ) { continue; }
				$price = min( $prices ); $stock = $in_stock ? 'instock' : 'outofstock';
			} elseif ( '' === $price ) { continue; }
			$products[] = array( 'id' => $product->get_id(), 'name' => $product->get_name( 'edit' ), 'sku' => $product->get_sku( 'edit' ), 'type' => $product->get_type(), 'price' => wc_format_decimal( $price ), 'stock_status' => $stock, 'search_terms' => implode( ' ', array_filter( $terms ) ) );
		}
		// A mutation during a build must never publish a mixed-generation index.
		if ( ! hash_equals( $current, self::index_revision() ) ) { return self::error( 'catalog_changed', 'The catalog changed while loading. Search again.', 409 ); }
		$result = array( 'products' => $products, 'revision' => $current, 'page' => $page, 'next_page' => $more && $page + 1 < self::INDEX_MAX_PAGES ? $page + 1 : null, 'complete' => ! $more, 'partial' => $partial, 'max_age' => 60 );
		set_transient( $key, $result, self::INDEX_TTL );
		return $result;
	}

	public static function search( string $query ) {
		$query = sanitize_text_field( $query );
		if ( strlen( $query ) > 120 ) {
			return self::error( 'product_query', 'Use a shorter product search.' );
		}
		$store = \WC_Data_Store::load( 'product' );
		$ids = '' === $query ? wc_get_products( array( 'limit' => 40, 'status' => 'publish', 'type' => array( 'simple', 'variable' ), 'return' => 'ids' ) ) : $store->search_products( $query, '', true, false, 100 );
		$products = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product instanceof \WC_Product && $product->is_type( 'variation' ) ) {
				if ( ! self::eligible( $product ) ) {
					continue;
				}
				$product = wc_get_product( $product->get_parent_id() );
			}
			if ( ! self::eligible( $product ) || isset( $products[ $product->get_id() ] ) ) {
				continue;
			}
			$price = $product->get_price( 'edit' ); $variation_count = null; $choose_variation = false;
			if ( $product->is_type( 'variable' ) ) {
				$attribute_options = self::attribute_options( $product );
				$variations = self::available_variations( $product, $attribute_options );
				if ( ! $variations ) {
					continue;
				}
				$price = min( array_map( static function ( $variation ) { return (float) $variation->get_price( 'edit' ); }, $variations ) );
				$available = array_values( array_filter( $variations, static function ( $variation ) { return $variation->is_in_stock(); } ) );
				$variation_count = count( $available ); $choose_variation = 1 !== $variation_count;
				if ( 1 === $variation_count ) {
					foreach ( $available[0]->get_variation_attributes() as $key => $value ) {
						if ( '' === $value && 1 !== count( $attribute_options[ $key ]['values'] ?? array() ) ) { $choose_variation = true; }
					}
				}
			}
			$addons = self::addons( $product->get_id() );
			$products[ $product->get_id() ] = array(
				'id' => $product->get_id(), 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'type' => $product->get_type(),
				'price' => wc_format_decimal( $price ), 'stock_status' => $product->get_stock_status(),
				'requires_configuration' => $choose_variation || is_wp_error( $addons ) || ! empty( $addons ), 'available_variation_count' => $variation_count,
			);
			if ( count( $products ) >= 30 ) {
				break;
			}
		}
		return array( 'products' => array_values( $products ) );
	}

	public static function configuration( int $id ) {
		$product = wc_get_product( $id );
		if ( $product instanceof \WC_Product && $product->is_type( 'variation' ) ) {
			if ( ! self::eligible( $product ) ) {
				return self::error( 'product_unavailable', 'This product cannot be added to a customer order.', 404 );
			}
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! self::eligible( $product ) ) {
			return self::error( 'product_unavailable', 'This product cannot be added to a customer order.', 404 );
		}
		$raw_addons = self::addons( $product->get_id() );
		if ( is_wp_error( $raw_addons ) ) {
			return $raw_addons;
		}
		$addons = array();
		foreach ( $raw_addons as $addon ) {
			$type = (string) ( $addon['type'] ?? '' );
			$options = array();
			$loop = 0;
			foreach ( (array) ( $addon['options'] ?? array() ) as $option ) {
				++$loop;
				if ( isset( $option['visibility'] ) && 0 === (int) $option['visibility'] ) {
					continue;
				}
				$label = (string) ( $option['label'] ?? '' );
				$list = 'checkbox' === $type || ( 'multiple_choice' === $type && 'radiobutton' === ( $addon['display'] ?? '' ) );
				$options[] = array( 'value' => sanitize_title( $label ) . ( $list ? '' : '-' . $loop ), 'label' => wp_strip_all_tags( $label ), 'price' => wc_format_decimal( $option['price'] ?? 0 ), 'price_type' => sanitize_key( $option['price_type'] ?? 'quantity_based' ) );
			}
			$addons[] = array(
				'key' => 'addon-' . $addon['field_name'], 'name' => wp_strip_all_tags( $addon['name'] ), 'type' => $type,
				'required' => ! empty( $addon['required'] ), 'options' => $options, 'price' => wc_format_decimal( $addon['price'] ?? 0 ),
				'price_type' => sanitize_key( $addon['price_type'] ?? 'quantity_based' ), 'display' => sanitize_key( $addon['display'] ?? '' ),
				'min' => ! empty( $addon['restrictions'] ) ? ( $addon['min'] ?? '' ) : '', 'max' => ! empty( $addon['restrictions'] ) ? ( $addon['max'] ?? '' ) : '',
				'restrictions_type' => sanitize_key( $addon['restrictions_type'] ?? '' ),
			);
		}
		$variations = array();
		$attribute_options = self::attribute_options( $product );
		if ( $product->is_type( 'variable' ) ) {
			foreach ( self::available_variations( $product, $attribute_options ) as $variation ) {
				$variations[] = array(
					'id' => $variation->get_id(), 'label' => wp_strip_all_tags( wc_get_formatted_variation( $variation, true, false, true ) ) ?: $variation->get_name(),
					'attributes' => $variation->get_variation_attributes(), 'price' => wc_format_decimal( $variation->get_price( 'edit' ) ), 'in_stock' => $variation->is_in_stock(),
				);
			}
			if ( ! $variations ) {
				return self::error( 'product_unavailable', 'This product has no available options for a customer order.', 404 );
			}
		}
		$price = $variations ? min( array_map( 'floatval', array_column( $variations, 'price' ) ) ) : $product->get_price( 'edit' );
		$stock = $variations ? ( in_array( true, array_column( $variations, 'in_stock' ), true ) ? 'instock' : 'outofstock' ) : $product->get_stock_status();
		return array(
			'id' => $product->get_id(), 'name' => $product->get_name(), 'type' => $product->get_type(), 'variations' => $variations,
			'price' => wc_format_decimal( $price ), 'stock_status' => $stock,
			'addons' => $addons, 'sold_individually' => $product->is_sold_individually(), 'quantity_per_line' => self::is_gift( $product ) ? 1 : null,
			'attribute_options' => $attribute_options,
		);
	}

	/** Revalidates client selections and invokes native plugin hooks on the isolated cart. */
	public static function add_to_cart( \WC_Cart $cart, array $line ) {
		$product_id = isset( $line['product_id'] ) && is_numeric( $line['product_id'] ) ? (int) $line['product_id'] : 0;
		$variation_id = isset( $line['variation_id'] ) && is_numeric( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;
		$quantity = $line['quantity'] ?? 1;
		if ( ! is_numeric( $quantity ) || ! is_finite( (float) $quantity ) || (float) $quantity <= 0 || (float) $quantity > 9999 || floor( (float) $quantity ) !== (float) $quantity ) {
			return self::error( 'product_quantity', 'Enter a whole product quantity between 1 and 9,999.' );
		}
		$quantity = (int) $quantity;
		$product = wc_get_product( $product_id );
		if ( $product instanceof \WC_Product && $product->is_type( 'variation' ) && ! $variation_id ) {
			$variation_id = $product->get_id();
			$product_id = $product->get_parent_id();
			$product = wc_get_product( $product_id );
		}
		if ( ! self::eligible( $product ) ) {
			return self::error( 'product_unavailable', 'This product is unavailable or is an event ticket.' );
		}
		if ( $product->is_type( 'variable' ) && ! $variation_id ) {
			return self::error( 'product_variation', 'Choose a product variation.' );
		}
		$selected = $variation_id ? wc_get_product( $variation_id ) : $product;
		if ( ! self::eligible( $selected ) || ( $variation_id && ( ! $selected->is_type( 'variation' ) || $selected->get_parent_id() !== $product_id ) ) ) {
			return self::error( 'product_variation', 'This variation does not belong to the selected product.' );
		}
		if ( ( $selected->is_sold_individually() || self::is_gift( $product ) ) && 1 !== $quantity ) {
			return self::error( 'product_quantity', 'Add this product one at a time, with its own product options.' );
		}
		if ( ! $selected->is_purchasable() || ! $selected->is_in_stock() || ! $selected->has_enough_stock( $quantity ) ) {
			return self::error( 'product_stock', 'This product does not have enough available stock.' );
		}
		$configuration = self::configuration( $product_id );
		if ( is_wp_error( $configuration ) ) {
			return $configuration;
		}
		$values = $line['addons'] ?? array();
		if ( ! is_array( $values ) ) {
			return self::error( 'product_addons', 'Product options must be named fields.' );
		}
		$post = array( 'add-to-cart' => (string) $product_id, 'quantity' => (string) $quantity, 'variation_id' => (string) $variation_id );
		$allowed_keys = array();
		foreach ( $configuration['addons'] as $addon ) {
			$key = $addon['key'];
			$allowed_keys[] = $key;
			if ( 'heading' === $addon['type'] ) {
				continue;
			}
			if ( ! in_array( $addon['type'], array( 'custom_text', 'custom_textarea', 'input_multiplier', 'custom_price', 'multiple_choice', 'checkbox', 'datepicker' ), true ) ) {
				return self::error( 'product_addon_unsupported', 'This product requires an option that Cart Cat cannot collect yet. Use its store product page.' );
			}
			$value = $values[ $key ] ?? ( 'checkbox' === $addon['type'] ? array() : '' );
			if ( 'checkbox' === $addon['type'] ) {
				if ( ! is_array( $value ) || count( $value ) > 100 || count( array_filter( $value, 'is_scalar' ) ) !== count( $value ) ) {
					return self::error( 'product_addons', 'Choose valid product options.' );
				}
				$value = array_map( 'sanitize_text_field', $value );
			} elseif ( ! is_scalar( $value ) || strlen( (string) $value ) > 10000 ) {
				return self::error( 'product_addons', 'Enter a valid product option.' );
			} else {
				$value = 'custom_textarea' === $addon['type'] ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
			}
			if ( $addon['required'] && ( '' === $value || array() === $value ) ) {
				return self::error( 'product_addon_required', $addon['name'] . ' is required.' );
			}
			if ( $addon['options'] && '' !== $value && array() !== $value ) {
				$options = array_column( $addon['options'], 'value' );
				foreach ( (array) $value as $option ) {
					if ( ! in_array( $option, $options, true ) ) {
						return self::error( 'product_addon_invalid', 'Choose an available option for ' . $addon['name'] . '.' );
					}
				}
			}
			$post[ $key ] = $value;
			if ( 'datepicker' === $addon['type'] && '' !== $value ) {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
					return self::error( 'product_addon_date', 'Choose a valid product date.' );
				}
				$post[ $key . '-wc-pao-date' ] = $date->getTimestamp();
				$post[ $key . '-wc-pao-date-gmt-offset' ] = 0;
			}
		}
		if ( array_diff( array_keys( $values ), $allowed_keys ) ) {
			return self::error( 'product_addons', 'The available product options changed. Configure the product again.', 409 );
		}
		$attributes = $variation_id ? $selected->get_variation_attributes() : array();
		$submitted = isset( $line['attributes'] ) && is_array( $line['attributes'] ) ? $line['attributes'] : array();
		foreach ( $attributes as $key => $value ) {
			if ( '' === $value && isset( $submitted[ $key ] ) && is_scalar( $submitted[ $key ] ) ) {
				$attributes[ $key ] = sanitize_text_field( (string) $submitted[ $key ] );
			}
		}
		if ( self::is_glass_selection( $product, $attributes ) ) {
			return self::error( 'product_variation', 'Wine by the glass cannot be added to a customer order.' );
		}
		$post = array_merge( $post, $attributes );
		$old_post = $_POST;
		$old_get = $_GET;
		$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices() : array();
		$before = $cart->get_cart();
		try {
			$_POST = wp_slash( $post );
			unset( $_GET['add-to-cart'], $_GET['order_again'], $_GET['pay_for_order'] );
			// Native add-on validation is also called directly because Woo's Store API
			// compatibility layer may skip the classic hook on REST requests.
			$addon_cart = $GLOBALS['Product_Addon_Cart'] ?? null;
			if ( $configuration['addons'] && ( ! is_object( $addon_cart ) || ! method_exists( $addon_cart, 'validate_cart_item_data' ) ) ) {
				return self::error( 'product_addons_unavailable', 'Product option validation is unavailable.' );
			}
			if ( is_object( $addon_cart ) && method_exists( $addon_cart, 'validate_cart_item_data' ) ) {
				$valid = $addon_cart->validate_cart_item_data( $product_id, $_POST );
				if ( is_wp_error( $valid ) ) {
					return self::error( 'product_addon_invalid', wp_strip_all_tags( $valid->get_error_message() ) );
				}
			}
			$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $attributes, $_POST );
			if ( ! $passed ) {
				return self::notice_error( 'Product validation failed. Check its options.' );
			}
			$data = self::is_gift( $product ) ? array( '_cart_cat_gift_line' => count( $before ) ) : array();
			$override = $line['price_override'] ?? null;
			if ( null !== $override && ! $selected->is_sold_individually() ) {
				// Adjusted rows keep their own quantity, including two overrides at the same price.
				$data['_cart_cat_price_line'] = count( $before );
			}
			$key = $cart->add_to_cart( $product_id, $quantity, $variation_id, $attributes, $data );
			if ( ! $key ) {
				$cart->set_cart_contents( $before );
				return self::notice_error( 'This product could not be added. Check stock and options.' );
			}
			if ( null !== $override ) { $cart->cart_contents[ $key ]['_cart_cat_price_override'] = $override; }
			return $key;
		} catch ( \Throwable $error ) {
			$cart->set_cart_contents( $before );
			return self::error( 'product_add_failed', 'This product could not be added. Check stock and options.' );
		} finally {
			$_POST = $old_post;
			$_GET = $old_get;
			if ( function_exists( 'wc_set_notices' ) ) {
				wc_set_notices( $notices );
			}
		}
	}

	/** Server-side exclusions also apply to direct variation IDs. */
	private static function eligible( $product ) {
		if ( ! $product instanceof \WC_Product || ! in_array( $product->get_type(), array( 'simple', 'variable', 'variation' ), true ) || 'publish' !== $product->get_status() ) {
			return false;
		}
		foreach ( array_filter( array( $product->get_id(), $product->get_parent_id() ) ) as $id ) {
			if ( get_post_meta( $id, '_tribe_wooticket_for_event', true ) || ( function_exists( 'tec_rb_get_ticket_event_id_for_product' ) && tec_rb_get_ticket_event_id_for_product( $id ) ) ) {
				return false;
			}
		}
		if ( $product->is_type( 'variation' ) && self::is_glass_selection( $product, $product->get_variation_attributes() ) ) {
			return false;
		}
		return true;
	}

	/** Keep the catalog and price preview on the same allowed variation set. */
	private static function available_variations( \WC_Product $product, array $attribute_options ): array {
		$variations = array();
		foreach ( $product->get_children() as $id ) {
			$variation = wc_get_product( $id );
			if ( ! self::eligible( $variation ) || ! $variation->is_purchasable() ) {
				continue;
			}
			foreach ( $variation->get_variation_attributes() as $key => $value ) {
				if ( '' === $value && empty( $attribute_options[ $key ]['values'] ) ) {
					continue 2;
				}
			}
			$variations[] = $variation;
		}
		return $variations;
	}

	private static function attribute_options( \WC_Product $product ): array {
		$options = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_attributes() as $attribute ) {
				if ( ! $attribute->get_variation() ) {
					continue;
				}
				$key = 'attribute_' . sanitize_title( $attribute->get_name() );
				$values = array_values( array_filter( $attribute->get_slugs(), static function ( $value ) use ( $product, $key ) {
					return ! self::is_glass_selection( $product, array( $key => $value ) );
				} ) );
				$options[ $key ] = array( 'label' => wc_attribute_label( $attribute->get_name() ), 'values' => $values );
			}
		}
		return $options;
	}

	/** Match the storefront's two Square import schemas without hiding glassware. */
	private static function is_glass_selection( \WC_Product $product, array $attributes ): bool {
		$glass = false;
		foreach ( array( 'attribute_pa_wine-servings', 'attribute_pa_option' ) as $key ) {
			if ( 'glass' === sanitize_title( (string) ( $attributes[ $key ] ?? '' ) ) ) {
				$glass = true;
				break;
			}
		}
		if ( ! $glass ) {
			return false;
		}
		if ( function_exists( 'minimly_child_is_wine_product' ) ) {
			return (bool) minimly_child_is_wine_product( $product );
		}
		// Preserve the store's category convention when running without its theme.
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$terms = get_the_terms( $id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return false;
		}
		$wine_keys = array( 'wine', 'wines', 'white', 'whites', 'red', 'reds', 'rose', 'rose-wine', 'sparkling', 'dessert', 'dessert-wine' );
		foreach ( $terms as $term ) {
			$ancestors = array_merge( array( $term->term_id ), get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
			foreach ( $ancestors as $term_id ) {
				$category = get_term( $term_id, 'product_cat' );
				if ( ! $category || is_wp_error( $category ) ) {
					continue;
				}
				foreach ( array( $category->slug, $category->name ) as $value ) {
					$key = sanitize_title( $value );
					if ( in_array( $key, $wine_keys, true ) || false !== strpos( $key, 'wine' ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}
	private static function addons( $id ) {
		if ( class_exists( '\WC_Product_Addons_Helper' ) ) {
			return (array) \WC_Product_Addons_Helper::get_product_addons( $id );
		}
		if ( get_post_meta( $id, '_product_addons', true ) ) {
			return self::error( 'product_addons_unavailable', 'Product Add-ons must be available to configure this product.' );
		}
		return array();
	}
	public static function is_gift( $product ) { return 'gift-membership' === $product->get_slug(); }
	private static function notice_error( $fallback ) {
		$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
		$notice = end( $notices );
		return self::error( 'product_validation', is_array( $notice ) && ! empty( $notice['notice'] ) ? wp_strip_all_tags( $notice['notice'] ) : $fallback );
	}
	private static function error( $code, $message, $status = 400 ) { return new \WP_Error( 'cart_cat_' . $code, $message, array( 'status' => $status ) ); }
}
