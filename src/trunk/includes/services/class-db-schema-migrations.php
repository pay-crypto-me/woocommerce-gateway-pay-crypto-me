<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       DbSchemaMigrations
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Imperative, versioned schema changes that dbDelta() cannot apply safely.
 *
 * Each migration is idempotent and verifies its post-condition. A failed step returns an error
 * through the same channel as the table activators, so DbInstaller does not record the target
 * schema version until every declarative and imperative change has succeeded.
 */
final class DbSchemaMigrations
{
    private const LEGACY_ONCHAIN_COLUMNS = [
        'num_confirmations',
        'amount_received',
        'tx_hash',
    ];

    /**
     * Runs all migrations newer than the recorded schema version.
     *
     * The current install path also runs this for a fresh install and a forced repair. The checks
     * are deliberately harmless there: fresh schema has no legacy columns, and a repair must be
     * able to finish a partially completed migration.
     *
     * @return string[]
     */
    public static function run(string $recorded_version): array
    {
        if (version_compare($recorded_version, '2', '>=')) {
            return [];
        }

        return self::remove_legacy_onchain_columns();
    }

    /**
     * Removes confirmation-tracking data that belonged to the former Pro seam.
     *
     * @return string[]
     */
    private static function remove_legacy_onchain_columns(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . PayCryptoMeBitcoinGatewayActivate::TABLE_TRANSACTIONS;
        $errors = [];

        foreach (self::LEGACY_ONCHAIN_COLUMNS as $column) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration must inspect the live information_schema; caching could make an idempotent retry act on stale structure.
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    $table,
                    $column
                )
            );

            if ((int) $exists === 0) {
                continue;
            }

            $safe_table = esc_sql($table);
            $safe_column = esc_sql($column);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- This is the intentional, escaped DDL for the versioned migration; table/column names cannot be placeholders.
            $result = $wpdb->query("ALTER TABLE `{$safe_table}` DROP COLUMN `{$safe_column}`");

            if ($result === false || !empty($wpdb->last_error)) {
                $errors[] = self::record_error(
                    $table,
                    "Could not remove legacy column {$column}: " . ($wpdb->last_error ?: 'database query failed')
                );
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Post-condition must inspect the live information_schema, not a cached result.
            $still_exists = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    $table,
                    $column
                )
            );

            if ((int) $still_exists !== 0) {
                $errors[] = self::record_error(
                    $table,
                    "Legacy column {$column} is still present after migration"
                );
            }
        }

        return $errors;
    }

    private static function record_error(string $table, string $message): string
    {
        $error = sprintf('%s: %s', $table, $message);
        $errors = get_option(DbInstaller::ERRORS_OPTION, []);
        $errors[] = $error;
        update_option(DbInstaller::ERRORS_OPTION, $errors);

        return $error;
    }
}
