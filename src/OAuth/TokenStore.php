<?php
/**
 * Persistent OAuth state.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the tokens of each environment and the client registration of the site.
 *
 * A token set is an array with `access_token`, `refresh_token`, `expires_at`, `project_id`, `project_name`
 * and `environment`. The test and live sets are independent.
 */
interface TokenStore {

	/**
	 * Token set of an environment.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @param bool   $fresh       Read from the database, not from a cache this request filled earlier.
	 * @return array<string,mixed> Empty when not connected.
	 */
	public function get( string $environment, bool $fresh = false ): array;

	/**
	 * Saves a token set in one write, so a rotated refresh token and its access token are never stored apart.
	 *
	 * @param string              $environment 'test' or 'live'.
	 * @param array<string,mixed> $tokens      Token set.
	 * @return void
	 */
	public function save( string $environment, array $tokens ): void;

	/**
	 * Removes the token set of one environment and nothing else.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return void
	 */
	public function clear( string $environment ): void;

	/**
	 * Client id stored for a registration fingerprint.
	 *
	 * @param string $fingerprint Fingerprint of the base URL and redirect URI.
	 * @return string '' when the site is not registered for it.
	 */
	public function client_id( string $fingerprint ): string;

	/**
	 * Stores the client id of the registration.
	 *
	 * @param string $client_id   Client id.
	 * @param string $fingerprint Fingerprint of the base URL and redirect URI.
	 * @return void
	 */
	public function save_client_id( string $client_id, string $fingerprint ): void;

	/**
	 * Forgets the client registration.
	 *
	 * @return void
	 */
	public function forget_client(): void;
}
