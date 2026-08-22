<?php
/**
 * CLI release gate proving CEOG_SAFE_MODE disables every blocking path.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'CEOG_FILE', dirname( __DIR__ ) . '/coderembassy-order-guard.php' );
define( 'CEOG_SAFE_MODE', true );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['ceog_options']    = array();
$GLOBALS['ceog_transients'] = array();
$GLOBALS['ceog_notices']    = array();
$GLOBALS['ceog_now']        = 1783792800;

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code, $message, $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}

function get_option( $key, $default = false ) { return $GLOBALS['ceog_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { unset( $autoload ); $GLOBALS['ceog_options'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['ceog_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $expiration = 0 ) { unset( $expiration ); $GLOBALS['ceog_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['ceog_transients'][ $key ] ); return true; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? strtolower( (string) $value ) : ''; }
function absint( $value ) { return abs( (int) $value ); }
function wp_salt( $scheme = 'auth' ) { return 'safe-mode-' . $scheme . '-test-salt'; }
function current_time( $type ) { return 'timestamp' === $type ? $GLOBALS['ceog_now'] : '2026-07-11 18:00:00'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function wp_unslash( $value ) { return $value; }
function __( $text ) { return $text; }
function esc_html_e( $text ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function apply_filters( $hook, $value ) { return 'ceog_breaker_now' === $hook ? $GLOBALS['ceog_now'] : $value; }
function wp_get_current_user() { return (object) array( 'roles' => array() ); }
function get_userdata() { return false; }
function current_user_can( $capability ) { return 'manage_woocommerce' === $capability; }
function wc_get_page_id() { return 42; }
function get_post() { return (object) array( 'ID' => 42 ); }
function has_block() { return false; }
function wc_add_notice( $message, $type ) { $GLOBALS['ceog_notices'][] = array( $message, $type ); }

final class CEOG_Safe_Mode_Session {
	public function has_session() { return false; }
	public function get( $key, $default = '' ) { unset( $key ); return $default; }
}
final class CEOG_Safe_Mode_WC { public $session; public function __construct() { $this->session = new CEOG_Safe_Mode_Session(); } }
$GLOBALS['ceog_wc'] = new CEOG_Safe_Mode_WC();
function WC() { return $GLOBALS['ceog_wc']; }

final class CEOG_Safe_Mode_WPDB {
	public $prefix = 'wp_';
	public $queries = array();
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) { $query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 ); }
		return $query;
	}
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
}

final class CEOG_Safe_Mode_Request {
	private $route;
	private $params;
	private $body;
	public function __construct( $route, $params = array(), $body = array() ) { $this->route = $route; $this->params = $params; $this->body = $body; }
	public function get_method() { return 'POST'; }
	public function get_route() { return $this->route; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	public function get_json_params() { return $this->body; }
}

final class CEOG_Safe_Mode_Errors {
	public $items = array();
	public function add( $code, $message ) { $this->items[] = array( $code, $message ); }
}

$wpdb = new CEOG_Safe_Mode_WPDB();
require dirname( __DIR__ ) . '/includes/functions.php';
require dirname( __DIR__ ) . '/includes/class-ceog-ip.php';
require dirname( __DIR__ ) . '/includes/class-ceog-logger.php';
require dirname( __DIR__ ) . '/includes/class-ceog-settings.php';
require dirname( __DIR__ ) . '/includes/class-ceog-lists.php';
require dirname( __DIR__ ) . '/includes/class-ceog-store-api-guard.php';
require dirname( __DIR__ ) . '/includes/class-ceog-breakers.php';
require dirname( __DIR__ ) . '/includes/class-ceog-honeypot.php';
require dirname( __DIR__ ) . '/includes/class-ceog-plugin.php';

function ceog_safe_mode_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.55';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/checkout';
$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/wc/store/v1/cart/add-item' ) );
$settings = array_merge(
	ceog_get_default_settings(),
	array(
		'mode' => 'enforce', 'strict_session' => 'yes', 'emergency_lockdown' => 'yes',
		'blocklist_emails' => array( 'blocked@example.com' ), 'whitelist_roles' => array(),
		'whitelist_ips' => array(), 'whitelist_payment_methods' => array(),
	)
);
$GLOBALS['ceog_options']['ceog_settings'] = $settings;
$settings_service = new CEOG_Settings();
$logger = new CEOG_Logger();
$lists = new CEOG_Lists( $settings_service, $logger );
$guard = new CEOG_Store_API_Guard( $settings_service, $logger, $lists );
$breakers = new CEOG_Breakers( $settings_service, $logger, $lists );
$honeypot = new CEOG_Honeypot( $settings_service, $logger );

ceog_safe_mode_assert( ceog_is_safe_mode() && ! ceog_is_enforcing(), 'Safe Mode must override a stored Enforce setting.' );

$errors = new CEOG_Safe_Mode_Errors();
$lists->validate_classic_checkout( array( 'billing_email' => 'blocked@example.com', 'payment_method' => 'bacs' ), $errors );
ceog_safe_mode_assert( empty( $errors->items ), 'Safe Mode must disable classic blocklist enforcement.' );

$cart = new CEOG_Safe_Mode_Request( '/wc/store/v1/cart/add-item' );
$checkout = new CEOG_Safe_Mode_Request( '/wc/store/v1/checkout' );
$batch = new CEOG_Safe_Mode_Request( '/wc/store/v1/batch', array( 'requests' => array( array( 'method' => 'POST', 'path' => '/wc/store/v1/checkout' ) ) ) );
ceog_safe_mode_assert( null === $guard->intercept_request( null, null, $cart ), 'Safe Mode must disable Strict Session blocking.' );
ceog_safe_mode_assert( null === $guard->intercept_request( null, null, $checkout ), 'Safe Mode must disable Emergency Lockdown.' );
ceog_safe_mode_assert( null === $guard->intercept_request( null, null, $batch ), 'Safe Mode must disable batch-wrapped lockdown.' );
$limits = $guard->configure_rate_limits( array( 'enabled' => false, 'limit' => 25, 'seconds' => 10 ) );
ceog_safe_mode_assert( false === $limits['enabled'], 'Safe Mode must not enable WooCommerce blocking rate limits.' );

$field = $honeypot->get_field_name();
$_POST = array( $field => 'filled' );
$honeypot->validate_checkout();
ceog_safe_mode_assert( empty( $GLOBALS['ceog_notices'] ), 'Safe Mode must disable honeypot enforcement.' );

$GLOBALS['ceog_transients']['ceog_breaker_until'] = $GLOBALS['ceog_now'] + 120;
$_POST = array( 'billing_email' => 'guest@example.com', 'payment_method' => 'bacs' );
$breakers->validate_classic_checkout();
ceog_safe_mode_assert( empty( $GLOBALS['ceog_notices'] ), 'Safe Mode must disable classic breaker enforcement.' );
ceog_safe_mode_assert( null === $breakers->intercept_store_api_checkout( null, null, $checkout ), 'Safe Mode must disable Store API breaker enforcement.' );

ceog_safe_mode_assert( count( $wpdb->queries ) >= 5, 'Safe Mode must continue logging monitor decisions.' );
foreach ( $wpdb->queries as $query ) {
	ceog_safe_mode_assert( false !== strpos( $query, "'monitor'" ), 'Every Safe Mode audit event must be stored as Monitor.' );
}

ob_start();
CEOG_Plugin::instance()->render_safe_mode_notice();
$notice = ob_get_clean();
ceog_safe_mode_assert( false !== strpos( $notice, 'Safe Mode is active' ), 'Safe Mode must show its persistent admin notice.' );

fwrite( STDOUT, "Phase 11 Safe Mode release gates passed.\n" );
