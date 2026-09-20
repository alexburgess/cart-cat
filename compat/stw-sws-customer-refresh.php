<?php
/**
 * Plugin Name: Stone Tower SWS Customer Refresh
 * Description: Runs the existing SWS Pro customer Refresh action hourly.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

final class STW_SWS_Customer_Refresh {

	const HOOK = 'stw_sws_customer_refresh_hourly';
	const ENABLED = 'stw_sws_customer_refresh_enabled';
	const RESULT = 'stw_sws_customer_refresh_last_attempt';
	const CONTROLLER = '\\Pixeldev\\SquareWooSyncPro\\REST\\CustomersController';
	private static $lock = false;
	private static $manual_request = null;

	public static function boot() {
		// This MU-plugin belongs to the winery's main store, not other network sites.
		if ( 1 !== get_current_blog_id() ) {
			return;
		}
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
		// rest_dispatch_request runs after the REST route's permission check.
		add_filter( 'rest_dispatch_request', array( __CLASS__, 'before_manual_refresh' ), 10, 4 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'after_manual_refresh' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'unlock' ) );
	}

	private static function available() {
		return (bool) get_option( self::ENABLED, true )
			&& class_exists( self::CONTROLLER )
			&& method_exists( self::CONTROLLER, 'get_customers' );
	}

	public static function schedule() {
		if ( ! self::available() ) {
			if ( wp_next_scheduled( self::HOOK ) ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$result = wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK, array(), true );
			if ( is_wp_error( $result ) || false === $result ) {
				self::record( 'schedule_failed' );
			}
		}
	}

	private static function lock_name() {
		global $wpdb;
		return 'stw_sws_customer_refresh_' . md5( DB_NAME . ':' . $wpdb->prefix );
	}

	private static function lock() {
		global $wpdb;
		if ( self::$lock ) {
			return false;
		}
		self::$lock = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
		return self::$lock;
	}

	public static function unlock() {
		global $wpdb;
		if ( self::$lock ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
			self::$lock = false;
		}
	}

	private static function is_refresh( $request ) {
		return $request instanceof WP_REST_Request
			&& 'GET' === $request->get_method()
			&& '/sws/v1/customers' === rtrim( $request->get_route(), '/' )
			&& 'true' === $request->get_param( 'force' );
	}

	public static function before_manual_refresh( $result, $request, $route, $handler ) {
		if ( null !== $result || ! self::available() || ! self::is_refresh( $request ) ) {
			return $result;
		}
		if ( ! self::lock() ) {
			return new WP_Error( 'stw_sws_refresh_busy', 'A customer refresh is being queued. Please try again shortly.', array( 'status' => 409 ) );
		}
		self::$manual_request = $request;
		return $result;
	}

	public static function after_manual_refresh( $result, $handler, $request ) {
		if ( self::$manual_request === $request ) {
			self::$manual_request = null;
			self::unlock();
		}
		return $result;
	}

	public static function run() {
		global $wpdb;
		if ( ! self::available() ) {
			return self::record( 'unavailable' );
		}
		if ( ! self::lock() ) {
			return self::record( 'skipped_dispatch_busy' );
		}
		try {
			// Read after taking the enqueue lock. Never clear a long-running job.
			// Bypass any option value cached before another request won the lock.
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'sws_customer_fetch_status' ) );
			if ( $wpdb->last_error ) {
				return self::record( 'invalid_native_status' );
			}
			$status = null === $raw ? array() : maybe_unserialize( $raw );
			if ( ! is_array( $status ) ) {
				return self::record( 'invalid_native_status' );
			}
			if ( in_array( $status['state'] ?? '', array( 'queuing', 'processing' ), true ) ) {
				return self::record( 'skipped_refresh_in_progress' );
			}
			$request = new WP_REST_Request( 'GET', '/sws/v1/customers' );
			$request->set_param( 'force', 'true' );
			// Omit full: this is Refresh, with the same native queue and settings.
			$class = self::CONTROLLER;
			$controller = new $class();
			$response = $controller->get_customers( $request );
			if ( is_wp_error( $response ) || ! $response instanceof WP_REST_Response ) {
				return self::record( 'native_refresh_failed' );
			}
			$data = $response->get_data();
			$http_status = $response->get_status();
			$queued = 200 === $http_status && is_array( $data ) && ! empty( $data['success'] );
			return self::record( $queued ? 'queued' : ( 409 === $http_status ? 'skipped_refresh_in_progress' : 'native_refresh_failed' ), $http_status );
		} catch ( Throwable $error ) {
			return self::record( 'native_refresh_failed' );
		} finally {
			self::unlock();
		}
	}

	private static function record( $state, $http_status = 0 ) {
		$result = array( 'at' => time(), 'state' => $state, 'http_status' => (int) $http_status );
		update_option( self::RESULT, $result, false );
		if ( in_array( $state, array( 'schedule_failed', 'native_refresh_failed', 'invalid_native_status' ), true ) ) {
			error_log( '[STW SWS customer refresh] ' . $state );
		}
		return $result;
	}
}

add_action( 'plugins_loaded', array( 'STW_SWS_Customer_Refresh', 'boot' ), 30 );
