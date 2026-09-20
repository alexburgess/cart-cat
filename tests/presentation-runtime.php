<?php
/** Display-only fixtures; local Woo runtime only. Nothing is mailed or charged. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable local Woo runtime only.' ); }
if ( ! defined( 'CART_CAT_PATH' ) ) { require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php'; }

use Cart_Cat\Presentation;
use Cart_Cat\Payments;

// Exercise the active plugin registration, including the branding plugin's pickup wrapper.
// Re-registering here would add the original callback alongside that wrapper.
$count = 0; $product = null;
$assert = static function ( $test, $message ) use ( &$count ) { ++$count; if ( ! $test ) { throw new RuntimeException( $message ); } };
$make_order = static function ( $owned = true, $method = 'pickup_location', $cost = '0' ) {
	$order = new WC_Order(); $order->set_currency( 'USD' ); $order->set_status( 'pending' ); $order->set_total( '40' ); $order->set_payment_method( 'cart_cat_square' ); $order->set_payment_method_title( 'Square — Cart Cat' );
	if ( $owned ) { $order->update_meta_data( '_cart_cat_order', '1' ); }
	$item = new WC_Order_Item_Shipping(); $item->set_method_id( $method ); $item->set_method_title( 'Pickup (Saved Tasting Room)' ); $item->set_total( $cost );
	$item->add_meta_data( 'pickup_location', 'Saved Tasting Room' ); $item->add_meta_data( 'pickup_address', '123 Fixture Lane, Leesburg, VA 20175' ); $item->add_meta_data( 'pickup_details', 'Bring your order number.<br>Ask at the welcome desk.' ); $order->add_item( $item ); $order->set_shipping_total( $cost );
	return $order;
};
$email_output = static function ( $order, $admin, $plain, $email ) { ob_start(); do_action( 'woocommerce_email_after_order_table', $order, $admin, $plain, $email ); return ob_get_clean(); };
try {
	$order = $make_order(); $snapshot = Payments::fingerprint( $order ); $email = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'];
	$totals = $order->get_order_item_totals();
	$assert( ! isset( $totals['payment_method'] ), 'Customer totals omit the internal Cart Cat payment gateway title.' );
	$assert( 'Pickup:' === $totals['shipping']['label'] && 'Complimentary' === $totals['shipping']['value'] && ! isset( $totals['shipping']['meta'] ), 'Complimentary pickup has one concise row without duplicated location metadata.' );
	$locations = Presentation::pickup_locations( $order );
	$assert( array( array( 'name' => 'Saved Tasting Room', 'address' => '123 Fixture Lane, Leesburg, VA 20175', 'details' => "Bring your order number.\nAsk at the welcome desk." ) ) === $locations, 'Pickup details are derived from the saved shipping line and preserve useful line breaks.' );
	$html = $email_output( $order, false, false, $email ); $plain = $email_output( $order, false, true, $email );
	$assert( false !== strpos( $html, '<h2>Pickup at the winery</h2>' ) && 1 === substr_count( $html, 'Saved Tasting Room' ) && false !== strpos( $html, 'Bring your order number.<br' ), 'HTML invoice section includes saved location, address and clean instructions once.' );
	$assert( false !== strpos( $plain, "Pickup at the winery\nSaved Tasting Room\n" ) && false === strpos( $plain, '<' ), 'Plain-text customer email contains useful pickup information without HTML.' );
	$assert( '' === $email_output( $order, true, false, $email ), 'Admin email receives no added customer pickup section.' );
	$assert( '' === $email_output( $order, false, false, WC()->mailer()->get_emails()['WC_Email_New_Order'] ), 'A non-customer email object receives no added pickup section.' );
	$assert( $snapshot === Payments::fingerprint( $order ), 'Presentation leaves all saved financial and fulfillment data unchanged.' );
	$order->set_status( 'processing' ); $assert( ! isset( $order->get_order_item_totals()['payment_method'] ), 'Paid order details also hide the internal gateway name.' );
	$ordinary = $make_order( false ); $ordinary_totals = $ordinary->get_order_item_totals();
	$assert( isset( $ordinary_totals['payment_method'] ) && 'Shipping:' === $ordinary_totals['shipping']['label'], 'Ordinary Woo order totals remain unchanged.' );
	$assert( '' === $email_output( $ordinary, false, false, $email ), 'Ordinary Woo customer email receives no Cart Cat section.' );
	$shipping = $make_order( true, 'ups', '12' ); $shipping_totals = $shipping->get_order_item_totals();
	$assert( 'Shipping:' === $shipping_totals['shipping']['label'] && ! Presentation::is_pickup( $shipping ) && array() === Presentation::pickup_locations( $shipping ), 'A shipped Cart Cat order retains shipping presentation.' );
	$assert( '' === $email_output( $shipping, false, false, $email ), 'Shipped orders receive no pickup instructions.' );
	$paid_pickup = $make_order( true, 'pickup_location', '5' ); $paid_pickup->set_shipping_tax( '0.30' );
	$assert( false !== strpos( $paid_pickup->get_order_item_totals( 'excl' )['shipping']['value'], '5.00' ) && false !== strpos( $paid_pickup->get_order_item_totals( 'incl' )['shipping']['value'], '5.30' ), 'Nonzero pickup preserves the correct tax-exclusive or inclusive charge.' );
	$fallback = $make_order(); $line = current( $fallback->get_items( 'shipping' ) ); $line->delete_meta_data( 'pickup_location' ); $line->delete_meta_data( 'pickup_address' ); $line->delete_meta_data( 'pickup_details' ); $line->add_meta_data( '_pickup_location_address', array( 'country' => 'US', 'state' => 'VA', 'postcode' => '20175', 'city' => 'Leesburg', 'address_1' => '456 Structured Lane' ) );
	$fallback_locations = Presentation::pickup_locations( $fallback );
	$assert( 'Pickup (Saved Tasting Room)' === $fallback_locations[0]['name'] && false !== strpos( $fallback_locations[0]['address'], '456 Structured Lane' ) && '' === $fallback_locations[0]['details'], 'Missing display metadata falls back to saved method name and structured pickup address.' );
	$line->update_meta_data( 'pickup_details', '<script>unexpected()</script>Bring &lt;your&gt; order &amp; ID.' );
	$assert( false === strpos( $email_output( $fallback, false, false, $email ), '<script' ), 'Saved instruction markup cannot inject HTML into customer email.' );
	$args = array( 'order' => $order, 'sent_to_admin' => false, 'plain_text' => false, 'show_image' => false, 'image_size' => array( 32, 32 ) );
	$images = apply_filters( 'woocommerce_email_order_items_args', $args );
	$assert( true === $images['show_image'] && array( 64, 64 ) === $images['image_size'], 'Cart Cat customer HTML email requests 64px native product images.' );
	$admin_args = array_merge( $args, array( 'sent_to_admin' => true ) ); $plain_args = array_merge( $args, array( 'plain_text' => true ) ); $ordinary_args = array_merge( $args, array( 'order' => $ordinary ) );
	$assert( $admin_args === apply_filters( 'woocommerce_email_order_items_args', $admin_args ) && $plain_args === apply_filters( 'woocommerce_email_order_items_args', $plain_args ) && $ordinary_args === apply_filters( 'woocommerce_email_order_items_args', $ordinary_args ), 'Admin, plain-text and ordinary order image options remain unchanged.' );
	$product = new WC_Product_Simple(); $product->set_name( 'Cart Cat presentation fixture ' . wp_generate_uuid4() ); $product->set_status( 'publish' ); $product->set_regular_price( '40' ); $product->save();
	$product_item = new WC_Order_Item_Product(); $product_item->set_product( $product ); $product_item->set_quantity( 1 ); $product_item->set_subtotal( '40' ); $product_item->set_total( '40' ); $order->add_item( $product_item );
	$items_html = wc_get_email_order_items( $order, array( 'show_image' => false ) );
	$assert( false !== strpos( $items_html, '<img' ) && false !== strpos( $items_html, 'placeholder' ), 'A product without an image uses WooCommerce native placeholder rendering.' );
	// Frozen original prices differ from both Woo's automatic-price subtotal and final manual price.
	$discounted = new WC_Order(); $discounted->set_currency( 'USD' ); $discounted->update_meta_data( '_cart_cat_order', '1' );
	$wine = new WC_Order_Item_Product(); $wine->set_product( $product ); $wine->set_name( 'Saved wine name' ); $wine->set_quantity( 2 ); $wine->set_subtotal( '80' ); $wine->set_total( '72' ); $wine->set_taxes( array( 'subtotal' => array( 98201 => '4.80' ), 'total' => array( 98201 => '4.32' ) ) ); $discounted->add_item( $wine );
	$gift = new WC_Order_Item_Product(); $gift->set_name( 'Untaxed gift' ); $gift->set_quantity( 1 ); $gift->set_subtotal( '36' ); $gift->set_total( '32.40' ); $discounted->add_item( $gift );
	$freight = new WC_Order_Item_Shipping(); $freight->set_method_id( 'ups' ); $freight->set_method_title( 'UPS Ground' ); $freight->set_total( '5' ); $freight->set_taxes( array( 'total' => array( 98201 => '0.30' ) ) ); $discounted->add_item( $freight );
	$rate = new WC_Order_Item_Tax(); $rate->set_rate_id( 98201 ); $rate->set_rate_percent( 6 ); $rate->set_label( 'Saved wine tax' ); $rate->set_tax_total( '4.32' ); $rate->set_shipping_tax_total( '0.30' ); $discounted->add_item( $rate );
	$discounted->set_discount_total( '11.60' ); $discounted->set_discount_tax( '0.48' ); $discounted->set_cart_tax( '4.32' ); $discounted->set_shipping_tax( '0.30' ); $discounted->set_shipping_total( '5' ); $discounted->set_total( '114.02' );
	$review = array( 'lines' => array( array( 'name' => 'Saved wine name', 'quantity' => 2, 'subtotal' => '100', 'automatic_savings' => '20', 'total' => '72', 'tax' => '4.32' ), array( 'name' => 'Untaxed gift', 'quantity' => 1, 'subtotal' => '40', 'automatic_savings' => '4', 'total' => '32.40', 'tax' => '0' ) ), 'automatic_discounts' => array( array( 'kind' => 'role', 'label' => 'Club Member Discount', 'amount' => '20' ), array( 'kind' => 'quantity', 'label' => '12+ Bottle Case', 'amount' => '4' ) ), 'totals' => array( 'total' => '114.02' ) );
	$discounted->update_meta_data( '_cart_cat_review', $review ); $before_pricing = Payments::fingerprint( $discounted );
	$exclusive = Presentation::customer_pricing( $discounted, 'excl' ); $inclusive = Presentation::customer_pricing( $discounted, 'incl' );
	$exclusive_lines = array_values( $exclusive['lines'] ); $inclusive_lines = array_values( $inclusive['lines'] );
	$assert( 100.0 === $exclusive_lines[0]['original'] && 72.0 === $exclusive_lines[0]['total'] && $exclusive_lines[0]['discounted'], 'Original price comes from the frozen review and payable price includes the manual discount.' );
	$assert( 40.0 === $exclusive_lines[1]['original'] && 32.4 === $exclusive_lines[1]['total'], 'Separate product lines retain their own original and payable amounts.' );
	$assert( 106.0 === $inclusive_lines[0]['original'] && 76.32 === $inclusive_lines[0]['total'] && 40.0 === $inclusive_lines[1]['original'], 'Tax-inclusive line prices use saved rate percentages for taxed lines and preserve exempt lines.' );
	$assert( str_contains( $exclusive['totals']['cart_subtotal']['value'], '140.00' ) && str_contains( $exclusive['totals']['cart_cat_automatic_discount']['value'], '24.00' ) && str_contains( $exclusive['totals']['discount']['value'], '11.60' ), 'Exclusive summary restores the original subtotal and subtracts automatic and manual discounts once.' );
	$assert( str_contains( $inclusive['totals']['cart_subtotal']['value'], '146.00' ) && str_contains( $inclusive['totals']['cart_cat_automatic_discount']['value'], '25.20' ) && str_contains( $inclusive['totals']['discount']['value'], '12.08' ), 'Inclusive summary includes each mixed-class discount tax reduction without proportional tax guessing.' );
	$assert( 'Club Member Discount / 12+ Bottle Case:' === $exclusive['totals']['cart_cat_automatic_discount']['label'] && 'Additional discount:' === $exclusive['totals']['discount']['label'], 'Customer summary preserves actual saved role and case names and distinguishes the extra manual discount.' );
	$assert( $discounted->get_order_item_totals( 'excl' )['order_total'] === $exclusive['totals']['order_total'] && $discounted->get_order_item_totals( 'incl' )['order_total'] === $inclusive['totals']['order_total'], 'Both display modes retain the native fixed grand total.' );
	$assert( $before_pricing === Payments::fingerprint( $discounted ) && $exclusive === Presentation::customer_pricing( $discounted, 'excl' ), 'Repeated pricing display is deterministic and does not change the order.' );
	$product->set_regular_price( '999' ); $product->set_price( '999' ); $product->save();
	$assert( $exclusive === Presentation::customer_pricing( $discounted, 'excl' ), 'A later catalog price change cannot alter the original price or discount shown.' );
	$old_review = $review; unset( $old_review['automatic_discounts'] ); $discounted->update_meta_data( '_cart_cat_review', $old_review ); $discounted->update_meta_data( '_cart_cat_role_discount_names', array( 'Legacy Club Discount' ) );
	$assert( 'Legacy Club Discount:' === Presentation::customer_pricing( $discounted, 'excl' )['totals']['cart_cat_automatic_discount']['label'], 'Older saved reviews fall back to their stored rule names.' );
	$discounted->delete_meta_data( '_cart_cat_review' ); $legacy = Presentation::customer_pricing( $discounted, 'excl' );
	$assert( ! isset( $legacy['totals']['cart_cat_automatic_discount'] ) && 80.0 === array_values( $legacy['lines'] )[0]['original'] && 72.0 === array_values( $legacy['lines'] )[0]['total'], 'Without an original snapshot, only the provable saved manual discount is shown.' );
	$discounted->update_meta_data( '_cart_cat_review', $review ); $wine->set_quantity( 3 );
	$assert( ! isset( Presentation::customer_pricing( $discounted, 'excl' )['totals']['cart_cat_automatic_discount'] ), 'A changed order line invalidates the old snapshot instead of displaying stale savings.' );
	$wine->set_quantity( 2 ); $rate->set_rate_id( 98202 );
	$assert( ! isset( Presentation::customer_pricing( $discounted, 'incl' )['totals']['cart_cat_automatic_discount'] ) && isset( Presentation::customer_pricing( $discounted, 'excl' )['totals']['cart_cat_automatic_discount'] ), 'Missing historical tax rates hide an unprovable inclusive original while exclusive snapshots remain usable.' );
	$fractional = new WC_Order(); $fractional->set_currency( 'USD' ); $fractional->update_meta_data( '_cart_cat_order', '1' );
	$fractional_lines = array();
	foreach ( array( 'Small line one', 'Small line two' ) as $name ) {
		$small = new WC_Order_Item_Product(); $small->set_name( $name ); $small->set_subtotal( '.05' ); $small->set_total( '.05' ); $small->set_taxes( array( 'subtotal' => array( 98201 => '.003' ), 'total' => array( 98201 => '.003' ) ) ); $fractional->add_item( $small );
		$fractional_lines[] = array( 'name' => $name, 'quantity' => 1, 'subtotal' => '.10', 'automatic_savings' => '.05', 'total' => '.05', 'tax' => '0.00' );
	}
	$fractional_rate = new WC_Order_Item_Tax(); $fractional_rate->set_rate_id( 98201 ); $fractional_rate->set_rate_percent( 6 ); $fractional->add_item( $fractional_rate );
	foreach ( array( 'no' => array( '.10', '.22', '.12' ), 'yes' => array( '.11', '.21', '.10' ) ) as $round_setting => $expected ) {
		$round_filter = static function () use ( $round_setting ) { return $round_setting; }; add_filter( 'pre_option_woocommerce_tax_round_at_subtotal', $round_filter );
		try {
			foreach ( $fractional->get_items() as $small ) { $small->set_taxes( array( 'subtotal' => array( 98201 => '.003' ), 'total' => array( 98201 => '.003' ) ) ); }
			$fractional->set_total( $expected[0] ); $fractional->update_meta_data( '_cart_cat_review', array( 'lines' => $fractional_lines, 'totals' => array( 'total' => $expected[0] ) ) ); $fractional_pricing = Presentation::customer_pricing( $fractional, 'incl' );
			$assert( str_contains( $fractional_pricing['totals']['cart_subtotal']['value'], '0' . $expected[1] ) && str_contains( $fractional_pricing['totals']['cart_cat_automatic_discount']['value'], '0' . $expected[2] ), 'Fractional taxes follow Woo ' . $round_setting . ' subtotal rounding and keep discount subtraction exact.' );
		} finally { remove_filter( 'pre_option_woocommerce_tax_round_at_subtotal', $round_filter ); }
	}
	$second_rate = new WC_Order_Item_Tax(); $second_rate->set_rate_id( 98202 ); $second_rate->set_rate_percent( 6 ); $fractional->add_item( $second_rate );
	foreach ( array( 'no' => array( '.10', '.24', '.14' ), 'yes' => array( '.11', '.22', '.11' ) ) as $round_setting => $expected ) {
		$round_filter = static function () use ( $round_setting ) { return $round_setting; }; add_filter( 'pre_option_woocommerce_tax_round_at_subtotal', $round_filter );
		try {
			foreach ( $fractional->get_items() as $small ) { $small->set_taxes( array( 'subtotal' => array( 98201 => '.003', 98202 => '.003' ), 'total' => array( 98201 => '.003', 98202 => '.003' ) ) ); }
			foreach ( $fractional_lines as &$small_review ) { $small_review['tax'] = 'yes' === $round_setting ? '0.01' : '0.00'; } unset( $small_review );
			$fractional->set_total( $expected[0] ); $fractional->update_meta_data( '_cart_cat_review', array( 'lines' => $fractional_lines, 'totals' => array( 'total' => $expected[0] ) ) ); $fractional_pricing = Presentation::customer_pricing( $fractional, 'incl' );
			$assert( str_contains( $fractional_pricing['totals']['cart_subtotal']['value'], '0' . $expected[1] ) && str_contains( $fractional_pricing['totals']['cart_cat_automatic_discount']['value'], '0' . $expected[2] ), 'Multiple saved tax rates follow Woo per-rate rounding with subtotal mode ' . $round_setting . '.' );
		} finally { remove_filter( 'pre_option_woocommerce_tax_round_at_subtotal', $round_filter ); }
	}
	// Render Woo's real invoice order-details table, without triggering or sending an email.
	$rate->set_rate_id( 98201 ); $wine->set_quantity( 2 ); $discounted->set_status( 'pending' ); $discounted->set_date_created( '2026-09-11 12:00:00' );
	$fee = new WC_Order_Item_Fee(); $fee->set_name( 'Gift wrapping' ); $fee->set_total( '2.00' ); $discounted->add_item( $fee );
	$discounted->set_total( '116.02' ); $review['totals']['total'] = '116.02'; $discounted->update_meta_data( '_cart_cat_review', $review );
	$render_details = static function ( $target, $admin, $plain, $mail ) {
		$shipping_filter = has_filter( 'woocommerce_order_shipping_to_display_shipped_via', '__return_false' );
		ob_start();
		try { do_action( 'woocommerce_email_order_details', $target, $admin, $plain, $mail ); return ob_get_contents(); }
		finally {
			ob_end_clean();
			// Woo's plain email template leaves this native display filter installed; isolate fixture renders.
			if ( false === $shipping_filter ) { remove_filter( 'woocommerce_order_shipping_to_display_shipped_via', '__return_false' ); }
		}
	};
	$text = static function ( $html ) { return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ); };
	$totals_seen = array(); $native_seen = array();
	$observe_totals = static function ( $rows, $target, $display ) use ( &$totals_seen, $discounted ) { if ( $target === $discounted && doing_action( 'woocommerce_email_order_details' ) ) { $totals_seen[] = $rows; } return $rows; };
	$observe_native = static function ( $rows, $target, $display ) use ( &$native_seen, $discounted ) { if ( $target === $discounted && doing_action( 'woocommerce_email_order_details' ) ) { $native_seen[] = $rows; } return $rows; };
	add_filter( 'woocommerce_get_order_item_totals', $observe_native, 29, 3 );
	add_filter( 'woocommerce_get_order_item_totals', $observe_totals, PHP_INT_MAX, 3 );
	try {
		foreach ( array( 'excl', 'incl' ) as $display ) {
			$display_filter = static function () use ( $display ) { return $display; }; add_filter( 'pre_option_woocommerce_tax_display_cart', $display_filter );
			try {
				$expected = Presentation::customer_pricing( $discounted, $display ); $native_before = $discounted->get_order_item_totals( $display ); $saved_before = Payments::fingerprint( $discounted );
				$totals_seen = array(); $native_seen = array(); $invoice_html = $render_details( $discounted, false, false, $email );
				$assert( 1 === count( $totals_seen ) && 1 === count( $native_seen ) && Presentation::customer_pricing( $discounted, $display, $native_seen[0] )['totals'] === $totals_seen[0], 'HTML invoice totals use native email rows without recursive totals calls (' . $display . ').' );
				foreach ( array( 'cart_subtotal', 'cart_cat_automatic_discount', 'discount' ) as $key ) { $assert( $expected['totals'][ $key ] === $totals_seen[0][ $key ], 'Invoice ' . $key . ' matches the customer payment page (' . $display . ').' ); }
				$assert( 1 === substr_count( $invoice_html, 'Club Member Discount / 12+ Bottle Case:' ) && str_contains( $invoice_html, 'Additional discount:' ), 'Invoice lists the applied club/case names and additional manual discount once (' . $display . ').' );
				$assert( 2 === substr_count( $invoice_html, '<del class="cc-email-original-price"' ) && str_contains( $invoice_html, 'cc-email-discounted-price' ), 'Both discounted invoice products show crossed-out originals above final payable prices (' . $display . ').' );
				foreach ( $native_seen[0] as $key => $native_row ) { if ( ! in_array( $key, array( 'cart_subtotal', 'discount' ), true ) ) { $assert( $native_row === $totals_seen[0][ $key ], 'Invoice retains native email ' . $key . ' row exactly (' . $display . ').' ); } }
				$assert( str_contains( $invoice_html, 'Gift wrapping' ) && str_contains( $invoice_html, '116.02' ), 'Invoice preserves fees and fixed total (' . $display . ').' );
				$totals_seen = array(); $native_seen = array(); $invoice_plain = $render_details( $discounted, false, true, $email );
				$assert( 1 === count( $totals_seen ) && 1 === count( $native_seen ) && Presentation::customer_pricing( $discounted, $display, $native_seen[0] )['totals'] === $totals_seen[0] && str_contains( $invoice_plain, 'Club Member Discount / 12+ Bottle Case:' ), 'Plain invoice lists the same saved discounts and amounts (' . $display . ').' );
				$assert( str_contains( $text( $invoice_plain ), 'Originally ' . ( 'incl' === $display ? '$106.00; after discounts $76.32' : '$100.00; after discounts $72.00' ) ) && ! str_contains( $invoice_plain, '<del' ), 'Plain product pricing names original and payable amounts without strike-through markup (' . $display . ').' );
				$assert( $native_before === $discounted->get_order_item_totals( $display ) && $saved_before === Payments::fingerprint( $discounted ) && ! str_contains( $discounted->get_formatted_line_subtotal( $wine ), 'cc-email-' ), 'Invoice rendering leaves ordinary totals, line formatting, and saved finances unchanged (' . $display . ').' );
			} finally { remove_filter( 'pre_option_woocommerce_tax_display_cart', $display_filter ); }
		}
	} finally { remove_filter( 'woocommerce_get_order_item_totals', $observe_totals, PHP_INT_MAX ); remove_filter( 'woocommerce_get_order_item_totals', $observe_native, 29 ); }
	$other_email = WC()->mailer()->get_emails()['WC_Email_Customer_Processing_Order'];
	foreach ( array( array( true, $email ), array( false, $other_email ) ) as $case ) {
		$unaffected = $render_details( $discounted, $case[0], false, $case[1] );
		$assert( ! str_contains( $unaffected, 'cc-email-original-price' ) && ! str_contains( $unaffected, 'Club Member Discount / 12+ Bottle Case:' ), 'Admin invoice and other customer-email types keep native price/discount conventions.' );
	}
	$non_cart_cat = new WC_Order(); $non_cart_cat->set_currency( 'USD' ); $non_cart_cat->set_status( 'pending' ); $non_cart_cat->set_date_created( '2026-09-11 12:00:00' ); $non_cart_cat->set_total( '116.02' ); $non_cart_cat->set_discount_total( '11.60' );
	foreach ( $discounted->get_items() as $fixture_line ) { $non_cart_cat->add_item( clone $fixture_line ); }
	$non_cart_cat->update_meta_data( '_cart_cat_review', $review );
	$unaffected = $render_details( $non_cart_cat, false, false, $email );
	$assert( ! str_contains( $unaffected, 'cc-email-original-price' ) && ! str_contains( $unaffected, 'Club Member Discount / 12+ Bottle Case:' ), 'Ordinary Woo invoices never acquire Cart Cat discount presentation.' );
	$nested = ''; $nesting = false;
	$nested_email = static function ( $target, $admin, $plain, $mail ) use ( &$nested, &$nesting, $discounted, $email, $other_email, $render_details ) {
		if ( $target === $discounted && $mail === $email && ! $nesting ) { $nesting = true; try { $nested = $render_details( $discounted, false, false, $other_email ); } finally { $nesting = false; } }
	};
	add_action( 'woocommerce_email_order_details', $nested_email, 5, 4 );
	try { $outer = $render_details( $discounted, false, false, $email ); }
	finally { remove_action( 'woocommerce_email_order_details', $nested_email, 5 ); }
	$assert( ! str_contains( $nested, 'cc-email-original-price' ) && ! str_contains( $nested, 'Club Member Discount / 12+ Bottle Case:' ) && str_contains( $outer, 'cc-email-original-price' ) && 1 === substr_count( $outer, 'Club Member Discount / 12+ Bottle Case:' ), 'Nested other emails mask and then restore the outer invoice pricing scope.' );
	$discounted->delete_meta_data( '_cart_cat_review' ); $legacy_invoice = $render_details( $discounted, false, false, $email );
	$assert( ! str_contains( $legacy_invoice, 'Club Member Discount / 12+ Bottle Case:' ) && str_contains( $legacy_invoice, 'cc-email-original-price' ) && str_contains( $legacy_invoice, '116.02' ), 'Legacy invoices show only provable saved manual savings, without inventing an original club price.' );
	echo 'Cart Cat presentation runtime: ' . $count . " assertions passed.\n";
} finally { if ( $product && $product->get_id() ) { $product->delete( true ); } }
