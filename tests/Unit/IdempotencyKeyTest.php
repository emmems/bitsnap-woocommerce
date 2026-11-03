<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Support\IdempotencyKey;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyTest extends TestCase {

	public function test_site_hash_is_short_stable_and_ignores_trailing_slash_and_case(): void {
		$hash = IdempotencyKey::site_hash( 'https://Shop.test/' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{10}$/', $hash );
		$this->assertSame( $hash, IdempotencyKey::site_hash( 'https://shop.test' ) );
		$this->assertNotSame( $hash, IdempotencyKey::site_hash( 'https://other.test' ) );
	}

	public function test_key_format(): void {
		$this->assertSame( 'woo-abc-123-2', IdempotencyKey::for_order( 'abc', 123, 2 ) );
		$this->assertSame( 'woo-abc-123-1', IdempotencyKey::for_order( 'abc', 123, 0 ) );
		$this->assertSame( 'woo-refund-77', IdempotencyKey::for_refund( 77 ) );
	}

	public function test_first_call_is_attempt_one(): void {
		$this->assertSame( 1, IdempotencyKey::next_attempt( 0, '', 'fp', 0, 1000 ) );
	}

	public function test_same_cart_and_live_session_reuses_the_attempt(): void {
		$this->assertSame( 3, IdempotencyKey::next_attempt( 3, 'fp', 'fp', 2000, 1000 ) );
		$this->assertSame( 3, IdempotencyKey::next_attempt( 3, 'fp', 'fp', 0, 1000 ), 'unknown expiry' );
	}

	public function test_changed_cart_starts_a_new_attempt(): void {
		$this->assertSame( 4, IdempotencyKey::next_attempt( 3, 'fp1', 'fp2', 2000, 1000 ) );
	}

	public function test_expired_session_starts_a_new_attempt(): void {
		$this->assertSame( 4, IdempotencyKey::next_attempt( 3, 'fp', 'fp', 1000, 1000 ) );
		$this->assertSame( 4, IdempotencyKey::next_attempt( 3, 'fp', 'fp', 999, 1000 ) );
	}

	public function test_fingerprint_depends_on_the_request(): void {
		$this->assertSame( IdempotencyKey::fingerprint( array( 'a' => 1 ) ), IdempotencyKey::fingerprint( array( 'a' => 1 ) ) );
		$this->assertNotSame( IdempotencyKey::fingerprint( array( 'a' => 1 ) ), IdempotencyKey::fingerprint( array( 'a' => 2 ) ) );
	}
}
