<?php
/**
 * Short-lived key-value storage.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the authorization state and the refresh lock. WordPress transients in production, an array in tests.
 */
interface Storage {

	/**
	 * Reads a value.
	 *
	 * @param string $key Key.
	 * @return mixed|null Null when unset or expired.
	 */
	public function get( string $key );

	/**
	 * Writes a value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return void
	 */
	public function set( string $key, $value, int $ttl ): void;

	/**
	 * Writes a value only when the key is free.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool True when the value was written.
	 */
	public function add( string $key, $value, int $ttl ): bool;

	/**
	 * Deletes a value.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public function delete( string $key ): void;
}
