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

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // No "IF NOT EXISTS": dbDelta() extracts the table name via
        // preg_match('|CREATE TABLE ([^ ]*)|', ...), so "IF NOT EXISTS" makes it capture the
        // literal word "IF" — dbDelta then always believes the table doesn't exist yet and
        // just re-runs this raw CREATE (harmless the first time, but any future column change
        // here becomes a silent no-op forever, since dbDelta's real diff/ALTER logic never runs).
        $wallets_table = $wpdb->prefix . 'paycrypto_me_bitcoin_wallet_xpubkeys';
        $sql = "CREATE TABLE $wallets_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            xpub VARCHAR(191) NOT NULL,
            network VARCHAR(50) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_xpub_network (xpub, network)
        ) $charset_collate;";

        dbDelta($sql);
        self::record_error_if_any($wallets_table);

        // No FOREIGN KEY: dbDelta doesn't manage FKs at all, and on a MyISAM host the
        // constraint is silently dropped — integrity is already enforced by the composite PK.
        $indexes_table = $wpdb->prefix . 'paycrypto_me_bitcoin_derivation_indexes';
        $sql = "CREATE TABLE $indexes_table (
            derivation_index BIGINT(20) UNSIGNED NOT NULL,
            wallet_xpubkeys_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (derivation_index, wallet_xpubkeys_id)
        ) $charset_collate;";

        dbDelta($sql);
        self::record_error_if_any($indexes_table);

        $table_name = $wpdb->prefix . 'paycrypto_me_bitcoin_transactions_data';
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            payment_address VARCHAR(255) NOT NULL,
            num_confirmations INT(11) NOT NULL DEFAULT 0,
            amount_received DECIMAL(16,8) NULL,
            tx_hash VARCHAR(255) NULL,
            derivation_index_id BIGINT(20) UNSIGNED NOT NULL,
            wallet_xpubkeys_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_order (order_id)
        ) $charset_collate;";

        dbDelta($sql);
        self::record_error_if_any($table_name);
    }

    /**
     * dbDelta()'s return value is a list of change descriptions, not a success flag — the
     * only reliable failure signal is $wpdb->last_error, which dbDelta never checks itself.
     * Without this, a failed CREATE (e.g. the InnoDB 767-byte index-key limit on older
     * MySQL/MariaDB) reports activation as successful and fails silently on the first order.
     */
    private static function record_error_if_any(string $table_name): void
    {
        global $wpdb;

        if (empty($wpdb->last_error)) {
            return;
        }

        $errors   = get_option('paycrypto_me_db_activation_errors', []);
        $errors[] = \sprintf('%s: %s', $table_name, $wpdb->last_error);
        update_option('paycrypto_me_db_activation_errors', $errors);
    }
}