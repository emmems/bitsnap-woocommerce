<?php
/**
 * Creates the Bitsnap payment session for an order.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

use Bitsnap\WooCommerce\Api\ApiException;
use Bitsnap\WooCommerce\Mapping\ApiOrder;
use Bitsnap\WooCommerce\Mapping\CheckoutSessionBuilder;
use Bitsnap\WooCommerce\Plugin;
use Bitsnap\WooCommerce\Support\IdempotencyKey;
use Bitsnap\WooCommerce\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `CheckoutService.CreateCheckoutSession` for a WooCommerce order (contract section 9.1).
 */
final class PaymentStarter {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Order reader.
	 *
	 * @var OrderReader
	 */
	private $reader;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Plugin      $plugin Plugin container.
	 * @param OrderReader $reader Order reader.
	 * @param Logger      $logger Logger.
	 */
	public function __construct( Plugin $plugin, OrderReader $reader, Logger $logger ) {
		$this->plugin = $plugin;
		$this->reader = $reader;
		$this->logger = $logger;
	}

	/**
	 * Creates the session and stores its ids on the order.
	 *
	 * @param \WC_Order $order       Order.
	 * @param string    $success_url The order-received URL.
	 * @return string URL of the Bitsnap payment page.
	 * @throws ApiException When Bitsnap rejects the request or cannot be reached.
	 */
	public function start( \WC_Order $order, string $success_url ): string {
		try {
			$lock = new \Bitsnap\WooCommerce\Support\Lock( 'order:' . $order->get_id() );
		} catch ( \RuntimeException $e ) {
			throw new ApiException( 'Another payment operation is in progress. Please retry.', 0, 'busy' );
		}
		try {
			$order = OrderState::reload( $order->get_id() );
			if ( $order->is_paid() ) {
				return $success_url;
			}
			$credentials = $this->plugin->credentials();
			$environment = $credentials->environment();

			$built = CheckoutSessionBuilder::build( $this->reader->snapshot( $order, $success_url ) );
			if ( 0 !== $built['delta'] ) {
				$this->logger->info( 'Order ' . $order->get_id() . ': closed a rounding difference of ' . $built['delta'] . ' minor units.' );
			}

			/**
			 * Filters the `CreateCheckoutSession` request.
			 *
			 * The total must still equal lines + shipping - discount, or Bitsnap rejects it.
			 *
			 * @param array     $request Request body.
			 * @param \WC_Order $order   Order.
			 */
			$request = apply_filters( 'bitsnap_wc_checkout_request', $built['request'], $order );

			$fingerprint = IdempotencyKey::fingerprint( array_merge( $request, array( '_env' => $environment ) ) );
			$attempt     = IdempotencyKey::next_attempt(
				(int) $order->get_meta( Meta::ATTEMPT ),
				(string) $order->get_meta( Meta::FINGERPRINT ),
				$fingerprint,
				(int) $order->get_meta( Meta::EXPIRES_AT ),
				time()
			);
			$key         = IdempotencyKey::for_order( IdempotencyKey::site_hash( home_url() ), $order->get_id(), $attempt );

			$client = $this->plugin->client( $environment );
			try {
				$response = $client->call( 'CheckoutService', 'CreateCheckoutSession', $request, $key );
			} catch ( ApiException $e ) {
				// A key without the customers:pii scope cannot send buyer details. Pay anyway; the hosted page asks for them.
				if ( 'missing_scope' === $e->get_reason() && isset( $request['buyer'] ) && false !== strpos( implode( ' ', $e->get_metadata() ) . $e->getMessage(), 'customers:pii' ) ) {
					$this->logger->info( 'The key has no customers:pii scope: retrying without buyer details.' );
					$response = $client->call( 'CheckoutService', 'CreateCheckoutSession', CheckoutSessionBuilder::without_buyer( $request ), $key . '-nobuyer' );
				} else {
					throw $e;
				}
			}

			// Scheme and host only. wp_http_validate_url() also resolves DNS and rejects private addresses, which breaks local Bitsnap installs.
			$url   = isset( $response['url'] ) && is_string( $response['url'] ) ? $response['url'] : '';
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! isset( $parts['scheme'] ) || ! in_array( $parts['scheme'], array( 'https', 'http' ), true ) ) {
				throw new ApiException( 'Bitsnap did not return a payment URL.', 200, 'internal' );
			}

			$returned_env = ApiOrder::environment( isset( $response['environment'] ) && is_string( $response['environment'] ) ? $response['environment'] : '' );
			if ( null !== $returned_env && $returned_env !== $environment ) {
				$this->logger->error( sprintf( 'Order %d: the %s key created a %s session. Check that the test and live keys are not swapped.', $order->get_id(), $environment, $returned_env ) );
			}
			$expires = isset( $response['expiresAt'] ) && is_string( $response['expiresAt'] ) ? (int) strtotime( $response['expiresAt'] ) : 0;

			$order->update_meta_data( Meta::ORDER_ID, isset( $response['orderId'] ) && is_scalar( $response['orderId'] ) ? (string) $response['orderId'] : '' );
			$order->update_meta_data( Meta::ENVIRONMENT, $returned_env ?? $environment );
			$order->update_meta_data( Meta::ATTEMPT, $attempt );
			$order->update_meta_data( Meta::FINGERPRINT, $fingerprint );
			$order->update_meta_data( Meta::EXPIRES_AT, $expires );
			$order->delete_meta_data( Meta::RETURN_CHECKED );
			$order->delete_meta_data( Meta::RECONCILED_AT );
			$order->delete_meta_data( Meta::GAVE_UP );
			$order->add_order_note(
				sprintf(
				/* translators: 1: Bitsnap order id, 2: environment (test or live). */
					__( 'Bitsnap payment started (order %1$s, %2$s mode). The buyer was sent to the payment page.', 'bitsnap-for-woocommerce' ),
					(string) $order->get_meta( Meta::ORDER_ID ),
					$returned_env ?? $environment
				)
			);
			$order->save();

			return $url;
		} finally {
			$lock->release();
		}
	}
}
