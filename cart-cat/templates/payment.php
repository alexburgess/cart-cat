<?php
/** Native Woo order-pay hosted fields. No stored-card disclosure on public links. */
defined('ABSPATH') || exit;
?>
<div class="cart-cat-payment" data-cart-cat-payment data-checking="<?php echo $checking ? '1' : '0'; ?>" data-options="<?php echo esc_attr(wp_json_encode($config)); ?>">
    <div class="cc-pay-applied" data-cart-cat-applied hidden></div>
    <button type="button" class="cc-pay-secondary" data-cart-cat-cancel hidden>Cancel applied payments</button>
    <?php if (($config['status'] ?? '') === 'paid') : ?>
        <p role="status">Payment complete.</p>
        <p><a href="<?php echo esc_url($config['completed_url']); ?>">View your order</a></p>
    <?php elseif (($config['status'] ?? '') === 'unavailable') : ?>
        <p role="status">This order is no longer available for payment. Please contact us.</p>
    <?php elseif ($checking) : ?>
        <p role="status">Your payment is being checked. Please do not submit another payment.</p>
        <p><a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>">Check payment status</a></p>
    <?php else : ?>
        <div class="cc-pay-gift-option">
            <button type="button" class="cc-pay-secondary" data-cart-cat-gift-open>Use Square gift card</button>
            <div data-cart-cat-gift-form hidden>
                <div data-cart-cat-gift></div>
                <button type="button" class="cc-pay-secondary" data-cart-cat-gift-apply disabled>Apply gift card</button>
            </div>
        </div>
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
    <input type="hidden" name="cart_cat_source_type" value="token" />
    <input type="hidden" name="cart_cat_action" value="pay" />
    <input type="hidden" name="cart_cat_request_id" value="" />
    <input type="hidden" name="cart_cat_payment_revision" value="<?php echo esc_attr($config['payment']['revision'] ?? ''); ?>" />
</div>
