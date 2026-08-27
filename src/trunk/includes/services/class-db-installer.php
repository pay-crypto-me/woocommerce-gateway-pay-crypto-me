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

    // MySQL advisory lock serializing install(): two admin requests arriving on an out-of-date
    // site would otherwise run dbDelta's ALTERs against the same tables concurrently. Same
    // mechanism as PayCryptoMeDBStatementsService::reserve_derivation_index_for_wallet().
    public const INSTALL_LOCK = 'paycrypto_me_db_install';
    private const INSTALL_LOCK_TIMEOUT = 10;

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
        global $wpdb;

        $got_lock = $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::INSTALL_LOCK, self::INSTALL_LOCK_TIMEOUT)
        );

        // Not a failure: another request holds the lock and is installing the same schema. Return
        // false without recording anything — writing to ERRORS_OPTION here would raise the admin
        // notice for a situation that resolves itself, and the recorded version deliberately stays
        // behind so the next admin request re-checks.
        if ((int) $got_lock !== 1) {
            return false;
        }

        try {
            return self::run_install();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::INSTALL_LOCK));
        }
    }

    private static function run_install(): bool
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
        // version_compare, not a strict comparison: a RECORDED version newer than the code's is
        // also "different", and the site that has it is running an older plugin than the one that
        // wrote it (a manual downgrade, a rolled-back update). Re-running the activators there
        // would rewrite the option backwards, so the real upgrade would then be skipped when the
        // newer plugin came back. Forward-only, like the schema itself.
        if (self::is_current()) {
            return;
        }

        if (get_transient(self::RETRY_TRANSIENT)) {
            return;
        }

        self::install();
    }

    /**
     * Whether the recorded schema is at least what this code expects.
     *
     * Public because maybe_upgrade() no longer runs on every front-end request (it is hooked on
     * admin_init/upgrader_process_complete), so between a plugin update and the next admin page
     * load a site can legitimately be running new code over an old schema. Any payment path that
     * needs a column or table introduced by a newer DB_VERSION must consult this and degrade
     * rather than assume.
     */
    public static function is_current(): bool
    {
        return version_compare((string) get_option(self::VERSION_OPTION, '0'), self::DB_VERSION, '>=');
    }

    /**
     * upgrader_process_complete hands its callbacks ($upgrader, $hook_extra); maybe_upgrade() takes
     * none. Bound directly, the day someone gives maybe_upgrade() a parameter WordPress would start
     * filling it with an unrelated object. This wrapper is where those arguments stop.
     */
    public static function maybe_upgrade_after_update(): void
    {
        self::maybe_upgrade();
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
