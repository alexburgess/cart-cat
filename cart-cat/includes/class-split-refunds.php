<?php
namespace Cart_Cat;
defined('ABSPATH') || exit;

/** One Woo refund intent, with fixed allocations and an idempotency key for every original payment. */
final class Split_Refunds {
    private static function error(string $message) { return new \WP_Error('cart_cat_split_refund', $message); }

    private static function save($order, array $intent): void {
        if (!Payment_Lock::owned($order->get_id())) { throw new \RuntimeException('Refund lock was lost.'); }
        $ids = array_values(array_filter(array_column($intent['parts'], 'refund_id')));
        foreach ($ids as $id) { add_option('_cart_cat_refund_map_' . hash('sha256', $id), $order->get_id(), '', false); }
        $order->update_meta_data('_cart_cat_pending_refund_ids', $ids);
        $order->update_meta_data('_cart_cat_refund_intent', $intent); $order->save();
    }

    public static function refund($order, array $attempt, $amount, string $reason, int $local_id) {
        if ($attempt['state'] !== 'paid' || !$local_id) { return self::error('Start this refund from the WooCommerce order screen after payment is complete.'); }
        $minor = Payments::minor(wc_format_decimal($amount, 2), $attempt['currency']);
        if (is_wp_error($minor) || $minor <= 0) { return self::error('Enter a positive refund amount.'); }
        $context = Square::context();
        if (is_wp_error($context) || $context !== $attempt['context']) { return self::error('The original Square connection is required.'); }
        $intent = Payments::meta_array($order, '_cart_cat_refund_intent');
        if ($intent) {
            if (($intent['schema'] ?? 0) !== 2) { return self::error('Review the previous refund before starting another.'); }
            if (!empty($intent['projected_id']) && (int) $intent['projected_id'] === $local_id) { return $intent['amount'] === $minor && in_array($intent['state'], array('COMPLETED', 'PENDING'), true) ? true : self::error('This refund is still being checked.'); }
            $projected = !empty($intent['projected_id']) ? wc_get_order($intent['projected_id']) : false;
            $confirmed = array_sum(array_map(static function ($part) { return $part['state'] === 'COMPLETED' ? $part['amount'] : 0; }, $intent['parts']));
            if ($projected && in_array($intent['state'], array('PARTIAL', 'FAILED'), true) && Payments::minor(wc_format_decimal($projected->get_amount(), 2), $intent['currency']) !== $confirmed) {
                return self::error('Square rejected part of the previous refund. Reconcile its existing Woo refund record with the confirmed money before requesting another refund.');
            }
            if (in_array($intent['state'], array('COMPLETED', 'PARTIAL', 'FAILED'), true) && (($projected && $projected->get_refunded_payment()) || ($intent['state'] === 'FAILED' && !$projected))) {
                $history = Payments::meta_array($order, '_cart_cat_refund_history'); $history[] = $intent;
                $order->update_meta_data('_cart_cat_refund_history', $history); $intent = array();
            } else {
                if ($projected || $intent['state'] === 'PENDING') { return self::error('Square is still processing the previous refund. Wait for its final result.'); }
                if (!empty($intent['projecting'])) { return self::error('The previous refund is being recorded. Review its existing records before retrying.'); }
                if ($minor !== $intent['amount']) { return self::error('The previous refund is unresolved. Retry that same amount to check it.'); }
            }
        }
        if (!$intent) {
            $remaining = $minor; $parts = array();
            $known = array();
            foreach (Payments::meta_array($order, '_cart_cat_refund_history') as $past) {
                foreach ($past['parts'] ?? array() as $part) { if ($part['state'] === 'COMPLETED') { $known[$part['payment_id']] = ($known[$part['payment_id']] ?? 0) + $part['amount']; } }
            }
            // Refund the card remainder first, then gift cards. Never return more to a source than it paid.
            $tenders = array_reverse($attempt['tenders']);
            usort($tenders, static function ($a, $b) { return ($a['kind'] === 'gift_card' ? 1 : 0) <=> ($b['kind'] === 'gift_card' ? 1 : 0); });
            foreach ($tenders as $tender) {
                if ($tender['status'] !== 'COMPLETED') { continue; }
                $response = Square::request('GET', '/v2/payments/' . rawurlencode($tender['payment_id']));
                if (is_wp_error($response)) { return self::error('Square could not verify the refundable payments. No refund was sent.'); }
                $payment = $response['payment'] ?? array();
                if (!Split_Payments::matches($order, $attempt, $tender, $payment) || $payment['status'] !== 'COMPLETED') { return self::error('A payment does not match the original checkout. Review it before refunding.'); }
                $refunded = (int) ($payment['refunded_money']['amount'] ?? 0);
                if ($refunded !== ($known[$tender['payment_id']] ?? 0)) { return self::error('Square has a refund that does not match Cart Cat’s records. Reconcile it before refunding again.'); }
                $take = min($remaining, $tender['amount'] - $refunded);
                if ($take > 0) {
                    $parts[] = array('id' => wp_generate_uuid4(), 'payment_id' => $tender['payment_id'], 'kind' => $tender['kind'], 'amount' => $take, 'refund_id' => '', 'state' => 'new');
                    $remaining -= $take;
                }
            }
            if ($remaining !== 0) { return self::error('The original payments have insufficient refundable funds.'); }
            $intent = array('schema' => 2, 'id' => wp_generate_uuid4(), 'amount' => $minor, 'currency' => $attempt['currency'], 'context' => $context, 'reason' => substr(sanitize_text_field($reason), 0, 192), 'parts' => $parts, 'state' => 'checking', 'native_id' => $local_id, 'projected_id' => 0, 'started' => time());
        }
        $intent['native_id'] = $local_id;
        self::save($order, $intent);
        self::process($order, $intent);
        if (in_array($intent['state'], array('COMPLETED', 'PENDING'), true)) {
            $intent['projected_id'] = $local_id; self::save($order, $intent);
            $local = wc_get_order($local_id);
            if ($local) { $local->update_meta_data('_cart_cat_square_refund_ids', array_column($intent['parts'], 'refund_id')); $local->update_meta_data('_cart_cat_refund_intent_id', $intent['id']); $local->save(); }
            return true;
        }
        Payments::schedule($order->get_id());
        return self::error('The split refund is being checked. Any confirmed refunds are recorded separately; do not refund in Square or request another amount.');
    }

    private static function process($order, array &$intent): void {
        foreach ($intent['parts'] as $index => $part) {
            if (in_array($part['state'], array('COMPLETED', 'FAILED', 'REJECTED', 'NOT_SENT'), true)) { continue; }
            $response = $part['refund_id'] !== ''
                ? Square::request('GET', '/v2/refunds/' . rawurlencode($part['refund_id']))
                : Square::request('POST', '/v2/refunds', array('idempotency_key' => $part['id'], 'payment_id' => $part['payment_id'], 'amount_money' => array('amount' => $part['amount'], 'currency' => $intent['currency']), 'reason' => $intent['reason']));
            if (is_wp_error($response)) { $intent['state'] = 'checking'; self::save($order, $intent); Payments::schedule($order->get_id()); return; }
            $refund = $response['refund'] ?? array();
            if (empty($refund['id']) || ($part['refund_id'] !== '' && $part['refund_id'] !== $refund['id']) || ($refund['payment_id'] ?? '') !== $part['payment_id'] || ($refund['amount_money']['amount'] ?? -1) !== $part['amount'] || ($refund['amount_money']['currency'] ?? '') !== $intent['currency'] || !in_array($refund['status'] ?? '', array('COMPLETED', 'PENDING', 'FAILED', 'REJECTED'), true)) {
                $intent['state'] = 'review'; self::save($order, $intent); Payments::schedule($order->get_id()); return;
            }
            $intent['parts'][$index]['refund_id'] = $refund['id'];
            $intent['parts'][$index]['state'] = $refund['status'];
            self::save($order, $intent);
            if (in_array($refund['status'], array('FAILED', 'REJECTED'), true)) {
                foreach ($intent['parts'] as &$later) { if ($later['state'] === 'new') { $later['state'] = 'NOT_SENT'; } } unset($later);
                break;
            }
        }
        $states = array_column($intent['parts'], 'state');
        $failed = array_intersect($states, array('FAILED', 'REJECTED', 'NOT_SENT'));
        $intent['state'] = in_array('PENDING', $states, true) ? 'PENDING' : ($failed ? (in_array('COMPLETED', $states, true) ? 'PARTIAL' : 'FAILED') : 'COMPLETED');
        // PENDING with another rejected allocation is not a successful full Woo refund.
        if ($failed && $intent['state'] === 'PENDING') { $intent['state'] = 'checking'; }
        self::save($order, $intent);
        if (in_array($intent['state'], array('checking', 'PENDING'), true)) { Payments::schedule($order->get_id()); }
    }

    public static function reconcile($order, array $intent): void {
        $context = Square::context();
        if (is_wp_error($context) || $context !== $intent['context']) { Payments::schedule($order->get_id()); return; }
        self::process($order, $intent);
        if (!in_array($intent['state'], array('COMPLETED', 'PARTIAL', 'FAILED'), true)) { return; }
        if (!empty($intent['projected_id']) && wc_get_order($intent['projected_id'])) {
            if ($intent['state'] !== 'COMPLETED' && empty($intent['rejection_noted'])) {
                $order->add_order_note('Square rejected part of split refund ' . $intent['id'] . '. Review the existing Woo refund; no replacement refund was sent.');
                $intent['rejection_noted'] = true; self::save($order, $intent);
            }
            return;
        }
        // Native Woo deletes its provisional refund when the gateway returns an unknown result.
        // Recover confirmed money-only refunds once that provisional row is gone; never restock twice.
        if (wc_get_order($intent['native_id'])) { Payments::schedule($order->get_id()); return; }
        $amount = 0; $ids = array();
        foreach ($intent['parts'] as $part) { if ($part['state'] === 'COMPLETED') { $amount += $part['amount']; $ids[] = $part['refund_id']; } }
        if (!$amount) { return; }
        $fresh = wc_get_order($order->get_id());
        foreach ($fresh->get_refunds() as $refund) {
            if ($refund->get_meta('_cart_cat_refund_intent_id') === $intent['id']) { $intent['projected_id'] = $refund->get_id(); self::save($order, $intent); return; }
        }
        if (!empty($intent['projecting'])) { return; } // An interrupted local save requires review, never duplicate projection.
        $intent['projecting'] = true; self::save($order, $intent);
        $tag = static function ($refund, $args) use ($order, $intent, $ids) {
            if ((int) $args['order_id'] !== $order->get_id() || ($args['cart_cat_recovery'] ?? '') !== $intent['id']) { return; }
            $refund->update_meta_data('_cart_cat_refund_intent_id', $intent['id']);
            $refund->update_meta_data('_cart_cat_square_refund_ids', $ids);
            $refund->set_refunded_payment(true);
        };
        add_action('woocommerce_create_refund', $tag, 99, 2);
        try {
            $refund = wc_create_refund(array('order_id' => $order->get_id(), 'amount' => $amount / 100, 'reason' => $intent['reason'] . ' (Square refund reconciliation)', 'refund_payment' => false, 'restock_items' => false, 'cart_cat_recovery' => $intent['id']));
        } finally { remove_action('woocommerce_create_refund', $tag, 99); }
        if (is_wp_error($refund)) {
            $order->add_order_note('Square confirmed refund ' . $intent['id'] . ', but its Woo record needs review. Do not issue another refund.');
            return;
        }
        $intent['projected_id'] = $refund->get_id(); self::save($order, $intent);
        $order->add_order_note('Recorded confirmed Square split refunds of ' . number_format($amount / 100, 2, '.', '') . ' ' . $intent['currency'] . ' after an interrupted refund. Inventory was not restocked. ' . ($intent['state'] === 'PARTIAL' ? 'Square rejected the remaining allocation; review before requesting any further refund.' : ''));
    }
}
