<?php
/**
 * CLI release gate for the idle storefront session path.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

$GLOBALS['ceog_idle_options']        = array( 'ceog_strict_session_enabled' => 'no' );
$GLOBALS['ceog_idle_settings_reads'] = 0;

function get_option( $key, $default = false ) {
	if ( 'ceog_settings' === $key ) {
		++$GLOBALS['ceog_idle_settings_reads'];
	}

	return $GLOBALS['ceog_idle_options'][ $key ] ?? $default;
}
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ? strtolower( (string) $value ) : ''; }
function absint( $value ) { return abs( (int) $value ); }
function wp_salt() { return 'idle-storefront-test-salt'; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function __($text) { return $text; }
function current_time( $type ) { return 'timestamp' === $type ? 1783792800 : '2026-07-11 18:00:00'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function apply_filters( $hook, $value ) { return $value; }

final class CEOG_Idle_Session {
	public $cookie_set = false;
	public function set_customer_session_cookie( $set ) { $this->cookie_set = (bool) $set; }
}

final class CEOG_Idle_WooCommerce {
	public $session;
	public function __construct() { $this->session = new CEOG_Idle_Session(); }
}

$GLOBALS['ceog_idle_wc'] = new CEOG_Idle_WooCommerce();
function WC() { return $GLOBALS['ceog_idle_wc']; }

require dirname( __DIR__ ) . '/includes/functions.php';
require dirname( __DIR__ ) . '/includes/class-ceog-settings.php';
require dirname( __DIR__ ) . '/includes/class-ceog-logger.php';
require dirname( __DIR__ ) . '/includes/class-ceog-lists.php';
require dirname( __DIR__ ) . '/includes/class-ceog-store-api-guard.php';

function ceog_idle_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$settings = new CEOG_Settings();
$logger   = new CEOG_Logger();
$lists    = new CEOG_Lists( $settings, $logger );
$guard    = new CEOG_Store_API_Guard( $settings, $logger, $lists );

$guard->ensure_frontend_session();
ceog_idle_assert( 0 === $GLOBALS['ceog_idle_settings_reads'], 'An idle storefront must not load the full settings option when Strict Session is disabled.' );
ceog_idle_assert( false === $GLOBALS['ceog_idle_wc']->session->cookie_set, 'An idle storefront must not create a WooCommerce session.' );

$strict_settings = ceog_get_default_settings();
$strict_settings['strict_session'] = 'yes';
$GLOBALS['ceog_idle_options']['ceog_strict_session_enabled'] = 'yes';
$GLOBALS['ceog_idle_options']['ceog_settings']                = $strict_settings;
$guard->ensure_frontend_session();
ceog_idle_assert( 1 === $GLOBALS['ceog_idle_settings_reads'], 'Strict Session must load and verify the full settings option.' );
ceog_idle_assert( true === $GLOBALS['ceog_idle_wc']->session->cookie_set, 'Strict Session must prepare the WooCommerce session cookie.' );

fwrite( STDOUT, "Phase 11 idle storefront release gate passed.\n" );
