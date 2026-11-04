<?php
/**
 * Where an API client gets an OAuth access token.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives {@see Client} the access token of an OAuth connection and refreshes it when needed.
 */
interface TokenSource {

	/**
	 * An access token that is valid for at least another minute.
	 *
	 * @return string
	 * @throws ApiException When no valid token can be had.
	 */
	public function current(): string;

	/**
	 * A new access token after the API rejected the old one, or the one a parallel request already got.
	 *
	 * @param string $stale The rejected token.
	 * @return string
	 * @throws ApiException When no valid token can be had.
	 */
	public function refresh( string $stale ): string;
}
