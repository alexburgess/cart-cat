<?php
namespace Cart_Cat;
defined('ABSPATH') || exit;

/** Square owns catalog-sale counts; SWS applies its existing Woo stock rules. */
final class Square_Inventory {
    private const HANDLER = '\\Pixeldev\\SquareWooSyncPro\\Jobs\\WebhookProcessJob';
    private const PRODUCT_SYNC = '\\Pixeldev\\SquareWooSyncPro\\Woo\\SyncProduct';
    private const HELPERS = '\\Pixeldev\\SquareWooSyncPro\\Common\\Helpers';
    private const LOCATIONS = '\\Pixeldev\\SquareWooSyncPro\\Inventory\\LocationStock';
    private const META = '_cart_cat_square_inventory_refresh';
    private const HOOK = 'cart_cat_refresh_square_inventory';
    private const RETRY_DELAYS = array(30, 120, 300, 900, 3600);

    public static function register(): void {
        add_action(self::HOOK, array(__CLASS__, 'refresh_order'), 10, 1);
        add_filter('woocommerce_can_restock_refunded_items', array(__CLASS__, 'can_restock_refunded_items'), 10, 3);
    }

    /** Reject stock arrangements that the native Square inbound path cannot own. */
    public static function validate_product($product) {
        $helpers = self::HELPERS;
        if (!$product instanceof \WC_Product || !is_callable(array($helpers, 'is_stock_locked'))) {
            return new \WP_Error('cart_cat_square_inventory', 'Square stock compatibility could not be verified for this product.');
        }
        if ((int) $product->get_stock_managed_by_id() !== (int) $product->get_id()) {
            return new \WP_Error('cart_cat_square_inventory', 'This variation shares its parent product’s stock. Separate variation stock is required for a Square catalog payment.');
        }
        if ($helpers::is_stock_locked($product)) {
            return new \WP_Error('cart_cat_square_inventory', 'This product ignores stock updates from Square. Enable Square stock updates before taking a catalog payment.');
        }
        return true;
    }

    /** Amount refunds leave catalog returns to Square, which then updates Woo. */
    public static function can_restock_refunded_items($allowed, $order, $refunded_items): bool {
        if (!$allowed || !Payments::owns($order)) { return (bool) $allowed; }
        $record = $order->get_meta(self::META, true);
        $inventory = is_array($record) ? (array) ($record['inventory'] ?? array()) : array();
        $linked = array();
        foreach ((array) $refunded_items as $item_id => $line) {
            if (isset($inventory[$item_id]) && !empty($line['qty'])) { $linked[$item_id] = $line; }
        }
        if (!$linked) { return true; }
        if (empty($record['restock_notice'])) {
            $record['restock_notice'] = true;
            $record['state'] = 'review';
            $order->update_meta_data(self::META, $record);
            $order->save_meta_data();
            $order->add_order_note('The Square payment refund is separate from returning catalog stock. Cart Cat did not restock Square-linked products only in WooCommerce. Restock those returned products in Square so SquareWooSync can update WooCommerce.');
        }
        wp_clear_scheduled_hook(self::HOOK, array($order->get_id()));
        // Preserve core restocking for custom/unlinked lines in a mixed refund.
        $native = array_diff_key((array) $refunded_items, $linked);
        if ($native) { wc_restock_refunded_items($order, $native); }
        return false;
    }

    /**
     * Record Square's completed catalog sale without subtracting it again in Woo.
     * Native Woo reduction still runs for any custom/unlinked lines in the order.
     * Item markers retain the quantities Woo uses for later restock bookkeeping.
     */
    public static function complete($order, array $snapshot, callable $complete) {
        $inventory = (array) ($snapshot['inventory'] ?? array());
        if (!$inventory) { return $complete(); }

        $fresh = wc_get_order($order->get_id());
        if (!$fresh) { return new \WP_Error('cart_cat_square_inventory', 'The paid order could not be loaded for stock bookkeeping.'); }
        $fresh->read_meta_data(true);
        $items = $fresh->get_items('line_item');
        $catalog_ids = array();
        foreach ($inventory as $item_id => $line) {
            $item = $items[(int) $item_id] ?? null;
            if (!$item || !is_array($line) || empty($line['catalog_id']) || !is_numeric($line['quantity'] ?? null) || (float) $line['quantity'] <= 0 || (float) $item->get_quantity() !== (float) $line['quantity'] || (int) ($item->get_variation_id() ?: $item->get_product_id()) !== (int) ($line['product_id'] ?? 0)) {
                return new \WP_Error('cart_cat_square_inventory', 'The paid order product quantities need stock review.');
            }
            $catalog_ids[] = (string) $line['catalog_id'];
        }

        $record = $fresh->get_meta(self::META, true);
        if (!is_array($record) || !$record) {
            $scope = (array) $fresh->get_meta('_cart_cat_square_scope', true);
            $record = array('state' => 'pending', 'catalog_ids' => array_values(array_unique($catalog_ids)), 'inventory' => $inventory, 'scope' => $scope, 'attempts' => 0, 'followup' => false);
            $fresh->update_meta_data(self::META, $record);
            $fresh->save_meta_data();
        }
        // Do not leave a stale caller meta cache that could overwrite this record.
        $order->read_meta_data(true);
        if (($record['state'] ?? '') !== 'complete') { self::schedule($fresh, 10); }

        $scoped_guards = array();
        $sync = self::PRODUCT_SYNC;
        foreach ($inventory as $item_id => $line) {
            $item = $items[(int) $item_id];
            if (!$item->get_meta('_reduced_stock', true)) {
                $item->update_meta_data('_reduced_stock', $line['quantity']);
                $item->save();
            }
            // payment_complete may use the caller's already-loaded item objects.
            $caller_item = $order->get_item((int) $item_id);
            if ($caller_item) { $caller_item->read_meta_data(true); }
            if (is_callable(array($sync, 'guard_from_square')) && is_callable(array($sync, 'unguard_from_square'))) {
                $product_id = (int) $line['product_id'];
                $already_guarded = is_callable(array($sync, 'is_from_square_this_request')) && $sync::is_from_square_this_request($product_id);
                if (!$already_guarded) { $sync::guard_from_square($product_id); $scoped_guards[] = $product_id; }
                if ($item->get_variation_id()) {
                    // SWS retains the parent guard until Woo's deferred shutdown sync.
                    $sync::guard_from_square((int) $item->get_product_id());
                }
            }
        }
        try {
            // Best effort before payment_complete releases the native stock hold.
            // The durable scheduled refresh remains armed if this read cannot apply.
            if (self::same_scope((array) ($record['scope'] ?? array()))) {
                try { self::refresh($catalog_ids, $inventory); } catch (\Throwable $error) { /* Scheduled recovery is already durable. */ }
            }
            return $complete();
        }
        finally { foreach (array_unique($scoped_guards) as $product_id) { $sync::unguard_from_square($product_id); } }
    }

    /** Durable fresh-count recovery; repeated runs do not subtract stock. */
    public static function refresh_order(int $order_id): void {
        $lease = Payment_Lock::acquire($order_id);
        if (is_wp_error($lease)) {
            if (!wp_next_scheduled(self::HOOK, array($order_id))) { wp_schedule_single_event(time() + 30, self::HOOK, array($order_id)); }
            return;
        }
        try {
            $order = wc_get_order($order_id);
            if (!$order || !Payments::owns($order)) { return; }
            $order->read_meta_data(true);
            $record = $order->get_meta(self::META, true);
            if (!is_array($record) || in_array($record['state'] ?? '', array('complete', 'review'), true) || empty($record['catalog_ids'])) { return; }
            // A crash can leave the durable refresh ahead of payment_complete.
            // Give payment reconciliation time to finish before interpreting an
            // unset stock-reduced flag as a later cancellation/restock.
            $refunds = $order->get_refunds();
            $waiting_for_completion = !$order->is_paid() && !$refunds && !$order->has_status(array('cancelled', 'refunded', 'failed'));
            $restocked = !$order->get_data_store()->get_stock_reduced($order_id);
            foreach ((array) ($record['inventory'] ?? array()) as $item_id => $line) {
                $item = $order->get_item((int) $item_id);
                if (!$item || (float) $item->get_meta('_reduced_stock', true) !== (float) ($line['quantity'] ?? 0)) { $restocked = true; }
            }
            if (!$waiting_for_completion && ($refunds || $restocked)) {
                $record['state'] = 'review';
                $order->update_meta_data(self::META, $record);
                $order->save_meta_data();
                $order->add_order_note('Cart Cat stopped its scheduled Square stock refresh because this order was refunded or restocked. The amount-only Square refund does not return catalog items to inventory. Reconcile returned stock in Square before refreshing these products.');
                return;
            }
            try {
                $result = $waiting_for_completion ? new \WP_Error('cart_cat_square_inventory_pending', 'The captured payment is still completing in WooCommerce.') : (self::same_scope((array) ($record['scope'] ?? array())) ? self::refresh($record['catalog_ids'], (array) ($record['inventory'] ?? array())) : new \WP_Error('cart_cat_square_inventory_scope', 'The Square connection changed before stock could be refreshed.'));
            } catch (\Throwable $error) { $result = new \WP_Error('cart_cat_square_inventory', 'Square stock refresh did not finish.'); }
            if (!is_wp_error($result)) {
                $record['state'] = !empty($record['followup']) ? 'complete' : 'pending';
                $record['followup'] = true;
                $record['attempts'] = 0;
                $record['refreshed_at'] = time();
                $order->update_meta_data(self::META, $record);
                $order->save_meta_data();
                // A second fresh read lets queued sale webhooks settle first.
                if ($record['state'] !== 'complete') { self::schedule($order, 120); }
                return;
            }
            $attempts = (int) ($record['attempts'] ?? 0) + 1;
            $record['attempts'] = $attempts;
            $record['state'] = $attempts > count(self::RETRY_DELAYS) ? 'review' : 'pending';
            $order->update_meta_data(self::META, $record);
            $order->save_meta_data();
            if ($record['state'] === 'review') {
                $order->add_order_note('Cart Cat could not refresh this sale’s Square stock in WooCommerce after repeated attempts. The payment is recorded. Review these products in SquareWooSync Stock Checker.');
            } else { self::schedule($order, self::RETRY_DELAYS[$attempts - 1]); }
        } finally { Payment_Lock::release($lease); }
    }

    private static function schedule($order, int $delay): void {
        $args = array($order->get_id());
        if (wp_next_scheduled(self::HOOK, $args)) { return; }
        $scheduled = wp_schedule_single_event(time() + $delay, self::HOOK, $args, true);
        if (is_wp_error($scheduled) || !$scheduled) {
            $record = (array) $order->get_meta(self::META, true);
            if (empty($record['schedule_warning'])) {
                $record['schedule_warning'] = true;
                $order->update_meta_data(self::META, $record);
                $order->save_meta_data();
                $order->add_order_note('Cart Cat could not schedule a Square stock refresh. The payment is recorded. Review these products in SquareWooSync Stock Checker.');
            }
        }
    }

    private static function same_scope(array $scope): bool {
        $active = Square::scope();
        foreach (array('application_id', 'location_id', 'environment', 'currency') as $key) {
            if (empty($scope[$key]) || $scope[$key] !== ($active[$key] ?? '')) { return false; }
        }
        return true;
    }

    /** Required before a new catalog-linked payment can be submitted. */
    public static function ready() {
        $settings = get_option('square-woo-sync_settings', array());
        if (empty($settings['squareAuto']['isActive']) || empty($settings['squareAuto']['stock']) || !class_exists(self::HANDLER) || !method_exists(self::HANDLER, 'handle')) {
            return new \WP_Error('cart_cat_square_inventory', 'Square product payments require Square-to-WooCommerce stock synchronization. Check the SquareWooSync stock settings before taking payment.');
        }
        foreach (array('is_stock_locked', 'convert_quantity', 'apply_stock_buffer', 'combines_location_stock') as $method) {
            if (!is_callable(array(self::HELPERS, $method))) { return new \WP_Error('cart_cat_square_inventory', 'SquareWooSync stock compatibility could not be verified.'); }
        }
        if (!is_callable(array(self::LOCATIONS, 'absent_for'))) { return new \WP_Error('cart_cat_square_inventory', 'SquareWooSync location stock compatibility could not be verified.'); }
        return true;
    }

    /**
     * Refresh only the variations involved in this sale, without provider writes.
     * SWS's own AddToCartStockRefreshJob uses this same synthetic-event interface.
     * Keep its buffers, per-product location rules, locks and loop guards intact.
     * Production supplies the frozen item mapping so success also confirms that
     * the native SWS handler actually applied each expected Woo stock count.
     */
    public static function refresh(array $catalog_ids, array $inventory = array()) {
        $ready = self::ready();
        if (is_wp_error($ready)) { return $ready; }
        $catalog_ids = array_values(array_unique($catalog_ids));
        foreach ($catalog_ids as $catalog_id) {
            if (!is_string($catalog_id) || !preg_match('/^[A-Za-z0-9_-]+$/D', $catalog_id)) {
                return new \WP_Error('cart_cat_square_inventory', 'The Square inventory reference is invalid.');
            }
        }
        foreach ($inventory as $line) {
            $product = wc_get_product((int) ($line['product_id'] ?? 0));
            $valid = self::validate_product($product);
            if (is_wp_error($valid)) { return $valid; }
        }
        $counts = array();
        foreach ($catalog_ids as $catalog_id) {
            $cursor = '';
            $seen = array();
            $pages = 0;
            do {
                $path = '/v2/inventory/' . rawurlencode($catalog_id);
                if ($cursor !== '') { $path .= '?cursor=' . rawurlencode($cursor); }
                $response = Square::request('GET', $path);
                if (is_wp_error($response)) { return $response; }
                if (!is_array($response['counts'] ?? null)) {
                    return new \WP_Error('cart_cat_square_inventory', 'Square did not return a complete inventory count.');
                }
                foreach ($response['counts'] as $count) {
                    if (!is_array($count) || ($count['catalog_object_id'] ?? '') !== $catalog_id || !is_string($count['location_id'] ?? null) || $count['location_id'] === '' || !is_string($count['state'] ?? null) || !is_numeric($count['quantity'] ?? null)) {
                        return new \WP_Error('cart_cat_square_inventory', 'Square returned an inventory count that could not be verified.');
                    }
                    $counts[] = $count;
                }
                $cursor = (string) ($response['cursor'] ?? '');
                if ($cursor !== '' && (isset($seen[$cursor]) || ++$pages >= 100)) {
                    return new \WP_Error('cart_cat_square_inventory', 'Square inventory pagination did not finish.');
                }
                $seen[$cursor] = true;
            } while ($cursor !== '');
        }
        $stock_counts = array();
        foreach ($counts as $count) {
            if ($count['state'] === 'IN_STOCK') { $stock_counts[$count['catalog_object_id']][$count['location_id']] = $count['quantity']; }
        }
        foreach ($catalog_ids as $catalog_id) {
            if (empty($stock_counts[$catalog_id])) { return new \WP_Error('cart_cat_square_inventory', 'Square did not return in-stock counts for a sold catalog product.'); }
        }
        try {
            $handler = self::HANDLER;
            (new $handler('inventory.count.updated', array('data' => array('object' => array('inventory_counts' => $counts)))))->handle();
        } catch (\Throwable $error) {
            return new \WP_Error('cart_cat_square_inventory', 'Square stock could not be refreshed in WooCommerce.');
        }
        $helpers = self::HELPERS;
        $locations = self::LOCATIONS;
        $settings = get_option('square-woo-sync_settings', array());
        foreach ($inventory as $line) {
            $product = wc_get_product((int) $line['product_id']);
            if (!$product) { return new \WP_Error('cart_cat_square_inventory', 'A sold product is unavailable for stock verification.'); }
            $map = $stock_counts[$line['catalog_id']] ?? array();
            foreach ($locations::absent_for((int) $product->get_id()) as $absent) { unset($map[$absent]); }
            $combine = $helpers::combines_location_stock($product, array('source' => 'webhook', 'catalog_object_id' => (string) $line['catalog_id']));
            $quantity = null;
            if ($combine && $map) {
                $quantity = 0;
                foreach ($map as $count) { $quantity += $helpers::convert_quantity($count); }
            } else {
                foreach ($map as $location => $count) {
                    if (!empty($settings['wooAuto']['allLocationsStock']) || (string) $location === (string) ($settings['location'] ?? '')) { $quantity = $helpers::convert_quantity($count); }
                }
            }
            $expected = $quantity === null ? null : $helpers::apply_stock_buffer($quantity, $product);
            $actual = $product->get_stock_quantity();
            if ($expected === null || $actual === null || abs((float) $expected - (float) $actual) > 0.000001) {
                return new \WP_Error('cart_cat_square_inventory', 'Square stock has not yet been applied to every sold WooCommerce product.');
            }
        }
        return true;
    }
}
