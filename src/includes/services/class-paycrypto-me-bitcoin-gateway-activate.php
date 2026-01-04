<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       BitcoinAddressService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class PayCryptoMeBitcoinGatewayActivate
{
    public static function activate()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'paycrypto_me_bitcoin_transactions_data';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $indexes_table = $wpdb->prefix . 'paycrypto_me_bitcoin_wallet_xpubkeys';
        $sql = "CREATE TABLE IF NOT EXISTS $indexes_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            xpub VARCHAR(255) NOT NULL,
            network VARCHAR(50) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_xpub_network (xpub, network)
        ) $charset_collate;";

        dbDelta($sql);

        $indexes_table = $wpdb->prefix . 'paycrypto_me_bitcoin_derivation_indexes';
        $sql = "CREATE TABLE IF NOT EXISTS $indexes_table (
            derivation_index BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            wallet_xpubkeys_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (derivation_index),
            FOREIGN KEY (wallet_xpubkeys_id) REFERENCES {$wpdb->prefix}paycrypto_me_bitcoin_wallet_xpubkeys(id)
        ) $charset_collate;";

        dbDelta($sql);

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            payment_address VARCHAR(255) NOT NULL,
            num_confirmations INT(11) NOT NULL DEFAULT 0,
            amount_received DECIMAL(16,8) NULL,
            tx_hash VARCHAR(255) NULL,
            derivation_index_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_order (order_id),
            UNIQUE KEY unique_payment_address (payment_address),
            UNIQUE KEY unique_derivation_index (derivation_index_id),
            FOREIGN KEY (derivation_index_id) REFERENCES {$wpdb->prefix}paycrypto_me_bitcoin_derivation_indexes(derivation_index)
        ) $charset_collate;";

        dbDelta($sql);
    }
}