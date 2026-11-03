/**
 * Registers the Bitsnap payment method with the WooCommerce Cart and Checkout
 * Blocks. The payment itself is a redirect to Bitsnap, so there are no fields:
 * the label, a short description and the (optional) test mode notice are all
 * that render.
 */
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { createElement } from '@wordpress/element';

const settings = getSetting( 'bitsnap_data', {} );

const title = decodeEntities(
	settings.title ||
		__( 'Online payment (Bitsnap)', 'bitsnap-for-woocommerce' )
);

const Label = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	return createElement( PaymentMethodLabel, { text: title } );
};

const Content = () => {
	const description = decodeEntities( settings.description || '' );
	return createElement(
		'div',
		{ className: 'wc-block-components-bitsnap-description' },
		description,
		settings.testMode
			? createElement(
					'p',
					{ className: 'wc-block-components-bitsnap-test-mode' },
					__(
						'Test mode is enabled. No real money is charged.',
						'bitsnap-for-woocommerce'
					)
			  )
			: null
	);
};

registerPaymentMethod( {
	name: 'bitsnap',
	label: createElement( Label, null ),
	content: createElement( Content, null ),
	edit: createElement( Content, null ),
	// Hide the method when the cart currency is not one the gateway supports.
	canMakePayment: ( args ) => {
		const currency =
			args && args.cartTotals ? args.cartTotals.currency_code : null;
		if ( ! currency || ! Array.isArray( settings.supportedCurrencies ) ) {
			return true;
		}
		return settings.supportedCurrencies.includes( currency );
	},
	ariaLabel: title,
	supports: {
		features: settings.supports || [ 'products', 'refunds' ],
	},
} );
