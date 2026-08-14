
# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### Fixed

 - A valid wallet xPub/yPub/zPub was rejected with "not valid for the selected network" on hosts
   missing the PHP GMP extension. The missing extension is now named as the cause, and a host or
   internal fault is never reported as a problem with the key you entered.
 - A failed database table install no longer records the schema version as up to date, so the
   upgrade is retried instead of failing silently forever, and the admin warning stays visible
   until the problem is actually fixed.
 - The On-Chain gateway's "Hide for Non-Admin Users" setting no longer hides the Lightning gateway
   too; each gateway now honors only its own setting.
 - Both gateways now explain in the admin why they are hidden from checkout (missing PHP extension,
   no network/xPub, or missing BTCPay/lnd credentials for the selected node type) instead of
   disappearing silently after a save that reported success.
 - A BTCPay/lnd URL that could not be stored is now reported instead of being silently saved empty.
 - Connection tests report the real transport error (DNS, TLS, timeout) instead of "HTTP 0", and say
   so when a configured TLS certificate could not be written to a temporary file.
 - A QR code that cannot be generated (host missing gd, iconv or fileinfo) is now logged, and an
   order-details panel that fails to render shows a message instead of nothing at all.
 - An expired Lightning invoice now shows as "Expired" instead of "Awaiting Payment", without a QR
   code that no wallet would accept.
 - The default payment-failure message shown to customers is now translatable.

### Added

 - On-chain payments now work on hosts without the PHP GMP extension when a single fixed bech32
   address (bc1…/tb1…) is configured, instead of the gateway being disabled entirely — bech32 needs
   no big-integer math, so only xPub derivation actually requires the extension. The On-Chain
   settings screen explains this route when the extension is missing.

### Planned

 - Add support for additional blockchain networks (planned).
 - Add automatic payment confirmation (webhook/polling), reserved for a future premium add-on.
 - Add fiat → sats conversion, reserved for a future premium add-on.

## 0.1.0

- Initial public release.
- Bitcoin On-Chain gateway: HD address derivation from xPub/yPub/zPub, mainnet and testnet.
- Bitcoin Lightning gateway: BTCPay Server and lnd REST support, with an in-admin connection tester.
- Support for WooCommerce Blocks and Custom Order Tables.
- Internationalization and translations included.
- Extension points reserved for the upcoming premium add-on, with no effect on the free plugin: an optional `value` (sats) arg honored by lnd invoice creation; `PayCryptoMeDBStatementsService::update_transaction_confirmations()` plus a `paycryptome_bitcoin_status_changed` action for on-chain confirmation tracking; order-details display filters (`paycryptome_order_display_args`, `paycryptome_order_display_data`); dedicated on-chain filters (`paycryptome_bitcoin_payment_uri`, `paycryptome_bitcoin_payment_data`); and the payment gateway is now passed to the `paycryptome_payment_amount`/`paycryptome_payment_data` filters.

## Upgrade Notice

= 0.1.0 =

Initial release.

