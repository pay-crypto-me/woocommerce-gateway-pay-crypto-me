const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        blocks: path.resolve(process.cwd(), 'src/blocks.js'),
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve(process.cwd(), 'assets/js/frontend/'),
        filename: '[name].js',
    },
};