<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Support\Secrets;
use PHPUnit\Framework\TestCase;

final class SecretsTest extends TestCase {

	protected function setUp(): void {
		if ( ! Secrets::is_available() ) {
			$this->markTestSkipped( 'libsodium is not available.' );
		}
	}

	public function test_round_trip(): void {
		$secrets = new Secrets( 'auth-key-material' );
		$stored  = $secrets->encrypt( 'bs_test_abc123' );
		$this->assertStringStartsWith( Secrets::PREFIX, $stored );
		$this->assertStringNotContainsString( 'bs_test_abc123', $stored );
		$this->assertSame( 'bs_test_abc123', $secrets->decrypt( $stored ) );
	}

	public function test_every_encryption_uses_a_new_nonce(): void {
		$secrets = new Secrets( 'k' );
		$this->assertNotSame( $secrets->encrypt( 'same' ), $secrets->encrypt( 'same' ) );
	}

	public function test_another_site_key_cannot_decrypt(): void {
		$stored = ( new Secrets( 'key-one' ) )->encrypt( 'whsec_x' );
		$this->assertNull( ( new Secrets( 'key-two' ) )->decrypt( $stored ) );
	}

	public function test_tampered_value_is_rejected(): void {
		$secrets = new Secrets( 'k' );
		$stored  = $secrets->encrypt( 'whsec_x' );
		$raw     = base64_decode( substr( $stored, strlen( Secrets::PREFIX ) ) );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		$this->assertNull( $secrets->decrypt( Secrets::PREFIX . base64_encode( $raw ) ) );
		$this->assertNull( $secrets->decrypt( Secrets::PREFIX . '!!!' ) );
		$this->assertNull( $secrets->decrypt( Secrets::PREFIX . base64_encode( 'short' ) ) );
	}

	public function test_plain_values_are_read_as_they_are(): void {
		$secrets = new Secrets( 'k' );
		$this->assertSame( 'bs_live_plain', $secrets->decrypt( 'bs_live_plain' ) );
		$this->assertSame( '', $secrets->decrypt( '' ) );
		$this->assertSame( '', $secrets->encrypt( '' ) );
	}

	public function test_without_key_material_the_value_stays_plain(): void {
		$secrets = new Secrets( '' );
		$this->assertFalse( $secrets->can_encrypt() );
		$this->assertSame( 'abc', $secrets->encrypt( 'abc' ) );
		$this->assertNull( $secrets->decrypt( Secrets::PREFIX . 'AAAA' ) );
	}
}
