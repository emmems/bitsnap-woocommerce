<?php
/**
 * Human-readable text for API errors.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

use Bitsnap\WooCommerce\Api\ApiException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps `ErrorInfo.reason` values (section 9) to messages for the merchant. These go into
 * order notes and the settings screen, never to the buyer.
 */
final class ErrorMessages {

	/**
	 * Describes an error.
	 *
	 * @param ApiException $error The error.
	 * @return string
	 */
	public static function describe( ApiException $error ): string {
		$metadata = $error->get_metadata();
		$detail   = $error->getMessage();

		switch ( $error->get_reason() ) {
			case 'total_mismatch':
				return __( 'The order total does not equal the sum of its lines. Check for plugins that change prices or add fees at checkout.', 'bitsnap-for-woocommerce' );
			case 'unsupported_currency':
				return __( 'The store currency is not enabled for any payment method in your Bitsnap project.', 'bitsnap-for-woocommerce' );
			case 'missing_scope':
				return sprintf(
					/* translators: %s: permission name such as checkout:create. */
					__( 'The API key lacks a required permission (%s). Create a new key with that permission in Bitsnap.', 'bitsnap-for-woocommerce' ),
					isset( $metadata['scope'] ) ? $metadata['scope'] : '?'
				);
			case 'test_mode_only':
				return __( 'This action works only with a test key.', 'bitsnap-for-woocommerce' );
			case 'rate_limited':
				return __( 'Bitsnap is limiting requests. Try again in a minute.', 'bitsnap-for-woocommerce' );
			case 'amount_exceeds_refundable':
				return __( 'The amount is higher than what is left to refund on this payment.', 'bitsnap-for-woocommerce' );
			case 'refund_not_supported':
				return __( 'This payment method cannot be refunded through Bitsnap. Return the money yourself, then record it with "Refund manually".', 'bitsnap-for-woocommerce' );
			case 'order_not_paid':
				return __( 'The Bitsnap order has not been paid, so there is nothing to refund.', 'bitsnap-for-woocommerce' );
			case 'invalid_request':
				/* translators: %s: error detail from the API, usually the name of the invalid field. */
				return sprintf( __( 'Bitsnap rejected the request: %s', 'bitsnap-for-woocommerce' ), $detail );
		}

		if ( 'network' === $error->get_connect_code() ) {
			/* translators: %s: technical error message. */
			return sprintf( __( 'Could not reach Bitsnap: %s', 'bitsnap-for-woocommerce' ), $detail );
		}
		if ( 401 === $error->get_http_status() || 'unauthenticated' === $error->get_connect_code() ) {
			return __( 'The API key is invalid or was revoked. Check the key in the gateway settings.', 'bitsnap-for-woocommerce' );
		}
		if ( 403 === $error->get_http_status() || 'permission_denied' === $error->get_connect_code() ) {
			return __( 'The API key is not allowed to do this. Check its permissions in Bitsnap.', 'bitsnap-for-woocommerce' );
		}
		/* translators: %s: error message from the API. */
		return sprintf( __( 'Bitsnap returned an error: %s', 'bitsnap-for-woocommerce' ), $detail );
	}
}
