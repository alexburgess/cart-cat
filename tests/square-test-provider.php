<?php
/** Independent, network-free Square arithmetic fixture for the itemization tests. */

function cc_square_test_money(int $amount, string $currency): array {
    return array('amount' => $amount, 'currency' => $currency);
}

/** Square uses bankers' rounding for tax calculations. Keep fixture arithmetic integer-only. */
function cc_square_test_tax(int $base, string $percentage): int {
    if (!preg_match('/^(\d+)(?:\.(\d+))?$/D', $percentage, $parts)) { throw new RuntimeException('Unexpected fixture tax percentage: ' . $percentage); }
    $fraction = $parts[2] ?? '';
    $scale = 10 ** strlen($fraction);
    $numerator = $base * ((int) $parts[1] * $scale + (int) $fraction);
    $denominator = 100 * $scale;
    if (!is_int($numerator) || !is_int($denominator)) { throw new RuntimeException('Fixture tax arithmetic exceeded integer precision.'); }
    $whole = intdiv($numerator, $denominator);
    $remainder = $numerator % $denominator;
    return $whole + (int) ($remainder * 2 > $denominator || ($remainder * 2 === $denominator && $whole % 2 === 1));
}

/** Returns a calculated Square order, as CalculateOrder would, without reading Woo totals. */
function cc_square_calculate(array $order): array {
    $currency = $order['line_items'][0]['base_price_money']['currency'] ?? 'USD';
    $taxes = array(); $discounts = array();
    foreach ($order['taxes'] ?? array() as $index => $tax) {
        if (($tax['scope'] ?? '') !== 'LINE_ITEM' || ($tax['type'] ?? '') !== 'ADDITIVE' || empty($tax['uid'])) { throw new RuntimeException('Fixture expects explicit additive line-item taxes.'); }
        $taxes[$tax['uid']] = array('index' => $index, 'percentage' => (string) $tax['percentage'], 'amount' => 0);
    }
    foreach ($order['discounts'] ?? array() as $index => $discount) {
        if (($discount['scope'] ?? '') !== 'LINE_ITEM' || ($discount['type'] ?? '') !== 'FIXED_AMOUNT' || empty($discount['uid'])) { throw new RuntimeException('Fixture expects explicit fixed line-item discounts.'); }
        $discounts[$discount['uid']] = array('index' => $index, 'amount' => (int) $discount['amount_money']['amount'], 'applied' => 0);
    }
    $sum = 0; $discount_total = 0; $tax_total = 0; $charges = 0;
    foreach ($order['line_items'] as &$line) {
        if (!preg_match('/^[1-9][0-9]*$/D', (string) $line['quantity'])) { throw new RuntimeException('Fixture expects positive integer product quantities.'); }
        $gross = (int) $line['base_price_money']['amount'] * (int) $line['quantity'];
        $discount_amount = 0;
        if (!empty($line['applied_discounts'])) {
            foreach ($line['applied_discounts'] as &$applied) {
                $uid = $applied['discount_uid'];
                if (!isset($discounts[$uid])) { throw new RuntimeException('Unknown fixture discount UID.'); }
                $amount = $discounts[$uid]['amount'];
                $discount_amount += $amount; $discounts[$uid]['applied'] += $amount;
                $applied['applied_money'] = cc_square_test_money($amount, $currency);
            }
            unset($applied);
        }
        if ($discount_amount > $gross) { throw new RuntimeException('Fixture discount exceeds line gross.'); }
        $net = $gross - $discount_amount; $line_tax = 0;
        if (!empty($line['applied_taxes'])) {
            foreach ($line['applied_taxes'] as &$applied) {
                $uid = $applied['tax_uid'];
                if (!isset($taxes[$uid])) { throw new RuntimeException('Unknown fixture tax UID.'); }
                $amount = cc_square_test_tax($net, $taxes[$uid]['percentage']);
                $taxes[$uid]['amount'] += $amount; $line_tax += $amount;
                $applied['applied_money'] = cc_square_test_money($amount, $currency);
            }
            unset($applied);
        }
        $line['gross_sales_money'] = cc_square_test_money($gross, $currency);
        $line['total_discount_money'] = cc_square_test_money($discount_amount, $currency);
        $line['total_tax_money'] = cc_square_test_money($line_tax, $currency);
        $line['total_money'] = cc_square_test_money($net + $line_tax, $currency);
        $sum += $net + $line_tax; $discount_total += $discount_amount; $tax_total += $line_tax;
    }
    unset($line);
    if (!empty($order['service_charges'])) {
        foreach ($order['service_charges'] as &$charge) {
            if (($charge['scope'] ?? '') !== 'ORDER' || ($charge['treatment_type'] ?? '') !== 'LINE_ITEM_TREATMENT' || ($charge['calculation_phase'] ?? '') !== 'SUBTOTAL_PHASE') { throw new RuntimeException('Unexpected fixture service-charge treatment.'); }
            $amount = (int) $charge['amount_money']['amount']; $charge_tax = 0;
            if (!empty($charge['applied_taxes'])) {
                if (empty($charge['taxable'])) { throw new RuntimeException('Untaxable fixture service charge has taxes.'); }
                foreach ($charge['applied_taxes'] as &$applied) {
                    $uid = $applied['tax_uid'];
                    if (!isset($taxes[$uid])) { throw new RuntimeException('Unknown fixture service-charge tax UID.'); }
                    $tax_amount = cc_square_test_tax($amount, $taxes[$uid]['percentage']);
                    $taxes[$uid]['amount'] += $tax_amount; $charge_tax += $tax_amount;
                    $applied['applied_money'] = cc_square_test_money($tax_amount, $currency);
                }
                unset($applied);
            }
            $charge['applied_money'] = cc_square_test_money($amount, $currency);
            $charge['total_tax_money'] = cc_square_test_money($charge_tax, $currency);
            $charge['total_money'] = cc_square_test_money($amount + $charge_tax, $currency);
            $sum += $amount + $charge_tax; $charges += $amount; $tax_total += $charge_tax;
        }
        unset($charge);
    }
    foreach ($taxes as $tax) { $order['taxes'][$tax['index']]['applied_money'] = cc_square_test_money($tax['amount'], $currency); }
    foreach ($discounts as $discount) { $order['discounts'][$discount['index']]['applied_money'] = cc_square_test_money($discount['applied'], $currency); }
    $order['total_money'] = cc_square_test_money($sum, $currency);
    $order['total_discount_money'] = cc_square_test_money($discount_total, $currency);
    $order['total_tax_money'] = cc_square_test_money($tax_total, $currency);
    $order['total_service_charge_money'] = cc_square_test_money($charges, $currency);
    $order['total_tip_money'] = cc_square_test_money(0, $currency);
    return $order;
}
