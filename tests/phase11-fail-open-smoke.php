<?php
/**
 * CLI release gate for missing storage and thrown dependency failures.
 *
 * @package CoderEmbassy_Order_Guard
 */

namespace Automattic\WooCommerce\StoreApi\Exceptions {
	class RouteException extends \Exception {}
}

namespace {
	if ( 'cli' !== PHP_SAPI ) {
		exit( 1 );
	}

	define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
	define( 'CEOG_FILE', dirname( __DIR__ ) . '/coderembassy-order-guard.php' );
	define( 'DAY_IN_SECONDS', 86400 );
	$GLOBALS['ceog_options']          = array();
	$GLOBALS['ceog_transients']       = array();
	$GLOBALS['ceog_notices']          = array();
	$GLOBALS['ceog_wc_errors']        = array();
	$GLOBALS['ceog_throw_option']     = false;
	$GLOBALS['ceog_throw_transient']  = false;
	$GLOBALS['ceog_log_table_exists'] = false;
	$GLOBALS['ceog_now']              = 1783792800;

	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	}

	final class CEOG_Phase11_WC_Logger {
		public function error( $message, $context = array() ) { $GLOBALS['ceog_wc_errors'][] = array( $message, $context ); }
	}

	function wc_get_logger() { return new CEOG_Phase11_WC_Logger(); }
	function get_option( $key, $default = false ) {
		if ( $GLOBALS['ceog_throw_option'] && 'ceog_settings' === $key ) { throw new \RuntimeException( 'Injected settings read failure.' ); }
		return $GLOBALS['ceog_options'][ $key ] ?? $default;
	}
	function update_option( $key, $value, $autoload = null ) { unset( $autoload ); $GLOBALS['ceog_options'][ $key ] = $value; return true; }
	function get_transient( $key ) {
		if ( $GLOBALS['ceog_throw_transient'] && 0 === strpos( $key, 'ceog_' ) ) { throw new \RuntimeException( 'Injected transient failure.' ); }
		return $GLOBALS['ceog_transients'][ $key ] ?? false;
	}
	function set_transient( $key, $value, $expiration = 0 ) { unset( $expiration ); $GLOBALS['ceog_transients'][ $key ] = $value; return true; }
	function delete_transient( $key ) { unset( $GLOBALS['ceog_transients'][ $key ] ); return true; }
	function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
	function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? strtolower( (string) $value ) : ''; }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_salt( $scheme = 'auth' ) { return 'phase-11-' . $scheme . '-test-salt'; }
	function current_time( $type ) { return 'timestamp' === $type ? $GLOBALS['ceog_now'] : '2026-07-11 18:00:00'; }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
	function wp_unslash( $value ) { return $value; }
	function __( $text ) { return $text; }
	function apply_filters( $hook, $value ) { return 'ceog_breaker_now' === $hook ? $GLOBALS['ceog_now'] : $value; }
	function wp_get_current_user() { return (object) array( 'roles' => array() ); }
	function get_userdata() { return false; }
	function wc_get_page_id() { return 42; }
	function get_post() { return (object) array( 'ID' => 42 ); }
	function has_block() { return false; }
	function wc_add_notice( $message, $type ) { $GLOBALS['ceog_notices'][] = array( $message, $type ); }
	function wc_get_orders() { return array(); }

	final class CEOG_Phase11_Session {
		public function has_session() { return false; }
		public function get( $key, $default = '' ) { unset( $key ); return $default; }
	}
	final class CEOG_Phase11_WC { public $session; public function __construct() { $this->session = new CEOG_Phase11_Session(); } }
	$GLOBALS['ceog_wc'] = new CEOG_Phase11_WC();
	function WC() { return $GLOBALS['ceog_wc']; }

	final class CEOG_Phase11_WPDB {
		public $prefix = 'wp_';
		public function prepare( $query, ...$args ) {
			foreach ( $args as $arg ) { $query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 ); }
			return $query;
		}
		public function query( $sql ) { unset( $sql ); return $GLOBALS['ceog_log_table_exists'] ? 1 : false; }
	}

	final class CEOG_Phase11_Request {
		private $route;
		private $params;
		private $body;
		private $throw_body;
		public function __construct( $route, $params = array(), $body = array(), $throw_body = false ) { $this->route = $route; $this->params = $params; $this->body = $body; $this->throw_body = $throw_body; }
		public function get_method() { return 'POST'; }
		public function get_route() { return $this->route; }
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
		public function get_json_params() { if ( $this->throw_body ) { throw new \RuntimeException( 'Injected body failure.' ); } return $this->body; }
	}

	final class CEOG_Phase11_Errors {
		public $items = array();
		public function add( $code, $message ) { $this->items[] = array( $code, $message ); }
	}

	final class CEOG_Phase11_Order {
		public $meta = array();
		public $notes = array();
		public $status = 'pending';
		public function get_id() { return 91; }
		public function get_customer_id() { return 0; }
		public function is_paid() { return false; }
		public function get_billing_email() { return 'new@example.com'; }
		public function get_payment_method() { return 'bacs'; }
		public function get_customer_ip_address() { return '203.0.113.44'; }
		public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function add_order_note( $note ) { $this->notes[] = $note; }
		public function set_status( $status ) { $this->status = $status; }
		public function save() {}
	}

	$wpdb = new CEOG_Phase11_WPDB();
	require dirname( __DIR__ ) . '/includes/functions.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-ip.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-logger.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-settings.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-lists.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-store-api-guard.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-breakers.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-honeypot.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-origin-rules.php';

	function ceog_phase11_fail_open_assert( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	}

	$_SERVER['REMOTE_ADDR'] = '203.0.113.44';
	$_SERVER['REQUEST_URI'] = '/checkout';
	$settings = array_merge(
		ceog_get_default_settings(),
		array(
			'mode' => 'enforce', 'strict_session' => 'yes', 'emergency_lockdown' => 'yes',
			'blocklist_emails' => array( 'blocked@example.com' ), 'whitelist_roles' => array(),
			'whitelist_ips' => array(), 'whitelist_payment_methods' => array(),
			'unknown_origin_onhold' => 'yes',
		)
	);
	$GLOBALS['ceog_options']['ceog_settings'] = $settings;
	$settings_service = new CEOG_Settings();
	$logger = new CEOG_Logger();
	$lists = new CEOG_Lists( $settings_service, $logger );
	$guard = new CEOG_Store_API_Guard( $settings_service, $logger, $lists );
	$breakers = new CEOG_Breakers( $settings_service, $logger, $lists );
	$honeypot = new CEOG_Honeypot( $settings_service, $logger );
	$origin = new CEOG_Origin_Rules( $settings_service, $logger );

	$errors = new CEOG_Phase11_Errors();
	$lists->validate_classic_checkout( array( 'billing_email' => 'blocked@example.com', 'payment_method' => 'bacs' ), $errors );
	ceog_phase11_fail_open_assert( empty( $errors->items ), 'A missing log table must fail open for classic blocklists.' );

	$checkout = new CEOG_Phase11_Request( '/wc/store/v1/checkout' );
	ceog_phase11_fail_open_assert( null === $guard->intercept_request( null, null, $checkout ), 'A missing log table must fail open for Emergency Lockdown.' );
	$cart = new CEOG_Phase11_Request( '/wc/store/v1/cart/add-item' );
	ceog_phase11_fail_open_assert( null === $guard->intercept_request( null, null, $cart ), 'A missing log table must fail open for Strict Session.' );
	$batch = new CEOG_Phase11_Request( '/wc/store/v1/batch', array( 'requests' => array( array( 'method' => 'POST', 'path' => '/wc/store/v1/checkout' ) ) ) );
	ceog_phase11_fail_open_assert( null === $guard->intercept_request( null, null, $batch ), 'A missing log table must fail open for batch-wrapped checkout.' );

	$field = $honeypot->get_field_name();
	$_POST = array( $field => 'filled' );
	$honeypot->validate_checkout();
	ceog_phase11_fail_open_assert( empty( $GLOBALS['ceog_notices'] ), 'A missing log table must fail open for the honeypot.' );

	$GLOBALS['ceog_transients']['ceog_breaker_until'] = $GLOBALS['ceog_now'] + 120;
	$_POST = array( 'billing_email' => 'new@example.com', 'payment_method' => 'bacs' );
	$breakers->validate_classic_checkout();
	ceog_phase11_fail_open_assert( empty( $GLOBALS['ceog_notices'] ), 'A missing log table must fail open for a tripped classic breaker.' );
	ceog_phase11_fail_open_assert( null === $breakers->intercept_store_api_checkout( null, null, $checkout ), 'A missing log table must fail open for a tripped Store API breaker.' );

	$order = new CEOG_Phase11_Order();
	ceog_phase11_fail_open_assert( false === $origin->evaluate_order( $order, 'store_api' ), 'A missing log table must suppress origin-side effects.' );
	ceog_phase11_fail_open_assert( empty( $order->meta ) && empty( $order->notes ) && 'pending' === $order->status, 'Origin fail-open must leave the order untouched.' );
	ceog_phase11_fail_open_assert( count( $GLOBALS['ceog_wc_errors'] ) >= 7, 'Each storage failure must reach the WooCommerce logger.' );

	$GLOBALS['ceog_options']['ceog_settings'] = 'corrupted';
	ceog_phase11_fail_open_assert( ! ceog_is_enforcing(), 'Corrupted settings must fall back to Monitor mode.' );
	ceog_phase11_fail_open_assert( null === $guard->intercept_request( null, null, $checkout ), 'Corrupted settings must not block checkout.' );

	$GLOBALS['ceog_throw_option'] = true;
	ceog_phase11_fail_open_assert( ! ceog_is_enforcing(), 'A thrown settings read must fail open.' );
	ceog_phase11_fail_open_assert( null === $guard->intercept_request( null, null, $checkout ), 'A thrown settings read must not escape a protection layer.' );
	$GLOBALS['ceog_throw_option'] = false;
	$GLOBALS['ceog_options']['ceog_settings'] = $settings;

	$GLOBALS['ceog_log_table_exists'] = true;
	$throwing_request = new CEOG_Phase11_Request( '/wc/store/v1/checkout', array(), array(), true );
	ceog_phase11_fail_open_assert( null === $guard->intercept_request( null, null, $throwing_request ), 'A thrown request-body exception must fail open.' );
	$GLOBALS['ceog_throw_transient'] = true;
	ceog_phase11_fail_open_assert( null === $breakers->intercept_store_api_checkout( null, null, $checkout ), 'A thrown breaker-state exception must fail open.' );

	fwrite( STDOUT, "Phase 11 fail-open release gates passed.\n" );
}
