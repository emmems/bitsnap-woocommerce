<?php
namespace Bitsnap\WooCommerce\Tests\Doubles;

use Bitsnap\WooCommerce\OAuth\Storage;

/** In-memory Storage. Lifetimes are not simulated; tests delete keys themselves. */
final class ArrayStorage implements Storage {

	/** @var array<string,mixed> */
	public $data = array();

	public function get( string $key ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : null;
	}

	public function set( string $key, $value, int $ttl ): void {
		$this->data[ $key ] = $value;
	}

	public function add( string $key, $value, int $ttl ): bool {
		if ( array_key_exists( $key, $this->data ) ) {
			return false;
		}
		$this->data[ $key ] = $value;
		return true;
	}

	public function delete( string $key ): void {
		unset( $this->data[ $key ] );
	}

	/** The one state record, for tests that start a connection. */
	public function only_state(): array {
		foreach ( $this->data as $key => $value ) {
			if ( 0 === strpos( $key, 'bitsnap_oauth_state_' ) ) {
				return $value;
			}
		}
		return array();
	}
}
