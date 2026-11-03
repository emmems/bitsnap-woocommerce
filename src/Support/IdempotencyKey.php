<?php
/**
 * Idempotency keys for write calls.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Support;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BITSNAP_WC_TESTING' ) ) {
	exit;
}

/**
 * Builds `woo-<site hash>-<order id>-<attempt>` keys and decides when a new attempt starts.
 *
 * The same key with the same request returns the same checkout session, so a
 * double click or a retry never creates two Bitsnap orders. A new attempt starts
 * when the cart changed or the old session expired.
 */
final class IdempotencyKey {

	/**
	 * Short stable hash of the site URL, so two shops on one Bitsnap project never collide.
	 *
	 * @param string $home_url The site's home URL.
	 * @return string Ten hex characters.
	 */
	public static function site_hash( string $home_url ): string {
		return substr( hash( 'sha256', strtolower( rtrim( $home_url, '/' ) ) ), 0, 10 );
	}

	/**
	 * Key for creating a checkout session.
	 *
	 * @param string $site_hash Result of {@see self::site_hash()}.
	 * @param int    $order_id  WooCommerce order id.
	 * @param int    $attempt   Attempt number, 1 or more.
	 * @return string
	 */
	public static function for_order( string $site_hash, int $order_id, int $attempt ): string {
		return sprintf( 'woo-%s-%d-%d', $site_hash, $order_id, max( 1, $attempt ) );
	}

	/**
	 * Key for a refund.
	 *
	 * @param int|string $refund_id WooCommerce refund id.
	 * @return string
	 */
	public static function for_refund( $refund_id ): string {
		return 'woo-refund-' . $refund_id;
	}

	/**
	 * Fingerprint of a request, used to notice that the cart changed.
	 *
	 * @param array<mixed> $request Request body.
	 * @return string
	 */
	public static function fingerprint( array $request ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure class, also used outside WordPress in unit tests.
		return hash( 'sha256', (string) json_encode( $request ) );
	}

	/**
	 * Decides the attempt number for the next call.
	 *
	 * @param int    $previous_attempt     Attempt of the last call, 0 if none.
	 * @param string $previous_fingerprint Fingerprint of the last call.
	 * @param string $fingerprint          Fingerprint of this call.
	 * @param int    $previous_expires_at  Unix time the last session expires, 0 if unknown.
	 * @param int    $now                  Current Unix time.
	 * @return int Attempt number to use.
	 */
	public static function next_attempt( int $previous_attempt, string $previous_fingerprint, string $fingerprint, int $previous_expires_at, int $now ): int {
		if ( $previous_attempt < 1 ) {
			return 1;
		}
		$changed = $previous_fingerprint !== $fingerprint;
		$expired = $previous_expires_at > 0 && $previous_expires_at <= $now;
		return ( $changed || $expired ) ? $previous_attempt + 1 : $previous_attempt;
	}
}
