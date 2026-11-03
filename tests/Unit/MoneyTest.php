<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase {

	/**
	 * @dataProvider minor_units
	 */
	public function test_to_minor( $input, int $expected ): void {
		$this->assertSame( $expected, Money::to_minor( $input ) );
	}

	public function minor_units(): array {
		return array(
			'integer'                 => array( 49, 4900 ),
			'two decimals'            => array( '49.90', 4990 ),
			'float noise'             => array( 19.99, 1999 ),
			'unrounded woo subtotal'  => array( '40.650406504', 4065 ),
			'half rounds up'          => array( '0.005', 1 ),
			'zero'                    => array( 0, 0 ),
			'negative fee'            => array( '-5.00', -500 ),
		);
	}

	public function test_to_major_string(): void {
		$this->assertSame( '10.50', Money::to_major_string( 1050 ) );
		$this->assertSame( '0.05', Money::to_major_string( 5 ) );
		$this->assertSame( '-3.07', Money::to_major_string( -307 ) );
	}

	public function test_parse_int_accepts_numbers_and_strings(): void {
		$this->assertSame( 4900, Money::parse_int( 4900 ) );
		$this->assertSame( 4900, Money::parse_int( '4900' ) );
		$this->assertSame( 4900, Money::parse_int( 4900.0 ) );
		$this->assertNull( Money::parse_int( '49.00' ) );
		$this->assertNull( Money::parse_int( null ) );
	}

	public function test_amount_of_reads_money_objects(): void {
		$this->assertSame( 1000, Money::amount_of( array( 'amount' => '1000', 'currency' => 'PLN' ) ) );
		$this->assertNull( Money::amount_of( array( 'currency' => 'PLN' ) ) );
		$this->assertNull( Money::amount_of( 'x' ) );
	}

	public function test_make_upper_cases_the_currency(): void {
		$this->assertSame( array( 'amount' => 1, 'currency' => 'EUR' ), Money::make( 1, 'eur' ) );
	}
}
