<?php
/**
 * CLI smoke checks for Phase 10 dashboard aggregation and alerts.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'CEOG_FILE', dirname( __DIR__ ) . '/coderembassy-order-guard.php' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['ceog_options']    = array( 'admin_email' => 'owner@example.com' );
$GLOBALS['ceog_transients'] = array();
$GLOBALS['ceog_hooks']      = array();
$GLOBALS['ceog_mail']       = array();
$GLOBALS['ceog_now']        = 1783792800;
$GLOBALS['ceog_order_queries'] = array();

function get_option( $key, $default = false ) { return $GLOBALS['ceog_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { unset( $autoload ); $GLOBALS['ceog_options'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['ceog_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $expiration = 0 ) { unset( $expiration ); $GLOBALS['ceog_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['ceog_transients'][ $key ] ); return true; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? strtolower( (string) $value ) : ''; }
function absint( $value ) { return abs( (int) $value ); }
function wp_salt( $scheme = 'auth' ) { return 'phase-10-' . $scheme . '-test-salt'; }
function current_time( $type ) { return 'timestamp' === $type ? $GLOBALS['ceog_now'] : '2026-07-11 18:00:00'; }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function __( $text ) { return $text; }
function admin_url( $path = '' ) { return 'https://store.example/wp-admin/' . ltrim( $path, '/' ); }
function apply_filters( $hook, $value ) {
	return in_array( $hook, array( 'ceog_breaker_now', 'ceog_dashboard_now' ), true ) ? $GLOBALS['ceog_now'] : $value;
}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ceog_hooks'][ $hook ][] = array( $callback, $priority, $accepted_args );
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['ceog_hooks'][ $hook ] ?? array() as $registered ) {
		call_user_func_array( $registered[0], array_slice( $args, 0, $registered[2] ) );
	}
}
function wp_mail( $recipient, $subject, $message ) {
	$GLOBALS['ceog_mail'][] = compact( 'recipient', 'subject', 'message' );
	return true;
}
function wc_get_orders( $args ) {
	$GLOBALS['ceog_order_queries'][] = $args;
	return (object) array( 'orders' => array(), 'total' => 3, 'max_num_pages' => 3 );
}

final class CEOG_Phase10_WPDB {
	public $prefix = 'wp_';
	public $group_queries = 0;
	public $recent_queries = 0;
	public $today;
	public $yesterday;

	public function __construct() {
		$this->today = wp_date( 'Y-m-d', $GLOBALS['ceog_now'] );
		$this->yesterday = wp_date( 'Y-m-d', $GLOBALS['ceog_now'] - DAY_IN_SECONDS );
	}

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

	public function get_results( $sql, $format = null ) {
		unset( $format );
		if ( false !== strpos( $sql, 'GROUP BY DATE(event_time)' ) ) {
			++$this->group_queries;
			return array(
				array( 'event_date' => $this->yesterday, 'suspicious_count' => '3', 'blocked_count' => '1' ),
				array( 'event_date' => $this->today, 'suspicious_count' => '4', 'blocked_count' => '2' ),
			);
		}

		++$this->recent_queries;
		return array(
			array(
				'id' => '9', 'event_time' => '2026-07-11 17:59:00', 'event_type' => 'blocked_checkout',
				'mode' => 'enforce', 'ip_display' => '203.0.113.0', 'ip_hash' => str_repeat( 'a', 64 ),
				'route' => '/checkout', 'order_id' => '0', 'reason' => 'Checkout paused', 'meta' => '{}',
			),
		);
	}
}

$wpdb = new CEOG_Phase10_WPDB();
require dirname( __DIR__ ) . '/includes/functions.php';
require dirname( __DIR__ ) . '/includes/class-ceog-ip.php';
require dirname( __DIR__ ) . '/includes/class-ceog-logger.php';
require dirname( __DIR__ ) . '/includes/class-ceog-settings.php';
require dirname( __DIR__ ) . '/includes/class-ceog-lists.php';
require dirname( __DIR__ ) . '/includes/class-ceog-breakers.php';
require dirname( __DIR__ ) . '/includes/class-ceog-dashboard.php';
require dirname( __DIR__ ) . '/includes/class-ceog-alerts.php';

function ceog_phase10_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['ceog_options']['ceog_settings'] = array_merge(
	ceog_get_default_settings(),
	array(
		'mode'                => 'enforce',
		'alert_email_enabled' => 'yes',
		'alert_email'         => 'security@example.com',
	)
);
$settings  = new CEOG_Settings();
$logger    = new CEOG_Logger();
$lists     = new CEOG_Lists( $settings, $logger );
$breakers  = new CEOG_Breakers( $settings, $logger, $lists );
$dashboard = new CEOG_Dashboard( $settings, $logger, $breakers );

$payload = $dashboard->get_payload();
ceog_phase10_assert( 2 === $payload['blocked_today'], 'Dashboard must derive today blocked count from the grouped query.' );
ceog_phase10_assert( 7 === $payload['suspicious_7d'], 'Dashboard must sum the seven-day suspicious count.' );
ceog_phase10_assert( 7 === count( $payload['weekly_series'] ), 'Dashboard must always return exactly seven chart days.' );
ceog_phase10_assert( 1 === count( $payload['recent_events'] ), 'Dashboard must return the bounded recent event set.' );
ceog_phase10_assert( $payload['pro_context']['show'] && 3 === $payload['pro_context']['remaining_orders'], 'Enforced activity with failed orders must expose honest Pro context.' );
ceog_phase10_assert( 1 === $wpdb->group_queries && 1 === $wpdb->recent_queries, 'The first dashboard request must run one aggregate and one recent query.' );
ceog_phase10_assert( 1 === count( $GLOBALS['ceog_order_queries'] ), 'Failed-order context must use one HPOS-safe query.' );

$cached = $dashboard->get_payload();
ceog_phase10_assert( 2 === $cached['blocked_today'], 'Cached dashboard values must remain typed.' );
ceog_phase10_assert( 1 === $wpdb->group_queries && 1 === $wpdb->recent_queries, 'A warm cache must not repeat database queries.' );

$GLOBALS['ceog_options']['ceog_settings']['mode'] = 'monitor';
$monitor_payload = $dashboard->get_payload();
ceog_phase10_assert( ! $monitor_payload['pro_context']['show'], 'The cleanup card must never appear outside effective Enforce mode.' );

$GLOBALS['ceog_transients']['ceog_dashboard_cache'] = array( 'marker' => true );
$settings->save( array( 'mode' => 'enforce' ) );
ceog_phase10_assert( ! isset( $GLOBALS['ceog_transients']['ceog_dashboard_cache'] ), 'Protection-setting saves must invalidate dashboard aggregates.' );
$dashboard->get_payload();
ceog_phase10_assert( 2 === $wpdb->group_queries && 2 === $wpdb->recent_queries, 'An invalidated cache must rebuild once.' );

$alerts = new CEOG_Alerts( $settings );
$alerts->register_hooks();
ceog_phase10_assert( isset( $GLOBALS['ceog_hooks']['ceog_breaker_tripped'] ), 'Alerts must listen to the internal breaker event.' );
do_action( 'ceog_breaker_tripped', 'global', array( 'count' => 20 ) );
ceog_phase10_assert( 1 === count( $GLOBALS['ceog_mail'] ), 'The first breaker trip must send one email.' );
ceog_phase10_assert( 'security@example.com' === $GLOBALS['ceog_mail'][0]['recipient'], 'Configured alert recipients must take precedence.' );
ceog_phase10_assert( false !== strpos( $GLOBALS['ceog_mail'][0]['subject'], 'Global' ), 'The alert subject must identify the tripped tier.' );
do_action( 'ceog_breaker_tripped', 'ip', array( 'count' => 5 ) );
ceog_phase10_assert( 1 === count( $GLOBALS['ceog_mail'] ), 'The hourly throttle must suppress subsequent trip emails.' );

delete_transient( 'ceog_alert_throttle' );
$GLOBALS['ceog_options']['ceog_settings']['alert_email'] = '';
do_action( 'ceog_breaker_tripped', 'ip', array() );
ceog_phase10_assert( 2 === count( $GLOBALS['ceog_mail'] ), 'An expired throttle must permit the next alert.' );
ceog_phase10_assert( 'owner@example.com' === $GLOBALS['ceog_mail'][1]['recipient'], 'An empty recipient must fall back to the administrator email.' );
ceog_phase10_assert( false !== strpos( $GLOBALS['ceog_mail'][1]['subject'], 'Per-IP' ), 'Fallback alerts must still identify the tier.' );

delete_transient( 'ceog_alert_throttle' );
$GLOBALS['ceog_options']['ceog_settings']['alert_email_enabled'] = 'no';
do_action( 'ceog_breaker_tripped', 'email', array() );
ceog_phase10_assert( 2 === count( $GLOBALS['ceog_mail'] ), 'Disabled alerts must not send mail.' );

fwrite( STDOUT, "Phase 10 dashboard and alert smoke checks passed.\n" );
