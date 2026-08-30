
# Changelog

All notable changes to this project are documented in this file.

## Unreleased

## 0.2.1

### Changed

 - Standardized translatable strings around reusable product-name constants, complete sentence
   templates, translator context, and placeholders that locales can safely reorder.
 - Added first-class block-script translation catalogs for all seven locales, retaining only the
   two runtime-consumable JSON files per locale instead of redundant source-path copies.

## 0.2.0

### Fixed

 - Bitcoin On-Chain orders paid to a fixed address are now recorded in the plugin's payments table,
   the same way orders paid to an address derived from an xPub already were. Until now those orders
   left no row at all, so they were missing from any accounting or reconciliation done against that
   table. Reprocessing an existing order (a checkout retry, or the "Pay for order" page) reuses the
   record already on file, so the customer always sees the address they were first given.
 - Database upgrades no longer run during a shopper's page load. They are checked when an
   administrator opens the dashboard and immediately after the plugin itself is updated, so a table
   change never lands in the middle of someone's visit to the store.
 - Rolling the plugin back to an older version no longer rewrites the recorded database version
   backwards, which used to make the real upgrade a no-op once the newer version was reinstalled.
 - Two administrators loading the dashboard at the same moment on a site with a pending database
   upgrade no longer run that upgrade twice in parallel; the second request waits for the first and
   stays silent instead of reporting a failure.
 - The plugin now restores a payments table that went missing (for example after a site
   migration/restore, or a manual database cleanup) instead of assuming it is still there —
   reactivating the plugin repairs it immediately, and the admin dashboard checks periodically on
   its own.
 - A database change that partly failed can no longer be recorded as successful. Previously, a
   future update touching more than one thing on the same table could have a failure in an earlier
   step masked by success in a later one.
 - Submitting the same fixed-address Bitcoin order twice in quick succession (for example a
   double-click, or two browser tabs on the same "Pay for order" page) no longer shows a payment
   error for an order that was, in fact, already recorded.
 - Submitting the same Lightning order twice in quick succession now returns the invoice already
   recorded for the order instead of showing an error or sending one request to an invoice that the
   plugin cannot later reconcile.

### Planned

 - Add support for additional blockchain networks (planned).
 - Add automatic payment confirmation (webhook/polling), reserved for a future Pro add-on.
 - Add fiat → sats conversion, reserved for a future Pro add-on.

## 0.1.2

### Changed

 - Updated the bundled dependencies to the versions built for the PHP 8.1 this plugin already
   requires. Composer had been resolving the entire dependency tree as if it were running on PHP 7.4,
   so the published package carried a cryptography compatibility layer a full major version behind
   and a PHP 5 compatibility package that never ran on any supported host. Both are gone and the
   published package is two packages smaller. This is dependency maintenance, not a security fix —
   no advisory applied to the previous versions. Address derivation, Lightning invoices and QR codes
   are unchanged, and the Bitcoin libraries themselves are byte-for-byte the same.
 - Switched the Bitcoin cryptography libraries from personal forks to the official
   `bitwasp/bitcoin` packages, resolved from Packagist. No functional change: the same addresses
   are derived, and the full test suite plus all 60 address vectors pass unchanged. The two
   side-channel advisories that `composer.json` used to suppress were filed against `mdanter/ecc`,
   a package this plugin has never shipped, so the suppression list is gone and `composer audit`
   now runs clean without it.

### Fixed

 - Saving the On-Chain gateway settings no longer prints PHP deprecation notices from the Bitcoin
   library, and no longer breaks the post-save redirect ("headers already sent"), on hosts that
   display PHP errors (e.g. with WP_DEBUG on). Address derivation at checkout is quiet the same way.
 - On a site running a PHP older than the 8.1 this plugin requires, the plugin now stops loading and
   says so in the admin, instead of taking the whole site down with a fatal error from the bundled
   dependencies. WordPress already blocks activating or updating it below 8.1, so this only affects a
   site whose PHP version was lowered after the plugin was already active.

## 0.1.1

### Changed

 - Admin errors, warnings, logs, connection-test feedback and order notes are now always in English
   instead of being translated. Everything the customer reads, and every settings label/description
   in the admin, stays translated as before (7 locales, 100%).

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
   disappearing silently after a save that reported success. Each explanation shows only on that
   gateway's own settings screen, where its fields are, instead of both appearing together on every
   WooCommerce admin page. Saving the settings no longer prints that explanation twice, and it can
   no longer report the state the gateway was in before the save.
 - A Lightning invoice reused on a checkout retry no longer risks being shown as expired while the
   node would still settle it: the order page now reads the invoice's actual expiry instead of
   re-anchoring its remaining hours to the order's creation date.
 - A BTCPay/lnd URL that could not be stored is now reported instead of being silently saved empty.
 - Connection tests report the real transport error (DNS, TLS, timeout) instead of "HTTP 0", and say
   so when a configured TLS certificate could not be written to a temporary file.
 - A QR code that cannot be generated (host missing gd, iconv or fileinfo) is now logged, and an
   order-details panel that fails to render shows a message instead of nothing at all.
 - An expired Lightning invoice now shows as "Expired" instead of "Awaiting Payment", without a QR
   code that no wallet would accept.
 - Copying the payment address on the admin order screen no longer submits the order form and
   reports "Order updated." — the button only copies, as it always did on the customer's page.
 - The default payment-failure message shown to customers is now translatable.
 - The On-Chain gateway is no longer disabled outright on hosts without the PHP GMP extension: only
   xPub derivation depends on it, so a store configured with a single fixed bech32 address
   (bc1…/tb1…) keeps taking on-chain payments there. The guard was wider than the actual dependency
   — bech32 needs no big-integer math — and the settings screen now explains that route when the
   extension is missing.

## 0.1.0

- Initial public release.
- Bitcoin On-Chain gateway: HD address derivation from xPub/yPub/zPub, mainnet and testnet.
- Bitcoin Lightning gateway: BTCPay Server and lnd REST support, with an in-admin connection tester.
- Support for WooCommerce Blocks and Custom Order Tables.
- Internationalization and translations included.
- Extension points reserved for the upcoming premium add-on, with no effect on the free plugin: an optional `value` (sats) arg honored by lnd invoice creation; `PayCryptoMeDBStatementsService::update_transaction_confirmations()` plus a `paycryptome_bitcoin_status_changed` action for on-chain confirmation tracking; order-details display filters (`paycryptome_order_display_args`, `paycryptome_order_display_data`); dedicated on-chain filters (`paycryptome_bitcoin_payment_uri`, `paycryptome_bitcoin_payment_data`); and the payment gateway is now passed to the `paycryptome_payment_amount`/`paycryptome_payment_data` filters.

## Upgrade Notice

= 0.1.2 =

Fixes saving the On-Chain settings on hosts that display PHP errors, where library deprecation
notices broke the post-save redirect. Bundled dependencies were updated to the versions built for the
PHP 8.1 this plugin already requires — maintenance, not a security fix — and a site below PHP 8.1 now
gets an explanation instead of a fatal error.

= 0.1.1 =

Admin errors, warnings and logs are now always in English; customer-facing text stays translated. A gateway that cannot take payments explains why instead of vanishing from checkout, and on-chain works without the GMP extension when a fixed bech32 address is configured.

= 0.1.0 =

Initial release.
