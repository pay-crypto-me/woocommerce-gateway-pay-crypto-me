<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       PayCryptoMeLightningDBStatementsService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class PayCryptoMeLightningDBStatementsService
{
    private string $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'paycrypto_me_lightning_invoices';
    }

    public function get_table_name(): string
    {
        return $this->table_name;
    }

    public function get_by_order_id(int $order_id): ?array
    {
        global $wpdb;

        $cache_key = 'paycrypto_lightning_order_' . $order_id;
        $cached = function_exists('wp_cache_get') ? wp_cache_get($cache_key, 'paycrypto_me') : false;
        if ($cached !== false && $cached !== null) {
            return $cached;
        }

        $table = esc_sql($this->table_name);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_id = %d LIMIT 1",
                $order_id
            ),
            ARRAY_A
        );

        $row = $row ?: null;
        // A miss must be re-readable immediately: another request may insert the order's invoice
        // between the caller's first lookup and its own insert attempt.
        if ($row !== null && function_exists('wp_cache_set')) {
            wp_cache_set($cache_key, $row, 'paycrypto_me', 300);
        }

        return $row;
    }

    public function exists_for_order(int $order_id): bool
    {
        return $this->get_by_order_id($order_id) !== null;
    }

    public function get_by_invoice_id(string $invoice_id): ?array
    {
        global $wpdb;

        $table = esc_sql($this->table_name);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE invoice_id = %s LIMIT 1",
                $invoice_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function insert_invoice(
        int $order_id,
        string $node_type,
        string $invoice_id,
        string $payment_request,
        string $expires_at,
        ?int $amount_sats = null
    ): bool {
        global $wpdb;

        if ($this->exists_for_order($order_id)) {
            return false;
        }

        $table = esc_sql($this->table_name);

        $data = [
            'order_id'        => $order_id,
            'node_type'       => $node_type,
            'invoice_id'      => $invoice_id,
            'payment_request' => $payment_request,
            'expires_at'      => $expires_at,
            'status'          => 'New',
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s'];

        if ($amount_sats !== null) {
            $data['amount_sats'] = $amount_sats;
            $formats[]           = '%d';
        }

        $inserted = $wpdb->insert($table, $data, $formats);

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('paycrypto_lightning_order_' . $order_id, 'paycrypto_me');
        }

        return $inserted !== false;
    }

    /**
     * Compare-and-swaps an existing (expired) invoice row with a freshly created one.
     *
     * Used instead of insert_invoice() when the order already has a row — insert_invoice()
     * would silently return false (UNIQUE KEY unique_order) and the caller would otherwise
     * diverge: the customer pays the new invoice while the DB (and any webhook lookup) still
     * points at the old one. Matching the invoice id observed by the caller makes concurrent
     * replacements deterministic: only one request can replace that exact expired row.
     */
    public function replace_invoice(
        int $order_id,
        string $node_type,
        string $invoice_id,
        string $payment_request,
        string $expires_at,
        ?int $amount_sats = null,
        ?string $expected_invoice_id = null
    ): bool {
        global $wpdb;

        $table = esc_sql($this->table_name);

        $data = [
            'node_type'       => $node_type,
            'invoice_id'      => $invoice_id,
            'payment_request' => $payment_request,
            'expires_at'      => $expires_at,
            'status'          => 'New',
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s'];

        if ($amount_sats !== null) {
            $data['amount_sats'] = $amount_sats;
            $formats[]           = '%d';
        }

        $where         = ['order_id' => $order_id];
        $where_formats = ['%d'];

        if ($expected_invoice_id !== null) {
            $where['invoice_id'] = $expected_invoice_id;
            $where_formats[]     = '%s';
        }

        $updated = $wpdb->update($table, $data, $where, $formats, $where_formats);

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('paycrypto_lightning_order_' . $order_id, 'paycrypto_me');
        }

        return $expected_invoice_id === null ? $updated !== false : $updated === 1;
    }

    public function update_status(int $order_id, string $status): bool
    {
        global $wpdb;

        $old_status = $this->get_by_order_id($order_id)['status'] ?? null;

        $table = esc_sql($this->table_name);

        $updated = $wpdb->update(
            $table,
            ['status' => $status],
            ['order_id' => $order_id],
            ['%s'],
            ['%d']
        );

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('paycrypto_lightning_order_' . $order_id, 'paycrypto_me');
        }

        if ($updated !== false && $old_status !== null && $old_status !== $status) {
            do_action('paycryptome_lightning_status_changed', $order_id, $old_status, $status);
        }

        return $updated !== false;
    }
}

// phpcs:enable
