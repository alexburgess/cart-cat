<?php
namespace Cart_Cat;
defined('ABSPATH') || exit;

final class Gateway extends \WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'cart_cat_square'; $this->method_title = 'Square — Cart Cat';
        $this->title = 'Credit or debit card'; $this->description = 'Pay securely with Square.';
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
        $checking = !is_wp_error($state) && ($state['status'] ?? '') === 'checking';
        $config = array('config' => Square::public_config(), 'amount' => $order->get_total(), 'currency' => $order->get_currency(), 'billing' => $order->get_address('billing'), 'channel' => 'customer');
        include dirname(__DIR__) . '/templates/payment.php';
    }
    public function validate_fields() { return true; }
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $nonce = isset($_POST['woocommerce-pay-nonce']) && is_scalar($_POST['woocommerce-pay-nonce']) ? wp_unslash($_POST['woocommerce-pay-nonce']) : '';
        if (!self::authorized($order) || !wp_verify_nonce($nonce, 'woocommerce-pay')) { wc_add_notice('Please reopen the secure payment link.', 'error'); return array('result' => 'failure'); }
        $result = Payments::pay((int) $order_id, array('source_type' => 'token', 'token' => isset($_POST['cart_cat_token']) && is_scalar($_POST['cart_cat_token']) ? wp_unslash($_POST['cart_cat_token']) : '', 'verification_token' => isset($_POST['cart_cat_verification_token']) && is_scalar($_POST['cart_cat_verification_token']) ? wp_unslash($_POST['cart_cat_verification_token']) : '', 'version' => isset($_POST['cart_cat_version']) && is_scalar($_POST['cart_cat_version']) ? sanitize_text_field(wp_unslash($_POST['cart_cat_version'])) : ''), 'customer');
        if (is_wp_error($result)) { wc_add_notice($result->get_error_message(), 'error'); return array('result' => 'failure'); }
        if ($result['status'] === 'paid') { return array('result' => 'success', 'redirect' => $this->get_return_url($order)); }
        if ($result['status'] === 'checking') { return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url()); }
        wc_add_notice($result['message'] ?? 'Square did not complete the payment.', 'error');
        return array('result' => 'failure');
    }
    public function process_refund($order_id, $amount = null, $reason = '') { return Payments::refund((int) $order_id, $amount, (string) $reason); }
    public function get_transaction_url($order) {
        $payment = (string) $order->get_transaction_id();
        return $payment ? 'https://squareup.com/dashboard/sales/transactions/' . rawurlencode($payment) : '';
    }
}
