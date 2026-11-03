<?php
/**
 * Decides what to do with a Bitsnap event or payment state.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Webhook;

use Bitsnap\WooCommerce\Mapping\ApiOrder;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BITSNAP_WC_TESTING' ) ) {
	exit;
}

/**
 * Pure state machine for the table in section 9.4 of the contract, extended with
 * the states of `GetOrder` (used by the return page and the reconcile job).
 *
 * It returns a list of actions as data. `Woo\OrderUpdater` applies them to the
 * order. Notes are returned as codes, so this class needs no translation functions.
 *
 * Action shapes (all have `type`):
 *
 * - `payment_complete`: `payment_id`, `note`. Calls `$order->payment_complete()`.
 * - `offline_paid`: `payment_id`. Cash on delivery or a transfer was paid after the
 *   order was already `processing`: record it, no status change.
 * - `offline_pending`: `kind` ('cod' or 'manual'), `status` to set.
 * - `set_status`: `status`, `note`.
 * - `refund`: `refund_id`, `amount` (minor units), `reason`, `source`.
 * - `note`: `note`, `args`.
 *
 * Order context (`$context`): `status` (WooCommerce status without the `wc-` prefix),
 * `is_paid` (bool), `awaiting_offline` (bool), `known_refund_ids` (string[]).
 */
final class EventPlanner {

	/**
	 * Statuses from which an unpaid order may move to failed.
	 */
	private const FAILABLE = array( 'pending', 'on-hold' );

	/**
	 * Statuses from which an unpaid order may be cancelled after an expiry.
	 */
	private const CANCELLABLE = array( 'pending', 'on-hold', 'failed' );

	/**
	 * Statuses from which a COD or manual choice may change the order.
	 */
	private const OFFLINE_FROM = array( 'pending', 'on-hold', 'failed' );

	/**
	 * Plans the actions for a webhook event.
	 *
	 * @param array<string,mixed> $event   Event from {@see Event::parse()}.
	 * @param array<string,mixed> $context Order context.
	 * @return array<int,array<string,mixed>>
	 */
	public static function plan( array $event, array $context ): array {
		$order = (array) ( $event['order'] ?? array() );

		switch ( $event['type'] ?? '' ) {
			case 'TRANSACTION_CREATED':
				if ( ApiOrder::STATE_AWAITING_OFFLINE === ( $order['payment_state'] ?? '' ) ) {
					return self::plan_for_state( ApiOrder::STATE_AWAITING_OFFLINE, $order, $context );
				}
				return array();

			case 'TRANSACTION_SUCCESS':
				return self::plan_for_state( ApiOrder::STATE_PAID, $order, $context );

			case 'TRANSACTION_FAILURE':
				return self::plan_for_state( ApiOrder::STATE_FAILED, $order, $context );

			case 'TRANSACTION_EXPIRED':
				return self::plan_for_state( ApiOrder::STATE_EXPIRED, $order, $context );

			case 'REFUND_SUCCEEDED':
				return self::plan_refund_succeeded( $event, $context );

			case 'REFUND_FAILED':
				$refund = (array) ( $event['refund'] ?? array() );
				return array(
					array(
						'type' => 'note',
						'note' => 'refund_failed',
						'args' => array(
							'refund_id' => (string) ( $refund['id'] ?? '' ),
							'amount'    => $refund['amount'] ?? null,
							'currency'  => (string) ( $order['currency'] ?? '' ),
						),
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Plans the actions for a payment state.
	 *
	 * @param string              $state   One of the `ApiOrder::STATE_*` values.
	 * @param array<string,mixed> $order   Normalised order, see {@see ApiOrder::normalize()}.
	 * @param array<string,mixed> $context Order context.
	 * @return array<int,array<string,mixed>>
	 */
	public static function plan_for_state( string $state, array $order, array $context ): array {
		$status     = (string) ( $context['status'] ?? '' );
		$is_paid    = ! empty( $context['is_paid'] );
		$offline    = ! empty( $context['awaiting_offline'] );
		$payment_id = (string) ( $order['payment_id'] ?? '' );
		$gateway    = (string) ( $order['payment_gateway'] ?? '' );

		switch ( $state ) {
			case ApiOrder::STATE_PAID:
			case ApiOrder::STATE_REFUNDED:
			case ApiOrder::STATE_PARTIAL_REFUND:
				if ( $offline ) {
					// Cash on delivery sits in "processing", a manual transfer in "on-hold".
					if ( 'on-hold' === $status ) {
						return array( self::payment_complete( $payment_id ) );
					}
					return array(
						array(
							'type'       => 'offline_paid',
							'payment_id' => $payment_id,
						),
					);
				}
				return $is_paid ? array() : array( self::payment_complete( $payment_id ) );

			case ApiOrder::STATE_FAILED:
				if ( $is_paid || ! in_array( $status, self::FAILABLE, true ) ) {
					return array();
				}
				return array(
					array(
						'type'   => 'set_status',
						'status' => 'failed',
						'note'   => 'payment_failed',
					),
				);

			case ApiOrder::STATE_EXPIRED:
				if ( $is_paid || ! in_array( $status, self::CANCELLABLE, true ) ) {
					return array();
				}
				return array(
					array(
						'type'   => 'set_status',
						'status' => 'cancelled',
						'note'   => 'payment_expired',
					),
				);

			case ApiOrder::STATE_AWAITING_OFFLINE:
				if ( $offline || ! in_array( $status, self::OFFLINE_FROM, true ) ) {
					return array();
				}
				return array(
					array(
						'type'   => 'offline_pending',
						'kind'   => 'cod' === $gateway ? 'cod' : 'manual',
						'status' => 'cod' === $gateway ? 'processing' : 'on-hold',
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Plans a `REFUND_SUCCEEDED` event.
	 *
	 * @param array<string,mixed> $event   Parsed event.
	 * @param array<string,mixed> $context Order context.
	 * @return array<int,array<string,mixed>>
	 */
	private static function plan_refund_succeeded( array $event, array $context ): array {
		$refund = (array) ( $event['refund'] ?? array() );
		$id     = (string) ( $refund['id'] ?? '' );
		if ( '' === $id ) {
			$id = 'event:' . (string) ( $event['id'] ?? '' );
		}
		if ( in_array( $id, (array) ( $context['known_refund_ids'] ?? array() ), true ) ) {
			return array();
		}
		$amount = $refund['amount'] ?? null;
		if ( null === $amount || $amount <= 0 ) {
			return array(
				array(
					'type' => 'note',
					'note' => 'refund_unreadable',
					'args' => array( 'refund_id' => $id ),
				),
			);
		}
		return array(
			array(
				'type'      => 'refund',
				'refund_id' => $id,
				'amount'    => (int) $amount,
				'reason'    => (string) ( $refund['reason'] ?? '' ),
				'source'    => (string) ( $refund['source'] ?? '' ),
			),
		);
	}

	/**
	 * Builds the `payment_complete` action.
	 *
	 * @param string $payment_id Gateway transaction id.
	 * @return array<string,mixed>
	 */
	private static function payment_complete( string $payment_id ): array {
		return array(
			'type'       => 'payment_complete',
			'payment_id' => $payment_id,
			'note'       => 'payment_received',
		);
	}
}
