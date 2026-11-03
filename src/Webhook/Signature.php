<?php
/**
 * Webhook signature check.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Webhook;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BITSNAP_WC_TESTING' ) ) {
	exit;
}

/**
 * Bitsnap signs the raw body with `whsec_...` using HMAC-SHA512 and sends the hex digest
 * in the `X-Content-Signature` header.
 */
final class Signature {

	/**
	 * Computes the signature of a body.
	 *
	 * @param string $body   Raw request body.
	 * @param string $secret Signing secret.
	 * @return string Lower-case hex HMAC-SHA512.
	 */
	public static function compute( string $body, string $secret ): string {
		return hash_hmac( 'sha512', $body, $secret );
	}

	/**
	 * Checks a signature in constant time.
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature Value of the signature header.
	 * @param string $secret    Signing secret.
	 * @return bool
	 */
	public static function verify( string $body, string $signature, string $secret ): bool {
		if ( '' === $secret ) {
			return false;
		}
		$signature = strtolower( trim( $signature ) );
		if ( 0 === strpos( $signature, 'sha512=' ) ) {
			$signature = substr( $signature, 7 );
		}
		if ( '' === $signature ) {
			return false;
		}
		return hash_equals( self::compute( $body, $secret ), $signature );
	}

	/**
	 * Finds which environment's secret signed the body.
	 *
	 * Both secrets are tried. If the same secret is configured for both
	 * environments, the environment named in the payload decides.
	 *
	 * @param string               $body      Raw request body.
	 * @param string               $signature Value of the signature header.
	 * @param array<string,string> $secrets   Environment ('test' or 'live') => secret.
	 * @param string|null          $hint      Environment named in the payload, if any.
	 * @return string|null 'test', 'live', or null if no secret matches.
	 */
	public static function match_environment( string $body, string $signature, array $secrets, ?string $hint = null ): ?string {
		$matches = array();
		foreach ( $secrets as $environment => $secret ) {
			if ( self::verify( $body, $signature, (string) $secret ) ) {
				$matches[] = (string) $environment;
			}
		}
		if ( array() === $matches ) {
			return null;
		}
		if ( null !== $hint && in_array( $hint, $matches, true ) ) {
			return $hint;
		}
		return $matches[0];
	}
}
