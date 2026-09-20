<?php
/** Native Woo order-pay hosted fields. No stored-card disclosure on public links. */
defined('ABSPATH') || exit;
?>
<div class="cart-cat-payment" data-cart-cat-payment data-checking="<?php echo $checking ? '1' : '0'; ?>" data-options="<?php echo esc_attr(wp_json_encode($config)); ?>">
    <?php if ($checking) : ?>
        <p role="status">Your payment is being checked. Please do not submit another payment.</p>
        <p><a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>">Check payment status</a></p>
    <?php else : ?>
        <div class="cc-pay-wallets" data-cart-cat-wallets hidden>
            <div class="cc-pay-wallet-buttons">
                <button type="button" class="cc-apple-pay" data-cart-cat-wallet="applePay" aria-label="Pay with Apple Pay" hidden></button>
                <div data-cart-cat-wallet="googlePay" hidden></div>
            </div>
            <p class="cc-pay-card-divider">Or pay by card</p>
        </div>
        <div data-cart-cat-card></div>
        <p data-cart-cat-card-status role="status" aria-live="polite">Loading Square’s secure card form…</p>
    <?php endif; ?>
    <input type="hidden" name="cart_cat_token" value="" />
    <input type="hidden" name="cart_cat_verification_token" value="" />
    <input type="hidden" name="cart_cat_version" value="<?php echo esc_attr($order->get_meta('_cart_cat_version', true)); ?>" />
</div>
