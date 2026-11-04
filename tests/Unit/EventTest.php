<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Mapping\ApiOrder;
use Bitsnap\WooCommerce\Webhook\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase {

	private function body( array $event_data, array $top = array() ): string {
		return json_encode( array_merge( array( 'projectId' => 'p1', 'eventData' => $event_data ), $top ) );
	}

	public function test_parses_a_full_event(): void {
		$event = Event::parse(
			$this->body(
				array(
					'id'       => 'ord_1',
					'eventId' => 'evt_1',
					'event'    => 'transaction_success',
					'order'    => array(
						'id'                => 'ord_1',
						'clientReferenceId' => '123',
						'metadata'          => array( 'source' => 'woocommerce', 'wcOrderKey' => 'wc_order_x', 'siteUrl' => 'https://shop.test' ),
						'paymentGateway'    => 'BLIK',
						'paymentId'         => 'pay_9',
						'paymentState'      => 'PAYMENT_STATE_PAID',
						'amountPaid'        => array( 'amount' => '10698', 'currency' => 'pln' ),
						'amountRefunded'    => array( 'amount' => 0, 'currency' => 'PLN' ),
					),
					'customer' => array( 'email' => 'a@b.pl' ),
				),
				array( 'environment' => 'ENVIRONMENT_TEST' )
			)
		);

		$this->assertSame( 'evt_1', $event['id'] );
		$this->assertSame( 'TRANSACTION_SUCCESS', $event['type'] );
		$this->assertSame( 'test', $event['environment'] );
		$this->assertSame( '123', $event['order']['client_reference_id'] );
		$this->assertSame( 'blik', $event['order']['payment_gateway'] );
		$this->assertSame( 'PAID', $event['order']['payment_state'] );
		$this->assertSame( 10698, $event['order']['amount_paid'] );
		$this->assertSame( 0, $event['order']['amount_refunded'] );
		$this->assertSame( 'PLN', $event['order']['currency'] );
		$this->assertSame( 'wc_order_x', $event['order']['metadata']['wcOrderKey'] );
		$this->assertNull( $event['refund'] );
	}

	public function test_a_missing_event_id_falls_back_to_a_hash_of_the_body(): void {
		$body  = $this->body( array( 'event' => 'TRANSACTION_SUCCESS', 'order' => array() ) );
		$event = Event::parse( $body );
		$this->assertSame( 'body:' . hash( 'sha256', $body ), $event['id'] );
	}

	public function test_legacy_identity_includes_event_type(): void {
		$created = Event::parse( $this->body( array( 'id' => 'ord_1', 'event' => 'TRANSACTION_CREATED' ) ) );
		$paid = Event::parse( $this->body( array( 'id' => 'ord_1', 'event' => 'TRANSACTION_SUCCESS' ) ) );
		$this->assertSame( 'ord_1:TRANSACTION_CREATED', $created['id'] );
		$this->assertSame( 'ord_1:TRANSACTION_SUCCESS', $paid['id'] );
	}

	/**
	 * @dataProvider invalid_bodies
	 */
	public function test_rejects_invalid_bodies( string $body ): void {
		$this->assertNull( Event::parse( $body ) );
	}

	public function invalid_bodies(): array {
		return array(
			'not json'          => array( 'nope' ),
			'no event data'     => array( '{"projectId":"p"}' ),
			'event data scalar' => array( '{"eventData":"x"}' ),
			'no event type'     => array( '{"eventData":{"id":"e"}}' ),
			'empty'             => array( '' ),
		);
	}

	public function test_parses_refund_details(): void {
		$event = Event::parse(
			$this->body(
				array(
					'id'     => 'evt_2',
					'event'  => 'REFUND_SUCCEEDED',
					'order'  => array( 'clientReferenceId' => '5' ),
					'refund' => array(
						'id'     => 'ref_1',
						'orderId' => 'paid_attempt',
						'amount' => array( 'amount' => 500, 'currency' => 'PLN' ),
						'status' => 'REFUND_STATUS_SUCCEEDED',
						'reason' => 'Damaged',
						'source' => 'DASHBOARD',
					),
				)
			)
		);
		$this->assertSame(
			array( 'id' => 'ref_1', 'order_id' => 'paid_attempt', 'currency' => 'PLN', 'amount' => 500, 'status' => 'SUCCEEDED', 'reason' => 'Damaged', 'source' => 'dashboard' ),
			$event['refund']
		);
	}

	public function test_peek_environment_reads_top_level_or_order_environment(): void {
		$this->assertSame( 'live', Event::peek_environment( '{"environment":"ENVIRONMENT_LIVE"}' ) );
		$this->assertSame( 'test', Event::peek_environment( '{"eventData":{"order":{"environment":"test"}}}' ) );
		$this->assertNull( Event::peek_environment( '{"environment":"ENVIRONMENT_UNSPECIFIED"}' ) );
		$this->assertNull( Event::peek_environment( 'x' ) );
	}

	public function test_api_order_accepts_enums_with_or_without_prefix(): void {
		$this->assertSame( 'AWAITING_OFFLINE', ApiOrder::normalize( array( 'paymentState' => 'payment_state_awaiting_offline' ) )['payment_state'] );
		$this->assertSame( 'PAID', ApiOrder::normalize( array( 'paymentState' => 'PAID' ) )['payment_state'] );
		$this->assertSame( '', ApiOrder::normalize( 'garbage' )['payment_state'] );
	}
}
