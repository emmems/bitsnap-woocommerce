<?php
/**
 * VAT rate of an order line.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns WooCommerce tax data into the integer percent Bitsnap expects.
 */
final class VatRate {

	/**
	 * Picks the VAT rate of a line.
	 *
	 * @param bool      $tax_enabled   Whether the shop calculates taxes at all.
	 * @param float[]   $rate_percents Percent of every tax rate applied to the line (from WC tax rates).
	 * @param float|int $net           Net amount of the line.
	 * @param float|int $tax           Tax amount of the line.
	 * @return int|null Whole percent, or null to leave it to the shop default in Bitsnap.
	 */
	public static function resolve( bool $tax_enabled, array $rate_percents, $net, $tax ): ?int {
		if ( ! $tax_enabled ) {
			return null;
		}
		if ( array() !== $rate_percents ) {
			return (int) round( array_sum( array_map( 'floatval', $rate_percents ) ) );
		}
		$net = (float) $net;
		$tax = (float) $tax;
		if ( $tax > 0 && $net > 0 ) {
			return (int) round( $tax / $net * 100 );
		}
		return 0;
	}
}
