<?php
namespace Cart_Cat;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Cart Cat', 'cart-cat' ), __( 'Cart Cat', 'cart-cat' ), 'manage_woocommerce', 'cart-cat', array( __CLASS__, 'render' ), 'none', 56 );
		add_submenu_page( 'cart-cat', __( 'Cart Cat settings', 'cart-cat' ), __( 'Settings', 'cart-cat' ), 'manage_options', 'cart-cat-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function settings() {
		register_setting( 'cart_cat_settings', 'cart_cat_google_maps_browser_key', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '', 'show_in_rest' => false ) );
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cart Cat settings', 'cart-cat' ); ?></h1>
			<?php settings_errors(); ?>
			<form action="options.php" method="post">
				<?php settings_fields( 'cart_cat_settings' ); ?>
				<h2><?php esc_html_e( 'Google address search', 'cart-cat' ); ?></h2>
				<p><?php esc_html_e( 'Search Google for billing and shipping addresses while preparing an order. Manual address entry is always available.', 'cart-cat' ); ?></p>
				<table class="form-table" role="presentation"><tr>
					<th scope="row"><label for="cart-cat-google-key"><?php esc_html_e( 'Google Maps browser API key', 'cart-cat' ); ?></label></th>
					<td><input id="cart-cat-google-key" name="cart_cat_google_maps_browser_key" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'cart_cat_google_maps_browser_key', '' ) ); ?>" autocomplete="off" data-1p-ignore="true" aria-describedby="cart-cat-google-key-help" />
					<p class="description" id="cart-cat-google-key-help"><?php esc_html_e( 'Use a browser key restricted to this website, with Maps JavaScript API and Places API (New) enabled in a Google Cloud project with billing enabled. This key is visible to staff browsers. Leave blank to disable address search.', 'cart-cat' ); ?> <a href="https://developers.google.com/maps/documentation/javascript/get-api-key" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google setup instructions', 'cart-cat' ); ?></a></p></td>
				</tr></table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function enqueue( $hook ) {
		wp_enqueue_style( 'cart-cat-menu-icon', CART_CAT_URL . 'assets/css/menu-icon.css', array(), CART_CAT_VERSION );
		if ( 'toplevel_page_cart-cat' !== $hook ) {
			return;
		}
		// Addify globally enqueues its rule-editor assets (including unscoped details CSS)
		// without Select2. Cart Cat has none of those editor controls.
		wp_dequeue_script( 'addify_csp_admin_js' );
		wp_dequeue_style( 'addify_csp_admin_css' );
		wp_enqueue_style( 'cart-cat-icons', CART_CAT_URL . 'assets/css/icons.css', array(), CART_CAT_VERSION );
		wp_enqueue_style( 'cart-cat-admin', CART_CAT_URL . 'assets/css/admin.css', array(), CART_CAT_VERSION );
		wp_enqueue_script( 'cart-cat-payment', CART_CAT_URL . 'assets/js/payment.js', array(), CART_CAT_VERSION, true );
		wp_enqueue_script( 'cart-cat-catalog-cache', CART_CAT_URL . 'assets/js/catalog-cache.js', array(), CART_CAT_VERSION, true );
		wp_enqueue_script( 'cart-cat-address-autocomplete', CART_CAT_URL . 'assets/js/address-autocomplete.js', array(), CART_CAT_VERSION, true );
		wp_enqueue_script( 'cart-cat-admin', CART_CAT_URL . 'assets/js/admin.js', array( 'cart-cat-payment', 'cart-cat-catalog-cache', 'cart-cat-address-autocomplete' ), CART_CAT_VERSION, true );
		wp_enqueue_script( 'cart-cat-icons', CART_CAT_URL . 'assets/js/icons.js', array( 'cart-cat-admin' ), CART_CAT_VERSION, true );
		$bootstrap = array(
			'restUrl' => esc_url_raw( rest_url( 'cart-cat/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'square'  => Square::public_config(),
			'hold' => Stock_Hold::config(),
			'countries' => WC()->countries->get_countries(),
			'states' => WC()->countries->get_states(),
			'addressSearch' => array( 'apiKey' => (string) get_option( 'cart_cat_google_maps_browser_key', '' ) ),
		);
		wp_add_inline_script( 'cart-cat-admin', 'window.CartCat = ' . wp_json_encode( $bootstrap ) . ';', 'before' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to create customer orders.', 'cart-cat' ) );
		}
		require CART_CAT_PATH . 'templates/admin.php';
	}
}
