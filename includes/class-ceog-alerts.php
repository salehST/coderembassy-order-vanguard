<?php
/**
 * Throttled circuit breaker alerts.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Sends at most one breaker-trip email per hour. */
final class CEOG_Alerts {
	/** @var CEOG_Settings */
	private $settings;

	/** @param CEOG_Settings $settings Settings service. */
	public function __construct( CEOG_Settings $settings ) {
		$this->settings = $settings;
	}

	/** Registers the internal breaker event listener. */
	public function register_hooks() {
		add_action( 'ceog_breaker_tripped', array( $this, 'send_breaker_alert' ), 10, 2 );
	}

	/**
	 * Sends one privacy-safe alert unless the global hourly throttle is active.
	 *
	 * @param string               $tier ip|email|global.
	 * @param array<string, mixed> $trip Non-sensitive trip summary.
	 * @return bool Whether an email attempt was made successfully.
	 */
	public function send_breaker_alert( $tier, $trip = array() ) {
		unset( $trip );

		return (bool) ceog_safe(
			function () use ( $tier ) {
				$settings = $this->settings->get();
				if ( 'yes' !== ( $settings['alert_email_enabled'] ?? 'yes' ) || get_transient( 'ceog_alert_throttle' ) ) {
					return false;
				}

				$tier = in_array( $tier, array( 'ip', 'email', 'global' ), true ) ? $tier : 'global';
				$recipient = sanitize_email( (string) ( $settings['alert_email'] ?? '' ) );
				if ( '' === $recipient ) {
					$recipient = sanitize_email( (string) get_option( 'admin_email', '' ) );
				}
				if ( '' === $recipient || ! function_exists( 'wp_mail' ) ) {
					return false;
				}

				$label = self::tier_label( $tier );
				/* translators: %s is a circuit breaker tier label. */
				$subject = sprintf( __( 'Order Guard alert: %s breaker tripped', 'coderembassy-order-guard' ), $label );
				$message = sprintf(
					/* translators: 1: circuit breaker tier, 2: dashboard URL. */
					__( "The %1\$s circuit breaker tripped after repeated failed orders. Review the Order Guard dashboard and Activity Log:\n\n%2\$s", 'coderembassy-order-guard' ),
					$label,
					admin_url( 'admin.php?page=coderembassy-order-guard#/dashboard' )
				);

				set_transient( 'ceog_alert_throttle', time(), HOUR_IN_SECONDS );

				return (bool) wp_mail( $recipient, $subject, $message );
			},
			false,
			'Sending a circuit breaker alert'
		);
	}

	/** @param string $tier Tier key. @return string */
	private static function tier_label( $tier ) {
		if ( 'ip' === $tier ) {
			return __( 'Per-IP', 'coderembassy-order-guard' );
		}
		if ( 'email' === $tier ) {
			return __( 'Per-email', 'coderembassy-order-guard' );
		}

		return __( 'Global', 'coderembassy-order-guard' );
	}
}
