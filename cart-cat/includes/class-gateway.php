<?php
namespace Cart_Cat;
defined('ABSPATH') || exit;

final class Gateway extends \WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'cart_cat_square'; $this->method_title = 'Square — Cart Cat';
        $this->title = 'Card or Square gift card'; $this->description = 'Pay securely with Square.';
        $this->enabled = 'yes'; $this->has_fields = true; $this->supports = array('products', 'refunds');
        add_action('wp_enqueue_scripts', array($this, 'scripts'));
    }
    public static function current_order() {
        $id = absint(get_query_var('order-pay'));
        return $id ? wc_get_order($id) : false;
    }
    private static function authorized($order): bool {
        if (!Payments::owns($order)) { return false; }
        $key = isset($_GET['key']) && is_scalar($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
        return $key !== '' && hash_equals($order->get_order_key(), $key) && current_user_can('pay_for_order', $order->get_id());
    }
    public function is_available() {
        $order = self::current_order();
        return $order && self::authorized($order) && !Stock_Hold::expired($order) && 'ready' === $order->get_meta('_cart_cat_preparation') && $order->needs_payment() && Square::public_config()['ready'];
    }
    public static function available_gateways($gateways) {
        $order = self::current_order();
        if (Payments::owns($order)) {
            return isset($gateways['cart_cat_square']) && self::authorized($order) && !Stock_Hold::expired($order) && 'ready' === $order->get_meta('_cart_cat_preparation') ? array('cart_cat_square' => $gateways['cart_cat_square']) : array();
        }
        unset($gateways['cart_cat_square']); return $gateways;
    }
    public function scripts(): void {
        $order = self::current_order();
        if (!self::authorized($order) || 'ready' !== $order->get_meta('_cart_cat_preparation')) { return; }
        wp_enqueue_script('cart-cat-payment', plugins_url('assets/js/payment.js', dirname(__DIR__) . '/cart-cat.php'), array(), CART_CAT_VERSION, true);
    }
    public function payment_fields() {
        $order = self::current_order(); if (!self::authorized($order) || 'ready' !== $order->get_meta('_cart_cat_preparation')) { return; }
        $state = Payments::status($order->get_id());
        $order = wc_get_order($order->get_id());
        $status = is_wp_error($state) ? 'checking' : $state['status'];
        if ($status !== 'paid' && $status !== 'checking' && !$order->needs_payment()) { $status = 'unavailable'; }
        $checking = in_array($status, array('checking', 'paid', 'unavailable'), true);
        $payment = !is_wp_error($state) ? ($state['payment'] ?? null) : null;
        $config = array('config' => Square::public_config(), 'amount' => $payment['remaining'] ?? $order->get_total(), 'currency' => $order->get_currency(), 'billing' => $order->get_address('billing'), 'channel' => 'customer', 'payment' => $payment, 'status' => $status);
        if ($status === 'paid') { $config['completed_url'] = $this->get_return_url($order); }
        include dirname(__DIR__) . '/templates/payment.php';
    }
    public function validate_fields() { return true; }
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $nonce = isset($_POST['woocommerce-pay-nonce']) && is_scalar($_POST['woocommerce-pay-nonce']) ? wp_unslash($_POST['woocommerce-pay-nonce']) : '';
        if (!self::authorized($order) || !wp_verify_nonce($nonce, 'woocommerce-pay')) { wc_add_notice('Please reopen the secure payment link.', 'error'); return array('result' => 'failure'); }
        $field = static function ($name, $default = '') { return isset($_POST[$name]) && is_string($_POST[$name]) ? wp_unslash($_POST[$name]) : $default; };
        $request = array('source_type' => $field('cart_cat_source_type', 'token'), 'token' => $field('cart_cat_token'), 'verification_token' => $field('cart_cat_verification_token'), 'version' => $field('cart_cat_version'), 'request_id' => $field('cart_cat_request_id'), 'payment_revision' => $field('cart_cat_payment_revision'));
        // The native pay-for-order capability, exact order key and Woo nonce protect both actions.
        $result = $field('cart_cat_action') === 'cancel' ? Payments::cancel_split((int) $order_id, $request, 'customer') : Payments::pay((int) $order_id, $request, 'customer');
        if (is_wp_error($result)) { wc_add_notice($result->get_error_message(), 'error'); return array('result' => 'failure'); }
        if ($result['status'] === 'paid') { return array('result' => 'success', 'redirect' => $this->get_return_url($order)); }
        if ($result['status'] === 'checking' || isset($result['payment'])) {
            if (!empty($result['message'])) { wc_add_notice($result['message'], 'notice'); }
            return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url());
        }
        wc_add_notice($result['message'] ?? 'Square did not complete the payment.', 'error');
        return array('result' => 'failure');
    }
    public function process_refund($order_id, $amount = null, $reason = '') { return Payments::refund((int) $order_id, $amount, (string) $reason); }
    public function get_transaction_url($order) {
        $payment = (string) $order->get_transaction_id();
        return $payment ? 'https://squareup.com/dashboard/sales/transactions/' . rawurlencode($payment) : '';
    }
}
