<?php
/**
 * Payment method for the Cart and Checkout Blocks.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Bitsnap\WooCommerce\Plugin;
use Bitsnap\WooCommerce\Support\Settings;
use Bitsnap\WooCommerce\Woo\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the script that adds Bitsnap to the block checkout and passes it the settings.
 * Paying is the same redirect as in the classic checkout, through `Gateway::process_payment()`.
 */
final class PaymentMethod extends AbstractPaymentMethodType {

	/**
	 * Payment method name, the same as the gateway id.
	 *
	 * @var string
	 */
	protected $name = 'bitsnap';

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 * @param string $id Gateway identifier.
	 */
	public function __construct( Plugin $plugin, string $id = 'bitsnap' ) {
		$this->plugin = $plugin;
		$this->name   = $id;
	}

	/**
	 * Loads the settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( Settings::OPTION, array() );
		if ( 'bitsnap' !== $this->name ) {
			foreach ( array( 'enabled', 'title', 'description' ) as $key ) {
				unset( $this->settings[ $key ] );
				if ( isset( $this->settings[ $this->name . '_' . $key ] ) ) {
					$this->settings[ $key ] = $this->settings[ $this->name . '_' . $key ];
				}
			}
		}
	}

	/**
	 * Whether the method is offered in the block checkout.
	 *
	 * @return bool
	 */
	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN )
			&& $this->plugin->credentials()->is_configured();
	}

	/**
	 * Script handles for the checkout.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$asset_file = BITSNAP_WC_DIR . 'build/blocks.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			// The JavaScript was not built (a plain git checkout). The classic checkout still works.
			return array();
		}
		$asset = require $asset_file;

		wp_register_script(
			'bitsnap-for-woocommerce-blocks',
			BITSNAP_WC_URL . 'build/blocks.js',
			isset( $asset['dependencies'] ) ? $asset['dependencies'] : array(),
			isset( $asset['version'] ) ? $asset['version'] : BITSNAP_WC_VERSION,
			true
		);
		wp_set_script_translations( 'bitsnap-for-woocommerce-blocks', 'bitsnap-for-woocommerce', BITSNAP_WC_DIR . 'languages' );

		return array( 'bitsnap-for-woocommerce-blocks' );
	}

	/**
	 * Data passed to the script (`getSetting( 'bitsnap_data' )`).
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ $this->name ];
		return array(
			'enabled'             => $this->is_active(),
			'title'               => $this->get_setting( 'title', $gateway->get_title() ),
			'description'         => $this->get_setting( 'description', $gateway->description ),
			'supports'            => array( 'products', 'refunds' ),
			'testMode'            => 'test' === $this->plugin->credentials()->environment(),
			'supportedCurrencies' => Gateway::supported_currencies(),
		);
	}
}
