<?php
/**
 * Classic checkout honeypot protection.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds and validates a site-specific hidden field on classic checkout.
 */
final class CEOG_Honeypot {
	/** @var CEOG_Settings */
	private $settings;

	/** @var CEOG_Logger */
	private $logger;

	/** @var array<string, string> */
	private $field_names = array();

	/**
	 * @param CEOG_Settings $settings Settings service.
	 * @param CEOG_Logger   $logger   Protection event logger.
	 */
	public function __construct( CEOG_Settings $settings, CEOG_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/** Registers classic checkout integrations. */
	public function register_hooks() {
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_field' ), 10, 1 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout' ), 10 );
	}

	/** Renders a bot-visible but human-inaccessible checkout field. */
	public function render_field() {
		ceog_safe(
			function () {
				if ( ! $this->is_enabled() ) {
					return;
				}

				$field_name = $this->get_field_name();
				$field_id   = $field_name . '_field';
				?>
				<p aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
					<label for="<?php echo esc_attr( $field_id ); ?>" aria-hidden="true">
						<?php esc_html_e( 'Leave this field empty', 'coderembassy-order-guard' ); ?>
					</label>
					<input
						type="text"
						id="<?php echo esc_attr( $field_id ); ?>"
						name="<?php echo esc_attr( $field_name ); ?>"
						value=""
						aria-hidden="true"
						tabindex="-1"
						autocomplete="off"
					/>
				</p>
				<?php
			},
			null,
			'Rendering the classic checkout honeypot'
		);
	}

	/** Logs a populated field and blocks only when enforcement is active. */
	public function validate_checkout() {
		$should_block = (bool) ceog_safe(
			function () {
				if ( ! $this->is_enabled() ) {
					return false;
				}

				$field_name = $this->get_field_name();
				if ( ! isset( $_POST[ $field_name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before validation hooks run.
					return false;
				}

				$value = wp_unslash( $_POST[ $field_name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on the next line; arrays are treated as suspicious input.
				$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
				$hit   = is_scalar( $value ) ? '' !== trim( (string) $value ) : ! empty( $value );
				if ( ! $hit ) {
					return false;
				}

				$logged = $this->logger->log(
					'honeypot_hit',
					array(
						'mode'   => ceog_is_enforcing() ? 'enforce' : 'monitor',
						'route'  => '/checkout',
						'reason' => __( 'Automated checkout signal detected.', 'coderembassy-order-guard' ),
						'meta'   => array( 'flow' => 'classic' ),
					)
				);

				return $logged && ceog_is_enforcing();
			},
			false,
			'Validating the classic checkout honeypot'
		);

		if ( $should_block && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				__( 'We could not process your order. Please try again or contact the store for assistance.', 'coderembassy-order-guard' ),
				'error'
			);
		}
	}

	/** Returns the deterministic field name generated from the site salt. */
	public function get_field_name() {
		return $this->get_field_name_for_flow( 'classic_checkout' );
	}

	/**
	 * Returns a deterministic site-specific field name for one checkout flow.
	 *
	 * This public extension point lets the separate Pro add-on register a
	 * Checkout Block field without duplicating or exposing the site salt.
	 *
	 * @param string $flow Stable checkout-flow identifier.
	 * @return string
	 */
	public function get_field_name_for_flow( $flow ) {
		$flow = sanitize_key( (string) $flow );
		$flow = '' !== $flow ? $flow : 'classic_checkout';
		if ( isset( $this->field_names[ $flow ] ) ) {
			return $this->field_names[ $flow ];
		}

		$salt = ceog_safe(
			function () {
				$stored = get_option( 'ceog_honeypot_salt', '' );
				if ( is_string( $stored ) && strlen( $stored ) >= 32 ) {
					return $stored;
				}

				try {
					$generated = bin2hex( random_bytes( 32 ) );
				} catch ( Throwable $throwable ) {
					ceog_log_internal_error( $throwable, 'Generating the honeypot salt' );
					$generated = hash( 'sha256', wp_salt( 'auth' ) . CEOG_FILE );
				}

				update_option( 'ceog_honeypot_salt', $generated, false );

				return $generated;
			},
			hash( 'sha256', wp_salt( 'auth' ) . CEOG_FILE ),
			'Loading the honeypot salt'
		);

		$this->field_names[ $flow ] = 'ceog_' . substr( hash_hmac( 'sha256', $flow, (string) $salt ), 0, 24 );

		return $this->field_names[ $flow ];
	}

	/** Returns whether the plugin and honeypot layer are enabled. */
	private function is_enabled() {
		$settings = $this->settings->get();

		return 'yes' === ( $settings['enabled'] ?? 'yes' )
			&& 'yes' === ( $settings['honeypot_enabled'] ?? 'yes' );
	}
}
