<?php
/** Isolated contact contract checks; no WordPress bootstrap, database, network, or provider calls. */
namespace {
	if ( PHP_SAPI !== 'cli' || defined( 'ABSPATH' ) ) { throw new \RuntimeException( 'Run this standalone fixture directly with PHP CLI.' ); }
	define( 'ABSPATH', __DIR__ );
	function get_current_user_id() { return 1; }
	function current_user_can( $capability ) { return 'manage_woocommerce' === $capability; }
	function get_option( $name, $default = false ) { return $default; }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
	function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wc_get_product( $id ) { return null; }
	function get_user_meta( $id, $key, $single ) { return $GLOBALS['contact_user_meta'][ $key ] ?? ''; }
	class WP_Error {}
	class WC_Validation {
		public static function is_phone( $phone ) { return ! preg_match( '/[^\s#0-9_+\/().-]/', $phone ); }
	}
	class WC_Order {
		public $meta = array( '_cart_cat_order' => '1' );
		public $addresses = array( 'billing' => array( 'email' => 'buyer@example.test', 'phone' => '2025550101' ), 'shipping' => array( 'phone' => '' ) );
		public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
		public function set_address( $address, $kind ) {
			if ( 'shipping' === $kind && array_key_exists( 'email', $address ) ) { throw new \RuntimeException( 'Woo shipping email is not a native property.' ); }
			$this->addresses[ $kind ] = array_merge( $this->addresses[ $kind ], $address );
		}
		public function get_address( $kind ) { return $this->addresses[ $kind ]; }
		public function get_items( $types ) { return array(); }
		public function get_customer_id() { return 0; }
		public function get_currency() { return 'USD'; }
		public function __call( $method, $arguments ) {
			if ( 0 === strpos( $method, 'get_billing_' ) ) { return $this->addresses['billing'][ substr( $method, 12 ) ] ?? ''; }
			if ( 0 === strpos( $method, 'get_' ) ) { return '0.00'; }
			throw new \RuntimeException( 'Unexpected fixture call: ' . $method );
		}
	}
}
namespace Cart_Cat {
	class Catalog {
		public static function configuration( $id ) { return array( 'addons' => array(), 'sold_individually' => false, 'quantity_per_line' => null ); }
	}
}
namespace {
	foreach ( array( 'customers', 'quote', 'orders', 'payments' ) as $class ) { require __DIR__ . '/../cart-cat/includes/class-' . $class . '.php'; }
	$count = 0;
	$assert = static function ( $condition, $message ) use ( &$count ) { $count++; if ( ! $condition ) { throw new \RuntimeException( $message ); } };
	$invoke = static function ( $class, $method, ...$arguments ) { return ( new \ReflectionMethod( $class, $method ) )->invoke( null, ...$arguments ); };
	$input = array( 'first_name' => 'Buying', 'last_name' => 'Customer', 'email' => 'Buyer@example.test', 'phone' => '2025550101', 'shipping' => array( 'first_name' => 'Receiving', 'last_name' => 'Customer', 'email' => ' Recipient@example.test ', 'phone' => '<b>+1 (202) 555-0102</b>', 'country' => 'us', 'state' => 'va' ) );
	$customer = \Cart_Cat\Customers::resolve( $input );
	$assert( 'recipient@example.test' === $customer['shipping']['email'], 'Recipient email is accepted and sanitized.' );
	$assert( '+1 (202) 555-0102' === $customer['shipping']['phone'], 'Recipient phone is accepted and sanitized.' );
	$assert( 'buyer@example.test' === $customer['email'] && 'buyer@example.test' === $customer['billing']['email'], 'Purchaser email remains independent.' );
	$assert( '2025550101' === $customer['billing']['phone'], 'Purchaser phone remains independent.' );
	$assert( 'US' === $customer['shipping']['country'] && 'VA' === $customer['shipping']['state'], 'Address normalization is retained.' );
	$missing = $input; unset( $missing['shipping']['email'], $missing['shipping']['phone'] );
	$blank = \Cart_Cat\Customers::resolve( $missing );
	$assert( '' === $blank['shipping']['email'] && '' === $blank['shipping']['phone'], 'Missing recipient contact stays blank instead of silently copying purchaser contact.' );
	$invalid = $input; $invalid['shipping']['email'] = array( 'attacker@example.test' ); $invalid['shipping']['phone'] = array( '2025550199' );
	$resolved = \Cart_Cat\Customers::resolve( $invalid );
	$assert( '' === $resolved['shipping']['email'] && '' === $resolved['shipping']['phone'], 'Structured values cannot inject recipient contact.' );
	$GLOBALS['contact_user_meta'] = array( 'shipping_email' => 'Profile-Recipient@example.test', 'shipping_phone' => '2025550103' );
	$profile = $invoke( \Cart_Cat\Customers::class, 'woo_fields', (object) array( 'ID' => 9, 'user_email' => 'profile@example.test' ) );
	$assert( 'profile-recipient@example.test' === $profile['shipping']['email'] && '2025550103' === $profile['shipping']['phone'], 'Stored Woo recipient contact is available for prefill.' );
	$assert( array() === $invoke( \Cart_Cat\Quote::class, 'validate_shipping_contact', $customer['shipping'] ), 'Valid recipient contact passes preparation validation.' );
	$notices = $invoke( \Cart_Cat\Quote::class, 'validate_shipping_contact', $blank['shipping'] );
	$assert( 2 === count( $notices ), 'Both missing recipient fields are reported.' );
	foreach ( array( '', 'not-an-email', 'person@example' ) as $email ) {
		$notices = $invoke( \Cart_Cat\Quote::class, 'validate_shipping_contact', array_merge( $customer['shipping'], array( 'email' => $email ) ) );
		$assert( array( 'Enter a valid shipping recipient email address.' ) === $notices, 'Invalid recipient email is blocked without rejecting the valid phone.' );
	}
	foreach ( array( '', '+()-', 'not-a-number' ) as $phone ) {
		$notices = $invoke( \Cart_Cat\Quote::class, 'validate_shipping_contact', array_merge( $customer['shipping'], array( 'phone' => $phone ) ) );
		$assert( array( 'Enter a valid shipping recipient phone number.' ) === $notices, 'Invalid recipient phone is blocked without rejecting the valid email.' );
	}
	$order = new WC_Order();
	$invoke( \Cart_Cat\Orders::class, 'set_shipping_address', $order, $customer['shipping'] );
	$assert( 'recipient@example.test' === $order->get_meta( '_shipping_email' ), 'Recipient email is stored as shipping metadata.' );
	$assert( '+1 (202) 555-0102' === $order->get_address( 'shipping' )['phone'], 'Recipient phone is passed through the native Woo shipping address.' );
	$assert( 'buyer@example.test' === $order->get_address( 'billing' )['email'], 'Saving shipping never mutates billing.' );
	$serialized = $invoke( \Cart_Cat\Orders::class, 'shipping_address', $order );
	$assert( 'recipient@example.test' === $serialized['email'] && '+1 (202) 555-0102' === $serialized['phone'], 'Order serialization restores independent recipient contact.' );
	$order->update_meta_data( '_cart_cat_input', array( 'lines' => array( array( 'product_id' => 1 ) ), 'hold_minutes' => 60, 'customer' => array( 'shipping' => array( 'email' => 'outdated@example.test' ) ) ) );
	$editable = $invoke( \Cart_Cat\Orders::class, 'editable_input', $order );
	$assert( 'recipient@example.test' === $editable['customer']['shipping']['email'] && '+1 (202) 555-0102' === $editable['customer']['shipping']['phone'], 'Reopened input uses current saved recipient contact over an outdated input snapshot.' );
	$before = \Cart_Cat\Payments::fingerprint( $order );
	$order->update_meta_data( '_shipping_email', 'changed@example.test' );
	$assert( $before !== \Cart_Cat\Payments::fingerprint( $order ), 'An outside recipient email edit invalidates the payment fingerprint.' );
	$order->update_meta_data( '_shipping_email', 'recipient@example.test' );
	$order->set_address( array( 'phone' => '2025550199' ), 'shipping' );
	$assert( $before !== \Cart_Cat\Payments::fingerprint( $order ), 'An outside recipient phone edit invalidates the payment fingerprint.' );
	$invoke( \Cart_Cat\Orders::class, 'set_shipping_address', $order, $blank['shipping'] );
	$assert( ! array_key_exists( '_shipping_email', $order->meta ) && '' === $order->get_address( 'shipping' )['phone'], 'Clearing recipient contact removes stale values during reprepare.' );
	$legacy = \Cart_Cat\Payments::fingerprint( $order );
	$order->update_meta_data( '_shipping_email', '' );
	$assert( $legacy === \Cart_Cat\Payments::fingerprint( $order ), 'Empty recipient email does not alter existing order fingerprints.' );
	echo 'Cart Cat shipping contact contract: ' . $count . " assertions passed.\n";
}
