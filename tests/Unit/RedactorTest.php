<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Support\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase {

	/**
	 * @dataProvider secrets
	 */
	public function test_secrets_are_removed( string $line, string $secret ): void {
		$clean = Redactor::redact( $line );
		$this->assertStringNotContainsString( $secret, $clean );
		$this->assertStringContainsString( Redactor::MASK, $clean );
	}

	public function secrets(): array {
		return array(
			'test key'        => array( 'key bs_test_AbC123xyz used', 'AbC123xyz' ),
			'live key'        => array( 'key=bs_live_SeCrEt99', 'SeCrEt99' ),
			'oauth token'     => array( 'token bsat_abc.def-ghi', 'abc.def-ghi' ),
			'webhook secret'  => array( 'secret whsec_0123456789abcdef', '0123456789abcdef' ),
			'bearer header'   => array( 'Authorization: Bearer abc123TOKEN', 'abc123TOKEN' ),
			'json secret'     => array( '{"signingSecret":"whsec_zzz111","x":1}', 'zzz111' ),
		);
	}

	public function test_normal_text_is_untouched(): void {
		$line = 'Order 123 total 10698 PLN via blik';
		$this->assertSame( $line, Redactor::redact( $line ) );
	}

	public function test_arrays_are_cleaned_by_key_and_by_value(): void {
		$clean = Redactor::redact_array(
			array(
				'Authorization' => 'Bearer bs_test_aaa',
				'api_key'       => 'bs_live_bbb',
				'url'           => 'https://x.test',
				'note'          => 'used bs_test_ccc',
				'nested'        => array( 'webhook_secret' => 'whsec_ddd', 'count' => 3 ),
			)
		);
		$this->assertSame( Redactor::MASK, $clean['Authorization'] );
		$this->assertSame( Redactor::MASK, $clean['api_key'] );
		$this->assertSame( Redactor::MASK, $clean['url'] );
		$this->assertStringNotContainsString( 'ccc', $clean['note'] );
		$this->assertSame( Redactor::MASK, $clean['nested']['webhook_secret'] );
		$this->assertSame( 3, $clean['nested']['count'] );
	}
}
