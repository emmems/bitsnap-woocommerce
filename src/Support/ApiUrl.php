<?php
/**
 * Validates destinations before credentials leave the site.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Support;

/** HTTPS public URLs, with an explicit local development exception. */
final class ApiUrl {
	/**
	 * Checks the narrowly scoped development opt-in.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function local( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return defined( 'BITSNAP_WC_ALLOW_INSECURE_API' ) && BITSNAP_WC_ALLOW_INSECURE_API
			&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
			&& in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true )
			&& ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || '.test' === substr( $host, -5 ) )
			&& ! wp_parse_url( $url, PHP_URL_USER ) && ! wp_parse_url( $url, PHP_URL_PASS );
	}

	/**
	 * Validates public HTTPS including DNS resolution and private-address rejection.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function valid( string $url ): bool {
		if ( self::local( $url ) ) {
			return true;
		}
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || false === wp_http_validate_url( $url ) ) {
			return false;
		}
		$host      = (string) wp_parse_url( $url, PHP_URL_HOST );
		$addresses = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
		$addresses = is_array( $addresses ) ? $addresses : array();
		$records   = dns_get_record( $host, DNS_AAAA );
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			$addresses[] = $record['ipv6'];
		}
		foreach ( $addresses as $address ) {
			if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return array() !== $addresses;
	}

	/**
	 * Sends credentials only to a validated destination; redirects remain disabled.
	 *
	 * @param string $url URL.
	 * @param array  $args HTTP arguments.
	 * @return array|\WP_Error
	 */
	public static function post( string $url, array $args ) {
		if ( ! self::valid( $url ) ) {
			return new \WP_Error( 'bitsnap_unsafe_url', 'Bitsnap requires a public HTTPS API URL.' );
		}
		return self::local( $url ) ? wp_remote_post( $url, $args ) : wp_safe_remote_post( $url, $args );
	}
}
