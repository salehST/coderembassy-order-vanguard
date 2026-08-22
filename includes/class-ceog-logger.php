<?php
/**
 * Privacy-preserving protection event logger.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns all reads and writes to the Order Guard log table.
 */
final class CEOG_Logger {
	/**
	 * Event types stored by the free plugin.
	 *
	 * @var string[]
	 */
	private static $event_types = array(
		'breaker_ip_trip',
		'breaker_email_trip',
		'breaker_global_trip',
		'blocked_add_item',
		'blocked_checkout',
		'blocked_batch_op',
		'flagged_order',
		'honeypot_hit',
		'blocklist_hit',
		'monitor_would_block',
		'auto_block_added',
		'auto_block_expired',
	);

	/**
	 * Returns the locked event type enum.
	 *
	 * @return string[]
	 */
	public static function event_types() {
		return self::$event_types;
	}

	/**
	 * Writes one hostile-input-safe event row behind a fail-open boundary.
	 *
	 * @param string               $event_type Event enum value.
	 * @param array<string, mixed> $context Event details.
	 * @return bool
	 */
	public function log( $event_type, $context = array() ) {
		return (bool) ceog_safe(
			function () use ( $event_type, $context ) {
				global $wpdb;

				$event_type = sanitize_key( (string) $event_type );
				if ( ! in_array( $event_type, self::$event_types, true ) ) {
					return false;
				}

				$context  = is_array( $context ) ? $context : array();
				$settings = ceog_get_settings();
				$ip       = isset( $context['ip'] )
					? CEOG_IP::canonicalize( $context['ip'] )
					: CEOG_IP::resolve( $settings );
				$mode     = isset( $context['mode'] ) && in_array( $context['mode'], array( 'monitor', 'enforce' ), true )
					? $context['mode']
					: ( ceog_is_enforcing() ? 'enforce' : 'monitor' );
				$meta     = isset( $context['meta'] ) && is_array( $context['meta'] ) ? $context['meta'] : array();

				if ( ! isset( $meta['user_agent'] ) && isset( $_SERVER['HTTP_USER_AGENT'] ) && is_scalar( $_SERVER['HTTP_USER_AGENT'] ) ) {
					$meta['user_agent'] = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) );
				}

				$route = isset( $context['route'] ) ? $context['route'] : self::current_route();
				$row   = array(
					'event_time' => current_time( 'mysql' ),
					'event_type' => $event_type,
					'mode'       => $mode,
					'ip_display' => 'yes' === ( $settings['log_full_ip'] ?? 'no' )
						? CEOG_IP::canonicalize( $ip )
						: CEOG_IP::anonymize( $ip ),
					'ip_hash'    => ceog_ip_hash( $ip ),
					'route'      => self::clean_string( $route, 191 ),
					'order_id'   => isset( $context['order_id'] ) ? absint( $context['order_id'] ) : 0,
					'reason'     => self::clean_string( $context['reason'] ?? '', 191 ),
					'meta'       => wp_json_encode( self::sanitize_meta( $meta ), JSON_UNESCAPED_SLASHES ),
				);

				$table = $wpdb->prefix . 'ceog_log';
				$sql   = $wpdb->prepare(
					"INSERT INTO {$table} (event_time, event_type, mode, ip_display, ip_hash, route, order_id, reason, meta) VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$row['event_time'],
					$row['event_type'],
					$row['mode'],
					$row['ip_display'],
					$row['ip_hash'],
					$row['route'],
					$row['order_id'],
					$row['reason'],
					false === $row['meta'] ? '{}' : $row['meta']
				);
				$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

				if ( false === $result ) {
					throw new RuntimeException( 'The Order Guard log row could not be stored.' );
				}

				return true;
			},
			false,
			'Writing a protection event'
		);
	}

	/**
	 * Returns a bounded, server-paginated log result.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function get_entries( $args = array() ) {
		return ceog_safe(
			function () use ( $args ) {
				global $wpdb;

				$args      = is_array( $args ) ? $args : array();
				$page      = max( 1, absint( $args['page'] ?? 1 ) );
				$per_page  = min( 100, max( 1, absint( $args['per_page'] ?? 25 ) ) );
				$with_total = ! isset( $args['include_total'] ) || (bool) $args['include_total'];
				$table     = $wpdb->prefix . 'ceog_log';
				$clauses   = array( '1 = %d' );
				$values    = array( 1 );

				if ( ! empty( $args['type'] ) && in_array( $args['type'], self::$event_types, true ) ) {
					$clauses[] = 'event_type = %s';
					$values[]  = $args['type'];
				}

				if ( ! empty( $args['after'] ) && self::validate_date( $args['after'] ) ) {
					$clauses[] = 'event_time >= %s';
					$values[]  = $args['after'] . ' 00:00:00';
				}

				if ( ! empty( $args['before'] ) && self::validate_date( $args['before'] ) ) {
					$clauses[] = 'event_time <= %s';
					$values[]  = $args['before'] . ' 23:59:59';
				}

				if ( ! empty( $args['ip_hash'] ) && self::validate_hash( $args['ip_hash'] ) ) {
					$clauses[] = 'ip_hash = %s';
					$values[]  = strtolower( $args['ip_hash'] );
				}

				$where        = implode( ' AND ', $clauses );
				$total        = 0;
				if ( $with_total ) {
					$count_sql = $wpdb->prepare(
						"SELECT COUNT(*) FROM {$table} WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Clauses are allowlisted above.
						$values
					);
					$total     = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
				}
				$offset       = ( $page - 1 ) * $per_page;
				$query_values = array_merge( $values, array( $per_page, $offset ) );
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic allowlisted clauses provide the additional placeholders.
				$rows_sql     = $wpdb->prepare(
					"SELECT id, event_time, event_type, mode, ip_display, ip_hash, route, order_id, reason, meta FROM {$table} WHERE {$where} ORDER BY event_time DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic allowlisted clauses provide the additional placeholders.
					$query_values
				);
				$rows         = $wpdb->get_results( $rows_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

				return array(
					'rows'  => array_map( array( __CLASS__, 'prepare_rest_row' ), is_array( $rows ) ? $rows : array() ),
					'total' => $total,
					'pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
				);
			},
			array(
				'rows'  => array(),
				'total' => 0,
				'pages' => 0,
			),
			'Reading protection events'
		);
	}

	/**
	 * Returns a bounded set of the newest privacy-safe event rows.
	 *
	 * @param int $limit Maximum rows, from 1 to 20.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_recent_entries( $limit = 5 ) {
		$limit = min( 20, max( 1, absint( $limit ) ) );

		return ceog_safe(
			function () use ( $limit ) {
				global $wpdb;

				$table = $wpdb->prefix . 'ceog_log';
				$sql   = $wpdb->prepare(
					"SELECT id, event_time, event_type, mode, ip_display, ip_hash, route, order_id, reason, meta FROM {$table} ORDER BY event_time DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$limit
				);
				$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

				if ( ! is_array( $rows ) ) {
					throw new RuntimeException( 'Recent Order Guard events could not be read.' );
				}

				return array_map( array( __CLASS__, 'prepare_rest_row' ), $rows );
			},
			array(),
			'Reading recent protection events'
		);
	}

	/**
	 * Deletes at most 100 selected rows using prepared placeholders.
	 *
	 * @param mixed $ids Row IDs.
	 * @return int Number of deleted rows.
	 */
	public function delete_entries( $ids ) {
		$ids = self::sanitize_ids( $ids );
		if ( empty( $ids ) ) {
			return 0;
		}

		return (int) ceog_safe(
			function () use ( $ids ) {
				global $wpdb;

				$table        = $wpdb->prefix . 'ceog_log';
				$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
				$sql          = $wpdb->prepare(
					"DELETE FROM {$table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder count comes from sanitized IDs.
					$ids
				);
				$deleted      = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

				if ( false === $deleted ) {
					throw new RuntimeException( 'The selected Order Guard log rows could not be deleted.' );
				}

				delete_transient( 'ceog_dashboard_cache' );

				return (int) $deleted;
			},
			0,
			'Deleting protection events'
		);
	}

	/**
	 * Prunes expired rows in bounded batches.
	 *
	 * @return int Number of rows deleted in this run.
	 */
	public function prune() {
		return (int) ceog_safe(
			function () {
				global $wpdb;

				$settings  = ceog_get_settings();
				$retention = absint( $settings['log_retention_days'] ?? 7 );
				$retention = (int) apply_filters( 'ceog_log_retention_days', $retention );
				$retention = min( 3650, max( 1, $retention ) );
				$cutoff    = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $retention * DAY_IN_SECONDS ) );
				$table     = $wpdb->prefix . 'ceog_log';
				$total     = 0;

				for ( $batch = 0; $batch < 50; $batch++ ) {
					$sql     = $wpdb->prepare(
						"DELETE FROM {$table} WHERE event_time < %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$cutoff,
						1000
					);
					$deleted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

					if ( false === $deleted ) {
						throw new RuntimeException( 'Expired Order Guard log rows could not be pruned.' );
					}

					$total += (int) $deleted;
					if ( $deleted < 1000 ) {
						break;
					}
				}

				return $total;
			},
			0,
			'Pruning protection events'
		);
	}

	/**
	 * Strictly validates a YYYY-MM-DD date.
	 *
	 * @param mixed $value Candidate date.
	 * @return bool
	 */
	public static function validate_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		$date = DateTime::createFromFormat( '!Y-m-d', $value );

		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Validates one SHA-256 correlation hash.
	 *
	 * @param mixed $value Candidate hash.
	 * @return bool
	 */
	public static function validate_hash( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/i', $value );
	}

	/**
	 * Validates the hard bulk-delete contract.
	 *
	 * @param mixed $value Candidate IDs.
	 * @return bool
	 */
	public static function validate_ids( $value ) {
		if ( ! is_array( $value ) || empty( $value ) || count( $value ) > 100 ) {
			return false;
		}

		foreach ( $value as $id ) {
			if ( ! is_scalar( $id ) || absint( $id ) < 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalizes and caps bulk-delete IDs.
	 *
	 * @param mixed $value Candidate IDs.
	 * @return int[]
	 */
	public static function sanitize_ids( $value ) {
		$ids = array();

		foreach ( is_array( $value ) ? array_slice( $value, 0, 100 ) : array() as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Converts a database row to the typed REST contract.
	 *
	 * @param mixed $row Database row.
	 * @return array<string, mixed>
	 */
	private static function prepare_rest_row( $row ) {
		$row       = is_array( $row ) ? $row : array();
		$order_id  = absint( $row['order_id'] ?? 0 );
		$meta      = json_decode( (string) ( $row['meta'] ?? '' ), true );
		$order_url = '';

		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order_url = (string) ceog_safe(
				static function () use ( $order_id ) {
					$order = wc_get_order( $order_id );

					return $order ? $order->get_edit_order_url() : '';
				},
				'',
				'Preparing an order edit link'
			);
		}

		return array(
			'id'         => absint( $row['id'] ?? 0 ),
			'event_time' => self::clean_string( $row['event_time'] ?? '', 19 ),
			'event_type' => sanitize_key( (string) ( $row['event_type'] ?? '' ) ),
			'mode'       => in_array( $row['mode'] ?? '', array( 'monitor', 'enforce' ), true ) ? $row['mode'] : 'monitor',
			'ip_display' => self::clean_string( $row['ip_display'] ?? '', 64 ),
			'ip_hash'    => self::validate_hash( $row['ip_hash'] ?? '' ) ? strtolower( $row['ip_hash'] ) : '',
			'route'      => self::clean_string( $row['route'] ?? '', 191 ),
			'order_id'   => $order_id,
			'order_url'  => esc_url_raw( $order_url ),
			'reason'     => self::clean_string( $row['reason'] ?? '', 191 ),
			'meta'       => is_array( $meta ) ? self::sanitize_meta( $meta ) : array(),
		);
	}

	/**
	 * Sanitizes nested log metadata while excluding plaintext email fields.
	 *
	 * @param mixed $value Metadata value.
	 * @param int   $depth Current recursion depth.
	 * @return mixed
	 */
	private static function sanitize_meta( $value, $depth = 0 ) {
		if ( $depth > 3 ) {
			return null;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return self::clean_string( $value, 500 );
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$output = array();
		$count  = 0;
		foreach ( $value as $key => $item ) {
			if ( $count >= 25 ) {
				break;
			}

			$clean_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
			if ( false !== strpos( (string) $clean_key, 'email' ) && 'email_domain' !== $clean_key ) {
				continue;
			}

			$output[ $clean_key ] = self::sanitize_meta( $item, $depth + 1 );
			++$count;
		}

		return $output;
	}

	/**
	 * Sanitizes and caps an attacker-controlled text value.
	 *
	 * @param mixed $value Candidate value.
	 * @param int   $length Maximum characters.
	 * @return string
	 */
	private static function clean_string( $value, $length ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		$value = sanitize_text_field( is_string( $value ) ? $value : '' );

		return function_exists( 'mb_substr' )
			? mb_substr( $value, 0, $length )
			: substr( $value, 0, $length );
	}

	/**
	 * Returns the current path without its query string.
	 *
	 * @return string
	 */
	private static function current_route() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_scalar( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';
		$route = wp_parse_url( $request_uri, PHP_URL_PATH );

		return is_string( $route ) ? $route : '';
	}
}
