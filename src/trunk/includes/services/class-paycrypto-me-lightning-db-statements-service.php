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
    private const INVOICE_ID_MAX_BYTES = 255;
    private const STATUS_MAX_BYTES = 30;

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

    /**
     * Atomically projects one status transition for one specific invoice.
     *
     * The invoice id and expected status are part of the compare-and-swap so a delayed webhook
     * cannot settle a replacement invoice and concurrent requests cannot both publish the same
     * transition. The action is a post-write notification, not a durable delivery mechanism.
     */
    public function transition_status(
        int $order_id,
        string $invoice_id,
        string $expected_status,
        string $new_status
    ): LightningStatusTransitionResult {
        global $wpdb;

        $this->validate_transition_arguments($order_id, $invoice_id, $expected_status, $new_status);

        $table = esc_sql($this->table_name);

        $can_suppress_errors = \method_exists($wpdb, 'suppress_errors');
        $previous_suppress_errors = $can_suppress_errors ? $wpdb->suppress_errors() : false;
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic CAS against the plugin's own escaped table; dynamic values are prepared and this mutation cannot be cached.
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET status = %s WHERE order_id = %d AND invoice_id = %s AND status = %s",
                    $new_status,
                    $order_id,
                    $invoice_id,
                    $expected_status
                )
            );
        } finally {
            if ($can_suppress_errors) {
                $wpdb->suppress_errors($previous_suppress_errors);
            }
        }

        if ($updated === false) {
            return $this->transition_result(
                LightningStatusTransitionResult::ERROR,
                $order_id,
                $invoice_id,
                null,
                $expected_status,
                $new_status,
                null,
                (string) (($wpdb->last_error ?? '') ?: 'Database update failed.')
            );
        }

        if ((int) $updated === 1) {
            $this->delete_order_cache($order_id);

            $result = $this->transition_result(
                LightningStatusTransitionResult::APPLIED,
                $order_id,
                $invoice_id,
                $invoice_id,
                $expected_status,
                $new_status,
                $new_status
            );

            do_action('paycryptome_lightning_status_changed', $order_id, $expected_status, $new_status, $invoice_id);

            return $result;
        }

        $row = $this->get_status_row_direct($order_id);
        $read_error = (string) ($wpdb->last_error ?? '');

        if ($read_error !== '') {
            return $this->transition_result(
                LightningStatusTransitionResult::ERROR,
                $order_id,
                $invoice_id,
                null,
                $expected_status,
                $new_status,
                null,
                $read_error
            );
        }

        if ($row === null) {
            return $this->transition_result(
                LightningStatusTransitionResult::NOT_FOUND,
                $order_id,
                $invoice_id,
                null,
                $expected_status,
                $new_status
            );
        }

        $this->delete_order_cache($order_id);
        $stored_invoice_id = (string) $row['invoice_id'];
        $current_status = (string) $row['status'];
        $outcome = $stored_invoice_id === $invoice_id && $current_status === $new_status
            ? LightningStatusTransitionResult::ALREADY_APPLIED
            : LightningStatusTransitionResult::CONFLICT;

        return $this->transition_result(
            $outcome,
            $order_id,
            $invoice_id,
            $stored_invoice_id,
            $expected_status,
            $new_status,
            $current_status
        );
    }

    /**
     * Backward-compatible status writer.
     *
     * @deprecated 0.3.0 Use transition_status() with the invoice identity and expected status.
     */
    public function update_status(int $order_id, string $status): bool
    {
        global $wpdb;

        $row = $this->get_status_row_direct($order_id);
        if ($row === null || (string) ($wpdb->last_error ?? '') !== '') {
            return false;
        }

        return $this->transition_status(
            $order_id,
            (string) $row['invoice_id'],
            (string) $row['status'],
            $status
        )->is_success();
    }

    private function get_status_row_direct(int $order_id): ?array
    {
        global $wpdb;

        $table = esc_sql($this->table_name);
        $can_suppress_errors = \method_exists($wpdb, 'suppress_errors');
        $previous_suppress_errors = $can_suppress_errors ? $wpdb->suppress_errors() : false;
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Resolution of a live CAS result must bypass object cache; table is escaped and the id is prepared.
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT invoice_id, status FROM {$table} WHERE order_id = %d LIMIT 1",
                    $order_id
                ),
                ARRAY_A
            );
        } finally {
            if ($can_suppress_errors) {
                $wpdb->suppress_errors($previous_suppress_errors);
            }
        }

        return $row ?: null;
    }

    private function delete_order_cache(int $order_id): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('paycrypto_lightning_order_' . $order_id, 'paycrypto_me');
        }
    }

    private function validate_transition_arguments(
        int $order_id,
        string $invoice_id,
        string $expected_status,
        string $new_status
    ): void {
        if ($order_id <= 0) {
            throw new \InvalidArgumentException('Order id must be greater than zero.');
        }
        if (trim($invoice_id) === '') {
            throw new \InvalidArgumentException('Invoice id must not be empty.');
        }
        if (strlen($invoice_id) > self::INVOICE_ID_MAX_BYTES) {
            throw new \InvalidArgumentException('Invoice id must not exceed 255 bytes.');
        }
        if (trim($expected_status) === '') {
            throw new \InvalidArgumentException('Expected status must not be empty.');
        }
        if (strlen($expected_status) > self::STATUS_MAX_BYTES) {
            throw new \InvalidArgumentException('Expected status must not exceed 30 bytes.');
        }
        if (trim($new_status) === '') {
            throw new \InvalidArgumentException('New status must not be empty.');
        }
        if (strlen($new_status) > self::STATUS_MAX_BYTES) {
            throw new \InvalidArgumentException('New status must not exceed 30 bytes.');
        }
    }

    private function transition_result(
        string $outcome,
        int $order_id,
        string $requested_invoice_id,
        ?string $stored_invoice_id,
        string $expected_status,
        string $requested_status,
        ?string $current_status = null,
        ?string $error_message = null
    ): LightningStatusTransitionResult {
        return new LightningStatusTransitionResult(
            $outcome,
            $order_id,
            $requested_invoice_id,
            $stored_invoice_id,
            $expected_status,
            $requested_status,
            $current_status,
            $error_message
        );
    }
}

// phpcs:enable
