<?php
/**
 * CLI smoke checks for Phase 4 IP privacy and log contracts.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['ceog_test_options'] = array();

function get_option( $key, $default = false ) {
	return $GLOBALS['ceog_test_options'][ $key ] ?? $default;
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

function absint( $value ) {
	return abs( (int) $value );
}

function wp_salt() {
	return 'phase-4-test-salt';
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

/**
 * Small wpdb stand-in that records prepared SQL.
 */
final class CEOG_Test_WPDB {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();
	public $total = 0;

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

	public function get_var( $sql ) {
		$this->queries[] = $sql;

		return $this->total;
	}

	public function get_results( $sql ) {
		$this->queries[] = $sql;

		return $this->rows;
	}
}

$wpdb = new CEOG_Test_WPDB();

require dirname( __DIR__ ) . '/includes/functions.php';
require dirname( __DIR__ ) . '/includes/class-ceog-ip.php';
require dirname( __DIR__ ) . '/includes/class-ceog-logger.php';

function ceog_phase4_assert( $condition, $message ) {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

$_SERVER['REMOTE_ADDR']          = '203.0.113.49';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.20';
$_SERVER['HTTP_X_FORWARDED_FOR']  = '192.0.2.9, 192.0.2.10';
$_SERVER['HTTP_USER_AGENT']       = "Bad\nAgent <script>alert(1)</script>";
$_SERVER['REQUEST_URI']           = '/wc/store/v1/checkout?secret=1';

ceog_phase4_assert( '203.0.113.49' === CEOG_IP::resolve( array( 'trusted_proxy' => 'none' ) ), 'Untrusted proxy headers must be ignored.' );
ceog_phase4_assert( '198.51.100.20' === CEOG_IP::resolve( array( 'trusted_proxy' => 'cloudflare' ) ), 'Cloudflare source must be used only when selected.' );
ceog_phase4_assert( '192.0.2.9' === CEOG_IP::resolve( array( 'trusted_proxy' => 'xff' ) ), 'Only the first X-Forwarded-For hop may be used.' );

$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
ceog_phase4_assert( '203.0.113.49' === CEOG_IP::resolve( array( 'trusted_proxy' => 'cloudflare' ) ), 'Invalid trusted headers must fall back to REMOTE_ADDR.' );
ceog_phase4_assert( '203.0.113.0' === CEOG_IP::anonymize( '203.0.113.49' ), 'IPv4 display must zero the final octet.' );

$ipv6_a = '2001:db8:abcd:12:1111:2222:3333:4444';
$ipv6_b = '2001:db8:abcd:12:ffff:eeee:dddd:cccc';
$ipv6_c = '2001:db8:abcd:13::1';
ceog_phase4_assert( CEOG_IP::normalize( $ipv6_a ) === CEOG_IP::normalize( $ipv6_b ), 'Addresses in one IPv6 /64 must normalize identically.' );
ceog_phase4_assert( CEOG_IP::hash( $ipv6_a ) === CEOG_IP::hash( $ipv6_b ), 'Addresses in one IPv6 /64 must hash identically.' );
ceog_phase4_assert( CEOG_IP::hash( $ipv6_a ) !== CEOG_IP::hash( $ipv6_c ), 'Different IPv6 /64 networks must not share a hash.' );
ceog_phase4_assert( '2001:db8:abcd:12::/64' === CEOG_IP::anonymize( $ipv6_a ), 'IPv6 display must expose only the /64 network.' );
ceog_phase4_assert( ceog_email_hash( 'Shopper@Example.com' ) === ceog_email_hash( 'shopper@example.com' ), 'Email hashes must be case-insensitive.' );

ceog_phase4_assert( CEOG_Logger::validate_date( '2026-07-10' ), 'Valid dates must pass.' );
ceog_phase4_assert( ! CEOG_Logger::validate_date( '2026-02-31' ), 'Impossible dates must fail.' );
ceog_phase4_assert( ! CEOG_Logger::validate_ids( range( 1, 101 ) ), 'Bulk deletion must reject more than 100 IDs.' );
ceog_phase4_assert( array( 2, 4 ) === CEOG_Logger::sanitize_ids( array( 2, '4', -2, 0, 'bad' ) ), 'Delete IDs must be positive, unique integers.' );

$logger = new CEOG_Logger();
$logged = $logger->log(
	'monitor_would_block',
	array(
		'reason' => "Suspicious\nrequest <b>blocked</b>",
		'meta'   => array(
			'billing_email' => 'private@example.com',
			'email_domain'  => 'example.com',
		),
	)
);
ceog_phase4_assert( $logged, 'A valid event must be inserted.' );
$insert_sql = $wpdb->queries[0] ?? '';
ceog_phase4_assert( false !== strpos( $insert_sql, '203.0.113.0' ), 'Default logging must store the anonymized address.' );
ceog_phase4_assert( false === strpos( $insert_sql, 'private@example.com' ), 'Plaintext email fields must not enter log metadata.' );
ceog_phase4_assert( false === strpos( $insert_sql, '<script>' ), 'Attacker-controlled metadata must be sanitized before insertion.' );

$wpdb->total = 1;
$wpdb->rows  = array(
	array(
		'id'         => '7',
		'event_time' => '2026-07-10 12:00:00',
		'event_type' => 'monitor_would_block',
		'mode'       => 'monitor',
		'ip_display' => '203.0.113.0',
		'ip_hash'    => ceog_ip_hash( '203.0.113.49' ),
		'route'      => '<script>/checkout</script>',
		'order_id'   => '0',
		'reason'     => '<b>Test</b>',
		'meta'       => '{"billing_email":"private@example.com","email_domain":"example.com"}',
	),
);
$result = $logger->get_entries( array( 'page' => 1, 'per_page' => 25 ) );
ceog_phase4_assert( 1 === $result['total'] && 1 === $result['pages'], 'Pagination totals must be typed integers.' );
ceog_phase4_assert( 7 === $result['rows'][0]['id'], 'Row IDs must be typed integers.' );
ceog_phase4_assert( '/checkout' === $result['rows'][0]['route'], 'REST rows must sanitize hostile route text without HTML escaping.' );
ceog_phase4_assert( ! isset( $result['rows'][0]['meta']['billing_email'] ), 'REST metadata must not expose plaintext email fields.' );

echo "Phase 4 IP privacy and logger smoke tests passed.\n";
