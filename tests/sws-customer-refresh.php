<?php
/** Standalone safety checks for the hourly SWS Refresh adapter. No WordPress/provider IO. */

namespace {

define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'cart_cat_refresh_test' );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['sws_test_load_controller'] = ! in_array( '--without-controller', $argv, true );

class WP_Error {

	public $code;
	public $data;

	public function __construct( $code, $message = '', $data = null ) {
		$this->code = $code;
		$this->data = $data;
	}
}

class WP_REST_Request {

	private $method;
	private $route;
	private $params = array();

	public function __construct( $method, $route ) { $this->method = $method; $this->route = $route; }
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	public function set_param( $name, $value ) { $this->params[ $name ] = $value; }
	public function get_param( $name ) { return $this->params[ $name ] ?? null; }
}

class WP_REST_Response {

	private $data;
	private $status;

	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}

class SWS_Refresh_Test_DB {

	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $last_error = '';
	public $status_read_error = false;
	public $lock_owner = null;
	public $acquisitions = 0;
	public $releases = 0;
	public $queries = array();
	public $on_acquire = null;

	public function prepare( $sql, ...$values ) {
		foreach ( $values as $value ) {
			$sql = preg_replace( '/%s/', "'" . addslashes( (string) $value ) . "'", $sql, 1 );
		}
		return $sql;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'GET_LOCK(' ) ) {
			if ( null !== $this->lock_owner ) { return '0'; }
			$this->lock_owner = 'this_request';
			++$this->acquisitions;
			if ( $this->on_acquire ) { ( $this->on_acquire )(); }
			return '1';
		}
		if ( str_contains( $sql, 'RELEASE_LOCK(' ) ) {
			if ( 'this_request' !== $this->lock_owner ) { throw new \RuntimeException( 'Released another request\'s lock.' ); }
			$this->lock_owner = null;
			++$this->releases;
			return '1';
		}
		if ( str_contains( $sql, 'option_value' ) && str_contains( $sql, 'sws_customer_fetch_status' ) ) {
			if ( $this->status_read_error ) { $this->last_error = 'Simulated database read failure'; return null; }
			return array_key_exists( 'sws_customer_fetch_status', $GLOBALS['sws_test_options'] )
				? serialize( $GLOBALS['sws_test_options']['sws_customer_fetch_status'] ) : null;
		}
		throw new \RuntimeException( 'Unexpected SQL in test: ' . $sql );
	}
}

function get_current_blog_id() { return $GLOBALS['sws_test_blog']; }
function get_option( $key, $default = false ) { return $GLOBALS['sws_test_option_cache'][ $key ] ?? $GLOBALS['sws_test_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['sws_test_options'][ $key ] = $value; $GLOBALS['sws_test_updates'][] = array( $key, $value, $autoload ); return true; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['sws_test_actions'][ $hook ][] = array( $callback, $priority, $args ); }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['sws_test_filters'][ $hook ][] = array( $callback, $priority, $args ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['sws_test_events'][ $hook ]['at'] ?? false; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['sws_test_events'][ $hook ] ); ++$GLOBALS['sws_test_clears']; return 1; }
function wp_schedule_event( $at, $recurrence, $hook, $args, $wp_error ) {
	++$GLOBALS['sws_test_schedule_calls'];
	if ( true !== $GLOBALS['sws_test_schedule_result'] ) { return $GLOBALS['sws_test_schedule_result']; }
	$GLOBALS['sws_test_events'][ $hook ] = compact( 'at', 'recurrence', 'args', 'wp_error' );
	return true;
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function maybe_unserialize( $value ) { return is_string( $value ) && preg_match( '/^(?:a|s|i|b|d|O|C):|^N;/', $value ) ? unserialize( $value ) : $value; }
function wp_cache_delete( $key, $group = '' ) { $GLOBALS['sws_test_cache_deletes'][] = array( $key, $group ); return true; }

}

namespace Pixeldev\SquareWooSyncPro\REST {

if ( $GLOBALS['sws_test_load_controller'] ) {
	class CustomersController {

		public function get_customers( $request ) {
			$GLOBALS['sws_test_native_requests'][] = $request;
			if ( $GLOBALS['sws_test_native_callback'] ) { return ( $GLOBALS['sws_test_native_callback'] )( $request ); }
			return new \WP_REST_Response( array( 'success' => true ), 200 );
		}
	}
}

}

namespace {

require dirname( __DIR__ ) . '/compat/stw-sws-customer-refresh.php';

$assertions = 0;
function check( $condition, $message ) {
	global $assertions;
	++$assertions;
	if ( ! $condition ) { throw new \RuntimeException( $message ); }
}

function reset_fixture() {
	if ( isset( $GLOBALS['wpdb'] ) ) { STW_SWS_Customer_Refresh::unlock(); }
	$property = new \ReflectionProperty( STW_SWS_Customer_Refresh::class, 'manual_request' );
	$property->setValue( null, null );
	$GLOBALS['wpdb'] = new SWS_Refresh_Test_DB();
	$GLOBALS['sws_test_blog'] = 1;
	$GLOBALS['sws_test_options'] = array();
	$GLOBALS['sws_test_option_cache'] = array();
	$GLOBALS['sws_test_updates'] = array();
	$GLOBALS['sws_test_actions'] = array();
	$GLOBALS['sws_test_filters'] = array();
	$GLOBALS['sws_test_events'] = array();
	$GLOBALS['sws_test_clears'] = 0;
	$GLOBALS['sws_test_schedule_calls'] = 0;
	$GLOBALS['sws_test_schedule_result'] = true;
	$GLOBALS['sws_test_native_requests'] = array();
	$GLOBALS['sws_test_native_callback'] = null;
	$GLOBALS['sws_test_cache_deletes'] = array();
}

function refresh_request( $method = 'GET', $route = '/sws/v1/customers', $force = 'true' ) {
	$request = new WP_REST_Request( $method, $route );
	$request->set_param( 'force', $force );
	return $request;
}

function assert_unlocked( $message ) {
	check( null === $GLOBALS['wpdb']->lock_owner, $message );
	check( $GLOBALS['wpdb']->acquisitions === $GLOBALS['wpdb']->releases, 'Every acquired lock was released.' );
}

reset_fixture();
if ( ! $GLOBALS['sws_test_load_controller'] ) {
	STW_SWS_Customer_Refresh::schedule();
	check( 0 === $GLOBALS['sws_test_schedule_calls'], 'Missing SWS does not schedule.' );
	check( 'unavailable' === STW_SWS_Customer_Refresh::run()['state'], 'Missing SWS is recorded, without calling native code.' );
	check( null === STW_SWS_Customer_Refresh::before_manual_refresh( null, refresh_request(), '', array() ), 'Missing SWS leaves REST unchanged.' );
	check( 0 === $GLOBALS['wpdb']->acquisitions, 'Missing SWS does not acquire a lock.' );
	echo "SWS customer refresh missing-provider checks: {$assertions} passed.\n";
	exit;
}

// Site scope and hook placement: the manual guard is after WordPress permission checks.
$GLOBALS['sws_test_blog'] = 2;
STW_SWS_Customer_Refresh::boot();
check( array() === $GLOBALS['sws_test_actions'] && array() === $GLOBALS['sws_test_filters'], 'Other network sites register no hooks.' );
$GLOBALS['sws_test_blog'] = 1;
STW_SWS_Customer_Refresh::boot();
check( isset( $GLOBALS['sws_test_actions'][ STW_SWS_Customer_Refresh::HOOK ] ), 'Main store registers its job.' );
check( isset( $GLOBALS['sws_test_filters']['rest_dispatch_request'] ), 'Manual refresh guard uses post-permission dispatch.' );
check( ! isset( $GLOBALS['sws_test_filters']['rest_request_before_callbacks'] ), 'No lock is taken before route permission checks.' );

// Hourly event is added once, with a future first run; disabling removes it.
reset_fixture();
$start = time();
STW_SWS_Customer_Refresh::schedule();
STW_SWS_Customer_Refresh::schedule();
$event = $GLOBALS['sws_test_events'][ STW_SWS_Customer_Refresh::HOOK ];
check( 1 === $GLOBALS['sws_test_schedule_calls'], 'Page visits do not duplicate the hourly event.' );
check( 'hourly' === $event['recurrence'] && array() === $event['args'], 'Event uses the native hourly schedule without arguments.' );
check( $event['at'] >= $start + 3600 && $event['at'] <= time() + 3600, 'Installation does not immediately trigger a directory scan.' );
$GLOBALS['sws_test_options'][ STW_SWS_Customer_Refresh::ENABLED ] = false;
STW_SWS_Customer_Refresh::schedule();
check( false === wp_next_scheduled( STW_SWS_Customer_Refresh::HOOK ) && 1 === $GLOBALS['sws_test_clears'], 'Disable switch removes recurrence.' );
check( 'unavailable' === STW_SWS_Customer_Refresh::run()['state'], 'Disable switch also protects an already-dispatched callback.' );
check( 0 === $GLOBALS['wpdb']->acquisitions && array() === $GLOBALS['sws_test_native_requests'], 'Disabled callback touches neither lock nor provider.' );
foreach ( array( false, new WP_Error( 'cron_failure' ) ) as $failure ) {
	reset_fixture();
	$GLOBALS['sws_test_schedule_result'] = $failure;
	STW_SWS_Customer_Refresh::schedule();
	check( 'schedule_failed' === $GLOBALS['sws_test_options'][ STW_SWS_Customer_Refresh::RESULT ]['state'], 'Scheduling failure is recorded.' );
}

// Same native action as Refresh, without destructive rebuild or alternate jobs.
reset_fixture();
$GLOBALS['sws_test_options']['sws_customer_fetch_status'] = array( 'state' => 'completed', 'last_updated' => time() - 5000 );
$original_status = $GLOBALS['sws_test_options']['sws_customer_fetch_status'];
$result = STW_SWS_Customer_Refresh::run();
check( 'queued' === $result['state'] && 200 === $result['http_status'], 'Native success is recorded as queued, not completed.' );
check( 1 === count( $GLOBALS['sws_test_native_requests'] ), 'One scheduled call invokes one native Refresh.' );
$request = $GLOBALS['sws_test_native_requests'][0];
check( 'GET' === $request->get_method() && '/sws/v1/customers' === $request->get_route(), 'Refresh uses the existing customer route and method.' );
check( 'true' === $request->get_param( 'force' ), 'Force is the exact native string true.' );
check( null === $request->get_param( 'full' ), 'Full rebuild flag is absent.' );
check( $original_status === $GLOBALS['sws_test_options']['sws_customer_fetch_status'], 'Adapter does not rewrite native state/timestamps.' );
check( array( STW_SWS_Customer_Refresh::RESULT ) === array_column( $GLOBALS['sws_test_updates'], 0 ), 'Adapter persists only its own result option.' );
check( false === $GLOBALS['sws_test_updates'][0][2], 'Attempt telemetry does not autoload on storefront requests.' );
assert_unlocked( 'Success releases dispatch lock.' );

// Busy status remains authoritative even beyond the native 30-minute timeout.
foreach ( array( 'queuing', 'processing' ) as $state ) {
	foreach ( array( time(), time() - 86400 ) as $updated ) {
		reset_fixture();
		$GLOBALS['sws_test_options']['sws_customer_fetch_status'] = array( 'state' => $state, 'last_updated' => $updated );
		$result = STW_SWS_Customer_Refresh::run();
		check( 'skipped_refresh_in_progress' === $result['state'], 'Fresh/stale busy native jobs are skipped.' );
		check( array() === $GLOBALS['sws_test_native_requests'], 'Busy skip does not restart or clear the native scan.' );
		assert_unlocked( 'Busy skip releases dispatch lock.' );
	}
}
reset_fixture();
$GLOBALS['wpdb']->on_acquire = function () { $GLOBALS['sws_test_options']['sws_customer_fetch_status'] = array( 'state' => 'queuing' ); };
check( 'skipped_refresh_in_progress' === STW_SWS_Customer_Refresh::run()['state'], 'Status is read after winning the dispatch lock.' );
check( array() === $GLOBALS['sws_test_native_requests'], 'Competing enqueue observed under the lock prevents a second job.' );
assert_unlocked( 'Race skip releases the lock.' );

reset_fixture();
$GLOBALS['sws_test_option_cache']['sws_customer_fetch_status'] = array( 'state' => 'completed' );
$GLOBALS['sws_test_options']['sws_customer_fetch_status'] = array( 'state' => 'processing', 'last_updated' => time() );
check( 'completed' === get_option( 'sws_customer_fetch_status' )['state'], 'Fixture reproduces stale option cache.' );
check( 'skipped_refresh_in_progress' === STW_SWS_Customer_Refresh::run()['state'], 'Raw state under lock overrides stale cached completed status.' );
check( array() === $GLOBALS['sws_test_native_requests'], 'Stale option cache cannot start a duplicate scan.' );
assert_unlocked( 'Raw busy state releases the lock.' );

reset_fixture();
$GLOBALS['wpdb']->status_read_error = true;
check( 'invalid_native_status' === STW_SWS_Customer_Refresh::run()['state'], 'Database read error fails closed.' );
check( array() === $GLOBALS['sws_test_native_requests'], 'Unreadable native state does not queue.' );
assert_unlocked( 'Status read failure releases the lock.' );

reset_fixture();
$GLOBALS['sws_test_options']['sws_customer_fetch_status'] = 'corrupt-status';
check( 'invalid_native_status' === STW_SWS_Customer_Refresh::run()['state'], 'Malformed native status fails closed.' );
check( array() === $GLOBALS['sws_test_native_requests'], 'Malformed native state never queues a job.' );
assert_unlocked( 'Malformed status releases the lock.' );

// Native failures and exceptions are contained, reported and always unlock.
foreach ( array(
	new WP_Error( 'native_failure' ),
	null,
	new WP_REST_Response( array( 'success' => false ), 200 ),
	new WP_REST_Response( array( 'success' => true ), 500 ),
	new WP_REST_Response( array( 'success' => false ), 409 ),
	new \RuntimeException( 'Native refresh threw.' ),
) as $failure ) {
	reset_fixture();
	$GLOBALS['sws_test_native_callback'] = function () use ( $failure ) { if ( $failure instanceof \Throwable ) { throw $failure; } return $failure; };
	$result = STW_SWS_Customer_Refresh::run();
	$expected = $failure instanceof WP_REST_Response && 409 === $failure->get_status() ? 'skipped_refresh_in_progress' : 'native_refresh_failed';
	check( $expected === $result['state'], 'Native failure is not reported as queued.' );
	assert_unlocked( 'Native failure releases the lock.' );
}

// Ordinary reads, other endpoints, other methods and overridden responses remain untouched.
foreach ( array(
	refresh_request( 'GET', '/sws/v1/customers', null ),
	refresh_request( 'GET', '/sws/v1/customers', true ),
	refresh_request( 'POST' ),
	refresh_request( 'GET', '/sws/v1/orders' ),
	refresh_request( 'GET', '/cart-cat/v1/customers' ),
	new \stdClass(),
) as $unrelated ) {
	reset_fixture();
	check( null === STW_SWS_Customer_Refresh::before_manual_refresh( null, $unrelated, '', array() ), 'Unrelated request response is unchanged.' );
	check( 0 === $GLOBALS['wpdb']->acquisitions, 'Unrelated request takes no lock.' );
}
reset_fixture();
$override = new WP_REST_Response( array( 'already_handled' => true ) );
check( $override === STW_SWS_Customer_Refresh::before_manual_refresh( $override, refresh_request(), '', array() ), 'Another dispatch override is preserved.' );
check( 0 === $GLOBALS['wpdb']->acquisitions, 'Overridden callback takes no lock.' );

// Manual winner blocks scheduler; nested/other responses cannot release its lock.
reset_fixture();
$manual = refresh_request( 'GET', '/sws/v1/customers/' );
check( null === STW_SWS_Customer_Refresh::before_manual_refresh( null, $manual, '', array() ), 'Manual Refresh may dispatch while holding the lock.' );
check( 'this_request' === $GLOBALS['wpdb']->lock_owner, 'Manual callback owns dispatch lock.' );
check( 'skipped_dispatch_busy' === STW_SWS_Customer_Refresh::run()['state'], 'Scheduler skips while manual dispatch holds the lock.' );
check( array() === $GLOBALS['sws_test_native_requests'], 'Manual/scheduler race cannot enqueue a second job.' );
STW_SWS_Customer_Refresh::after_manual_refresh( null, array(), refresh_request() );
check( 'this_request' === $GLOBALS['wpdb']->lock_owner, 'Unrelated callback cannot release another request lock.' );
$native_error = new WP_Error( 'native_error' );
check( $native_error === STW_SWS_Customer_Refresh::after_manual_refresh( $native_error, array(), $manual ), 'Manual native error is preserved.' );
assert_unlocked( 'Manual completion/error releases dispatch lock.' );

// Scheduler winner blocks a simultaneous manual dispatch, including explicit rebuilds.
reset_fixture();
$GLOBALS['sws_test_native_callback'] = function () {
	$request = refresh_request();
	$request->set_param( 'full', 'true' );
	$blocked = STW_SWS_Customer_Refresh::before_manual_refresh( null, $request, '', array() );
	check( $blocked instanceof WP_Error && 'stw_sws_refresh_busy' === $blocked->code && 409 === $blocked->data['status'], 'Manual dispatch receives retryable conflict while scheduler queues.' );
	STW_SWS_Customer_Refresh::after_manual_refresh( $blocked, array(), $request );
	check( 'this_request' === $GLOBALS['wpdb']->lock_owner, 'Rejected manual callback cannot release scheduler lock.' );
	return new WP_REST_Response( array( 'success' => true ) );
};
check( 'queued' === STW_SWS_Customer_Refresh::run()['state'], 'Scheduler finishes its one native enqueue.' );
assert_unlocked( 'Scheduler releases lock after blocked manual attempt.' );

// A different PHP/database connection owning the lock blocks both entry points.
reset_fixture();
$GLOBALS['wpdb']->lock_owner = 'other_request';
check( 'skipped_dispatch_busy' === STW_SWS_Customer_Refresh::run()['state'], 'External lock owner blocks scheduler.' );
$blocked = STW_SWS_Customer_Refresh::before_manual_refresh( null, refresh_request(), '', array() );
check( $blocked instanceof WP_Error && 409 === $blocked->data['status'], 'External lock owner blocks manual enqueue.' );
STW_SWS_Customer_Refresh::unlock();
check( 'other_request' === $GLOBALS['wpdb']->lock_owner && 0 === $GLOBALS['wpdb']->releases, 'Cleanup never releases an external connection lock.' );
check( array() === $GLOBALS['sws_test_native_requests'], 'Contended lock never calls the provider.' );
$GLOBALS['wpdb']->lock_owner = null;

// Shutdown cleanup covers a manually dispatched callback that aborts before after_callbacks.
reset_fixture();
STW_SWS_Customer_Refresh::before_manual_refresh( null, refresh_request(), '', array() );
STW_SWS_Customer_Refresh::unlock();
STW_SWS_Customer_Refresh::unlock();
assert_unlocked( 'Shutdown cleanup is safe and idempotent.' );

echo "SWS customer refresh checks: {$assertions} passed.\n";

}
