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

delete_option( 'woocommerce_bitsnap_settings' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'bitsnap_wc_reconcile', array(), 'bitsnap-for-woocommerce' );
}
