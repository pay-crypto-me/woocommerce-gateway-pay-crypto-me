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

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            xpub VARCHAR(255) NOT NULL,
            network VARCHAR(50) NOT NULL,
            derivation_index BIGINT(20) UNSIGNED NOT NULL,
            payment_address VARCHAR(255) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_xpub_index (xpub, derivation_index)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}