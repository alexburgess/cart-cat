<?php
namespace Cart_Cat;
defined('ABSPATH') || exit;

/** Builds a frozen, catalog-linked Square sale from the saved Woo order. */
final class Square_Order {
    /** CalculateOrder is a preview: no Square order, payment or inventory change is created. */
    public static function prepare($order, array $context) {
        try {
            $snapshot = self::build($order, $context);
        } catch (\RuntimeException $error) {
            return new \WP_Error('cart_cat_square_itemization', $error->getMessage());
        }
        $response = Square::request('POST', '/v2/orders/calculate', array('order' => $snapshot['order']));
        if (is_wp_error($response)) { return new \WP_Error('cart_cat_square_preview', 'Square could not verify the itemized order. Please retry; no charge was sent.'); }
        $verified = self::verify($response['order'] ?? array(), $snapshot);
        return is_wp_error($verified) ? $verified : $snapshot;
    }

    private static function fail(string $message): void { throw new \RuntimeException($message . ' No charge was sent.'); }
    private static function money(int $amount): array { return array('amount' => $amount, 'currency' => 'USD'); }
    private static function cents($amount): int {
        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0 || (float) $amount > 1000000000) { self::fail('The order contains an unsupported amount.'); }
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }
    private static function text($value, int $length = 255): string {
        return mb_substr(trim(html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, 'UTF-8')), 0, $length);
    }

    /** Largest-remainder allocation preserves cents, with stable ties in Woo item order. */
    private static function allocate(int $total, array $weights): array {
        $sum = array_sum($weights); $result = array_fill_keys(array_keys($weights), 0);
        if ($total === 0) { return $result; }
        if ($sum <= 0) { self::fail('The order has a tax or discount without a corresponding amount.'); }
        $remainders = array(); $assigned = 0;
        foreach ($weights as $key => $weight) {
            $share = $total * ($weight / $sum);
            $result[$key] = (int) floor($share); $assigned += $result[$key]; $remainders[$key] = $share - $result[$key];
        }
        arsort($remainders, SORT_NUMERIC);
        foreach (array_keys($remainders) as $key) { if ($assigned >= $total) { break; } ++$result[$key]; ++$assigned; }
        if ($assigned !== $total) { self::fail('The order amounts could not be allocated exactly.'); }
        return $result;
    }

    private static function catalog(string $id, array &$cache): array {
        if ($id === '' || strlen($id) > 192 || !preg_match('/^[A-Za-z0-9_-]+$/D', $id)) { self::fail('A product needs a valid SWS Pro Square link.'); }
        if (!isset($cache[$id])) {
            $response = Square::request('GET', '/v2/catalog/object/' . rawurlencode($id) . '?include_related_objects=true');
            if (is_wp_error($response) || ($response['object']['id'] ?? '') !== $id) { self::fail('A linked Square product could not be retrieved. Check its SWS Pro link and retry.'); }
            $cache[$id] = $response['object'];
            foreach ((array) ($response['related_objects'] ?? array()) as $related) {
                if (!empty($related['id']) && !isset($cache[$related['id']])) { $cache[$related['id']] = $related; }
            }
        }
        $object = $cache[$id];
        if (!empty($object['is_deleted'])) { self::fail('A linked Square product has been deleted. Repair its SWS Pro link.'); }
        return $object;
    }
    private static function at_location(array $object, string $location): bool {
        return !in_array($location, (array) ($object['absent_at_location_ids'] ?? array()), true)
            && (!empty($object['present_at_all_locations'] ?? true) || in_array($location, (array) ($object['present_at_location_ids'] ?? array()), true));
    }
    private static function tracks_stock(array $variation, string $location): bool {
        $tracking = !empty($variation['item_variation_data']['track_inventory']);
        foreach ((array) ($variation['item_variation_data']['location_overrides'] ?? array()) as $override) {
            if (($override['location_id'] ?? '') === $location && array_key_exists('track_inventory', $override)) { $tracking = (bool) $override['track_inventory']; }
        }
        return $tracking;
    }
    private static function variation($product, string $location, array &$cache): array {
        $label = self::text($product->get_name());
        if ($product->is_type('variation')) {
            $variation_id = (string) $product->get_meta('square_product_id', true);
            $parent = wc_get_product($product->get_parent_id());
            $item_id = $parent ? (string) $parent->get_meta('square_product_id', true) : '';
        } elseif ($product->is_type('simple')) {
            $item_id = (string) $product->get_meta('square_product_id', true);
            $variation_id = (string) $product->get_meta('square_variation_id', true);
        } else { self::fail('The product “' . $label . '” does not have a supported Square variation.'); }
        if ($item_id === '' || ($product->is_type('variation') && $variation_id === '')) { self::fail('The product “' . $label . '” needs its SWS Pro Square link repaired.'); }
        $item = self::catalog($item_id, $cache);
        if (($item['type'] ?? '') !== 'ITEM' || !empty($item['item_data']['is_archived'])) { self::fail('The Square item for “' . $label . '” is unavailable.'); }
        if ($variation_id === '') {
            // SWS simple products can have only the parent ITEM link. Never guess among variations.
            $variations = array_values(array_filter((array) ($item['item_data']['variations'] ?? array()), static function ($entry) { return ($entry['type'] ?? '') === 'ITEM_VARIATION' && empty($entry['is_deleted']); }));
            if (count($variations) !== 1) { self::fail('The product “' . $label . '” needs an explicit SWS Pro Square variation link.'); }
            $variation_id = (string) $variations[0]['id'];
        }
        $variation = self::catalog($variation_id, $cache);
        if (($variation['type'] ?? '') !== 'ITEM_VARIATION' || ($variation['item_variation_data']['item_id'] ?? '') !== $item_id || empty($variation['version']) || ($variation['item_variation_data']['sellable'] ?? true) === false) { self::fail('The Square variation for “' . $label . '” does not match its SWS Pro product link.'); }
        // Catalog visibility is a POS setting, not an Orders API sale restriction.
        // Managed stock still needs a location that SWS includes in its stock calculation.
        if (($product->managing_stock() || self::tracks_stock($variation, $location)) && (!self::at_location($item, $location) || !self::at_location($variation, $location))) { self::fail('The Square stock location for “' . $label . '” needs review in SWS Pro.'); }
        return $variation;
    }

    private static function build($order, array $context): array {
        if ($order->get_currency() !== 'USD' || ($context['currency'] ?? '') !== 'USD' || empty($context['location_id'])) { self::fail('The Square connection does not match the order currency or location.'); }
        $square = array('location_id' => $context['location_id'], 'pricing_options' => array('auto_apply_discounts' => false, 'auto_apply_taxes' => false), 'line_items' => array());
        $expected = array('lines' => array(), 'charges' => array(), 'taxes' => array(), 'total' => self::cents($order->get_total()), 'tax' => 0, 'discount' => 0, 'service_charge' => 0);
        $components = array(); $cache = array(); $rates = array(); $tax_weights = array(); $inventory = array();
        foreach ($order->get_items('tax') as $tax) {
            $id = (string) $tax->get_rate_id();
            if (isset($rates[$id])) { self::fail('The order has duplicate tax rate records.'); }
            $rates[$id] = array('name' => self::text($tax->get_label() ?: 'Tax'), 'percent' => $tax->get_rate_percent(), 'compound' => $tax->is_compound(), 'cart' => self::cents($tax->get_tax_total()), 'shipping' => self::cents($tax->get_shipping_tax_total()));
        }
        foreach ($order->get_items(array('line_item', 'shipping', 'fee')) as $item_id => $item) {
            $kind = $item->get_type(); $net = self::cents($item->get_total());
            $uid = 'woo-' . $kind . '-' . $item_id;
            $component = array('kind' => $kind, 'net' => $net, 'parts' => array(), 'taxes' => array());
            if ($kind === 'line_item') {
                $product = $item->get_product();
                if (!$product) { self::fail('An order product is unavailable. Review its SWS Pro link.'); }
                $quantity = (float) $item->get_quantity();
                if ($quantity < 1 || $quantity > 9999 || floor($quantity) !== $quantity) { self::fail('Square itemization requires whole product quantities between 1 and 9,999.'); }
                $quantity = (int) $quantity; $subtotal = self::cents($item->get_subtotal());
                if ($subtotal < $net) { self::fail('An order product total exceeds its saved subtotal.'); }
                $variation = self::variation($product, $context['location_id'], $cache);
                $tracking = self::tracks_stock($variation, $context['location_id']);
                // SWS can enable Woo stock management when Square's sale webhook arrives.
                if ($product->managing_stock() || $tracking) {
                    $stock_validation = Square_Inventory::validate_product($product);
                    if (is_wp_error($stock_validation)) { self::fail($stock_validation->get_error_message()); }
                    if (!$tracking) { self::fail('Square inventory tracking must be enabled for “' . self::text($item->get_name()) . '” before taking this payment.'); }
                    $inventory[(string) $item_id] = array('catalog_id' => $variation['id'], 'quantity' => $quantity, 'product_id' => $product->get_id());
                }
                $unit = intdiv($subtotal, $quantity); $remainder = $subtotal % $quantity;
                // At most two prices, one cent apart, retain both exact quantity and exact gross sales.
                $parts = array();
                if ($quantity > $remainder) { $parts[] = array('quantity' => $quantity - $remainder, 'unit' => $unit); }
                if ($remainder > 0) { $parts[] = array('quantity' => $remainder, 'unit' => $unit + 1); }
                $gross = array_map(static function ($part) { return $part['quantity'] * $part['unit']; }, $parts);
                $discounts = self::allocate($subtotal - $net, $gross);
                foreach ($parts as $index => $part) {
                    $part_uid = $uid . '-' . $index; $discount = $discounts[$index];
                    $line = array('uid' => $part_uid, 'name' => self::text($item->get_name()), 'quantity' => (string) $part['quantity'], 'catalog_object_id' => $variation['id'], 'catalog_version' => (int) $variation['version'], 'base_price_money' => self::money($part['unit']), 'metadata' => array('woo_item_id' => (string) $item_id, 'woo_product_id' => (string) $product->get_id()));
                    if ($discount) {
                        $discount_uid = 'discount-' . $part_uid;
                        $square['discounts'][] = array('uid' => $discount_uid, 'name' => 'WooCommerce discount', 'type' => 'FIXED_AMOUNT', 'scope' => 'LINE_ITEM', 'amount_money' => self::money($discount));
                        $line['applied_discounts'] = array(array('uid' => 'apply-' . $discount_uid, 'discount_uid' => $discount_uid));
                    }
                    $square['line_items'][] = $line;
                    $component['parts'][$part_uid] = array('index' => count($square['line_items']) - 1, 'net' => $gross[$index] - $discount);
                    $expected['lines'][$part_uid] = array('catalog' => $variation['id'], 'quantity' => $part['quantity'], 'gross' => $gross[$index], 'discount' => $discount, 'tax' => 0, 'total' => $gross[$index] - $discount);
                    $expected['discount'] += $discount;
                }
            } else {
                $name = $item->get_name() ?: ($kind === 'shipping' ? 'Postage' : 'Fee');
                $square['service_charges'][] = array('uid' => $uid, 'name' => self::text($name), 'amount_money' => self::money($net), 'scope' => 'ORDER', 'treatment_type' => 'LINE_ITEM_TREATMENT', 'calculation_phase' => 'SUBTOTAL_PHASE', 'taxable' => false, 'metadata' => array('woo_item_id' => (string) $item_id, 'woo_item_type' => $kind));
                $component['parts'][$uid] = array('index' => count($square['service_charges']) - 1, 'net' => $net);
                $expected['charges'][$uid] = array('amount' => $net, 'tax' => 0, 'total' => $net);
                $expected['service_charge'] += $net;
            }
            $tax_data = $item->get_taxes();
            foreach ((array) ($tax_data['total'] ?? array()) as $rate_id => $amount) {
                self::cents($amount);
                if ((float) $amount <= 0) { continue; }
                if (!isset($rates[(string) $rate_id])) { self::fail('An order line has tax without a saved WooCommerce tax rate.'); }
                $group = $kind === 'shipping' ? 'shipping' : 'cart';
                $tax_weights[(string) $rate_id][$group][$uid] = (float) $amount;
            }
            $components[$uid] = $component;
        }
        if (!$square['line_items']) { self::fail('The order has no linked products.'); }
        foreach ($rates as $rate_id => $rate) {
            foreach (array('cart', 'shipping') as $group) {
                $weights = $tax_weights[$rate_id][$group] ?? array();
                // Woo can store sub-cent line tax and round only the rate total.
                if (abs(self::cents(array_sum($weights)) - $rate[$group]) > count($weights)) { self::fail('The saved line taxes do not match the order tax totals.'); }
                $amounts = self::allocate($rate[$group], $weights);
                foreach ($amounts as $uid => $amount) { $components[$uid]['taxes'][$rate_id] = $amount; }
            }
        }
        foreach ($components as $uid => $component) {
            $part_weights = array_map(static function ($part) { return $part['net']; }, $component['parts']);
            foreach ($component['taxes'] as $rate_id => $amount) {
                $rate = $rates[$rate_id]; $parts = self::allocate($amount, $part_weights);
                foreach ($parts as $part_uid => $part_tax) {
                    if (!$part_tax) { continue; }
                    $part = $component['parts'][$part_uid]; $tax_uid = 'tax-' . $rate_id . '-' . $part_uid;
                    $percent = self::percentage($part_tax, $part['net'], $rate['percent'], $rate['compound']);
                    $square['taxes'][] = array('uid' => $tax_uid, 'name' => $rate['name'], 'type' => 'ADDITIVE', 'scope' => 'LINE_ITEM', 'percentage' => $percent, 'metadata' => array('woo_tax_rate_id' => (string) $rate_id, 'woo_tax_rate_percent' => (string) $rate['percent'], 'woo_tax_amount' => (string) $part_tax, 'woo_tax_compound' => $rate['compound'] ? 'true' : 'false'));
                    $applied = array('uid' => 'apply-' . $tax_uid, 'tax_uid' => $tax_uid);
                    $expected['taxes'][$tax_uid] = $part_tax; $expected['tax'] += $part_tax;
                    if ($component['kind'] === 'line_item') {
                        $square['line_items'][$part['index']]['applied_taxes'][] = $applied;
                        $expected['lines'][$part_uid]['tax'] += $part_tax; $expected['lines'][$part_uid]['total'] += $part_tax;
                    } else {
                        $square['service_charges'][$part['index']]['taxable'] = true;
                        $square['service_charges'][$part['index']]['applied_taxes'][] = $applied;
                        $expected['charges'][$part_uid]['tax'] += $part_tax; $expected['charges'][$part_uid]['total'] += $part_tax;
                    }
                }
            }
        }
        $computed = array_sum(array_column($expected['lines'], 'total')) + array_sum(array_column($expected['charges'], 'total'));
        if ($computed !== $expected['total'] || $expected['tax'] !== self::cents($order->get_total_tax()) || $expected['discount'] !== self::cents($order->get_discount_total())) { self::fail('The saved order breakdown does not match its total. Review the order in WooCommerce.'); }
        return array('version' => 1, 'order' => $square, 'expected' => $expected, 'inventory' => $inventory);
    }

    /** Retain nominal rates when they round correctly; otherwise reproduce the saved collected cents. */
    private static function percentage(int $tax, int $base, $nominal, bool $compound): string {
        if ($base <= 0) { self::fail('An order line has tax without a taxable amount.'); }
        if (!$compound && is_numeric($nominal) && (float) $nominal >= 0) {
            $value = rtrim(rtrim(number_format((float) $nominal, 8, '.', ''), '0'), '.');
            if (strlen($value) <= 10 && (int) round($base * (float) $value / 100, 0, PHP_ROUND_HALF_EVEN) === $tax) { return $value; }
        }
        $rate = 100 * $tax / $base;
        $decimals = max(0, 9 - strlen((string) (int) $rate));
        $value = number_format($rate, $decimals, '.', '');
        if (strpos($value, '.') !== false) { $value = rtrim(rtrim($value, '0'), '.'); }
        if ($value === '' || strlen($value) > 10 || (int) round($base * (float) $value / 100, 0, PHP_ROUND_HALF_EVEN) !== $tax) { self::fail('The saved tax cannot be represented exactly in Square.'); }
        return $value;
    }

    /** Verify both preview and actual CreateOrder, including equal-total component swaps. */
    public static function verify(array $square, array $snapshot) {
        $expected = $snapshot['expected'];
        $valid = ($square['location_id'] ?? '') === $snapshot['order']['location_id'];
        foreach (array('total_money' => 'total', 'total_tax_money' => 'tax', 'total_discount_money' => 'discount', 'total_service_charge_money' => 'service_charge') as $field => $key) { $valid = self::matches_money($square[$field] ?? array(), $expected[$key]) && $valid; }
        foreach (array('line_items' => 'lines', 'service_charges' => 'charges', 'taxes' => 'taxes') as $field => $key) {
            $rows = $square[$field] ?? array(); $seen = array();
            if (!is_array($rows) || count($rows) !== count($expected[$key])) { $valid = false; continue; }
            foreach ($rows as $row) {
                if (!is_array($row)) { $valid = false; continue; }
                $uid = $row['uid'] ?? '';
                if (!isset($expected[$key][$uid]) || isset($seen[$uid])) { $valid = false; continue; }
                $seen[$uid] = true; $target = $expected[$key][$uid];
                if ($key === 'taxes') { $valid = self::matches_money($row['applied_money'] ?? array(), $target) && $valid; continue; }
                foreach (array('total_money' => 'total', 'total_tax_money' => 'tax') as $money => $part) { $valid = self::matches_money($row[$money] ?? array(), $target[$part]) && $valid; }
                if ($key === 'lines') {
                    $valid = ($row['catalog_object_id'] ?? '') === $target['catalog'] && is_numeric($row['quantity'] ?? null) && (float) $row['quantity'] === (float) $target['quantity'] && $valid;
                    $valid = self::matches_money($row['gross_sales_money'] ?? array(), $target['gross']) && self::matches_money($row['total_discount_money'] ?? array(), $target['discount']) && $valid;
                } else { $valid = self::matches_money($row['applied_money'] ?? array(), $target['amount']) && $valid; }
            }
        }
        return $valid ? true : new \WP_Error('cart_cat_square_itemization', 'Square’s products, tax, postage or discounts do not match the saved WooCommerce order. Review the order before retrying; no charge was sent.');
    }
    private static function matches_money($money, int $amount): bool {
        return is_array($money) && isset($money['amount']) && is_int($money['amount']) && $money['amount'] === $amount && ($money['currency'] ?? '') === 'USD';
    }
}
