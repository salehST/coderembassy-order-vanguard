<?php
/**
 * Minimal CLI checks for the Phase 1 safety helpers.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

$safe_mode = isset( $argv[1] ) && 'safe-mode' === $argv[1];

if ( $safe_mode ) {
	define( 'CEOG_SAFE_MODE', true );
}

$GLOBALS['ceog_test_settings'] = array(
	'enabled' => 'yes',
	'mode'    => 'monitor',
);

/**
 * Test double for get_option().
 *
 * @return mixed
 */
function get_option() {
	return $GLOBALS['ceog_test_settings'];
}

/**
 * Test double for wp_parse_args().
 *
 * @param array $args     Supplied values.
 * @param array $defaults Default values.
 * @return array
 */
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}

/**
 * Test double for sanitize_text_field().
 *
 * @param string $value Supplied value.
 * @return string
 */
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', $value ) );
}
function wp_unslash( $value ) { return $value; }

require dirname( __DIR__ ) . '/includes/functions.php';

/**
 * Stops the smoke test on a failed assertion.
 *
 * @param bool   $condition Expected truthy condition.
 * @param string $message   Failure description.
 * @return void
 */
function ceog_test_assert( $condition, $message ) {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

if ( $safe_mode ) {
	$GLOBALS['ceog_test_settings']['mode'] = 'enforce';
	ceog_test_assert( ! ceog_is_enforcing(), 'Safe Mode must override an enforce setting.' );
	echo "Phase 1 Safe Mode smoke test passed.\n";
	exit( 0 );
}

ceog_test_assert( ! ceog_is_enforcing(), 'Monitor mode must not enforce.' );

$GLOBALS['ceog_test_settings']['mode'] = 'enforce';
ceog_test_assert( ceog_is_enforcing(), 'Enabled Enforce mode must enforce.' );

$GLOBALS['ceog_test_settings'] = 'corrupt';
ceog_test_assert( ! ceog_is_enforcing(), 'Corrupted settings must fail open.' );

$fallback = ceog_safe(
	static function () {
		throw new RuntimeException( "Injected\nfailure" );
	},
	'safe-default',
	'Smoke test'
);
ceog_test_assert( 'safe-default' === $fallback, 'A thrown callback must return the safe default.' );

echo "Phase 1 enforcement and fail-open smoke tests passed.\n";
