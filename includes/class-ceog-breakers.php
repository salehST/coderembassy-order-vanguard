<?php
/**
 * Tiered failed-order circuit breakers.
 *
 * @package CoderEmbassy_Order_Vanguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts failed orders and temporarily pauses applicable checkout traffic.
 */
final class CEOG_Breakers {
	/**
	 * Settings service.
	 *
	 * @var CEOG_Settings
	 */
	private $settings;

	/**
	 * Protection event logger.
	 *
	 * @var CEOG_Logger
	 */
	private $logger;

	/**
	 * Whitelist-first list service.
	 *
	 * @var CEOG_Lists
	 */
	private $lists;

	/**
	 * Creates the breaker service.
	 *
	 * @param CEOG_Settings $settings Settings service.
	 * @param CEOG_Logger   $logger   Protection event logger.
	 * @param CEOG_Lists    $lists    Shared list service.
	 */
	public function __construct( CEOG_Settings $settings, CEOG_Logger $logger, CEOG_Lists $lists ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->lists    = $lists;
	}

	/**
	 * Registers failed-order and checkout hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'woocommerce_order_status_failed', array( $this, 'record_failed_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic_checkout' ), 5 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'intercept_store_api_checkout' ), 5, 3 );
	}

	/**
	 * Feeds enabled sliding windows from one failed order.
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $order    WooCommerce order object.
	 * @return void
	 */
	public function record_failed_order( $order_id, $order = null ) {
		ceog_safe(
			function () use ( $order_id, $order ) {
				$settings = $this->settings->get();
				if ( 'yes' !== ( $settings['enabled'] ?? 'yes' ) ) {
					return;
				}

				if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
					$order = wc_get_order( $order_id );
				}

				if ( ! is_object( $order ) ) {
					return;
				}

				$context = $this->order_context( $order );
				if ( $this->lists->is_whitelisted( $context ) ) {
					return;
				}

				$now       = $this->now();
				$ip_hash   = ceog_ip_hash( $context['ip'] ?? '' );
				$email_hash = ceog_email_hash( $context['billing_email'] ?? '' );
				$order_id  = method_exists( $order, 'get_id' ) ? absint( $order->get_id() ) : absint( $order_id );

				if ( 'yes' === ( $settings['breaker_ip_enabled'] ?? 'yes' ) && '' !== $ip_hash ) {
					$trip = $this->record_keyed_failure(
						'ip',
						$ip_hash,
						(int) $settings['breaker_ip_threshold'],
						(int) $settings['breaker_ip_window'],
						(int) $settings['breaker_ip_block'],
						$now
					);
					if ( $trip ) {
						$this->log_trip( 'ip', $trip, $context, $order_id );
					}
				}

				if ( 'yes' === ( $settings['breaker_email_enabled'] ?? 'yes' ) && '' !== $email_hash ) {
					$trip = $this->record_keyed_failure(
						'email',
						$email_hash,
						(int) $settings['breaker_email_threshold'],
						(int) $settings['breaker_email_window'],
						(int) $settings['breaker_email_block'],
						$now
					);
					if ( $trip ) {
						$this->log_trip( 'email', $trip, $context, $order_id );
					}
				}

				if ( 'yes' === ( $settings['breaker_global_enabled'] ?? 'yes' ) ) {
					$trip = $this->record_global_failure(
						(int) $settings['breaker_global_threshold'],
						(int) $settings['breaker_global_window'],
						(int) $settings['breaker_global_cooldown'],
						$now
					);
					if ( $trip ) {
						$this->log_trip( 'global', $trip, $context, $order_id );
					}
				}
			},
			null,
			'Recording a failed order in circuit breakers'
		);
	}

	/**
	 * Pauses classic checkout while an applicable breaker is tripped.
	 *
	 * @return void
	 */
	public function validate_classic_checkout() {
		ceog_safe(
			function () {
				$settings = $this->settings->get();
				if ( 'yes' !== ( $settings['enabled'] ?? 'yes' ) ) {
					return;
				}

				$context = $this->classic_context( $settings );
				$tiers   = $this->applicable_tiers( $context, $settings );
				if ( empty( $tiers ) ) {
					return;
				}

				$logged = $this->log_checkout_attempt( $tiers, '/checkout', $context, false );
				if ( $logged && ceog_is_enforcing() && function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( self::pause_message(), 'error' );
				}
			},
			null,
			'Checking circuit breakers during classic checkout'
		);
	}

	/**
	 * Pauses direct and batch-wrapped Store API checkout operations.
	 *
	 * @param mixed $response Existing response from an earlier filter.
	 * @param mixed $handler  Matched route handler.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function intercept_store_api_checkout( $response, $handler, $request ) {
		unset( $handler );

		if (
			null !== $response
			|| ! is_object( $request )
			|| ! method_exists( $request, 'get_method' )
			|| ! method_exists( $request, 'get_route' )
			|| 'POST' !== strtoupper( (string) $request->get_method() )
		) {
			return $response;
		}

		return ceog_safe(
			function () use ( $response, $request ) {
				$settings = $this->settings->get();
				if ( 'yes' !== ( $settings['enabled'] ?? 'yes' ) ) {
					return $response;
				}

				$route = self::path_only( $request->get_route() );
				if ( self::is_batch_route( $route ) ) {
					return $this->inspect_batch( $response, $request, $settings );
				}

				if ( ! self::is_checkout_route( $route ) ) {
					return $response;
				}

				$body    = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : array();
				$context = $this->request_context( is_array( $body ) ? $body : array(), $settings );
				$tiers   = $this->applicable_tiers( $context, $settings );
				if ( empty( $tiers ) ) {
					return $response;
				}

				$logged = $this->log_checkout_attempt( $tiers, $route, $context, false );

				return $logged && ceog_is_enforcing() ? self::pause_error() : $response;
			},
			$response,
			'Checking circuit breakers during Store API checkout'
		);
	}

	/**
	 * Returns a privacy-safe live status payload for the admin view.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status_payload() {
		return ceog_safe(
			function () {
				$settings = $this->settings->get();
				$now      = $this->now();
				$ip       = $this->active_index( 'ip', $now );
				$email    = $this->active_index( 'email', $now );
				$global_until = $this->global_until( $now );

				return array(
					'mode'     => ceog_is_enforcing() ? 'enforce' : 'monitor',
					'safeMode' => (bool) ceog_is_safe_mode(),
					'tiers'    => array(
						'ip'     => $this->tier_status(
							'yes' === ( $settings['breaker_ip_enabled'] ?? 'yes' ),
							$ip,
							(int) $settings['breaker_ip_threshold'],
							(int) $settings['breaker_ip_window'],
							(int) $settings['breaker_ip_block'],
							$now
						),
						'email'  => $this->tier_status(
							'yes' === ( $settings['breaker_email_enabled'] ?? 'yes' ),
							$email,
							(int) $settings['breaker_email_threshold'],
							(int) $settings['breaker_email_window'],
							(int) $settings['breaker_email_block'],
							$now
						),
						'global' => $this->tier_status(
							'yes' === ( $settings['breaker_global_enabled'] ?? 'yes' ),
							$global_until > $now ? array( 'global' => $global_until ) : array(),
							(int) $settings['breaker_global_threshold'],
							(int) $settings['breaker_global_window'],
							(int) $settings['breaker_global_cooldown'],
							$now
						),
					),
				);
			},
			array(
				'mode'     => 'monitor',
				'safeMode' => (bool) ceog_is_safe_mode(),
				'tiers'    => array(),
			),
			'Reading circuit breaker status'
		);
	}

	/**
	 * Inspects every embedded Store API checkout operation.
	 *
	 * @param mixed                $response Existing response.
	 * @param mixed                $request  REST request.
	 * @param array<string, mixed> $settings Settings.
	 * @return mixed
	 */
	private function inspect_batch( $response, $request, $settings ) {
		$requests = method_exists( $request, 'get_param' ) ? $request->get_param( 'requests' ) : null;
		if ( ! is_array( $requests ) || empty( $requests ) || count( $requests ) > 25 ) {
			return $response;
		}

		$violated = false;
		foreach ( $requests as $index => $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$method = isset( $operation['method'] ) && is_scalar( $operation['method'] )
				? strtoupper( sanitize_text_field( (string) $operation['method'] ) )
				: 'POST';
			$route  = isset( $operation['path'] ) && is_scalar( $operation['path'] )
				? self::path_only( $operation['path'] )
				: '';

			if ( 'POST' !== $method || ! self::is_checkout_route( $route ) ) {
				continue;
			}

			$body    = isset( $operation['body'] ) && is_array( $operation['body'] ) ? $operation['body'] : array();
			$context = $this->request_context( $body, $settings );
			$tiers   = $this->applicable_tiers( $context, $settings );
			if ( empty( $tiers ) ) {
				continue;
			}

			if ( $this->log_checkout_attempt( $tiers, $route, $context, true, $index ) ) {
				$violated = true;
			}
		}

		return $violated && ceog_is_enforcing() ? self::pause_error() : $response;
	}

	/**
	 * Returns all currently applicable tiers after whitelist precedence.
	 *
	 * @param array<string, mixed> $context  Checkout context.
	 * @param array<string, mixed> $settings Settings.
	 * @return string[]
	 */
	private function applicable_tiers( $context, $settings ) {
		if ( $this->lists->is_whitelisted( $context ) ) {
			return array();
		}

		$now   = $this->now();
		$tiers = array();
		if ( 'yes' === ( $settings['breaker_ip_enabled'] ?? 'yes' ) ) {
			$ip_hash = ceog_ip_hash( $context['ip'] ?? '' );
			if ( '' !== $ip_hash && $this->keyed_until( 'ip', $ip_hash, $now ) > $now ) {
				$tiers[] = 'ip';
			}
		}

		if ( 'yes' === ( $settings['breaker_email_enabled'] ?? 'yes' ) ) {
			$email_hash = ceog_email_hash( $context['billing_email'] ?? '' );
			if ( '' !== $email_hash && $this->keyed_until( 'email', $email_hash, $now ) > $now ) {
				$tiers[] = 'email';
			}
		}

		if ( 'yes' === ( $settings['breaker_global_enabled'] ?? 'yes' ) && $this->global_until( $now ) > $now ) {
			$tiers[] = 'global';
		}

		return $tiers;
	}

	/**
	 * Records one keyed failure and returns trip details on a new trip.
	 *
	 * @param string $tier      ip|email.
	 * @param string $hash      One-way entity hash.
	 * @param int    $threshold Failure threshold.
	 * @param int    $window    Sliding window seconds.
	 * @param int    $duration  Block duration seconds.
	 * @param int    $now       Current timestamp.
	 * @return array<string, int>|null
	 */
	private function record_keyed_failure( $tier, $hash, $threshold, $window, $duration, $now ) {
		$key        = self::entity_key( $tier, $hash );
		$state      = get_transient( $key );
		$state      = is_array( $state ) ? $state : array();
		$timestamps = $this->prune_timestamps( $state['timestamps'] ?? array(), $now, $window );
		$timestamps[] = $now;
		if ( count( $timestamps ) > $threshold ) {
			$timestamps = array_slice( $timestamps, -$threshold );
		}
		$until      = absint( $state['until'] ?? 0 );
		$until      = $until > $now ? $until : 0;
		$tripped    = false;

		if ( 0 === $until && count( $timestamps ) >= $threshold ) {
			$until   = $now + $duration;
			$tripped = true;
			$this->mark_active( $tier, $hash, $until, $duration );
		}

		set_transient(
			$key,
			array(
				'timestamps' => $timestamps,
				'until'      => $until,
			),
			max( $window, $duration ) + 60
		);

		return $tripped
			? array( 'count' => count( $timestamps ), 'threshold' => $threshold, 'window' => $window, 'until' => $until )
			: null;
	}

	/**
	 * Records one global failure and returns trip details on a new trip.
	 *
	 * @param int $threshold Failure threshold.
	 * @param int $window    Sliding window seconds.
	 * @param int $duration  Cooldown seconds.
	 * @param int $now       Current timestamp.
	 * @return array<string, int>|null
	 */
	private function record_global_failure( $threshold, $window, $duration, $now ) {
		$timestamps   = $this->prune_timestamps( get_transient( 'ceog_global_fails' ), $now, $window );
		$timestamps[] = $now;
		if ( count( $timestamps ) > $threshold ) {
			$timestamps = array_slice( $timestamps, -$threshold );
		}
		$until        = $this->global_until( $now );
		$tripped      = false;

		if ( $until <= $now && count( $timestamps ) >= $threshold ) {
			$until   = $now + $duration;
			$tripped = true;
			set_transient( 'ceog_breaker_until', $until, $duration + 60 );
		}

		set_transient( 'ceog_global_fails', $timestamps, max( $window, $duration ) + 60 );

		return $tripped
			? array( 'count' => count( $timestamps ), 'threshold' => $threshold, 'window' => $window, 'until' => $until )
			: null;
	}

	/**
	 * Returns a keyed block-until timestamp, clearing expired summary state.
	 *
	 * @param string $tier ip|email.
	 * @param string $hash One-way entity hash.
	 * @param int    $now  Current timestamp.
	 * @return int
	 */
	private function keyed_until( $tier, $hash, $now ) {
		$state = get_transient( self::entity_key( $tier, $hash ) );
		$until = is_array( $state ) ? absint( $state['until'] ?? 0 ) : 0;

		if ( $until <= $now ) {
			$this->unmark_active( $tier, $hash, $now );

			return 0;
		}

		return $until;
	}

	/**
	 * Returns the current global cooldown timestamp.
	 *
	 * @param int $now Current timestamp.
	 * @return int
	 */
	private function global_until( $now ) {
		$stored = get_transient( 'ceog_breaker_until' );
		$until  = absint( $stored );
		if ( $until <= $now ) {
			delete_transient( 'ceog_breaker_until' );
			if ( $stored ) {
				delete_transient( 'ceog_dashboard_cache' );
			}

			return 0;
		}

		return $until;
	}

	/**
	 * Adds a hashed entity to the privacy-safe active summary index.
	 *
	 * @param string $tier     ip|email.
	 * @param string $hash     One-way entity hash.
	 * @param int    $until    Block-until timestamp.
	 * @param int    $duration Block duration.
	 * @return void
	 */
	private function mark_active( $tier, $hash, $until, $duration ) {
		$key   = self::active_key( $tier );
		$index = get_transient( $key );
		$index = is_array( $index ) ? $index : array();
		$index[ $hash ] = $until;
		if ( count( $index ) > 500 ) {
			$index = array_slice( $index, -500, 500, true );
		}
		set_transient( $key, $index, $duration + 60 );
	}

	/**
	 * Removes one expired entity from its summary index.
	 *
	 * @param string $tier ip|email.
	 * @param string $hash One-way entity hash.
	 * @param int    $now  Current timestamp.
	 * @return void
	 */
	private function unmark_active( $tier, $hash, $now ) {
		$key   = self::active_key( $tier );
		$index = $this->clean_active_index( get_transient( $key ), $now );
		unset( $index[ $hash ] );

		if ( empty( $index ) ) {
			delete_transient( $key );
		} else {
			set_transient( $key, $index, max( $index ) - $now + 60 );
		}
	}

	/**
	 * Returns and persists a cleaned active summary index.
	 *
	 * @param string $tier ip|email.
	 * @param int    $now  Current timestamp.
	 * @return array<string, int>
	 */
	private function active_index( $tier, $now ) {
		$key   = self::active_key( $tier );
		$stored = get_transient( $key );
		$index = $this->clean_active_index( $stored, $now );
		if ( is_array( $stored ) && count( $stored ) !== count( $index ) ) {
			delete_transient( 'ceog_dashboard_cache' );
		}

		if ( empty( $index ) ) {
			delete_transient( $key );
		} else {
			set_transient( $key, $index, max( $index ) - $now + 60 );
		}

		return $index;
	}

	/**
	 * Removes invalid and expired active-index rows.
	 *
	 * @param mixed $index Candidate index.
	 * @param int   $now   Current timestamp.
	 * @return array<string, int>
	 */
	private function clean_active_index( $index, $now ) {
		$output = array();
		foreach ( is_array( $index ) ? $index : array() as $hash => $until ) {
			if ( CEOG_Logger::validate_hash( $hash ) && absint( $until ) > $now ) {
				$output[ strtolower( $hash ) ] = absint( $until );
			}
		}

		return $output;
	}

	/**
	 * Removes timestamps outside one sliding window.
	 *
	 * @param mixed $timestamps Candidate timestamps.
	 * @param int   $now        Current timestamp.
	 * @param int   $window     Window seconds.
	 * @return int[]
	 */
	private function prune_timestamps( $timestamps, $now, $window ) {
		$output = array();
		foreach ( is_array( $timestamps ) ? $timestamps : array() as $timestamp ) {
			$timestamp = absint( $timestamp );
			if ( $timestamp >= $now - $window && $timestamp <= $now ) {
				$output[] = $timestamp;
			}
		}

		return $output;
	}

	/**
	 * Logs one newly tripped tier without storing plaintext identities.
	 *
	 * @param string               $tier     ip|email|global.
	 * @param array<string, int>   $trip     Trip details.
	 * @param array<string, mixed> $context  Order context.
	 * @param int                  $order_id Failed order ID.
	 * @return void
	 */
	private function log_trip( $tier, $trip, $context, $order_id ) {
		$event = 'breaker_' . ( 'email' === $tier ? 'email' : $tier ) . '_trip';
		$logged = $this->logger->log(
			$event,
			array(
				'ip'       => $context['ip'] ?? '',
				'mode'     => ceog_is_enforcing() ? 'enforce' : 'monitor',
				'route'    => '/order-status/failed',
				'order_id' => $order_id,
				'reason'   => self::trip_reason( $tier ),
				'meta'     => array(
					'tier'      => $tier,
					'count'     => (int) $trip['count'],
					'threshold' => (int) $trip['threshold'],
					'window'    => (int) $trip['window'],
					'until'     => (int) $trip['until'],
				),
			)
		);
		delete_transient( 'ceog_dashboard_cache' );
		if ( $logged && function_exists( 'do_action' ) ) {
			do_action( 'ceog_breaker_tripped', $tier, $trip );
		}
	}

	/**
	 * Logs one checkout attempt affected by one or more tiers.
	 *
	 * @param string[]             $tiers       Applicable tiers.
	 * @param string               $route       Checkout path.
	 * @param array<string, mixed> $context     Checkout context.
	 * @param bool                 $batch       Whether embedded in a batch.
	 * @param int|null             $batch_index Embedded operation index.
	 * @return bool Whether the audit event was stored.
	 */
	private function log_checkout_attempt( $tiers, $route, $context, $batch, $batch_index = null ) {
		$enforcing = ceog_is_enforcing();
		return $this->logger->log(
			$enforcing ? ( $batch ? 'blocked_batch_op' : 'blocked_checkout' ) : 'monitor_would_block',
			array(
				'ip'     => $context['ip'] ?? '',
				'mode'   => $enforcing ? 'enforce' : 'monitor',
				'route'  => $route,
				'reason' => __( 'Checkout matched a tripped circuit breaker.', 'coderembassy-order-vanguard' ),
				'meta'   => array(
					'rule'        => 'circuit_breaker',
					'tiers'       => array_values( $tiers ),
					'batch'       => (bool) $batch,
					'batch_index' => null === $batch_index ? null : absint( $batch_index ),
				),
			)
		);
	}

	/**
	 * Builds one failed-order context without leaking the current admin user.
	 *
	 * @param object $order WooCommerce order.
	 * @return array<string, mixed>
	 */
	private function order_context( $order ) {
		return array(
			'ip'             => method_exists( $order, 'get_customer_ip_address' ) ? $order->get_customer_ip_address() : '',
			'billing_email'  => method_exists( $order, 'get_billing_email' ) ? $order->get_billing_email() : '',
			'payment_method' => method_exists( $order, 'get_payment_method' ) ? $order->get_payment_method() : '',
			'user_roles'     => $this->order_roles( $order ),
		);
	}

	/**
	 * Resolves customer roles for an order, with guests represented explicitly.
	 *
	 * @param object $order WooCommerce order.
	 * @return string[]
	 */
	private function order_roles( $order ) {
		$user_id = method_exists( $order, 'get_user_id' ) ? absint( $order->get_user_id() ) : 0;
		if ( $user_id < 1 || ! function_exists( 'get_userdata' ) ) {
			return array();
		}

		$user = get_userdata( $user_id );

		return is_object( $user ) && isset( $user->roles ) && is_array( $user->roles )
			? array_values( array_filter( array_map( 'sanitize_key', $user->roles ) ) )
			: array();
	}

	/**
	 * Builds a classic checkout context from sanitized request values.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private function classic_context( $settings ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before validation hooks run.
		$email = isset( $_POST['billing_email'] ) && is_scalar( $_POST['billing_email'] )
			? sanitize_email( wp_unslash( $_POST['billing_email'] ) )
			: '';
		$payment = isset( $_POST['payment_method'] ) && is_scalar( $_POST['payment_method'] )
			? sanitize_key( wp_unslash( $_POST['payment_method'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'ip'             => CEOG_IP::resolve( $settings ),
			'billing_email'  => $email,
			'payment_method' => $payment,
		);
	}

	/**
	 * Builds a Store API context from a checkout operation body.
	 *
	 * @param array<string, mixed> $body     Request body.
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private function request_context( $body, $settings ) {
		$billing = isset( $body['billing_address'] ) && is_array( $body['billing_address'] ) ? $body['billing_address'] : array();
		$email   = $billing['email'] ?? ( $body['billing_email'] ?? '' );
		$payment = $body['payment_method'] ?? '';

		return array(
			'ip'             => CEOG_IP::resolve( $settings ),
			'billing_email'  => is_scalar( $email ) ? sanitize_email( (string) $email ) : '',
			'payment_method' => is_scalar( $payment ) ? sanitize_key( (string) $payment ) : '',
		);
	}

	/**
	 * Builds a typed tier status without exposing active hashes.
	 *
	 * @param bool               $enabled    Whether the tier is enabled.
	 * @param array<string, int> $active     Active hashed entities.
	 * @param int                $threshold  Failure threshold.
	 * @param int                $window     Sliding window seconds.
	 * @param int                $duration   Block/cooldown seconds.
	 * @param int                $now        Current timestamp.
	 * @return array<string, mixed>
	 */
	private function tier_status( $enabled, $active, $threshold, $window, $duration, $now ) {
		$remaining = empty( $active ) ? 0 : max( 0, max( $active ) - $now );

		return array(
			'enabled'         => (bool) $enabled,
			'state'           => $enabled ? ( $remaining > 0 ? 'cooling_down' : 'ready' ) : 'disabled',
			'blocking'        => (bool) ( $enabled && $remaining > 0 && ceog_is_enforcing() ),
			'cooldownSeconds' => (int) $remaining,
			'activeEntities'  => (int) count( $active ),
			'threshold'       => (int) $threshold,
			'window'          => (int) $window,
			'duration'        => (int) $duration,
		);
	}

	/**
	 * Returns the current timestamp through a testable filter.
	 *
	 * @return int
	 */
	private function now() {
		return (int) apply_filters( 'ceog_breaker_now', time() );
	}

	/**
	 * Returns one transient key from an entity hash.
	 *
	 * @param string $tier ip|email.
	 * @param string $hash One-way hash.
	 * @return string
	 */
	private static function entity_key( $tier, $hash ) {
		return ( 'email' === $tier ? 'ceog_brk_em_' : 'ceog_brk_ip_' ) . strtolower( $hash );
	}

	/**
	 * Returns one active-summary transient key.
	 *
	 * @param string $tier ip|email.
	 * @return string
	 */
	private static function active_key( $tier ) {
		return 'email' === $tier ? 'ceog_brk_em_active' : 'ceog_brk_ip_active';
	}

	/**
	 * Returns the friendly public pause message.
	 *
	 * @return string
	 */
	private static function pause_message() {
		return __( 'Checkout is temporarily paused due to unusual activity. Please try again in a couple of minutes.', 'coderembassy-order-vanguard' );
	}

	/**
	 * Returns a Store API 503 response for a tripped breaker.
	 *
	 * @return WP_Error
	 */
	private static function pause_error() {
		return new WP_Error( 'ceog_breaker', self::pause_message(), array( 'status' => 503 ) );
	}

	/**
	 * Returns a non-sensitive trip reason.
	 *
	 * @param string $tier ip|email|global.
	 * @return string
	 */
	private static function trip_reason( $tier ) {
		if ( 'ip' === $tier ) {
			return __( 'The per-IP failed-order circuit breaker tripped.', 'coderembassy-order-vanguard' );
		}

		if ( 'email' === $tier ) {
			return __( 'The per-email failed-order circuit breaker tripped.', 'coderembassy-order-vanguard' );
		}

		return __( 'The global failed-order circuit breaker tripped.', 'coderembassy-order-vanguard' );
	}

	/**
	 * Normalizes a route or URL to its path.
	 *
	 * @param mixed $value Route or URL.
	 * @return string
	 */
	private static function path_only( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$path  = wp_parse_url( $value, PHP_URL_PATH );

		return is_string( $path ) ? $path : '';
	}

	/**
	 * Matches only the Store API checkout endpoint.
	 *
	 * @param string $route Route path.
	 * @return bool
	 */
	private static function is_checkout_route( $route ) {
		return 1 === preg_match( '#^/wc/store(?:/v\d+)?/checkout/?$#', $route );
	}

	/**
	 * Matches only the Store API batch endpoint.
	 *
	 * @param string $route Route path.
	 * @return bool
	 */
	private static function is_batch_route( $route ) {
		return 1 === preg_match( '#^/wc/store(?:/v\d+)?/batch/?$#', $route );
	}
}
