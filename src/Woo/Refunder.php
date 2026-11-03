<?php
/**
 * Refunds through Bitsnap.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

use Bitsnap\WooCommerce\Api\ApiException;
use Bitsnap\WooCommerce\Mapping\ApiOrder;
use Bitsnap\WooCommerce\Plugin;
use Bitsnap\WooCommerce\Support\IdempotencyKey;
use Bitsnap\WooCommerce\Support\Logger;
use Bitsnap\WooCommerce\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `RefundsService.CreateRefund` (contract section 9.3), called from `process_refund()`.
 */
final class Refunder {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Order updater (for the refund id list).
	 *
	 * @var OrderUpdater
	 */
	private $updater;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin  Plugin container.
	 * @param OrderUpdater $updater Order updater.
	 * @param Logger       $logger  Logger.
	 */
	public function __construct( Plugin $plugin, OrderUpdater $updater, Logger $logger ) {
		$this->plugin  = $plugin;
		$this->updater = $updater;
		$this->logger  = $logger;
	}

	/**
	 * Refunds part or all of a payment.
	 *
	 * WooCommerce creates the refund record before it calls the gateway, so the newest
	 * refund of the order is the one being processed. Its id makes the idempotency key.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount in major units, null for the full order total.
	 * @param string     $reason   Reason typed by the admin.
	 * @return true|\WP_Error True when Bitsnap accepted the refund.
	 */
	public function refund( int $order_id, $amount, string $reason ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'bitsnap_refund_order', __( 'The order was not found.', 'bitsnap-for-woocommerce' ) );
		}

		$bitsnap_id  = (string) $order->get_meta( Meta::ORDER_ID );
		$environment = (string) $order->get_meta( Meta::ENVIRONMENT );
		if ( '' === $bitsnap_id || ! in_array( $environment, array( 'test', 'live' ), true ) ) {
			return new \WP_Error( 'bitsnap_refund_no_payment', __( 'This order was not paid through Bitsnap, so Bitsnap cannot refund it.', 'bitsnap-for-woocommerce' ) );
		}
		if ( ! $this->plugin->credentials()->is_configured( $environment ) ) {
			return new \WP_Error(
				'bitsnap_refund_no_key',
				sprintf(
					/* translators: %s: environment, test or live. */
					__( 'There is no %s API key in the gateway settings, so the refund cannot be sent to Bitsnap.', 'bitsnap-for-woocommerce' ),
					$environment
				)
			);
		}

		$minor = null === $amount ? Money::to_minor( (float) $order->get_total() ) : Money::to_minor( (float) $amount );
		if ( $minor <= 0 ) {
			return new \WP_Error( 'bitsnap_refund_amount', __( 'The refund amount must be greater than zero.', 'bitsnap-for-woocommerce' ) );
		}

		$request = array(
			'orderId' => $bitsnap_id,
			'amount'  => Money::make( $minor, $order->get_currency() ),
		);
		if ( '' !== $reason ) {
			$request['reason'] = $reason;
		}

		try {
			$response = $this->plugin->client( $environment )->call(
				'RefundsService',
				'CreateRefund',
				$request,
				IdempotencyKey::for_refund( $this->wc_refund_id( $order, $minor ) )
			);
		} catch ( ApiException $e ) {
			$this->logger->error( sprintf( 'Refund of order %d failed: %s %s', $order_id, $e->get_reason(), $e->getMessage() ) );
			return new \WP_Error( 'bitsnap_refund_' . ( '' !== $e->get_reason() ? $e->get_reason() : 'failed' ), ErrorMessages::describe( $e ) );
		}

		$refund = isset( $response['refund'] ) && is_array( $response['refund'] ) ? $response['refund'] : array();
		$status = ApiOrder::enum( isset( $refund['status'] ) && is_string( $refund['status'] ) ? $refund['status'] : '', 'REFUND_STATUS_' );
		$id     = isset( $refund['id'] ) && is_scalar( $refund['id'] ) ? (string) $refund['id'] : '';

		if ( 'MANUAL_REQUIRED' === $status ) {
			return new \WP_Error(
				'bitsnap_refund_manual',
				__( 'This payment method cannot be refunded through Bitsnap (cash on delivery or a manual transfer). Return the money yourself, then record it with "Refund manually".', 'bitsnap-for-woocommerce' )
			);
		}
		if ( 'FAILED' === $status ) {
			return new \WP_Error( 'bitsnap_refund_failed', __( 'Bitsnap could not make the refund. Check the payment in the Bitsnap dashboard.', 'bitsnap-for-woocommerce' ) );
		}

		// Record the id now, so the REFUND_SUCCEEDED webhook for this refund does not create a second WooCommerce refund.
		if ( '' !== $id ) {
			$this->updater->remember( $order, Meta::REFUND_IDS, $id );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: Bitsnap refund id, 3: refund status such as SUCCEEDED or PENDING. */
				__( 'Refund of %1$s sent to Bitsnap (refund %2$s, status %3$s).', 'bitsnap-for-woocommerce' ),
				Money::to_major_string( $minor ) . ' ' . $order->get_currency(),
				'' !== $id ? $id : '-',
				'' !== $status ? $status : '-'
			)
		);

		return true;
	}

	/**
	 * Id of the WooCommerce refund being processed.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $minor Refund amount in minor units.
	 * @return string Refund id, or a stable fallback made from the order, count and amount.
	 */
	private function wc_refund_id( \WC_Order $order, int $minor ): string {
		$refunds = $order->get_refunds();
		if ( array() !== $refunds ) {
			$latest = reset( $refunds );
			if ( $latest instanceof \WC_Order_Refund && Money::to_minor( (float) $latest->get_amount() ) === $minor ) {
				return (string) $latest->get_id();
			}
		}
		return sprintf( '%d-%d-%d', $order->get_id(), count( $refunds ), $minor );
	}
}
