<?php
/**
 * Removes secrets from text before it is logged.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redacts API keys, tokens, webhook secrets and Authorization headers.
 */
final class Redactor {

	/**
	 * Marker that replaces a secret.
	 */
	public const MASK = '[redacted]';

	/**
	 * Redacts secrets in a string.
	 *
	 * @param string $text Text that may contain secrets.
	 * @return string Text with secrets replaced by a marker.
	 */
	public static function redact( string $text ): string {
		$patterns = array(
			'/\b(bs_(?:test|live)_)[A-Za-z0-9_\-]+/' => '$1' . self::MASK,
			'/\b(bsat_|bsrt_)[A-Za-z0-9_\-\.]+/'     => '$1' . self::MASK,
			'/\b(whsec_)[A-Za-z0-9_\-]+/'            => '$1' . self::MASK,
			'/(Bearer\s+)[A-Za-z0-9_\-\.=]+/i'       => '$1' . self::MASK,
			'/("?(?:signingSecret|signing_secret|client_secret|access_token|refresh_token)"?\s*[:=]\s*"?)[^",\s}]+/i' => '$1' . self::MASK,
		);
		return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
	}

	/**
	 * Redacts secrets in every string of a nested array, and drops header values
	 * named like credentials.
	 *
	 * @param array<mixed> $data Data to clean.
	 * @return array<mixed>
	 */
	public static function redact_array( array $data ): array {
		$clean = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/authorization|secret|token|api[_-]?key/i', $key ) ) {
				$clean[ $key ] = self::MASK;
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = self::redact_array( $value );
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = self::redact( $value );
			} else {
				$clean[ $key ] = $value;
			}
		}
		return $clean;
	}
}
