<?php
/**
 * Money helpers. Amounts travel as integer minor units (4900 = 49.00 PLN).
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Support;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BITSNAP_WC_TESTING' ) ) {
	exit;
}

/**
 * Pure conversions between WooCommerce amounts and Bitsnap `Money`.
 *
 * Only two-decimal currencies are supported (PLN, EUR, USD, GBP and so on).
 */
final class Money {

	/**
	 * Converts a decimal amount in major units to integer minor units.
	 *
	 * @param float|int|string $amount Amount such as 49.9, "49.90" or 40.650406.
	 * @return int Minor units, rounded half up.
	 */
	public static function to_minor( $amount ): int {
		return (int) round( (float) $amount * 100, 0, PHP_ROUND_HALF_UP );
	}

	/**
	 * Formats minor units as a plain decimal string for WooCommerce, "10.50".
	 *
	 * @param int $minor Minor units.
	 * @return string Decimal string with a dot and two decimals.
	 */
	public static function to_major_string( int $minor ): string {
		$sign = $minor < 0 ? '-' : '';
		$abs  = abs( $minor );
		return sprintf( '%s%d.%02d', $sign, intdiv( $abs, 100 ), $abs % 100 );
	}

	/**
	 * Builds a `Money` JSON object.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO 4217 code.
	 * @return array{amount:int,currency:string}
	 */
	public static function make( int $minor, string $currency ): array {
		return array(
			'amount'   => $minor,
			'currency' => strtoupper( $currency ),
		);
	}

	/**
	 * Reads an integer amount from an API value.
	 *
	 * Connect JSON encodes int64 as a string, int32 as a number. Both are accepted.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null Minor units, or null if the value is not a whole number.
	 */
	public static function parse_int( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (int) $value;
		}
		if ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * Reads the amount of a `Money` JSON object.
	 *
	 * @param mixed $money Decoded `Money` object, or anything else.
	 * @return int|null Minor units, or null if missing.
	 */
	public static function amount_of( $money ): ?int {
		if ( ! is_array( $money ) || ! array_key_exists( 'amount', $money ) ) {
			return null;
		}
		return self::parse_int( $money['amount'] );
	}
}
