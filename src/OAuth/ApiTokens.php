<?php
/**
 * OAuth tokens for the API client.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\OAuth;

use Bitsnap\WooCommerce\Api\ApiException;
use Bitsnap\WooCommerce\Api\TokenSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Exception messages are logged and shown to administrators escaped. They never hold a token.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * {@see TokenSource} for one environment of an OAuth connection. It turns OAuth failures into API errors, so
 * callers handle one kind of exception.
 */
final class ApiTokens implements TokenSource {

	/**
	 * OAuth service.
	 *
	 * @var Service
	 */
	private $service;

	/**
	 * 'test' or 'live'.
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * Constructor.
	 *
	 * @param Service $service     OAuth service.
	 * @param string  $environment 'test' or 'live'.
	 */
	public function __construct( Service $service, string $environment ) {
		$this->service     = $service;
		$this->environment = $environment;
	}

	/**
	 * An access token that is valid for at least another minute.
	 *
	 * @return string
	 * @throws ApiException When no valid token can be had.
	 */
	public function current(): string {
		try {
			return $this->service->access_token( $this->environment );
		} catch ( OAuthException $e ) {
			throw self::translate( $e );
		}
	}

	/**
	 * A new access token after the API rejected the old one.
	 *
	 * @param string $stale The rejected token.
	 * @return string
	 * @throws ApiException When no valid token can be had.
	 */
	public function refresh( string $stale ): string {
		try {
			return $this->service->refresh( $this->environment, $stale );
		} catch ( OAuthException $e ) {
			throw self::translate( $e );
		}
	}

	/**
	 * Maps an OAuth failure to an API error.
	 *
	 * @param OAuthException $e Failure.
	 * @return ApiException
	 */
	private static function translate( OAuthException $e ): ApiException {
		if ( in_array( $e->error_name(), array( 'network', 'server_error', 'busy' ), true ) ) {
			return new ApiException( 'The Bitsnap login service could not be reached (' . $e->error_name() . ').', 0, 'network' );
		}
		return new ApiException( 'The Bitsnap connection is no longer valid (' . $e->error_name() . ').', 401, 'unauthenticated', 'oauth_reconnect' );
	}
}
