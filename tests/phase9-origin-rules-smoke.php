<?php
/**
 * CLI smoke checks for Phase 9 unknown-origin order review signals.
 *
 * @package CoderEmbassy_Order_Vanguard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'CEOG_FILE', dirname( __DIR__ ) . '/coderembassy-order-vanguard.php' );
$GLOBALS['ceog_options']            = array();
$GLOBALS['ceog_hooks']              = array();
$GLOBALS['ceog_completed_by_email'] = array();
$GLOBALS['ceog_order_queries']      = array();

function get_option( $key, $default = false ) { return $GLOBALS['ceog_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { unset( $autoload ); $GLOBALS['ceog_options'][ $key ] = $value; return true; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? strtolower( (string) $value ) : ''; }
function absint( $value ) { return abs( (int) $value ); }
function wp_salt( $scheme = 'auth' ) { return 'phase-9-' . $scheme . '-test-salt'; }
function current_time( $type ) { return 'timestamp' === $type ? 1783792800 : '2026-07-11 18:00:00'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function __( $text ) { return $text; }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ceog_hooks'][ $hook ] = array( $callback, $priority, $accepted_args );
}
function wc_get_orders( $args ) {
	$GLOBALS['ceog_order_queries'][] = $args;
	$email = $args['billing_email'] ?? '';
	return $GLOBALS['ceog_completed_by_email'][ $email ] ?? array();
}

final class CEOG_Phase9_WPDB {
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

final class CEOG_Phase9_Order {
	private $id;
	private $customer_id;
	private $paid;
	private $email;
	private $payment_method;
	private $ip;
	public $meta = array();
	public $notes = array();
	public $status = 'pending';
	public $save_count = 0;

	public function __construct( $id, $args = array() ) {
		$this->id             = $id;
		$this->customer_id    = $args['customer_id'] ?? 0;
		$this->paid           = $args['paid'] ?? false;
		$this->email          = $args['email'] ?? 'guest@example.com';
		$this->payment_method = $args['payment_method'] ?? 'bacs';
		$this->ip             = $args['ip'] ?? '203.0.113.90';
		$this->meta           = $args['meta'] ?? array();
	}

	public function get_id() { return $this->id; }
	public function get_customer_id() { return $this->customer_id; }
	public function is_paid() { return $this->paid; }
	public function get_billing_email() { return $this->email; }
	public function get_payment_method() { return $this->payment_method; }
	public function get_customer_ip_address() { return $this->ip; }
	public function get_meta( $key, $single = true ) { unset( $single ); return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function set_status( $status ) { $this->status = $status; }
	public function save() { ++$this->save_count; }
}

$wpdb = new CEOG_Phase9_WPDB();
require dirname( __DIR__ ) . '/includes/functions.php';
require dirname( __DIR__ ) . '/includes/class-ceog-ip.php';
require dirname( __DIR__ ) . '/includes/class-ceog-logger.php';
require dirname( __DIR__ ) . '/includes/class-ceog-settings.php';
require dirname( __DIR__ ) . '/includes/class-ceog-origin-rules.php';

function ceog_phase9_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function ceog_phase9_settings( $overrides = array() ) {
	$GLOBALS['ceog_options']['ceog_settings'] = array_merge(
		ceog_get_default_settings(),
		array(
			'mode'                      => 'monitor',
			'unknown_origin_action'     => 'flag',
			'unknown_origin_onhold'     => 'no',
			'whitelist_payment_methods' => array(),
		),
		$overrides
	);
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.90';
$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';
ceog_phase9_settings();
$settings = new CEOG_Settings();
$logger   = new CEOG_Logger();
$rules    = new CEOG_Origin_Rules( $settings, $logger );
$rules->register_hooks();

ceog_phase9_assert( 3 === $GLOBALS['ceog_hooks']['woocommerce_checkout_order_processed'][2], 'Classic checkout must register all three hook arguments.' );
ceog_phase9_assert( isset( $GLOBALS['ceog_hooks']['woocommerce_store_api_checkout_order_processed'] ), 'Store API order processing must be registered.' );

$browser_order = new CEOG_Phase9_Order(
	101,
	array( 'meta' => array( '_wc_order_attribution_source_type' => 'typein' ) )
);
ceog_phase9_assert( false === $rules->evaluate_order( $browser_order, 'classic' ), 'A normal browser order with direct attribution must not be flagged.' );
ceog_phase9_assert( empty( $browser_order->meta['_ceog_origin_signal'] ), 'Known attribution must not create an Order Vanguard signal.' );

$unknown_order = new CEOG_Phase9_Order(
	102,
	array( 'meta' => array( '_wc_order_attribution_source_type' => 'unknown' ) )
);
ceog_phase9_assert( true === $rules->evaluate_order( $unknown_order, 'classic' ), 'A first-time unpaid guest with unknown attribution must be flagged.' );
ceog_phase9_assert( 'unknown' === $unknown_order->meta['_ceog_origin_signal'], 'The order metabox signal must be stored.' );
ceog_phase9_assert( 1 === count( $unknown_order->notes ), 'A flagged order must receive one review note.' );
ceog_phase9_assert( 'pending' === $unknown_order->status, 'Flag-only mode must preserve the unpaid order status.' );
ceog_phase9_assert( 1 === $unknown_order->save_count, 'The order signal must be persisted once.' );
ceog_phase9_assert( 1 === count( $wpdb->queries ) && false !== strpos( $wpdb->queries[0], "'flagged_order'" ), 'A flagged order event must be logged.' );
ceog_phase9_assert( false === $rules->evaluate_order( $unknown_order, 'classic' ), 'A repeated hook must be idempotent.' );
ceog_phase9_assert( 1 === count( $unknown_order->notes ) && 1 === count( $wpdb->queries ), 'A repeated hook must not duplicate notes or logs.' );

ceog_phase9_settings( array( 'unknown_origin_onhold' => 'yes' ) );
$store_api_order = new CEOG_Phase9_Order( 103, array( 'email' => 'api@example.com' ) );
$rules->evaluate_store_api_order( $store_api_order );
ceog_phase9_assert( 'on-hold' === $store_api_order->status, 'The opt-in hold must apply only to a flagged unpaid order.' );
ceog_phase9_assert( 2 === count( $wpdb->queries ) && false !== strpos( $wpdb->queries[1], 'store_api' ), 'Store API flags must identify their flow.' );

$paid_order = new CEOG_Phase9_Order( 104, array( 'paid' => true, 'email' => 'paid@example.com' ) );
ceog_phase9_assert( false === $rules->evaluate_order( $paid_order, 'store_api' ), 'Paid orders must never be touched.' );
ceog_phase9_assert( empty( $paid_order->meta ) && empty( $paid_order->notes ) && 0 === $paid_order->save_count, 'Paid orders must remain completely unchanged.' );

$customer_order = new CEOG_Phase9_Order( 105, array( 'customer_id' => 55, 'email' => 'member@example.com' ) );
ceog_phase9_assert( false === $rules->evaluate_order( $customer_order, 'classic' ), 'Registered customers must not be flagged.' );

ceog_phase9_settings( array( 'whitelist_payment_methods' => array( 'paypal' ) ) );
$wallet_order = new CEOG_Phase9_Order( 106, array( 'payment_method' => 'paypal', 'email' => 'wallet@example.com' ) );
ceog_phase9_assert( false === $rules->evaluate_order( $wallet_order, 'store_api' ), 'Whitelisted wallet and express gateways must bypass the rule.' );

ceog_phase9_settings();
$GLOBALS['ceog_completed_by_email']['returning@example.com'] = array( 80 );
$returning_order = new CEOG_Phase9_Order( 107, array( 'email' => 'returning@example.com' ) );
ceog_phase9_assert( false === $rules->evaluate_order( $returning_order, 'classic' ), 'A guest email with a completed order must not be flagged.' );
$last_query = end( $GLOBALS['ceog_order_queries'] );
ceog_phase9_assert( 'completed' === $last_query['status'] && 'ids' === $last_query['return'] && 1 === $last_query['limit'], 'Prior-order detection must use one bounded HPOS-safe query.' );
ceog_phase9_assert( array( 107 ) === $last_query['exclude'], 'Prior-order detection must exclude the order under evaluation.' );

ceog_phase9_settings( array( 'unknown_origin_action' => 'off' ) );
$disabled_order = new CEOG_Phase9_Order( 108, array( 'email' => 'disabled@example.com' ) );
ceog_phase9_assert( false === $rules->evaluate_order( $disabled_order, 'classic' ), 'The disabled origin rule must not inspect or alter orders.' );
ceog_phase9_assert( 2 === count( $wpdb->queries ), 'Excluded and disabled orders must not write log rows.' );

fwrite( STDOUT, "Phase 9 unknown-origin smoke checks passed.\n" );
