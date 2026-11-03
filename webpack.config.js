/**
 * Webpack config for the block checkout script.
 *
 * Extends the @wordpress/scripts config and swaps the dependency extraction
 * plugin for the WooCommerce one, so `@woocommerce/blocks-registry` and
 * `@woocommerce/settings` are loaded from WooCommerce instead of bundled.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,
	entry: {
		blocks: path.resolve( process.cwd(), 'assets/js/blocks/index.js' ),
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDependencyExtractionWebpackPlugin(),
	],
};
