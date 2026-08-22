<?php
/**
 * Order Guard uninstall cleanup.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'ceog_prune_log' );

$ceog_settings = get_option( 'ceog_settings', array() );

if ( ! is_array( $ceog_settings ) || 'yes' !== ( $ceog_settings['delete_data_on_uninstall'] ?? 'no' ) ) {
	return;
}

global $wpdb;

$ceog_table_name = $wpdb->prefix . 'ceog_log';

// The identifier is composed exclusively from WordPress's trusted table prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$ceog_table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

delete_option( 'ceog_settings' );
delete_option( 'ceog_db_version' );
delete_option( 'ceog_strict_session_enabled' );
delete_option( 'ceog_honeypot_salt' );

foreach ( array( 'ip', 'email' ) as $ceog_breaker_tier ) {
	$ceog_active_key = 'ip' === $ceog_breaker_tier ? 'ceog_brk_ip_active' : 'ceog_brk_em_active';
	$ceog_prefix     = 'ip' === $ceog_breaker_tier ? 'ceog_brk_ip_' : 'ceog_brk_em_';
	$ceog_active     = get_transient( $ceog_active_key );
	foreach ( is_array( $ceog_active ) ? array_keys( $ceog_active ) : array() as $ceog_hash ) {
		if ( is_string( $ceog_hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $ceog_hash ) ) {
			delete_transient( $ceog_prefix . $ceog_hash );
		}
	}
	delete_transient( $ceog_active_key );
}

delete_transient( 'ceog_breaker_until' );
delete_transient( 'ceog_global_fails' );
delete_transient( 'ceog_safe_mode_logged' );
delete_transient( 'ceog_dashboard_cache' );
delete_transient( 'ceog_alert_throttle' );
