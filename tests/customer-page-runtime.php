<?php
/** Native Woo order-pay rendering, with isolated local fixtures and no mail/payment. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Disposable local Woo runtime only.' ); }
require_once dirname( __DIR__ ) . '/cart-cat/cart-cat.php';
Cart_Cat\Presentation::register(); Cart_Cat\Customer_Page::register();
$assertions = 0; $order = null; $product = null; $ordinary = null; $old_user = get_current_user_id(); $old_get = $_GET;
$assert = static function ( $test, $message ) use ( &$assertions ) { ++$assertions; if ( ! $test ) { throw new RuntimeException( $message ); } };
$gateway = new class extends WC_Payment_Gateway {
 public function __construct() { $this->id = 'cart_cat_square'; }
 public function payment_fields() { echo '<div class="cart-cat-payment"><div style="border:1px solid #ccc;border-radius:4px;padding:14px;font:14px sans-serif;color:#555">Card number &nbsp;&nbsp;&nbsp; MM/YY &nbsp; CVV</div><p>Secure card entry provided by Square.</p><input type="hidden" name="cart_cat_token" value="" /></div>'; }
};
$render = static function ( $order, $gateways ) { ob_start(); wc_get_template( 'checkout/form-pay.php', array( 'order' => $order, 'available_gateways' => $gateways, 'order_button_text' => 'Pay for order' ) ); return ob_get_clean(); };
try {
 wp_set_current_user( 0 );
 $product = new WC_Product_Simple(); $product->set_name( '2025 Rosé of Nebbiolo — Bottle (750ml)' ); $product->set_regular_price( '48' ); $product->set_status( 'publish' ); $product->save();
 $order = new WC_Order(); $order->set_status( 'pending' ); $order->set_currency( 'USD' ); $order->set_order_key( wc_generate_order_key() ); $order->set_billing_email( 'customer@example.test' ); $order->set_payment_method( 'cart_cat_square' ); $order->set_payment_method_title( 'Square — Cart Cat' ); $order->update_meta_data( '_cart_cat_order', '1' ); $order->update_meta_data( '_cart_cat_preparation', 'ready' );
 $line = new WC_Order_Item_Product(); $line->set_product( $product ); $line->set_quantity( 2 ); $line->set_subtotal( '81.60' ); $line->set_total( '81.60' ); $order->add_item( $line );
 $shipping = new WC_Order_Item_Shipping(); $shipping->set_method_id( 'pickup_location' ); $shipping->set_method_title( 'Collection from The Club Shop at Tower View Tasting Room' ); $shipping->add_meta_data( 'pickup_location', 'The Club Shop at Tower View Tasting Room' ); $shipping->add_meta_data( 'pickup_address', 'Gate 2, 19925 Hogback Mountain Road, Leesburg, VA 20175' ); $shipping->add_meta_data( 'pickup_details', 'During our normal opening hours, please pop by and pickup your shipment from our club shop, located at the Tower View tasting room.' ); $order->add_item( $shipping ); $order->set_total( '81.60' ); $order->save();
 $order->update_meta_data( '_cart_cat_review', array( 'lines' => array( array( 'name' => $line->get_name(), 'quantity' => 2, 'subtotal' => '96.00', 'total' => '81.60', 'tax' => '0.00', 'automatic_savings' => '14.40' ) ), 'automatic_discounts' => array( array( 'kind' => 'role', 'label' => 'Club Member Discount', 'amount' => '14.40' ) ), 'totals' => array( 'total' => '81.60' ) ) ); $order->save();
 $_GET['key'] = $order->get_order_key();
 $assert( Cart_Cat\Customer_Page::authorized( $order ), 'Guest ownership follows Woo pay_for_order capability plus exact key.' );
 $html = $render( $order, array( 'cart_cat_square' => $gateway ) );
 $assert( str_contains( $html, 'class="cc-customer-pay"' ) && str_contains( $html, 'id="order_review"' ), 'Authorized Cart Cat order uses the scoped native form override.' );
 $assert( str_contains( $html, 'name="payment_method" value="cart_cat_square"' ) && str_contains( $html, 'name="woocommerce_pay" value="1"' ) && str_contains( $html, 'name="woocommerce-pay-nonce"' ) && str_contains( $html, 'id="place_order"' ), 'Native gateway selection, pay action, nonce and submit contracts remain intact.' );
 $assert( str_contains( $html, 'name="cart_cat_token"' ) && ! str_contains( $html, 'Cards on file' ), 'Hosted fields remain in the form without public saved cards.' );
 $assert( str_contains( $html, '<img' ) && str_contains( $html, 'Quantity: 2' ) && str_contains( $html, '2025 Rosé' ), 'Product rows contain image, name and fixed quantity.' );
 $assert( str_contains( $html, 'Pay $81.60' ) && ! str_contains( $html, 'name="quantity' ), 'Prepared total is shown on the button with no quantity editors.' );
 $assert( ! str_contains( $html, 'Payment method:' ) && ! str_contains( $html, 'Square — Cart Cat' ) && ! str_contains( $html, 'Shipping:' ), 'Internal payment title and misleading pickup shipping title are hidden.' );
 $assert( str_contains( $html, 'Pickup at the winery' ) && str_contains( $html, 'Complimentary' ) && 1 === substr_count( $html, 'Gate 2, 19925' ), 'Pickup has a concise summary and separate saved address.' );
 $assert( str_contains( $html, '<del class="cc-pay-original-price">' ) && str_contains( $html, '96.00' ) && str_contains( $html, '<span class="cc-pay-current-price">' ), 'Discounted line renders an accessible crossed-out original above the actual payable amount.' );
 $assert( str_contains( $html, 'class="cc-pay-discount"' ) && str_contains( $html, 'Club Member Discount:' ) && str_contains( $html, '14.40' ), 'The customer summary shows the saved named discount explicitly.' );
 if ( ! empty( $args[0] ) ) { file_put_contents( $args[0], $html ); }
 $_GET['key'] = 'incorrect';
 $assert( ! Cart_Cat\Customer_Page::authorized( $order ) && '/native.php' === Cart_Cat\Customer_Page::template( '/native.php', 'checkout/form-pay.php', array( 'order' => $order ), '', '' ), 'Incorrect key cannot activate the template or disclose the order.' );
 $_GET['key'] = $order->get_order_key(); $order->set_customer_id( 999999 ); $order->save();
 $assert( ! Cart_Cat\Customer_Page::authorized( $order ), 'Account-linked orders retain native access checks for anonymous users.' );
 $order->set_customer_id( 0 ); $order->save();
 $ordinary = new WC_Order(); $ordinary->set_order_key( wc_generate_order_key() ); $ordinary->save(); $_GET['key'] = $ordinary->get_order_key();
 $assert( '/native.php' === Cart_Cat\Customer_Page::template( '/native.php', 'checkout/form-pay.php', array( 'order' => $ordinary ), '', '' ), 'Ordinary Woo payment pages retain their original template.' );
 $_GET['key'] = $order->get_order_key(); $order->update_meta_data( '_cart_cat_preparation', 'editing' ); $order->save(); $editing = $render( $order, array( 'cart_cat_square' => $gateway ) );
 $assert( str_contains( $editing, 'The winery is updating this order' ) && str_contains( $editing, ' disabled' ) && ! str_contains( $editing, 'name="cart_cat_token"' ), 'Editing orders cannot present an active card form.' );
 $order->update_meta_data( '_cart_cat_preparation', 'ready' ); $order->save(); $unavailable = $render( $order, array() );
 $assert( str_contains( $unavailable, 'Payment is currently unavailable' ) && str_contains( $unavailable, ' disabled' ), 'Unavailable gateway leaves a clear disabled payment action.' );
 $order->update_meta_data( '_cart_cat_hold_minutes', 240 ); $order->update_meta_data( '_cart_cat_expires_at', time() + 240 * MINUTE_IN_SECONDS ); $order->save();
 $active_hold = $render( $order, array( 'cart_cat_square' => $gateway ) );
 $assert( str_contains( $active_hold, 'Complete payment by ' ) && str_contains( $active_hold, 'name="cart_cat_token"' ), 'An active managed hold shows its saved customer payment deadline.' );
 $order->update_meta_data( '_cart_cat_expires_at', time() - 1 ); $order->save();
 $expired_hold = $render( $order, array( 'cart_cat_square' => $gateway ) );
 $assert( str_contains( $expired_hold, 'The payment deadline has passed' ) && str_contains( $expired_hold, ' disabled' ) && ! str_contains( $expired_hold, 'name="cart_cat_token"' ), 'An expired hold hides hosted fields and disables payment even when a stale gateway is supplied.' );
 $order->update_meta_data( '_cart_cat_payment_attempt', array( 'state' => 'checking' ) ); $order->save();
 $checking_hold = $render( $order, array( 'cart_cat_square' => $gateway ) );
 $assert( ! str_contains( $checking_hold, 'The payment deadline has passed' ) && str_contains( $checking_hold, 'name="cart_cat_token"' ), 'An existing checking attempt keeps the gateway available to render its reconciliation state after the customer deadline.' );
 echo 'Cart Cat customer page runtime: ' . $assertions . " assertions passed.\n";
} finally {
 foreach ( array( $order, $ordinary, $product ) as $object ) { if ( $object && $object->get_id() ) { $object->delete( true ); } }
 $_GET = $old_get; wp_set_current_user( $old_user );
}
