<?php
/**
 * Settings storage and REST normalization.
 *
 * @package CoderEmbassy_Order_Vanguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the single ceog_settings option.
 */
final class CEOG_Settings {
	/**
	 * Yes/no settings stored as strings in WordPress.
	 *
	 * @var string[]
	 */
	private static $boolean_keys = array(
		'enabled',
		'breaker_ip_enabled',
		'breaker_email_enabled',
		'breaker_global_enabled',
		'rate_limit_enabled',
		'strict_session',
		'emergency_lockdown',
		'unknown_origin_onhold',
		'honeypot_enabled',
		'log_full_ip',
		'alert_email_enabled',
		'delete_data_on_uninstall',
	);

	/**
	 * Integer settings and their inclusive bounds.
	 *
	 * @var array<string, int[]>
	 */
	private static $integer_bounds = array(
		'breaker_ip_threshold'     => array( 1, 1000 ),
		'breaker_ip_window'        => array( 30, 86400 ),
		'breaker_ip_block'         => array( 30, 86400 ),
		'breaker_email_threshold'  => array( 1, 1000 ),
		'breaker_email_window'     => array( 30, 86400 ),
		'breaker_email_block'      => array( 30, 86400 ),
		'breaker_global_threshold' => array( 1, 10000 ),
		'breaker_global_window'    => array( 30, 86400 ),
		'breaker_global_cooldown'  => array( 30, 86400 ),
		'rate_limit_limit'         => array( 1, 1000 ),
		'rate_limit_seconds'       => array( 1, 3600 ),
	);

	/**
	 * Returns a normalized copy of the stored settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get() {
		$settings = ceog_get_settings();

		return array_merge(
			ceog_get_default_settings(),
			self::sanitize_patch( $settings )
		);
	}

	/**
	 * Saves a validated settings patch over the current values.
	 *
	 * @param array<string, mixed> $input Settings patch.
	 * @return array<string, mixed>
	 */
	public function save( $input ) {
		$next = array_merge( $this->get(), self::sanitize_patch( $input ) );

		if ( self::checkout_uses_block() ) {
			$next['emergency_lockdown'] = 'no';
		}

		update_option( 'ceog_settings', $next, false );
		update_option( 'ceog_strict_session_enabled', $next['strict_session'], true );
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'ceog_dashboard_cache' );
		}

		return $this->get();
	}

	/**
	 * Saves the global protection mode.
	 *
	 * @param string $mode monitor|enforce.
	 * @return array<string, mixed>
	 */
	public function save_mode( $mode ) {
		return $this->save(
			array(
				'mode' => in_array( $mode, array( 'monitor', 'enforce' ), true ) ? $mode : 'monitor',
			)
		);
	}

	/**
	 * Validates the object accepted by the REST settings route.
	 *
	 * @param mixed $value Supplied route value.
	 * @return bool
	 */
	public static function validate_patch( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$unknown_keys = array_diff( array_keys( $value ), array_keys( ceog_get_default_settings() ) );

		return empty( $unknown_keys );
	}

	/**
	 * Sanitizes only recognized keys supplied by a caller.
	 *
	 * @param mixed $input Supplied settings object.
	 * @return array<string, mixed>
	 */
	public static function sanitize_patch( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$defaults  = ceog_get_default_settings();
		$sanitized = array();

		foreach ( $input as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			if ( in_array( $key, self::$boolean_keys, true ) ) {
				$sanitized[ $key ] = self::normalize_yes_no( $value );
				continue;
			}

			if ( 'log_retention_days' === $key ) {

				$days              = absint( $value );

				$sanitized[ $key ] = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;

				continue;

			}


			if ( isset( self::$integer_bounds[ $key ] ) ) {
				$bounds            = self::$integer_bounds[ $key ];
				$sanitized[ $key ] = min( $bounds[1], max( $bounds[0], absint( $value ) ) );
				continue;
			}

			switch ( $key ) {
				case 'mode':
					$mode              = sanitize_key( self::scalar_string( $value ) );
					$sanitized[ $key ] = in_array( $mode, array( 'monitor', 'enforce' ), true ) ? $mode : 'monitor';
					break;
				case 'trusted_proxy':
					$proxy             = sanitize_key( self::scalar_string( $value ) );
					$sanitized[ $key ] = in_array( $proxy, array( 'none', 'cloudflare', 'xff' ), true ) ? $proxy : 'none';
					break;
				case 'unknown_origin_action':
					$action            = sanitize_key( self::scalar_string( $value ) );
					$sanitized[ $key ] = in_array( $action, array( 'off', 'flag' ), true ) ? $action : 'flag';
					break;
				case 'alert_email':
					$sanitized[ $key ] = sanitize_email( self::scalar_string( $value ) );
					break;
				case 'blocklist_emails':
					$sanitized[ $key ] = self::sanitize_email_list( $value );
					break;
				case 'blocklist_email_domains':
					$sanitized[ $key ] = self::sanitize_domain_list( $value );
					break;
				case 'blocklist_ips':
				case 'whitelist_ips':
					$sanitized[ $key ] = self::sanitize_ip_list( $value );
					break;
				case 'whitelist_roles':
				case 'whitelist_payment_methods':
					$sanitized[ $key ] = self::sanitize_key_list( $value );
					break;
			}
		}

		return $sanitized;
	}

	/**
	 * Returns settings with native JSON scalar types.
	 *
	 * @param array<string, mixed> $settings Stored settings.
	 * @return array<string, mixed>
	 */
	public static function for_rest( $settings ) {
		$output = $settings;

		foreach ( self::$boolean_keys as $key ) {
			$output[ $key ] = isset( $settings[ $key ] ) && 'yes' === $settings[ $key ];
		}

		foreach ( array_keys( self::$integer_bounds ) as $key ) {
			$output[ $key ] = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : 0;
		}


		$days                         = absint( $settings['log_retention_days'] ?? 30 );


		$output['log_retention_days'] = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;



		return $output;
	}

	/**
	 * Detects the WooCommerce Checkout block on the configured checkout page.
	 *
	 * Detection fails closed because Emergency Lockdown is unsafe for block
	 * checkout and should not be enabled when compatibility is uncertain.
	 *
	 * @return bool
	 */
	public static function checkout_uses_block() {
		return (bool) ceog_safe(
			static function () {
				if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'has_block' ) ) {
					return true;
				}

				$checkout_page_id = (int) wc_get_page_id( 'checkout' );
				if ( $checkout_page_id <= 0 ) {
					return true;
				}

				$checkout_page = get_post( $checkout_page_id );
				if ( ! $checkout_page ) {
					return true;
				}

				return has_block( 'woocommerce/checkout', $checkout_page );
			},
			true,
			'Detecting the WooCommerce Checkout block'
		);
	}

	/**
	 * Reads WooCommerce's native Checkout rate-limiting feature flag.
	 *
	 * @return bool
	 */
	public static function native_checkout_rate_limit_enabled() {
		return (bool) ceog_safe(
			static function () {
				if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					return false;
				}

				return \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'rate_limit_checkout' );
			},
			false,
			'Detecting WooCommerce native checkout rate limiting'
		);
	}

	/**
	 * Normalizes common REST/HTML boolean representations.
	 *
	 * @param mixed $value Supplied value.
	 * @return string
	 */
	private static function normalize_yes_no( $value ) {
		return in_array( $value, array( true, 1, '1', 'yes', 'true', 'on' ), true ) ? 'yes' : 'no';
	}

	/**
	 * Sanitizes a list of email addresses.
	 *
	 * @param mixed $value Supplied values.
	 * @return string[]
	 */
	private static function sanitize_email_list( $value ) {
		$emails = array();

		foreach ( is_array( $value ) ? $value : array() as $email ) {
			if ( is_scalar( $email ) ) {
				$emails[] = sanitize_email( (string) $email );
			}
		}

		return array_values( array_unique( array_filter( $emails ) ) );
	}

	/**
	 * Sanitizes a list of email domains.
	 *
	 * @param mixed $value Supplied values.
	 * @return string[]
	 */
	private static function sanitize_domain_list( $value ) {
		$domains = is_array( $value ) ? $value : array();
		$output  = array();

		foreach ( $domains as $domain ) {
			if ( ! is_scalar( $domain ) ) {
				continue;
			}

			$domain = strtolower( ltrim( sanitize_text_field( (string) $domain ), '@' ) );
			if ( preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain ) ) {
				$output[] = $domain;
			}
		}

		return array_values( array_unique( $output ) );
	}

	/**
	 * Sanitizes a list of exact IPv4/IPv6 addresses.
	 *
	 * @param mixed $value Supplied values.
	 * @return string[]
	 */
	private static function sanitize_ip_list( $value ) {
		$ips    = is_array( $value ) ? $value : array();
		$output = array();

		foreach ( $ips as $ip ) {
			if ( ! is_scalar( $ip ) ) {
				continue;
			}

			$ip = trim( (string) $ip );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$output[] = $ip;
			}
		}

		return array_values( array_unique( $output ) );
	}

	/**
	 * Sanitizes a list of WordPress-style keys.
	 *
	 * @param mixed $value Supplied values.
	 * @return string[]
	 */
	private static function sanitize_key_list( $value ) {
		$items = array();

		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( is_scalar( $item ) ) {
				$items[] = sanitize_key( (string) $item );
			}
		}

		return array_values( array_unique( array_filter( $items ) ) );
	}

	/**
	 * Returns a scalar value as a string without array conversion warnings.
	 *
	 * @param mixed $value Supplied value.
	 * @return string
	 */
	private static function scalar_string( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
