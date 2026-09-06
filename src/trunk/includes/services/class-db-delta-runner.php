<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       DbDeltaRunner
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Runs dbDelta() for one table and verifies the result actually applied — not just that it ran
 * without a fatal.
 *
 * $wpdb->last_error alone is not enough: wpdb::query() calls flush() (which clears last_error) on
 * every single query, and dbDelta() builds every statement up front and executes them all in one
 * loop, column ALTERs before index ALTERs. So last_error only ever reflects dbDelta's LAST
 * statement — a failing "ADD COLUMN" followed by a succeeding "ADD INDEX" leaves last_error empty
 * even though the column never landed (measured against MySQL 8.0.46; see AGENTS.md's F5). Today,
 * every activator emits exactly one statement per table, so this has never fired in production —
 * it exists for the first DB_VERSION bump that changes more than one thing on the same table.
 *
 * The second check is dbDelta($sql, false): with execute=false it runs only SHOW queries and
 * returns the list of changes it WOULD make, without applying them (read-only). Its own change
 * descriptions start with "Created table ", "Added column " or "Added index " exactly when the
 * corresponding structure is still absent (measured) — that subset is what this class treats as a
 * real failure. "Changed type of …" / "Changed default value of …" describe a column that DOES
 * exist but is declared differently — the class of cross-engine normalisation noise (MariaDB,
 * MySQL 5.7, Percona) that would otherwise risk blocking the version option forever on a healthy
 * site, so those are deliberately ignored here. The full list is asserted empty for our own schema
 * by the integration suite (fresh install and every frozen snapshot), so real drift is caught in
 * development rather than treated as fatal on a merchant's site.
 */
final class DbDeltaRunner
{
    private const STRUCTURAL_ABSENCE_PREFIXES = ['Created table ', 'Added column ', 'Added index '];

    /**
     * @return string[] Errors recorded during this run — empty means the table genuinely matches
     *                  $sql. Appended to DbInstaller::ERRORS_OPTION and returned, same shape the
     *                  activators already exposed, so DbInstaller::install() can decide about the
     *                  version option without re-reading the option.
     */
    public static function run(string $sql, string $table_name): array
    {
        global $wpdb;

        dbDelta($sql);

        if (!empty($wpdb->last_error)) {
            return self::record($table_name, $wpdb->last_error);
        }

        $pending = self::structural_absences((array) dbDelta($sql, false));

        if ($pending !== []) {
            return self::record(
                $table_name,
                'dbDelta ran without reporting an error but the schema is still missing: ' . implode('; ', $pending)
            );
        }

        return [];
    }

    /** @return string[] The subset of $pending describing a structurally absent table/column/index. */
    private static function structural_absences(array $pending): array
    {
        return array_values(array_filter(
            $pending,
            static function ($description): bool {
                foreach (self::STRUCTURAL_ABSENCE_PREFIXES as $prefix) {
                    if (str_starts_with((string) $description, $prefix)) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    /** @return string[] */
    private static function record(string $table_name, string $message): array
    {
        $error = \sprintf('%s: %s', $table_name, $message);

        $errors   = get_option(DbInstaller::ERRORS_OPTION, []);
        $errors[] = $error;
        update_option(DbInstaller::ERRORS_OPTION, $errors);

        return [$error];
    }
}
