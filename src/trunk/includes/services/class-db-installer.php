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

    // Throttles the "are the 4 tables actually there" probe added alongside the repair path
    // below — otherwise every admin_init hit would run a SHOW TABLES LIKE per table.
    public const HEALTH_TRANSIENT = 'paycrypto_me_db_health_check';
    private const HEALTH_CHECK_INTERVAL = 12 * HOUR_IN_SECONDS;

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
     * @param bool $force Skip the post-lock is_current() short-circuit and always run the
     *                     activators — needed by activate() so a site whose recorded version is
     *                     already current but whose tables are missing (a restored/rolled-back
     *                     site) still gets them recreated on the SAME activation.
     * @return bool Whether the schema is now at DB_VERSION.
     */
    public static function install(bool $force = false): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A live advisory lock cannot be cached; it serializes concurrent schema installation.
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
            // Another request may have already finished the upgrade while this one waited for the
            // lock (e.g. two admin_init hits racing right after an update). Recheck rather than
            // blindly rerunning dbDelta on all 4 tables and rewriting the version option again —
            // that redundant pass is wasted work at best, and at worst a transient DB hiccup on it
            // would raise the "tables failed to install" notice for a schema that already upgraded
            // cleanly. Skipped under $force: the caller already established the tables need
            // rebuilding regardless of the recorded version.
            if (!$force && self::is_current()) {
                return true;
            }

            return self::run_install();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releasing the live advisory lock must reach the database and cannot use a cached result.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::INSTALL_LOCK));
        }
    }

    /**
     * The register_activation_hook target. A zero-argument wrapper, not install() directly:
     * activation fires do_action("activate_{$plugin}", $network_wide) with a bool, and
     * install(bool $force) would silently receive it as $force — a single-site activation would
     * pass false (today's bug intact) and a network activation true, coupling the schema-force
     * behaviour to multisite by accident. See maybe_upgrade_after_update() for the same hazard on
     * the other hook.
     */
    public static function activate(): void
    {
        self::install(true);
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
     * schema change reaches a site that installed an earlier version — and, when the schema is
     * already current, throttled-probes that the 4 tables actually still exist.
     *
     * Throttled after a failure: install() deliberately leaves the recorded version behind so the
     * upgrade is retried, and without the transient that retry would re-run dbDelta on every
     * single request for as long as the host stays broken.
     */
    public static function maybe_upgrade(): void
    {
        if (get_transient(self::RETRY_TRANSIENT)) {
            return;
        }

        // version_compare, not a strict comparison: a RECORDED version newer than the code's is
        // also "different", and the site that has it is running an older plugin than the one that
        // wrote it (a manual downgrade, a rolled-back update). Re-running the activators there
        // would rewrite the option backwards, so the real upgrade would then be skipped when the
        // newer plugin came back. Forward-only, like the schema itself.
        if (!self::is_current()) {
            self::install();

            return;
        }

        self::verify_tables_present();
    }

    /**
     * A recorded version that is current says nothing about whether the tables are actually
     * there — a restored site migration, or a merchant who manually dropped the tables
     * uninstall.php deliberately kept, both leave that option set with nothing behind it. Without
     * this, activation is the ONLY self-repair path, and the admin notice's own advice
     * ("try deactivating/reactivating the plugin") has nothing else to point at.
     */
    private static function verify_tables_present(): void
    {
        if (get_transient(self::HEALTH_TRANSIENT)) {
            return;
        }

        // Set before the work, not after: an uncaught fatal below must not turn this into a
        // per-request probe for as long as the host stays broken. A repair attempt that returns
        // normally but FAILS un-sets it again (below) — otherwise this 12h window would outlive
        // install()'s own 1h RETRY_TRANSIENT and silence the next automatic repair attempt for up
        // to ~11 hours longer than the rest of the code's retry cadence implies.
        set_transient(self::HEALTH_TRANSIENT, 1, self::HEALTH_CHECK_INTERVAL);

        if (self::missing_tables() === []) {
            return;
        }

        if (!self::install(true)) {
            delete_transient(self::HEALTH_TRANSIENT);
        }
    }

    /** @return string[] Full, prefixed names of the declared tables that do not currently exist. */
    private static function missing_tables(): array
    {
        global $wpdb;

        $missing = [];

        foreach (self::tables() as $table) {
            $full_name = $wpdb->prefix . $table;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema health must observe the current table state; caching could hide a missing or newly restored table.
            $found = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($full_name))
            );

            if ($found !== $full_name) {
                $missing[] = $full_name;
            }
        }

        return $missing;
    }

    /** @return string[] Bare (unprefixed) names of all 4 custom tables — the one source for them. */
    public static function tables(): array
    {
        return array_merge(
            PayCryptoMeBitcoinGatewayActivate::TABLES,
            PayCryptoMeLightningGatewayActivate::TABLES
        );
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
     * upgrader_process_complete fires after ANY plugin/theme/core update, not just this plugin's —
     * cheap to no-op via is_current() for every update but this one. It hands its callbacks
     * ($upgrader, $hook_extra); maybe_upgrade() takes none. Bound directly, the day someone gives
     * maybe_upgrade() a parameter WordPress would start filling it with an unrelated object. This
     * wrapper is where those arguments stop.
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
