<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

/** One customer deadline, with renewable inventory protection for an unresolved payment. */
final class Stock_Hold {
	public static function register(): void {
		add_filter( 'woocommerce_order_hold_stock_minutes', array( __CLASS__, 'reservation_minutes' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'native_cancellation' ), PHP_INT_MAX, 2 );
	}
	public static function config(): array {
		$default = (int) get_option( 'woocommerce_hold_stock_minutes', 60 );
		if ( $default < 15 || $default > 10080 ) { $default = 60; }
		$steps = array_values( array_unique( array_merge( array( 15, 30, 45, 60 ), range( 120, 1440, 60 ), array( 2880, 4320, 10080, $default ) ) ) );
		sort( $steps, SORT_NUMERIC );
		return array( 'default_minutes' => $default, 'steps' => $steps, 'timezone' => wp_timezone_string() );
	}
	public static function normalize( $minutes ) {
		if ( ! is_int( $minutes ) || $minutes < 15 || $minutes > 10080 ) { return new \WP_Error( 'cart_cat_hold_minutes', 'Choose a stock hold between 15 minutes and 7 days.', array( 'status' => 422 ) ); }
		return $minutes;
	}
	public static function managed( $order ): bool {
		return Payments::owns( $order ) && (int) $order->get_meta( '_cart_cat_hold_minutes' ) >= 15;
	}
	public static function establish( $order, int $minutes ): void {
		$order->update_meta_data( '_cart_cat_hold_minutes', $minutes );
		$order->update_meta_data( '_cart_cat_expires_at', time() + $minutes * MINUTE_IN_SECONDS );
	}
	/** Save the confirmed email hold and request together with native stock reservations. */
	public static function confirm_email( $order, int $minutes, int $deadline ) {
		global $wpdb;
		if ( ! Payment_Lock::owned( $order->get_id() ) ) { return new \WP_Error( 'cart_cat_payment_busy', 'The order is being updated. Reload it before sending the email.', array( 'status' => 409 ) ); }
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return new \WP_Error( 'cart_cat_stock_hold', 'The stock hold could not be updated. No email was sent.', array( 'status' => 409 ) ); }
		try {
			$order->update_meta_data( '_cart_cat_hold_minutes', $minutes );
			$order->update_meta_data( '_cart_cat_expires_at', $deadline );
			$input = $order->get_meta( '_cart_cat_input' );
			if ( is_array( $input ) ) { $input['hold_minutes'] = $minutes; $order->update_meta_data( '_cart_cat_input', $input ); }
			$review = $order->get_meta( '_cart_cat_review' );
			if ( is_array( $review ) ) { $review['hold_minutes'] = $minutes; $order->update_meta_data( '_cart_cat_review', $review ); }
			// Woo deletes an order's existing reservations on failure. Roll back those deletions too.
			try { wc_reserve_stock_for_order( $order ); }
			catch ( \Automattic\WooCommerce\Checkout\Helpers\ReserveStockException $error ) {
				// A same-second native no-op UPDATE reports zero affected rows and releases the hold.
				// Its second atomic attempt reinserts that row; a real shortage still fails and rolls back.
				wc_reserve_stock_for_order( $order );
			}
			if ( ! Payment_Lock::owned( $order->get_id() ) ) { throw new \RuntimeException( 'Order lock was lost.' ); }
			$order->save_meta_data();
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'Stock hold commit failed.' ); }
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			// HPOS caches can contain objects read inside the rolled-back transaction.
			$id = $order->get_id(); clean_post_cache( $id );
			\WC_Cache_Helper::invalidate_cache_group( 'object_' . $id );
			if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) { wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id ); }
			foreach ( array( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore', '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta' ) as $class ) {
				if ( class_exists( $class ) && method_exists( $class, 'clear_cached_data' ) ) { wc_get_container()->get( $class )->clear_cached_data( array( $id ) ); }
			}
			$order->read_meta_data( true );
			return new \WP_Error( 'cart_cat_stock_hold', 'The stock hold could not be updated. The previous deadline remains; no email was sent.', array( 'status' => 409 ) );
		}
		self::schedule( $order );
		return true;
	}
	public static function payment_status( $order ): string {
		if ( $order->is_paid() ) { return 'paid'; }
		$attempt = $order->get_meta( '_cart_cat_payment_attempt' );
		if ( ! is_array( $attempt ) || ! $attempt ) { return 'unpaid'; }
		if ( 'declined' === ( $attempt['state'] ?? '' ) ) { return 'declined'; }
		if ( 'paid' === ( $attempt['state'] ?? '' ) && ! empty( $attempt['payment_id'] ) && $order->get_transaction_id() === $attempt['payment_id'] ) { return 'paid'; }
		return 'checking';
	}
	public static function expired( $order ): bool {
		return self::deadline_passed( $order ) && Payments::can_expire( $order );
	}
	public static function deadline_passed( $order ): bool {
		return self::managed( $order ) && (int) $order->get_meta( '_cart_cat_expires_at' ) <= time();
	}
	public static function validate( $order ) {
		return self::expired( $order ) ? new \WP_Error( 'cart_cat_order_expired', 'This order’s stock hold has expired. Ask the winery to review and prepare the order again.', array( 'status' => 409 ) ) : true;
	}
	public static function details( $order ): array {
		$minutes = self::managed( $order ) ? (int) $order->get_meta( '_cart_cat_hold_minutes' ) : (int) get_option( 'woocommerce_hold_stock_minutes', 60 );
		$deadline = (int) $order->get_meta( '_cart_cat_expires_at' );
		if ( ! $deadline && ! self::managed( $order ) ) { $deadline = (int) wp_next_scheduled( 'cart_cat_expire_order', array( $order->get_id() ) ); }
		$status = self::payment_status( $order );
		$active = 'paid' !== $status && $order->has_status( 'pending' ) && $deadline > time();
		return array( 'minutes' => $minutes, 'expires_at' => $deadline, 'expires_label' => $deadline ? wp_date( 'M j, Y \a\t g:i a T', $deadline ) : '', 'active' => $active );
	}
	public static function reservation_minutes( $minutes, $order ): int {
		if ( ! self::managed( $order ) ) { return (int) $minutes; }
		$remaining = max( 0, (int) ceil( ( (int) $order->get_meta( '_cart_cat_expires_at' ) - time() ) / MINUTE_IN_SECONDS ) );
		// A payment already sent must be reconciled even after its customer deadline.
		return 'checking' === self::payment_status( $order ) ? max( 15, $remaining ) : $remaining;
	}
	public static function native_cancellation( $cancel, $order ): bool {
		// Only the Cart Cat callback owns the payment mutex and may cancel these orders.
		return Payments::owns( $order ) ? false : (bool) $cancel;
	}
	public static function schedule( $order ): void {
		wp_clear_scheduled_hook( 'cart_cat_expire_order', array( $order->get_id() ) );
		$deadline = (int) $order->get_meta( '_cart_cat_expires_at' );
		if ( $deadline > 0 ) { wp_schedule_single_event( $deadline, 'cart_cat_expire_order', array( $order->get_id() ) ); }
	}
	public static function duration_label( int $minutes ): string {
		if ( 0 === $minutes % 1440 ) { $count = intdiv( $minutes, 1440 ); return $count . ( 1 === $count ? ' day' : ' days' ); }
		if ( 0 === $minutes % 60 ) { $count = intdiv( $minutes, 60 ); return $count . ( 1 === $count ? ' hour' : ' hours' ); }
		return $minutes . ' minutes';
	}
	public static function note( $order ): string {
		$hold = self::details( $order );
		return ' Stock held for ' . self::duration_label( $hold['minutes'] ) . '; payment due by ' . $hold['expires_label'] . '.';
	}
}
