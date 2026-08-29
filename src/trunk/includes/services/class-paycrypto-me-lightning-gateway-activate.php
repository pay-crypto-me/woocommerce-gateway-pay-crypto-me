<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       PayCryptoMeLightningGatewayActivate
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class PayCryptoMeLightningGatewayActivate
{
    // Bare (unprefixed) table name — see PayCryptoMeBitcoinGatewayActivate::TABLES for why this
    // is the one source rather than a literal repeated across DbInstaller/tests.
    public const TABLE_LIGHTNING_INVOICES = 'paycrypto_me_lightning_invoices';

    public const TABLES = [self::TABLE_LIGHTNING_INVOICES];

    /**
     * @return string[] Errors recorded during this run — see the Bitcoin activator for why the
     *                  list is returned as well as stored.
     */
    public static function activate(): array
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // No "IF NOT EXISTS" — see the docblock on PayCryptoMeBitcoinGatewayActivate for why:
        // dbDelta() would otherwise capture "IF" as the table name and never diff/ALTER again.
        $table_name = $wpdb->prefix . self::TABLE_LIGHTNING_INVOICES;
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            node_type VARCHAR(20) NOT NULL,
            invoice_id VARCHAR(255) NOT NULL,
            payment_request TEXT NOT NULL,
            amount_sats BIGINT(20) UNSIGNED NULL,
            expires_at DATETIME NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'New',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_order (order_id)
        ) $charset_collate;";

        return DbDeltaRunner::run($sql, $table_name);
    }
}
