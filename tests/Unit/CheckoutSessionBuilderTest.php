<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Mapping\CheckoutSessionBuilder;
use PHPUnit\Framework\TestCase;

final class CheckoutSessionBuilderTest extends TestCase {

	/**
	 * A snapshot with sensible defaults. Override any key.
	 */
	private function snapshot( array $override = array() ): array {
		return array_merge(
			array(
				'currency'    => 'PLN',
				'total'       => 0,
				'items'       => array(),
				'fees'        => array(),
				'shipping'    => array(),
				'buyer'       => array(),
				'success_url' => 'https://shop.test/checkout/order-received/123/?key=wc_order_x',
				'cancel_url'  => 'https://shop.test/checkout/order-pay/123/?bitsnap_cancelled=1',
				'reference'   => '123',
				'order_key'   => 'wc_order_x',
				'site_url'    => 'https://shop.test',
				'locale'      => 'pl',
			),
			$override
		);
	}

	private function item( string $name, int $quantity, int $gross_subtotal, ?int $gross_total = null, ?int $vat = 23, string $sku = '' ): array {
		return array(
			'name'           => $name,
			'sku'            => $sku,
			'quantity'       => $quantity,
			'gross_subtotal' => $gross_subtotal,
			'gross_total'    => null === $gross_total ? $gross_subtotal : $gross_total,
			'vat_rate'       => $vat,
			'image_url'      => '',
		);
	}

	/**
	 * The contract: sum(lines) + shipping - discount == total, to the grosz.
	 */
	private function assertTotalsMatch( array $request ): void {
		$sum = 0;
		foreach ( $request['lines'] as $line ) {
			$this->assertGreaterThanOrEqual( 0, $line['unitPrice']['amount'] );
			$sum += $line['unitPrice']['amount'] * $line['quantity'];
		}
		$sum += $request['shipping']['price']['amount'] ?? 0;
		$sum -= $request['discount']['amount'] ?? 0;
		$this->assertSame( $request['total']['amount'], $sum, 'sum(lines) + shipping - discount must equal total' );
	}

	/**
	 * Example of section 9.1. The contract text shows a total of 10698, but 2 x 49.00 + 13.99 - 5.00 is 106.99, so the test uses 10699.
	 */
	public function test_example_from_the_contract(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total'    => 10699,
					'items'    => array( $this->item( 'T-shirt M', 2, 9800, 9300, 23, 'TS-M' ) ),
					'shipping' => array( array( 'name' => 'InPost Paczkomat', 'gross_total' => 1399, 'vat_rate' => 23 ) ),
					'buyer'    => array( 'email' => 'a@b.pl' ),
				)
			)
		);
		$request = $built['request'];

		$this->assertSame( 0, $built['delta'] );
		$this->assertSame(
			array(
				'name'      => 'T-shirt M',
				'unitPrice' => array( 'amount' => 4900, 'currency' => 'PLN' ),
				'quantity'  => 2,
				'vatRate'   => 23,
				'sku'       => 'TS-M',
			),
			$request['lines'][0]
		);
		$this->assertSame( array( 'name' => 'InPost Paczkomat', 'price' => array( 'amount' => 1399, 'currency' => 'PLN' ), 'vatRate' => 23 ), $request['shipping'] );
		$this->assertSame( array( 'amount' => 500, 'currency' => 'PLN' ), $request['discount'] );
		$this->assertSame( array( 'amount' => 10699, 'currency' => 'PLN' ), $request['total'] );
		$this->assertSame( '123', $request['clientReferenceId'] );
		$this->assertSame( 'pl', $request['locale'] );
		$this->assertSame(
			array( 'source' => 'woocommerce', 'wcOrderKey' => 'wc_order_x', 'siteUrl' => 'https://shop.test' ),
			$request['metadata']
		);
		$this->assertSame( array( 'email' => 'a@b.pl' ), $request['buyer'] );
		$this->assertTotalsMatch( $request );
	}

	public function test_coupon_discount_is_gross_and_applied_once(): void {
		// 2 x 49.00, 10.00 coupon spread over the line: line total after the coupon is 88.00.
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total' => 8800,
					'items' => array( $this->item( 'Mug', 2, 9800, 8800 ) ),
				)
			)
		);
		$this->assertSame( array( 'amount' => 1000, 'currency' => 'PLN' ), $built['request']['discount'] );
		$this->assertSame( 0, $built['delta'] );
		$this->assertTotalsMatch( $built['request'] );
	}

	public function test_indivisible_line_adds_a_rounding_line_when_the_total_is_higher(): void {
		// 3 x 33.33 = 99.99 in lines, but the line is 100.00 in WooCommerce.
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total'          => 10000,
					'rounding_label' => 'Zaokrąglenie',
					'items'          => array( $this->item( 'Cable', 3, 10000 ) ),
				)
			)
		);
		$lines = $built['request']['lines'];
		$this->assertSame( 3333, $lines[0]['unitPrice']['amount'] );
		$this->assertSame( 1, $built['delta'] );
		$this->assertCount( 2, $lines );
		$this->assertSame( 'Zaokrąglenie', $lines[1]['name'] );
		$this->assertSame( 1, $lines[1]['quantity'] );
		$this->assertSame( 1, $lines[1]['unitPrice']['amount'] );
		$this->assertArrayNotHasKey( 'vatRate', $lines[1] );
		$this->assertArrayNotHasKey( 'discount', $built['request'] );
		$this->assertTotalsMatch( $built['request'] );
	}

	public function test_indivisible_line_folds_into_the_discount_when_the_total_is_lower(): void {
		// 3 x 33.34 = 100.02 in lines, but the line is 100.00 in WooCommerce.
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total' => 10000,
					'items' => array( $this->item( 'Cable', 3, 10000 ) ),
				)
			)
		);
		// 10000 / 3 = 3333.33 rounds to 3333, so lines are 9999 and the rounding line is used.
		$this->assertSame( 1, $built['delta'] );

		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total' => 10001,
					'items' => array( $this->item( 'Cable', 3, 10001 ) ),
				)
			)
		);
		// 10001 / 3 = 3333.67 rounds to 3334, so lines are 10002 and the extra grosz goes to the discount.
		$this->assertSame( -1, $built['delta'] );
		$this->assertSame( array( 'amount' => 1, 'currency' => 'PLN' ), $built['request']['discount'] );
		$this->assertCount( 1, $built['request']['lines'] );
		$this->assertTotalsMatch( $built['request'] );
	}

	public function test_rounding_difference_is_added_to_a_real_coupon_discount(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total' => 9001,
					'items' => array( $this->item( 'Cable', 3, 10001, 9001 ) ),
				)
			)
		);
		// Coupon of 10.00 plus 1 grosz of rounding.
		$this->assertSame( 1001, $built['request']['discount']['amount'] );
		$this->assertTotalsMatch( $built['request'] );
	}

	public function test_tax_rounding_in_woocommerce_is_closed_too(): void {
		// Two lines rounded separately by the shop; WooCommerce's order total differs by 1 grosz from the lines.
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total' => 2468,
					'items' => array(
						$this->item( 'A', 1, 1234 ),
						$this->item( 'B', 1, 1233 ),
					),
				)
			)
		);
		$this->assertSame( 1, $built['delta'] );
		$this->assertTotalsMatch( $built['request'] );
	}

	public function test_negative_fee_becomes_discount_and_positive_fee_becomes_a_line(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total' => 10000 + 500 - 300,
					'items' => array( $this->item( 'Shirt', 1, 10000 ) ),
					'fees'  => array(
						array( 'name' => 'Gift wrap', 'gross_total' => 500, 'vat_rate' => 23 ),
						array( 'name' => 'Loyalty', 'gross_total' => -300, 'vat_rate' => 23 ),
						array( 'name' => 'Free fee', 'gross_total' => 0, 'vat_rate' => 23 ),
					),
				)
			)
		);
		$request = $built['request'];
		$this->assertCount( 2, $request['lines'] );
		$this->assertSame( 'Gift wrap', $request['lines'][1]['name'] );
		$this->assertSame( 500, $request['lines'][1]['unitPrice']['amount'] );
		$this->assertSame( 300, $request['discount']['amount'] );
		$this->assertSame( 0, $built['delta'] );
		$this->assertTotalsMatch( $request );
	}

	public function test_several_shipping_lines_are_combined_and_free_shipping_is_omitted(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total'    => 1000 + 1500 + 500,
					'items'    => array( $this->item( 'Shirt', 1, 1000 ) ),
					'shipping' => array(
						array( 'name' => 'Courier', 'gross_total' => 1500, 'vat_rate' => 23 ),
						array( 'name' => 'Insurance', 'gross_total' => 500, 'vat_rate' => 8 ),
					),
				)
			)
		);
		$this->assertSame( 'Courier, Insurance', $built['request']['shipping']['name'] );
		$this->assertSame( 2000, $built['request']['shipping']['price']['amount'] );
		$this->assertSame( 23, $built['request']['shipping']['vatRate'], 'VAT rate of the most expensive line' );
		$this->assertTotalsMatch( $built['request'] );

		$free = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total'    => 1000,
					'items'    => array( $this->item( 'Shirt', 1, 1000 ) ),
					'shipping' => array( array( 'name' => 'Free shipping', 'gross_total' => 0, 'vat_rate' => 0 ) ),
				)
			)
		);
		$this->assertArrayNotHasKey( 'shipping', $free['request'] );
	}

	public function test_line_without_vat_rate_omits_the_field(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot( array( 'total' => 1000, 'items' => array( $this->item( 'Shirt', 1, 1000, 1000, null ) ) ) )
		);
		$this->assertArrayNotHasKey( 'vatRate', $built['request']['lines'][0] );
	}

	public function test_zero_rated_line_sends_zero(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot( array( 'total' => 1000, 'items' => array( $this->item( 'Book', 1, 1000, 1000, 0 ) ) ) )
		);
		$this->assertSame( 0, $built['request']['lines'][0]['vatRate'] );
	}

	public function test_lines_with_no_quantity_are_skipped_and_empty_names_get_a_dash(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total' => 1000,
					'items' => array( $this->item( 'Gone', 0, 500 ), $this->item( '  ', 1, 1000 ) ),
				)
			)
		);
		$this->assertCount( 1, $built['request']['lines'] );
		$this->assertSame( '-', $built['request']['lines'][0]['name'] );
	}

	public function test_long_names_are_cut_to_250_characters(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot( array( 'total' => 100, 'items' => array( $this->item( str_repeat( 'ą', 400 ), 1, 100 ) ) ) )
		);
		$this->assertSame( 250, mb_strlen( $built['request']['lines'][0]['name'] ) );
	}

	public function test_more_than_the_maximum_lines_are_folded_into_one(): void {
		$items = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$items[] = $this->item( 'Item ' . $i, 1, 100 );
		}
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total'             => 15001, // One grosz of rounding, so the rounding line is needed too.
					'items'             => $items,
					'other_items_label' => 'Other items (%d)',
				)
			)
		);
		$lines = $built['request']['lines'];
		$this->assertLessThanOrEqual( CheckoutSessionBuilder::MAX_LINES, count( $lines ) );
		$this->assertSame( 'Item 1', $lines[0]['name'] );
		$this->assertSame( 'Other items (53)', $lines[97]['name'] );
		$this->assertSame( 5300, $lines[97]['unitPrice']['amount'] );
		$this->assertTotalsMatch( $built['request'] );
	}

	public function test_buyer_is_pruned_of_empty_values_and_empty_addresses(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total' => 100,
					'items' => array( $this->item( 'A', 1, 100 ) ),
					'buyer' => array(
						'email'           => 'a@b.pl',
						'firstName'       => 'Anna',
						'lastName'        => '',
						'phone'           => '',
						'company'         => '',
						'nip'             => '1234563218',
						'billingAddress'  => array( 'line1' => 'Prosta 1', 'line2' => '', 'city' => 'Warszawa', 'postalCode' => '00-001', 'country' => 'PL' ),
						'shippingAddress' => array( 'line1' => '', 'line2' => '', 'city' => '', 'postalCode' => '', 'country' => 'PL' ),
					),
				)
			)
		);
		$this->assertSame(
			array(
				'email'          => 'a@b.pl',
				'firstName'      => 'Anna',
				'nip'            => '1234563218',
				'billingAddress' => array( 'line1' => 'Prosta 1', 'city' => 'Warszawa', 'postalCode' => '00-001', 'country' => 'PL' ),
			),
			$built['request']['buyer']
		);
	}

	public function test_empty_buyer_is_left_out_and_without_buyer_strips_it(): void {
		$built = CheckoutSessionBuilder::build( $this->snapshot( array( 'total' => 100, 'items' => array( $this->item( 'A', 1, 100 ) ) ) ) );
		$this->assertArrayNotHasKey( 'buyer', $built['request'] );

		$with = CheckoutSessionBuilder::build( $this->snapshot( array( 'total' => 100, 'items' => array( $this->item( 'A', 1, 100 ) ), 'buyer' => array( 'email' => 'a@b.pl' ) ) ) );
		$this->assertArrayNotHasKey( 'buyer', CheckoutSessionBuilder::without_buyer( $with['request'] ) );
		$this->assertArrayHasKey( 'lines', CheckoutSessionBuilder::without_buyer( $with['request'] ) );
	}

	public function test_metadata_has_order_number_only_when_it_differs_and_values_are_capped(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot(
				array(
					'total'          => 100,
					'items'          => array( $this->item( 'A', 1, 100 ) ),
					'order_number'   => 'WEB-123',
					'plugin_version' => '0.1.0',
					'site_url'       => 'https://' . str_repeat( 'x', 600 ) . '.test',
				)
			)
		);
		$meta = $built['request']['metadata'];
		$this->assertSame( 'WEB-123', $meta['wcOrderNumber'] );
		$this->assertSame( '0.1.0', $meta['pluginVersion'] );
		$this->assertSame( 500, strlen( $meta['siteUrl'] ) );
		$this->assertLessThanOrEqual( 20, count( $meta ) );

		$same = CheckoutSessionBuilder::build( $this->snapshot( array( 'total' => 100, 'items' => array( $this->item( 'A', 1, 100 ) ), 'order_number' => '123' ) ) );
		$this->assertArrayNotHasKey( 'wcOrderNumber', $same['request']['metadata'] );
	}

	public function test_reference_is_capped_at_200_characters_and_unknown_locale_is_dropped(): void {
		$built = CheckoutSessionBuilder::build(
			$this->snapshot( array( 'total' => 100, 'items' => array( $this->item( 'A', 1, 100 ) ), 'reference' => str_repeat( '9', 300 ), 'locale' => 'de' ) )
		);
		$this->assertSame( 200, strlen( $built['request']['clientReferenceId'] ) );
		$this->assertArrayNotHasKey( 'locale', $built['request'] );
	}

	/**
	 * Random carts: whatever the shop sends, the request must add up.
	 */
	public function test_totals_always_match_for_random_carts(): void {
		mt_srand( 20261103 );
		for ( $run = 0; $run < 300; $run++ ) {
			$items    = array();
			$expected = 0;
			for ( $i = 0, $n = mt_rand( 1, 6 ); $i < $n; $i++ ) {
				$quantity = mt_rand( 1, 9 );
				$subtotal = mt_rand( 1, 500000 );
				$discount = mt_rand( 0, 1 ) ? mt_rand( 0, $subtotal ) : 0;
				$items[]  = $this->item( 'P' . $i, $quantity, $subtotal, $subtotal - $discount );
				$expected += $subtotal - $discount;
			}
			$shipping = mt_rand( 0, 1 ) ? mt_rand( 1, 5000 ) : 0;
			$expected += $shipping;
			$drift    = mt_rand( -2, 2 ); // WooCommerce's own tax rounding.
			$built    = CheckoutSessionBuilder::build(
				$this->snapshot(
					array(
						'total'    => $expected + $drift,
						'items'    => $items,
						'shipping' => $shipping > 0 ? array( array( 'name' => 'Ship', 'gross_total' => $shipping, 'vat_rate' => 23 ) ) : array(),
					)
				)
			);
			$this->assertTotalsMatch( $built['request'] );
		}
	}
}
