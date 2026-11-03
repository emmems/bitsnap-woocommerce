<?php
/**
 * Polish tax number (NIP) lookup in order meta.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds and validates a NIP. The plugin adds no checkout field of its own: it reads
 * the field the shop's invoice plugin already collects.
 */
final class Nip {

	/**
	 * Order meta keys used by common Polish invoice and VAT-number plugins, in order of preference.
	 *
	 * These come from plugin documentation and shop exports, not from a live test of
	 * every plugin. Shops with another field use the `bitsnap_wc_order_nip` filter.
	 */
	public const META_KEYS = array(
		'_billing_nip',
		'billing_nip',
		'_nip',
		'nip',
		'_billing_vat_number',
		'billing_vat_number',
		'_vat_number',
		'vat_number',
		'_billing_vat',
		'_billing_tax_id',
		'_billing_company_nip',
		'_billing_nip_number',
		'_wc_billing/nip',
		'_wc_billing/vat_number',
	);

	/**
	 * Returns the first valid NIP found in a map of meta values.
	 *
	 * @param array<string,mixed> $meta Meta key => value.
	 * @param string[]            $keys Keys to try, in order.
	 * @return string|null Ten digits, or null.
	 */
	public static function from_meta( array $meta, array $keys = self::META_KEYS ): ?string {
		foreach ( $keys as $key ) {
			if ( ! isset( $meta[ $key ] ) || ! is_scalar( $meta[ $key ] ) ) {
				continue;
			}
			$nip = self::normalize( (string) $meta[ $key ] );
			if ( null !== $nip ) {
				return $nip;
			}
		}
		return null;
	}

	/**
	 * Normalises and validates a NIP.
	 *
	 * Accepts "PL 123-456-32-18", "1234563218" and similar. Foreign VAT ids
	 * (for example "DE123456789") are not NIPs and return null.
	 *
	 * @param string $raw Raw value from the order.
	 * @return string|null Ten digits with a valid checksum, or null.
	 */
	public static function normalize( string $raw ): ?string {
		$value = strtoupper( trim( $raw ) );
		if ( 0 === strpos( $value, 'PL' ) ) {
			$value = substr( $value, 2 );
		}
		$value = (string) preg_replace( '/[\s\-\.]/', '', $value );
		if ( ! preg_match( '/^\d{10}$/', $value ) ) {
			return null;
		}
		return self::checksum_ok( $value ) ? $value : null;
	}

	/**
	 * Checks the NIP checksum (weights 6 5 7 2 3 4 5 6 7, modulo 11).
	 *
	 * @param string $digits Ten digits.
	 * @return bool
	 */
	private static function checksum_ok( string $digits ): bool {
		$weights = array( 6, 5, 7, 2, 3, 4, 5, 6, 7 );
		$sum     = 0;
		foreach ( $weights as $i => $weight ) {
			$sum += $weight * (int) $digits[ $i ];
		}
		$check = $sum % 11;
		return 10 !== $check && $check === (int) $digits[9];
	}
}
