# PayCrypto.Me for WooCommerce

Accept cryptocurrency payments in your WooCommerce store with PayCrypto.Me. A complete solution that allows your customers to pay with Bitcoin (BTC), Ethereum (ETH), Solana (SOL), and many other cryptocurrencies.

## Description

PayCrypto.Me for WooCommerce introduces a secure and easy-to-use payment gateway that enables cryptocurrency payments in your online store. With support for major cryptocurrencies and a user-friendly interface, you can start accepting crypto payments in minutes.

## Features

- ✅ Support for multiple cryptocurrencies (BTC, ETH, SOL, and more)
- ✅ WooCommerce Blocks compatibility (Cart & Checkout blocks)
- ✅ Secure payment processing through PayCrypto.Me API
- ✅ Real-time payment status updates
- ✅ Refund support
- ✅ Test mode for development
- ✅ Comprehensive logging for debugging
- ✅ Responsive design for mobile devices
- ✅ Multi-language support ready

## Requirements

- WordPress 5.0 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- PayCrypto.Me account and API key

## Installation

### Automatic Installation

1. Login to your WordPress admin panel
2. Go to Plugins > Add New
3. Search for "PayCrypto.Me for WooCommerce"
4. Click "Install Now" and then "Activate"

### Manual Installation

1. Download the plugin ZIP file
2. Upload the ZIP file through WordPress admin (Plugins > Add New > Upload Plugin)
3. Activate the plugin through the 'Plugins' menu in WordPress

### FTP Installation

1. Download and extract the plugin files
2. Upload the `woocommerce-gateway-pay-crypto-me` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress

## Configuration

1. Go to **WooCommerce > Settings > Payments**
2. Find **PayCrypto.Me** in the payment methods list
3. Click **Set up** or **Manage**
4. Configure the following settings:

### Basic Settings

- **Enable/Disable**: Enable PayCrypto.Me payments
- **Title**: Payment method title shown to customers (default: "Cryptocurrency Payment")
- **Description**: Payment method description shown to customers

### API Configuration

- **Test Mode**: Enable for testing (uses test API endpoints)
- **API Key**: Your PayCrypto.Me API key (get it from your dashboard)

### Advanced Settings

- **Hide for non-admin users**: Only show payment method to administrators (useful for testing)
- **Enable logging**: Enable detailed logging for debugging purposes

## Getting Your API Key

1. Visit [PayCrypto.Me](https://paycrypto.me/)
2. Create an account or log in
3. Navigate to your dashboard
4. Go to API Settings
5. Generate a new API key
6. Copy the API key to your WooCommerce settings

## Testing

1. Enable **Test Mode** in the plugin settings
2. Use test API credentials from your PayCrypto.Me dashboard
3. Place test orders to verify the integration
4. Check payment status updates and order management

## Troubleshooting

### Common Issues

**Payment method not visible**
- Ensure the plugin is activated
- Check if "Hide for non-admin users" is enabled
- Verify WooCommerce is active and up to date

**API connection errors**
- Verify your API key is correct
- Check if test mode setting matches your API key type
- Enable logging to see detailed error messages

**Blocks checkout issues**
- Ensure you're using WooCommerce 5.0+
- Clear any caching plugins
- Check browser console for JavaScript errors

### Debug Logging

Enable logging in the plugin settings to troubleshoot issues:

1. Go to WooCommerce > Settings > Payments > PayCrypto.Me
2. Enable "Enable logging"
3. View logs at WooCommerce > Status > Logs

## Support

- **Documentation**: [PayCrypto.Me Docs](https://docs.paycrypto.me/)
- **Support Forum**: [WordPress.org Support](https://wordpress.org/support/plugin/woocommerce-gateway-pay-crypto-me/)
- **Email Support**: support@paycrypto.me
- **GitHub Issues**: [Report bugs or request features](https://github.com/pay-crypto-me/woocommerce-gateway-pay-crypto-me/issues)

## Contributing

We welcome contributions! Please see our [Contributing Guidelines](CONTRIBUTING.md) for details.

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## Security

If you discover a security vulnerability, please send an email to security@paycrypto.me instead of using the issue tracker.

## License

This plugin is licensed under the [GNU General Public License v3.0](LICENSE).

## Credits

- **Developer**: Lucas Rosa
- **Company**: PayCrypto.Me
- **Contributors**: [View all contributors](https://github.com/pay-crypto-me/woocommerce-gateway-pay-crypto-me/contributors)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a complete list of changes in each version.

---

**Tested up to**: WordPress 6.4
**WooCommerce tested up to**: 8.2
**Stable tag**: 0.1.0
**License**: GPLv3
**License URI**: https://www.gnu.org/licenses/gpl-3.0.html