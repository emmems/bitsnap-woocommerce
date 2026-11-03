<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Mapping\Nip;
use PHPUnit\Framework\TestCase;

final class NipTest extends TestCase {

	/**
	 * @dataProvider valid
	 */
	public function test_normalizes_valid_nips( string $raw ): void {
		$this->assertSame( '1234563218', Nip::normalize( $raw ) );
	}

	public function valid(): array {
		return array(
			'plain'          => array( '1234563218' ),
			'dashes'         => array( '123-456-32-18' ),
			'spaces'         => array( ' 123 456 32 18 ' ),
			'country prefix' => array( 'PL1234563218' ),
			'lower prefix'   => array( 'pl 123-456-32-18' ),
		);
	}

	/**
	 * @dataProvider invalid
	 */
	public function test_rejects_invalid_nips( string $raw ): void {
		$this->assertNull( Nip::normalize( $raw ) );
	}

	public function invalid(): array {
		return array(
			'bad checksum'     => array( '1234563219' ),
			'too short'        => array( '123456321' ),
			'too long'         => array( '12345632180' ),
			'letters'          => array( '12345632A8' ),
			'foreign vat id'   => array( 'DE123456789' ),
			'empty'            => array( '' ),
			'checksum is ten'  => array( '1234567890' ),
		);
	}

	public function test_second_known_nip_is_valid(): void {
		$this->assertSame( '5260250995', Nip::normalize( '526-025-09-95' ) );
	}

	public function test_from_meta_takes_first_valid_key_in_order(): void {
		$meta = array(
			'_billing_nip'        => 'not a nip',
			'billing_nip'         => '',
			'_billing_vat_number' => 'PL 526-025-09-95',
			'_vat_number'         => '1234563218',
		);
		$this->assertSame( '5260250995', Nip::from_meta( $meta ) );
	}

	public function test_from_meta_returns_null_when_nothing_matches(): void {
		$this->assertNull( Nip::from_meta( array( '_billing_nip' => '', 'other' => '1234563218' ) ) );
	}

	public function test_from_meta_ignores_non_scalar_values(): void {
		$this->assertNull( Nip::from_meta( array( '_billing_nip' => array( '1234563218' ) ) ) );
	}

	public function test_from_meta_uses_given_keys(): void {
		$this->assertSame( '1234563218', Nip::from_meta( array( 'my_nip' => '1234563218' ), array( 'my_nip' ) ) );
	}
}
