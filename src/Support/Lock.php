<?php
/**
 * Database-backed atomic locks shared by all PHP processes.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Support;

/** Serializes operations without relying on a persistent object cache. */
final class Lock {
	/**
	 * Process-owned leases.
	 *
	 * @var array<string,array{value:string,count:int}>
	 */
	private static $owned = array();
	/**
	 * Option key.
	 *
	 * @var string
	 */
	private $key;
	/**
	 * Unique ownership value.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Acquires a lease, or fails so callers can retry.
	 *
	 * @param string $name Resource name.
	 * @param int    $ttl Lease seconds, longer than all HTTP retries.
	 * @throws \RuntimeException When busy.
	 */
	public function __construct( string $name, int $ttl = 180 ) {
		global $wpdb;
		$this->key = 'bitsnap_lock_' . hash( 'sha256', $name );
		if ( isset( self::$owned[ $this->key ] ) ) {
			throw new \RuntimeException( 'Bitsnap operation is already running.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$this->value = ( time() + $ttl ) . ':' . bin2hex( random_bytes( 16 ) );
		if ( add_option( $this->key, $this->value, '', false ) ) {
			self::$owned[ $this->key ] = array(
				'value' => $this->value,
				'count' => 1,
			);
			return;
		}
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( is_string( $old ) && (int) $old < time() ) {
			$wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => $this->key,
					'option_value' => $old,
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( $this->key, 'options' );
			if ( add_option( $this->key, $this->value, '', false ) ) {
				self::$owned[ $this->key ] = array(
					'value' => $this->value,
					'count' => 1,
				);
				return;
			}
		}
		throw new \RuntimeException( 'Bitsnap operation is busy; retry shortly.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Whether this process already holds a resource.
	 *
	 * @param string $name Resource.
	 * @return bool
	 */
	public static function owned( string $name ): bool {
		return isset( self::$owned[ 'bitsnap_lock_' . hash( 'sha256', $name ) ] );
	}

	/** Releases only this owner's lease. */
	public function release(): void {
		global $wpdb;
		unset( self::$owned[ $this->key ] );
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $this->key,
				'option_value' => $this->value,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $this->key, 'options' );
	}
}
