<?php
/** Cart Cat payment invitation, preserving Woo's native invoice content hooks. */
use Automattic\WooCommerce\Utilities\FeaturesUtil;
defined( 'ABSPATH' ) || exit;
$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );
do_action( 'woocommerce_email_header', $email_heading, $email );
echo $email_improvements_enabled ? '<div class="email-introduction">' : '';
?>
<p><?php echo $order->get_billing_first_name() ? sprintf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) ) : esc_html__( 'Hi,', 'woocommerce' ); ?></p>
<p><?php
if ( $order->has_status( 'failed' ) ) {
	printf( esc_html__( 'Sorry, your order on %s was unsuccessful. Your order details are below. You can try your payment again using the button below.', 'cart-cat' ), esc_html( get_bloginfo( 'name', 'display' ) ) );
} else {
	printf( esc_html__( 'An order has been created for you on %s. Your order details are below. Complete your purchase using the button below.', 'cart-cat' ), esc_html( get_bloginfo( 'name', 'display' ) ) );
}
?></p>
<p class="cart-cat-invoice-payment-container"><a class="button cart-cat-invoice-payment" href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>"><?php esc_html_e( 'Pay for this order', 'woocommerce' ); ?></a></p>
<?php
echo $email_improvements_enabled ? '</div>' : '';
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}
do_action( 'woocommerce_email_footer', $email );
