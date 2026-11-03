<?php
/**
 * Normalised view of a Bitsnap `Order`.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Mapping;

use Bitsnap\WooCommerce\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the order object found in webhook payloads and in `OrdersService.GetOrder`.
 *
 * Enum values are accepted with or without their proto prefix
 * (`PAYMENT_STATE_PAID` and `PAID`), and int64 values as number or string.
 */
final class ApiOrder {

	public const STATE_PENDING          = 'PENDING';
	public const STATE_AWAITING_OFFLINE = 'AWAITING_OFFLINE';
	public const STATE_PAID             = 'PAID';
	public const STATE_FAILED           = 'FAILED';
	public const STATE_EXPIRED          = 'EXPIRED';
	public const STATE_REFUNDED         = 'REFUNDED';
	public const STATE_PARTIAL_REFUND   = 'PARTIALLY_REFUNDED';

	/**
	 * Normalises an order object.
	 *
	 * @param mixed $order Decoded JSON `Order`.
	 * @return array{id:string,client_reference_id:string,metadata:array<string,string>,payment_gateway:string,payment_id:string,payment_state:string,amount_paid:?int,amount_refunded:?int,currency:string,environment:?string}
	 */
	public static function normalize( $order ): array {
		$order = is_array( $order ) ? $order : array();

		$metadata = array();
		if ( isset( $order['metadata'] ) && is_array( $order['metadata'] ) ) {
			foreach ( $order['metadata'] as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$metadata[ (string) $key ] = (string) $value;
				}
			}
		}

		$paid     = isset( $order['amountPaid'] ) ? $order['amountPaid'] : null;
		$refunded = isset( $order['amountRefunded'] ) ? $order['amountRefunded'] : null;
		$total    = isset( $order['total'] ) ? $order['total'] : null;
		$currency = '';
		foreach ( array( $paid, $total, $refunded ) as $money ) {
			if ( is_array( $money ) && ! empty( $money['currency'] ) && is_string( $money['currency'] ) ) {
				$currency = strtoupper( $money['currency'] );
				break;
			}
		}

		return array(
			'id'                  => self::str( $order, 'id' ),
			'client_reference_id' => self::str( $order, 'clientReferenceId' ),
			'metadata'            => $metadata,
			'payment_gateway'     => strtolower( self::str( $order, 'paymentGateway' ) ),
			'payment_id'          => self::str( $order, 'paymentId' ),
			'payment_state'       => self::enum( self::str( $order, 'paymentState' ), 'PAYMENT_STATE_' ),
			'amount_paid'         => Money::amount_of( $paid ),
			'amount_refunded'     => Money::amount_of( $refunded ),
			'currency'            => $currency,
			'environment'         => self::environment( self::str( $order, 'environment' ) ),
		);
	}

	/**
	 * Converts an `Environment` enum or a plain word to 'test' or 'live'.
	 *
	 * @param string $value Raw value such as "ENVIRONMENT_TEST".
	 * @return string|null 'test', 'live' or null if unknown or unset.
	 */
	public static function environment( string $value ): ?string {
		$value = self::enum( $value, 'ENVIRONMENT_' );
		if ( 'TEST' === $value ) {
			return 'test';
		}
		if ( 'LIVE' === $value ) {
			return 'live';
		}
		return null;
	}

	/**
	 * Upper-cases an enum and removes its proto prefix.
	 *
	 * @param string $value  Raw value.
	 * @param string $prefix Proto prefix such as "PAYMENT_STATE_".
	 * @return string
	 */
	public static function enum( string $value, string $prefix ): string {
		$value = strtoupper( trim( $value ) );
		if ( 0 === strpos( $value, $prefix ) ) {
			$value = substr( $value, strlen( $prefix ) );
		}
		return $value;
	}

	/**
	 * Reads a scalar field as a string.
	 *
	 * @param array<string,mixed> $data Source array.
	 * @param string              $key  Field name.
	 * @return string
	 */
	private static function str( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
	}
}
