module.exports = {
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	rules: {
		// These packages are provided by WooCommerce at runtime (see webpack.config.js), not installed.
		'import/no-unresolved': [ 'error', { ignore: [ '^@woocommerce/' ] } ],
	},
};
