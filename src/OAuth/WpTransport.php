<?php
/**
 * WordPress HTTP for the OAuth endpoints.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * {@see Transport} on top of `wp_remote_post`. It does not log: bodies of OAuth answers hold tokens.
 */
final class WpTransport implements Transport {

	/**
	 * Sends a POST request.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $body Fields.
	 * @param bool                $json Whether to send JSON instead of a form.
	 * @return array{status:int,body:string}
	 */
	public function post( string $url, array $body, bool $json = false ): array {
		$response = \Bitsnap\WooCommerce\Support\ApiUrl::post(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => $json ? 'application/json' : 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'        => $json ? wp_json_encode( $body ) : $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
			);
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
