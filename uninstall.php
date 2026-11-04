<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes the plugin's settings. Order meta (`_bitsnap_*`) stays, because it links WooCommerce
 * orders to their Bitsnap payments and is needed for refunds and bookkeeping.
 *
 * @package Bitsnap\WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Removes credentials, transient records and schedules for the current site. */
function bitsnap_wc_uninstall_site() {
	global $wpdb;
	delete_option( 'woocommerce_bitsnap_settings' );
	delete_option( 'bitsnap_oauth_tokens_test' );
	delete_option( 'bitsnap_oauth_tokens_live' );
	delete_option( 'bitsnap_reconcile_page' );
	$patterns = array( '_transient_bitsnap_%', '_transient_timeout_bitsnap_%', 'bitsnap_lock_%' );
	foreach ( $patterns as $pattern ) {
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", str_replace( '_', '\\_', $pattern ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'bitsnap_wc_reconcile', array(), 'bitsnap-for-woocommerce' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $bitsnap_site_id ) {
		switch_to_blog( $bitsnap_site_id );
		bitsnap_wc_uninstall_site();
		restore_current_blog();
	}
} else {
	bitsnap_wc_uninstall_site();
}
