<?php
/**
 * Unknown-origin order review signals.
 *
 * @package CoderEmbassy_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags unattributed first-time guest orders without blocking checkout.
 */
final class CEOG_Origin_Rules {
	/** @var CEOG_Settings */
	private $settings;

	/** @var CEOG_Logger */
	private $logger;

	/**
	 * @param CEOG_Settings $settings Settings service.
	 * @param CEOG_Logger   $logger   Protection event logger.
	 */
	public function __construct( CEOG_Settings $settings, CEOG_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/** Registers classic and Store API order-processed hooks. */
	public function register_hooks() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'evaluate_classic_order' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'evaluate_store_api_order' ), 20, 1 );
	}

	/**
	 * Evaluates an order created by classic checkout.
	 *
	 * @param int                 $order_id   Order ID.
	 * @param array<string,mixed> $posted_data Sanitized checkout data.
	 * @param mixed               $order      WooCommerce order object.
	 * @return void
	 */
	public function evaluate_classic_order( $order_id, $posted_data = array(), $order = null ) {
		unset( $posted_data );

		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}

		$this->evaluate_order( $order, 'classic' );
	}

	/**
	 * Evaluates an order created by Checkout Block or the Store API.
	 *
	 * @param mixed $order WooCommerce order object.
	 * @return void
	 */
	public function evaluate_store_api_order( $order ) {
		$this->evaluate_order( $order, 'store_api' );
	}

	/**
	 * Records an unknown-origin review signal when every narrow rule matches.
	 *
	 * Origin is a review signal, not a fraud verdict. This method never blocks,
	 * cancels, or modifies a paid order, regardless of protection mode.
	 *
	 * @param mixed  $order WooCommerce order object.
	 * @param string $flow  classic|store_api.
	 * @return bool Whether the order was newly flagged.
	 */
	public function evaluate_order( $order, $flow = 'classic' ) {
		return (bool) ceog_safe(
			function () use ( $order, $flow ) {
				$settings = $this->settings->get();
				if (
					'yes' !== ( $settings['enabled'] ?? 'yes' )
					|| 'flag' !== ( $settings['unknown_origin_action'] ?? 'flag' )
					|| ! is_object( $order )
					|| ! method_exists( $order, 'get_meta' )
					|| ! method_exists( $order, 'update_meta_data' )
				) {
					return false;
				}

				if ( method_exists( $order, 'is_paid' ) && $order->is_paid() ) {
					return false;
				}

				$customer_id = method_exists( $order, 'get_customer_id' )
					? absint( $order->get_customer_id() )
					: ( method_exists( $order, 'get_user_id' ) ? absint( $order->get_user_id() ) : 0 );
				if ( $customer_id > 0 ) {
					return false;
				}

				$payment_method = method_exists( $order, 'get_payment_method' )
					? sanitize_key( (string) $order->get_payment_method() )
					: '';
				if ( '' !== $payment_method && in_array( $payment_method, (array) ( $settings['whitelist_payment_methods'] ?? array() ), true ) ) {
					return false;
				}

				$source_type = sanitize_key( (string) $order->get_meta( '_wc_order_attribution_source_type', true ) );
				if ( '' !== $source_type && 'unknown' !== $source_type ) {
					return false;
				}

				if ( '' !== trim( (string) $order->get_meta( '_ceog_origin_signal', true ) ) ) {
					return false;
				}

				if ( $this->guest_has_completed_order( $order ) ) {
					return false;
				}

				$order_id       = method_exists( $order, 'get_id' ) ? absint( $order->get_id() ) : 0;
				$placed_on_hold = 'yes' === ( $settings['unknown_origin_onhold'] ?? 'no' )
					&& method_exists( $order, 'set_status' );
				$logged = $this->logger->log(
					'flagged_order',
					array(
						'ip'       => method_exists( $order, 'get_customer_ip_address' ) ? $order->get_customer_ip_address() : '',
						'order_id' => $order_id,
						'reason'   => __( 'Unknown order attribution requires review.', 'coderembassy-order-guard' ),
						'meta'     => array(
							'flow'           => 'store_api' === $flow ? 'store_api' : 'classic',
							'source_type'    => '' === $source_type ? 'missing' : 'unknown',
							'placed_on_hold' => $placed_on_hold,
						),
					)
				);
				if ( ! $logged ) {
					return false;
				}

				$order->update_meta_data( '_ceog_origin_signal', 'unknown' );
				if ( method_exists( $order, 'add_order_note' ) ) {
					$order->add_order_note(
						__( 'Order Guard: unknown origin - review before fulfilment.', 'coderembassy-order-guard' )
					);
				}

				if ( $placed_on_hold ) {
					$order->set_status( 'on-hold' );
				}

				if ( method_exists( $order, 'save' ) ) {
					$order->save();
				} elseif ( method_exists( $order, 'save_meta_data' ) ) {
					$order->save_meta_data();
				}

				return true;
			},
			false,
			'Evaluating unknown order origin'
		);
	}

	/**
	 * Checks prior completed guest orders by billing email through WC_Order_Query.
	 *
	 * @param object $order WooCommerce order object.
	 * @return bool
	 */
	private function guest_has_completed_order( $order ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! method_exists( $order, 'get_billing_email' ) ) {
			return false;
		}

		$email = strtolower( sanitize_email( (string) $order->get_billing_email() ) );
		if ( '' === $email ) {
			return false;
		}

		$order_id = method_exists( $order, 'get_id' ) ? absint( $order->get_id() ) : 0;
		$previous = wc_get_orders(
			array(
				'billing_email' => $email,
				'status'        => 'completed',
				'exclude'       => $order_id > 0 ? array( $order_id ) : array(),
				'limit'         => 1,
				'return'        => 'ids',
			)
		);

		return is_array( $previous ) && ! empty( $previous );
	}
}
