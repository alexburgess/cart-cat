<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;

/** Presentation for the existing Woo customer invoice; delivery remains owned by Woo. */
final class Invoice {
	public static function register(): void {
		add_filter( 'woocommerce_email_subject_customer_invoice', array( __CLASS__, 'title' ), PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_email_heading_customer_invoice', array( __CLASS__, 'title' ), PHP_INT_MAX, 3 );
		add_filter( 'wc_get_template', array( __CLASS__, 'template' ), 30, 5 );
		add_filter( 'woocommerce_email_styles', array( __CLASS__, 'styles' ), 5, 2 );
	}
	private static function applies( $order, $email ): bool {
		return Payments::owns( $order ) && $order->needs_payment() && $email instanceof \WC_Email && 'customer_invoice' === $email->id && $email->is_customer_email();
	}
	public static function title( $title, $order, $email ) {
		return self::applies( $order, $email ) ? 'Complete Your Purchase (Order #' . sanitize_text_field( $order->get_order_number() ) . ')' : $title;
	}
	public static function template( $located, $template_name, $args, $template_path, $default_path ) {
		if ( 'emails/customer-invoice.php' !== $template_name || ! empty( $args['sent_to_admin'] ) || ! empty( $args['plain_text'] ) || ! self::applies( $args['order'] ?? null, $args['email'] ?? null ) ) { return $located; }
		return CART_CAT_PATH . 'templates/emails/customer-invoice.php';
	}
	public static function styles( $css, $email ) {
		if ( ! $email instanceof \WC_Email || ! self::applies( $email->object ?? null, $email ) ) { return $css; }
		// The winery's later .button rules supply its canonical outlined button when active.
		// Otherwise retain a usable Woo-colored button without depending on the branding plugin.
		$color = sanitize_hex_color( get_option( 'woocommerce_email_base_color', '#7f54b3' ) ) ?: '#7f54b3';
		$text = wc_light_or_dark( $color, '#202020', '#ffffff' );
		return $css . "\n#body_content a.cart-cat-invoice-payment { background:{$color}; border:1px solid {$color}; border-radius:3px; color:{$text}; display:inline-block; font-weight:600; line-height:1.4; padding:12px 24px; text-decoration:none; }\n";
	}
}
