<?php

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\DbInstaller;

/**
 * DbInstaller::install() must only record paycrypto_me_db_version when every dbDelta call succeeded.
 *
 * Regression: the version used to be written unconditionally, so a failed CREATE (e.g. the
 * InnoDB 767-byte index-key limit on older MySQL) left the site permanently claiming to be on
 * the current schema — maybe_upgrade() never retried, and the only symptom was payments
 * failing later with no explanation.
 */
class DbInstallerTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;

        $wpdb = new class {
            public $prefix = 'wp_';
            public $last_error = '';

            // install() serializes itself on a MySQL advisory lock; '1' is "acquired".
            public $get_lock_result = '1';
            public array $lock_calls = [];

            // Health-check double: which full (prefixed) table names "exist" for SHOW TABLES LIKE.
            // Defaults to all 4 present — tests that want a missing table unset() one.
            public array $existing_tables = [
                'wp_paycrypto_me_bitcoin_wallet_xpubkeys' => true,
                'wp_paycrypto_me_bitcoin_derivation_indexes' => true,
                'wp_paycrypto_me_bitcoin_transactions_data' => true,
                'wp_paycrypto_me_lightning_invoices' => true,
            ];
            public array $show_tables_queries = [];

            public function get_charset_collate()
            {
                return 'DEFAULT CHARACTER SET utf8mb4';
            }

            public function prepare($query, ...$args)
            {
                return $args ? vsprintf($query, $args) : $query;
            }

            public function esc_like($text)
            {
                return $text;
            }

            public function get_var($query)
            {
                if (stripos($query, 'RELEASE_LOCK') !== false) {
                    $this->lock_calls[] = 'release';

                    return '1';
                }

                if (stripos($query, 'GET_LOCK') !== false) {
                    $this->lock_calls[] = 'get';

                    return $this->get_lock_result;
                }

                if (stripos($query, 'SHOW TABLES LIKE') !== false) {
                    $table = trim((string) str_ireplace('SHOW TABLES LIKE', '', $query));
                    $this->show_tables_queries[] = $table;

                    return isset($this->existing_tables[$table]) ? $table : null;
                }

                return null;
            }
        };

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/var/www/html/');
        }

        // Shared shims with ActivateDbDeltaTest: whichever file's setUp() runs first declares them.
        if (!function_exists('update_option')) {
            function update_option($key, $value) {
                $GLOBALS['__update_option_calls'][] = [$key, $value];
                return true;
            }
        }
        if (!function_exists('dbDelta')) {
            function dbDelta($queries, $execute = true) {
                if (!$execute) {
                    return $GLOBALS['__dbdelta_dry_run_result'] ?? [];
                }
                $GLOBALS['__dbdelta_captured'][] = is_array($queries) ? implode("\n", $queries) : (string) $queries;
                return true;
            }
        }

        $GLOBALS['__update_option_calls'] = [];
        $GLOBALS['__delete_option_calls'] = [];
        $GLOBALS['__dbdelta_captured'] = [];
        $GLOBALS['__transients'] = [];
        $GLOBALS['__options'] = [];
        unset($GLOBALS['__dbdelta_dry_run_result']);
    }

    private function recorded_version_writes(): array
    {
        return array_values(array_filter(
            $GLOBALS['__update_option_calls'],
            fn(array $call): bool => $call[0] === 'paycrypto_me_db_version'
        ));
    }

    public function test_records_the_schema_version_when_every_table_succeeds()
    {
        $result = DbInstaller::install();

        $this->assertTrue($result);
        $this->assertCount(1, $this->recorded_version_writes());
        $this->assertSame(
            DbInstaller::DB_VERSION,
            $this->recorded_version_writes()[0][1]
        );
        $this->assertFalse(get_transient('paycrypto_me_db_upgrade_retry'));
    }

    public function test_does_not_record_the_schema_version_when_dbdelta_fails()
    {
        global $wpdb;
        $wpdb->last_error = 'Specified key was too long; max key length is 767 bytes';

        $result = DbInstaller::install();

        $this->assertFalse($result);
        $this->assertSame([], $this->recorded_version_writes(), 'A failed schema install must not claim to be on the current version');
    }

    public function test_throttles_the_retry_after_a_failure()
    {
        global $wpdb;
        $wpdb->last_error = 'Specified key was too long; max key length is 767 bytes';

        DbInstaller::install();

        // Without the transient, maybe_upgrade() would re-run dbDelta on all 4 tables on
        // every single request for as long as the failure lasts.
        $this->assertSame(1, get_transient('paycrypto_me_db_upgrade_retry'));
    }

    public function test_clears_the_previous_error_buffer_before_running()
    {
        DbInstaller::install();

        // The admin notice reads this option and no longer deletes it after rendering, so the
        // only thing that may clear it is a fresh install attempt.
        $this->assertContains('paycrypto_me_db_activation_errors', $GLOBALS['__delete_option_calls']);
    }

    public function test_maybe_upgrade_does_nothing_when_the_recorded_version_is_newer()
    {
        // The site ran a newer plugin at some point and was rolled back. A strict !== treated that
        // as "needs upgrading" and rewrote the option backwards, which then made the real upgrade
        // a no-op when the newer plugin returned.
        $GLOBALS['__options']['paycrypto_me_db_version'] = '9';

        DbInstaller::maybe_upgrade();

        $this->assertSame([], $GLOBALS['__dbdelta_captured'], 'No activator may run against a newer recorded schema');
        $this->assertSame([], $this->recorded_version_writes(), 'The recorded version must never be downgraded');
    }

    public function test_maybe_upgrade_runs_when_the_recorded_version_is_older()
    {
        $GLOBALS['__options']['paycrypto_me_db_version'] = '0';

        DbInstaller::maybe_upgrade();

        $this->assertNotEmpty($GLOBALS['__dbdelta_captured']);
        $this->assertCount(1, $this->recorded_version_writes());
        $this->assertSame(DbInstaller::DB_VERSION, $this->recorded_version_writes()[0][1]);
    }

    public function test_is_current_reflects_the_recorded_version()
    {
        $GLOBALS['__options']['paycrypto_me_db_version'] = '0';
        $this->assertFalse(DbInstaller::is_current());

        $GLOBALS['__options']['paycrypto_me_db_version'] = DbInstaller::DB_VERSION;
        $this->assertTrue(DbInstaller::is_current());

        $GLOBALS['__options']['paycrypto_me_db_version'] = '9';
        $this->assertTrue(DbInstaller::is_current(), 'A newer recorded schema still satisfies this code');

        unset($GLOBALS['__options']['paycrypto_me_db_version']);
        $this->assertFalse(DbInstaller::is_current(), 'An install that never recorded a version is not current');
    }

    public function test_install_gives_up_quietly_when_the_lock_is_held()
    {
        global $wpdb;
        $wpdb->get_lock_result = '0';

        $result = DbInstaller::install();

        $this->assertFalse($result);
        $this->assertSame([], $GLOBALS['__dbdelta_captured'], 'The other request is already running dbDelta');
        $this->assertSame([], $this->recorded_version_writes());

        // Losing the race is not a failure: recording it would raise the "tables failed to
        // install" admin notice for a situation that resolves itself on the next request.
        $error_writes = array_filter(
            $GLOBALS['__update_option_calls'],
            fn(array $call): bool => $call[0] === 'paycrypto_me_db_activation_errors'
        );
        $this->assertSame([], $error_writes);
        $this->assertFalse(get_transient('paycrypto_me_db_upgrade_retry'));
    }

    public function test_install_releases_the_lock_even_when_a_table_fails()
    {
        global $wpdb;
        $wpdb->last_error = 'Table storage engine failed';

        DbInstaller::install();

        // RELEASE_LOCK runs from a finally: a failed install must not leave the lock held for the
        // rest of the connection's life, blocking every retry.
        $this->assertSame(['get', 'release'], $wpdb->lock_calls);
    }

    public function test_install_rechecks_is_current_after_acquiring_the_lock()
    {
        // Simulate the loser-of-the-race-that-then-wins-the-lock case: another request already
        // finished the upgrade and recorded DB_VERSION while this one was waiting on GET_LOCK.
        $GLOBALS['__options']['paycrypto_me_db_version'] = DbInstaller::DB_VERSION;

        $result = DbInstaller::install();

        $this->assertTrue($result);
        $this->assertSame([], $GLOBALS['__dbdelta_captured'], 'Must not rerun dbDelta once another request already brought the schema current');
        $this->assertSame([], $this->recorded_version_writes(), 'Must not rewrite the version option a second time for no reason');
    }

    public function test_records_every_failing_table_in_the_error_option()
    {
        global $wpdb;
        $wpdb->last_error = 'Table storage engine failed';

        DbInstaller::install();

        $error_writes = array_filter(
            $GLOBALS['__update_option_calls'],
            fn(array $call): bool => $call[0] === 'paycrypto_me_db_activation_errors'
        );

        // 3 on-chain tables + 1 Lightning table.
        $this->assertCount(4, $error_writes);
    }

    public function test_install_force_reruns_dbdelta_even_when_the_recorded_version_is_current()
    {
        // The sibling to test_install_rechecks_is_current_after_acquiring_the_lock: that test pins
        // install()'s (no force) short-circuit, this one pins that install(true)/activate() must
        // NOT take it — a site whose version option is current but whose tables are missing (a
        // restored migration, a manual DROP TABLE) has nothing else that will recreate them.
        $GLOBALS['__options']['paycrypto_me_db_version'] = DbInstaller::DB_VERSION;

        $result = DbInstaller::install(true);

        $this->assertTrue($result);
        $this->assertNotEmpty($GLOBALS['__dbdelta_captured'], 'install(true) must run the activators regardless of the recorded version');
    }

    public function test_activate_runs_the_activators_even_when_the_recorded_version_is_current()
    {
        $GLOBALS['__options']['paycrypto_me_db_version'] = DbInstaller::DB_VERSION;

        DbInstaller::activate();

        $this->assertNotEmpty($GLOBALS['__dbdelta_captured']);
    }

    public function test_activate_takes_zero_parameters()
    {
        // Regression guard for T1: register_activation_hook fires
        // do_action("activate_{$plugin}", $network_wide) with a bool. If activate() ever grows a
        // parameter, WordPress feeds it $network_wide silently — exactly the hazard install(bool
        // $force) itself would have had as the direct activation target.
        $method = new \ReflectionMethod(DbInstaller::class, 'activate');

        $this->assertSame(0, $method->getNumberOfParameters());
    }

    public function test_maybe_upgrade_force_installs_when_a_declared_table_is_missing()
    {
        $GLOBALS['__options']['paycrypto_me_db_version'] = DbInstaller::DB_VERSION;

        global $wpdb;
        unset($wpdb->existing_tables['wp_paycrypto_me_bitcoin_transactions_data']);

        DbInstaller::maybe_upgrade();

        $this->assertNotEmpty($GLOBALS['__dbdelta_captured'], 'A missing table must trigger a repair install even though the version is current');
        $this->assertSame(1, get_transient(DbInstaller::HEALTH_TRANSIENT), 'A successful repair keeps the 12h health window — nothing left to redo');
    }

    public function test_maybe_upgrade_clears_the_health_transient_when_the_repair_attempt_fails()
    {
        // Code-review finding: HEALTH_TRANSIENT used to be set unconditionally for the full 12h
        // BEFORE the repair attempt, and stayed set even when install(true) genuinely failed —
        // silencing the next automatic repair attempt for up to ~11h longer than the 1h
        // RETRY_TRANSIENT cadence the rest of this class already uses for a failed upgrade.
        $GLOBALS['__options']['paycrypto_me_db_version'] = DbInstaller::DB_VERSION;

        global $wpdb;
        unset($wpdb->existing_tables['wp_paycrypto_me_bitcoin_transactions_data']);
        $wpdb->last_error = 'Table storage engine failed';

        DbInstaller::maybe_upgrade();

        $this->assertFalse(get_transient(DbInstaller::HEALTH_TRANSIENT), 'A failed repair must not block the next attempt for the full 12h window');
        $this->assertSame(1, get_transient(DbInstaller::RETRY_TRANSIENT), 'The faster 1h retry cadence governs instead');
    }

    public function test_maybe_upgrade_does_nothing_and_sets_the_health_transient_when_all_tables_are_present()
    {
        $GLOBALS['__options']['paycrypto_me_db_version'] = DbInstaller::DB_VERSION;

        DbInstaller::maybe_upgrade();

        $this->assertSame([], $GLOBALS['__dbdelta_captured'], 'Nothing to repair when every table is present');
        $this->assertSame(1, get_transient(DbInstaller::HEALTH_TRANSIENT));
    }

    public function test_maybe_upgrade_skips_the_probe_entirely_while_the_health_transient_is_set()
    {
        $GLOBALS['__options']['paycrypto_me_db_version'] = DbInstaller::DB_VERSION;
        $GLOBALS['__transients'][DbInstaller::HEALTH_TRANSIENT] = 1;

        global $wpdb;

        DbInstaller::maybe_upgrade();

        $this->assertSame([], $wpdb->show_tables_queries, 'No SHOW TABLES LIKE probe may run while the health transient is set');
        $this->assertSame([], $GLOBALS['__dbdelta_captured']);
    }

    public function test_maybe_upgrade_returns_early_while_the_retry_transient_is_set_before_any_probe_or_install()
    {
        $GLOBALS['__options']['paycrypto_me_db_version'] = '0';
        $GLOBALS['__transients'][DbInstaller::RETRY_TRANSIENT] = 1;

        global $wpdb;

        DbInstaller::maybe_upgrade();

        $this->assertSame([], $GLOBALS['__dbdelta_captured'], 'The retry throttle must short-circuit before even checking is_current()');
        $this->assertSame([], $wpdb->show_tables_queries);
    }

    public function test_tables_returns_exactly_the_four_bare_names()
    {
        $tables = DbInstaller::tables();

        $this->assertCount(4, $tables);
        $this->assertSame($tables, array_unique($tables), 'The 4 table names must be disjoint across both activators');
        $this->assertContains('paycrypto_me_bitcoin_wallet_xpubkeys', $tables);
        $this->assertContains('paycrypto_me_bitcoin_derivation_indexes', $tables);
        $this->assertContains('paycrypto_me_bitcoin_transactions_data', $tables);
        $this->assertContains('paycrypto_me_lightning_invoices', $tables);
    }
}
