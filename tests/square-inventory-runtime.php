<?php
/** Real Woo bookkeeping with mocked Square counts; disposable localhost only. */
if (!defined('WP_CLI') || !WP_CLI || !function_exists('wc_create_order') || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), array('localhost', '127.0.0.1'), true)) { throw new RuntimeException('Run against isolated localhost WooCommerce.'); }
$source = rtrim(getenv('CART_CAT_SOURCE') ?: dirname(__DIR__), '/');
require_once $source . '/cart-cat/cart-cat.php';
require_once __DIR__ . '/square-inventory-fixture.php';
use Cart_Cat\Square_Inventory;
use Cart_Cat\Square;
use Pixeldev\SquareWooSyncPro\Jobs\WebhookProcessJob;
use Pixeldev\SquareWooSyncPro\Woo\SyncProduct;
global $inventory_assertions, $inventory_products, $inventory_orders;
$inventory_assertions = 0;
$inventory_products = array();
$inventory_orders = array();
$inventory_remote = array('requests' => array(), 'pages' => array(), 'fail' => false, 'quantity' => '4');
$inventory_config = array('ready' => true, 'application_id' => 'test-app', 'location_id' => 'loc-test', 'environment' => 'sandbox', 'currency' => 'USD');
$inventory_settings = array('location' => 'loc-test', 'squareAuto' => array('isActive' => true, 'stock' => true), 'wooAuto' => array('isActive' => false, 'stock' => false));
$inventory_settings_filter = static function() use (&$inventory_settings) { return $inventory_settings; };
add_filter('pre_option_square-woo-sync_settings', $inventory_settings_filter);
add_filter('cart_cat_square_public_config', static function() use (&$inventory_config) { return $inventory_config; });
add_filter('cart_cat_square_transport', static function($original, $method, $path, $body) use (&$inventory_remote) {
    $inventory_remote['requests'][] = array($method, $path);
    if ($method !== 'GET' || strpos($path, '/v2/inventory/') !== 0) { throw new RuntimeException('Inventory fixture forbids provider mutation: ' . $method . ' ' . $path); }
    if ($inventory_remote['fail']) { return new WP_Error('fixture_unavailable', 'Inventory temporarily unavailable.'); }
    if ($inventory_remote['pages']) { return array_shift($inventory_remote['pages']); }
    $id = rawurldecode(explode('?', substr($path, strlen('/v2/inventory/')), 2)[0]);
    return array('counts' => array(array('catalog_object_id' => $id, 'catalog_object_type' => 'ITEM_VARIATION', 'location_id' => 'loc-test', 'state' => 'IN_STOCK', 'quantity' => $inventory_remote['quantity'])));
}, 10, 4);
function cc_inventory_expect($value, $message) { global $inventory_assertions; $inventory_assertions++; if (!$value) { throw new RuntimeException($message); } }
function cc_inventory_order(bool $mixed = false): array {
    global $inventory_products, $inventory_orders;
    $product = new WC_Product_Simple();
    $product->set_name('Cart Cat catalog inventory fixture'); $product->set_regular_price('10'); $product->set_manage_stock(true); $product->set_stock_quantity(5); $product->save();
    $catalog_id = 'inventory-fixture-' . $product->get_id();
    $product->update_meta_data('square_variation_id', $catalog_id); $product->save(); $inventory_products[] = $product->get_id();
    $order = wc_create_order(array('created_via' => 'cart_cat')); $inventory_orders[] = $order->get_id();
    $item_id = $order->add_product($product, 1);
    $custom_id = 0;
    if ($mixed) {
        $custom = new WC_Product_Simple(); $custom->set_name('Unlinked inventory fixture'); $custom->set_regular_price('2'); $custom->set_manage_stock(true); $custom->set_stock_quantity(5); $custom->save();
        $custom_id = $custom->get_id(); $inventory_products[] = $custom_id; $order->add_product($custom, 1);
    }
    $order->set_currency('USD'); $order->set_payment_method('cart_cat_square'); $order->calculate_totals(false);
    $order->update_meta_data('_cart_cat_order', '1'); $order->update_meta_data('_cart_cat_square_scope', Square::scope()); $order->save();
    return array($order, array('inventory' => array($item_id => array('catalog_id' => $catalog_id, 'quantity' => 1, 'product_id' => $product->get_id()))), $product->get_id(), $custom_id, $item_id);
}
function cc_inventory_stock(int $id) { return (float) wc_get_product($id)->get_stock_quantity(); }
function cc_inventory_run_refresh(int $id): void { wp_clear_scheduled_hook('cart_cat_refresh_square_inventory', array($id)); Square_Inventory::refresh_order($id); }
try {
    cc_inventory_expect(Square_Inventory::ready() === true, 'Active SWS inbound stock allows catalog stock ownership.');
    $inventory_settings = array('squareAuto' => array('isActive' => true, 'stock' => false));
    cc_inventory_expect(is_wp_error(Square_Inventory::ready()), 'Disabled inbound stock fails readiness before payment.');
    $inventory_settings = array('location' => 'loc-test', 'squareAuto' => array('isActive' => true, 'stock' => true), 'wooAuto' => array('isActive' => false, 'stock' => false));

    list($validation_order, , $validation_id) = cc_inventory_order();
    $validation_product = wc_get_product($validation_id);
    cc_inventory_expect(Square_Inventory::validate_product($validation_product) === true, 'An independently managed unlocked product supports Square stock ownership.');
    $validation_product->update_meta_data('square_ignore_stock_updates', 'yes'); $validation_product->save_meta_data();
    cc_inventory_expect(is_wp_error(Square_Inventory::validate_product(wc_get_product($validation_id))), 'SWS per-product stock lock fails before payment.');
    $validation_product->delete_meta_data('square_ignore_stock_updates'); $validation_product->save_meta_data();
    $lock_filter = static function($locked, $id) use ($validation_id) { return $id === $validation_id || $locked; };
    add_filter('squarewoosync_ignore_square_stock', $lock_filter, 10, 2);
    cc_inventory_expect(is_wp_error(Square_Inventory::validate_product(wc_get_product($validation_id))), 'SWS stock lock filter also fails before payment.');
    remove_filter('squarewoosync_ignore_square_stock', $lock_filter, 10);
    $parent = new WC_Product_Variable(); $parent->set_name('Inventory shared-stock fixture'); $parent->set_manage_stock(true); $parent->set_stock_quantity(5); $parent->save(); $inventory_products[] = $parent->get_id();
    $child = new WC_Product_Variation(); $child->set_parent_id($parent->get_id()); $child->set_regular_price('10'); $child->set_manage_stock(false); $child->save(); $inventory_products[] = $child->get_id();
    cc_inventory_expect(is_wp_error(Square_Inventory::validate_product(wc_get_product($child->get_id()))), 'Variation sharing parent stock is rejected.');
    $child->set_manage_stock(true); $child->set_stock_quantity(5); $child->save();
    $parent->update_meta_data('square_ignore_stock_updates', 'yes'); $parent->save_meta_data();
    cc_inventory_expect(Square_Inventory::validate_product(wc_get_product($child->get_id())) === true, 'Independent variation follows its own stock lock, matching native SWS semantics.');

    foreach (array('before', 'after') as $timing) {
        list($order, $snapshot, $product_id, , $item_id) = cc_inventory_order();
        $order->get_items(); // Intentionally hold the pre-payment item metadata cache.
        $event = array('data' => array('object' => array('inventory_counts' => array(array('catalog_object_id' => $snapshot['inventory'][$item_id]['catalog_id'], 'location_id' => 'loc-test', 'state' => 'IN_STOCK', 'quantity' => '4')))));
        if ($timing === 'before') { (new WebhookProcessJob('inventory.count.updated', $event))->handle(); }
        $fingerprint = \Cart_Cat\Payments::fingerprint($order);
        $result = Square_Inventory::complete($order, $snapshot, static function() use ($order) { $order->payment_complete('fixture-payment'); return 'paid'; });
        if ($timing === 'after') { (new WebhookProcessJob('inventory.count.updated', $event))->handle(); }
        $fresh = wc_get_order($order->get_id());
        cc_inventory_expect($result === 'paid' && $fresh->is_paid(), 'Native payment completion preserved with webhook ' . $timing . ' response.');
        cc_inventory_expect(cc_inventory_stock($product_id) === 4.0, 'Webhook ' . $timing . ' response never subtracts the catalog sale twice.');
        cc_inventory_expect((float) $fresh->get_item($item_id)->get_meta('_reduced_stock') === 1.0 && $fresh->get_data_store()->get_stock_reduced($fresh->get_id()), 'Native item and order stock bookkeeping remains recorded.');
        cc_inventory_expect(\Cart_Cat\Payments::fingerprint($fresh) === $fingerprint, 'Stock bookkeeping does not invalidate the frozen payment fingerprint.');
        cc_inventory_expect((bool) wp_next_scheduled('cart_cat_refresh_square_inventory', array($order->get_id())), 'A durable fresh-count recovery event survives completion.');
        Square_Inventory::complete($fresh, $snapshot, static function() use ($fresh) { $fresh->payment_complete('fixture-payment'); });
        cc_inventory_expect(cc_inventory_stock($product_id) === 4.0, 'Repeated completion remains an absolute-count refresh.');
    }

    list($unmanaged, $unmanaged_snapshot, $unmanaged_id) = cc_inventory_order();
    $unmanaged_product = wc_get_product($unmanaged_id); $unmanaged_product->set_manage_stock(false); $unmanaged_product->save();
    Square_Inventory::complete($unmanaged, $unmanaged_snapshot, static function() use ($unmanaged) { $unmanaged->payment_complete('unmanaged-fixture'); });
    cc_inventory_expect(wc_get_product($unmanaged_id)->managing_stock() && cc_inventory_stock($unmanaged_id) === 4.0, 'Square count enabling Woo stock management before completion still never double-deducts.');

    list($mixed, $snapshot, $linked_id, $custom_id) = cc_inventory_order(true);
    Square_Inventory::complete($mixed, $snapshot, static function() use ($mixed) { $mixed->payment_complete('mixed-fixture'); });
    cc_inventory_expect(cc_inventory_stock($linked_id) === 4.0 && cc_inventory_stock($custom_id) === 4.0, 'Mixed orders keep native stock reduction for unlinked lines only.');
    list($legacy, , $legacy_id, , $legacy_item) = cc_inventory_order();
    $before = count($inventory_remote['requests']);
    Square_Inventory::complete($legacy, array(), static function() use ($legacy) { $legacy->payment_complete('legacy-fixture'); });
    cc_inventory_expect(cc_inventory_stock($legacy_id) === 4.0 && count($inventory_remote['requests']) === $before, 'Legacy total-only attempts keep native stock behavior without inventory requests.');
    cc_inventory_expect(Square_Inventory::can_restock_refunded_items(true, $legacy, array($legacy_item => array('qty' => 1))), 'Legacy custom-total orders retain native refund restocking.');

    list($failed, $snapshot, $failed_product) = cc_inventory_order();
    $inventory_remote['fail'] = true;
    Square_Inventory::complete($failed, $snapshot, static function() use ($failed) { $failed->payment_complete('refresh-failure'); });
    cc_inventory_expect(wc_get_order($failed->get_id())->is_paid() && cc_inventory_stock($failed_product) === 5.0, 'Unavailable inventory refresh never vetoes a confirmed capture or double-deducts.');
    cc_inventory_run_refresh($failed->get_id());
    $record = wc_get_order($failed->get_id())->get_meta('_cart_cat_square_inventory_refresh');
    cc_inventory_expect($record['attempts'] === 1 && $record['state'] === 'pending', 'Failed fresh read records a durable retry.');
    $inventory_remote['fail'] = false;
    cc_inventory_run_refresh($failed->get_id()); cc_inventory_run_refresh($failed->get_id());
    cc_inventory_expect(cc_inventory_stock($failed_product) === 4.0 && wc_get_order($failed->get_id())->get_meta('_cart_cat_square_inventory_refresh')['state'] === 'complete', 'Fresh retry and follow-up recover exact inventory.');

    list($silent, $silent_snapshot, $silent_id) = cc_inventory_order();
    $GLOBALS['cart_cat_inventory_fixture']['silent_skip'] = true;
    Square_Inventory::complete($silent, $silent_snapshot, static function() use ($silent) { $silent->payment_complete('silent-fixture'); });
    cc_inventory_run_refresh($silent->get_id());
    cc_inventory_expect(cc_inventory_stock($silent_id) === 5.0 && wc_get_order($silent->get_id())->get_meta('_cart_cat_square_inventory_refresh')['attempts'] === 1, 'Silent SWS handler no-op is detected by actual Woo count and remains retryable.');
    $GLOBALS['cart_cat_inventory_fixture']['silent_skip'] = false;
    cc_inventory_run_refresh($silent->get_id());
    cc_inventory_expect(cc_inventory_stock($silent_id) === 4.0, 'A later native SWS apply recovers a silent skipped refresh.');

    list($refunded, $snapshot, $refunded_product, , $refunded_item) = cc_inventory_order();
    Square_Inventory::complete($refunded, $snapshot, static function() use ($refunded) { $refunded->payment_complete('refund-fixture'); });
    cc_inventory_run_refresh($refunded->get_id()); cc_inventory_run_refresh($refunded->get_id());
    $refund = wc_create_refund(array('order_id' => $refunded->get_id(), 'amount' => '1.00', 'refund_payment' => false, 'restock_items' => true, 'line_items' => array($refunded_item => array('qty' => 1, 'refund_total' => '1.00', 'refund_tax' => array()))));
    cc_inventory_expect(!is_wp_error($refund) && (float) $refund->get_amount() === 1.0 && cc_inventory_stock($refunded_product) === 4.0, 'Late amount refund succeeds while Square-linked restock remains in Square’s return workflow.');
    $before = count($inventory_remote['requests']); cc_inventory_run_refresh($refunded->get_id());
    cc_inventory_expect(cc_inventory_stock($refunded_product) === 4.0 && count($inventory_remote['requests']) === $before, 'Refund review performs no scheduled provider refresh.');
    cc_inventory_expect(wc_get_order($refunded->get_id())->get_meta('_cart_cat_square_inventory_refresh')['state'] === 'review', 'Refund follow-up leaves an explicit inventory review state.');

    $mixed_items = $mixed->get_items(); $mixed_custom_item = 0;
    foreach ($mixed_items as $id => $item) { if ($item->get_product_id() === $custom_id) { $mixed_custom_item = $id; } }
    $mixed_linked_item = array_key_first($mixed->get_meta('_cart_cat_square_inventory_refresh')['inventory']);
    $mixed_refund = wc_create_refund(array('order_id' => $mixed->get_id(), 'amount' => '2.00', 'refund_payment' => false, 'restock_items' => true, 'line_items' => array($mixed_linked_item => array('qty' => 1, 'refund_total' => '1.00', 'refund_tax' => array()), $mixed_custom_item => array('qty' => 1, 'refund_total' => '1.00', 'refund_tax' => array()))));
    cc_inventory_expect(!is_wp_error($mixed_refund) && cc_inventory_stock($linked_id) === 4.0 && cc_inventory_stock($custom_id) === 5.0, 'Mixed refunds leave catalog restock to Square while unlinked items retain native Woo restock.');

    list($drift, $snapshot) = cc_inventory_order();
    Square_Inventory::complete($drift, $snapshot, static function() use ($drift) { $drift->payment_complete('scope-fixture'); });
    $inventory_config['location_id'] = 'changed-location'; $before = count($inventory_remote['requests']);
    for ($attempt = 0; $attempt < 6; $attempt++) { cc_inventory_run_refresh($drift->get_id()); }
    cc_inventory_expect(count($inventory_remote['requests']) === $before, 'Connection drift never reads another account/location inventory.');
    cc_inventory_expect(wc_get_order($drift->get_id())->get_meta('_cart_cat_square_inventory_refresh')['state'] === 'review' && !wp_next_scheduled('cart_cat_refresh_square_inventory', array($drift->get_id())), 'Retry budget ends with inventory review and no runaway schedule.');
    $inventory_config['location_id'] = 'loc-test';

    $row = array('catalog_object_id' => 'page-fixture', 'location_id' => 'loc-test', 'state' => 'IN_STOCK', 'quantity' => '4');
    $inventory_remote['pages'] = array(array('counts' => array($row), 'cursor' => 'page2'), array('counts' => array()));
    $before = count($inventory_remote['requests']);
    cc_inventory_expect(Square_Inventory::refresh(array('page-fixture', 'page-fixture')) === true && count($inventory_remote['requests']) === $before + 2, 'Inventory read deduplicates IDs and completes cursor pagination.');
    $applies = count($GLOBALS['cart_cat_inventory_fixture']['handled']);
    $inventory_remote['pages'] = array(array('counts' => array()));
    cc_inventory_expect(is_wp_error(Square_Inventory::refresh(array('page-fixture'))) && count($GLOBALS['cart_cat_inventory_fixture']['handled']) === $applies, 'Empty counts never report a successful catalog refresh.');
    $inventory_remote['pages'] = array(array('counts' => array($row), 'cursor' => 'repeat'), array('counts' => array($row), 'cursor' => 'repeat'));
    cc_inventory_expect(is_wp_error(Square_Inventory::refresh(array('page-fixture'))) && count($GLOBALS['cart_cat_inventory_fixture']['handled']) === $applies, 'Repeated cursors never apply a partial inventory snapshot.');
    $inventory_remote['pages'] = array(array('counts' => array(array('catalog_object_id' => 'wrong'))));
    cc_inventory_expect(is_wp_error(Square_Inventory::refresh(array('page-fixture'))) && count($GLOBALS['cart_cat_inventory_fixture']['handled']) === $applies, 'Malformed or foreign counts never reach the stock writer.');
    cc_inventory_expect(!array_filter($inventory_remote['requests'], static function($call) { return $call[0] !== 'GET'; }), 'Every provider inventory request is read-only GET.');
    echo 'Square inventory runtime: ' . $inventory_assertions . " assertions passed.\n";
} finally {
    foreach ($inventory_orders as $id) { wp_clear_scheduled_hook('cart_cat_refresh_square_inventory', array($id)); $order = wc_get_order($id); if ($order) { foreach ($order->get_refunds() as $refund) { $refund->delete(true); } $order->delete(true); } }
    foreach ($inventory_products as $id) { $product = wc_get_product($id); if ($product) { $product->delete(true); } }
    remove_filter('pre_option_square-woo-sync_settings', $inventory_settings_filter);
}
