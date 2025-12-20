const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        'paycrypto-me-script': path.resolve(process.cwd(), 'src/paycrypto-me-script.js'),
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve(process.cwd(), 'assets/js/frontend/'),
        filename: '[name].js',
    },
    externals: {
        '@woocommerce/blocks-registry': ['wc', 'wcBlocksRegistry'],
        '@woocommerce/settings': ['wc', 'wcSettings'],
        '@wordpress/element': 'wp.element',
        '@wordpress/i18n': 'wp.i18n',
        '@wordpress/html-entities': 'wp.htmlEntities',
    },
};