<?php

namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Api\ApiException;
use Bitsnap\WooCommerce\Api\ErrorMapper;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase {

	/**
	 * Encodes an ErrorInfo message the way a Connect server does.
	 */
	private function error_info( string $reason, array $metadata = array() ): string {
		$bytes = "\x0a" . chr( strlen( $reason ) ) . $reason;
		foreach ( $metadata as $key => $value ) {
			$entry  = "\x0a" . chr( strlen( $key ) ) . $key . "\x12" . chr( strlen( $value ) ) . $value;
			$bytes .= "\x12" . chr( strlen( $entry ) ) . $entry;
		}
		return base64_encode( $bytes );
	}

	public function test_reads_reason_from_a_debug_detail(): void {
		$body = json_encode(
			array(
				'code'    => 'invalid_argument',
				'message' => '[total_mismatch] lines add up to 10000, total is 10001',
				'details' => array(
					array( 'type' => 'bitsnap.v1.ErrorInfo', 'value' => 'AAAA', 'debug' => array( 'reason' => 'total_mismatch', 'metadata' => array( 'field' => 'total' ) ) ),
				),
			)
		);
		$error = ErrorMapper::parse( $body );
		$this->assertSame( 'invalid_argument', $error['code'] );
		$this->assertSame( 'total_mismatch', $error['reason'] );
		$this->assertSame( array( 'field' => 'total' ), $error['metadata'] );
		$this->assertSame( 'lines add up to 10000, total is 10001', $error['message'], 'the [reason] prefix is removed' );
	}

	public function test_decodes_the_base64_error_info_proto(): void {
		$body  = json_encode(
			array(
				'code'    => 'permission_denied',
				'message' => 'missing scope',
				'details' => array( array( 'type' => 'bitsnap.v1.ErrorInfo', 'value' => $this->error_info( 'missing_scope', array( 'scope' => 'customers:pii' ) ) ) ),
			)
		);
		$error = ErrorMapper::parse( $body );
		$this->assertSame( 'missing_scope', $error['reason'] );
		$this->assertSame( array( 'scope' => 'customers:pii' ), $error['metadata'] );
	}

	public function test_url_safe_base64_without_padding_is_accepted(): void {
		$value = rtrim( strtr( $this->error_info( 'rate_limited' ), '+/', '-_' ), '=' );
		$this->assertSame( 'rate_limited', ErrorMapper::decode_error_info( $value )['reason'] );
	}

	public function test_falls_back_to_the_message_prefix(): void {
		$error = ErrorMapper::parse( '{"code":"failed_precondition","message":"[order_not_paid] Order is not paid"}' );
		$this->assertSame( 'order_not_paid', $error['reason'] );
		$this->assertSame( 'Order is not paid', $error['message'] );
	}

	public function test_reads_a_plain_reason_field(): void {
		$error = ErrorMapper::parse( '{"code":"x","message":"m","details":[{"reason":"refund_not_supported"}]}' );
		$this->assertSame( 'refund_not_supported', $error['reason'] );
	}

	public function test_garbage_gives_empty_fields(): void {
		foreach ( array( '', 'not json', '[]', '{"details":"x"}', '{"details":[1,"a"]}' ) as $body ) {
			$error = ErrorMapper::parse( $body );
			$this->assertSame( '', $error['reason'], $body );
		}
		$this->assertSame( '', ErrorMapper::decode_error_info( '%%%' )['reason'] );
		$this->assertSame( '', ErrorMapper::decode_error_info( base64_encode( "\x0a\x7fabc" ) )['reason'], 'truncated field' );
	}

	public function test_exception_retry_rules(): void {
		$this->assertTrue( ( new ApiException( 'x', 0, 'network' ) )->is_retryable() );
		$this->assertTrue( ( new ApiException( 'x', 503 ) )->is_retryable() );
		$this->assertTrue( ( new ApiException( 'x', 429 ) )->is_retryable() );
		$this->assertFalse( ( new ApiException( 'x', 400, 'invalid_argument', 'total_mismatch' ) )->is_retryable() );
		$this->assertFalse( ( new ApiException( 'x', 401 ) )->is_retryable() );
	}
}
