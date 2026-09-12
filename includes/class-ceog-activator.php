<?php
/**
 * Plugin installation and lifecycle tasks.
 *
 * @package CoderEmbassy_Order_Vanguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and maintains the Order Vanguard data store.
 */
final class CEOG_Activator {
	/**
	 * Installs the schema and recurring maintenance event.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! ceog_is_woocommerce_active() ) {
			return;
		}

		self::install_schema();
		self::schedule_events();
	}

	/**
	 * Applies schema upgrades after WooCommerce becomes available.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( CEOG_DB_VERSION !== get_option( 'ceog_db_version' ) ) {
			self::install_schema();
		}

		self::schedule_events();
	}

	/**
	 * Clears scheduled events while preserving merchant data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'ceog_prune_log' );
	}

	/**
	 * Creates the attack-volume log table using dbDelta.
	 *
	 * @return void
	 */
	private static function install_schema() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'ceog_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_time datetime NOT NULL,
			event_type varchar(32) NOT NULL,
			mode varchar(10) NOT NULL,
			ip_display varchar(64) NOT NULL,
			ip_hash char(64) NOT NULL DEFAULT '',
			route varchar(191) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reason varchar(191) NOT NULL DEFAULT '',
			meta longtext NULL,
			PRIMARY KEY  (id),
			KEY event_time (event_time),
			KEY event_type_time (event_type,event_time),
			KEY ip_hash_time (ip_hash,event_time),
			KEY order_id (order_id),
			KEY mode_time (mode,event_time)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$installed_table = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema verification during activation cannot use the object cache.
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table_name )
			)
		);

		if ( $table_name === $installed_table ) {
			update_option( 'ceog_db_version', CEOG_DB_VERSION, true );
		}

		$settings = get_option( 'ceog_settings', array() );
		$strict_session = is_array( $settings ) && 'yes' === ( $settings['strict_session'] ?? 'no' ) ? 'yes' : 'no';
		update_option( 'ceog_strict_session_enabled', $strict_session, true );
	}

	/**
	 * Schedules daily batched log pruning.
	 *
	 * @return void
	 */
	private static function schedule_events() {
		if ( wp_next_scheduled( 'ceog_prune_log' ) ) {
			return;
		}

		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'ceog_prune_log' );
	}
}
