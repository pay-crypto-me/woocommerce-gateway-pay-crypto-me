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
    // Bare (unprefixed) table names — the one source for them. DbInstaller::tables() exposes
    // these for the missing-table health check; tests/integration/SchemaTestCase and
    // tests/bin/dump-schema.php point at DbInstaller::tables() rather than repeating the list.
    public const TABLE_WALLETS = 'paycrypto_me_bitcoin_wallet_xpubkeys';
    public const TABLE_DERIVATION_INDEXES = 'paycrypto_me_bitcoin_derivation_indexes';
    public const TABLE_TRANSACTIONS = 'paycrypto_me_bitcoin_transactions_data';

    public const TABLES = [self::TABLE_WALLETS, self::TABLE_DERIVATION_INDEXES, self::TABLE_TRANSACTIONS];

    /**
     * @return string[] Errors recorded during this run — empty means every table is in place.
     *                  Returned as well as stored so DbInstaller::install() can decide whether to
     *                  record the schema version without re-reading the option.
     */
    public static function activate(): array
    {
        global $wpdb;

        $errors = [];

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // No "IF NOT EXISTS": dbDelta() extracts the table name via
        // preg_match('|CREATE TABLE ([^ ]*)|', ...), so "IF NOT EXISTS" makes it capture the
        // literal word "IF" — dbDelta then always believes the table doesn't exist yet and
        // just re-runs this raw CREATE (harmless the first time, but any future column change
        // here becomes a silent no-op forever, since dbDelta's real diff/ALTER logic never runs).
        $wallets_table = $wpdb->prefix . self::TABLE_WALLETS;
        $sql = "CREATE TABLE $wallets_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            xpub VARCHAR(191) NOT NULL,
            network VARCHAR(50) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_xpub_network (xpub, network)
        ) $charset_collate;";

        $errors = array_merge($errors, DbDeltaRunner::run($sql, $wallets_table));

        // No FOREIGN KEY: dbDelta doesn't manage FKs at all, and on a MyISAM host the
        // constraint is silently dropped — integrity is already enforced by the composite PK.
        $indexes_table = $wpdb->prefix . self::TABLE_DERIVATION_INDEXES;
        $sql = "CREATE TABLE $indexes_table (
            derivation_index BIGINT(20) UNSIGNED NOT NULL,
            wallet_xpubkeys_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (derivation_index, wallet_xpubkeys_id)
        ) $charset_collate;";

        $errors = array_merge($errors, DbDeltaRunner::run($sql, $indexes_table));

        $table_name = $wpdb->prefix . self::TABLE_TRANSACTIONS;
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

        $errors = array_merge($errors, DbDeltaRunner::run($sql, $table_name));

        return $errors;
    }
}