<?php
namespace Cart_Cat;
defined('ABSPATH') || exit;

/** Connection-scoped MySQL mutex: no time lease can expire during provider I/O. */
final class Payment_Lock {
    private static $held = array();
    private static function name(int $order_id): string {
        global $wpdb;
        return 'cart_cat_' . substr(hash('sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $order_id), 0, 48);
    }
    public static function acquire(int $order_id) {
        global $wpdb;
        if (isset(self::$held[$order_id])) { return new \WP_Error('cart_cat_payment_busy', 'This order is already being processed. Check its payment status.'); }
        $name = self::name($order_id);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
            return new \WP_Error('cart_cat_payment_busy', 'This order is already being processed. Check its payment status.');
        }
        $owner = wp_generate_uuid4(); self::$held[$order_id] = $owner;
        return array($order_id, $name, $owner);
    }
    public static function owned(int $order_id): bool {
        global $wpdb;
        if (!isset(self::$held[$order_id])) { return false; }
        return (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::name($order_id))) === '1';
    }
    public static function release(array $lease): void {
        global $wpdb;
        if ((self::$held[$lease[0]] ?? null) !== $lease[2]) { return; }
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lease[1])); unset(self::$held[$lease[0]]);
    }
}
