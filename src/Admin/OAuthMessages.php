<?php
/**
 * Texts for OAuth failures.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Admin;

use Bitsnap\WooCommerce\OAuth\OAuthException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an {@see OAuthException} into a translated sentence for the merchant. Server text is never shown.
 */
final class OAuthMessages {

	/**
	 * Describes an error.
	 *
	 * @param OAuthException $error The error.
	 * @return string Plain text.
	 */
	public static function describe( OAuthException $error ): string {
		switch ( $error->error_name() ) {
			case 'insecure_url':
				return __( 'Connect with Bitsnap needs a secure (https) address for this store. Use a manual API key instead, or switch the store to https.', 'bitsnap-for-woocommerce' );
			case 'invalid_state':
				return __( 'The Bitsnap connection expired or could not be verified. Please connect again.', 'bitsnap-for-woocommerce' );
			case 'access_denied':
				return __( 'Access was not granted in Bitsnap, so nothing was connected.', 'bitsnap-for-woocommerce' );
			case 'registration_failed':
				return __( 'Bitsnap could not register this store. Check the API base URL and try again.', 'bitsnap-for-woocommerce' );
			case 'network':
			case 'server_error':
			case 'busy':
				return __( 'Bitsnap could not be reached. Try again in a moment.', 'bitsnap-for-woocommerce' );
			case 'invalid_response':
				return __( 'Bitsnap returned an answer this plugin does not understand. Update the plugin and try again.', 'bitsnap-for-woocommerce' );
			case 'invalid_grant':
			case 'not_connected':
				return __( 'The connection with Bitsnap is no longer valid. Connect again.', 'bitsnap-for-woocommerce' );
		}
		return __( 'Bitsnap did not complete the connection. Please try connecting again.', 'bitsnap-for-woocommerce' );
	}
}
