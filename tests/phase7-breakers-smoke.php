<?php
/**
 * CLI smoke checks for Phase 7 tiered circuit breakers.
 *
 * @package CoderEmbassy_Order_Guard
 */

namespace Automattic\WooCommerce\StoreApi\Exceptions {
	class RouteException extends \Exception {
		public $error_code;
		public $status;

		public function __construct( $error_code, $message, $status ) {
			parent::__construct( $message );
			$this->error_code = $error_code;
			$this->status     = $status;
		}
	}
}

namespace {
	if ( 'cli' !== PHP_SAPI ) {
		exit( 1 );
	}

	define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
	define( 'DAY_IN_SECONDS', 86400 );
	$GLOBALS['ceog_options']    = array();
	$GLOBALS['ceog_transients'] = array();
	$GLOBALS['ceog_now']        = 1783792800;
	$GLOBALS['ceog_notices']    = array();

	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code, $message, $data = array() ) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
	}

	function get_option( $key, $default = false ) { return $GLOBALS['ceog_options'][ $key ] ?? $default; }
	function update_option( $key, $value ) { $GLOBALS['ceog_options'][ $key ] = $value; return true; }
	function get_transient( $key ) { return $GLOBALS['ceog_transients'][ $key ] ?? false; }
	function set_transient( $key, $value ) { $GLOBALS['ceog_transients'][ $key ] = $value; return true; }
	function delete_transient( $key ) { unset( $GLOBALS['ceog_transients'][ $key ] ); return true; }
	function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
	function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? strtolower( (string) $value ) : ''; }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_salt() { return 'phase-7-test-salt'; }
	function current_time( $type ) { return 'timestamp' === $type ? $GLOBALS['ceog_now'] : '2026-07-11 18:00:00'; }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function esc_url_raw( $value ) { return filter_var( $value, FILTER_SANITIZE_URL ); }
	function apply_filters( $hook, $value ) { return 'ceog_breaker_now' === $hook ? $GLOBALS['ceog_now'] : $value; }
	function __( $text ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function wp_unslash( $value ) { return $value; }
	function wp_get_current_user() { return (object) array( 'roles' => array( 'customer' ) ); }
	function get_userdata( $user_id ) { return $user_id ? (object) array( 'roles' => array( 'customer' ) ) : false; }
	function wc_get_page_id() { return 42; }
	function get_post() { return (object) array( 'ID' => 42 ); }
	function has_block() { return false; }
	function wc_add_notice( $message, $type ) { $GLOBALS['ceog_notices'][] = array( $message, $type ); }

	final class CEOG_Phase7_WPDB {
		public $prefix = 'wp_';
		public $queries = array();

		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
				$query = preg_replace( '/%[sd]/', $replacement, $query, 1 );
			}
			return $query;
		}

		public function query( $sql ) { $this->queries[] = $sql; return 1; }
	}

	final class CEOG_Phase7_Order {
		private $id;
		private $ip;
		private $email;
		private $payment;
		public $meta = array();

		public function __construct( $id, $ip, $email, $payment = 'stripe' ) {
			$this->id = $id;
			$this->ip = $ip;
			$this->email = $email;
			$this->payment = $payment;
		}
		public function get_id() { return $this->id; }
		public function get_customer_ip_address() { return $this->ip; }
		public function get_billing_email() { return $this->email; }
		public function get_payment_method() { return $this->payment; }
		public function get_user_id() { return 0; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function save_meta_data() {}
	}

	final class CEOG_Phase7_Request {
		private $route;
		private $method;
		private $body;
		private $params;

		public function __construct( $route, $body = array(), $params = array(), $method = 'POST' ) {
			$this->route = $route;
			$this->body = $body;
			$this->params = $params;
			$this->method = $method;
		}
		public function get_route() { return $this->route; }
		public function get_method() { return $this->method; }
		public function get_json_params() { return $this->body; }
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	}

	$wpdb = new CEOG_Phase7_WPDB();
	require dirname( __DIR__ ) . '/includes/functions.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-ip.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-logger.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-settings.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-lists.php';
	require dirname( __DIR__ ) . '/includes/class-ceog-breakers.php';

	function ceog_phase7_assert( $condition, $message ) {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	function ceog_phase7_reset( $overrides = array() ) {
		$GLOBALS['ceog_transients'] = array();
		$GLOBALS['ceog_notices'] = array();
		$settings = array_merge(
			ceog_get_default_settings(),
			array(
				'mode' => 'enforce',
				'breaker_ip_threshold' => 2,
				'breaker_email_threshold' => 2,
				'breaker_global_threshold' => 100,
				'breaker_ip_window' => 300,
				'breaker_email_window' => 300,
				'breaker_global_window' => 300,
				'breaker_ip_block' => 60,
				'breaker_email_block' => 90,
				'breaker_global_cooldown' => 120,
				'whitelist_roles' => array(),
				'whitelist_ips' => array(),
				'whitelist_payment_methods' => array(),
			),
			$overrides
		);
		$GLOBALS['ceog_options']['ceog_settings'] = $settings;
	}

	$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';
	$settings_service = new CEOG_Settings();
	$logger = new CEOG_Logger();
	$lists = new CEOG_Lists( $settings_service, $logger );
	$breakers = new CEOG_Breakers( $settings_service, $logger, $lists );

	ceog_phase7_reset();
	$breakers->record_failed_order( 1, new CEOG_Phase7_Order( 1, '2001:db8:1:2::1', 'one@example.com' ) );
	$breakers->record_failed_order( 2, new CEOG_Phase7_Order( 2, '2001:db8:1:2:ffff::9', 'two@example.com' ) );
	$status = $breakers->get_status_payload();
	ceog_phase7_assert( 'cooling_down' === $status['tiers']['ip']['state'], 'One IPv6 /64 must feed one per-IP breaker.' );
	ceog_phase7_assert( 1 === $status['tiers']['ip']['activeEntities'], 'Status must expose only an aggregate active count.' );
	$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:abcd::8';
	$_POST = array( 'billing_email' => 'fresh@example.com', 'payment_method' => 'stripe' );
	$breakers->validate_classic_checkout();
	ceog_phase7_assert( 1 === count( $GLOBALS['ceog_notices'] ), 'Matching per-IP trips must block classic checkout.' );
	$_SERVER['REMOTE_ADDR'] = '2001:db8:9:9::1';
	$GLOBALS['ceog_notices'] = array();
	$breakers->validate_classic_checkout();
	ceog_phase7_assert( empty( $GLOBALS['ceog_notices'] ), 'A different IPv6 /64 must remain open.' );

	ceog_phase7_reset();
	$breakers->record_failed_order( 3, new CEOG_Phase7_Order( 3, '198.51.100.1', 'same@example.com' ) );
	$breakers->record_failed_order( 4, new CEOG_Phase7_Order( 4, '198.51.100.2', 'same@example.com' ) );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
	$email_request = new CEOG_Phase7_Request( '/wc/store/v1/checkout', array( 'billing_address' => array( 'email' => 'same@example.com' ) ) );
	$email_result = $breakers->intercept_store_api_checkout( null, null, $email_request );
	ceog_phase7_assert( $email_result instanceof WP_Error && 503 === $email_result->data['status'], 'Per-email trips must follow an email across rotating IPs.' );

	ceog_phase7_reset( array( 'breaker_global_threshold' => 3 ) );
	$breakers->record_failed_order( 5, new CEOG_Phase7_Order( 5, '192.0.2.1', 'a@example.com' ) );
	$breakers->record_failed_order( 6, new CEOG_Phase7_Order( 6, '192.0.2.2', 'b@example.com' ) );
	$breakers->record_failed_order( 7, new CEOG_Phase7_Order( 7, '192.0.2.3', 'c@example.com' ) );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.200';
	$global_request = new CEOG_Phase7_Request( '/wc/store/v1/checkout', array( 'billing_address' => array( 'email' => 'new@example.com' ), 'payment_method' => 'stripe' ) );
	$global_result = $breakers->intercept_store_api_checkout( null, null, $global_request );
	ceog_phase7_assert( $global_result instanceof WP_Error && 503 === $global_result->data['status'], 'Distributed failures must trip the global breaker.' );
	$GLOBALS['ceog_options']['ceog_settings']['whitelist_payment_methods'] = array( 'paypal' );
	$wallet = new CEOG_Phase7_Request( '/wc/store/v1/checkout', array( 'billing_address' => array( 'email' => 'new@example.com' ), 'payment_method' => 'paypal' ) );
	ceog_phase7_assert( null === $breakers->intercept_store_api_checkout( null, null, $wallet ), 'Whitelisted wallets must bypass a global trip.' );

	$GLOBALS['ceog_options']['ceog_settings']['whitelist_payment_methods'] = array();
	$batch = new CEOG_Phase7_Request(
		'/wc/store/v1/batch',
		array(),
		array( 'requests' => array( array( 'method' => 'POST', 'path' => '/wc/store/v1/checkout', 'body' => array( 'billing_address' => array( 'email' => 'batch@example.com' ) ) ) ) )
	);
	$batch_result = $breakers->intercept_store_api_checkout( null, null, $batch );
	ceog_phase7_assert( $batch_result instanceof WP_Error && 503 === $batch_result->data['status'], 'Batch-wrapped checkout must not bypass the global breaker.' );
	$GLOBALS['ceog_options']['ceog_settings']['mode'] = 'monitor';
	$before_logs = count( $wpdb->queries );
	ceog_phase7_assert( null === $breakers->intercept_store_api_checkout( null, null, $global_request ), 'Monitor mode must allow matched checkout.' );
	ceog_phase7_assert( count( $wpdb->queries ) === $before_logs + 1, 'Monitor mode must log the checkout it would pause.' );
	$GLOBALS['ceog_options']['ceog_settings']['mode'] = 'enforce';
	$GLOBALS['ceog_now'] += 121;
	ceog_phase7_assert( null === $breakers->intercept_store_api_checkout( null, null, $global_request ), 'Global checkout must reopen after cooldown.' );
	ceog_phase7_assert( 'ready' === $breakers->get_status_payload()['tiers']['global']['state'], 'Expired global state must return to Ready.' );

	ceog_phase7_reset( array( 'blocklist_emails' => array( 'blocked@example.com' ) ) );
	$blocked_order = new CEOG_Phase7_Order( 8, '203.0.113.40', 'blocked@example.com' );
	$caught = false;
	try {
		$lists->validate_store_api_order( $blocked_order );
	} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $exception ) {
		$caught = 403 === $exception->status && 'ceog_blocklist' === $exception->error_code;
	}
	ceog_phase7_assert( $caught, 'Store API blocklist matches must stop before payment with 403.' );
	ceog_phase7_assert( 'email' === $blocked_order->meta['_ceog_list_match'], 'Store API list matches must be recorded on the order.' );

	ceog_phase7_reset( array( 'whitelist_ips' => array( '203.0.113.50' ) ) );
	$breakers->record_failed_order( 9, new CEOG_Phase7_Order( 9, '203.0.113.50', 'trusted@example.com' ) );
	ceog_phase7_assert( empty( $GLOBALS['ceog_transients'] ), 'Whitelisted failed orders must not feed counters.' );
	ceog_phase7_reset( array( 'breaker_global_threshold' => 1 ) );
	$breakers->record_failed_order( 10, new CEOG_Phase7_Order( 10, '203.0.113.60', 'safe@example.com' ) );
	define( 'CEOG_SAFE_MODE', true );
	ceog_phase7_assert( null === $breakers->intercept_store_api_checkout( null, null, $global_request ), 'Safe Mode must force breaker matches into log-only behavior.' );

	echo "Phase 7 tiered circuit breaker smoke tests passed.\n";
}
