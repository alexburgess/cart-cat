<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

final class Orders {
	public static function request_key( string $request_id, string $request_scope = '' ): string {
		return hash( 'sha256', ( '' === $request_scope ? get_current_user_id() : $request_scope ) . ':' . $request_id );
	}
	/** The optional scope is server-owned; draft retries must share identity across staff. */
	public static function create( array $input, string $quote_hash, string $request_id, string $request_scope = '' ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-zA-Z0-9-]{16,80}$/', $request_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $quote_hash ) ) { return self::error( 'request', 'Refresh the quote before preparing this order.' ); }
		$key = self::request_key( $request_id, $request_scope );
		$lock = 'cc-create-' . substr( $key, 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { return self::error( 'busy', 'This order is already being prepared. Retry with the same order request.', 409 ); }
		try {
			$record = get_option( 'cart_cat_request_' . $key, false );
			if ( false !== $record ) {
				$order = wc_get_order( (int) ( $record['order_id'] ?? 0 ) );
				if ( ! $order ) { return self::error( 'preparation_review', 'Order preparation was interrupted. Review the existing request before preparing another.', 409 ); }
				if ( ! hash_equals( (string) $order->get_meta( '_cart_cat_version' ), $quote_hash ) ) { return self::error( 'request_changed', 'This request already belongs to a different order version.', 409 ); }
				if ( 'ready' !== $order->get_meta( '_cart_cat_preparation' ) ) { return self::error( 'preparation_review', 'Order preparation was interrupted. Review the existing order before preparing another.', 409, array( 'order_id' => $order->get_id(), 'edit_url' => $order->get_edit_order_url() ) ); }
				return self::get( $order->get_id() );
			}
			return Quote::with_quote( $input, true, static function ( $quote ) use ( $quote_hash, $key ) {
				if ( ! $quote['can_submit'] || ! hash_equals( $quote['hash'], $quote_hash ) ) { return self::error( 'stale_quote', 'The order has changed. Review the refreshed totals before continuing.', 409 ); }
				$customer = $quote['_customer'];
				if ( empty( $customer['first_name'] ) || empty( $customer['last_name'] ) || empty( $customer['email'] ) || ! is_email( $customer['email'] ) ) { return self::error( 'customer', 'Enter the customer name and a valid email address.' ); }
				$order = new \WC_Order();
				try {
					if ( ! add_option( 'cart_cat_request_' . $key, array( 'order_id' => 0, 'hash' => $quote_hash ), '', false ) ) { return self::error( 'request_busy', 'This order request is already being prepared.', 409 ); }
					$order->set_created_via( 'cart_cat' );
					$order->set_status( 'checkout-draft' );
					$order->set_customer_id( $customer['woo_id'] );
					$order->set_currency( $quote['currency'] );
					$order->set_prices_include_tax( wc_prices_include_tax() );
					$order->set_payment_method( 'cart_cat_square' );
					$order->set_payment_method_title( 'Square — Cart Cat' );
					$order->set_address( array_merge( $customer['billing'], array( 'first_name' => $customer['first_name'], 'last_name' => $customer['last_name'], 'email' => $customer['email'], 'phone' => $customer['phone'] ?? '' ) ), 'billing' );
					self::set_shipping_address( $order, $customer['shipping'] );
					$order->update_meta_data( '_cart_cat_order', '1' );
					$order->update_meta_data( '_cart_cat_request', $key );
					$order->update_meta_data( '_cart_cat_version', $quote['hash'] );
					$order->update_meta_data( '_cart_cat_preparation', 'building' );
					$order->update_meta_data( '_cart_cat_staff_id', get_current_user_id() );
					$order->update_meta_data( '_cart_cat_square_customer_id', $customer['square_id'] ?? '' );
					$order->update_meta_data( '_cart_cat_square_scope', $quote['_square_scope'] );
					$order->update_meta_data( '_cart_cat_discount', $quote['_input']['discount'] );
					$order->update_meta_data( '_cart_cat_pricing_roles', $customer['roles'] );
					$order->update_meta_data( '_cart_cat_tax_location', Context::current()['pickup_address'] );
					self::save_review( $order, $quote );
					$order->save(); // Durable request identity precedes any line-item save.
					update_option( 'cart_cat_request_' . $key, array( 'order_id' => $order->get_id(), 'hash' => $quote_hash ), false );
					WC()->checkout()->set_data_from_cart( $order );
					self::save_heat_hold( $order, $quote );
					$order->set_status( 'pending' );
					Stock_Hold::establish( $order, $quote['_input']['hold_minutes'] );
					$order->save();
					if ( wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) !== $quote['totals']['total'] ) { throw new \RuntimeException( 'Total mismatch' ); }
					wc_reserve_stock_for_order( $order );
					$order->update_meta_data( '_cart_cat_snapshot', Payments::fingerprint( $order ) );
					$order->update_meta_data( '_cart_cat_preparation', 'ready' );
					$order->save();
					self::save_staff_note( $order );
					$order->add_order_note( self::preparation_note( $quote ) . Stock_Hold::note( $order ) );
					Stock_Hold::schedule( $order );
					return self::get( $order->get_id() );
				} catch ( \Throwable $e ) {
					if ( $order->get_id() ) {
						$order->update_meta_data( '_cart_cat_preparation', 'review' );
						$order->save();
						$order->add_order_note( 'Cart Cat preparation did not finish. No payment was submitted. Review this order before retrying.' );
						wc_release_stock_for_order( $order );
					}
					return self::error( 'prepare_failed', 'The order could not be prepared. Review the existing order before trying again.', 409, $order->get_id() ? array( 'order_id' => $order->get_id(), 'edit_url' => $order->get_edit_order_url() ) : array() );
				}
			} );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	public static function load( int $id ) {
		$order = wc_get_order( $id );
		return $order && '1' === $order->get_meta( '_cart_cat_order' ) ? $order : self::error( 'not_found', 'Cart Cat order not found.', 404 );
	}
	public static function get( int $id ) {
		$order = self::load( $id ); if ( is_wp_error( $order ) ) { return $order; }
		$input = self::editable_input( $order );
		$editable = self::editable( $order );
		return array( 'order_id' => $id, 'number' => $order->get_order_number(), 'status' => $order->get_status(), 'total' => $order->get_total(), 'currency' => $order->get_currency(), 'billing' => $order->get_address( 'billing' ), 'shipping' => self::shipping_address( $order ), 'payment_url' => $order->get_checkout_payment_url(), 'edit_url' => $order->get_edit_order_url(), 'version' => $order->get_meta( '_cart_cat_version' ), 'paid' => $order->is_paid(), 'preparation' => $order->get_meta( '_cart_cat_preparation' ), 'payment_email' => self::payment_email( $order ), 'hold' => Stock_Hold::details( $order ), 'payment_status' => Stock_Hold::payment_status( $order ), 'order_notes' => (string) $order->get_meta( '_cart_cat_order_notes' ), 'order_notes_visible' => 'yes' === $order->get_meta( '_cart_cat_order_notes_visible' ), 'can_edit' => true === $editable && ! is_wp_error( $input ), 'edit_message' => is_wp_error( $editable ) ? $editable->get_error_message() : ( is_wp_error( $input ) ? $input->get_error_message() : '' ), 'input' => is_wp_error( $input ) ? null : $input, 'customer' => self::review( $order )['customer'], 'review' => self::review( $order ) );
	}
	/** Suspend payment before exposing editable fields. The native order and its stock hold remain. */
	public static function edit( int $id, string $version ) {
		$lease = Payment_Lock::acquire( $id ); if ( is_wp_error( $lease ) ) { return $lease; }
		try {
			$order = self::load( $id ); if ( is_wp_error( $order ) ) { return $order; }
			if ( ! hash_equals( (string) $order->get_meta( '_cart_cat_version' ), $version ) ) { return self::error( 'order_changed', 'The order changed. Reload its current details before editing.', 409 ); }
			$editable = self::editable( $order ); if ( is_wp_error( $editable ) ) { return $editable; }
			$input = self::editable_input( $order ); if ( is_wp_error( $input ) ) { return $input; }
			if ( 'editing' !== $order->get_meta( '_cart_cat_preparation' ) ) {
				if ( ! Payment_Lock::owned( $id ) ) { return self::error( 'payment_busy', 'The order lock was lost. Reload its current status before editing.', 409 ); }
				$attempt = $order->get_meta( '_cart_cat_payment_attempt' );
				if ( is_array( $attempt ) && $attempt ) {
					$history = $order->get_meta( '_cart_cat_payment_history' );
					$history = is_array( $history ) ? $history : array(); $history[] = $attempt;
					$order->update_meta_data( '_cart_cat_payment_history', $history );
					$order->delete_meta_data( '_cart_cat_payment_attempt' );
					$order->delete_meta_data( 'square_order_id' );
					wp_clear_scheduled_hook( 'cart_cat_reconcile_payment', array( $id ) );
				}
				$order->update_meta_data( '_cart_cat_preparation', 'editing' );
				$order->save();
				$order->add_order_note( sprintf( 'Opened for changes in Cart Cat by %s. Payment is paused until the order is prepared again.', wp_get_current_user()->display_name ) );
			}
			return self::get( $id );
		} finally { Payment_Lock::release( $lease ); }
	}
	/** Replace a suspended order's prepared contents, never its identity or payment history. */
	public static function prepare( int $id, array $input, string $quote_hash, string $version, string $request_id ) {
		if ( ! preg_match( '/^[a-zA-Z0-9-]{16,80}$/', $request_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $quote_hash ) ) { return self::error( 'request', 'Refresh the quote before preparing this order.' ); }
		$lease = Payment_Lock::acquire( $id ); if ( is_wp_error( $lease ) ) { return $lease; }
		try {
			$order = self::load( $id ); if ( is_wp_error( $order ) ) { return $order; }
			$request = hash( 'sha256', get_current_user_id() . ':' . $request_id . ':' . $version . ':' . $quote_hash );
			if ( 'ready' === $order->get_meta( '_cart_cat_preparation' ) && hash_equals( (string) $order->get_meta( '_cart_cat_reprepare_request' ), $request ) ) { return self::get( $id ); }
			if ( ! hash_equals( (string) $order->get_meta( '_cart_cat_version' ), $version ) ) { return self::error( 'order_changed', 'The order changed. Reload its current details before preparing it.', 409 ); }
			$editable = self::editable( $order ); if ( is_wp_error( $editable ) ) { return $editable; }
			if ( 'editing' !== $order->get_meta( '_cart_cat_preparation' ) ) { return self::error( 'not_editing', 'Open the order for changes before preparing it again.', 409 ); }
			return Quote::with_quote( $input, true, static function ( $quote ) use ( $order, $quote_hash, $request ) {
				if ( ! $quote['can_submit'] || ! hash_equals( $quote['hash'], $quote_hash ) ) { return self::error( 'stale_quote', 'The order has changed. Review the refreshed totals before continuing.', 409 ); }
				$customer = $quote['_customer'];
				if ( empty( $customer['first_name'] ) || empty( $customer['last_name'] ) || empty( $customer['email'] ) || ! is_email( $customer['email'] ) ) { return self::error( 'customer', 'Enter the customer name and a valid email address.' ); }
				try {
					if ( ! Payment_Lock::owned( $order->get_id() ) ) { return self::error( 'payment_busy', 'The order lock was lost. Reload its current status before preparing it.', 409 ); }
					$order->update_meta_data( '_cart_cat_preparation', 'building' );
					$order->set_customer_id( $customer['woo_id'] ); $order->set_currency( $quote['currency'] ); $order->set_prices_include_tax( wc_prices_include_tax() );
					$order->set_address( array_merge( $customer['billing'], array( 'first_name' => $customer['first_name'], 'last_name' => $customer['last_name'], 'email' => $customer['email'], 'phone' => $customer['phone'] ?? '' ) ), 'billing' );
					self::set_shipping_address( $order, $customer['shipping'] );
					$order->update_meta_data( '_cart_cat_staff_id', get_current_user_id() );
					$order->update_meta_data( '_cart_cat_square_customer_id', $customer['square_id'] ?? '' );
					$order->update_meta_data( '_cart_cat_square_scope', $quote['_square_scope'] );
					$order->update_meta_data( '_cart_cat_discount', $quote['_input']['discount'] );
					$order->update_meta_data( '_cart_cat_pricing_roles', $customer['roles'] );
					$order->update_meta_data( '_cart_cat_tax_location', Context::current()['pickup_address'] );
					foreach ( array( '_hhh_shipping_preference', '_hhh_weather_hold_period_id', '_hhh_weather_hold_period_title', '_hhh_weather_hold_period_start', '_hhh_weather_hold_period_end', '_hhh_weather_hold_period_states', '_hhh_destination_country', '_hhh_destination_state', '_hhh_wine_category_id', '_hhh_choice_was_required', '_hhh_choice_selected_at', '_hhh_shipping_preference_label', '_hhh_checkout_copy_hash' ) as $key ) { $order->delete_meta_data( $key ); }
					// Persist one recoverable customer/product selection before any native line write can fail.
					// Rotate guest access in that same save, before changed recipient details become visible.
					self::save_review( $order, $quote );
					$order->set_order_key( wc_generate_order_key() );
					$order->save();
					$order->remove_order_items();
					WC()->checkout()->set_data_from_cart( $order ); self::save_heat_hold( $order, $quote );
					Stock_Hold::establish( $order, $quote['_input']['hold_minutes'] ); $order->save();
					if ( wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) !== $quote['totals']['total'] ) { throw new \RuntimeException( 'Total mismatch' ); }
					// Native holds do not remove products dropped from the order; replace this order's hold explicitly.
					wc_release_stock_for_order( $order ); wc_reserve_stock_for_order( $order );
					if ( ! Payment_Lock::owned( $order->get_id() ) ) { return self::error( 'payment_busy', 'The order lock was lost. Reload its current status before continuing.', 409 ); }
					$order->update_meta_data( '_cart_cat_version', hash( 'sha256', $quote_hash . ':' . wp_generate_uuid4() ) );
					$order->update_meta_data( '_cart_cat_reprepare_request', $request );
					$order->update_meta_data( '_cart_cat_snapshot', Payments::fingerprint( $order ) );
					$order->update_meta_data( '_cart_cat_preparation', 'ready' );
					$order->save();
					Stock_Hold::schedule( $order );
					self::save_staff_note( $order );
					$order->add_order_note( self::preparation_note( $quote ) . Stock_Hold::note( $order ) );
					return self::get( $order->get_id() );
				} catch ( \Throwable $e ) {
					if ( ! Payment_Lock::owned( $order->get_id() ) ) { return self::error( 'payment_busy', 'The order lock was lost. Reload its current status before continuing.', 409 ); }
					$order->update_meta_data( '_cart_cat_preparation', 'editing' ); $order->save(); wc_release_stock_for_order( $order );
					return self::error( 'prepare_failed', 'These changes could not be prepared. Payment remains paused. Review the details and prepare this same order again.', 409, array( 'order_id' => $order->get_id(), 'edit_url' => $order->get_edit_order_url() ) );
				}
			} );
		} finally { Payment_Lock::release( $lease ); }
	}
	public static function email( int $id, array $request = array() ) {
		$lease = Payment_Lock::acquire( $id ); if ( is_wp_error( $lease ) ) { return $lease; }
		try { return self::email_locked( $id, $request ); }
		finally { Payment_Lock::release( $lease ); }
	}
	private static function email_locked( int $id, array $request ) {
		$order = self::load( $id ); if ( is_wp_error( $order ) ) { return $order; }
		$confirmation = $request ? self::email_request( $order, $request ) : null;
		if ( is_wp_error( $confirmation ) ) { return $confirmation; }
		if ( $confirmation && 'accepted' === ( $confirmation['record']['state'] ?? '' ) ) { return array_merge( self::get( $id ), array( 'email_status' => 'sent' ) ); }
		if ( self::payment_email( $order )['uncertain'] ) { return self::error( 'email_status_unavailable', 'An email attempt was interrupted while sending. Check the email log before sending another payment email.', 409, array( 'hold' => Stock_Hold::details( $order ), 'payment_email' => self::payment_email( $order ) ) ); }
		if ( Stock_Hold::deadline_passed( $order ) ) { return self::error( 'order_expired', 'This order’s payment deadline has passed. Review its payment status before preparing or emailing it again.', 409 ); }
		if ( ! Square::public_config()['ready'] ) { return self::error( 'payment_unavailable', 'Square must be ready before sending a customer payment link.' ); }
		if ( ! $order->needs_payment() || 'ready' !== $order->get_meta( '_cart_cat_preparation' ) ) { return self::error( 'not_payable', 'This order is not awaiting payment.' ); }
		$emails = WC()->mailer()->get_emails();
		$email = $emails['WC_Email_Customer_Invoice'] ?? null;
		if ( ! $email ) { return self::error( 'email', 'The customer invoice email is unavailable.' ); }
		if ( $confirmation ) {
			if ( ! $order->has_status( 'pending' ) ) { return self::error( 'not_payable', 'Review this order before changing its stock hold or sending payment email.', 409 ); }
			if ( ! Payments::can_expire( $order ) || $order->get_transaction_id() || $order->get_meta( '_cart_cat_square_payment_id' ) || ( $order->get_meta( 'square_order_id' ) && ! $order->get_meta( '_cart_cat_payment_attempt' ) ) ) { return self::error( 'payment_checking', 'Payment is being checked. Wait for its final result before sending payment email.', 409 ); }
			$deadline = Stock_Hold::details( $order )['expires_at'];
			if ( $deadline && $deadline <= time() ) { return self::error( 'order_expired', 'This order’s stock hold expired. Review it before sending payment email.', 409 ); }
			$validation = self::validate_payment( $order ); if ( is_wp_error( $validation ) ) { return $validation; }
			$records = $order->get_meta( '_cart_cat_email_requests' ); $records = is_array( $records ) ? $records : array();
			$record = $confirmation['record'];
			if ( ! $record ) {
				$hold = $order->get_meta( '_cart_cat_email_hold' );
				$confirmed = is_array( $hold ) && ( $hold['version'] ?? '' ) === $request['version'];
				$restart = ! Stock_Hold::managed( $order ) || ( ! $confirmed && ! self::payment_email( $order )['accepted'] ) || $request['hold_minutes'] !== Stock_Hold::details( $order )['minutes'];
				$record = array( 'version' => $request['version'], 'minutes' => $request['hold_minutes'], 'fingerprint' => Payments::fingerprint( $order ), 'state' => 'applied', 'expires_at' => $restart ? time() + $request['hold_minutes'] * MINUTE_IN_SECONDS : (int) $order->get_meta( '_cart_cat_expires_at' ) );
				$records[ $confirmation['key'] ] = $record;
				$order->update_meta_data( '_cart_cat_email_requests', $records );
				$order->update_meta_data( '_cart_cat_email_hold', array( 'version' => $request['version'], 'minutes' => $request['hold_minutes'], 'expires_at' => $record['expires_at'] ) );
				$hold_result = Stock_Hold::confirm_email( $order, $request['hold_minutes'], $record['expires_at'] );
				if ( is_wp_error( $hold_result ) ) { return $hold_result; }
			} elseif ( $record['minutes'] !== Stock_Hold::details( $order )['minutes'] || $record['expires_at'] !== (int) $order->get_meta( '_cart_cat_expires_at' ) ) {
				return self::error( 'email_request_changed', 'The stock hold changed after this email attempt. Review the current order before sending again.', 409 );
			}
			if ( ! Payment_Lock::owned( $id ) ) { return self::error( 'payment_busy', 'The order lock was lost. Reload before sending the email.', 409 ); }
			$record['state'] = 'sending'; $records[ $confirmation['key'] ] = $record;
			$order->update_meta_data( '_cart_cat_email_requests', $records ); $order->save_meta_data();
		}
		$sent = null; $recipient = $order->get_billing_email();
		$watch = static function ( $success, $email_id, $object ) use ( &$sent, $email ) { if ( $object === $email ) { $sent = (bool) $success; } };
		$watch_recipient = static function ( $params, $object ) use ( &$recipient, $email ) {
			if ( $object === $email && isset( $params[0] ) ) {
				$recipients = is_array( $params[0] ) ? $params[0] : array( $params[0] );
				$recipient = implode( ', ', array_map( 'sanitize_text_field', array_filter( $recipients, 'is_scalar' ) ) );
			}
			return $params;
		};
		add_action( 'woocommerce_email_sent', $watch, 10, 3 );
		add_filter( 'woocommerce_mail_callback_params', $watch_recipient, PHP_INT_MAX, 2 );
		try { $email->trigger( $id, $order ); } catch ( \Throwable $error ) { /* Without a callback result, delivery remains uncertain. */ }
		finally { remove_action( 'woocommerce_email_sent', $watch, 10 ); remove_filter( 'woocommerce_mail_callback_params', $watch_recipient, PHP_INT_MAX ); }
		if ( true !== $sent ) {
			if ( $confirmation && false === $sent && Payment_Lock::owned( $id ) ) { $records[ $confirmation['key'] ]['state'] = 'failed'; $order->update_meta_data( '_cart_cat_email_requests', $records ); $order->save_meta_data(); }
			if ( $confirmation && null === $sent ) { return self::error( 'email_status_unavailable', 'The email result is uncertain. Check the email log before sending another payment email.', 409, array( 'hold' => Stock_Hold::details( $order ), 'payment_email' => self::payment_email( $order ) ) ); }
			return self::error( 'email_failed', 'The email was not accepted for sending. The order and its stock hold are saved; retry this payment email.', 502, array( 'hold' => Stock_Hold::details( $order ), 'payment_email' => self::payment_email( $order ) ) );
		}
		if ( ! Payment_Lock::owned( $id ) ) { return self::error( 'email_status_unavailable', 'The email was accepted for sending, but its order status could not be saved. Reload the order before continuing.', 409 ); }
		$order->update_meta_data( '_cart_cat_payment_email', array( 'recipient' => $recipient, 'accepted_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'version' => (string) $order->get_meta( '_cart_cat_version' ) ) );
		if ( $confirmation ) { $records[ $confirmation['key'] ]['state'] = 'accepted'; $order->update_meta_data( '_cart_cat_email_requests', $records ); }
		$order->save_meta_data();
		$order->add_order_note( 'Cart Cat payment email accepted for sending to the order billing email.' );
		return array_merge( self::get( $id ), array( 'email_status' => 'sent' ) );
	}
	private static function email_request( $order, array $request ) {
		if ( ! is_string( $request['version'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $request['version'] ) || ! is_string( $request['request_id'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9-]{16,80}$/', $request['request_id'] ) ) { return self::error( 'email_request', 'Review the payment email and stock hold before sending.', 422 ); }
		$minutes = Stock_Hold::normalize( $request['hold_minutes'] ?? null ); if ( is_wp_error( $minutes ) ) { return $minutes; }
		$fingerprint = Payments::fingerprint( $order );
		if ( ! hash_equals( (string) $order->get_meta( '_cart_cat_version' ), $request['version'] ) || ! hash_equals( (string) $order->get_meta( '_cart_cat_snapshot' ), $fingerprint ) ) { return self::error( 'order_changed', 'The order changed. Review its current details before sending payment email.', 409 ); }
		$key = hash( 'sha256', get_current_user_id() . ':' . $request['request_id'] );
		$records = $order->get_meta( '_cart_cat_email_requests' ); $record = is_array( $records ) ? ( $records[ $key ] ?? array() ) : array();
		if ( $record && ( $record['version'] !== $request['version'] || $record['minutes'] !== $minutes || ! hash_equals( $record['fingerprint'], $fingerprint ) ) ) { return self::error( 'email_request_changed', 'This email request already belongs to different order details or a different stock hold.', 409 ); }
		return array( 'key' => $key, 'record' => $record );
	}
	private static function payment_email( $order ) {
		$record = $order->get_meta( '_cart_cat_payment_email' );
		$version = (string) $order->get_meta( '_cart_cat_version' );
		$accepted = is_array( $record ) && ! empty( $record['recipient'] ) && ! empty( $record['accepted_at'] ) && ! empty( $record['version'] ) && hash_equals( $version, (string) $record['version'] );
		$uncertain = false;
		foreach ( (array) $order->get_meta( '_cart_cat_email_requests' ) as $attempt ) { if ( is_array( $attempt ) && ( $attempt['version'] ?? '' ) === $version && 'sending' === ( $attempt['state'] ?? '' ) ) { $uncertain = true; break; } }
		return array( 'accepted' => $accepted, 'recipient' => $accepted ? (string) $record['recipient'] : '', 'accepted_at' => $accepted ? (string) $record['accepted_at'] : null, 'version' => $version, 'uncertain' => $uncertain );
	}
	public static function complete_free( int $id ) {
		$order = self::load( $id ); if ( is_wp_error( $order ) ) { return $order; }
		if ( (float) $order->get_total() !== 0.0 ) { return self::error( 'requires_payment', 'This order requires payment.' ); }
		return Payments::pay( $id, array( 'source_type' => 'free', 'version' => $order->get_meta( '_cart_cat_version' ) ), 'staff' );
	}
	public static function validate_payment( $order ) {
		$hold = Stock_Hold::validate( $order ); if ( is_wp_error( $hold ) ) { return $hold; }
		if ( 'ready' !== $order->get_meta( '_cart_cat_preparation' ) ) { return self::error( 'preparation', 'Order preparation must finish before payment.' ); }
		$wine_ids = array(); $vole = null;
		if ( class_exists( '\\VinoshipperVole\\Settings' ) ) {
			$vole = new \VinoshipperVole\Settings();
			$category = (int) $vole->get( 'wine_category_id' );
			$children = get_term_children( $category, 'product_cat' );
			$wine_ids = array_merge( array( $category ), is_wp_error( $children ) ? array() : $children );
		}
		$tax_location = $order->get_meta( '_cart_cat_tax_location' );
		$pickup = is_array( $tax_location ) && 'US' === ( $tax_location['country'] ?? '' ) && 'VA' === ( $tax_location['state'] ?? '' );
		foreach ( $order->get_items( 'shipping' ) as $shipping ) { if ( 'pickup_location' === $shipping->get_method_id() ) { $pickup = true; } }
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || 'publish' !== $product->get_status() || get_post_meta( $product->get_id(), '_tribe_wooticket_for_event', true ) || get_post_meta( $product->get_parent_id(), '_tribe_wooticket_for_event', true ) ) { return self::error( 'product_changed', 'An order product is no longer available. Ask the winery to review this order.' ); }
			$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
			if ( $product->get_parent_id() && ( ! $parent || 'publish' !== $parent->get_status() ) ) { return self::error( 'product_changed', 'An order product is no longer available. Ask the winery to review this order.' ); }
			if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) { return self::error( 'stock_changed', 'An order product is out of stock. Ask the winery to review this order.' ); }
			if ( ! $pickup && $vole && $vole->enabled() && 'US' === $order->get_shipping_country() && in_array( $order->get_shipping_state(), $vole->states(), true ) && has_term( $wine_ids, 'product_cat', $item->get_product_id() ) ) { return self::error( 'vinoshipper', 'This shipment now requires Vinoshipper checkout. Ask the winery to review the order before payment.' ); }
		}
		return true;
	}
	public static function expire( int $id ) {
		$lease = Payment_Lock::acquire( $id );
		if ( is_wp_error( $lease ) ) { wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, 'cart_cat_expire_order', array( $id ) ); return; }
		try {
			$order = self::load( $id ); if ( is_wp_error( $order ) || ! $order->has_status( Stock_Hold::managed( $order ) ? array( 'pending', 'failed' ) : 'pending' ) ) { return; }
			$deadline = (int) $order->get_meta( '_cart_cat_expires_at' );
			if ( $deadline > time() ) { wp_schedule_single_event( $deadline, 'cart_cat_expire_order', array( $id ) ); return; }
			if ( ! Payments::can_expire( $order ) ) {
				if ( Stock_Hold::managed( $order ) ) { Payments::refresh_reservation( $order ); }
				wp_schedule_single_event( time() + 7 * MINUTE_IN_SECONDS, 'cart_cat_expire_order', array( $id ) ); return;
			}
			if ( Stock_Hold::managed( $order ) ) { self::expire_locked( $order ); return; }
			if ( ! Payment_Lock::owned( $id ) ) { return; }
			$order->update_status( 'cancelled', 'Cart Cat unpaid order expired under the WooCommerce hold-stock setting.' );
			wc_release_stock_for_order( $order );
		} finally { Payment_Lock::release( $lease ); }
	}
	/** The caller already owns the shared lock, including a definitive decline after its deadline. */
	public static function expire_locked( $order ): bool {
		if ( ! Payment_Lock::owned( $order->get_id() ) || ! Stock_Hold::expired( $order ) || ! $order->has_status( array( 'pending', 'failed' ) ) ) { return false; }
		$order->update_status( 'cancelled', 'Cart Cat unpaid order expired after its ' . Stock_Hold::duration_label( (int) $order->get_meta( '_cart_cat_hold_minutes' ) ) . ' stock hold.' );
		wc_release_stock_for_order( $order );
		wp_clear_scheduled_hook( 'cart_cat_expire_order', array( $order->get_id() ) );
		return true;
	}
	private static function editable( $order ) {
		if ( ! $order->has_status( 'pending' ) || $order->is_paid() || $order->get_transaction_id() || $order->get_meta( '_cart_cat_square_payment_id' ) ) { return self::error( 'not_editable', 'Only an unpaid pending order can be changed in Cart Cat.', 409 ); }
		if ( ! Payments::can_expire( $order ) ) { return self::error( 'payment_checking', 'Payment is being checked. Wait for its final result before changing the order.', 409 ); }
		if ( $order->get_meta( 'square_order_id' ) && ! $order->get_meta( '_cart_cat_payment_attempt' ) ) { return self::error( 'payment_checking', 'An existing Square order needs payment review before this order can be changed.', 409 ); }
		if ( ! in_array( $order->get_meta( '_cart_cat_preparation' ), array( 'ready', 'editing' ), true ) ) { return self::error( 'preparation_review', 'This preparation needs review in the normal order screen before it can be changed.', 409 ); }
		if ( 'ready' === $order->get_meta( '_cart_cat_preparation' ) && ! hash_equals( (string) $order->get_meta( '_cart_cat_snapshot' ), Payments::fingerprint( $order ) ) ) { return self::error( 'order_changed', 'This order changed outside Cart Cat. Review it in the normal order screen.', 409 ); }
		return true;
	}
	private static function save_review( $order, array $quote ) {
		$review = $quote;
		foreach ( array_keys( $review ) as $key ) { if ( '_' === substr( $key, 0, 1 ) ) { unset( $review[ $key ] ); } }
		$input = array_intersect_key( $quote['_input'], array_flip( array( 'customer', 'lines', 'discount', 'fulfillment', 'hold_minutes', 'order_notes', 'order_notes_visible' ) ) );
		$input['customer'] = array_intersect_key( $quote['_customer'], array_flip( array( 'first_name', 'last_name', 'email', 'phone', 'billing', 'shipping' ) ) );
		$input['lines'] = array_map( static function ( $line ) {
			$stored = wc_clean( array_intersect_key( $line, array_flip( array( 'product_id', 'variation_id', 'quantity', 'attributes' ) ) ) );
			$stored['addons'] = array(); $config = Catalog::configuration( (int) $line['product_id'] );
			foreach ( is_wp_error( $config ) ? array() : $config['addons'] as $addon ) {
				if ( ! array_key_exists( $addon['key'], $line['addons'] ?? array() ) ) { continue; }
				$value = $line['addons'][ $addon['key'] ];
				$stored['addons'][ $addon['key'] ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : ( 'custom_textarea' === $addon['type'] ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value ) );
			}
			return $stored;
		}, $input['lines'] );
		$order->update_meta_data( '_cart_cat_input', $input );
		$order->update_meta_data( '_cart_cat_order_notes', $input['order_notes'] );
		$order->update_meta_data( '_cart_cat_order_notes_visible', ! empty( $input['order_notes_visible'] ) ? 'yes' : 'no' );
		$order->set_customer_note( ! empty( $input['order_notes_visible'] ) ? $input['order_notes'] : '' );
		$order->update_meta_data( '_cart_cat_review', $review );
		$order->update_meta_data( '_cart_cat_role_discount_names', self::role_discount_names( $quote ) );
		$order->update_meta_data( '_cart_cat_case_discount_names', self::case_discount_names( $quote ) );
	}
	private static function set_shipping_address( $order, array $address ) {
		$email = $address['email'] ?? '';
		unset( $address['email'] );
		$order->set_address( $address, 'shipping' );
		// Woo has a native shipping phone, but recipient email remains extension metadata.
		if ( '' !== $email ) { $order->update_meta_data( '_shipping_email', $email ); }
		else { $order->delete_meta_data( '_shipping_email' ); }
	}
	private static function shipping_address( $order ) {
		return array_merge( $order->get_address( 'shipping' ), array( 'email' => (string) $order->get_meta( '_shipping_email' ) ) );
	}
	private static function editable_input( $order ) {
		$input = $order->get_meta( '_cart_cat_input' );
		if ( ! is_array( $input ) || empty( $input['lines'] ) ) {
			// Older releases saved no add-on submission. Do not infer recipient or option fields from display text.
			$lines = array();
			foreach ( $order->get_items() as $item ) {
				$config = Catalog::configuration( $item->get_product_id() );
				if ( is_wp_error( $config ) ) { return $config; }
				if ( ! empty( $config['addons'] ) ) { return self::error( 'legacy_options', 'This older order has product options that were not saved for editing. Use the normal order screen to review it.', 409, array( 'edit_url' => $order->get_edit_order_url() ) ); }
				$attributes = array(); $product = $item->get_product();
				foreach ( $product && $product->is_type( 'variation' ) ? $product->get_variation_attributes() : array() as $key => $value ) { $attributes[ $key ] = '' !== $value ? $value : (string) $item->get_meta( substr( $key, 10 ) ); }
				$lines[] = array( 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'quantity' => $item->get_quantity(), 'attributes' => $attributes, 'addons' => array() );
			}
			$tax_location = $order->get_meta( '_cart_cat_tax_location' ); $rates = array();
			foreach ( $order->get_items( 'shipping' ) as $shipping ) { $rates[] = $shipping->get_method_id() . ':' . $shipping->get_instance_id(); }
			$input = array( 'lines' => $lines, 'discount' => $order->get_meta( '_cart_cat_discount' ) ?: array( 'type' => 'percent', 'amount' => 0, 'mode' => 'add' ), 'fulfillment' => array( 'type' => is_array( $tax_location ) && ! empty( $tax_location ) ? 'pickup' : 'shipping', 'rates' => $rates, 'heat_hold_choice' => (string) $order->get_meta( '_hhh_shipping_preference' ) ) );
		}
		$input['hold_minutes'] = isset( $input['hold_minutes'] ) ? (int) $input['hold_minutes'] : ( Stock_Hold::managed( $order ) ? Stock_Hold::details( $order )['minutes'] : Stock_Hold::config()['default_minutes'] );
		$input['order_notes'] = isset( $input['order_notes'] ) ? (string) $input['order_notes'] : (string) $order->get_meta( '_cart_cat_order_notes' );
		$input['order_notes_visible'] = isset( $input['order_notes_visible'] ) ? (bool) $input['order_notes_visible'] : 'yes' === $order->get_meta( '_cart_cat_order_notes_visible' );
		$reference = Customers::reference_for_order( $order ); if ( is_wp_error( $reference ) ) { return $reference; }
		$input['customer'] = array( 'reference' => $reference, 'first_name' => $order->get_billing_first_name(), 'last_name' => $order->get_billing_last_name(), 'email' => $order->get_billing_email(), 'phone' => $order->get_billing_phone(), 'billing' => $order->get_address( 'billing' ), 'shipping' => self::shipping_address( $order ) );
		foreach ( $input['lines'] as &$line ) {
			$config = Catalog::configuration( (int) $line['product_id'] );
			$product = wc_get_product( ! empty( $line['variation_id'] ) ? (int) $line['variation_id'] : (int) $line['product_id'] );
			$line['name'] = $product ? $product->get_name() : 'Unavailable product';
			$line['meta'] = array();
			$line['sold_individually'] = ! is_wp_error( $config ) && $config['sold_individually'];
			$line['quantity_per_line'] = ! is_wp_error( $config ) ? $config['quantity_per_line'] : null;
			if ( ! is_wp_error( $config ) ) {
				foreach ( $config['addons'] as $addon ) {
					$value = $line['addons'][ $addon['key'] ] ?? '';
					if ( '' === $value || array() === $value ) { continue; }
					$values = array();
					foreach ( (array) $value as $entry ) { $labels = array_column( $addon['options'], 'label', 'value' ); $values[] = $labels[ $entry ] ?? $entry; }
					$line['meta'][] = array( 'key' => $addon['name'], 'value' => implode( ', ', $values ) );
				}
			}
		}
		unset( $line );
		return $input;
	}
	private static function review( $order ) {
		$review = $order->get_meta( '_cart_cat_review' );
		if ( is_array( $review ) && isset( $review['customer'] ) ) {
			if ( ! isset( $review['automatic_discounts'] ) && (float) ( $review['totals']['automatic_savings'] ?? 0 ) > 0 ) {
				$role_names = array_filter( (array) $order->get_meta( '_cart_cat_role_discount_names' ) );
				$case_names = array_filter( (array) $order->get_meta( '_cart_cat_case_discount_names' ) );
				$names = array_values( array_unique( array_filter( array_map( 'wp_strip_all_tags', array_merge( $role_names, $case_names ) ) ) ) );
				// Older snapshots retain the combined amount; never invent a split.
				if ( $names ) { $review['automatic_discounts'] = array( array( 'kind' => $case_names ? 'quantity' : 'role', 'label' => implode( ', ', $names ), 'amount' => $review['totals']['automatic_savings'] ) ); }
			}
			return $review;
		}
		$lines = array(); $subtotal = 0;
		foreach ( $order->get_items() as $item ) { $subtotal += (float) $item->get_subtotal(); $lines[] = array( 'name' => $item->get_name(), 'quantity' => $item->get_quantity(), 'subtotal' => $item->get_subtotal(), 'total' => $item->get_total(), 'tax' => $item->get_total_tax(), 'automatic_savings' => '0.00', 'meta' => array() ); }
		$sources = array(); if ( $order->get_customer_id() ) { $sources[] = 'woocommerce'; } if ( $order->get_meta( '_cart_cat_square_customer_id' ) ) { $sources[] = 'square'; }
		return array( 'currency' => $order->get_currency(), 'lines' => $lines, 'totals' => array( 'subtotal' => wc_format_decimal( $subtotal, wc_get_price_decimals() ), 'automatic_savings' => '0.00', 'discount' => $order->get_discount_total(), 'shipping' => $order->get_shipping_total(), 'fees' => wc_format_decimal( array_sum( array_map( static function ( $item ) { return (float) $item->get_total(); }, $order->get_items( 'fee' ) ) ), wc_get_price_decimals() ), 'tax' => $order->get_total_tax(), 'total' => $order->get_total() ), 'customer' => array( 'name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 'roles' => (array) $order->get_meta( '_cart_cat_pricing_roles' ), 'sources' => $sources ), 'rates' => array(), 'heat_hold' => array( 'required' => false, 'choices' => array(), 'choice' => (string) $order->get_meta( '_hhh_shipping_preference' ) ) );
	}
	private static function role_discount_names( array $quote ) {
		$names = array();
		foreach ( $quote['_cart']->get_cart() as $line ) {
			if ( (float) $line['data']->get_price() >= (float) ( $line['_cart_cat_catalog_unit'] ?? 0 ) ) { continue; }
			if ( ! empty( $line['_quantity_crab_discount']['applied'] ) && (float) ( $line['_cart_cat_role_addon_savings_unit'] ?? 0 ) <= 0 ) { continue; }
			$names = array_merge( $names, (array) ( $line['_cart_cat_role_discount_names'] ?? array() ) );
		}
		return array_values( array_unique( array_filter( $names ) ) );
	}
	private static function case_discount_names( array $quote ) {
		$names = array();
		foreach ( $quote['_cart']->get_cart() as $line ) {
			$marker = $line['_quantity_crab_discount'] ?? array();
			if ( empty( $marker['applied'] ) ) { continue; }
			// Quantity Crab records the names of the winning rules in its server-created marker.
			foreach ( (array) ( $marker['rules'] ?? array() ) as $name ) { $names[] = wp_strip_all_tags( (string) $name ); }
		}
		return array_values( array_unique( array_filter( $names ) ) );
	}
	/** Latest form value is separate from immutable private Woo note history. */
	private static function save_staff_note( $order ): void {
		$notes = (string) $order->get_meta( '_cart_cat_order_notes' );
		if ( $notes === (string) $order->get_meta( '_cart_cat_order_notes_applied' ) ) { return; }
		if ( '' !== $notes ) {
			$note_id = $order->add_order_note( sprintf( "Cart Cat staff note by %s:\n%s", wp_get_current_user()->display_name, $notes ), false, true );
			if ( ! $note_id ) { throw new \RuntimeException( 'The private staff note could not be saved.' ); }
		}
		$order->update_meta_data( '_cart_cat_order_notes_applied', $notes );
		$order->save_meta_data();
	}
	private static function preparation_note( array $quote ) {
		$note = sprintf( 'Prepared in Cart Cat by %s.', wp_get_current_user()->display_name );
		$names = self::role_discount_names( $quote );
		if ( $names ) { $note .= ' Role discount was applied (' . implode( ', ', $names ) . ').'; }
		$case_names = self::case_discount_names( $quote );
		if ( $case_names ) { $note .= ' Case discount was applied (' . implode( ', ', $case_names ) . ').'; }
		$discount = $quote['_input']['discount'];
		if ( (float) $discount['amount'] > 0 ) {
			$amount = 'percent' === $discount['type'] ? wc_format_decimal( $discount['amount'] ) . '%' : html_entity_decode( wp_strip_all_tags( wc_price( $discount['amount'], array( 'currency' => $quote['currency'] ) ) ), ENT_QUOTES );
			$note .= ' Manual discount was applied (' . $amount . ( 'replace' === $discount['mode'] ? ', replacing role pricing' : ( $names ? ', added to role pricing' : '' ) ) . ').';
		}
		return $note;
	}
	private static function save_heat_hold( $order, array $quote ) {
		if ( ! $quote['heat_hold']['required'] ) { return; }
		$settings = \Heat_Hold_Horse_Plugin::instance()->get_settings();
		$schedules = new \Heat_Hold_Horse_Schedules();
		$checkout = new \Heat_Hold_Horse_Checkout( $schedules, static function () use ( $settings ) { return $settings; } );
		$period = $schedules->get_active_period_for_destination( $order->get_shipping_country(), $order->get_shipping_state() );
		if ( ! $period ) { throw new \RuntimeException( 'Heat Hold changed' ); }
		$previous = $_POST;
		try {
			$_POST[ \Heat_Hold_Horse_Checkout::CLASSIC_FIELD ] = $quote['heat_hold']['choice'];
			$_POST[ \Heat_Hold_Horse_Checkout::CLASSIC_PERIOD_FIELD ] = $period['id'];
			$checkout->save_classic_order_preference( $order, array() );
		} finally { $_POST = $previous; }
	}
	private static function error( $code, $message, $status = 422, array $extra = array() ) { return new \WP_Error( 'cart_cat_' . $code, $message, array_merge( array( 'status' => $status ), $extra ) ); }
}
