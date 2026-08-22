<?php
/**
 * CLI smoke checks for Phase 5 list precedence and checkout behavior.
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
	return 'phase-5-test-salt';
}

function current_time( $type ) {
	return 'timestamp' === $type ? 1783706400 : '2026-07-10 12:00:00';
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

function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }

function wp_get_current_user() {
	return (object) array( 'roles' => $GLOBALS['ceog_user_roles'] );
}

function wp_roles() {
	return (object) array(
		'roles' => array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
			'shop_manager'  => array( 'name' => 'Shop manager' ),
		),
	);
}

function translate_user_role( $label ) {
	return $label;
}

final class CEOG_Lists_Test_Gateway {
	public $id;
	private $title;

	public function __construct( $id, $title ) {
		$this->id    = $id;
		$this->title = $title;
	}

	public function get_method_title() {
		return $this->title;
	}
}

final class CEOG_Lists_Test_Gateway_Manager {
	public function payment_gateways() {
		return array(
			'cod'    => new CEOG_Lists_Test_Gateway( 'cod', 'Cash on delivery' ),
			'stripe' => new CEOG_Lists_Test_Gateway( 'stripe', 'Stripe' ),
		);
	}
}

final class CEOG_Lists_Test_WooCommerce {
	public $session = null;

	public function payment_gateways() {
		return new CEOG_Lists_Test_Gateway_Manager();
	}
}

function WC() {
	return new CEOG_Lists_Test_WooCommerce();
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

/**
 * Records logger writes without requiring MySQL.
 */
final class CEOG_Lists_Test_WPDB {
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

final class CEOG_Lists_Test_Errors {
	public $errors = array();

	public function add( $code, $message ) {
		$this->errors[ $code ] = $message;
	}
}

$wpdb = new CEOG_Lists_Test_WPDB();

require dirname( __DIR__ ) . '/includes/functions.php';
require dirname( __DIR__ ) . '/includes/class-ceog-ip.php';
require dirname( __DIR__ ) . '/includes/class-ceog-logger.php';
require dirname( __DIR__ ) . '/includes/class-ceog-settings.php';
require dirname( __DIR__ ) . '/includes/class-ceog-lists.php';

function ceog_phase5_assert( $condition, $message ) {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

$settings = ceog_get_default_settings();
$settings['whitelist_roles']           = array( 'shop_manager' );
$settings['whitelist_ips']             = array( '2001:db8:44:55::1' );
$settings['whitelist_payment_methods'] = array( 'paypal' );
$settings['blocklist_emails']          = array( 'blocked@example.com' );
$settings['blocklist_email_domains']   = array( 'junk.test' );
$settings['blocklist_ips']             = array( '2001:db8:44:55::99', '198.51.100.20' );

ceog_phase5_assert(
	CEOG_Lists::evaluate_whitelist( array( 'user_roles' => array( 'shop_manager' ), 'ip' => '203.0.113.1' ), $settings ),
	'A whitelisted user role must bypass later list checks.'
);
ceog_phase5_assert(
	CEOG_Lists::evaluate_whitelist( array( 'user_roles' => array(), 'ip' => '2001:db8:44:55:ffff::8' ), $settings ),
	'Any address in a whitelisted IPv6 /64 must match.'
);
ceog_phase5_assert(
	CEOG_Lists::evaluate_whitelist( array( 'user_roles' => array(), 'ip' => '203.0.113.1', 'payment_method' => 'paypal' ), $settings ),
	'A whitelisted payment method must match.'
);

$email_match = CEOG_Lists::evaluate_blocklist( array( 'billing_email' => 'BLOCKED@example.com', 'ip' => '203.0.113.1' ), $settings );
ceog_phase5_assert( 'email' === $email_match['type'], 'Exact billing email matching must be case-insensitive.' );
$domain_match = CEOG_Lists::evaluate_blocklist( array( 'billing_email' => 'bot@junk.test', 'ip' => '203.0.113.1' ), $settings );
ceog_phase5_assert( 'email_domain' === $domain_match['type'], 'Billing email domains must match independently.' );
$ipv6_match = CEOG_Lists::evaluate_blocklist( array( 'billing_email' => 'ok@example.com', 'ip' => '2001:db8:44:55:abcd::10' ), $settings );
ceog_phase5_assert( 'ip' === $ipv6_match['type'], 'Blocklist matching must collapse IPv6 addresses to /64.' );
$ipv4_miss = CEOG_Lists::evaluate_blocklist( array( 'billing_email' => 'ok@example.com', 'ip' => '198.51.100.21' ), $settings );
ceog_phase5_assert( ! $ipv4_miss['matched'], 'IPv4 matching must remain exact.' );

$GLOBALS['ceog_test_options']['ceog_settings'] = $settings;
$service = new CEOG_Lists( new CEOG_Settings(), new CEOG_Logger() );
$precedence = $service->match_blocklist(
	array(
		'billing_email' => 'blocked@example.com',
		'ip'            => '2001:db8:44:55:abcd::10',
		'user_roles'    => array(),
	)
);
ceog_phase5_assert( ! $precedence['matched'] && $precedence['whitelisted'], 'Whitelist matches must take precedence over every blocklist match.' );

$_SERVER['REMOTE_ADDR']  = '203.0.113.10';
$_SERVER['REQUEST_URI']  = '/checkout';
$settings['whitelist_ips'] = array();
$settings['mode']          = 'monitor';
$GLOBALS['ceog_test_options']['ceog_settings'] = $settings;
$monitor_errors = new CEOG_Lists_Test_Errors();
$before_logs    = count( $wpdb->queries );
$service->validate_classic_checkout(
	array( 'billing_email' => 'blocked@example.com', 'payment_method' => 'cod' ),
	$monitor_errors
);
ceog_phase5_assert( empty( $monitor_errors->errors ), 'Monitor mode must log and allow a blocklist match.' );
ceog_phase5_assert( count( $wpdb->queries ) === $before_logs + 1, 'Monitor mode must write one blocklist_hit event.' );

$settings['mode'] = 'enforce';
$GLOBALS['ceog_test_options']['ceog_settings'] = $settings;
$enforce_errors = new CEOG_Lists_Test_Errors();
$service->validate_classic_checkout(
	array( 'billing_email' => 'blocked@example.com', 'payment_method' => 'cod' ),
	$enforce_errors
);
ceog_phase5_assert( isset( $enforce_errors->errors['ceog_blocklist'] ), 'Enforce mode must add a generic checkout error.' );

$settings['whitelist_payment_methods'] = array( 'cod' );
$GLOBALS['ceog_test_options']['ceog_settings'] = $settings;
$whitelist_errors = new CEOG_Lists_Test_Errors();
$before_logs      = count( $wpdb->queries );
$service->validate_classic_checkout(
	array( 'billing_email' => 'blocked@example.com', 'payment_method' => 'cod' ),
	$whitelist_errors
);
ceog_phase5_assert( empty( $whitelist_errors->errors ), 'Payment-method whitelist precedence must bypass enforcement.' );
ceog_phase5_assert( count( $wpdb->queries ) === $before_logs, 'Whitelisted checkout must not create a blocklist event.' );

$added = $service->add_block_entity( 'email', 'NewBlock@Example.com' );
ceog_phase5_assert( ! empty( $added['added'] ), 'One-click block must add a new valid entity.' );
ceog_phase5_assert(
	in_array( 'newblock@example.com', $GLOBALS['ceog_test_options']['ceog_settings']['blocklist_emails'], true ),
	'One-click email storage must be normalized and persisted.'
);
ceog_phase5_assert( ! CEOG_Lists::validate_patch( array( 'unexpected_list' => array() ) ), 'Unknown list keys must be rejected.' );
ceog_phase5_assert( ! CEOG_Lists::validate_patch( array( 'blocklist_emails' => array( 'not-an-email' ) ) ), 'Malformed email entries must be rejected.' );
ceog_phase5_assert( ! CEOG_Lists::validate_patch( array( 'blocklist_email_domains' => array( 'bad..domain' ) ) ), 'Malformed domain entries must be rejected.' );
ceog_phase5_assert( ! CEOG_Lists::validate_patch( array( 'blocklist_ips' => array( '999.1.1.1' ) ) ), 'Malformed IP entries must be rejected.' );

$payload        = $service->get_payload();
$role_options   = array_column( $payload['options']['roles'], 'value' );
$gateway_options = array_column( $payload['options']['payment_methods'], 'value' );
ceog_phase5_assert( in_array( 'editor', $role_options, true ), 'REST payload must expose live WordPress role options.' );
ceog_phase5_assert( in_array( 'stripe', $gateway_options, true ), 'REST payload must expose installed WooCommerce payment methods.' );
ceog_phase5_assert( in_array( 'cod', $gateway_options, true ), 'Saved payment keys must remain available in the selector.' );

echo "Phase 5 list precedence and classic checkout smoke tests passed.\n";
