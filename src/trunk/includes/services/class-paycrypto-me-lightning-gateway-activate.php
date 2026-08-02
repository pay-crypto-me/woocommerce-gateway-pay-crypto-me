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
    public static function activate()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // No "IF NOT EXISTS" — see the docblock on PayCryptoMeBitcoinGatewayActivate for why:
        // dbDelta() would otherwise capture "IF" as the table name and never diff/ALTER again.
        $table_name = $wpdb->prefix . 'paycrypto_me_lightning_invoices';
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

        dbDelta($sql);

        // See PayCryptoMeBitcoinGatewayActivate::record_error_if_any() — dbDelta() never
        // checks $wpdb->last_error itself, so a failed CREATE would otherwise report success.
        if (!empty($wpdb->last_error)) {
            $errors   = get_option('paycrypto_me_db_activation_errors', []);
            $errors[] = \sprintf('%s: %s', $table_name, $wpdb->last_error);
            update_option('paycrypto_me_db_activation_errors', $errors);
        }
    }
}
