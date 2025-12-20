# Changelog

All notable changes to the PayCrypto.Me for WooCommerce plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- JavaScript files for WooCommerce Blocks support
- CSS styling for payment method interface
- Translation files for internationalization
- Build configuration for modern JavaScript development

### Changed
- Nothing yet

### Fixed
- Nothing yet

### Removed
- Nothing yet

## [0.1.0] - 2025-10-26

### Added
- Initial plugin release
- PayCrypto.Me payment gateway integration
- Support for multiple cryptocurrencies (BTC, ETH, SOL, etc.)
- WooCommerce Blocks compatibility (basic structure)
- Payment method configuration in WooCommerce settings
- Test mode support for development
- API key authentication with PayCrypto.Me
- Refund support for processed payments
- Debug logging functionality
- Admin option to hide payment method from non-admin users
- Plugin text domain for translations
- WordPress and WooCommerce compatibility declarations
- Plugin icons and branding assets

### Security
- Secure API communication with PayCrypto.Me
- Input validation and sanitization
- WordPress security best practices implementation

### Technical
- Object-oriented PHP code structure
- PSR-4 autoloading compatible namespace
- WordPress coding standards compliance
- WooCommerce payment gateway abstract class extension
- Custom order tables compatibility
- Action hooks for extensibility

---

## Release Notes

### Version 0.1.0 - Initial Release

This is the first release of PayCrypto.Me for WooCommerce. The plugin provides a solid foundation for accepting cryptocurrency payments in WooCommerce stores.

**Key Features:**
- Complete payment gateway integration
- Multi-cryptocurrency support
- WooCommerce admin integration
- Test mode for safe development
- Comprehensive logging system

**Next Steps:**
- Complete WooCommerce Blocks JavaScript implementation
- Add frontend styling and user experience improvements
- Implement real-time payment status updates
- Add webhook support for instant payment confirmations
- Create comprehensive translation files

**Known Limitations:**
- WooCommerce Blocks frontend JavaScript files need to be created
- Frontend CSS styling is minimal
- Translation files are not yet generated
- Webhook support is not implemented

**Compatibility:**
- WordPress: 5.0+
- WooCommerce: 5.0+
- PHP: 7.4+

---

## Upgrade Notes

### From Development to 0.1.0
- No upgrade path needed - this is the initial release

---

## Support

For support, bug reports, or feature requests:
- GitHub: https://github.com/paycrypto-me/woocommerce-gateway-paycrypto-me/issues
- Email: support@paycrypto.me
- Documentation: https://docs.paycrypto.me/

---

**Legend:**
- `Added` for new features
- `Changed` for changes in existing functionality
- `Deprecated` for soon-to-be removed features
- `Removed` for now removed features
- `Fixed` for any bug fixes
- `Security` for vulnerability fixes