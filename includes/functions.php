<?php
/**
 * Shared Order Vanguard helpers.
 *
 * @package CoderEmbassy_Order_Vanguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the canonical settings defaults.
 *
 * @return array<string, mixed>
 */
function ceog_get_default_settings() {
	return array(
		'enabled'                   => 'yes',
		'mode'                      => 'monitor',
		'breaker_ip_enabled'        => 'yes',
		'breaker_ip_threshold'      => 5,
		'breaker_ip_window'         => 300,
		'breaker_ip_block'          => 600,
		'breaker_email_enabled'     => 'yes',
		'breaker_email_threshold'   => 3,
		'breaker_email_window'      => 600,
		'breaker_email_block'       => 900,
		'breaker_global_enabled'    => 'yes',
		'breaker_global_threshold'  => 20,
		'breaker_global_window'     => 300,
		'breaker_global_cooldown'   => 120,
		'rate_limit_enabled'        => 'yes',
		'rate_limit_limit'          => 25,
		'rate_limit_seconds'        => 10,
		'strict_session'            => 'no',
		'emergency_lockdown'        => 'no',
		'unknown_origin_action'     => 'flag',
		'unknown_origin_onhold'     => 'no',
		'honeypot_enabled'          => 'yes',
		'blocklist_emails'          => array(),
		'blocklist_email_domains'   => array(),
		'blocklist_ips'             => array(),
		'whitelist_ips'             => array(),
		'whitelist_roles'           => array( 'administrator', 'shop_manager' ),
		'whitelist_payment_methods' => array(),
		'log_full_ip'               => 'no',
		'log_retention_days'        => 30,
		'trusted_proxy'             => 'none',
		'alert_email_enabled'       => 'yes',
		'alert_email'               => '',
		'delete_data_on_uninstall'  => 'no',
	);
}

/**
 * Reads settings with monitor-first defaults when storage is malformed.
 *
 * @return array<string, mixed>
 */
function ceog_get_settings() {
	$defaults = ceog_get_default_settings();

	try {
		$settings = get_option( 'ceog_settings', array() );
	} catch ( Throwable $throwable ) {
		ceog_log_internal_error( $throwable, 'Reading plugin settings' );

		return $defaults;
	}

	if ( ! is_array( $settings ) ) {
		return $defaults;
	}

	return wp_parse_args( $settings, $defaults );
}

/**
 * Reports an internal failure to the WooCommerce log without rethrowing.
 *
 * @param Throwable $throwable Failure to report.
 * @param string    $context   Short operation description.
 * @return void
 */
function ceog_log_internal_error( Throwable $throwable, $context = '' ) {
	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	$context           = substr( sanitize_text_field( (string) $context ), 0, 191 );
	$exception_message = substr( sanitize_text_field( $throwable->getMessage() ), 0, 1000 );
	$message = sprintf(
		'Order Vanguard fail-open: %1$s [%2$s] %3$s',
		$context ? $context : 'Unhandled protection error',
		get_class( $throwable ),
		$exception_message
	);

	try {
		wc_get_logger()->error(
			$message,
			array( 'source' => 'order-guard' )
		);
	} catch ( Throwable $logging_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Logging must never turn a protection failure into a checkout failure.
	}
}

/**
 * Runs a callback behind the plugin-wide fail-open boundary.
 *
 * @param callable $callback Callback to execute.
 * @param mixed    $default  Value returned after a failure.
 * @param string   $context  Short operation description for logs.
 * @return mixed
 */
function ceog_safe( $callback, $default = null, $context = '' ) {
	if ( ! is_callable( $callback ) ) {
		ceog_log_internal_error( new InvalidArgumentException( 'The supplied callback is not callable.' ), $context );

		return $default;
	}

	try {
		return call_user_func( $callback );
	} catch ( Throwable $throwable ) {
		ceog_log_internal_error( $throwable, $context );

		return $default;
	}
}

/**
 * Determines whether emergency Safe Mode is active.
 *
 * @return bool
 */
function ceog_is_safe_mode() {
	return defined( 'CEOG_SAFE_MODE' ) && true === CEOG_SAFE_MODE;
}

/**
 * Single enforcement gate used by every future blocking path.
 *
 * @return bool
 */
function ceog_is_enforcing() {
	if ( ceog_is_safe_mode() ) {
		return false;
	}

	try {
		$settings = ceog_get_settings();

		return 'yes' === $settings['enabled'] && 'enforce' === $settings['mode'];
	} catch ( Throwable $throwable ) {
		ceog_log_internal_error( $throwable, 'Evaluating enforcement mode' );

		return false;
	}
}

/**
 * Shared permission callback for every Order Vanguard REST route.
 *
 * @return bool
 */
function ceog_rest_can_manage() {
	return current_user_can( 'manage_woocommerce' );
}
