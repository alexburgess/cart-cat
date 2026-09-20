<?php
namespace Cart_Cat;
defined('ABSPATH') || exit;

/** Uses the same Square connection as the store, without exposing credentials. */
final class Square {
    public static function public_config(): array {
        $settings = get_option('square-woo-sync_settings', array());
        $gateways = function_exists('WC') && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        $gateway = $gateways['squaresync_credit'] ?? null;
        $application = $gateway && method_exists($gateway, 'get_application_id') ? $gateway->get_application_id() : '';
        $location = (string) ($settings['location'] ?? '');
        $environment = ($settings['environment'] ?? '') === 'sandbox' ? 'sandbox' : 'production';
        return apply_filters('cart_cat_square_public_config', array(
            'ready' => $application !== '' && $location !== '' && class_exists('Pixeldev\\SquareWooSyncPro\\Square\\SquareHelper') && defined('SWS_EXTERNAL_PAYMENT_OWNER_API') && SWS_EXTERNAL_PAYMENT_OWNER_API >= 1 && is_ssl(),
            'application_id' => (string) $application, 'location_id' => $location,
            'environment' => $environment, 'currency' => get_woocommerce_currency(),
            'country' => WC()->countries->get_base_country(),
            'wallets' => array(
                'applePay' => $gateway && 'yes' === $gateway->get_option('enable_apple_pay'),
                'googlePay' => $gateway && 'yes' === $gateway->get_option('enable_google_pay'),
            ),
        ));
    }

    /** Public connection identity only; safe to freeze without provider I/O. */
    public static function scope(): array {
        $config = self::public_config();
        return array('application_id' => (string) ($config['application_id'] ?? ''), 'location_id' => (string) ($config['location_id'] ?? ''), 'environment' => (string) ($config['environment'] ?? ''), 'currency' => (string) ($config['currency'] ?? ''));
    }

    public static function context() {
        $config = self::public_config();
        $server_ready = !empty($config['application_id']) && !empty($config['location_id']) && class_exists('Pixeldev\\SquareWooSyncPro\\Square\\SquareHelper') && defined('SWS_EXTERNAL_PAYMENT_OWNER_API') && SWS_EXTERNAL_PAYMENT_OWNER_API >= 1;
        // Background reconciliation does not render hosted fields and must work from CLI/cron.
        if (!$config['ready'] && !$server_ready) {
            return new \WP_Error('cart_cat_square_unavailable', 'Square payment is not ready. Check HTTPS, the Square connection and the Cart Cat compatibility patch.');
        }
        $location = self::request('GET', '/v2/locations/' . rawurlencode($config['location_id']));
        if (is_wp_error($location)) { return $location; }
        $data = $location['location'] ?? array();
        if (($data['id'] ?? '') !== $config['location_id'] || empty($data['merchant_id']) || ($data['status'] ?? '') !== 'ACTIVE' || ($data['currency'] ?? '') !== $config['currency']) {
            return new \WP_Error('cart_cat_square_location', 'Square location or currency could not be verified.');
        }
        return array('application_id' => $config['application_id'], 'location_id' => $config['location_id'], 'environment' => $config['environment'], 'merchant_id' => $data['merchant_id'], 'currency' => $config['currency']);
    }

    public static function request(string $method, string $path, array $body = array()) {
        // The allowlist prevents a caller from turning this into a generic provider proxy.
        $read_only = strtoupper($method) === 'GET' && preg_match('#^/v2/(?:catalog/object|inventory)/[A-Za-z0-9_-]+(?:\?[A-Za-z0-9_=&.%+~\-]*)?$#D', $path);
        if (!$read_only && !preg_match('#^/v2/(?:locations|customers|cards|orders|payments|refunds)(?:[/?][A-Za-z0-9_:/?&=.%+~\-]*)?$#D', $path)) {
            return new \WP_Error('cart_cat_square_path', 'Unsupported Square request.');
        }
        $mock = apply_filters('cart_cat_square_transport', null, $method, $path, $body);
        if ($mock !== null) { return $mock; }
        if (!class_exists('Pixeldev\\SquareWooSyncPro\\Square\\SquareHelper')) {
            return new \WP_Error('cart_cat_square_unavailable', 'Square connection is unavailable.');
        }
        try {
            $client = new \Pixeldev\SquareWooSyncPro\Square\SquareHelper();
            $response = $client->square_api_request(substr($path, 3), strtoupper($method), $body ?: null, null, false, array('Square-Version' => '2026-01-22'));
        } catch (\Throwable $e) {
            return new \WP_Error('cart_cat_square_unknown', 'Square did not confirm this request.', array('status' => 0, 'codes' => array()));
        }
        if (empty($response['success']) || !is_array($response['data'] ?? null)) {
            $codes = array();
            foreach ((array) ($response['errors'] ?? array()) as $error) {
                if (is_array($error) && isset($error['code'])) { $codes[] = sanitize_key($error['code']); }
            }
            return new \WP_Error('cart_cat_square_error', 'Square did not confirm this request.', array('status' => (int) ($response['status'] ?? 0), 'codes' => $codes));
        }
        return $response['data'];
    }
}
