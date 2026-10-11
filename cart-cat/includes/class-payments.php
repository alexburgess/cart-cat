<?php
namespace Cart_Cat;
defined('ABSPATH') || exit;

/** Shared payment owner for staff and native Woo order-pay. */
final class Payments {
    private static $refund_context = array();

    public static function register(): void {
        add_filter('woocommerce_payment_gateways', static function ($gateways) { $gateways[] = Gateway::class; return $gateways; });
        add_filter('woocommerce_available_payment_gateways', array(Gateway::class, 'available_gateways'), 999);
        add_action('cart_cat_reconcile_payment', array(__CLASS__, 'reconcile'), 10, 1);
        add_action('rest_api_init', array(__CLASS__, 'webhook_route'));
        add_filter('sws_external_payment_owner', array(__CLASS__, 'external_owner'), 10, 3);
        add_filter('sws_external_square_order_owner', array(__CLASS__, 'external_square_owner'), 10, 2);
        add_action('woocommerce_create_refund', array(__CLASS__, 'capture_refund'), 10, 2);
    }

    public static function owns($order): bool {
        return $order instanceof \WC_Order && (string) $order->get_meta('_cart_cat_order', true) === '1';
    }
    public static function can_expire($order): bool {
        if (!self::owns($order) || $order->is_paid()) { return false; }
        $attempt = self::meta_array($order, '_cart_cat_payment_attempt');
        return !$attempt || ($attempt['state'] ?? '') === 'declined';
    }
    public static function external_owner($managed, $order, $operation = ''): bool {
        return (bool) $managed || self::owns($order);
    }
    public static function external_square_owner($managed, $square_order): bool {
        if ($managed) { return true; }
        return is_array($square_order) && ($square_order['metadata']['cart_cat_owner'] ?? '') === '1'
            && preg_match('/^cc-\d+-[a-f0-9]{24}$/D', (string) ($square_order['reference_id'] ?? '')) === 1;
    }
    public static function meta_array($order, string $key): array {
        $value = $order->get_meta($key, true); return is_array($value) ? $value : array();
    }
    private static function order(int $id) {
        $order = wc_get_order($id);
        if ($order && method_exists($order, 'read_meta_data')) { $order->read_meta_data(true); }
        return self::owns($order) ? $order : new \WP_Error('cart_cat_order', 'Cart Cat order not found.');
    }
    private static function canonical($value) {
        if (is_int($value) || is_float($value)) { return (string) $value; }
        if (!is_array($value)) { return $value; }
        if (array_keys($value) !== range(0, count($value) - 1)) { ksort($value); }
        foreach ($value as &$child) { $child = self::canonical($child); } unset($child);
        return $value;
    }
    public static function fingerprint($order): string {
        $items = array();
        foreach ($order->get_items(array('line_item', 'shipping', 'fee', 'tax', 'coupon')) as $item) {
            $data = $item->get_data();
            unset($data['id'], $data['order_id']);
            $meta = array();
            foreach ($item->get_meta_data() as $entry) {
                $m = $entry->get_data();
                // Operational stock bookkeeping can be saved after capture, before payment_complete recovers.
                if ($m['key'] === '_reduced_stock') { continue; }
                $meta[] = array('key' => $m['key'], 'value' => $m['value']);
            }
            $data['meta_data'] = $meta; $items[] = $data;
        }
        // Freeze checkout choices, not HHH's later operational hold/release state.
        $fulfillment = array();
        foreach (array('_cart_cat_tax_location', '_hhh_shipping_preference', '_hhh_weather_hold_period_id', '_hhh_weather_hold_period_title', '_hhh_weather_hold_period_start', '_hhh_weather_hold_period_end', '_hhh_weather_hold_period_states', '_hhh_destination_country', '_hhh_destination_state', '_hhh_wine_category_id', '_hhh_choice_was_required', '_hhh_choice_selected_at', '_hhh_shipping_preference_label', '_hhh_checkout_copy_hash') as $key) {
            $fulfillment[$key] = $order->get_meta($key, true);
        }
        $state = array('currency' => $order->get_currency(), 'total' => $order->get_total(), 'discount' => $order->get_discount_total(), 'discount_tax' => $order->get_discount_tax(), 'shipping' => $order->get_shipping_total(), 'shipping_tax' => $order->get_shipping_tax(), 'cart_tax' => $order->get_cart_tax(), 'customer_id' => $order->get_customer_id(), 'billing' => $order->get_address('billing'), 'shipping_address' => $order->get_address('shipping'), 'square_customer' => $order->get_meta('_cart_cat_square_customer_id', true), 'items' => $items, 'fulfillment' => $fulfillment);
        $state['square_scope'] = self::meta_array($order, '_cart_cat_square_scope');
        // Preserve existing fingerprints when no recipient email was captured.
        $shipping_email = (string) $order->get_meta('_shipping_email', true);
        if ($shipping_email !== '') { $state['shipping_email'] = $shipping_email; }
        $state = self::canonical($state);
        return hash('sha256', wp_json_encode($state));
    }
    public static function minor($amount, string $currency = 'USD') {
        // The configured winery and gateway use USD. No guessed currency exponents.
        if ($currency !== 'USD') { return new \WP_Error('cart_cat_currency', 'Cart Cat payments currently require USD.'); }
        $value = (string) $amount;
        if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D', $value, $m)) { return new \WP_Error('cart_cat_amount', 'The order amount has unsupported precision.'); }
        $minor = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        return $minor;
    }
    private static function settled($order): bool {
        $attempt = self::meta_array($order, '_cart_cat_payment_attempt');
        return $order->is_paid() || ($order->has_status('refunded') && ($attempt['state'] ?? '') === 'paid' && $order->get_transaction_id() === ($attempt['payment_id'] ?? ''));
    }
    public static function result($order, string $status = '', string $message = ''): array {
        $attempt = self::meta_array($order, '_cart_cat_payment_attempt');
        if ($status === '') {
            $status = self::settled($order) ? 'paid' : (in_array($attempt['state'] ?? '', array('creating_order', 'charging', 'checking', 'review'), true) ? 'checking' : (($attempt['state'] ?? '') === 'declined' ? 'declined' : 'unpaid'));
            if (Split_Payments::owns($attempt) && !self::settled($order)) { $status = Split_Payments::status_label($attempt); }
        }
        $out = array('order_id' => $order->get_id(), 'status' => $status);
        if (Split_Payments::owns($attempt)) { $out['payment'] = Split_Payments::summary($attempt); }
        if ($message !== '') { $out['message'] = $message; }
        $receipt = (string) $order->get_meta('_cart_cat_receipt_url', true);
        if ($status === 'paid' && $receipt && preg_match('#^https://(?:squareup\.com|squareupsandbox\.com)/#', $receipt)) { $out['receipt_url'] = $receipt; }
        return $out;
    }
    public static function save($order, array $attempt): void {
        if (!Payment_Lock::owned((int) $order->get_id())) { throw new \RuntimeException('The payment database lock was lost. Reconcile the existing attempt.'); }
        $order->update_meta_data('_cart_cat_payment_attempt', $attempt); $order->save();
    }
    public static function schedule(int $id): void {
        $order = wc_get_order($id);
        $attempt = $order ? self::meta_array($order, '_cart_cat_payment_attempt') : array();
        $delay = min(900, 45 * (2 ** min(5, (int) floor(((int) ($attempt['reconciliations'] ?? 0)) / 4))));
        if ($order) {
            $hold_minutes = (int) apply_filters('woocommerce_order_hold_stock_minutes', (int) get_option('woocommerce_hold_stock_minutes', 60), $order);
            if ($hold_minutes > 0) { $delay = min($delay, max(15, (int) floor($hold_minutes * 30))); }
        }
        if (function_exists('as_schedule_single_action')) {
            // Only pending jobs deduplicate: the currently running job must enqueue its successor.
            $pending = as_get_scheduled_actions(array('hook' => 'cart_cat_reconcile_payment', 'args' => array($id), 'group' => 'cart-cat', 'status' => 'pending', 'per_page' => 1), 'ids');
            if (!$pending) { as_schedule_single_action(time() + $delay, 'cart_cat_reconcile_payment', array($id), 'cart-cat', false); }
        } elseif (!wp_next_scheduled('cart_cat_reconcile_payment', array($id))) { wp_schedule_single_event(time() + $delay, 'cart_cat_reconcile_payment', array($id)); }
    }
    private static function checking($order, array $attempt, string $message = 'Payment is being checked. Do not start another charge.'): array {
        $attempt['state'] = 'checking'; self::save($order, $attempt); self::schedule($order->get_id());
        return self::result($order, 'checking', $message);
    }

    /** Public card references are opaque HMACs, bound to this order/version/customer. */
    private static function card_reference($order, string $card_id): string {
        return hash_hmac('sha256', $order->get_id() . '|' . $order->get_meta('_cart_cat_version', true) . '|' . $order->get_meta('_cart_cat_square_customer_id', true) . '|' . $card_id, wp_salt('auth'));
    }
    private static function raw_cards($order) {
        $customer_id = (string) $order->get_meta('_cart_cat_square_customer_id', true);
        if ($customer_id === '') { return array(); }
        $cards = array(); $cursor = ''; $seen = array(); $owners = array();
        do {
            $path = '/v2/cards?customer_id=' . rawurlencode($customer_id);
            if ($cursor !== '') { $path .= '&cursor=' . rawurlencode($cursor); }
            $response = Square::request('GET', $path);
            if (is_wp_error($response)) { return $response; }
            foreach ((array) ($response['cards'] ?? array()) as $card) {
                if (empty($card['enabled']) || empty($card['id'])) { continue; }
                if ((int) ($card['exp_year'] ?? 0) * 100 + (int) ($card['exp_month'] ?? 0) < (int) gmdate('Ym')) { continue; }
                $owner = (string) ($card['customer_id'] ?? '');
                if ($owner === '') { continue; }
                if ($owner !== $customer_id) {
                    // Square can keep a merged profile's former ID on its cards.
                    // Resolve that ID afresh; email similarity is never ownership.
                    if (!array_key_exists($owner, $owners)) {
                        $profile = Square::request('GET', '/v2/customers/' . rawurlencode($owner));
                        if (is_wp_error($profile) || empty($profile['customer']['id'])) { return new \WP_Error('cart_cat_card_owner_unavailable', 'Square could not verify a saved card’s customer. Please retry loading the cards.', array('status' => 502)); }
                        $owners[$owner] = (string) $profile['customer']['id'] === $customer_id;
                    }
                    if (!$owners[$owner]) { continue; }
                }
                $cards[] = $card;
            }
            $cursor = (string) ($response['cursor'] ?? '');
            if ($cursor !== '' && isset($seen[$cursor])) { return new \WP_Error('cart_cat_cards', 'Square card pagination could not be completed.'); }
            $seen[$cursor] = true;
        } while ($cursor !== '' && count($seen) < 20);
        if ($cursor !== '') { return new \WP_Error('cart_cat_cards', 'The customer has too many card records to verify.'); }
        return $cards;
    }
    public static function cards(int $order_id) {
        if (!current_user_can('manage_woocommerce')) { return new \WP_Error('cart_cat_forbidden', 'Staff access required.'); }
        $order = self::order($order_id); if (is_wp_error($order)) { return $order; }
        $scope = self::validate_scope($order); if (is_wp_error($scope)) { return $scope; }
        $raw = self::raw_cards($order); if (is_wp_error($raw)) { return $raw; }
        $cards = array();
        foreach ($raw as $card) { $cards[] = array('reference' => self::card_reference($order, $card['id']), 'brand' => sanitize_text_field($card['card_brand'] ?? ''), 'last4' => preg_replace('/[^0-9]/', '', (string) ($card['last_4'] ?? '')), 'expiry_month' => (int) $card['exp_month'], 'expiry_year' => (int) $card['exp_year']); }
        $result = array('cards' => $cards);
        if (!$cards) { $result['message'] = $order->get_meta('_cart_cat_square_customer_id', true) ? 'No enabled, unexpired cards are available for this customer in Square.' : 'Select a linked Square customer to use a card on file.'; }
        return $result;
    }

    public static function pay(int $order_id, array $request, string $channel = 'staff') {
        if ($channel === 'staff' && !current_user_can('manage_woocommerce')) { return new \WP_Error('cart_cat_forbidden', 'Staff access required.'); }
        if (!in_array($channel, array('staff', 'customer'), true)) { return new \WP_Error('cart_cat_channel', 'Invalid payment channel.'); }
        $lease = Payment_Lock::acquire($order_id); if (is_wp_error($lease)) { return $lease; }
        try {
            $order = self::order($order_id); if (is_wp_error($order)) { return $order; }
            if (self::settled($order)) { return self::result($order); }
            $attempt = self::meta_array($order, '_cart_cat_payment_attempt');
            if (Split_Payments::owns($attempt) && ($attempt['state'] ?? '') !== 'declined') { return Split_Payments::pay($order, $attempt, $request, $channel); }
            if (Split_Payments::seen_request($order, (string) ($request['request_id'] ?? ''))) { return self::result($order); }
            // A cancelled split resets the amount due. A stale remainder form must never charge the full total.
            if ((Split_Payments::owns($attempt) || !empty($request['payment_revision'])) && !hash_equals((string) ($attempt['revision'] ?? ''), (string) ($request['payment_revision'] ?? ''))) {
                return new \WP_Error('cart_cat_payment_changed', 'Payment changed. Reload its current balance before submitting again.', array('status' => 409));
            }
            // A lease expiring never grants permission to start a fresh charge.
            if ($attempt && !in_array($attempt['state'] ?? '', array('declined'), true)) { return self::reconcile_locked($order, $attempt); }
            $hold = Stock_Hold::validate($order); if (is_wp_error($hold)) { return $hold; }
            if (!$order->has_status(array('pending', 'failed'))) { return new \WP_Error('cart_cat_unpayable', 'This order is not awaiting payment.'); }
            if ($order->get_transaction_id()) { return new \WP_Error('cart_cat_existing_payment', 'An existing transaction must be reviewed first.'); }
            $version = (string) $order->get_meta('_cart_cat_version', true);
            if ($version === '' || !hash_equals($version, (string) ($request['version'] ?? '')) || !hash_equals((string) $order->get_meta('_cart_cat_snapshot', true), self::fingerprint($order))) { return new \WP_Error('cart_cat_order_changed', 'The order changed. Review its current details before paying.'); }
            if ((string) $order->get_meta('_cart_cat_preparation', true) !== 'ready') { return new \WP_Error('cart_cat_not_ready', 'The order has not finished preparation.'); }
            $amount = self::minor($order->get_total(), $order->get_currency());
            if (is_wp_error($amount)) { return $amount; }
            if ($amount === 0 && ($request['source_type'] ?? '') === 'free' && $channel === 'staff') {
                $validation = self::reserve($order); if (is_wp_error($validation)) { return $validation; }
                $order->payment_complete(); $order->add_order_note('Cart Cat zero-total order completed by staff user #' . get_current_user_id() . '.');
                return self::result($order, 'paid');
            }
            if ($amount <= 0) { return new \WP_Error('cart_cat_zero', 'Use Complete order for a zero-total order.'); }
            $scope = self::validate_scope($order); if (is_wp_error($scope)) { return $scope; }
            if (!Square::public_config()['ready']) { return new \WP_Error('cart_cat_square_unavailable', 'Square payment requires HTTPS and the configured compatibility integration.'); }
            $context = Square::context(); if (is_wp_error($context)) { return $context; }
            $source_type = $request['source_type'] ?? '';
            $customer = (string) $order->get_meta('_cart_cat_square_customer_id', true);
            $source = self::source($order, $request, $channel); if (is_wp_error($source)) { return $source; }
            if ($source_type === 'gift_card') {
                $valid = Split_Payments::validate_request($request); if (is_wp_error($valid)) { return $valid; }
            }
            $verification = $request['verification_token'] ?? '';
            if (!is_string($verification) || strlen($verification) > 4096 || preg_match('/[\x00-\x20\x7f]/', $verification)) { return new \WP_Error('cart_cat_verification', 'Square did not provide a valid buyer verification token.'); }
            $validation = self::reserve($order); if (is_wp_error($validation)) { return $validation; }
            $itemization = Square_Order::prepare($order, $context);
            if (is_wp_error($itemization)) { return $itemization; }
            if (!empty($itemization['inventory'])) {
                $inventory_ready = Square_Inventory::ready(); if (is_wp_error($inventory_ready)) { return $inventory_ready; }
            }
            // Preview/catalog reads may take time; recheck the deadline, lock and saved order before any write to Square.
            $order = self::order($order_id);
            if (is_wp_error($order)) { return $order; }
            $hold = Stock_Hold::validate($order); if (is_wp_error($hold)) { return $hold; }
            if (!hash_equals((string) $order->get_meta('_cart_cat_snapshot', true), self::fingerprint($order))) { return new \WP_Error('cart_cat_order_changed', 'The order changed while Square verified its items. Review it before paying.'); }
            $scope = self::validate_scope($order); if (is_wp_error($scope)) { return $scope; }
            if ($attempt) {
                $history = self::meta_array($order, '_cart_cat_payment_history'); $history[] = $attempt;
                $order->update_meta_data('_cart_cat_payment_history', $history);
            }
            $attempt = array('id' => wp_generate_uuid4(), 'state' => 'creating_order', 'started' => time(), 'amount' => $amount, 'currency' => $order->get_currency(), 'version' => $version, 'fingerprint' => self::fingerprint($order), 'context' => $context, 'channel' => $channel, 'actor' => $channel === 'staff' ? get_current_user_id() : 0, 'source_type' => $source_type, 'customer_id' => $customer, 'square_order_id' => '', 'payment_id' => '', 'reconciliations' => 0);
            $attempt['itemization'] = $itemization;
            if ($source_type === 'gift_card') { $attempt = Split_Payments::initialize($order, $attempt); }
            self::save($order, $attempt);
            if (Stock_Hold::managed($order)) {
                // The customer deadline may be seconds away; protect the recorded attempt before provider I/O.
                $protection = self::reserve_native($order);
                if (is_wp_error($protection)) {
                    $attempt['state'] = 'declined'; self::save($order, $attempt); Orders::expire_locked($order);
                    return new \WP_Error('cart_cat_stock', 'Stock could not be reserved. No charge was sent.');
                }
            }
            $sq_order = self::create_square_order($order, $attempt);
            if (is_wp_error($sq_order)) {
                if ($sq_order->get_error_code() === 'cart_cat_square_itemization') {
                    $attempt['state'] = 'declined'; self::save($order, $attempt);
                    return $sq_order;
                }
                return self::checking($order, $attempt);
            }
            $attempt['square_order_id'] = $sq_order['id'];
            $order->update_meta_data('square_order_id', $sq_order['id']);
            $attempt['state'] = 'charging'; self::save($order, $attempt);
            if (Split_Payments::owns($attempt)) { return Split_Payments::send($order, $attempt, $request, $channel, $source); }
            $body = array('idempotency_key' => $attempt['id'], 'source_id' => $source, 'amount_money' => array('amount' => $amount, 'currency' => $attempt['currency']), 'autocomplete' => true, 'location_id' => $context['location_id'], 'order_id' => $sq_order['id'], 'reference_id' => self::reference($order, $attempt), 'customer_details' => array('customer_initiated' => true, 'seller_keyed_in' => $channel === 'staff' && $source_type === 'token'));
            if ($channel === 'customer' && $source_type === 'token' && $verification !== '') { $body['verification_token'] = $verification; }
            if ($customer !== '') { $body['customer_id'] = $customer; }
            if (is_email($order->get_billing_email())) { $body['buyer_email_address'] = $order->get_billing_email(); }
            $response = Square::request('POST', '/v2/payments', $body);
            unset($body['source_id'], $body['verification_token'], $source, $verification);
            if (is_wp_error($response)) {
                $data = (array) $response->get_error_data();
                $declines = array('card_declined', 'card_expired', 'cvv_failure', 'address_verification_failure', 'insufficient_funds', 'card_not_supported', 'payment_limit_exceeded');
                if ((int) ($data['status'] ?? 0) === 400 && array_intersect($declines, (array) ($data['codes'] ?? array()))) {
                    $attempt['state'] = 'declined'; self::save($order, $attempt);
                    $order->add_order_note('Square declined the Cart Cat payment. No payment was captured.');
                    if (Stock_Hold::managed($order)) { Orders::expire_locked($order); }
                    return self::result($order, 'declined', 'Square declined this card. Choose another payment method.');
                }
                return self::checking($order, $attempt);
            }
            return self::apply_payment($order, $attempt, $response['payment'] ?? array());
        } finally { Payment_Lock::release($lease); }
    }
    /** Shared source validation; provider IDs are resolved only from staff-bound references. */
    public static function source($order, array $request, string $channel) {
        $type = $request['source_type'] ?? '';
        if ($type === 'card') {
            if ($channel !== 'staff') { return new \WP_Error('cart_cat_forbidden', 'Stored cards are available only to staff.'); }
            $cards = self::raw_cards($order); if (is_wp_error($cards)) { return $cards; }
            foreach ($cards as $card) { if (hash_equals(self::card_reference($order, $card['id']), (string) ($request['card_reference'] ?? ''))) { return $card['id']; } }
            return new \WP_Error('cart_cat_card', 'The selected card is no longer available for this customer.');
        }
        if (!in_array($type, array('token', 'gift_card'), true)) { return new \WP_Error('cart_cat_source', 'Select a payment method.'); }
        $source = $request['token'] ?? '';
        if (!is_string($source) || strlen($source) < 6 || strlen($source) > 4096 || preg_match('/[\x00-\x20\x7f]/', $source) || preg_match('/^(?:ccof:|gftc:|[0-9]+$)/', $source)) { return new \WP_Error('cart_cat_token', 'Square did not provide a valid one-time payment token.'); }
        return $source;
    }
    public static function validate_scope($order) {
        $stored = self::meta_array($order, '_cart_cat_square_scope');
        if (empty($stored['application_id']) || empty($stored['location_id']) || empty($stored['currency']) || !in_array($stored['environment'] ?? '', array('production', 'sandbox'), true)) {
            return new \WP_Error('cart_cat_square_scope_missing', 'This order was prepared without a complete Square connection. Review and prepare a new order after configuring Square.');
        }
        if ($stored !== Square::scope()) { return new \WP_Error('cart_cat_square_scope_changed', 'The Square connection changed after this order was prepared. Restore the original connection or review and prepare a new order.'); }
        return true;
    }
    public static function reserve($order) {
        $validation = Orders::validate_payment($order); if (is_wp_error($validation)) { return $validation; }
        $reservation = self::reserve_native($order);
        return is_wp_error($reservation) ? new \WP_Error('cart_cat_stock', 'Stock could not be reserved. Check stock and retry payment; no charge was sent.') : true;
    }
    private static function reserve_native($order) {
        if (Stock_Hold::managed($order) && $order->has_status('failed')) {
            if (!Payment_Lock::owned((int)$order->get_id())) { return new \WP_Error('cart_cat_payment_busy', 'The order lock was lost. Reload its current status.'); }
            // Woo excludes reservations on failed orders from checkout availability.
            $order->set_status('pending'); $order->save();
        }
        try { wc_reserve_stock_for_order($order); }
        catch (\Automattic\WooCommerce\Checkout\Helpers\ReserveStockException $error) {
            // Woo releases this order's rows before throwing. Its same-second
            // no-op UPDATE can return 0, so retry the native atomic reservation once.
            try { wc_reserve_stock_for_order($order); }
            catch (\Automattic\WooCommerce\Checkout\Helpers\ReserveStockException $again) { return new \WP_Error('cart_cat_stock', 'Stock could not be reserved.'); }
        }
        return true;
    }
    public static function refresh_reservation($order): void {
        if (!Payment_Lock::owned((int) $order->get_id())) { throw new \RuntimeException('The payment database lock was lost.'); }
        // Reconciliation must still record an existing capture if inventory changed.
        // Native reservation updates holds only; payment_complete owns stock reduction.
        try { $result = self::reserve_native($order); }
        catch (\Throwable $error) { $result = new \WP_Error('cart_cat_stock', 'Stock reservation needs review.'); }
        if (is_wp_error($result) && !$order->get_meta('_cart_cat_stock_hold_review', true)) {
            $order->update_meta_data('_cart_cat_stock_hold_review', time()); $order->save_meta_data();
            $order->add_order_note('Cart Cat could not refresh the stock reservation while checking payment. Review inventory and the existing payment; no replacement charge was sent.');
        }
    }
    public static function reference($order, array $attempt): string { return 'cc-' . $order->get_id() . '-' . substr(str_replace('-', '', $attempt['id']), 0, 24); }
    public static function create_square_order($order, array $attempt) {
        // Old in-flight attempts must replay their original payload and idempotency key.
        $sale = isset($attempt['itemization']) ? $attempt['itemization']['order'] : array('location_id' => $attempt['context']['location_id'], 'pricing_options' => array('auto_apply_discounts' => false, 'auto_apply_taxes' => false), 'line_items' => array(array('name' => 'WooCommerce order #' . $order->get_order_number(), 'quantity' => '1', 'base_price_money' => array('amount' => $attempt['amount'], 'currency' => $attempt['currency']))));
        $sale['reference_id'] = self::reference($order, $attempt);
        $sale['metadata'] = array('cart_cat_owner' => '1', 'woo_order_id' => (string) $order->get_id());
        $payload = array('idempotency_key' => $attempt['id'] . '-order', 'order' => $sale);
        if ($attempt['customer_id'] !== '') { $payload['order']['customer_id'] = $attempt['customer_id']; }
        $response = Square::request('POST', '/v2/orders', $payload);
        if (is_wp_error($response)) { return $response; }
        $sq = $response['order'] ?? array();
        if (empty($sq['id']) || ($sq['reference_id'] ?? '') !== self::reference($order, $attempt) || ($sq['location_id'] ?? '') !== $attempt['context']['location_id']) { return new \WP_Error('cart_cat_square_order', 'Square order could not be verified.'); }
        if (isset($attempt['itemization'])) {
            $verified = Square_Order::verify($sq, $attempt['itemization']); if (is_wp_error($verified)) { return $verified; }
        } elseif ((int) ($sq['total_money']['amount'] ?? -1) !== $attempt['amount'] || ($sq['total_money']['currency'] ?? '') !== $attempt['currency']) {
            return new \WP_Error('cart_cat_square_order', 'Square order could not be verified.');
        }
        return $sq;
    }
    private static function apply_payment($order, array $attempt, array $payment) {
        $matches = !empty($payment['id']) && ($payment['reference_id'] ?? '') === self::reference($order, $attempt)
            && ($payment['order_id'] ?? '') === $attempt['square_order_id'] && ($payment['location_id'] ?? '') === $attempt['context']['location_id']
            && (int) ($payment['amount_money']['amount'] ?? -1) === $attempt['amount'] && ($payment['amount_money']['currency'] ?? '') === $attempt['currency']
            && ($attempt['customer_id'] === '' || ($payment['customer_id'] ?? '') === $attempt['customer_id'])
            && (int) ($payment['tip_money']['amount'] ?? 0) === 0
            && (!isset($payment['total_money']['amount']) || (int) $payment['total_money']['amount'] === $attempt['amount'])
            && ($attempt['payment_id'] === '' || $attempt['payment_id'] === $payment['id']);
        if (!$matches || !hash_equals($attempt['fingerprint'], self::fingerprint($order))) {
            $attempt['state'] = 'review'; self::save($order, $attempt); self::schedule($order->get_id());
            return self::result($order, 'checking', 'Payment details require review. No new charge will be started.');
        }
        $attempt['payment_id'] = $payment['id'];
        $state = $payment['status'] ?? '';
        if ($state === 'COMPLETED') {
            $attempt['state'] = 'paid';
            $order->update_meta_data('_cart_cat_square_payment_id', $payment['id']);
            $order->update_meta_data('_cart_cat_receipt_url', esc_url_raw($payment['receipt_url'] ?? ''));
            self::save($order, $attempt);
            if (!$order->is_paid()) {
                $order->set_payment_method('cart_cat_square'); $order->set_payment_method_title('Square — Cart Cat');
                if (!empty($attempt['itemization']['inventory'])) {
                    $completed = Square_Inventory::complete($order, $attempt['itemization'], static function () use ($order, $payment) { $order->payment_complete($payment['id']); });
                    if (is_wp_error($completed)) {
                        $attempt['state'] = 'review'; self::save($order, $attempt); self::schedule($order->get_id());
                        return self::result($order, 'checking', 'Square captured this payment. The order’s stock bookkeeping needs review; do not charge again.');
                    }
                } else { $order->payment_complete($payment['id']); }
                $order->add_order_note('Cart Cat Square payment captured. Payment ID: ' . $payment['id'] . '. Channel: ' . $attempt['channel'] . ($attempt['actor'] ? '. Staff user #' . $attempt['actor'] : '') . '.');
            }
            return self::result($order, 'paid');
        }
        if (in_array($state, array('FAILED', 'CANCELED'), true)) {
            $attempt['state'] = 'declined'; self::save($order, $attempt);
            if (Stock_Hold::managed($order)) { Orders::expire_locked($order); }
            return self::result($order, 'declined', 'Square did not capture this payment.');
        }
        return self::checking($order, $attempt);
    }
    private static function reconcile_locked($order, array $attempt) {
        if (self::settled($order)) { return self::result($order); }
        if (Split_Payments::owns($attempt)) { return Split_Payments::reconcile($order, $attempt); }
        if (($attempt['state'] ?? '') === 'declined') { return self::result($order, 'declined'); }
        if (!hash_equals((string) ($attempt['fingerprint'] ?? ''), self::fingerprint($order))) {
            $attempt['state'] = 'review'; self::save($order, $attempt);
            return self::result($order, 'checking', 'The order changed during payment. Review the existing attempt before taking any further payment.');
        }
        self::refresh_reservation($order);
        $context = Square::context();
        if (is_wp_error($context) || $context !== ($attempt['context'] ?? array())) { return self::checking($order, $attempt, 'The Square connection must match the original attempt before reconciliation.'); }
        $attempt['reconciliations'] = (int) ($attempt['reconciliations'] ?? 0) + 1;
        if (empty($attempt['square_order_id'])) {
            // Replaying order creation is safe: the original idempotency key/payload is fixed.
            $sq = self::create_square_order($order, $attempt);
            if (is_wp_error($sq)) {
                if ($sq->get_error_code() === 'cart_cat_square_itemization') {
                    $attempt['state'] = 'declined'; self::save($order, $attempt);
                    return self::result($order, 'declined', $sq->get_error_message());
                }
                return self::checking($order, $attempt);
            }
            $attempt['square_order_id'] = $sq['id']; $order->update_meta_data('square_order_id', $sq['id']); self::save($order, $attempt);
            // There was no CreatePayment call if the order creation never returned.
            $attempt['state'] = 'declined'; self::save($order, $attempt);
            if (Stock_Hold::managed($order)) { Orders::expire_locked($order); }
            return self::result($order, 'declined', 'The order is ready. No card charge was sent; enter the card again.');
        }
        if (!empty($attempt['payment_id'])) {
            $response = Square::request('GET', '/v2/payments/' . rawurlencode($attempt['payment_id']));
            return is_wp_error($response) ? self::checking($order, $attempt) : self::apply_payment($order, $attempt, $response['payment'] ?? array());
        }
        // No token is persisted. Look up the original payment; absence is never proof of failure.
        $cursor = ''; $matches = array(); $pages = 0;
        do {
            $path = '/v2/payments?location_id=' . rawurlencode($context['location_id']) . '&begin_time=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $attempt['started'] - 120)) . '&sort_order=DESC&limit=100';
            if ($cursor !== '') { $path .= '&cursor=' . rawurlencode($cursor); }
            $response = Square::request('GET', $path);
            if (is_wp_error($response)) { return self::checking($order, $attempt); }
            foreach ((array) ($response['payments'] ?? array()) as $payment) { if (($payment['reference_id'] ?? '') === self::reference($order, $attempt)) { $matches[] = $payment; } }
            $cursor = (string) ($response['cursor'] ?? ''); $pages++;
        } while ($cursor !== '' && $pages < 10);
        if (count($matches) === 1 && $cursor === '') { return self::apply_payment($order, $attempt, $matches[0]); }
        return self::checking($order, $attempt, 'Square payment status is not yet confirmed. Do not charge again.');
    }
    public static function status(int $order_id) {
        $order = self::order($order_id); if (is_wp_error($order)) { return $order; }
        $attempt = self::meta_array($order, '_cart_cat_payment_attempt');
        if (!$attempt || self::settled($order)) { return self::result($order); }
        $lease = Payment_Lock::acquire($order_id); if (is_wp_error($lease)) { return self::result($order, 'checking'); }
        try { $order = self::order($order_id); return is_wp_error($order) ? $order : self::reconcile_locked($order, self::meta_array($order, '_cart_cat_payment_attempt')); }
        finally { Payment_Lock::release($lease); }
    }
    public static function reconcile(int $order_id): void { self::status($order_id); self::reconcile_refund($order_id); }

    public static function cancel_split(int $order_id, array $request, string $channel = 'staff') {
        if ($channel === 'staff' && !current_user_can('manage_woocommerce')) { return new \WP_Error('cart_cat_forbidden', 'Staff access required.'); }
        if (!in_array($channel, array('staff', 'customer'), true)) { return new \WP_Error('cart_cat_channel', 'Invalid payment channel.'); }
        $lease = Payment_Lock::acquire($order_id); if (is_wp_error($lease)) { return $lease; }
        try {
            $order = self::order($order_id); if (is_wp_error($order)) { return $order; }
            $attempt = self::meta_array($order, '_cart_cat_payment_attempt');
            if (!Split_Payments::owns($attempt) || self::settled($order)) { return self::result($order); }
            if (!hash_equals((string) $order->get_meta('_cart_cat_version'), (string) ($request['version'] ?? '')) || !hash_equals($attempt['revision'], (string) ($request['payment_revision'] ?? ''))) { return new \WP_Error('cart_cat_payment_changed', 'Payment changed. Reload its current status before cancelling.', array('status' => 409)); }
            return Split_Payments::cancel($order, $attempt);
        } finally { Payment_Lock::release($lease); }
    }

    public static function webhook_route(): void {
        register_rest_route('cart-cat/v1', '/square/webhook', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array(__CLASS__, 'webhook')));
    }
    public static function webhook($request) {
        $key = defined('CART_CAT_SQUARE_WEBHOOK_SIGNATURE_KEY') ? CART_CAT_SQUARE_WEBHOOK_SIGNATURE_KEY : get_option('cart_cat_square_webhook_signature_key', '');
        $url = rest_url('cart-cat/v1/square/webhook');
        $signature = (string) $request->get_header('x-square-hmacsha256-signature');
        if (!$key || !hash_equals(base64_encode(hash_hmac('sha256', $url . $request->get_body(), $key, true)), $signature)) { return new \WP_Error('cart_cat_signature', 'Invalid webhook signature.', array('status' => 403)); }
        $body = json_decode($request->get_body(), true);
        $object = $body['data']['object'] ?? array();
        $reference = (string) ($object['payment']['reference_id'] ?? '');
        if (preg_match('/^cc-(\d+)-[a-f0-9]{24}$/D', $reference, $match)) { self::schedule((int) $match[1]); }
        $refund_id = (string) ($object['refund']['id'] ?? '');
        if ($refund_id !== '') {
            $id = (int) get_option('_cart_cat_refund_map_' . hash('sha256', $refund_id), 0);
            $order = $id ? self::order($id) : false;
            if ($order && !is_wp_error($order) && ((string) $order->get_meta('_cart_cat_pending_refund_id', true) === $refund_id || in_array($refund_id, (array) $order->get_meta('_cart_cat_pending_refund_ids', true), true))) { self::schedule($id); }
        }
        return new \WP_REST_Response(array('received' => true), 200);
    }

    public static function capture_refund($refund, $args): void {
        if ($refund instanceof \WC_Order_Refund && !empty($args['order_id'])) { self::$refund_context[(int) $args['order_id']] = (int) $refund->get_id(); }
    }
    public static function refund(int $order_id, $amount, string $reason = '') {
        $lease = Payment_Lock::acquire($order_id); if (is_wp_error($lease)) { return $lease; }
        try {
            $order = self::order($order_id); if (is_wp_error($order)) { return $order; }
            $attempt = self::meta_array($order, '_cart_cat_payment_attempt');
            if (Split_Payments::owns($attempt)) { return Split_Refunds::refund($order, $attempt, $amount, $reason, self::$refund_context[$order_id] ?? 0); }
            if (empty($attempt['payment_id']) || ($attempt['state'] ?? '') !== 'paid') { return new \WP_Error('cart_cat_refund', 'A verified Cart Cat payment is required.'); }
            $minor = self::minor(wc_format_decimal($amount, 2), $order->get_currency());
            if (is_wp_error($minor) || $minor <= 0) { return new \WP_Error('cart_cat_refund_amount', 'Enter a positive refund amount.'); }
            $context = Square::context(); if (is_wp_error($context) || $context !== $attempt['context']) { return new \WP_Error('cart_cat_refund_context', 'The original Square connection is required.'); }
            $intent = self::meta_array($order, '_cart_cat_refund_intent');
            $local_id = self::$refund_context[$order_id] ?? 0;
            if (!$local_id) { return new \WP_Error('cart_cat_refund_context', 'Start this refund from the WooCommerce order screen.'); }
            if ($intent && ($intent['state'] ?? '') === 'PENDING') { return new \WP_Error('cart_cat_refund_pending', 'Square is still processing the previous refund. Check its status before starting another.'); }
            if ($intent && !empty($intent['projected_id']) && ($projection = wc_get_order($intent['projected_id'])) && $projection instanceof \WC_Order_Refund && $projection->get_refunded_payment()) {
                $history = self::meta_array($order, '_cart_cat_refund_history'); $history[] = $intent; $order->update_meta_data('_cart_cat_refund_history', $history); $intent = array();
            }
            if ($intent && !empty($intent['projected_id']) && (int) $intent['projected_id'] !== $local_id && wc_get_order($intent['projected_id'])) { return new \WP_Error('cart_cat_refund_projection', 'The previous refund is still being recorded. Review that Woo refund before starting another.'); }
            if ($intent && (int) $intent['amount'] !== $minor) { return new \WP_Error('cart_cat_refund_busy', 'Resolve the previous refund before requesting a different amount.'); }
            if (!$intent) {
                $payment = Square::request('GET', '/v2/payments/' . rawurlencode($attempt['payment_id']));
                if (is_wp_error($payment)) { return $payment; }
                $p = $payment['payment'] ?? array();
                if (($p['id'] ?? '') !== $attempt['payment_id'] || ($p['status'] ?? '') !== 'COMPLETED' || ($p['location_id'] ?? '') !== $context['location_id'] || ($p['amount_money']['currency'] ?? '') !== $order->get_currency() || $minor > (int) ($p['amount_money']['amount'] ?? 0) - (int) ($p['refunded_money']['amount'] ?? 0)) { return new \WP_Error('cart_cat_refund_limit', 'The verified Square payment has insufficient refundable funds.'); }
                $intent = array('id' => wp_generate_uuid4(), 'order_id' => $order_id, 'payment_id' => $attempt['payment_id'], 'amount' => $minor, 'currency' => $order->get_currency(), 'reason' => substr(sanitize_text_field($reason), 0, 192), 'state' => 'checking', 'refund_id' => '', 'projected_id' => 0);
                $order->update_meta_data('_cart_cat_refund_intent', $intent); $order->save();
            }
            $response = self::refund_request($intent);
            if (is_wp_error($response)) { self::schedule($order_id); return new \WP_Error('cart_cat_refund_checking', 'The refund is being checked. Retry this same refund only; do not refund in Square separately.'); }
            $refund = $response['refund'] ?? array();
            if (!self::refund_matches($intent, $refund)) { self::schedule($order_id); return new \WP_Error('cart_cat_refund_mismatch', 'Square refund requires review.'); }
            $intent['refund_id'] = $refund['id']; $intent['state'] = $refund['status'];
            $order->update_meta_data('_cart_cat_pending_refund_id', $refund['id']);
            add_option('_cart_cat_refund_map_' . hash('sha256', $refund['id']), $order_id, '', false);
            if (in_array($refund['status'], array('COMPLETED', 'PENDING'), true)) {
                $intent['projected_id'] = $local_id; $order->update_meta_data('_cart_cat_refund_intent', $intent); $order->save();
                $local = wc_get_order($local_id); if ($local) { $local->update_meta_data('_cart_cat_square_refund_id', $refund['id']); $local->save(); }
                if ($refund['status'] === 'PENDING') { self::schedule($order_id); }
                return true;
            }
            $order->update_meta_data('_cart_cat_refund_intent', $intent); $order->save();
            return new \WP_Error('cart_cat_refund_failed', 'Square did not complete this refund. Review the order before retrying.');
        } finally { Payment_Lock::release($lease); }
    }
    private static function refund_request(array $intent) {
        if (empty($intent['order_id']) || !Payment_Lock::owned((int) $intent['order_id'])) { return new \WP_Error('cart_cat_refund_lock', 'The refund lock was lost. Check the existing refund before retrying.'); }
        if ($intent['refund_id'] !== '') { return Square::request('GET', '/v2/refunds/' . rawurlencode($intent['refund_id'])); }
        return Square::request('POST', '/v2/refunds', array('idempotency_key' => $intent['id'], 'payment_id' => $intent['payment_id'], 'amount_money' => array('amount' => $intent['amount'], 'currency' => $intent['currency']), 'reason' => $intent['reason']));
    }
    private static function refund_matches(array $intent, array $refund): bool {
        return !empty($refund['id']) && ($intent['refund_id'] === '' || $intent['refund_id'] === $refund['id']) && ($refund['payment_id'] ?? '') === $intent['payment_id'] && (int) ($refund['amount_money']['amount'] ?? -1) === $intent['amount'] && ($refund['amount_money']['currency'] ?? '') === $intent['currency'];
    }
    private static function reconcile_refund(int $order_id): void {
        $lease = Payment_Lock::acquire($order_id); if (is_wp_error($lease)) { return; }
        try {
            $order = self::order($order_id); if (is_wp_error($order)) { return; }
            $intent = self::meta_array($order, '_cart_cat_refund_intent');
            if (($intent['schema'] ?? 0) === 2) { Split_Refunds::reconcile($order, $intent); return; }
            if (!$intent || in_array($intent['state'], array('COMPLETED', 'FAILED', 'REJECTED'), true)) { return; }
            $attempt = self::meta_array($order, '_cart_cat_payment_attempt');
            $context = Square::context(); if (is_wp_error($context) || $context !== ($attempt['context'] ?? array())) { self::schedule($order_id); return; }
            $response = self::refund_request($intent);
            if (!is_wp_error($response) && self::refund_matches($intent, $response['refund'] ?? array())) {
                $intent['refund_id'] = $response['refund']['id']; $intent['state'] = $response['refund']['status'];
                $order->update_meta_data('_cart_cat_pending_refund_id', $intent['refund_id']); add_option('_cart_cat_refund_map_' . hash('sha256', $intent['refund_id']), $order_id, '', false); $order->update_meta_data('_cart_cat_refund_intent', $intent); $order->save();
                if (in_array($intent['state'], array('FAILED', 'REJECTED'), true)) { $order->add_order_note('Square rejected refund ' . $intent['refund_id'] . '. Review the Woo refund record; no further refund was issued.'); }
            }
            if (!in_array($intent['state'], array('COMPLETED', 'FAILED', 'REJECTED'), true)) { self::schedule($order_id); }
        } finally { Payment_Lock::release($lease); }
    }
}
