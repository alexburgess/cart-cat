<?php
/** Test-only SWS stand-ins. Require only on an isolated localhost WP runtime. */
namespace {
    if (!defined('WP_CLI') || !WP_CLI || !function_exists('wc_create_order') || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), array('localhost', '127.0.0.1'), true)) { throw new \RuntimeException('Square inventory fixtures require isolated localhost WooCommerce.'); }
    $GLOBALS['cart_cat_inventory_fixture'] = $GLOBALS['cart_cat_inventory_fixture'] ?? array('handled' => array(), 'applied' => array(), 'fail' => false, 'silent_skip' => false);
}
namespace Pixeldev\SquareWooSyncPro\Common {
    if (!class_exists(Helpers::class)) {
        class Helpers {
            public static function is_stock_locked($product): bool {
                $id = $product instanceof \WC_Product ? $product->get_id() : (int) $product;
                return (bool) apply_filters('squarewoosync_ignore_square_stock', get_post_meta($id, 'square_ignore_stock_updates', true) === 'yes', $id, $product);
            }
            public static function convert_quantity($quantity) {
                $settings = get_option('square-woo-sync_settings', array());
                return !empty($settings['inventory']['enableDecimalStock']) ? (float) $quantity : (int) $quantity;
            }
            public static function apply_stock_buffer($quantity, $product) {
                $settings = get_option('square-woo-sync_settings', array());
                $buffer = max(0, (float) ($settings['inventory']['stockBuffer'] ?? 0));
                if ($buffer === 0.0 || $product->get_meta('square_ignore_stock_buffer', true) === 'yes') { return $quantity; }
                return self::convert_quantity(max(0, (float) $quantity - $buffer));
            }
            public static function combines_location_stock($product, array $context = array()): bool {
                $settings = get_option('square-woo-sync_settings', array());
                $id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
                return (bool) apply_filters('squarewoosync_combine_location_stock', !empty($settings['squareAuto']['combineAllLocationStock']), $id, $context);
            }
        }
    }
}
namespace Pixeldev\SquareWooSyncPro\Inventory {
    if (!class_exists(LocationStock::class)) {
        class LocationStock {
            public static function absent_for(int $id): array {
                $product = wc_get_product($id);
                $owner = $product && $product->is_type('variation') ? $product->get_parent_id() : $id;
                return array_values(array_filter(array_map('trim', explode(',', (string) get_post_meta($owner, 'square_absent_locations', true))), 'strlen'));
            }
        }
    }
}
namespace Pixeldev\SquareWooSyncPro\Woo {
    if (!class_exists(SyncProduct::class)) {
        class SyncProduct {
            private static $guards = array();
            public static function guard_from_square(int $id): void { self::$guards[$id] = true; }
            public static function unguard_from_square(int $id): void { unset(self::$guards[$id]); }
            public static function is_from_square_this_request(int $id): bool { return !empty(self::$guards[$id]); }
        }
    }
}
namespace Pixeldev\SquareWooSyncPro\Jobs {
    if (!class_exists(WebhookProcessJob::class)) {
        class WebhookProcessJob {
            private $event;
            private $payload;
            public function __construct($event, $payload) { $this->event = $event; $this->payload = $payload; }
            public function handle(): void {
                if ($GLOBALS['cart_cat_inventory_fixture']['fail']) { throw new \RuntimeException('Simulated SWS apply failure.'); }
                $GLOBALS['cart_cat_inventory_fixture']['handled'][] = $this->payload;
                if (!empty($GLOBALS['cart_cat_inventory_fixture']['silent_skip'])) { return; }
                if ($this->event !== 'inventory.count.updated') { throw new \RuntimeException('Unexpected fixture event.'); }
                $maps = array();
                foreach ($this->payload['data']['object']['inventory_counts'] as $count) {
                    if (($count['state'] ?? '') !== 'IN_STOCK') { continue; }
                    $maps[$count['catalog_object_id']][$count['location_id']] = $count['quantity'];
                }
                $settings = get_option('square-woo-sync_settings', array());
                foreach ($maps as $catalog_id => $map) {
                    global $wpdb;
                    $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('square_variation_id','square_product_id') AND meta_value=%s", $catalog_id));
                    foreach ($ids as $id) {
                        $product = wc_get_product((int) $id);
                        if (!$product || $product->is_type('variable')) { continue; }
                        if (\Pixeldev\SquareWooSyncPro\Common\Helpers::is_stock_locked($product)) { continue; }
                        $product_map = $map;
                        foreach (\Pixeldev\SquareWooSyncPro\Inventory\LocationStock::absent_for((int) $id) as $absent) { unset($product_map[$absent]); }
                        $quantity = null;
                        if (\Pixeldev\SquareWooSyncPro\Common\Helpers::combines_location_stock($product, array('source' => 'webhook', 'catalog_object_id' => $catalog_id))) {
                            $quantity = 0;
                            foreach ($product_map as $count) { $quantity += \Pixeldev\SquareWooSyncPro\Common\Helpers::convert_quantity($count); }
                        } else {
                            foreach ($product_map as $location => $count) {
                                if (!empty($settings['wooAuto']['allLocationsStock']) || $location === ($settings['location'] ?? 'loc-test')) { $quantity = \Pixeldev\SquareWooSyncPro\Common\Helpers::convert_quantity($count); }
                            }
                        }
                        if ($quantity === null) { continue; }
                        $quantity = \Pixeldev\SquareWooSyncPro\Common\Helpers::apply_stock_buffer($quantity, $product);
                        \Pixeldev\SquareWooSyncPro\Woo\SyncProduct::guard_from_square((int) $id);
                        $product->set_manage_stock(true);
                        $product->save();
                        wc_update_product_stock($product, (float) $quantity, 'set');
                        $GLOBALS['cart_cat_inventory_fixture']['applied'][] = array((int) $id, (float) $quantity);
                    }
                }
            }
        }
    }
}
