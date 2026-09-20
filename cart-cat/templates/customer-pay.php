<?php
/** Cart Cat's customer-facing view of Woo checkout/form-pay.php (10.9.0). */
defined( 'ABSPATH' ) || exit;
if ( ! \Cart_Cat\Customer_Page::authorized( $order ) ) { return; }
$pricing = \Cart_Cat\Presentation::customer_pricing( $order );
$totals = $pricing['totals'];
$pickup = \Cart_Cat\Presentation::is_pickup( $order );
$gateway = $available_gateways['cart_cat_square'] ?? null;
$ready = 'ready' === $order->get_meta( '_cart_cat_preparation' );
$stock_hold = \Cart_Cat\Stock_Hold::details( $order );
$expired = \Cart_Cat\Stock_Hold::expired( $order );
?>
<form id="order_review" class="cc-customer-pay" method="post">
	<div class="cc-pay-intro"><p class="cc-pay-eyebrow"><?php echo esc_html( sprintf( 'Order #%s', $order->get_order_number() ) ); ?></p><p>Review your order and complete your payment below.</p></div>
	<div class="cc-pay-layout">
		<div class="cc-pay-details">
			<section class="cc-pay-card" aria-labelledby="cc-pay-products-heading">
				<h2 id="cc-pay-products-heading">Your order</h2>
				<ul class="cc-pay-products">
				<?php foreach ( $order->get_items() as $item_id => $item ) : ?>
					<?php if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) { continue; } $product = $item->get_product(); ?>
					<li class="cc-pay-product">
						<div class="cc-pay-product-image"><?php echo wp_kses_post( $product ? $product->get_image( 'woocommerce_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ) : wc_placeholder_img( 'woocommerce_thumbnail' ) ); ?></div>
						<div class="cc-pay-product-info">
							<h3><?php echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) ); ?></h3>
							<?php do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, false ); wc_display_item_meta( $item ); do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false ); ?>
							<p class="cc-pay-quantity"><?php echo esc_html( sprintf( 'Quantity: %s', $item->get_quantity() ) ); ?></p>
						</div>
						<?php $price = $pricing['lines'][ $item_id ]; ?>
						<div class="cc-pay-line-price<?php echo $price['discounted'] ? ' has-discount' : ''; ?>">
							<?php if ( $price['discounted'] ) : ?><del class="cc-pay-original-price"><span class="screen-reader-text">Original price: </span><?php echo wp_kses_post( $price['original_html'] ); ?></del><?php endif; ?>
							<span class="cc-pay-current-price"><span class="screen-reader-text">Price to pay: </span><?php echo wp_kses_post( $price['total_html'] ); ?></span>
						</div>
					</li>
				<?php endforeach; ?>
				</ul>
			</section>
			<?php if ( $order->get_items( 'shipping' ) ) : ?>
			<section class="cc-pay-card cc-pay-fulfillment" aria-labelledby="cc-pay-fulfillment-heading">
				<h2 id="cc-pay-fulfillment-heading"><?php echo $pickup ? 'Pickup at the winery' : 'Delivery'; ?></h2>
				<?php if ( $pickup ) : ?>
					<?php foreach ( \Cart_Cat\Presentation::pickup_locations( $order ) as $location ) : ?>
						<p class="cc-pay-location"><?php echo esc_html( $location['name'] ); ?></p>
						<?php if ( $location['address'] ) : ?><address><?php echo nl2br( esc_html( $location['address'] ) ); ?></address><?php endif; ?>
						<?php if ( $location['details'] ) : ?><p class="cc-pay-note"><?php echo nl2br( esc_html( $location['details'] ) ); ?></p><?php endif; ?>
					<?php endforeach; ?>
				<?php else : ?>
					<?php if ( $order->get_formatted_shipping_address() ) : ?><address><?php echo wp_kses_post( $order->get_formatted_shipping_address() ); ?></address><?php endif; ?>
					<?php foreach ( $order->get_items( 'shipping' ) as $shipping ) : ?><p class="cc-pay-note"><?php echo esc_html( $shipping->get_name() ); ?></p><?php endforeach; ?>
					<?php if ( $order->get_meta( '_hhh_shipping_preference_label' ) ) : ?><p class="cc-pay-note"><?php echo esc_html( $order->get_meta( '_hhh_shipping_preference_label' ) ); ?></p><?php endif; ?>
				<?php endif; ?>
			</section>
			<?php endif; ?>
			<?php if ( $order->get_customer_note() ) : ?>
			<section class="cc-pay-card cc-pay-order-notes" aria-labelledby="cc-pay-order-notes-heading">
				<h2 id="cc-pay-order-notes-heading">Order notes</h2>
				<p class="cc-pay-note"><?php echo nl2br( esc_html( $order->get_customer_note() ) ); ?></p>
			</section>
			<?php endif; ?>
			<?php if ( $order->get_billing_email() ) : ?><p class="cc-pay-contact"><?php echo esc_html( sprintf( 'Order updates will be sent to %s.', $order->get_billing_email() ) ); ?></p><?php endif; ?>
		</div>
		<section class="cc-pay-card cc-pay-summary" aria-labelledby="cc-pay-summary-heading">
			<h2 id="cc-pay-summary-heading">Order summary</h2>
			<dl class="cc-pay-totals">
				<?php foreach ( $totals as $key => $total ) : ?>
					<div class="<?php echo 'order_total' === $key ? 'cc-pay-grand-total' : ( 'discount' === ( $total['type'] ?? '' ) ? 'cc-pay-discount' : '' ); ?>"><dt><?php echo wp_kses_post( $total['label'] ); ?></dt><dd><?php echo wp_kses_post( $total['value'] ); ?></dd></div>
				<?php endforeach; ?>
			</dl>
			<?php do_action( 'woocommerce_pay_order_before_payment' ); ?>
			<div id="payment" class="cc-pay-payment">
				<?php if ( $gateway && $ready && ! $expired && $order->needs_payment() ) : ?>
					<h2>Payment details</h2>
					<?php if ( $stock_hold['active'] && $stock_hold['expires_label'] ) : ?><p class="cc-pay-note"><?php echo esc_html( sprintf( 'Complete payment by %s.', $stock_hold['expires_label'] ) ); ?></p><?php endif; ?>
					<input type="hidden" name="payment_method" value="cart_cat_square" />
					<?php $gateway->payment_fields(); ?>
				<?php else : ?>
					<p role="status"><?php echo $expired ? 'The payment deadline has passed. Please contact the winery to arrange a new order.' : ( ! $ready ? 'The winery is updating this order. Please use the new payment email once your changes are ready.' : 'Payment is currently unavailable. Please contact the winery for help with this order.' ); ?></p>
				<?php endif; ?>
				<div class="form-row">
					<input type="hidden" name="woocommerce_pay" value="1" />
					<?php wc_get_template( 'checkout/terms.php' ); do_action( 'woocommerce_pay_order_before_submit' ); ?>
					<?php $button_text = sprintf( 'Pay %s', html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ) ); ?>
					<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="button alt" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $button_text ) . '"' . ( ! $gateway || ! $ready || $expired ? ' disabled' : '' ) . '>' . esc_html( $button_text ) . '</button>' ); ?>
					<?php do_action( 'woocommerce_pay_order_after_submit' ); wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
				</div>
			</div>
		</section>
	</div>
</form>
