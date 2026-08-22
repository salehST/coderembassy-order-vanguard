<?php
/**
 * Minimal CLI checks for the Phase 2 settings service.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

$GLOBALS['ceog_test_options'] = array();
$GLOBALS['ceog_checkout_block'] = false;

function get_option( $key, $default = false ) {
	return $GLOBALS['ceog_test_options'][ $key ] ?? $default;
}

function update_option( $key, $value ) {
	$GLOBALS['ceog_test_options'][ $key ] = $value;
	return true;
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) );
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

function wc_get_page_id() {
	return 42;
}

function get_post() {
	return (object) array( 'ID' => 42 );
}

function has_block() {
	return (bool) $GLOBALS['ceog_checkout_block'];
}

require dirname( __DIR__ ) . '/includes/functions.php';
require dirname( __DIR__ ) . '/includes/class-ceog-settings.php';

function ceog_phase2_assert( $condition, $message ) {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

$service = new CEOG_Settings();
$saved   = $service->save(
	array(
		'enabled'              => false,
		'mode'                 => 'enforce',
		'breaker_ip_threshold' => 0,
		'rate_limit_limit'     => 5000,
		'alert_email'          => 'owner@example.com',
		'blocklist_emails'     => array( 'blocked@example.com', array( 'invalid' ) ),
		'blocklist_ips'        => array( '192.0.2.44', 'not-an-ip', array( 'invalid' ) ),
	)
);

ceog_phase2_assert( 'no' === $saved['enabled'], 'Boolean false must store as no.' );
ceog_phase2_assert( 'enforce' === $saved['mode'], 'Valid Enforce mode must persist.' );
ceog_phase2_assert( 1 === $saved['breaker_ip_threshold'], 'Threshold must clamp to its minimum.' );
ceog_phase2_assert( 1000 === $saved['rate_limit_limit'], 'Rate limit must clamp to its maximum.' );
ceog_phase2_assert( array( 'blocked@example.com' ) === $saved['blocklist_emails'], 'Nested email values must be discarded.' );
ceog_phase2_assert( array( '192.0.2.44' ) === $saved['blocklist_ips'], 'Invalid IP values must be discarded.' );
ceog_phase2_assert( ! CEOG_Settings::validate_patch( array( 'unknown_key' => true ) ), 'Unknown settings keys must be rejected.' );

$rest = CEOG_Settings::for_rest( $saved );
ceog_phase2_assert( false === $rest['enabled'], 'REST output must expose booleans as booleans.' );
ceog_phase2_assert( is_int( $rest['rate_limit_limit'] ), 'REST output must expose numeric values as integers.' );

$GLOBALS['ceog_checkout_block'] = true;
$guarded = $service->save( array( 'emergency_lockdown' => true ) );
ceog_phase2_assert( 'no' === $guarded['emergency_lockdown'], 'Checkout block detection must force Emergency Lockdown off.' );
ceog_phase2_assert( CEOG_Settings::checkout_uses_block(), 'Checkout block detection must be exposed to the REST metadata layer.' );

$GLOBALS['ceog_checkout_block'] = false;
$classic = $service->save( array( 'emergency_lockdown' => true ) );
ceog_phase2_assert( 'yes' === $classic['emergency_lockdown'], 'Classic checkout may persist an explicitly enabled Emergency Lockdown.' );

echo "Phase 2 settings and REST normalization smoke tests passed.\n";
