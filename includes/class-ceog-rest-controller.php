<?php
/**
 * Authenticated admin REST endpoints.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the admin data contract under ceog/v1.
 */
final class CEOG_REST_Controller {
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
	 * Shared whitelist and blocklist service.
	 *
	 * @var CEOG_Lists
	 */
	private $lists;

	/**
	 * Tiered circuit breaker service.
	 *
	 * @var CEOG_Breakers
	 */
	private $breakers;

	/**
	 * Cached dashboard service.
	 *
	 * @var CEOG_Dashboard
	 */
	private $dashboard;

	/**
	 * Creates the REST controller.
	 *
	 * @param CEOG_Settings $settings Settings service.
	 * @param CEOG_Logger   $logger   Protection event logger.
	 * @param CEOG_Lists    $lists    Shared list service.
	 * @param CEOG_Breakers  $breakers Tiered circuit breakers.
	 * @param CEOG_Dashboard $dashboard Cached dashboard service.
	 */
	public function __construct( CEOG_Settings $settings, CEOG_Logger $logger, CEOG_Lists $lists, CEOG_Breakers $breakers, CEOG_Dashboard $dashboard ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->lists    = $lists;
		$this->breakers = $breakers;
		$this->dashboard = $dashboard;
	}

	/**
	 * Registers the Phase 2 routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'ceog/v1',
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'ceog_rest_can_manage',
					'args'                => array(),
					'callback'            => array( $this, 'get_settings' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'ceog_rest_can_manage',
					'args'                => array(
						'settings' => array(
							'required'          => true,
							'type'              => 'object',
							'validate_callback' => array( 'CEOG_Settings', 'validate_patch' ),
							'sanitize_callback' => array( 'CEOG_Settings', 'sanitize_patch' ),
						),
					),
					'callback'            => array( $this, 'update_settings' ),
				),
			)
		);

		register_rest_route(
			'ceog/v1',
			'/mode',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'ceog_rest_can_manage',
				'args'                => array(
					'mode' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'monitor', 'enforce' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
				'callback'            => array( $this, 'update_mode' ),
			)
		);

		register_rest_route(
			'ceog/v1',
			'/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'ceog_rest_can_manage',
				'args'                => array(
					'per_page' => array(
						'default'           => 25,
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'default'           => 1,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'type'     => array(
						'type'              => 'string',
						'enum'              => CEOG_Logger::event_types(),
						'sanitize_callback' => 'sanitize_key',
					),
					'after'    => array(
						'type'              => 'string',
						'validate_callback' => array( 'CEOG_Logger', 'validate_date' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'before'   => array(
						'type'              => 'string',
						'validate_callback' => array( 'CEOG_Logger', 'validate_date' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ip_hash'  => array(
						'type'              => 'string',
						'validate_callback' => array( 'CEOG_Logger', 'validate_hash' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'callback'            => array( $this, 'get_log' ),
			)
		);

		register_rest_route(
			'ceog/v1',
			'/log/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'ceog_rest_can_manage',
				'args'                => array(
					'ids' => array(
						'required'          => true,
						'type'              => 'array',
						'items'             => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'minItems'          => 1,
						'maxItems'          => 100,
						'validate_callback' => array( 'CEOG_Logger', 'validate_ids' ),
						'sanitize_callback' => array( 'CEOG_Logger', 'sanitize_ids' ),
					),
				),
				'callback'            => array( $this, 'delete_log' ),
			)
		);

		register_rest_route(
			'ceog/v1',
			'/lists',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => 'ceog_rest_can_manage',
					'args'                => array(),
					'callback'            => array( $this, 'get_lists' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => 'ceog_rest_can_manage',
					'args'                => array(
						'lists' => array(
							'required'          => true,
							'type'              => 'object',
							'validate_callback' => array( 'CEOG_Lists', 'validate_patch' ),
							'sanitize_callback' => array( 'CEOG_Lists', 'sanitize_patch' ),
						),
					),
					'callback'            => array( $this, 'update_lists' ),
				),
			)
		);

		register_rest_route(
			'ceog/v1',
			'/block-entity',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'ceog_rest_can_manage',
				'args'                => array(
					'type'  => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'email', 'ip' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'value' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( 'CEOG_Lists', 'validate_entity_value' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'callback'            => array( $this, 'block_entity' ),
			)
		);

		register_rest_route(
			'ceog/v1',
			'/breakers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'ceog_rest_can_manage',
				'args'                => array(),
				'callback'            => array( $this, 'get_breakers' ),
			)
		);

		register_rest_route(
			'ceog/v1',
			'/dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'ceog_rest_can_manage',
				'args'                => array(),
				'callback'            => array( $this, 'get_dashboard' ),
			)
		);
	}

	/**
	 * Returns cached dashboard aggregates with live breaker state.
	 *
	 * @return WP_REST_Response
	 */
	public function get_dashboard() {
		return rest_ensure_response( $this->dashboard->get_payload() );
	}

	/**
	 * Returns privacy-safe live breaker state.
	 *
	 * @return WP_REST_Response
	 */
	public function get_breakers() {
		return rest_ensure_response( $this->breakers->get_status_payload() );
	}

	/**
	 * Returns all blocklist and whitelist values.
	 *
	 * @return WP_REST_Response
	 */
	public function get_lists() {
		return rest_ensure_response( $this->lists->get_payload() );
	}

	/**
	 * Saves validated list values.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function update_lists( WP_REST_Request $request ) {
		$input = CEOG_Lists::sanitize_patch( $request->get_param( 'lists' ) );

		return rest_ensure_response( $this->lists->save_patch( $input ) );
	}

	/**
	 * Adds one validated email or IP to a blocklist.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function block_entity( WP_REST_Request $request ) {
		$result = $this->lists->add_block_entity(
			sanitize_key( (string) $request->get_param( 'type' ) ),
			$request->get_param( 'value' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'ceog_invalid_block_entity',
				__( 'Enter a valid email address or IP address.', 'coderembassy-order-guard' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Returns one filtered page of protection events.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_log( WP_REST_Request $request ) {
		$after  = (string) $request->get_param( 'after' );
		$before = (string) $request->get_param( 'before' );

		if ( '' !== $after && '' !== $before && $after > $before ) {
			return new WP_Error(
				'ceog_invalid_log_range',
				__( 'The start date must not be later than the end date.', 'coderembassy-order-guard' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			$this->logger->get_entries(
				array(
					'per_page' => $request->get_param( 'per_page' ),
					'page'     => $request->get_param( 'page' ),
					'type'     => $request->get_param( 'type' ),
					'after'    => $after,
					'before'   => $before,
					'ip_hash'  => $request->get_param( 'ip_hash' ),
				)
			)
		);
	}

	/**
	 * Deletes a bounded set of selected event rows.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function delete_log( WP_REST_Request $request ) {
		$deleted = $this->logger->delete_entries( $request->get_param( 'ids' ) );

		return rest_ensure_response( array( 'deleted' => (int) $deleted ) );
	}

	/**
	 * Returns current settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( $this->settings_payload( $this->settings->get() ) );
	}

	/**
	 * Saves a settings patch after route-level validation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ) {
		$input = $request->get_param( 'settings' );
		$input = CEOG_Settings::sanitize_patch( is_array( $input ) ? $input : array() );

		if (
			isset( $input['emergency_lockdown'] )
			&& 'yes' === $input['emergency_lockdown']
			&& CEOG_Settings::checkout_uses_block()
		) {
			return new WP_Error(
				'ceog_checkout_block_lockdown',
				__( 'Emergency Lockdown cannot be enabled while the WooCommerce Checkout block is active.', 'coderembassy-order-guard' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $this->settings_payload( $this->settings->save( $input ) ) );
	}

	/**
	 * Updates the monitor/enforce mode.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_mode( WP_REST_Request $request ) {
		$mode = sanitize_key( (string) $request->get_param( 'mode' ) );

		if ( ! in_array( $mode, array( 'monitor', 'enforce' ), true ) ) {
			return new WP_Error(
				'ceog_invalid_mode',
				__( 'The selected protection mode is invalid.', 'coderembassy-order-guard' ),
				array( 'status' => 400 )
			);
		}

		$settings = $this->settings->save_mode( $mode );

		return rest_ensure_response(
			array(
				'mode'      => (string) $settings['mode'],
				'enforcing' => (bool) ceog_is_enforcing(),
				'safeMode'  => (bool) ceog_is_safe_mode(),
			)
		);
	}

	/**
	 * Builds a consistently typed settings response.
	 *
	 * @param array<string, mixed> $settings Stored settings.
	 * @return array<string, mixed>
	 */
	private function settings_payload( $settings ) {
		return array(
			'settings' => CEOG_Settings::for_rest( $settings ),
			'meta'     => array(
				'safeMode'               => (bool) ceog_is_safe_mode(),
				'enforcing'              => (bool) ceog_is_enforcing(),
				'version'                => (string) CEOG_VERSION,
				'checkoutBlockDetected'  => (bool) CEOG_Settings::checkout_uses_block(),
				'nativeRateLimitEnabled' => (bool) CEOG_Settings::native_checkout_rate_limit_enabled(),
				'batchInspectionActive'  => true,
			),
		);
	}
}
