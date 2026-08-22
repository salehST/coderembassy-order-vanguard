<?php
/**
 * CLI smoke checks for Phase 8 classic checkout honeypot protection.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'CEOG_FILE', dirname( __DIR__ ) . '/coderembassy-order-guard.php' );
$GLOBALS['ceog_options'] = array();
$GLOBALS['ceog_hooks']   = array();
$GLOBALS['ceog_notices'] = array();

function get_option( $key, $default = false ) { return $GLOBALS['ceog_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { unset( $autoload ); $GLOBALS['ceog_options'][ $key ] = $value; return true; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? strtolower( (string) $value ) : ''; }
function absint( $value ) { return abs( (int) $value ); }
function wp_salt( $scheme = 'auth' ) { return 'phase-8-' . $scheme . '-test-salt'; }
function current_time( $type ) { return 'timestamp' === $type ? 1783792800 : '2026-07-11 18:00:00'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $value ) { return $value; }
function __( $text ) { return $text; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $text ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ceog_hooks'][ $hook ] = array( $callback, $priority, $accepted_args );
}
function wc_add_notice( $message, $type ) { $GLOBALS['ceog_notices'][] = array( $message, $type ); }

final class CEOG_Phase8_WPDB {
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

$wpdb = new CEOG_Phase8_WPDB();
require dirname( __DIR__ ) . '/includes/functions.php';
require dirname( __DIR__ ) . '/includes/class-ceog-ip.php';
require dirname( __DIR__ ) . '/includes/class-ceog-logger.php';
require dirname( __DIR__ ) . '/includes/class-ceog-settings.php';
require dirname( __DIR__ ) . '/includes/class-ceog-honeypot.php';

function ceog_phase8_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function ceog_phase8_settings( $overrides = array() ) {
	$GLOBALS['ceog_options']['ceog_settings'] = array_merge(
		ceog_get_default_settings(),
		array( 'mode' => 'monitor' ),
		$overrides
	);
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
$_SERVER['REQUEST_URI'] = '/checkout';
ceog_phase8_settings();
$settings  = new CEOG_Settings();
$logger    = new CEOG_Logger();
$honeypot  = new CEOG_Honeypot( $settings, $logger );
$honeypot->register_hooks();

ceog_phase8_assert( isset( $GLOBALS['ceog_hooks']['woocommerce_after_order_notes'] ), 'The classic checkout field hook must be registered.' );
ceog_phase8_assert( isset( $GLOBALS['ceog_hooks']['woocommerce_checkout_process'] ), 'The classic checkout validation hook must be registered.' );

$field_name = $honeypot->get_field_name();
$block_name = $honeypot->get_field_name_for_flow( 'checkout_block' );
$second     = new CEOG_Honeypot( $settings, $logger );
ceog_phase8_assert( 1 === preg_match( '/^ceog_[a-f0-9]{24}$/', $field_name ), 'The field name must be opaque and bounded.' );
ceog_phase8_assert( $field_name === $second->get_field_name(), 'The stored site salt must produce a stable field name.' );
ceog_phase8_assert( 1 === preg_match( '/^ceog_[a-f0-9]{24}$/', $block_name ), 'The Pro extension field name must remain opaque and bounded.' );
ceog_phase8_assert( $field_name !== $block_name, 'Classic and Checkout Block flows must use separate salted field names.' );
ceog_phase8_assert( $block_name === $second->get_field_name_for_flow( 'checkout_block' ), 'The Checkout Block field name must be stable for the site.' );
ceog_phase8_assert( strlen( get_option( 'ceog_honeypot_salt', '' ) ) >= 32, 'A site-specific honeypot salt must be stored.' );

ob_start();
$honeypot->render_field();
$markup = ob_get_clean();
ceog_phase8_assert( false !== strpos( $markup, 'name="' . $field_name . '"' ), 'The rendered field must use the salted name.' );
ceog_phase8_assert( false !== strpos( $markup, 'aria-hidden="true"' ), 'The field must be hidden from screen readers.' );
ceog_phase8_assert( false !== strpos( $markup, 'tabindex="-1"' ), 'The field must be unreachable by keyboard.' );
ceog_phase8_assert( false !== strpos( $markup, 'autocomplete="off"' ), 'The field must disable autocomplete.' );
ceog_phase8_assert( false !== strpos( $markup, 'position:absolute;left:-9999px' ), 'The field must be visually off-screen.' );

$_POST = array( $field_name => '' );
$honeypot->validate_checkout();
ceog_phase8_assert( empty( $wpdb->queries ), 'An empty honeypot must not be logged.' );
ceog_phase8_assert( empty( $GLOBALS['ceog_notices'] ), 'An empty honeypot must not block checkout.' );

$_POST = array( $field_name => 'filled-by-automation' );
$honeypot->validate_checkout();
ceog_phase8_assert( 1 === count( $wpdb->queries ), 'A monitor-mode hit must be logged once.' );
ceog_phase8_assert( false !== strpos( $wpdb->queries[0], "'honeypot_hit'" ), 'The event must use the locked honeypot type.' );
ceog_phase8_assert( false !== strpos( $wpdb->queries[0], "'monitor'" ), 'Monitor hits must be labeled as monitor decisions.' );
ceog_phase8_assert( false === strpos( $wpdb->queries[0], 'filled-by-automation' ), 'Submitted honeypot content must never be logged.' );
ceog_phase8_assert( empty( $GLOBALS['ceog_notices'] ), 'Monitor mode must allow a honeypot hit.' );

ceog_phase8_settings( array( 'mode' => 'enforce' ) );
$GLOBALS['ceog_notices'] = array();
$_POST = array( $field_name => array( 'hostile-shape' ) );
$honeypot->validate_checkout();
ceog_phase8_assert( 2 === count( $wpdb->queries ), 'An enforced hit must also be logged.' );
ceog_phase8_assert( 1 === count( $GLOBALS['ceog_notices'] ), 'Enforce mode must add one checkout error.' );
ceog_phase8_assert( 'error' === $GLOBALS['ceog_notices'][0][1], 'The enforced response must be an error notice.' );
ceog_phase8_assert( 0 === preg_match( '/honeypot|bot/i', $GLOBALS['ceog_notices'][0][0] ), 'The customer error must not reveal the mechanism.' );

ceog_phase8_settings( array( 'honeypot_enabled' => 'no' ) );
ob_start();
$honeypot->render_field();
$disabled_markup = ob_get_clean();
$_POST = array( $field_name => 'filled' );
$honeypot->validate_checkout();
ceog_phase8_assert( '' === trim( $disabled_markup ), 'The disabled honeypot must not render.' );
ceog_phase8_assert( 2 === count( $wpdb->queries ), 'The disabled honeypot must not log or block.' );

fwrite( STDOUT, "Phase 8 honeypot smoke checks passed.\n" );
