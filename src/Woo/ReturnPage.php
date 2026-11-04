<?php
/**
 * Checks the payment when the buyer comes back to the shop.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The buyer can land on the order-received page before the webhook arrives. If the order is
 * still pending, ask Bitsnap once for the state (per payment session).
 */
final class ReturnPage {

	/**
	 * Order sync.
	 *
	 * @var OrderSync
	 */
	private $sync;

	/**
	 * Constructor.
	 *
	 * @param OrderSync $sync Order sync.
	 */
	public function __construct( OrderSync $sync ) {
		$this->sync = $sync;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// `before_thankyou` runs ahead of the status text where the theme supports it; `thankyou` is the documented hook.
		add_action( 'woocommerce_before_thankyou', array( $this, 'check' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'check' ) );
	}

	/**
	 * Syncs a pending order once.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function check( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! in_array( $order->get_payment_method(), Gateway::IDS, true ) ) {
			return;
		}
		if ( ! $order->has_status( 'pending' ) || '' === (string) $order->get_meta( Meta::ORDER_ID ) ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( Meta::RETURN_CHECKED ) ) {
			return;
		}
		$order->update_meta_data( Meta::RETURN_CHECKED, time() );
		$order->save();

		$this->sync->sync( $order, 'return' );
	}
}
