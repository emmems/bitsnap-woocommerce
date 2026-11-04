<?php
/**
 * HTTP for the OAuth endpoints.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts to an OAuth endpoint.
 */
interface Transport {

	/**
	 * Sends a POST request.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $body Fields, sent form-encoded or, with `$json`, as a JSON object.
	 * @param bool                $json Whether to send JSON instead of a form.
	 * @return array{status:int,body:string} Status 0 means the request did not reach the server.
	 */
	public function post( string $url, array $body, bool $json = false ): array;
}
