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

            public function get_charset_collate()
            {
                return 'DEFAULT CHARACTER SET utf8mb4';
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
