<?php
/**
 * CLI smoke checks for Phase 6 Store API direct and batch guards.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['ceog_test_options'] = array();
$GLOBALS['ceog_user_roles']   = array( 'customer' );

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code, $message, $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function get_option( $key, $default = false ) {
	return $GLOBALS['ceog_test_options'][ $key ] ?? $default;
}

function update_option( $key, $value ) {
	$GLOBALS['ceog_test_options'][ $key ] = $value;
	return true;
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) );
}
function wp_unslash( $value ) { return $value; }

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_email( $value ) {
	return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? (string) $value : '';
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_salt() {
	return 'phase-6-test-salt';
}

function current_time( $type ) {
	return 'timestamp' === $type ? 1783706400 : '2026-07-11 12:00:00';
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function esc_url_raw( $value ) {
	return filter_var( $value, FILTER_SANITIZE_URL );
}

function delete_transient() {
	return true;
}

function apply_filters( $hook, $value ) {
	return $value;
}

function __( $text ) {
	return $text;
}

function wp_get_current_user() {
	return (object) array( 'roles' => $GLOBALS['ceog_user_roles'] );
}

function wc_get_page_id() {
	return 42;
}

function get_post() {
	return (object) array( 'ID' => 42 );
}

function has_block() {
	return false;
}

function is_admin() {
	return false;
}

function wp_doing_ajax() {
	return false;
}

function wp_doing_cron() {
	return false;
}

final class CEOG_Store_Test_Session {
	public $has_session = false;
	public $cookie_set = false;
	public $payment_method = '';

	public function has_session() {
		return $this->has_session;
	}

	public function set_customer_session_cookie( $set ) {
		$this->cookie_set = (bool) $set;
		$this->has_session = (bool) $set;
	}

	public function get( $key, $default = '' ) {
		return 'chosen_payment_method' === $key ? $this->payment_method : $default;
	}
}

final class CEOG_Store_Test_WooCommerce {
	public $session;

	public function __construct() {
		$this->session = new CEOG_Store_Test_Session();
	}
}

$GLOBALS['ceog_woocommerce'] = new CEOG_Store_Test_WooCommerce();

function WC() {
	return $GLOBALS['ceog_woocommerce'];
}

final class CEOG_Store_Test_WPDB {
	public $prefix = 'wp_';
	public $queries = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;

		return 1;
	}
}

final class CEOG_Store_Test_Request {
	private $route;
	private $method;
	private $params;
	private $body;

	public function __construct( $route, $method = 'POST', $params = array(), $body = array() ) {
		$this->route  = $route;
		$this->method = $method;
		$this->params = $params;
		$this->body   = $body;
	}

	public function get_route() {
		return $this->route;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}

	public function get_json_params() {
		return $this->body;
	}
}

$wpdb = new CEOG_Store_Test_WPDB();

require dirname( __DIR__ ) . '/includes/functions.php';
require dirname( __DIR__ ) . '/includes/class-ceog-ip.php';
require dirname( __DIR__ ) . '/includes/class-ceog-logger.php';
require dirname( __DIR__ ) . '/includes/class-ceog-settings.php';
require dirname( __DIR__ ) . '/includes/class-ceog-lists.php';
require dirname( __DIR__ ) . '/includes/class-ceog-store-api-guard.php';

function ceog_phase6_assert( $condition, $message ) {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

$_SERVER['REMOTE_ADDR']   = '203.0.113.50';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/wp-json/wc/store/v1/cart/add-item';
$GLOBALS['wp']             = (object) array( 'query_vars' => array( 'rest_route' => '/wc/store/v1/cart/add-item' ) );

$settings = ceog_get_default_settings();
$settings['mode']               = 'monitor';
$settings['strict_session']     = 'yes';
$settings['emergency_lockdown'] = 'yes';
$settings['whitelist_roles']    = array();
$settings['whitelist_ips']      = array();
$settings['whitelist_payment_methods'] = array();
$GLOBALS['ceog_test_options']['ceog_settings'] = $settings;
$GLOBALS['ceog_test_options']['ceog_strict_session_enabled'] = 'yes';

$settings_service = new CEOG_Settings();
$logger           = new CEOG_Logger();
$lists            = new CEOG_Lists( $settings_service, $logger );
$guard            = new CEOG_Store_API_Guard( $settings_service, $logger, $lists );
$defaults         = array( 'enabled' => false, 'proxy_support' => false, 'limit' => 25, 'seconds' => 10 );

$monitor_limits = $guard->configure_rate_limits( $defaults );
ceog_phase6_assert( false === $monitor_limits['enabled'], 'Monitor mode must not enable a blocking rate limiter.' );

$settings['mode'] = 'enforce';
$settings['rate_limit_limit']   = 12;
$settings['rate_limit_seconds'] = 8;
$settings['trusted_proxy']      = 'cloudflare';
$GLOBALS['ceog_test_options']['ceog_settings'] = $settings;
$enforce_limits = $guard->configure_rate_limits( $defaults );
ceog_phase6_assert( true === $enforce_limits['enabled'], 'Enforce mode must enable cart mutation rate limiting.' );
ceog_phase6_assert( 12 === $enforce_limits['limit'] && 8 === $enforce_limits['seconds'], 'Configured rate-limit values must reach WooCommerce.' );
ceog_phase6_assert( true === $enforce_limits['proxy_support'], 'Trusted proxy settings must enable WooCommerce proxy support.' );

$GLOBALS['wp']->query_vars['rest_route'] = '/wc/store/v1/checkout';
$checkout_limits = $guard->configure_rate_limits( $defaults );
ceog_phase6_assert( false === $checkout_limits['enabled'], 'Order Guard must not override WooCommerce checkout rate limiting.' );

$GLOBALS['wp']->query_vars['rest_route'] = '/wc/store/v1/batch';
$batch_limits = $guard->configure_rate_limits( $defaults );
ceog_phase6_assert( true === $batch_limits['enabled'], 'Batch envelopes must use the configured Store API limiter.' );

$settings['mode'] = 'monitor';
$GLOBALS['ceog_test_options']['ceog_settings'] = $settings;
$before_logs = count( $wpdb->queries );
$monitor_cart = $guard->intercept_request( null, null, new CEOG_Store_Test_Request( '/wc/store/v1/cart/add-item' ) );
ceog_phase6_assert( null === $monitor_cart, 'Monitor mode must allow sessionless cart mutations.' );
ceog_phase6_assert( count( $wpdb->queries ) === $before_logs + 1, 'Monitor mode must log a sessionless cart mutation.' );

$settings['mode'] = 'enforce';
$GLOBALS['ceog_test_options']['ceog_settings'] = $settings;
$blocked_cart = $guard->intercept_request( null, null, new CEOG_Store_Test_Request( '/wc/store/v1/cart/add-item' ) );
ceog_phase6_assert( is_wp_error( $blocked_cart ) && 403 === $blocked_cart->data['status'], 'Enforce mode must reject sessionless cart mutations with 403.' );

$GLOBALS['ceog_woocommerce']->session->has_session = true;
$before_logs = count( $wpdb->queries );
$allowed_cart = $guard->intercept_request( null, null, new CEOG_Store_Test_Request( '/wc/store/v1/cart/add-item' ) );
ceog_phase6_assert( null === $allowed_cart && count( $wpdb->queries ) === $before_logs, 'An existing WooCommerce session must pass strict-session checks.' );

$GLOBALS['ceog_woocommerce']->session->has_session = false;
$settings['whitelist_ips'] = array( '203.0.113.50' );
$GLOBALS['ceog_test_options']['ceog_settings'] = $settings;
$before_logs = count( $wpdb->queries );
$whitelisted_cart = $guard->intercept_request( null, null, new CEOG_Store_Test_Request( '/wc/store/v1/cart/add-item' ) );
ceog_phase6_assert( null === $whitelisted_cart && count( $wpdb->queries ) === $before_logs, 'Whitelist precedence must bypass Store API rules.' );

$settings['whitelist_ips'] = array();
$GLOBALS['ceog_test_options']['ceog_settings'] = $settings;
$blocked_checkout = $guard->intercept_request( null, null, new CEOG_Store_Test_Request( '/wc/store/v1/checkout' ) );
ceog_phase6_assert( is_wp_error( $blocked_checkout ) && 404 === $blocked_checkout->data['status'], 'Emergency Lockdown must return 404 for direct checkout.' );

$batch_request = new CEOG_Store_Test_Request(
	'/wc/store/v1/batch',
	'POST',
	array(
		'requests' => array(
			array( 'method' => 'POST', 'path' => '/wc/store/v1/cart/add-item', 'body' => array() ),
			array( 'method' => 'POST', 'path' => '/wc/store/v1/checkout', 'body' => array() ),
		),
	)
);
$before_logs = count( $wpdb->queries );
$blocked_batch = $guard->intercept_request( null, null, $batch_request );
ceog_phase6_assert( is_wp_error( $blocked_batch ) && 403 === $blocked_batch->data['status'], 'Any enforced batch violation must reject the entire batch.' );
ceog_phase6_assert( count( $wpdb->queries ) === $before_logs + 2, 'Each violating embedded operation must be logged separately.' );

$malformed = new CEOG_Store_Test_Request(
	'/wc/store/v1/batch',
	'POST',
	array( 'requests' => array( array( 'method' => 'POST' ) ) )
);
$malformed_result = $guard->intercept_request( null, null, $malformed );
ceog_phase6_assert( is_wp_error( $malformed_result ) && 400 === $malformed_result->data['status'], 'Malformed batch operations must return 400 in Enforce mode.' );

$before_logs = count( $wpdb->queries );
$get_result = $guard->intercept_request( null, null, new CEOG_Store_Test_Request( '/wc/store/v1/cart', 'GET' ) );
$other_result = $guard->intercept_request( null, null, new CEOG_Store_Test_Request( '/wp/v2/posts', 'POST' ) );
ceog_phase6_assert( null === $get_result && null === $other_result && count( $wpdb->queries ) === $before_logs, 'GET and non-Store routes must remain untouched.' );

$GLOBALS['ceog_woocommerce']->session->has_session = false;
$GLOBALS['ceog_woocommerce']->session->cookie_set  = false;
$guard->ensure_frontend_session();
ceog_phase6_assert( $GLOBALS['ceog_woocommerce']->session->cookie_set, 'Strict Session must prepare a cookie on normal front-end page views.' );

define( 'CEOG_SAFE_MODE', true );
$GLOBALS['ceog_woocommerce']->session->has_session = false;
$safe_checkout = $guard->intercept_request( null, null, new CEOG_Store_Test_Request( '/wc/store/v1/checkout' ) );
ceog_phase6_assert( null === $safe_checkout, 'Safe Mode must force Emergency Lockdown into monitor behavior.' );

echo "Phase 6 Store API direct and batch smoke tests passed.\n";
