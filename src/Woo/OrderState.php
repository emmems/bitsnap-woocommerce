<?php
/**
 * Fresh order reads after acquiring a database lock.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

/** Discards request-local and persistent WooCommerce snapshots before reading. */
final class OrderState {
	/**
	 * Loads state under the caller's order lock, including HPOS caches.
	 *
	 * @param int $id Order id.
	 * @return \WC_Order|false
	 */
	public static function reload( int $id ) {
		clean_post_cache( $id );
		if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
		}
		$store = \WC_Data_Store::load( 'order' );
		if ( method_exists( $store->get_current_class_name(), 'clear_cached_data' ) ) {
			$store->clear_cached_data( array( $id ) );
		}
		$order = wc_get_order( $id );
		if ( $order instanceof \WC_Order ) {
			$order->read_meta_data( true );
		}
		return $order;
	}
}
