<?php
/** Real Woo order fixtures with mocked read-only Square catalog/calculation calls. */
if (!defined('WP_CLI') || !WP_CLI || !function_exists('wc_create_order') || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), array('localhost', '127.0.0.1'), true)) { throw new RuntimeException('Disposable localhost WooCommerce fixture only.'); }
$source = rtrim(getenv('CART_CAT_SOURCE') ?: dirname(__DIR__), '/');
require_once $source . '/cart-cat/cart-cat.php';
require_once $source . '/cart-cat/includes/class-square-order.php';
require_once __DIR__ . '/square-test-provider.php';
require_once __DIR__ . '/square-inventory-fixture.php';

use Cart_Cat\Square_Order;

$assertions = 0; $created_orders = array(); $created_products = array(); $created_rates = array();
$catalog = array(); $requests = array(); $calculate_corruption = '';
$context = array('application_id' => 'test-app', 'location_id' => 'loc-test', 'environment' => 'sandbox', 'merchant_id' => 'merchant-test', 'currency' => 'USD');
$old_rounding = get_option('woocommerce_tax_round_at_subtotal', null);
$sws_settings = static function () { return array('location' => 'loc-test', 'squareAuto' => array('isActive' => true, 'stock' => true), 'wooAuto' => array('isActive' => false, 'stock' => false)); };
add_filter('pre_option_square-woo-sync_settings', $sws_settings);
$assert = static function ($test, $message) use (&$assertions) { ++$assertions; if (!$test) { throw new RuntimeException($message); } };
$ok = static function ($result) { if (is_wp_error($result)) { throw new RuntimeException($result->get_error_code() . ': ' . $result->get_error_message()); } return $result; };
$transport = static function ($original, $method, $path, $body) use (&$catalog, &$requests, &$calculate_corruption) {
    $requests[] = array($method, $path, $body);
    if ($method === 'GET' && strpos($path, '/v2/catalog/object/') === 0) {
        $id = rawurldecode(explode('?', substr($path, strlen('/v2/catalog/object/')))[0]);
        if (!isset($catalog[$id])) { return new WP_Error('not_found', 'Synthetic catalog object is absent.'); }
        $object = $catalog[$id]; $related = array();
        if (($object['type'] ?? '') === 'ITEM_VARIATION') {
            $parent_id = $object['item_variation_data']['item_id'] ?? '';
            if (isset($catalog[$parent_id])) { $related[] = $catalog[$parent_id]; }
        } elseif (($object['type'] ?? '') === 'ITEM') {
            foreach ($object['item_data']['variations'] ?? array() as $index => $variation) {
                $fresh = $catalog[$variation['id']] ?? $variation;
                $object['item_data']['variations'][$index] = $fresh; $related[] = $fresh;
            }
        }
        return array('object' => $object, 'related_objects' => $related);
    }
    if ($method === 'POST' && $path === '/v2/orders/calculate') {
        $calculated = cc_square_calculate($body['order']);
        if ($calculate_corruption === 'total') { ++$calculated['total_money']['amount']; }
        if ($calculate_corruption === 'components') { ++$calculated['total_tax_money']['amount']; --$calculated['total_service_charge_money']['amount']; }
        return array('order' => $calculated);
    }
    throw new RuntimeException('Itemization must not mutate the provider: ' . $method . ' ' . $path);
};
add_filter('cart_cat_square_transport', $transport, 10, 4);

$product = static function (string $name, bool $mapped = true) use (&$created_products, &$catalog) {
    $p = new WC_Product_Simple(); $p->set_name($name); $p->set_regular_price('10.00'); $p->set_status('publish'); $p->set_stock_status('instock'); $p->save(); $created_products[] = $p->get_id();
    if ($mapped) {
        $item_id = 'ITEM-' . $p->get_id(); $variation_id = 'VAR-' . $p->get_id();
        $variation = array('id' => $variation_id, 'type' => 'ITEM_VARIATION', 'version' => 1234567890, 'is_deleted' => false, 'present_at_all_locations' => true, 'item_variation_data' => array('item_id' => $item_id, 'name' => 'Regular', 'pricing_type' => 'FIXED_PRICING', 'price_money' => array('amount' => 9900, 'currency' => 'USD')));
        $catalog[$variation_id] = $variation;
        $catalog[$item_id] = array('id' => $item_id, 'type' => 'ITEM', 'version' => 1234567890, 'is_deleted' => false, 'present_at_all_locations' => true, 'item_data' => array('name' => $name, 'variations' => array($variation)));
        $p->update_meta_data('square_product_id', $item_id); $p->update_meta_data('square_variation_id', $variation_id); $p->save();
    }
    return $p;
};
$order = static function () use (&$created_orders) {
    $o = wc_create_order(array('created_via' => 'cart_cat')); $created_orders[] = $o->get_id(); $o->set_currency('USD'); return $o;
};
$line = static function ($o, $p, $qty, string $subtotal, string $total, array $taxes = array(), ?array $subtotal_taxes = null) {
    $item = new WC_Order_Item_Product(); $item->set_product($p); $item->set_quantity($qty); $item->set_subtotal($subtotal); $item->set_total($total); $item->set_taxes(array('total' => $taxes, 'subtotal' => $subtotal_taxes ?? $taxes)); $o->add_item($item); return $item;
};
$charge = static function ($o, string $type, string $name, string $amount, array $taxes = array()) {
    $item = $type === 'shipping' ? new WC_Order_Item_Shipping() : new WC_Order_Item_Fee();
    if ($type === 'shipping') { $item->set_method_title($name); $item->set_method_id('flat_rate'); }
    else { $item->set_name($name); $item->set_tax_status($taxes ? 'taxable' : 'none'); }
    $item->set_total($amount); $item->set_taxes(array('total' => $taxes)); $o->add_item($item); return $item;
};
$finish = static function ($o) { $o->update_taxes(); $o->calculate_totals(false); return $o; };
$prepare = static function ($o) use ($context, $ok) { return $ok(Square_Order::prepare($o, $context)); };

try {
    update_option('woocommerce_tax_round_at_subtotal', 'no');
    foreach (array('six' => '6.0000', 'five' => '5.0000', 'ten' => '10.0000') as $key => $rate) {
        $created_rates[$key] = WC_Tax::_insert_tax_rate(array('tax_rate_country' => 'US', 'tax_rate_state' => 'VA', 'tax_rate' => $rate, 'tax_rate_name' => 'Fixture ' . $key, 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => ''));
    }
    $six = $created_rates['six']; $five = $created_rates['five']; $ten = $created_rates['ten'];
    $wine = $product('Itemized wine'); $exempt = $product('Exempt product'); $exempt->set_tax_status('none'); $exempt->save();

    $o = $order(); $line($o, $wine, 2, '24.00', '20.00', array($six => '1.20'), array($six => '1.44')); $line($o, $exempt, 1, '7.50', '7.50');
    $charge($o, 'shipping', 'Postage — Ground', '5.00', array($six => '0.30')); $charge($o, 'fee', 'Gift wrapping', '2.00', array($six => '0.12')); $finish($o);
    $assert($o->get_total() === '36.12', 'Synthetic Woo order has the independently specified grand total.');
    $snapshot = $prepare($o); $payload = $snapshot['order']; $calculated = cc_square_calculate($payload);
    $assert(($snapshot['version'] ?? 0) === 1, 'The prepared itemization uses a versioned snapshot.');
    $assert(count($payload['line_items']) === 2, 'Only the two products are product line items.');
    $wine_lines = array_values(array_filter($payload['line_items'], static function ($item) use ($wine) { return ($item['catalog_object_id'] ?? '') === $wine->get_meta('square_variation_id'); }));
    $assert(count($wine_lines) === 1 && $wine_lines[0]['quantity'] === '2', 'Square records the actual catalog variation and quantity.');
    $assert($wine_lines[0]['base_price_money']['amount'] === 1200, 'Saved Woo price overrides the current $99 Square catalog price.');
    $assert(!empty($wine_lines[0]['applied_discounts']), 'The Woo discount is explicitly applied to the purchased product.');
    $assert($calculated['total_discount_money']['amount'] === 400, 'Native Square discounts preserve the exact $4 Woo saving.');
    $assert(count($payload['service_charges']) === 2 && $calculated['total_service_charge_money']['amount'] === 700, 'Postage and fees are separate service charges, totaling $7 before tax.');
    $charge_names = array_column($payload['service_charges'], 'name');
    $assert(in_array('Gift wrapping', $charge_names, true) && count(array_filter($charge_names, static function ($name) { return strpos($name, 'Postage — Ground') !== false; })) === 1, 'Shipping and fee labels survive itemization.');
    $assert($calculated['total_tax_money']['amount'] === 162 && $calculated['total_money']['amount'] === 3612, 'Product, postage and fee taxes remain separate and sum to the saved $36.12.');
    $exempt_lines = array_values(array_filter($payload['line_items'], static function ($item) use ($exempt) { return ($item['catalog_object_id'] ?? '') === $exempt->get_meta('square_variation_id'); }));
    $assert(empty($exempt_lines[0]['applied_taxes']), 'Exempt products receive no tax application.');
    $assert(($payload['pricing_options']['auto_apply_taxes'] ?? true) === false && ($payload['pricing_options']['auto_apply_discounts'] ?? true) === false, 'Square catalog tax and discount automation cannot change the saved Woo amount.');
    $assert(Square_Order::verify($calculated, $snapshot) === true, 'The independently calculated itemization passes full component verification.');
    $tampered = $calculated; ++$tampered['total_tax_money']['amount']; --$tampered['total_service_charge_money']['amount'];
    $assert(is_wp_error(Square_Order::verify($tampered, $snapshot)), 'A matching grand total cannot hide mismatched tax/service-charge components.');
    $tampered = $calculated; ++$tampered['line_items'][0]['total_tax_money']['amount'];
    $assert(is_wp_error(Square_Order::verify($tampered, $snapshot)), 'A product-level tax mismatch cannot hide behind unchanged order totals.');
    $calculate_corruption = 'total'; $assert(is_wp_error(Square_Order::prepare($o, $context)), 'CalculateOrder total mismatches fail before an order or payment can be created.');
    $calculate_corruption = 'components'; $assert(is_wp_error(Square_Order::prepare($o, $context)), 'CalculateOrder component mismatches fail even when the grand total matches.'); $calculate_corruption = '';

    $o = $order(); $line($o, $wine, 1, '10.00', '10.00', array($six => '0.60')); $line($o, $wine, 1, '10.00', '10.00', array($five => '0.50')); $finish($o);
    $snapshot = $prepare($o); $calculated = cc_square_calculate($snapshot['order']);
    $assert($calculated['total_tax_money']['amount'] === 110 && $calculated['total_money']['amount'] === 2110, 'Different tax rates stay attached to their respective line items.');
    $assert(count($snapshot['order']['taxes']) >= 2, 'Mixed Woo tax rates remain separately identifiable native Square taxes.');

    $o = $order(); $line($o, $wine, 3, '10.00', '8.99', array($six => '0.54'), array($six => '0.60')); $finish($o);
    $snapshot = $prepare($o); $calculated = cc_square_calculate($snapshot['order']); $units = 0; $gross = 0;
    foreach ($calculated['line_items'] as $item) { $units += (int) $item['quantity']; $gross += $item['gross_sales_money']['amount']; $assert($item['catalog_object_id'] === $wine->get_meta('square_variation_id'), 'Every rounding split remains linked to the exact purchased variation.'); }
    $assert($units === 3 && $gross === 1000, 'Indivisible unit prices split without changing product sales quantity or $10 subtotal.');
    $assert($calculated['total_discount_money']['amount'] === 101 && $calculated['total_tax_money']['amount'] === 54 && $calculated['total_money']['amount'] === 953, 'Split lines preserve every cent of discount, tax and final amount.');

    $o = $order(); $line($o, $wine, 1, '0.05', '0.05', array($ten => '0.01')); $finish($o);
    $snapshot = $prepare($o); $calculated = cc_square_calculate($snapshot['order']);
    $assert(cc_square_test_tax(5, '10') === 0 && $calculated['total_tax_money']['amount'] === 1 && $calculated['total_money']['amount'] === 6, 'A saved Woo half-cent rounded up survives Square bankers rounding.');

    update_option('woocommerce_tax_round_at_subtotal', 'yes');
    $o = $order(); $line($o, $wine, 1, '0.05', '0.05', array($ten => '0.005')); $line($o, $wine, 1, '0.05', '0.05', array($ten => '0.005')); $finish($o);
    $snapshot = $prepare($o); $calculated = cc_square_calculate($snapshot['order']);
    $assert($o->get_total() === '0.11' && $calculated['total_tax_money']['amount'] === 1 && $calculated['total_money']['amount'] === 11, 'Subtotal tax rounding allocates the saved one cent across both product lines exactly once.');
    update_option('woocommerce_tax_round_at_subtotal', 'no');

    // SWS maps variations directly and maps simple products to an item plus a variation.
    $variable = new WC_Product_Variable(); $variable->set_name('Variable parent'); $variable->set_status('publish'); $variable->save(); $created_products[] = $variable->get_id();
    $variable->update_meta_data('square_product_id', 'VARIABLE-ITEM'); $variable->save();
    $variation = new WC_Product_Variation(); $variation->set_parent_id($variable->get_id()); $variation->set_regular_price('10.00'); $variation->set_status('publish'); $variation->update_meta_data('square_product_id', 'VARIABLE-VAR'); $variation->save(); $created_products[] = $variation->get_id();
    $catalog['VARIABLE-VAR'] = array('id' => 'VARIABLE-VAR', 'type' => 'ITEM_VARIATION', 'version' => 1234567890, 'is_deleted' => false, 'present_at_all_locations' => true, 'item_variation_data' => array('item_id' => 'VARIABLE-ITEM', 'name' => 'Large', 'pricing_type' => 'FIXED_PRICING', 'price_money' => array('amount' => 1000, 'currency' => 'USD')));
    $catalog['VARIABLE-ITEM'] = array('id' => 'VARIABLE-ITEM', 'type' => 'ITEM', 'version' => 1234567890, 'is_deleted' => false, 'present_at_all_locations' => true, 'item_data' => array('name' => 'Variable parent', 'variations' => array($catalog['VARIABLE-VAR'])));
    $o = $order(); $line($o, $variation, 2, '20.00', '20.00'); $finish($o); $snapshot = $prepare($o);
    $assert($snapshot['order']['line_items'][0]['catalog_object_id'] === 'VARIABLE-VAR', 'SWS variation square_product_id maps to the purchased variation, using its parent item for verification.');
    $simple_fallback = $product('Single variation fallback'); $simple_fallback->delete_meta_data('square_variation_id'); $simple_fallback->save();
    $o = $order(); $line($o, $simple_fallback, 1, '10.00', '10.00'); $finish($o); $snapshot = $prepare($o);
    $assert($snapshot['order']['line_items'][0]['catalog_object_id'] === 'VAR-' . $simple_fallback->get_id(), 'A simple product without variation metadata can use its sole active catalog variation.');
    $item_id = $simple_fallback->get_meta('square_product_id'); $second = $catalog[$item_id]['item_data']['variations'][0]; $second['id'] = 'AMBIGUOUS-VAR'; $catalog[$item_id]['item_data']['variations'][] = $second; $catalog['AMBIGUOUS-VAR'] = $second;
    $assert(is_wp_error(Square_Order::prepare($o, $context)), 'A simple product with ambiguous variations cannot guess a catalog match.');

    $stale = $product('Stale SWS variation'); $stale->update_meta_data('square_variation_id', 'MISSING-VARIATION'); $stale->save();
    $o = $order(); $line($o, $stale, 1, '10.00', '10.00'); $finish($o);
    $assert(is_wp_error(Square_Order::prepare($o, $context)), 'An explicit stale variation link cannot fall back to a different variation.');

    $unlinked = $product('No SWS mapping', false); $o = $order(); $line($o, $unlinked, 1, '10.00', '10.00'); $finish($o);
    $assert(is_wp_error(Square_Order::prepare($o, $context)), 'Missing SWS metadata cannot silently create a custom item.');
    $o = $order(); $line($o, $wine, 1, '10.00', '10.00'); $finish($o); $variation_id = $wine->get_meta('square_variation_id'); $original = $catalog[$variation_id];
    foreach (array('deleted', 'wrong-type', 'wrong-parent', 'unsellable', 'unversioned', 'archived-parent', 'deleted-parent') as $failure) {
        $catalog[$variation_id] = $original; $parent_id = $original['item_variation_data']['item_id']; $parent_before = $catalog[$parent_id];
        if ($failure === 'deleted') { $catalog[$variation_id]['is_deleted'] = true; }
        if ($failure === 'wrong-type') { $catalog[$variation_id]['type'] = 'ITEM'; }
        if ($failure === 'wrong-parent') { $catalog[$variation_id]['item_variation_data']['item_id'] = 'OTHER-ITEM'; }
        if ($failure === 'unsellable') { $catalog[$variation_id]['item_variation_data']['sellable'] = false; }
        if ($failure === 'unversioned') { unset($catalog[$variation_id]['version']); }
        if ($failure === 'archived-parent') { $catalog[$parent_id]['item_data']['is_archived'] = true; }
        if ($failure === 'deleted-parent') { $catalog[$parent_id]['is_deleted'] = true; }
        $assert(is_wp_error(Square_Order::prepare($o, $context)), 'Invalid catalog mapping fails closed: ' . $failure);
        $catalog[$parent_id] = $parent_before;
    }
    $catalog[$variation_id] = $original;
    // Verified Square CalculateOrder accepts valid unmanaged catalog items at
    // this order location even when their catalog presence lists omit it.
    foreach (array('variation-elsewhere', 'variation-absent', 'parent-elsewhere', 'parent-absent') as $presence) {
        $catalog[$variation_id] = $original; $parent_id = $original['item_variation_data']['item_id']; $parent_before = $catalog[$parent_id];
        if ($presence === 'variation-elsewhere') { $catalog[$variation_id]['present_at_all_locations'] = false; $catalog[$variation_id]['present_at_location_ids'] = array('different-location'); }
        if ($presence === 'variation-absent') { $catalog[$variation_id]['absent_at_location_ids'] = array('loc-test'); }
        if ($presence === 'parent-elsewhere') { $catalog[$parent_id]['present_at_all_locations'] = false; $catalog[$parent_id]['present_at_location_ids'] = array('different-location'); }
        if ($presence === 'parent-absent') { $catalog[$parent_id]['absent_at_location_ids'] = array('loc-test'); }
        $snapshot = $prepare($o);
        $assert($snapshot['order']['line_items'][0]['catalog_object_id'] === $variation_id && $snapshot['order']['location_id'] === 'loc-test', 'Unmanaged sale preserves its validated catalog link despite catalog presence: ' . $presence);
        $catalog[$parent_id] = $parent_before;
    }
    $catalog[$variation_id] = $original;

    $tracked = $product('Inventory-managed product'); $tracked->set_manage_stock(true); $tracked->set_stock_quantity(10); $tracked->save();
    $o = $order(); $tracked_item = $line($o, $tracked, 3, '10.00', '10.00'); $finish($o); $tracked_id = $tracked->get_meta('square_variation_id');
    $assert(is_wp_error(Square_Order::prepare($o, $context)), 'A Woo-managed product requires corresponding Square inventory tracking.');
    $catalog[$tracked_id]['item_variation_data']['track_inventory'] = true; $snapshot = $prepare($o);
    $assert(count($snapshot['inventory']) === 1 && $snapshot['inventory'][(string) $tracked_item->get_id()]['quantity'] === 3, 'Inventory ownership retains original quantity when product prices split into multiple Square lines.');
    $catalog[$tracked_id]['item_variation_data']['location_overrides'] = array(array('location_id' => 'loc-test', 'track_inventory' => false));
    $assert(is_wp_error(Square_Order::prepare($o, $context)), 'A location override disabling Square inventory overrides global tracking.');
    $catalog[$tracked_id]['item_variation_data']['track_inventory'] = false; $catalog[$tracked_id]['item_variation_data']['location_overrides'][0]['track_inventory'] = true;
    $assert(!is_wp_error(Square_Order::prepare($o, $context)), 'A location override enabling Square inventory satisfies the managed-product requirement.');
    $tracked_before = $catalog[$tracked_id]; $tracked_parent_id = $tracked_before['item_variation_data']['item_id'];
    foreach (array('variation-elsewhere', 'variation-absent', 'parent-elsewhere', 'parent-absent') as $presence) {
        $catalog[$tracked_id] = $tracked_before; $parent_before = $catalog[$tracked_parent_id];
        if ($presence === 'variation-elsewhere') { $catalog[$tracked_id]['present_at_all_locations'] = false; $catalog[$tracked_id]['present_at_location_ids'] = array('different-location'); }
        if ($presence === 'variation-absent') { $catalog[$tracked_id]['absent_at_location_ids'] = array('loc-test'); }
        if ($presence === 'parent-elsewhere') { $catalog[$tracked_parent_id]['present_at_all_locations'] = false; $catalog[$tracked_parent_id]['present_at_location_ids'] = array('different-location'); }
        if ($presence === 'parent-absent') { $catalog[$tracked_parent_id]['absent_at_location_ids'] = array('loc-test'); }
        $assert(is_wp_error(Square_Order::prepare($o, $context)), 'Managed sale requires its SWS inventory source to be present at this location: ' . $presence);
        $catalog[$tracked_parent_id] = $parent_before;
    }
    $catalog[$tracked_id] = $tracked_before;

    $o = $order(); $line($o, $wine, 1, '10.00', '10.00'); $charge($o, 'fee', 'Negative fee', '-1.00'); $finish($o);
    $assert(is_wp_error(Square_Order::prepare($o, $context)), 'Negative fees fail rather than misclassifying the discount as positive revenue.');
    // Model stores using Woo's supported fractional-stock filter extension point.
    $integer_stock_filter = remove_filter('woocommerce_stock_amount', 'intval');
    try {
        $o = $order(); $fractional = $line($o, $wine, 1.5, '15.00', '15.00'); $finish($o);
        $assert((float) $fractional->get_quantity() === 1.5, 'The fractional-stock fixture retains the requested noninteger quantity.');
        $assert(is_wp_error(Square_Order::prepare($o, $context)), 'Noninteger Woo product quantity is rejected before provider mutation.');
    } finally { if ($integer_stock_filter) { add_filter('woocommerce_stock_amount', 'intval'); } }
    $o = $order(); $line($o, $wine, 1, '0.00', '0.00', array($six => '0.01')); $finish($o);
    $assert(is_wp_error(Square_Order::prepare($o, $context)), 'Tax on a zero-value product cannot be represented as a fabricated taxable sale.');
    foreach ($requests as $request) { $assert($request[0] === 'GET' || ($request[0] === 'POST' && $request[1] === '/v2/orders/calculate'), 'Preparing itemization makes no Square order or payment mutation.'); }
    echo 'Cart Cat real Woo ' . WC_VERSION . ' Square itemization tests passed (' . $assertions . ' assertions; ' . WC_Data_Store::load('order')->get_current_class_name() . ').' . PHP_EOL;
} finally {
    remove_filter('cart_cat_square_transport', $transport, 10);
    remove_filter('pre_option_square-woo-sync_settings', $sws_settings);
    foreach (array_reverse($created_orders) as $id) { $o = wc_get_order($id); if ($o) { $o->delete(true); } }
    foreach (array_reverse($created_products) as $id) { $p = wc_get_product($id); if ($p) { $p->delete(true); } }
    foreach ($created_rates as $id) { WC_Tax::_delete_tax_rate($id); }
    if ($old_rounding === null) { delete_option('woocommerce_tax_round_at_subtotal'); } else { update_option('woocommerce_tax_round_at_subtotal', $old_rounding); }
}
