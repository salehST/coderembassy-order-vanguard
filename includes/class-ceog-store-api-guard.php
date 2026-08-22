<?php
/**
 * WooCommerce Store API protection layer.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies precise Store API rules without touching unrelated REST traffic.
 */
final class CEOG_Store_API_Guard {
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
	 * Creates the Store API guard.
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
	 * Registers Store API hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'woocommerce_store_api_rate_limit_options', array( $this, 'configure_rate_limits' ), 20, 1 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'intercept_request' ), 10, 3 );
		add_action( 'woocommerce_init', array( $this, 'ensure_frontend_session' ) );
	}

	/**
	 * Enables WooCommerce's limiter for cart mutations and batch envelopes.
	 *
	 * Checkout is deliberately excluded so WooCommerce's native optional
	 * checkout limiter keeps full ownership of place-order throttling.
	 *
	 * @param mixed $options WooCommerce rate-limit options.
	 * @return mixed
	 */
	public function configure_rate_limits( $options ) {
		if ( ! is_array( $options ) || ! $this->is_original_post_request() ) {
			return $options;
		}

		return ceog_safe(
			function () use ( $options ) {
				$settings = $this->settings->get();
				$route    = $this->current_rest_route();

				if (
					'yes' !== ( $settings['rate_limit_enabled'] ?? 'no' )
					|| ! ceog_is_enforcing()
					|| ( ! self::is_cart_route( $route ) && ! self::is_batch_route( $route ) )
					|| $this->lists->is_whitelisted( $this->request_context( array(), $settings ) )
				) {
					return $options;
				}

				$options['enabled']       = true;
				$options['limit']         = (int) $settings['rate_limit_limit'];
				$options['seconds']       = (int) $settings['rate_limit_seconds'];
				$options['proxy_support'] = 'none' !== ( $settings['trusted_proxy'] ?? 'none' );

				return $options;
			},
			$options,
			'Configuring Store API rate limiting'
		);
	}

	/**
	 * Intercepts only POST requests to cart, checkout, and batch Store routes.
	 *
	 * @param mixed $response Existing response from an earlier filter.
	 * @param mixed $handler  Matched route handler.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function intercept_request( $response, $handler, $request ) {
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

				if ( ! self::is_cart_route( $route ) && ! self::is_checkout_route( $route ) ) {
					return $response;
				}

				$body    = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : array();
				$context = $this->request_context( is_array( $body ) ? $body : array(), $settings );
				if ( $this->lists->is_whitelisted( $context ) ) {
					return $response;
				}

				$violation = $this->evaluate_route( $route, $settings );
				if ( ! $violation ) {
					return $response;
				}

				$logged = $this->log_violation( $violation, $route, $context, false );

				return $logged && ceog_is_enforcing() ? $this->violation_error( $violation ) : $response;
			},
			$response,
			'Inspecting a Store API request'
		);
	}

	/**
	 * Ensures real front-end page views receive a session before cart actions.
	 *
	 * @return void
	 */
	public function ensure_frontend_session() {
		ceog_safe(
			function () {
				if ( 'yes' !== get_option( 'ceog_strict_session_enabled', 'no' ) ) {
					return;
				}

				$settings = $this->settings->get();
				if (
					'yes' !== ( $settings['enabled'] ?? 'yes' )
					|| 'yes' !== ( $settings['strict_session'] ?? 'no' )
					|| is_admin()
					|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
					|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
					|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
				) {
					return;
				}

				if ( ! function_exists( 'WC' ) ) {
					return;
				}

				$woocommerce = WC();
				if (
					is_object( $woocommerce )
					&& $woocommerce->session
					&& method_exists( $woocommerce->session, 'set_customer_session_cookie' )
				) {
					$woocommerce->session->set_customer_session_cookie( true );
				}
			},
			null,
			'Preparing a WooCommerce session for strict Store API checks'
		);
	}

	/**
	 * Inspects every embedded batch operation before rejecting the envelope.
	 *
	 * @param mixed                $response Existing response.
	 * @param mixed                $request  REST request.
	 * @param array<string, mixed> $settings Settings.
	 * @return mixed
	 */
	private function inspect_batch( $response, $request, $settings ) {
		$base_context = $this->request_context( array(), $settings );
		if ( $this->lists->is_whitelisted( $base_context ) ) {
			return $response;
		}

		$requests = method_exists( $request, 'get_param' ) ? $request->get_param( 'requests' ) : null;
		if ( ! is_array( $requests ) || empty( $requests ) || count( $requests ) > 25 ) {
			$violation = array( 'rule' => 'malformed_batch', 'status' => 400 );
			$logged = $this->log_violation( $violation, '/wc/store/v1/batch', $base_context, true );

			return $logged && ceog_is_enforcing() ? $this->violation_error( $violation ) : $response;
		}

		$first_error = null;
		foreach ( $requests as $index => $operation ) {
			if ( ! is_array( $operation ) ) {
				$violation = array( 'rule' => 'malformed_batch', 'status' => 400, 'batch_index' => $index );
				if ( $this->log_violation( $violation, '/wc/store/v1/batch', $base_context, true ) ) {
					$first_error = $first_error ?: $violation;
				}
				continue;
			}

			$method = isset( $operation['method'] ) && is_scalar( $operation['method'] )
				? strtoupper( sanitize_text_field( (string) $operation['method'] ) )
				: 'POST';
			$path   = isset( $operation['path'] ) && is_scalar( $operation['path'] )
				? self::path_only( $operation['path'] )
				: '';

			if ( '' === $path || 0 !== strpos( $path, '/wc/store/' ) ) {
				$violation = array( 'rule' => 'malformed_batch', 'status' => 400, 'batch_index' => $index );
				if ( $this->log_violation( $violation, '/wc/store/v1/batch', $base_context, true ) ) {
					$first_error = $first_error ?: $violation;
				}
				continue;
			}

			if ( 'POST' !== $method ) {
				continue;
			}

			$body    = isset( $operation['body'] ) && is_array( $operation['body'] ) ? $operation['body'] : array();
			$context = $this->request_context( $body, $settings );
			if ( $this->lists->is_whitelisted( $context ) ) {
				continue;
			}

			$violation = $this->evaluate_route( $path, $settings );
			if ( ! $violation ) {
				continue;
			}

			$violation['batch_index'] = $index;
			if ( $this->log_violation( $violation, $path, $context, true ) ) {
				$first_error = $first_error ?: $violation;
			}
		}

		return $first_error && ceog_is_enforcing()
			? $this->violation_error( $first_error )
			: $response;
	}

	/**
	 * Evaluates rules for one normalized Store API path.
	 *
	 * @param string               $route    Store API path.
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>|null
	 */
	private function evaluate_route( $route, $settings ) {
		if (
			self::is_cart_route( $route )
			&& 'yes' === ( $settings['strict_session'] ?? 'no' )
			&& ! $this->has_existing_session()
		) {
			return array( 'rule' => 'strict_session', 'status' => 403 );
		}

		if ( self::is_checkout_route( $route ) && 'yes' === ( $settings['emergency_lockdown'] ?? 'no' ) ) {
			return array( 'rule' => 'emergency_lockdown', 'status' => 404 );
		}

		return null;
	}

	/**
	 * Returns a request context suitable for whitelist checks and logs.
	 *
	 * @param array<string, mixed> $body     Request body.
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private function request_context( $body, $settings ) {
		$billing = isset( $body['billing_address'] ) && is_array( $body['billing_address'] )
			? $body['billing_address']
			: array();
		$context = array( 'ip' => CEOG_IP::resolve( $settings ) );
		$email   = $billing['email'] ?? ( $body['billing_email'] ?? '' );
		$payment = $body['payment_method'] ?? '';

		if ( is_scalar( $email ) && '' !== trim( (string) $email ) ) {
			$context['billing_email'] = (string) $email;
		}

		if ( is_scalar( $payment ) && '' !== trim( (string) $payment ) ) {
			$context['payment_method'] = (string) $payment;
		}

		return $context;
	}

	/**
	 * Writes a typed event without exposing sensitive matched values.
	 *
	 * @param array<string, mixed> $violation Rule details.
	 * @param string               $route     Store API path.
	 * @param array<string, mixed> $context   Request context.
	 * @param bool                 $is_batch  Whether this is embedded in a batch.
	 * @return bool Whether the audit event was stored.
	 */
	private function log_violation( $violation, $route, $context, $is_batch ) {
		$enforcing = ceog_is_enforcing();
		$rule      = $violation['rule'] ?? '';
		$event     = 'monitor_would_block';

		if ( $enforcing ) {
			$event = $is_batch
				? 'blocked_batch_op'
				: ( 'emergency_lockdown' === $rule ? 'blocked_checkout' : 'blocked_add_item' );
		}

		$reason = __( 'The Store API request would be blocked by current settings.', 'coderembassy-order-guard' );
		if ( 'strict_session' === $rule ) {
			$reason = __( 'Store API cart mutation had no existing WooCommerce session.', 'coderembassy-order-guard' );
		} elseif ( 'emergency_lockdown' === $rule ) {
			$reason = __( 'Emergency Store API Checkout Lockdown is enabled.', 'coderembassy-order-guard' );
		} elseif ( 'malformed_batch' === $rule ) {
			$reason = __( 'Store API batch request was malformed.', 'coderembassy-order-guard' );
		}

		return $this->logger->log(
			$event,
			array(
				'ip'     => $context['ip'] ?? '',
				'mode'   => $enforcing ? 'enforce' : 'monitor',
				'route'  => $route,
				'reason' => $reason,
				'meta'   => array(
					'rule'        => $rule,
					'batch'       => $is_batch,
					'batch_index' => isset( $violation['batch_index'] ) ? absint( $violation['batch_index'] ) : null,
				),
			)
		);
	}

	/**
	 * Creates the generic REST error for a violation.
	 *
	 * @param array<string, mixed> $violation Rule details.
	 * @return WP_Error
	 */
	private function violation_error( $violation ) {
		$rule = $violation['rule'] ?? '';

		if ( 'strict_session' === $rule ) {
			return new WP_Error(
				'ceog_strict_session',
				__( 'A valid WooCommerce session is required for this request.', 'coderembassy-order-guard' ),
				array( 'status' => 403 )
			);
		}

		if ( 'emergency_lockdown' === $rule ) {
			return new WP_Error(
				'ceog_emergency_lockdown',
				__( 'No route was found matching the URL and request method.', 'coderembassy-order-guard' ),
				array( 'status' => 404 )
			);
		}

		return new WP_Error(
			'ceog_malformed_batch',
			__( 'The Store API batch request is malformed.', 'coderembassy-order-guard' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Returns whether WooCommerce restored or created a real session.
	 *
	 * @return bool
	 */
	private function has_existing_session() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		$woocommerce = WC();

		return is_object( $woocommerce )
			&& $woocommerce->session
			&& method_exists( $woocommerce->session, 'has_session' )
			&& $woocommerce->session->has_session();
	}

	/**
	 * Checks the original HTTP method, including apiFetch overrides.
	 *
	 * @return bool
	 */
	private function is_original_post_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $method ) {
			return false;
		}

		$override = isset( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ) && is_scalar( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] )
			? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ) ) )
			: '';

		return '' === $override || 'POST' === $override;
	}

	/**
	 * Reads the current REST path during WooCommerce authentication.
	 *
	 * @return string
	 */
	private function current_rest_route() {
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] )
			? $GLOBALS['wp']->query_vars['rest_route']
			: '';

		return self::path_only( $route );
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
	 * Matches only Store API cart paths.
	 *
	 * @param string $route Route path.
	 * @return bool
	 */
	private static function is_cart_route( $route ) {
		return 1 === preg_match( '#^/wc/store(?:/v\d+)?/cart(?:/.*)?$#', $route );
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
