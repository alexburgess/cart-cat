<?php
namespace Cart_Cat;
defined( 'ABSPATH' ) || exit;
/** Request-only session: never creates cookies or persists a customer's cart. */
final class Session extends \WC_Session {
	public function __construct() { $this->_customer_id = 'cart-cat-preview'; }
	public function has_session() { return true; }
	public function set_customer_session_cookie( $set ) {}
	public function save_data( $old_session_key = 0 ) {}
	public function get_session_data() { return $this->_data; }
}
