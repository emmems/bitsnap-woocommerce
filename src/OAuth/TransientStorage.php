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
	 * Owned refresh locks.
	 *
	 * @var array<string,\Bitsnap\WooCommerce\Support\Lock>
	 */
	private $locks = array();

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
	 * Acquires an atomic database lease. Refresh and every token writer share the
	 * same per-environment lock, with ownership-checked release.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool
	 */
	public function add( string $key, $value, int $ttl ): bool {
		try {
			$this->locks[ $key ] = new \Bitsnap\WooCommerce\Support\Lock( str_replace( 'bitsnap_oauth_refresh_', 'tokens:', $key ), $ttl );
			return true;
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}

	/**
	 * Deletes a value.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public function delete( string $key ): void {
		if ( isset( $this->locks[ $key ] ) ) {
			$this->locks[ $key ]->release();
			unset( $this->locks[ $key ] );
		}
		delete_transient( $key );
	}
}
