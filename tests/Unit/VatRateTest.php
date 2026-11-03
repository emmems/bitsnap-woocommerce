<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Mapping\VatRate;
use PHPUnit\Framework\TestCase;

final class VatRateTest extends TestCase {

	public function test_taxes_off_leaves_rate_to_bitsnap(): void {
		$this->assertNull( VatRate::resolve( false, array( 23.0 ), 100, 23 ) );
	}

	public function test_uses_tax_rate_percent(): void {
		$this->assertSame( 23, VatRate::resolve( true, array( 23.0 ), 100, 23 ) );
		$this->assertSame( 8, VatRate::resolve( true, array( 8.0 ), 100, 8 ) );
	}

	public function test_rounds_fractional_rate(): void {
		$this->assertSame( 9, VatRate::resolve( true, array( 8.5 ), 100, 8.5 ) );
	}

	public function test_falls_back_to_amounts(): void {
		$this->assertSame( 23, VatRate::resolve( true, array(), 40.65, 9.35 ) );
	}

	public function test_untaxed_line_is_zero_rated(): void {
		$this->assertSame( 0, VatRate::resolve( true, array(), 100, 0 ) );
		$this->assertSame( 0, VatRate::resolve( true, array(), 0, 0 ) );
	}
}
