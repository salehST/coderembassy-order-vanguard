<?php
/**
 * Cached dashboard aggregation.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the live admin dashboard without loading the full event log.
 */
final class CEOG_Dashboard {
	const CACHE_KEY = 'ceog_dashboard_cache';
	const CACHE_TTL = 45;

	/** @var CEOG_Settings */
	private $settings;

	/** @var CEOG_Logger */
	private $logger;

	/** @var CEOG_Breakers */
	private $breakers;

	/**
	 * @param CEOG_Settings $settings Settings service.
	 * @param CEOG_Logger   $logger   Protection event logger.
	 * @param CEOG_Breakers $breakers Circuit breaker service.
	 */
	public function __construct( CEOG_Settings $settings, CEOG_Logger $logger, CEOG_Breakers $breakers ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->breakers = $breakers;
	}

	/**
	 * Returns cached event aggregates merged with live protection state.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payload() {
		return ceog_safe(
			function () {
				$cached = get_transient( self::CACHE_KEY );
				if ( ! self::valid_cached_payload( $cached ) ) {
					$cached = $this->build_cached_payload();
					set_transient( self::CACHE_KEY, $cached, self::CACHE_TTL );
				}

				$settings = $this->settings->get();
				$breakers = $this->breakers->get_status_payload();
				$enforcing = ceog_is_enforcing();
				$remaining = absint( $cached['failed_orders_remaining'] ?? 0 );
				$blocked_7d = absint( $cached['blocked_7d'] ?? 0 );

				return array(
					'blocked_today'   => absint( $cached['blocked_today'] ?? 0 ),
					'suspicious_7d'   => absint( $cached['suspicious_7d'] ?? 0 ),
					'mode'            => $enforcing ? 'enforce' : 'monitor',
					'configured_mode' => in_array( $settings['mode'] ?? '', array( 'monitor', 'enforce' ), true ) ? $settings['mode'] : 'monitor',
					'protection_enabled' => 'yes' === ( $settings['enabled'] ?? 'yes' ),
					'safe_mode'       => (bool) ceog_is_safe_mode(),
					'breakers'        => is_array( $breakers['tiers'] ?? null ) ? $breakers['tiers'] : array(),
					'weekly_series'   => array_values( $cached['weekly_series'] ?? array() ),
					'recent_events'   => array_values( $cached['recent_events'] ?? array() ),
					'pro_context'     => array(
						'show'             => (bool) ( $enforcing && $blocked_7d > 0 && $remaining > 0 ),
						'blocked_attempts' => $blocked_7d,
						'remaining_orders' => $remaining,
					),
					'cache_ttl'       => self::CACHE_TTL,
					'generated_at'    => $this->now(),
				);
			},
			self::empty_payload(),
			'Building the Order Guard dashboard'
		);
	}

	/** Clears cached event aggregates. */
	public static function invalidate_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Runs one indexed seven-day aggregate query and one bounded recent query.
	 *
	 * @return array<string, mixed>
	 */
	private function build_cached_payload() {
		global $wpdb;

		$now       = $this->now();
		$dates     = array();
		$series    = array();
		for ( $offset = 6; $offset >= 0; --$offset ) {
			$date            = wp_date( 'Y-m-d', $now - ( $offset * DAY_IN_SECONDS ) );
			$dates[]         = $date;
			$series[ $date ] = array( 'date' => $date, 'count' => 0, 'blocked' => 0 );
		}

		$table = $wpdb->prefix . 'ceog_log';
		$sql   = $wpdb->prepare(
			"SELECT DATE(event_time) AS event_date, COUNT(*) AS suspicious_count, SUM(CASE WHEN mode = 'enforce' AND event_type IN ('blocked_add_item','blocked_checkout','blocked_batch_op','blocklist_hit','honeypot_hit') THEN 1 ELSE 0 END) AS blocked_count FROM {$table} WHERE event_time >= %s AND event_time <= %s GROUP BY DATE(event_time) ORDER BY event_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$dates[0] . ' 00:00:00',
			$dates[6] . ' 23:59:59'
		);
		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Dashboard event aggregates could not be read.' );
		}

		foreach ( $rows as $row ) {
			$date = isset( $row['event_date'] ) ? (string) $row['event_date'] : '';
			if ( ! isset( $series[ $date ] ) ) {
				continue;
			}

			$series[ $date ]['count']   = absint( $row['suspicious_count'] ?? 0 );
			$series[ $date ]['blocked'] = absint( $row['blocked_count'] ?? 0 );
		}

		$suspicious_7d = 0;
		$blocked_7d    = 0;
		foreach ( $series as $day ) {
			$suspicious_7d += (int) $day['count'];
			$blocked_7d    += (int) $day['blocked'];
		}

		return array(
			'blocked_today'          => (int) $series[ $dates[6] ]['blocked'],
			'blocked_7d'             => $blocked_7d,
			'suspicious_7d'          => $suspicious_7d,
			'weekly_series'          => array_values( $series ),
			'recent_events'          => $this->logger->get_recent_entries( 5 ),
			'failed_orders_remaining' => $blocked_7d > 0 ? $this->failed_orders_remaining( $now ) : 0,
		);
	}

	/**
	 * Counts recent failed/cancelled orders through the HPOS-safe order API.
	 *
	 * @param int $now Current timestamp.
	 * @return int
	 */
	private function failed_orders_remaining( $now ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$result = wc_get_orders(
			array(
				'status'       => array( 'failed', 'cancelled' ),
				'date_created' => '>=' . wp_date( 'Y-m-d H:i:s', $now - ( 7 * DAY_IN_SECONDS ) ),
				'limit'        => 1,
				'paginate'     => true,
				'return'       => 'ids',
			)
		);

		return is_object( $result ) && isset( $result->total ) ? absint( $result->total ) : 0;
	}

	/** @return int */
	private function now() {
		return (int) apply_filters( 'ceog_dashboard_now', current_time( 'timestamp' ) );
	}

	/** @param mixed $payload Candidate transient value. @return bool */
	private static function valid_cached_payload( $payload ) {
		return is_array( $payload )
			&& isset( $payload['weekly_series'], $payload['recent_events'] )
			&& is_array( $payload['weekly_series'] )
			&& 7 === count( $payload['weekly_series'] )
			&& is_array( $payload['recent_events'] );
	}

	/** @return array<string, mixed> */
	private static function empty_payload() {
		return array(
			'blocked_today'     => 0,
			'suspicious_7d'     => 0,
			'mode'              => 'monitor',
			'configured_mode'   => 'monitor',
			'protection_enabled' => false,
			'safe_mode'         => (bool) ceog_is_safe_mode(),
			'breakers'          => array(),
			'weekly_series'     => array(),
			'recent_events'     => array(),
			'pro_context'       => array( 'show' => false, 'blocked_attempts' => 0, 'remaining_orders' => 0 ),
			'cache_ttl'         => self::CACHE_TTL,
			'generated_at'      => 0,
		);
	}
}
