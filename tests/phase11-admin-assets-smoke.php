<?php
/**
 * CLI release gate for a source-only install missing compiled admin assets.
 *
 * @package CoderEmbassy_Order_Vanguard
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'CEOG_PATH', sys_get_temp_dir() . '/ceog-missing-build/' );

$GLOBALS['ceog_admin_hook'] = 'woocommerce_page_coderembassy-order-vanguard';

function add_action() {}
function add_submenu_page() { return $GLOBALS['ceog_admin_hook']; }
function __( $text ) { return $text; }
function esc_html_e( $text ) { echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function current_user_can( $capability ) { return 'manage_woocommerce' === $capability; }
function get_current_screen() { return (object) array( 'id' => $GLOBALS['ceog_admin_hook'] ); }

require dirname( __DIR__ ) . '/includes/class-ceog-admin.php';

function ceog_assets_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$admin = new CEOG_Admin();
$admin->register_menu();

ob_start();
$admin->render_missing_assets_notice();
$notice = ob_get_clean();

ceog_assets_assert( false !== strpos( $notice, 'Order Vanguard admin assets are missing' ), 'A missing build must show an actionable administrator notice.' );
ceog_assets_assert( false !== strpos( $notice, 'notice-error' ), 'The missing-build notice must be clearly presented as an error.' );

fwrite( STDOUT, "Phase 11 missing admin assets release gate passed.\n" );
