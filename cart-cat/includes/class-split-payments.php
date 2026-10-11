<?php
namespace Cart_Cat;
defined('ABSPATH') || exit;

/** Versioned gift-card checkout ledger. Every entry is a distinct, recoverable authorization. */
final class Split_Payments {
    public static function owns(array $attempt): bool { return ($attempt['schema'] ?? 0) === 2; }

    public static function initialize($order, array $attempt): array {
        $deadline = (int) $order->get_meta('_cart_cat_expires_at');
        $attempt['schema'] = 2;
        $attempt['revision'] = wp_generate_uuid4();
        $attempt['expires_at'] = min($deadline ?: PHP_INT_MAX, time() + 15 * MINUTE_IN_SECONDS);
        $attempt['tenders'] = array();
        $attempt['pay_request'] = array();
        $attempt['cancel_requested'] = false;
        return $attempt;
    }

    public static function validate_request(array $request) {
        if (!is_string($request['request_id'] ?? null) || !preg_match('/^[a-f0-9-]{32,36}$/D', $request['request_id'])) {
            return new \WP_Error('cart_cat_payment_request', 'Reload the payment form before submitting.', array('status' => 409));
        }
        $verification = $request['verification_token'] ?? '';
        if (!is_string($verification) || strlen($verification) > 4096 || preg_match('/[\x00-\x20\x7f]/', $verification)) { return new \WP_Error('cart_cat_verification', 'Square did not provide a valid buyer verification token.'); }
        return true;
    }

    public static function seen_request($order, string $request_id): bool {
        if ($request_id === '') { return false; }
        $history = Payments::meta_array($order, '_cart_cat_payment_history');
        $history[] = Payments::meta_array($order, '_cart_cat_payment_attempt');
        foreach ($history as $attempt) {
            foreach (($attempt['tenders'] ?? array()) as $tender) { if (($tender['request_id'] ?? '') === $request_id) { return true; } }
        }
        return false;
    }

    private static function collected(array $attempt): int {
        $sum = 0;
        foreach ($attempt['tenders'] as $tender) {
            if (in_array($tender['status'], array('APPROVED', 'COMPLETED'), true)) { $sum += $tender['amount']; }
        }
        return $sum;
    }

    public static function status_label(array $attempt): string {
        if ($attempt['state'] === 'paid') { return 'paid'; }
        if ($attempt['state'] === 'declined') { return 'declined'; }
        if ($attempt['state'] === 'partial') { return self::collected($attempt) > 0 ? 'partial' : 'unpaid'; }
        return 'checking';
    }

    /** Public totals are computed from verified provider amounts, never a browser amount. */
    public static function summary(array $attempt): array {
        $tenders = array();
        foreach ($attempt['tenders'] as $tender) {
            if (!in_array($tender['status'], array('APPROVED', 'COMPLETED'), true)) { continue; }
            $tenders[] = array('kind' => $tender['kind'], 'last4' => $tender['last4'] ?? '', 'amount' => number_format($tender['amount'] / 100, 2, '.', ''), 'status' => $tender['status']);
        }
        return array('revision' => $attempt['revision'], 'remaining' => number_format(max(0, $attempt['amount'] - self::collected($attempt)) / 100, 2, '.', ''), 'authorized' => number_format(self::collected($attempt) / 100, 2, '.', ''), 'currency' => $attempt['currency'], 'tenders' => $tenders, 'expires_at' => $attempt['expires_at'], 'can_cancel' => empty($attempt['pay_request']) && !in_array($attempt['state'], array('paid', 'declined'), true));
    }

    private static function save($order, array &$attempt): void {
        $previous = Payments::meta_array($order, '_cart_cat_payment_attempt');
        if ($previous !== $attempt) { $attempt['revision'] = wp_generate_uuid4(); Payments::save($order, $attempt); }
    }

    private static function waiting($order, array &$attempt, string $message = 'Checking the existing payments with Square. Please do not pay separately.'): array {
        self::save($order, $attempt);
        Payments::schedule($order->get_id());
        return Payments::result($order, 'checking', $message);
    }

    private static function review($order, array &$attempt): array {
        $attempt['state'] = 'review';
        return self::waiting($order, $attempt, 'The existing payments need review. No additional payment will be taken.');
    }

    /** Called only while Payments owns the order mutex. */
    public static function pay($order, array $attempt, array $request, string $channel) {
        if (!hash_equals((string) $order->get_meta('_cart_cat_version'), (string) ($request['version'] ?? ''))) { return new \WP_Error('cart_cat_order_changed', 'The order changed. Reopen its payment page.', array('status' => 409)); }
        $valid = self::validate_request($request); if (is_wp_error($valid)) { return $valid; }
        $seen = self::seen_request($order, $request['request_id']);
        $result = self::reconcile($order, $attempt);
        if ($seen || $result['status'] === 'paid' || $result['status'] === 'checking' || $result['status'] === 'declined') { return $result; }
        $attempt = Payments::meta_array($order, '_cart_cat_payment_attempt');
        if (!hash_equals($attempt['revision'], (string) ($request['payment_revision'] ?? ''))) { return new \WP_Error('cart_cat_payment_changed', 'Payment changed. Check its current balance before submitting again.', array('status' => 409)); }
        if (!Square::public_config()['ready']) { return new \WP_Error('cart_cat_square_unavailable', 'Square requires a ready connection and HTTPS.'); }
        $scope = Payments::validate_scope($order); if (is_wp_error($scope)) { return $scope; }
        $source = Payments::source($order, $request, $channel); if (is_wp_error($source)) { return $source; }
        $reserved = Payments::reserve($order); if (is_wp_error($reserved)) { return $reserved; }
        return self::send($order, $attempt, $request, $channel, $source);
    }

    public static function send($order, array $attempt, array $request, string $channel, string $source) {
        if (!Payment_Lock::owned($order->get_id())) { return new \WP_Error('cart_cat_payment_busy', 'The order lock was lost. Check payment status.'); }
        $order = wc_get_order($order->get_id());
        $scope = Payments::validate_scope($order); if (is_wp_error($scope)) { return $scope; }
        if ($attempt['expires_at'] <= time() || Stock_Hold::deadline_passed($order) || !$order->has_status(array('pending', 'failed'))) { return self::cancel($order, $attempt); }
        if ($order->get_meta('_cart_cat_preparation') !== 'ready') { return self::review($order, $attempt); }
        if (!hash_equals($attempt['fingerprint'], Payments::fingerprint($order))) { return self::review($order, $attempt); }
        if (count($attempt['tenders']) >= 20) { return new \WP_Error('cart_cat_payment_limit', 'Too many payment attempts. Cancel the applied payments before starting again.'); }
        $remaining = $attempt['amount'] - self::collected($attempt);
        if ($remaining <= 0) { return self::reconcile($order, $attempt); }
        $gift = $request['source_type'] === 'gift_card';
        $tender = array('id' => wp_generate_uuid4(), 'request_id' => $request['request_id'], 'requested' => $remaining, 'amount' => 0, 'payment_id' => '', 'status' => 'sending', 'kind' => $gift ? 'gift_card' : 'card', 'channel' => $channel, 'actor' => $channel === 'staff' ? get_current_user_id() : 0, 'started' => time());
        $attempt['tenders'][] = $tender;
        $index = count($attempt['tenders']) - 1;
        $attempt['state'] = 'charging';
        self::save($order, $attempt);
        Payments::schedule($order->get_id());
        $body = array('idempotency_key' => $tender['id'], 'source_id' => $source, 'amount_money' => array('amount' => $remaining, 'currency' => $attempt['currency']), 'autocomplete' => false, 'delay_action' => 'CANCEL', 'delay_duration' => 'PT' . max(60, $attempt['expires_at'] - time()) . 'S', 'location_id' => $attempt['context']['location_id'], 'order_id' => $attempt['square_order_id'], 'reference_id' => Payments::reference($order, $tender), 'customer_details' => array('customer_initiated' => true, 'seller_keyed_in' => $channel === 'staff' && $request['source_type'] !== 'card'));
        if ($gift) { $body['accept_partial_authorization'] = true; }
        if ($attempt['customer_id'] !== '') { $body['customer_id'] = $attempt['customer_id']; }
        if (is_email($order->get_billing_email())) { $body['buyer_email_address'] = $order->get_billing_email(); }
        if ($channel === 'customer' && !$gift && !empty($request['verification_token'])) { $body['verification_token'] = $request['verification_token']; }
        $response = Square::request('POST', '/v2/payments', $body);
        unset($body, $source);
        if (is_wp_error($response)) {
            $data = (array) $response->get_error_data();
            $declines = array('card_declined', 'generic_decline', 'card_declined_verification_required', 'cardholder_insufficient_permissions', 'card_expired', 'expiration_failure', 'cvv_failure', 'address_verification_failure', 'insufficient_funds', 'card_not_supported', 'payment_limit_exceeded', 'invalid_card', 'invalid_account', 'card_token_expired', 'card_token_used', 'insufficient_permissions', 'payment_source_not_found');
            if ((int) ($data['status'] ?? 0) === 400 && array_intersect($declines, (array) ($data['codes'] ?? array()))) {
                $attempt['tenders'][$index]['status'] = 'FAILED';
                $held = self::collected($attempt) > 0;
                $attempt['state'] = $held ? 'partial' : 'declined'; self::save($order, $attempt);
                if (!$held) { Orders::expire_locked($order); }
                return Payments::result($order, '', $held ? 'Square declined that payment. Previously applied funds remain reserved; choose another method or cancel the applied payments.' : 'Square declined that payment. Try another payment method.');
            }
            return self::waiting($order, $attempt);
        }
        if (!self::apply($order, $attempt, $index, $response['payment'] ?? array())) { return self::review($order, $attempt); }
        self::save($order, $attempt);
        return self::reconcile($order, $attempt);
    }

    /** Validate each authorization independently, including the smaller amount a gift card actually approved. */
    public static function matches($order, array $attempt, array $tender, array $payment): bool {
        $amount = $payment['amount_money']['amount'] ?? null;
        $gift = $tender['kind'] === 'gift_card';
        return !empty($payment['id']) && is_string($payment['id'])
            && ($tender['payment_id'] === '' || $tender['payment_id'] === $payment['id'])
            && ($payment['reference_id'] ?? '') === Payments::reference($order, $tender)
            && ($payment['order_id'] ?? '') === $attempt['square_order_id']
            && ($payment['location_id'] ?? '') === $attempt['context']['location_id']
            && ($attempt['customer_id'] === '' || ($payment['customer_id'] ?? '') === $attempt['customer_id'])
            && is_int($amount) && $amount > 0 && ($gift ? $amount <= $tender['requested'] : $amount === $tender['requested'])
            && (!$tender['amount'] || $amount === $tender['amount'])
            && ($payment['amount_money']['currency'] ?? '') === $attempt['currency']
            && (int) ($payment['tip_money']['amount'] ?? 0) === 0
            && (!isset($payment['total_money']) || ((int) ($payment['total_money']['amount'] ?? -1) === $amount && ($payment['total_money']['currency'] ?? '') === $attempt['currency']))
            && (!$gift || ($payment['card_details']['card']['card_brand'] ?? '') === 'SQUARE_GIFT_CARD')
            && in_array($payment['status'] ?? '', array('APPROVED', 'COMPLETED', 'FAILED', 'CANCELED'), true);
    }

    private static function apply($order, array &$attempt, int $index, array $payment): bool {
        $tender = $attempt['tenders'][$index];
        if (!self::matches($order, $attempt, $tender, $payment)) { return false; }
        $tender['payment_id'] = $payment['id'];
        $tender['amount'] = $payment['amount_money']['amount'];
        $tender['status'] = $payment['status'];
        $tender['last4'] = preg_replace('/[^0-9]/', '', (string) ($payment['card_details']['card']['last_4'] ?? ''));
        $tender['last4'] = substr($tender['last4'], -4);
        $tender['receipt_url'] = esc_url_raw($payment['receipt_url'] ?? '');
        $tender['refunded'] = (int) ($payment['refunded_money']['amount'] ?? 0);
        $attempt['tenders'][$index] = $tender;
        return true;
    }

    private static function fetch($order, array $attempt, array $tender) {
        if ($tender['payment_id'] !== '') { return Square::request('GET', '/v2/payments/' . rawurlencode($tender['payment_id'])); }
        // Never replay a token or interpret a missing payment as a failed payment.
        $cursor = ''; $matches = array(); $pages = 0; $seen = array();
        do {
            $path = '/v2/payments?location_id=' . rawurlencode($attempt['context']['location_id']) . '&begin_time=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $tender['started'] - 120)) . '&sort_order=DESC&limit=100';
            if ($cursor !== '') { $path .= '&cursor=' . rawurlencode($cursor); }
            $response = Square::request('GET', $path);
            if (is_wp_error($response)) { return $response; }
            foreach ((array) ($response['payments'] ?? array()) as $payment) {
                if (($payment['reference_id'] ?? '') === Payments::reference($order, $tender)) { $matches[] = $payment; }
            }
            $cursor = (string) ($response['cursor'] ?? '');
            if ($cursor !== '' && isset($seen[$cursor])) { break; }
            $seen[$cursor] = true; $pages++;
        } while ($cursor !== '' && $pages < 10);
        return count($matches) === 1 && $cursor === '' ? array('payment' => $matches[0]) : new \WP_Error('cart_cat_payment_unknown', 'Square has not confirmed the existing payment.');
    }

    public static function cancel($order, array $attempt): array {
        // Once capture was submitted, only reconciliation may determine its outcome.
        if (!empty($attempt['pay_request'])) { return self::reconcile($order, $attempt); }
        if ($attempt['state'] === 'declined') { return Payments::result($order); }
        $attempt['cancel_requested'] = true; $attempt['state'] = 'canceling';
        self::save($order, $attempt);
        return self::reconcile($order, $attempt);
    }

    public static function reconcile($order, array $attempt): array {
        if ($attempt['state'] === 'declined') { return Payments::result($order); }
        if (!Payment_Lock::owned($order->get_id())) { return Payments::result($order, 'checking'); }
        Payments::refresh_reservation($order);
        $context = Square::context();
        if (is_wp_error($context) || $context !== $attempt['context']) { return self::waiting($order, $attempt, 'Restore the original Square connection to check these payments.'); }
        if (empty($attempt['pay_request']) && ($attempt['expires_at'] <= time() || Stock_Hold::deadline_passed($order) || !$order->has_status(array('pending', 'failed')))) {
            $attempt['cancel_requested'] = true;
        }
        if (empty($attempt['square_order_id'])) {
            $sq = Payments::create_square_order($order, $attempt);
            if (is_wp_error($sq)) { return self::waiting($order, $attempt); }
            $attempt['square_order_id'] = $sq['id']; $order->update_meta_data('square_order_id', $sq['id']);
        }
        if (!$attempt['tenders']) {
            // The payment journal is saved before CreatePayment; no entry means no charge was sent.
            $attempt['state'] = 'declined'; self::save($order, $attempt); Orders::expire_locked($order);
            return Payments::result($order, 'declined', 'No payment was sent. You can enter the payment details again.');
        }
        $unknown = false;
        foreach ($attempt['tenders'] as $index => $tender) {
            if ($tender['status'] === 'FAILED' && $tender['payment_id'] === '') { continue; }
            $response = self::fetch($order, $attempt, $tender);
            if (is_wp_error($response)) {
                if ($attempt['cancel_requested'] && empty($attempt['pay_request']) && $tender['payment_id'] === '') {
                    // Release an authorization whose CreatePayment response was lost. A successful empty
                    // response can also mean "not found", so still wait for a verified payment status.
                    Square::request('POST', '/v2/payments/cancel', array('idempotency_key' => $tender['id']));
                }
                $unknown = true; continue;
            }
            if (!self::apply($order, $attempt, $index, $response['payment'] ?? array())) { return self::review($order, $attempt); }
        }
        if ($unknown) { $attempt['state'] = !empty($attempt['pay_request']) ? 'completing' : ($attempt['cancel_requested'] ? 'canceling' : 'checking'); return self::waiting($order, $attempt); }
        $completed = array_filter($attempt['tenders'], static function ($t) { return $t['status'] === 'COMPLETED'; });
        if ($attempt['cancel_requested'] && empty($attempt['pay_request'])) {
            if ($completed) { return self::review($order, $attempt); }
            $attempt['state'] = 'canceling'; self::save($order, $attempt);
            foreach ($attempt['tenders'] as $index => $tender) {
                if ($tender['status'] !== 'APPROVED') { continue; }
                $response = Square::request('POST', '/v2/payments/' . rawurlencode($tender['payment_id']) . '/cancel');
                if (is_wp_error($response)) { return self::waiting($order, $attempt, 'Checking cancellation with Square. Applied funds are not yet confirmed released.'); }
                if (!self::apply($order, $attempt, $index, $response['payment'] ?? array()) || $attempt['tenders'][$index]['status'] !== 'CANCELED') { return self::review($order, $attempt); }
                self::save($order, $attempt);
            }
            $attempt['state'] = 'declined'; self::save($order, $attempt); Orders::expire_locked($order);
            $order->add_order_note('Cart Cat split payment cancelled. Square confirmed all pending authorizations were released.');
            return Payments::result($order, 'declined', 'Applied payments were cancelled and their reserved funds released.');
        }
        if (!hash_equals($attempt['fingerprint'], Payments::fingerprint($order))) { return self::review($order, $attempt); }
        if ($completed && empty($attempt['pay_request'])) { return self::review($order, $attempt); }
        if (self::collected($attempt) > $attempt['amount']) { return self::review($order, $attempt); }
        if (self::collected($attempt) < $attempt['amount']) {
            if (!empty($attempt['pay_request']) || $attempt['state'] === 'review') { return self::review($order, $attempt); }
            $attempt['state'] = 'partial'; self::save($order, $attempt); Payments::schedule($order->get_id());
            return Payments::result($order);
        }
        if ($attempt['state'] === 'review') { return self::review($order, $attempt); }
        return self::complete($order, $attempt);
    }

    private static function complete($order, array $attempt): array {
        $active = array_values(array_filter($attempt['tenders'], static function ($t) { return in_array($t['status'], array('APPROVED', 'COMPLETED'), true); }));
        foreach ($active as $tender) { if (!empty($tender['refunded'])) { return self::review($order, $attempt); } }
        $response = Square::request('GET', '/v2/orders/' . rawurlencode($attempt['square_order_id']));
        if (is_wp_error($response)) { return self::waiting($order, $attempt); }
        $sq = $response['order'] ?? array();
        if (($sq['id'] ?? '') !== $attempt['square_order_id'] || ($sq['reference_id'] ?? '') !== Payments::reference($order, $attempt) || ($sq['location_id'] ?? '') !== $attempt['context']['location_id'] || is_wp_error(Square_Order::verify($sq, $attempt['itemization']))) { return self::review($order, $attempt); }
        $ids = array_column($active, 'payment_id');
        if (empty($attempt['pay_request'])) {
            if (($sq['state'] ?? '') !== 'OPEN' || empty($sq['version'])) { return self::review($order, $attempt); }
            $attempt['pay_request'] = array('idempotency_key' => $attempt['id'] . '-pay', 'order_version' => $sq['version'], 'payment_ids' => $ids);
            $attempt['state'] = 'completing'; self::save($order, $attempt);
        }
        if ($ids !== $attempt['pay_request']['payment_ids']) { return self::review($order, $attempt); }
        $all_completed = !array_filter($active, static function ($t) { return $t['status'] !== 'COMPLETED'; });
        if (!$all_completed || ($sq['state'] ?? '') !== 'COMPLETED') {
            // Persisted request is replayed exactly, even after an ambiguous capture or browser reload.
            $response = Square::request('POST', '/v2/orders/' . rawurlencode($attempt['square_order_id']) . '/pay', $attempt['pay_request']);
            if (is_wp_error($response)) { return self::waiting($order, $attempt); }
            return self::waiting($order, $attempt);
        }
        $attempt['state'] = 'paid'; $attempt['payment_id'] = $ids[0];
        $order->update_meta_data('_cart_cat_square_payment_id', $ids[0]);
        $order->update_meta_data('_cart_cat_square_payment_ids', $ids);
        $order->update_meta_data('_cart_cat_receipt_url', $active[0]['receipt_url'] ?? '');
        self::save($order, $attempt);
        if (!$order->is_paid()) {
            $order->set_payment_method('cart_cat_square'); $order->set_payment_method_title('Square — Cart Cat');
            $finish = static function () use ($order, $ids) { $order->payment_complete($ids[0]); };
            if (!empty($attempt['itemization']['inventory'])) {
                $result = Square_Inventory::complete($order, $attempt['itemization'], $finish);
                if (is_wp_error($result)) { $attempt['state'] = 'completing'; return self::waiting($order, $attempt, 'Square captured the payments. Stock bookkeeping is being checked; do not pay again.'); }
            } else { $finish(); }
            $parts = array_map(static function ($t) { return ($t['kind'] === 'gift_card' ? 'gift card' : 'card') . ' ' . number_format($t['amount'] / 100, 2, '.', '') . ' (' . $t['payment_id'] . ')'; }, $active);
            $order->add_order_note('Cart Cat Square split payment captured: ' . implode('; ', $parts) . '.');
        }
        return Payments::result($order, 'paid');
    }
}
