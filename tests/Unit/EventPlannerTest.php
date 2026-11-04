<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Mapping\ApiOrder;
use Bitsnap\WooCommerce\Webhook\Event;
use Bitsnap\WooCommerce\Webhook\EventPlanner;
use PHPUnit\Framework\TestCase;

final class EventPlannerTest extends TestCase {

	public function test_invoice_has_no_payment_transition(): void {
		$this->assertSame( array(), EventPlanner::plan( $this->event( 'INVOICE_ISSUED' ), $this->ctx( 'pending' ) ) );
	}

	public function test_attempt_and_amount_guards_apply_to_reconciliation_states(): void {
		$context = array_merge( $this->ctx( 'pending' ), array( 'attempt_id' => 'new', 'total_minor' => 1000, 'currency' => 'PLN' ) );
		$order = array( 'id' => 'old', 'amount_paid' => 1000, 'currency' => 'PLN', 'payment_id' => 'paid_old' );
		foreach ( array( 'FAILED', 'EXPIRED', 'AWAITING_OFFLINE' ) as $state ) {
			$this->assertSame( array(), EventPlanner::plan_for_state( $state, $order, $context ) );
		}
		$actions = EventPlanner::plan_for_state( 'PAID', $order, $context );
		$this->assertSame( array( 'note', 'payment_complete' ), $this->types( $actions ) );
		$missing = $order;
		unset( $missing['id'] );
		$this->assertSame( 'on-hold', EventPlanner::plan_for_state( 'PAID', $missing, $context )[0]['status'] );
		foreach ( array( array( 999, 'PLN' ), array( 1000, 'EUR' ), array( null, 'PLN' ), array( 1001, 'PLN' ) ) as $money ) {
			$order['amount_paid'] = $money[0];
			$order['currency'] = $money[1];
			$this->assertSame( 'on-hold', EventPlanner::plan_for_state( 'PAID', $order, $context )[0]['status'] );
		}
	}

	private function ctx( string $status, bool $paid = false, bool $offline = false, array $refunds = array() ): array {
		return array(
			'status'           => $status,
			'is_paid'          => $paid,
			'awaiting_offline' => $offline,
			'known_refund_ids' => $refunds,
		);
	}

	private function event( string $type, array $order = array(), array $refund = null ): array {
		$body = array(
			'projectId' => 'p1',
			'eventData' => array(
				'id'    => 'evt_' . $type,
				'event' => $type,
				'order' => array_merge(
					array(
						'id'                => 'ord_1',
						'clientReferenceId' => '123',
						'metadata'          => array( 'source' => 'woocommerce', 'wcOrderKey' => 'wc_order_x' ),
						'paymentGateway'    => 'blik',
						'paymentId'         => 'pay_9',
						'paymentState'      => 'PAYMENT_STATE_PAID',
					),
					$order
				),
			),
		);
		if ( null !== $refund ) {
			$body['eventData']['refund'] = $refund;
		}
		return Event::parse( json_encode( $body ) );
	}

	private function types( array $actions ): array {
		return array_column( $actions, 'type' );
	}

	public function test_success_completes_a_pending_order(): void {
		$actions = EventPlanner::plan( $this->event( 'TRANSACTION_SUCCESS' ), $this->ctx( 'pending' ) );
		$this->assertSame( array( 'payment_complete' ), $this->types( $actions ) );
		$this->assertSame( 'pay_9', $actions[0]['payment_id'] );
	}

	public function test_success_completes_failed_cancelled_and_on_hold_orders(): void {
		foreach ( array( 'failed', 'cancelled', 'on-hold' ) as $status ) {
			$actions = EventPlanner::plan( $this->event( 'TRANSACTION_SUCCESS' ), $this->ctx( $status ) );
			$this->assertSame( array( 'payment_complete' ), $this->types( $actions ), $status );
		}
	}

	public function test_success_is_idempotent_for_a_paid_order(): void {
		$this->assertSame( array(), EventPlanner::plan( $this->event( 'TRANSACTION_SUCCESS' ), $this->ctx( 'processing', true ) ) );
		$this->assertSame( array(), EventPlanner::plan( $this->event( 'TRANSACTION_SUCCESS' ), $this->ctx( 'completed', true ) ) );
	}

	public function test_failure_marks_an_unpaid_order_failed(): void {
		$actions = EventPlanner::plan( $this->event( 'TRANSACTION_FAILURE' ), $this->ctx( 'pending' ) );
		$this->assertSame( 'set_status', $actions[0]['type'] );
		$this->assertSame( 'failed', $actions[0]['status'] );
	}

	public function test_failure_never_touches_a_paid_cancelled_or_failed_order(): void {
		$this->assertSame( array(), EventPlanner::plan( $this->event( 'TRANSACTION_FAILURE' ), $this->ctx( 'processing', true ) ) );
		$this->assertSame( array(), EventPlanner::plan( $this->event( 'TRANSACTION_FAILURE' ), $this->ctx( 'cancelled' ) ) );
		$this->assertSame( array(), EventPlanner::plan( $this->event( 'TRANSACTION_FAILURE' ), $this->ctx( 'failed' ) ) );
	}

	public function test_expiry_cancels_an_unpaid_order_only(): void {
		$actions = EventPlanner::plan( $this->event( 'TRANSACTION_EXPIRED' ), $this->ctx( 'pending' ) );
		$this->assertSame( 'cancelled', $actions[0]['status'] );
		$this->assertSame( 'cancelled', EventPlanner::plan( $this->event( 'TRANSACTION_EXPIRED' ), $this->ctx( 'failed' ) )[0]['status'] );
		$this->assertSame( array(), EventPlanner::plan( $this->event( 'TRANSACTION_EXPIRED' ), $this->ctx( 'completed', true ) ) );
		$this->assertSame( array(), EventPlanner::plan( $this->event( 'TRANSACTION_EXPIRED' ), $this->ctx( 'refunded' ) ) );
	}

	public function test_cod_choice_moves_the_order_to_processing(): void {
		$event   = $this->event( 'TRANSACTION_CREATED', array( 'paymentGateway' => 'cod', 'paymentState' => 'PAYMENT_STATE_AWAITING_OFFLINE', 'paymentId' => '' ) );
		$actions = EventPlanner::plan( $event, $this->ctx( 'pending' ) );
		$this->assertSame( 'offline_pending', $actions[0]['type'] );
		$this->assertSame( 'cod', $actions[0]['kind'] );
		$this->assertSame( 'processing', $actions[0]['status'] );
	}

	public function test_manual_transfer_puts_the_order_on_hold(): void {
		$event   = $this->event( 'TRANSACTION_CREATED', array( 'paymentGateway' => 'manual', 'paymentState' => 'PAYMENT_STATE_AWAITING_OFFLINE' ) );
		$actions = EventPlanner::plan( $event, $this->ctx( 'pending' ) );
		$this->assertSame( 'manual', $actions[0]['kind'] );
		$this->assertSame( 'on-hold', $actions[0]['status'] );
	}

	public function test_offline_choice_is_applied_once(): void {
		$event = $this->event( 'TRANSACTION_CREATED', array( 'paymentGateway' => 'cod', 'paymentState' => 'AWAITING_OFFLINE' ) );
		$this->assertSame( array(), EventPlanner::plan( $event, $this->ctx( 'processing', true, true ) ) );
		$this->assertSame( array(), EventPlanner::plan( $event, $this->ctx( 'completed', true ) ), 'a paid order is left alone' );
	}

	public function test_created_without_an_offline_state_does_nothing(): void {
		$event = $this->event( 'TRANSACTION_CREATED', array( 'paymentState' => 'PAYMENT_STATE_PENDING' ) );
		$this->assertSame( array(), EventPlanner::plan( $event, $this->ctx( 'pending' ) ) );
	}

	public function test_cod_paid_later_is_recorded_without_a_status_change(): void {
		$actions = EventPlanner::plan( $this->event( 'TRANSACTION_SUCCESS', array( 'paymentGateway' => 'cod' ) ), $this->ctx( 'processing', true, true ) );
		$this->assertSame( array( 'offline_paid' ), $this->types( $actions ) );
	}

	public function test_manual_transfer_confirmed_completes_the_on_hold_order(): void {
		$actions = EventPlanner::plan( $this->event( 'TRANSACTION_SUCCESS', array( 'paymentGateway' => 'manual' ) ), $this->ctx( 'on-hold', false, true ) );
		$this->assertSame( array( 'payment_complete' ), $this->types( $actions ) );
	}

	public function test_refund_succeeded_creates_a_refund_once(): void {
		$refund  = array( 'id' => 'ref_1', 'amount' => array( 'amount' => 1000, 'currency' => 'PLN' ), 'status' => 'REFUND_STATUS_SUCCEEDED', 'source' => 'dashboard' );
		$event   = $this->event( 'REFUND_SUCCEEDED', array(), $refund );
		$actions = EventPlanner::plan( $event, $this->ctx( 'processing', true ) );
		$this->assertSame( 'refund', $actions[0]['type'] );
		$this->assertSame( 'ref_1', $actions[0]['refund_id'] );
		$this->assertSame( 1000, $actions[0]['amount'] );
		$this->assertSame( 'dashboard', $actions[0]['source'] );

		$this->assertSame( array(), EventPlanner::plan( $event, $this->ctx( 'processing', true, false, array( 'ref_1' ) ) ), 'already recorded' );
	}

	public function test_refund_amount_as_string_is_read(): void {
		$refund = array( 'id' => 'ref_2', 'amount' => array( 'amount' => '2500', 'currency' => 'PLN' ) );
		$actions = EventPlanner::plan( $this->event( 'REFUND_SUCCEEDED', array(), $refund ), $this->ctx( 'processing', true ) );
		$this->assertSame( 2500, $actions[0]['amount'] );
	}

	public function test_refund_without_an_amount_only_leaves_a_note(): void {
		$actions = EventPlanner::plan( $this->event( 'REFUND_SUCCEEDED', array(), array( 'id' => 'ref_3' ) ), $this->ctx( 'processing', true ) );
		$this->assertSame( array( 'note' ), $this->types( $actions ) );
		$this->assertSame( 'refund_unreadable', $actions[0]['note'] );
	}

	public function test_refund_failed_leaves_a_note(): void {
		$refund  = array( 'id' => 'ref_4', 'amount' => array( 'amount' => 700, 'currency' => 'PLN' ) );
		$actions = EventPlanner::plan( $this->event( 'REFUND_FAILED', array(), $refund ), $this->ctx( 'processing', true ) );
		$this->assertSame( 'note', $actions[0]['type'] );
		$this->assertSame( 'refund_failed', $actions[0]['note'] );
		$this->assertSame( 700, $actions[0]['args']['amount'] );
	}

	public function test_unknown_events_do_nothing(): void {
		$this->assertSame( array(), EventPlanner::plan( $this->event( 'SUBSCRIPTION_RENEWED' ), $this->ctx( 'pending' ) ) );
	}

	public function test_get_order_states_use_the_same_rules(): void {
		$order = ApiOrder::normalize( array( 'paymentGateway' => 'blik', 'paymentId' => 'pay_1' ) );
		$this->assertSame( array( 'payment_complete' ), $this->types( EventPlanner::plan_for_state( ApiOrder::STATE_PAID, $order, $this->ctx( 'pending' ) ) ) );
		$this->assertSame( array( 'payment_complete' ), $this->types( EventPlanner::plan_for_state( ApiOrder::STATE_PARTIAL_REFUND, $order, $this->ctx( 'pending' ) ) ) );
		$this->assertSame( array(), EventPlanner::plan_for_state( ApiOrder::STATE_PENDING, $order, $this->ctx( 'pending' ) ) );
		$this->assertSame( array(), EventPlanner::plan_for_state( 'SOMETHING_NEW', $order, $this->ctx( 'pending' ) ) );
	}
}
