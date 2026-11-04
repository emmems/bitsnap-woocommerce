<?php
/**
 * Applies planned actions to a WooCommerce order.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

use Bitsnap\WooCommerce\Support\Logger;
use Bitsnap\WooCommerce\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns the data actions of {@see \Bitsnap\WooCommerce\Webhook\EventPlanner} into WooCommerce calls.
 */
final class OrderUpdater {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * The order facts the planner needs.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{status:string,is_paid:bool,awaiting_offline:bool,known_refund_ids:string[]}
	 */
	public function context( \WC_Order $order ): array {
		return array(
			'attempt_id'       => (string) $order->get_meta( Meta::ORDER_ID ),
			'total_minor'      => Money::to_minor( (float) $order->get_total() ),
			'currency'         => $order->get_currency(),
			'status'           => $order->get_status(),
			'is_paid'          => $order->is_paid(),
			'awaiting_offline' => '' !== (string) $order->get_meta( Meta::OFFLINE ),
			'known_refund_ids' => $this->list_meta( $order, Meta::REFUND_IDS ),
			'invoice_url'      => (string) $order->get_meta( Meta::INVOICE_URL ),
		);
	}

	/**
	 * Applies actions.
	 *
	 * @param \WC_Order           $order     Order.
	 * @param array<int,array>    $actions   Actions from the planner.
	 * @param array<string,mixed> $api_order Normalised Bitsnap order (see `ApiOrder::normalize()`).
	 * @param string              $source    Where the change came from: webhook, return or reconcile.
	 * @return void
	 */
	public function apply( \WC_Order $order, array $actions, array $api_order, string $source ): void {
		foreach ( $actions as $action ) {
			$this->logger->info(
				'Applying ' . $action['type'] . ' to order ' . $order->get_id(),
				array( 'source' => $source )
			);
			switch ( $action['type'] ) {
				case 'payment_complete':
					$order->update_meta_data( Meta::PAID_ORDER_ID, (string) $api_order['id'] );
					$this->label_payment( $order, $api_order );
					$order->delete_meta_data( Meta::OFFLINE );
					$order->save();
					$order->payment_complete( (string) $action['payment_id'] );
					$order->add_order_note( $this->note( 'payment_received', $api_order, (string) $action['payment_id'] ) );
					break;

				case 'offline_paid':
					$order->update_meta_data( Meta::PAID_ORDER_ID, (string) $api_order['id'] );
					$this->label_payment( $order, $api_order );
					if ( '' !== (string) $action['payment_id'] ) {
						$order->set_transaction_id( (string) $action['payment_id'] );
					}
					$order->set_date_paid( time() );
					$order->delete_meta_data( Meta::OFFLINE );
					$order->add_order_note( $this->note( 'offline_paid', $api_order, (string) $action['payment_id'] ) );
					$order->save();
					break;

				case 'offline_pending':
					$this->label_payment( $order, $api_order );
					$order->update_meta_data( Meta::OFFLINE, (string) $action['kind'] );
					$order->save();
					$order->update_status( (string) $action['status'], $this->note( 'offline_' . $action['kind'], $api_order, '' ) . ' ' );
					break;

				case 'set_status':
					$order->update_status( (string) $action['status'], $this->note( (string) $action['note'], $api_order, '' ) . ' ' );
					break;

				case 'invoice':
					$url = (string) $action['url'];
					if ( '' !== $url && $url !== (string) $order->get_meta( Meta::INVOICE_URL ) ) {
						/* translators: %s: URL of the invoice issued by Bitsnap. */
						$order->add_order_note( sprintf( __( 'Bitsnap invoice issued: %s', 'bitsnap-for-woocommerce' ), esc_url_raw( $url ) ), 0, false );
						$order->update_meta_data( Meta::INVOICE_URL, $url );
						$order->save();
					}
					break;

				case 'refund':
					$this->record_refund( $order, $action );
					break;

				case 'note':
					$order->add_order_note( $this->note( (string) $action['note'], $api_order, '', (array) ( $action['args'] ?? array() ) ) );
					break;
			}
		}
	}

	/**
	 * Remembers an id in a capped list stored in order meta.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 * @param string    $value Id to add.
	 * @return void
	 */
	public function remember( \WC_Order $order, string $key, string $value ): void {
		$list = $this->list_meta( $order, $key );
		if ( in_array( $value, $list, true ) ) {
			return;
		}
		$list[] = $value;
		$list   = array_slice( $list, -Meta::LIST_CAP );
		$order->update_meta_data( $key, $list );
		$order->save();
	}

	/**
	 * Reads a list stored in order meta.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 * @return string[]
	 */
	public function list_meta( \WC_Order $order, string $key ): array {
		$value = $order->get_meta( $key );
		return is_array( $value ) ? array_map( 'strval', $value ) : array();
	}

	/**
	 * Records the payment method the buyer used, for example "Bitsnap (BLIK)".
	 *
	 * @param \WC_Order           $order     Order.
	 * @param array<string,mixed> $api_order Normalised Bitsnap order.
	 * @return void
	 */
	private function label_payment( \WC_Order $order, array $api_order ): void {
		$gateway = (string) ( $api_order['payment_gateway'] ?? '' );
		if ( '' === $gateway ) {
			return;
		}
		$order->update_meta_data( Meta::PAYMENT_GATEWAY, $gateway );
		$order->set_payment_method_title( sprintf( 'Bitsnap (%s)', strtoupper( $gateway ) ) );
	}

	/**
	 * Creates the WooCommerce refund for a refund made in Bitsnap.
	 *
	 * Stock is not restored: the merchant decides that.
	 *
	 * @param \WC_Order           $order  Order.
	 * @param array<string,mixed> $action Refund action.
	 * @return void
	 * @throws \RuntimeException When a refund cannot be persisted or is still being submitted.
	 */
	private function record_refund( \WC_Order $order, array $action ): void {
		foreach ( $order->get_refunds() as $existing ) {
			if ( (string) $existing->get_meta( '_bitsnap_refund_id' ) === (string) $action['refund_id'] ) {
				return;
			}
		}
		if ( $order->get_meta( '_bitsnap_pending_refund' ) ) {
			\Bitsnap\WooCommerce\Plugin::instance()->refunder()->settle_pending( $order );
			// Recovery may have attached this remote refund to the admin's record.
			foreach ( $order->get_refunds() as $existing ) {
				if ( (string) $existing->get_meta( '_bitsnap_refund_id' ) === (string) $action['refund_id'] ) {
					return;
				}
			}
		}
		$amount = Money::to_major_string( (int) $action['amount'] );
		$reason = '' !== (string) $action['reason']
			? (string) $action['reason']
			/* translators: %s: where the refund was made, for example "dashboard". */
			: sprintf( __( 'Refunded in Bitsnap (%s)', 'bitsnap-for-woocommerce' ), '' !== (string) $action['source'] ? (string) $action['source'] : 'api' );

		// Set identity on the refund before its first database save.
		$identity = static function ( $refund ) use ( $action ) {
			if ( $refund instanceof \WC_Order_Refund && ! $refund->get_id() ) {
				$refund->update_meta_data( '_bitsnap_refund_id', (string) $action['refund_id'] );
			}
		};
		add_action( 'woocommerce_before_order_refund_object_save', $identity );
		try {
			$refund = wc_create_refund(
				array(
					'amount'         => $amount,
					'reason'         => $reason,
					'order_id'       => $order->get_id(),
					'refund_payment' => false,
					'restock_items'  => false,
				)
			);

		} finally {
			remove_action( 'woocommerce_before_order_refund_object_save', $identity );
		}

		if ( is_wp_error( $refund ) || ! $refund instanceof \WC_Order_Refund || ! $refund->get_id() || ( method_exists( $refund, 'get_last_save_error' ) && null !== $refund->get_last_save_error() ) ) {
			$error = is_wp_error( $refund ) ? $refund->get_error_message() : 'The WooCommerce refund was not persisted.';
			$this->logger->error( 'Could not record refund ' . $action['refund_id'] . ' on order ' . $order->get_id() . ': ' . $error );
			$order->add_order_note(
				sprintf(
					/* translators: 1: refund amount, 2: Bitsnap refund id, 3: error message. */
					__( 'Bitsnap refunded %1$s (refund %2$s), but WooCommerce could not record it: %3$s. Record the refund manually.', 'bitsnap-for-woocommerce' ),
					$amount,
					$action['refund_id'],
					$error
				)
			);
			throw new \RuntimeException( 'Could not persist Bitsnap refund.' );
		}

		$this->remember( $order, Meta::REFUND_IDS, (string) $action['refund_id'] );
		$order->add_order_note(
			sprintf(
				/* translators: 1: refund amount, 2: Bitsnap refund id. */
				__( 'Refund of %1$s made in Bitsnap was recorded (refund %2$s). Stock was not restored.', 'bitsnap-for-woocommerce' ),
				$amount,
				$action['refund_id']
			)
		);
	}

	/**
	 * Builds the text of an order note from its code.
	 *
	 * @param string              $code       Note code from the planner.
	 * @param array<string,mixed> $api_order  Normalised Bitsnap order.
	 * @param string              $payment_id Gateway transaction id.
	 * @param array<string,mixed> $args       Extra values of the note.
	 * @return string
	 */
	private function note( string $code, array $api_order, string $payment_id, array $args = array() ): string {
		$gateway = '' !== (string) ( $api_order['payment_gateway'] ?? '' ) ? strtoupper( (string) $api_order['payment_gateway'] ) : 'Bitsnap';
		$payment = '' !== $payment_id ? $payment_id : '-';

		switch ( $code ) {
			case 'payment_received':
				/* translators: 1: payment method such as BLIK, 2: transaction id. */
				return sprintf( __( 'Bitsnap payment received (%1$s, transaction %2$s).', 'bitsnap-for-woocommerce' ), $gateway, $payment );
			case 'offline_paid':
				/* translators: 1: payment method, 2: transaction id. */
				return sprintf( __( 'Bitsnap confirmed the payment (%1$s, transaction %2$s).', 'bitsnap-for-woocommerce' ), $gateway, $payment );
			case 'payment_failed':
				return __( 'Bitsnap reported that the payment failed.', 'bitsnap-for-woocommerce' );
			case 'payment_expired':
				return __( 'The Bitsnap payment session expired without a payment.', 'bitsnap-for-woocommerce' );
			case 'offline_cod':
				return __( 'The buyer chose payment on delivery in Bitsnap. The order is processing; collect the payment on delivery.', 'bitsnap-for-woocommerce' );
			case 'offline_manual':
				return __( 'The buyer chose a manual transfer in Bitsnap. The order is on hold until Bitsnap confirms the transfer.', 'bitsnap-for-woocommerce' );
			case 'refund_failed':
				/* translators: 1: refund amount, 2: Bitsnap refund id. */
				return sprintf( __( 'A refund of %1$s in Bitsnap failed (refund %2$s). Check it in the Bitsnap dashboard.', 'bitsnap-for-woocommerce' ), $this->format_minor( $args ), (string) ( $args['refund_id'] ?? '-' ) );
			case 'refund_unreadable':
				/* translators: %s: Bitsnap refund id. */
				return sprintf( __( 'Bitsnap reported a refund (%s) without an amount. Record it manually if money was returned.', 'bitsnap-for-woocommerce' ), (string) ( $args['refund_id'] ?? '-' ) );
			default:
				return $code;
		}
	}

	/**
	 * Formats an amount in minor units with its currency, for a note.
	 *
	 * @param array<string,mixed> $args Note arguments with `amount` and `currency`.
	 * @return string
	 */
	private function format_minor( array $args ): string {
		if ( ! isset( $args['amount'] ) || ! is_int( $args['amount'] ) ) {
			return '?';
		}
		return trim( Money::to_major_string( $args['amount'] ) . ' ' . (string) ( $args['currency'] ?? '' ) );
	}
}
