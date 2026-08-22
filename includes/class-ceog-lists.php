<?php
/**
 * Shared allowlist and blocklist services.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns list storage, matching, classic-checkout enforcement, and order tools.
 */
final class CEOG_Lists {
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
	 * Settings keys owned by this service.
	 *
	 * @var string[]
	 */
	private static $list_keys = array(
		'blocklist_emails',
		'blocklist_email_domains',
		'blocklist_ips',
		'whitelist_ips',
		'whitelist_roles',
		'whitelist_payment_methods',
	);

	/**
	 * Creates the list service.
	 *
	 * @param CEOG_Settings $settings Settings service.
	 * @param CEOG_Logger   $logger   Protection event logger.
	 */
	public function __construct( CEOG_Settings $settings, CEOG_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Registers checkout and order-admin integrations.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'validate_store_api_order' ), 5, 1 );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'register_order_metabox' ) );
			add_action( 'admin_post_ceog_block_entity', array( $this, 'handle_block_entity' ) );
		}
	}

	/**
	 * Applies blocklists to Store API orders before payment is attempted.
	 *
	 * The WooCommerce RouteException is deliberately thrown outside the
	 * fail-open callback so an intended block is not mistaken for a plugin
	 * failure. If WooCommerce's exception contract is unavailable, checkout
	 * proceeds and the compatibility problem is written to the status log.
	 *
	 * @param mixed $order WooCommerce order object.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException On an enforced match.
	 */
	public function validate_store_api_order( $order ) {
		$should_block = (bool) ceog_safe(
			function () use ( $order ) {
				$settings = $this->settings->get();
				if ( 'yes' !== ( $settings['enabled'] ?? 'yes' ) || ! is_object( $order ) ) {
					return false;
				}

				$context = array(
					'billing_email'  => method_exists( $order, 'get_billing_email' ) ? $order->get_billing_email() : '',
					'ip'             => method_exists( $order, 'get_customer_ip_address' ) ? $order->get_customer_ip_address() : '',
					'payment_method' => method_exists( $order, 'get_payment_method' ) ? $order->get_payment_method() : '',
					'user_roles'     => self::order_user_roles( $order ),
				);
				$match = $this->match_blocklist( $context );

				if ( empty( $match['matched'] ) ) {
					return false;
				}

				$reason = self::match_reason( $match['type'] );
				$meta   = array( 'match_type' => $match['type'], 'flow' => 'store_api' );
				if ( 'email_domain' === $match['type'] ) {
					$meta['email_domain'] = $match['value'];
				}

				if ( method_exists( $order, 'update_meta_data' ) ) {
					$order->update_meta_data( '_ceog_list_match', sanitize_key( $match['type'] ) );
					if ( method_exists( $order, 'save_meta_data' ) ) {
						$order->save_meta_data();
					}
				}

				$logged = $this->logger->log(
					'blocklist_hit',
					array(
						'ip'       => $context['ip'],
						'mode'     => ceog_is_enforcing() ? 'enforce' : 'monitor',
						'route'    => '/wc/store/v1/checkout',
						'order_id' => method_exists( $order, 'get_id' ) ? absint( $order->get_id() ) : 0,
						'reason'   => $reason,
						'meta'     => $meta,
					)
				);

				return $logged && ceog_is_enforcing();
			},
			false,
			'Checking Store API order blocklists'
		);

		if ( ! $should_block ) {
			return;
		}

		$exception_class = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		if ( ! class_exists( $exception_class ) ) {
			ceog_log_internal_error(
				new RuntimeException( 'WooCommerce Store API RouteException is unavailable.' ),
				'Blocking a Store API blocklist match'
			);

			return;
		}

		throw new $exception_class(
			'ceog_blocklist',
			esc_html( __( 'We could not process your order. Please contact the store for assistance.', 'coderembassy-order-guard' ) ),
			403
		);
	}

	/**
	 * Returns whether any role, IP, or payment-method whitelist matches.
	 *
	 * @param array<string, mixed> $context Request or order context.
	 * @return bool
	 */
	public function is_whitelisted( $context = array() ) {
		return self::evaluate_whitelist( $context, $this->settings->get() );
	}

	/**
	 * Returns the first blocklist match after applying whitelist precedence.
	 *
	 * @param array<string, mixed> $context Request or order context.
	 * @return array<string, mixed>
	 */
	public function match_blocklist( $context = array() ) {
		$settings = $this->settings->get();

		if ( self::evaluate_whitelist( $context, $settings ) ) {
			return array(
				'matched'     => false,
				'whitelisted' => true,
				'type'        => '',
			);
		}

		$match = self::evaluate_blocklist( $context, $settings );

		/**
		 * Allows an active add-on to supply additional list matches.
		 * Whitelist precedence has already been applied above.
		 *
		 * @param array<string, mixed> $match    Current match.
		 * @param array<string, mixed> $context  Checkout context.
		 * @param array<string, mixed> $settings Sanitized Free settings.
		 */
		return apply_filters( 'ceog_blocklist_match', $match, $context, $settings );
	}

	/**
	 * Applies blocklists to classic checkout validation.
	 *
	 * @param array<string, mixed> $data   Checkout data.
	 * @param mixed                $errors WooCommerce validation errors.
	 * @return void
	 */
	public function validate_classic_checkout( $data, $errors ) {
		ceog_safe(
			function () use ( $data, $errors ) {
				$settings = $this->settings->get();
				if ( 'yes' !== ( $settings['enabled'] ?? 'yes' ) ) {
					return;
				}

				$data    = is_array( $data ) ? $data : array();
				$context = array(
					'billing_email' => $data['billing_email'] ?? '',
					'payment_method' => $data['payment_method'] ?? '',
					'ip'             => CEOG_IP::resolve( $settings ),
				);
				$match   = $this->match_blocklist( $context );

				if ( empty( $match['matched'] ) ) {
					return;
				}

				$reason = self::match_reason( $match['type'] );
				$meta   = array( 'match_type' => $match['type'] );
				if ( 'email_domain' === $match['type'] ) {
					$meta['email_domain'] = $match['value'];
				}

				$logged = $this->logger->log(
					'blocklist_hit',
					array(
						'ip'     => $context['ip'],
						'mode'   => ceog_is_enforcing() ? 'enforce' : 'monitor',
						'route'  => '/checkout',
						'reason' => $reason,
						'meta'   => $meta,
					)
				);

				if ( $logged && ceog_is_enforcing() && is_object( $errors ) && method_exists( $errors, 'add' ) ) {
					$errors->add(
						'ceog_blocklist',
						esc_html( __( 'We could not process your order. Please contact the store for assistance.', 'coderembassy-order-guard' ) )
					);
				}
			},
			null,
			'Checking classic checkout blocklists'
		);
	}

	/**
	 * Returns the typed REST payload for both list panels.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payload() {
		$settings = $this->settings->get();

		return array(
			'blocklist' => array(
				'emails'  => array_values( $settings['blocklist_emails'] ),
				'domains' => array_values( $settings['blocklist_email_domains'] ),
				'ips'     => array_values( $settings['blocklist_ips'] ),
			),
			'whitelist' => array(
				'ips'             => array_values( $settings['whitelist_ips'] ),
				'roles'           => array_values( $settings['whitelist_roles'] ),
				'payment_methods' => array_values( $settings['whitelist_payment_methods'] ),
			),
			'counts'    => array(
				'blocked' => count( $settings['blocklist_emails'] ) + count( $settings['blocklist_email_domains'] ) + count( $settings['blocklist_ips'] ),
				'allowed' => count( $settings['whitelist_ips'] ) + count( $settings['whitelist_roles'] ) + count( $settings['whitelist_payment_methods'] ),
			),
			'options'   => array(
				'roles'           => self::available_roles( $settings['whitelist_roles'] ),
				'payment_methods' => self::available_payment_methods( $settings['whitelist_payment_methods'] ),
			),
		);
	}

	/**
	 * Saves a validated list patch.
	 *
	 * @param mixed $input List values.
	 * @return array<string, mixed>
	 */
	public function save_patch( $input ) {
		$this->settings->save( self::sanitize_patch( $input ) );
		delete_transient( 'ceog_dashboard_cache' );

		return $this->get_payload();
	}

	/**
	 * Adds one email or IP to its blocklist without entry-count limits.
	 *
	 * @param string $type  email|ip.
	 * @param mixed  $value Entity value.
	 * @return array<string, mixed>|false
	 */
	public function add_block_entity( $type, $value ) {
		$type     = sanitize_key( (string) $type );
		$settings = $this->settings->get();
		$added    = false;

		if ( 'email' === $type ) {
			$value = strtolower( sanitize_email( is_scalar( $value ) ? (string) $value : '' ) );
			if ( '' === $value ) {
				return false;
			}

			$emails = array_map( 'strtolower', $settings['blocklist_emails'] );
			if ( ! in_array( $value, $emails, true ) ) {
				$settings['blocklist_emails'][] = $value;
				$added = true;
			}
		} elseif ( 'ip' === $type ) {
			$value = CEOG_IP::canonicalize( $value );
			if ( '' === $value ) {
				return false;
			}

			if ( ! self::ip_in_list( $value, $settings['blocklist_ips'] ) ) {
				$settings['blocklist_ips'][] = $value;
				$added = true;
			}
		} else {
			return false;
		}

		if ( $added ) {
			$key = 'email' === $type ? 'blocklist_emails' : 'blocklist_ips';
			$this->settings->save( array( $key => $settings[ $key ] ) );
			delete_transient( 'ceog_dashboard_cache' );
		}

		return array(
			'added' => $added,
			'lists' => $this->get_payload(),
		);
	}

	/**
	 * Validates the object accepted by POST /lists.
	 *
	 * @param mixed $value Candidate patch.
	 * @return bool
	 */
	public static function validate_patch( $value ) {
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), self::$list_keys ) ) {
			return false;
		}

		foreach ( $value as $key => $items ) {
			if ( ! is_array( $items ) ) {
				return false;
			}

			foreach ( $items as $item ) {
				if ( ! is_scalar( $item ) || ! self::valid_list_item( $key, $item ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Validates a bounded scalar value for the one-click block endpoint.
	 *
	 * @param mixed $value Candidate entity.
	 * @return bool
	 */
	public static function validate_entity_value( $value ) {
		return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 320;
	}

	/**
	 * Sanitizes every list entry according to its list type.
	 *
	 * @param mixed $input Candidate patch.
	 * @return array<string, array<int, string>>
	 */
	public static function sanitize_patch( $input ) {
		$output = array();

		foreach ( is_array( $input ) ? $input : array() as $key => $items ) {
			if ( ! in_array( $key, self::$list_keys, true ) || ! is_array( $items ) ) {
				continue;
			}

			$clean = array();
			foreach ( $items as $item ) {
				if ( ! is_scalar( $item ) ) {
					continue;
				}

				if ( 'blocklist_emails' === $key ) {
					$value = strtolower( sanitize_email( (string) $item ) );
				} elseif ( 'blocklist_email_domains' === $key ) {
					$value = self::clean_domain( $item );
				} elseif ( in_array( $key, array( 'blocklist_ips', 'whitelist_ips' ), true ) ) {
					$value = CEOG_IP::canonicalize( $item );
				} else {
					$value = sanitize_key( (string) $item );
				}

				if ( '' !== $value ) {
					$clean[] = $value;
				}
			}

			$output[ $key ] = array_values( array_unique( $clean ) );
		}

		return $output;
	}

	/**
	 * Evaluates role, IP, and payment-method whitelist precedence.
	 *
	 * @param array<string, mixed>      $context  Request or order context.
	 * @param array<string, mixed>|null $settings Optional settings.
	 * @return bool
	 */
	public static function evaluate_whitelist( $context = array(), $settings = null ) {
		$context  = is_array( $context ) ? $context : array();
		$settings = is_array( $settings ) ? $settings : ceog_get_settings();
		$roles    = self::context_roles( $context );

		if ( array_intersect( $roles, (array) ( $settings['whitelist_roles'] ?? array() ) ) ) {
			return true;
		}

		$ip = array_key_exists( 'ip', $context ) ? $context['ip'] : CEOG_IP::resolve( $settings );
		if ( self::ip_in_list( $ip, (array) ( $settings['whitelist_ips'] ?? array() ) ) ) {
			return true;
		}

		$payment_method = self::context_payment_method( $context );

		return '' !== $payment_method
			&& in_array( $payment_method, (array) ( $settings['whitelist_payment_methods'] ?? array() ), true );
	}

	/**
	 * Evaluates exact email, email domain, and /64-aware IP blocklists.
	 *
	 * @param array<string, mixed>      $context  Request or order context.
	 * @param array<string, mixed>|null $settings Optional settings.
	 * @return array<string, mixed>
	 */
	public static function evaluate_blocklist( $context = array(), $settings = null ) {
		$context  = is_array( $context ) ? $context : array();
		$settings = is_array( $settings ) ? $settings : ceog_get_settings();
		$email    = strtolower( sanitize_email( is_scalar( $context['billing_email'] ?? '' ) ? (string) $context['billing_email'] : '' ) );

		if ( '' !== $email && in_array( $email, array_map( 'strtolower', (array) ( $settings['blocklist_emails'] ?? array() ) ), true ) ) {
			return array( 'matched' => true, 'whitelisted' => false, 'type' => 'email', 'value' => $email );
		}

		$domain = false !== strrpos( $email, '@' ) ? substr( $email, strrpos( $email, '@' ) + 1 ) : '';
		if ( '' !== $domain && in_array( $domain, (array) ( $settings['blocklist_email_domains'] ?? array() ), true ) ) {
			return array( 'matched' => true, 'whitelisted' => false, 'type' => 'email_domain', 'value' => $domain );
		}

		$ip = array_key_exists( 'ip', $context ) ? $context['ip'] : CEOG_IP::resolve( $settings );
		if ( self::ip_in_list( $ip, (array) ( $settings['blocklist_ips'] ?? array() ) ) ) {
			return array( 'matched' => true, 'whitelisted' => false, 'type' => 'ip', 'value' => CEOG_IP::canonicalize( $ip ) );
		}

		return array( 'matched' => false, 'whitelisted' => false, 'type' => '', 'value' => '' );
	}

	/**
	 * Registers the metabox on both legacy and HPOS order screens.
	 *
	 * @return void
	 */
	public function register_order_metabox() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		add_meta_box(
			'ceog-order-signals',
			__( 'Order Guard', 'coderembassy-order-guard' ),
			array( $this, 'render_order_metabox' ),
			array_values( array_unique( $screens ) ),
			'side',
			'default'
		);
	}

	/**
	 * Shows current list status, recorded signals, and one-click actions.
	 *
	 * @param mixed $post_or_order Legacy post or HPOS order object.
	 * @return void
	 */
	public function render_order_metabox( $post_or_order ) {
		$order = is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' )
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) && isset( $post_or_order->ID ) ? $post_or_order->ID : 0 );

		if ( ! $order ) {
			return;
		}

		$email = strtolower( (string) $order->get_billing_email() );
		$ip    = CEOG_IP::canonicalize( $order->get_customer_ip_address() );
		$context = array(
			'billing_email'  => $email,
			'ip'             => $ip,
			'payment_method' => $order->get_payment_method(),
			'user_roles'     => array(),
		);
		$whitelisted = $this->is_whitelisted( $context );
		$match       = $this->match_blocklist( $context );
		$status      = $whitelisted
			? __( 'Whitelisted', 'coderembassy-order-guard' )
			: ( ! empty( $match['matched'] ) ? __( 'Blocklisted', 'coderembassy-order-guard' ) : __( 'No list match', 'coderembassy-order-guard' ) );
		$signals = array(
			__( 'Origin', 'coderembassy-order-guard' )  => $order->get_meta( '_ceog_origin_signal', true ),
			__( 'Session', 'coderembassy-order-guard' ) => $order->get_meta( '_ceog_session_present', true ),
			__( 'Lists', 'coderembassy-order-guard' )   => $order->get_meta( '_ceog_list_match', true ),
			__( 'Breaker', 'coderembassy-order-guard' ) => $order->get_meta( '_ceog_breaker_context', true ),
		);
		$signals = array_filter(
			$signals,
			static function ( $value ) {
				return is_scalar( $value ) && '' !== trim( (string) $value );
			}
		);
		$settings      = $this->settings->get();
		$email_blocked = in_array( $email, array_map( 'strtolower', $settings['blocklist_emails'] ), true );
		$ip_blocked    = self::ip_in_list( $ip, $settings['blocklist_ips'] );
		?>
		<p><strong><?php esc_html_e( 'Current list status', 'coderembassy-order-guard' ); ?></strong><br><?php echo esc_html( $status ); ?></p>
		<?php if ( $signals ) : ?>
			<ul class="ceog-order-signals">
				<?php foreach ( $signals as $label => $value ) : ?>
					<li><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( (string) $value ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'No Order Guard signals have been recorded for this order.', 'coderembassy-order-guard' ); ?></p>
		<?php endif; ?>
		<hr>
		<?php
		$this->render_block_button( $order->get_id(), 'email', $email, __( 'Block this email', 'coderembassy-order-guard' ), $email_blocked );
		$this->render_block_button( $order->get_id(), 'ip', $ip, __( 'Block this IP', 'coderembassy-order-guard' ), $ip_blocked );
	}

	/**
	 * Handles nonce-protected one-click block actions from an order.
	 *
	 * @return void
	 */
	public function handle_block_entity() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to update Order Guard lists.', 'coderembassy-order-guard' ) );
		}

		$order_id = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
		check_admin_referer( 'ceog_block_entity_' . $order_id );
		$order = wc_get_order( $order_id );
		$type  = sanitize_key( wp_unslash( $_POST['entity_type'] ?? '' ) );

		if ( ! $order || ! in_array( $type, array( 'email', 'ip' ), true ) ) {
			wp_die( esc_html__( 'The requested Order Guard action is invalid.', 'coderembassy-order-guard' ) );
		}

		$expected = 'email' === $type
			? strtolower( (string) $order->get_billing_email() )
			: CEOG_IP::canonicalize( $order->get_customer_ip_address() );
		$supplied = isset( $_POST['entity_value'] ) && is_scalar( $_POST['entity_value'] )
			? (string) wp_unslash( $_POST['entity_value'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by type immediately below.
			: '';
		$supplied = 'email' === $type ? strtolower( sanitize_email( $supplied ) ) : CEOG_IP::canonicalize( $supplied );

		if ( '' === $expected || $expected !== $supplied ) {
			wp_die( esc_html__( 'The requested Order Guard value is invalid.', 'coderembassy-order-guard' ) );
		}

		$result = $this->add_block_entity( $type, $supplied );
		if ( false === $result ) {
			wp_die( esc_html__( 'The requested Order Guard value is invalid.', 'coderembassy-order-guard' ) );
		}

		if ( ! empty( $result['added'] ) ) {
			$order->add_order_note(
				'email' === $type
					? __( 'Order Guard: billing email added to the blocklist.', 'coderembassy-order-guard' )
					: __( 'Order Guard: customer IP added to the blocklist.', 'coderembassy-order-guard' )
			);
		}

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = $order->get_edit_order_url();
		}

		wp_safe_redirect( add_query_arg( 'ceog_blocked', $type, $redirect ) );
		exit;
	}

	/**
	 * Renders one order-bound nonce-protected admin action.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $type     Entity type.
	 * @param string $value    Entity value.
	 * @param string $label    Button label.
	 * @param bool   $blocked  Whether the value is already blocked.
	 * @return void
	 */
	private function render_block_button( $order_id, $type, $value, $label, $blocked ) {
		if ( '' === $value ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 0">
			<input type="hidden" name="action" value="ceog_block_entity">
			<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
			<input type="hidden" name="entity_type" value="<?php echo esc_attr( $type ); ?>">
			<input type="hidden" name="entity_value" value="<?php echo esc_attr( $value ); ?>">
			<?php wp_nonce_field( 'ceog_block_entity_' . $order_id ); ?>
			<button type="submit" class="button button-secondary" <?php disabled( $blocked ); ?>><?php echo esc_html( $blocked ? __( 'Already blocklisted', 'coderembassy-order-guard' ) : $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Returns current-user roles unless an explicit role context is supplied.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return string[]
	 */
	private static function context_roles( $context ) {
		if ( array_key_exists( 'user_roles', $context ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', is_array( $context['user_roles'] ) ? $context['user_roles'] : array() ) ) );
		}

		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();

			return is_object( $user ) && isset( $user->roles ) && is_array( $user->roles )
				? array_values( array_filter( array_map( 'sanitize_key', $user->roles ) ) )
				: array();
		}

		return array();
	}

	/**
	 * Resolves roles from an order customer without falling back to the admin.
	 *
	 * @param object $order WooCommerce order.
	 * @return string[]
	 */
	private static function order_user_roles( $order ) {
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
	 * Validates one semantic list value before route sanitization.
	 *
	 * @param string $key  List key.
	 * @param mixed  $item Candidate value.
	 * @return bool
	 */
	private static function valid_list_item( $key, $item ) {
		$item = trim( (string) $item );
		if ( '' === $item ) {
			return false;
		}

		if ( 'blocklist_emails' === $key ) {
			return '' !== sanitize_email( $item );
		}

		if ( 'blocklist_email_domains' === $key ) {
			return '' !== self::clean_domain( $item );
		}

		if ( in_array( $key, array( 'blocklist_ips', 'whitelist_ips' ), true ) ) {
			return '' !== CEOG_IP::canonicalize( $item );
		}

		return '' !== sanitize_key( $item );
	}

	/**
	 * Returns a valid lowercase ASCII email domain.
	 *
	 * @param mixed $value Candidate domain.
	 * @return string
	 */
	private static function clean_domain( $value ) {
		$domain = strtolower( ltrim( sanitize_text_field( (string) $value ), '@' ) );

		return false !== strpos( $domain, '.' ) && filter_var( 'ceog@' . $domain, FILTER_VALIDATE_EMAIL )
			? $domain
			: '';
	}

	/**
	 * Returns searchable role labels while preserving saved custom roles.
	 *
	 * @param string[] $selected Saved role keys.
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function available_roles( $selected ) {
		$options = array();

		if ( function_exists( 'wp_roles' ) ) {
			$roles = wp_roles();
			foreach ( is_object( $roles ) && isset( $roles->roles ) && is_array( $roles->roles ) ? $roles->roles : array() as $key => $details ) {
				$key = sanitize_key( $key );
				if ( '' === $key ) {
					continue;
				}

				$label = isset( $details['name'] ) ? sanitize_text_field( $details['name'] ) : $key;
				if ( function_exists( 'translate_user_role' ) ) {
					$label = translate_user_role( $label );
				}

				$label           = sanitize_text_field( $label );
				$options[ $key ] = '' !== $label ? $label : $key;
			}
		}

		return self::merge_selected_options( $options, $selected );
	}

	/**
	 * Returns installed WooCommerce gateways plus saved manual keys.
	 *
	 * @param string[] $selected Saved payment-method keys.
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function available_payment_methods( $selected ) {
		$options = ceog_safe(
			static function () {
				if ( ! function_exists( 'WC' ) ) {
					return array();
				}

				$woocommerce = WC();
				if ( ! is_object( $woocommerce ) || ! method_exists( $woocommerce, 'payment_gateways' ) ) {
					return array();
				}

				$manager  = $woocommerce->payment_gateways();
				$gateways = is_object( $manager ) && method_exists( $manager, 'payment_gateways' )
					? $manager->payment_gateways()
					: array();
				$output   = array();

				foreach ( is_array( $gateways ) ? $gateways : array() as $gateway ) {
					$key = is_object( $gateway ) && isset( $gateway->id ) ? sanitize_key( $gateway->id ) : '';
					if ( '' === $key ) {
						continue;
					}

					$label = method_exists( $gateway, 'get_method_title' )
						? $gateway->get_method_title()
						: ( method_exists( $gateway, 'get_title' ) ? $gateway->get_title() : $key );
					$label          = sanitize_text_field( $label );
					$output[ $key ] = '' !== $label ? $label : $key;
				}

				return $output;
			},
			array(),
			'Reading WooCommerce payment methods for list options'
		);

		return self::merge_selected_options( is_array( $options ) ? $options : array(), $selected );
	}

	/**
	 * Merges saved keys into an option map and returns stable typed rows.
	 *
	 * @param array<string, string> $options  Known option labels by key.
	 * @param string[]              $selected Saved keys.
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function merge_selected_options( $options, $selected ) {
		foreach ( is_array( $selected ) ? $selected : array() as $key ) {
			$key = sanitize_key( $key );
			if ( '' !== $key && ! isset( $options[ $key ] ) ) {
				$options[ $key ] = $key;
			}
		}

		uasort( $options, 'strnatcasecmp' );
		$output = array();
		foreach ( $options as $value => $label ) {
			$output[] = array(
				'value' => sanitize_key( $value ),
				'label' => sanitize_text_field( $label ),
			);
		}

		return $output;
	}

	/**
	 * Resolves a sanitized payment method from explicit or checkout context.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return string
	 */
	private static function context_payment_method( $context ) {
		if ( isset( $context['payment_method'] ) && is_scalar( $context['payment_method'] ) ) {
			return sanitize_key( (string) $context['payment_method'] );
		}

		if ( isset( $_POST['payment_method'] ) && is_scalar( $_POST['payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce owns checkout nonce verification.
			return sanitize_key( wp_unslash( $_POST['payment_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce owns checkout nonce verification.
		}

		if ( function_exists( 'WC' ) ) {
			$woocommerce = WC();
			if ( is_object( $woocommerce ) && $woocommerce->session ) {
				return sanitize_key( (string) $woocommerce->session->get( 'chosen_payment_method', '' ) );
			}
		}

		return '';
	}

	/**
	 * Matches IPv4 exactly and all IPv6 values by their /64 network.
	 *
	 * @param mixed   $ip   Candidate address.
	 * @param string[] $list Stored addresses.
	 * @return bool
	 */
	private static function ip_in_list( $ip, $list ) {
		$needle = CEOG_IP::normalize( $ip );
		if ( '' === $needle ) {
			return false;
		}

		foreach ( is_array( $list ) ? $list : array() as $entry ) {
			$entry = is_scalar( $entry ) ? preg_replace( '/\/64$/', '', trim( (string) $entry ) ) : '';
			if ( $needle === CEOG_IP::normalize( $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a non-sensitive reason string for logging.
	 *
	 * @param string $type Match type.
	 * @return string
	 */
	private static function match_reason( $type ) {
		if ( 'email' === $type ) {
			return __( 'Billing email matched the blocklist.', 'coderembassy-order-guard' );
		}

		if ( 'email_domain' === $type ) {
			return __( 'Billing email domain matched the blocklist.', 'coderembassy-order-guard' );
		}

		return __( 'Customer IP matched the blocklist.', 'coderembassy-order-guard' );
	}
}

/**
 * Shared whitelist-first helper for every protection layer.
 *
 * @param array<string, mixed> $context Request or order context.
 * @return bool
 */
function ceog_is_whitelisted( $context = array() ) {
	return CEOG_Lists::evaluate_whitelist( $context );
}
