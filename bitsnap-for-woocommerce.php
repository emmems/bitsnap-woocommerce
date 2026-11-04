<?php
/**
 * Plugin Name:          Bitsnap for WooCommerce
 * Plugin URI:           https://github.com/emmems/bitsnap-woocommerce
 * Description:          Accept BLIK, cards, bank transfers and more in WooCommerce through Bitsnap. The buyer pays on a secure Bitsnap page.
 * Version:              0.2.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Bitsnap
 * Author URI:           https://bitsnap.pl
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          bitsnap-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      11.2
 *
 * @package Bitsnap\WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BITSNAP_WC_VERSION', '0.2.0' );
define( 'BITSNAP_WC_FILE', __FILE__ );
define( 'BITSNAP_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BITSNAP_WC_URL', plugin_dir_url( __FILE__ ) );

require_once BITSNAP_WC_DIR . 'src/Autoloader.php';
\Bitsnap\WooCommerce\Autoloader::register( BITSNAP_WC_DIR . 'src/' );

// Updates come from GitHub releases (not WordPress.org). Works even while WooCommerce is inactive.
\Bitsnap\WooCommerce\Updates\Updater::register();

/**
 * Tells WooCommerce which features the plugin supports.
 *
 * Declared early, before WooCommerce initialises, even when WooCommerce is inactive.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BITSNAP_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BITSNAP_WC_FILE, true );
		}
	}
);

/**
 * Starts the plugin once all plugins are loaded, or explains why it cannot.
 */
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'bitsnap-for-woocommerce', false, dirname( plugin_basename( BITSNAP_WC_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'Bitsnap for WooCommerce needs WooCommerce to be installed and active.', 'bitsnap-for-woocommerce' )
					);
				}
			);
			return;
		}

		\Bitsnap\WooCommerce\Plugin::instance()->boot();
	},
	11
);

register_deactivation_hook(
	__FILE__,
	static function () {
		\Bitsnap\WooCommerce\Woo\Reconciler::unschedule();
	}
);
