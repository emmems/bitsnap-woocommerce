<?php
/**
 * Additional Bitsnap payment method.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shares the primary gateway connection and settings. */
final class CodGateway extends Gateway {
	/**
	 * Gateway identifier.
	 *
	 * @return string
	 */
	protected function gateway_id(): string {
		return 'bitsnap_cod';
	}
}
