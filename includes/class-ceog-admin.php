<?php
/**
 * WordPress admin menu and React asset bootstrap.
 *
 * @package CoderEmbassy_Order_Vanguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mounts the standalone React app on one WooCommerce submenu.
 */
final class CEOG_Admin {
	/**
	 * WordPress hook suffix for the plugin screen.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_missing_assets_notice' ) );
	}

	/**
	 * Adds WooCommerce -> Order Vanguard.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Order Vanguard', 'coderembassy-order-vanguard' ),
			__( 'Order Vanguard', 'coderembassy-order-vanguard' ),
			'manage_woocommerce',
			'coderembassy-order-vanguard',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Prints the single React mount point.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Order Vanguard.', 'coderembassy-order-vanguard' ) );
		}

		echo '<div id="ceog-app" class="ceog-app"></div>';
	}

	/**
	 * Enqueues the compiled app only on the Order Vanguard screen.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix || ! $this->assets_available() ) {
			return;
		}

		$asset = include CEOG_PATH . 'build/index.asset.php';

		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) ) {
			return;
		}

		wp_enqueue_script(
			'ceog-admin',
			CEOG_URL . 'build/index.js',
			(array) $asset['dependencies'],
			(string) $asset['version'],
			true
		);

		$style_file = file_exists( CEOG_PATH . 'build/index.css' )
			? 'build/index.css'
			: 'build/style-index.css';

		if ( file_exists( CEOG_PATH . $style_file ) ) {
			wp_enqueue_style(
				'ceog-admin',
				CEOG_URL . $style_file,
				array(),
				(string) $asset['version']
			);
			wp_style_add_data( 'ceog-admin', 'rtl', 'replace' );
		}

		$user = wp_get_current_user();

		$bootstrap = apply_filters(
			'ceog_admin_bootstrap',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'ceog/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'caps'      => array(
					'manage' => current_user_can( 'manage_woocommerce' ),
				),
				'version'   => CEOG_VERSION,
				'isPro'     => false,
				'logoLight' => esc_url_raw( CEOG_URL . 'assets/images/logo-light.png' ),
				'logoDark'  => esc_url_raw( CEOG_URL . 'assets/images/logo-dark.png' ),
				'user'      => array(
					'displayName' => sanitize_text_field( $user->display_name ),
					'avatarUrl'   => esc_url_raw( get_avatar_url( $user->ID, array( 'size' => 64 ) ) ),
				),
			)
		);
		wp_localize_script( 'ceog-admin', 'ceogBoot', is_array( $bootstrap ) ? $bootstrap : array() );

		wp_set_script_translations(
			'ceog-admin',
			'coderembassy-order-vanguard',
			CEOG_PATH . 'languages'
		);
	}

	/**
	 * Warns administrators when a source-only package was installed.
	 *
	 * @return void
	 */
	public function render_missing_assets_notice() {
		if (
			! $this->is_order_guard_screen()
			|| $this->assets_available()
			|| ! current_user_can( 'manage_woocommerce' )
		) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Order Vanguard admin assets are missing. Please reinstall the plugin from a complete release package.', 'coderembassy-order-vanguard' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Checks the required compiled files.
	 *
	 * @return bool
	 */
	private function assets_available() {
		return file_exists( CEOG_PATH . 'build/index.js' )
			&& file_exists( CEOG_PATH . 'build/index.asset.php' )
			&& (
				file_exists( CEOG_PATH . 'build/index.css' )
				|| file_exists( CEOG_PATH . 'build/style-index.css' )
			);
	}

	/**
	 * Checks whether the current screen is the React app screen.
	 *
	 * @return bool
	 */
	private function is_order_guard_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $this->hook_suffix === $screen->id;
	}
}
