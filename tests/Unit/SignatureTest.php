<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Webhook\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase {

	private const BODY = '{"projectId":"p1","eventData":{"id":"evt_1","event":"TRANSACTION_SUCCESS"}}';

	public function test_signature_is_hex_hmac_sha512(): void {
		$sig = Signature::compute( self::BODY, 'whsec_abc' );
		$this->assertSame( 128, strlen( $sig ) );
		$this->assertSame( hash_hmac( 'sha512', self::BODY, 'whsec_abc' ), $sig );
	}

	public function test_verify_accepts_the_right_signature(): void {
		$sig = Signature::compute( self::BODY, 'whsec_abc' );
		$this->assertTrue( Signature::verify( self::BODY, $sig, 'whsec_abc' ) );
		$this->assertTrue( Signature::verify( self::BODY, strtoupper( $sig ), 'whsec_abc' ) );
		$this->assertTrue( Signature::verify( self::BODY, '  ' . $sig . "\n", 'whsec_abc' ) );
		$this->assertTrue( Signature::verify( self::BODY, 'sha512=' . $sig, 'whsec_abc' ) );
	}

	public function test_verify_rejects_a_changed_body_or_secret(): void {
		$sig = Signature::compute( self::BODY, 'whsec_abc' );
		$this->assertFalse( Signature::verify( self::BODY . ' ', $sig, 'whsec_abc' ) );
		$this->assertFalse( Signature::verify( self::BODY, $sig, 'whsec_other' ) );
	}

	public function test_verify_rejects_empty_input(): void {
		$this->assertFalse( Signature::verify( self::BODY, '', 'whsec_abc' ) );
		$this->assertFalse( Signature::verify( self::BODY, Signature::compute( self::BODY, '' ), '' ) );
	}

	public function test_match_environment_finds_the_secret_that_signed(): void {
		$secrets = array( 'test' => 'whsec_test', 'live' => 'whsec_live' );
		$this->assertSame( 'live', Signature::match_environment( self::BODY, Signature::compute( self::BODY, 'whsec_live' ), $secrets ) );
		$this->assertSame( 'test', Signature::match_environment( self::BODY, Signature::compute( self::BODY, 'whsec_test' ), $secrets ) );
	}

	public function test_match_environment_returns_null_for_a_bad_signature(): void {
		$this->assertNull( Signature::match_environment( self::BODY, 'deadbeef', array( 'test' => 'whsec_test' ) ) );
		$this->assertNull( Signature::match_environment( self::BODY, Signature::compute( self::BODY, 'whsec_x' ), array() ) );
	}

	public function test_hint_breaks_a_tie_when_both_environments_share_a_secret(): void {
		$secrets = array( 'test' => 'whsec_same', 'live' => 'whsec_same' );
		$sig     = Signature::compute( self::BODY, 'whsec_same' );
		$this->assertSame( 'live', Signature::match_environment( self::BODY, $sig, $secrets, 'live' ) );
		$this->assertSame( 'test', Signature::match_environment( self::BODY, $sig, $secrets, null ) );
	}

	public function test_a_live_hint_cannot_pick_an_environment_whose_secret_did_not_sign(): void {
		$secrets = array( 'test' => 'whsec_test', 'live' => 'whsec_live' );
		$sig     = Signature::compute( self::BODY, 'whsec_test' );
		$this->assertSame( 'test', Signature::match_environment( self::BODY, $sig, $secrets, 'live' ) );
	}
}
