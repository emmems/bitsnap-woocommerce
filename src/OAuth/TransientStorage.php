<?php
/**
 * Transient-backed storage.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * {@see Storage} on WordPress transients.
 */
final class TransientStorage implements Storage {

	/**
	 * Reads a value.
	 *
	 * @param string $key Key.
	 * @return mixed|null
	 */
	public function get( string $key ) {
		$value = get_transient( $key );
		return false === $value ? null : $value;
	}

	/**
	 * Writes a value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return void
	 */
	public function set( string $key, $value, int $ttl ): void {
		set_transient( $key, $value, $ttl );
	}

	/**
	 * Writes a value only when the key is free. With an external object cache this is one atomic
	 * `wp_cache_add`; without one, transients live in the options table and the check is best effort.
	 * {@see Service::refresh()} therefore re-reads the stored tokens after it gets the lock.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool
	 */
	public function add( string $key, $value, int $ttl ): bool {
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $key, $value, 'transient', $ttl );
		}
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		return set_transient( $key, $value, $ttl );
	}

	/**
	 * Deletes a value.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public function delete( string $key ): void {
		delete_transient( $key );
	}
}
