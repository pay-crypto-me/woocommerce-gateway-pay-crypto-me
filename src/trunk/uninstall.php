<?php
/**
 * PayCrypto.Me Gateway for WooCommerce — Uninstall handler
 *
 * Deletes the gateway settings, which hold secrets in plain text (lnd_macaroon_hex — admin-level
 * control of the node — btcpay_api_key, lnd_certificate) that would otherwise remain in the
 * database indefinitely after uninstall.
 *
 * The 4 custom tables are deliberately KEPT: they are the store's payment records (derived
 * addresses, derivation indexes, Lightning invoices) and remain needed for accounting and
 * reconciliation of past orders long after the plugin is removed. Dropping them would destroy
 * financial history that exists nowhere else. `paycrypto_me_db_version` is kept for the same
 * reason — it describes the schema we are leaving in place, so a later reinstall upgrades from
 * the correct state instead of assuming a clean install.
 *
 * @package WooCommerce\PayCryptoMe
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('woocommerce_paycrypto_me_settings');
delete_option('woocommerce_paycrypto_me_lightning_settings');

// Stale admin-notice buffer and the failed-upgrade/health-check throttles, not payment records.
delete_option('paycrypto_me_db_activation_errors');
delete_transient('paycrypto_me_db_upgrade_retry');
delete_transient('paycrypto_me_db_health_check');
