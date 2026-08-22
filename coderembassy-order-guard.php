<?php
/**
 * Plugin Name: CoderEmbassy Order Guard for WooCommerce
 * Plugin URI:  https://coderembassy.com/
 * Description: API-level protection against card testing, bot orders, and fake WooCommerce checkouts.
 * Version:     1.0.11
 * Author:      CoderEmbassy
 * Author URI:  https://coderembassy.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: coderembassy-order-guard
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CEOG_VERSION', '1.0.11' );
define( 'CEOG_DB_VERSION', '1.0.4' );
define( 'CEOG_FILE', __FILE__ );
define( 'CEOG_PATH', plugin_dir_path( __FILE__ ) );
define( 'CEOG_URL', plugin_dir_url( __FILE__ ) );
define( 'CEOG_BASENAME', plugin_basename( __FILE__ ) );

require_once CEOG_PATH . 'includes/functions.php';
require_once CEOG_PATH . 'includes/class-ceog-activator.php';
require_once CEOG_PATH . 'includes/class-ceog-ip.php';
require_once CEOG_PATH . 'includes/class-ceog-logger.php';
require_once CEOG_PATH . 'includes/class-ceog-settings.php';
require_once CEOG_PATH . 'includes/class-ceog-lists.php';
require_once CEOG_PATH . 'includes/class-ceog-store-api-guard.php';
require_once CEOG_PATH . 'includes/class-ceog-breakers.php';
require_once CEOG_PATH . 'includes/class-ceog-honeypot.php';
require_once CEOG_PATH . 'includes/class-ceog-origin-rules.php';
require_once CEOG_PATH . 'includes/class-ceog-dashboard.php';
require_once CEOG_PATH . 'includes/class-ceog-alerts.php';
require_once CEOG_PATH . 'includes/class-ceog-rest-controller.php';
require_once CEOG_PATH . 'includes/class-ceog-admin.php';
require_once CEOG_PATH . 'includes/class-ceog-plugin.php';

register_activation_hook( CEOG_FILE, array( 'CEOG_Activator', 'activate' ) );
register_deactivation_hook( CEOG_FILE, array( 'CEOG_Activator', 'deactivate' ) );

/**
 * Declares compatibility with WooCommerce order storage and checkout blocks.
 *
 * @return void
 */
function ceog_declare_woocommerce_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CEOG_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CEOG_FILE, true );
}
add_action( 'before_woocommerce_init', 'ceog_declare_woocommerce_compatibility' );

/**
 * Determines whether WooCommerce is available for Order Guard.
 *
 * @return bool
 */
function ceog_is_woocommerce_active() {
	return class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' );
}

/**
 * Shows the dependency notice without booting plugin services.
 *
 * @return void
 */
function ceog_woocommerce_dependency_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'CoderEmbassy Order Guard requires WooCommerce to be installed and active.', 'coderembassy-order-guard' ); ?></p>
	</div>
	<?php
}

/**
 * Boots the plugin only after WooCommerce has loaded.
 *
 * @return void
 */
function ceog_boot_plugin() {
	if ( ! ceog_is_woocommerce_active() ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', 'ceog_woocommerce_dependency_notice' );
		}

		return;
	}

	ceog_safe( array( 'CEOG_Activator', 'maybe_upgrade' ), null, 'Plugin database upgrade' );
	CEOG_Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'ceog_boot_plugin', 20 );
