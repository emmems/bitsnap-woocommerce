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
	 * Refund created in this request, keyed by order.
	 *
	 * @var array<int,\WC_Order_Refund>
	 */
	private static $created = array();

	/**
	 * Captures the actual refund before WooCommerce calls process_refund.
	 *
	 * @param \WC_Order_Refund $refund Refund being created.
	 * @param array            $args Creation arguments.
	 * @return void
	 */
	public static function capture( $refund, array $args ): void {
		if ( $refund instanceof \WC_Order_Refund && ! empty( $args['refund_payment'] ) ) {
			self::$created[ $refund->get_parent_id() ] = $refund;
		}
	}


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
	 * WooCommerce saves the refund before calling the gateway. The creation hook captures
	 * that exact record in this request; its id makes the idempotency key.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount in major units, null for the full order total.
	 * @param string     $reason   Reason typed by the admin.
	 * @return true|\WP_Error True when Bitsnap accepted the refund.
	 */
	public function refund( int $order_id, $amount, string $reason ) {
		try {
			$lock = new \Bitsnap\WooCommerce\Support\Lock( 'order:' . $order_id );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'bitsnap_busy', $e->getMessage() );
		}
		try {
			$order = OrderState::reload( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return new \WP_Error( 'bitsnap_refund_order', __( 'The order was not found.', 'bitsnap-for-woocommerce' ) );
			}

			$bitsnap_id = (string) $order->get_meta( Meta::PAID_ORDER_ID );
			if ( '' === $bitsnap_id ) {
				$bitsnap_id = (string) $order->get_meta( Meta::ORDER_ID );
			}
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

			$created   = self::$created[ $order_id ] ?? null;
			$refund_id = $created instanceof \WC_Order_Refund ? $created->get_id() : 0;
			unset( self::$created[ $order_id ] );
			$wc_refund = $refund_id ? wc_get_order( $refund_id ) : false;
			if ( ! $wc_refund instanceof \WC_Order_Refund || Money::to_minor( (float) $wc_refund->get_amount() ) !== $minor ) {
				return new \WP_Error( 'bitsnap_refund_identity', 'The WooCommerce refund identity is unavailable.' );
			}
			$pending = $order->get_meta( '_bitsnap_pending_refund' );
			if ( $pending && (int) ( $pending['wc_id'] ?? 0 ) !== $refund_id ) {
				return new \WP_Error( 'bitsnap_refund_pending', 'An earlier refund needs reconciliation before another submission.' );
			}
			$order->update_meta_data(
				'_bitsnap_pending_refund',
				array(
					'wc_id'   => $refund_id,
					'request' => $request,
				)
			);
			if ( ! $order->save() || ( method_exists( $order, 'get_last_save_error' ) && null !== $order->get_last_save_error() ) ) {
				return new \WP_Error( 'bitsnap_refund_pending_save', 'The pending refund could not be saved. Please retry.' );
			}
			try {
				$response = $this->plugin->client( $environment )->call(
					'RefundsService',
					'CreateRefund',
					$request,
					IdempotencyKey::for_refund( (string) $refund_id )
				);
			} catch ( ApiException $e ) {
				if ( ! $e->is_retryable() ) {
					$order->delete_meta_data( '_bitsnap_pending_refund' );
					$order->save();
				}
				$this->logger->error( sprintf( 'Refund of order %d failed: HTTP %d %s', $order_id, $e->get_http_status(), $e->get_reason() ) );
				return new \WP_Error( 'bitsnap_refund_' . ( '' !== $e->get_reason() ? $e->get_reason() : 'failed' ), ErrorMessages::describe( $e ) );
			}

			$refund = isset( $response['refund'] ) && is_array( $response['refund'] ) ? $response['refund'] : array();
			$status = ApiOrder::enum( isset( $refund['status'] ) && is_string( $refund['status'] ) ? $refund['status'] : '', 'REFUND_STATUS_' );
			$id     = isset( $refund['id'] ) && is_scalar( $refund['id'] ) ? (string) $refund['id'] : '';

			if ( in_array( $status, array( 'MANUAL_REQUIRED', 'FAILED' ), true ) ) {
				$order->delete_meta_data( '_bitsnap_pending_refund' );
				$order->save();
			}
			if ( 'MANUAL_REQUIRED' === $status ) {
				return new \WP_Error(
					'bitsnap_refund_manual',
					__( 'This payment method cannot be refunded through Bitsnap (cash on delivery or a manual transfer). Return the money yourself, then record it with "Refund manually".', 'bitsnap-for-woocommerce' )
				);
			}
			if ( 'FAILED' === $status ) {
				return new \WP_Error( 'bitsnap_refund_failed', __( 'Bitsnap could not make the refund. Check the payment in the Bitsnap dashboard.', 'bitsnap-for-woocommerce' ) );
			}

			if ( '' === $id ) {
				return new \WP_Error( 'bitsnap_refund_invalid', 'Bitsnap did not return a refund identity. Retry reconciliation.' );
			}
			// Record the id now, so the REFUND_SUCCEEDED webhook for this refund does not create a second WooCommerce refund.
			if ( '' !== $id ) {
				$wc_refund->update_meta_data( '_bitsnap_refund_id', $id );
				if ( ! $wc_refund->save() || ( method_exists( $wc_refund, 'get_last_save_error' ) && null !== $wc_refund->get_last_save_error() ) ) {
					return new \WP_Error( 'bitsnap_refund_persistence', 'Refund identity could not be saved; reconciliation will retry.' );
				}
				$this->updater->remember( $order, Meta::REFUND_IDS, $id );
			}
			$order->delete_meta_data( '_bitsnap_pending_refund' );
			$order->save();
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
		} finally {
			$lock->release();
		}
	}
	/**
	 * Replays an interrupted submission under the caller's order lock.
	 *
	 * WooCommerce may have removed its refund after a transport failure. In that
	 * case the webhook creates the accounting record after resolving the remote id.
	 *
	 * @param \WC_Order $order Locked, freshly read order.
	 * @return void
	 * @throws \RuntimeException When the remote identity is still unknown.
	 */
	public function settle_pending( \WC_Order $order ): void {
		$pending = $order->get_meta( '_bitsnap_pending_refund' );
		if ( ! is_array( $pending ) || empty( $pending['wc_id'] ) || empty( $pending['request'] ) ) {
			throw new \RuntimeException( 'Refund submission needs manual reconciliation.' );
		}
		$response = $this->plugin->client( (string) $order->get_meta( Meta::ENVIRONMENT ) )->call(
			'RefundsService',
			'CreateRefund',
			$pending['request'],
			IdempotencyKey::for_refund( (string) $pending['wc_id'] )
		);
		$id       = (string) ( $response['refund']['id'] ?? '' );
		if ( '' === $id ) {
			throw new \RuntimeException( 'Refund identity is unavailable.' );
		}
		$refund = wc_get_order( (int) $pending['wc_id'] );
		if ( $refund instanceof \WC_Order_Refund ) {
			$refund->update_meta_data( '_bitsnap_refund_id', $id );
			if ( ! $refund->save() || ( method_exists( $refund, 'get_last_save_error' ) && null !== $refund->get_last_save_error() ) ) {
				throw new \RuntimeException( 'Refund identity could not be saved.' );
			}
			$this->updater->remember( $order, Meta::REFUND_IDS, $id );
		}
		$order->delete_meta_data( '_bitsnap_pending_refund' );
		$order->save();
	}
}
