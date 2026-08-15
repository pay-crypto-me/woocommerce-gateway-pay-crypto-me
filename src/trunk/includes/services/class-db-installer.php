<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       DbInstaller
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Owns the lifecycle of the 4 custom tables: activation, version-gated upgrades, and the admin
 * notice for a failed install.
 *
 * Lives here rather than on WC_PayCryptoMe (which is defined in the plugin entry file, alongside
 * file-scope hook registration) so it is autoloadable and unit-testable on its own.
 */
class DbInstaller
{
    // Schema version for the 4 custom tables (independent of the plugin version) — bump this
    // whenever the dbDelta SQL in either *GatewayActivate class changes, so maybe_upgrade()
    // re-runs dbDelta for existing installs (WordPress doesn't re-fire
    // register_activation_hook on a plugin update, only on activate).
    public const DB_VERSION = '1';

    public const VERSION_OPTION = 'paycrypto_me_db_version';
    public const ERRORS_OPTION  = 'paycrypto_me_db_activation_errors';
    public const RETRY_TRANSIENT = 'paycrypto_me_db_upgrade_retry';

    /**
     * Creates/upgrades every custom table and records the schema version ONLY if all of them
     * succeeded.
     *
     * Recording the version unconditionally meant a failed CREATE (e.g. the InnoDB 767-byte
     * index-key limit on older MySQL/MariaDB) left the site claiming to be on the current schema
     * forever: the upgrade never ran again and the failure surfaced only as broken payments much
     * later. On failure the recorded version stays behind so the next attempt retries.
     *
     * @return bool Whether the schema is now at DB_VERSION.
     */
    public static function install(): bool
    {
        delete_option(self::ERRORS_OPTION);

        $errors = array_merge(
            PayCryptoMeBitcoinGatewayActivate::activate(),
            PayCryptoMeLightningGatewayActivate::activate()
        );

        if (!empty($errors)) {
            set_transient(self::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS);

            return false;
        }

        delete_transient(self::RETRY_TRANSIENT);
        update_option(self::VERSION_OPTION, self::DB_VERSION);

        return true;
    }

    /**
     * Re-runs the install when DB_VERSION changed since the last successful run — the only way a
     * schema change reaches a site that installed an earlier version.
     *
     * Throttled after a failure: install() deliberately leaves the recorded version behind so the
     * upgrade is retried, and without the transient that retry would re-run dbDelta on every
     * single request for as long as the host stays broken.
     */
    public static function maybe_upgrade(): void
    {
        if (get_option(self::VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        if (get_transient(self::RETRY_TRANSIENT)) {
            return;
        }

        self::install();
    }

    public static function render_activation_errors(): void
    {
        $errors = get_option(self::ERRORS_OPTION, []);

        if (empty($errors) || !current_user_can('manage_options')) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p><ul>',
            'PayCrypto.Me for WooCommerce: some database tables failed to install correctly. Payments may not work correctly until this is resolved — check with your host and try deactivating/reactivating the plugin.'
        );

        // Escaped per-item in the loop rather than mapped/imploded into the printf above:
        // static analysis can't trace escaping through array_map(), and this notice must stay
        // provably escaped since $errors carries raw $wpdb->last_error strings.
        foreach ($errors as $error) {
            printf('<li>%s</li>', esc_html($error));
        }

        echo '</ul></div>';

        // Deliberately NOT deleted here: the tables are still broken after the notice has been
        // rendered once. install() clears this buffer when it next runs, so the notice disappears
        // exactly when the problem is actually fixed — it used to vanish after a single page load
        // and never come back, while the schema stayed broken.
    }
}
