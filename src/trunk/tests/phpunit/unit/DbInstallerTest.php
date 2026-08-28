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

            public function get_charset_collate()
            {
                return 'DEFAULT CHARACTER SET utf8mb4';
            }

            public function prepare($query, ...$args)
            {
                return $args ? vsprintf($query, $args) : $query;
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
            function dbDelta($queries) {
                $GLOBALS['__dbdelta_captured'][] = is_array($queries) ? implode("\n", $queries) : (string) $queries;
                return true;
            }
        }

        $GLOBALS['__update_option_calls'] = [];
        $GLOBALS['__delete_option_calls'] = [];
        $GLOBALS['__dbdelta_captured'] = [];
        $GLOBALS['__transients'] = [];
        $GLOBALS['__options'] = [];
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
}
