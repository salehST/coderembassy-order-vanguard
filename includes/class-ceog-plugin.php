<?php
/**
 * Main plugin coordinator.
 *
 * @package CoderEmbassy_Order_Vanguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Order Vanguard services and their WordPress hooks.
 */
final class CEOG_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var CEOG_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether hooks have already been registered.
	 *
	 * @var bool
	 */
	private $running = false;

	/**
	 * Keeps service instances alive for their registered callbacks.
	 *
	 * @var object[]
	 */
	private $services = array();

	/**
	 * Returns the singleton coordinator.
	 *
	 * @return CEOG_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the initial plugin hooks once.
	 *
	 * @return void
	 */
	public function run() {
		if ( $this->running ) {
			return;
		}

		$this->running = true;

		$settings  = new CEOG_Settings();
		$logger    = new CEOG_Logger();
		$lists     = new CEOG_Lists( $settings, $logger );
		$store_api = new CEOG_Store_API_Guard( $settings, $logger, $lists );
		$breakers  = new CEOG_Breakers( $settings, $logger, $lists );
		$honeypot  = new CEOG_Honeypot( $settings, $logger );
		$origin     = new CEOG_Origin_Rules( $settings, $logger );
		$dashboard  = new CEOG_Dashboard( $settings, $logger, $breakers );
		$alerts     = new CEOG_Alerts( $settings );
		$rest       = new CEOG_REST_Controller( $settings, $logger, $lists, $breakers, $dashboard );
		$admin     = new CEOG_Admin();

		$this->services = array( $settings, $logger, $lists, $store_api, $breakers, $honeypot, $origin, $dashboard, $alerts, $rest, $admin );
		$lists->register_hooks();
		$store_api->register_hooks();
		$breakers->register_hooks();
		$honeypot->register_hooks();
		$origin->register_hooks();
		$alerts->register_hooks();

		add_action( 'init', array( $this, 'maybe_log_safe_mode' ) );
		add_action( 'admin_notices', array( $this, 'render_safe_mode_notice' ) );
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		add_action( 'ceog_prune_log', array( $logger, 'prune' ) );

		if ( is_admin() ) {
			$admin->register_hooks();
		}
	}

	/**
	 * Records the Safe Mode state no more than once per day.
	 *
	 * @return void
	 */
	public function maybe_log_safe_mode() {
		if ( ! ceog_is_safe_mode() || get_transient( 'ceog_safe_mode_logged' ) ) {
			return;
		}

		ceog_safe(
			function () {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->warning(
						'Order Vanguard Safe Mode is active; enforcement is suspended.',
						array( 'source' => 'order-guard' )
					);
				}
			},
			null,
			'Logging Safe Mode state'
		);

		set_transient( 'ceog_safe_mode_logged', 1, DAY_IN_SECONDS );
	}

	/**
	 * Displays the persistent Safe Mode warning to store managers.
	 *
	 * @return void
	 */
	public function render_safe_mode_notice() {
		if ( ! ceog_is_safe_mode() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'Order Vanguard Safe Mode is active - protection is monitoring only.', 'coderembassy-order-vanguard' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}
}
