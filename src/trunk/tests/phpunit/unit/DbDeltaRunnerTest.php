<?php

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\DbDeltaRunner;
use PayCryptoMe\WooCommerce\DbInstaller;

/**
 * DbDeltaRunner is the fix for F5 (CLAUDE.md): $wpdb->last_error only reflects the LAST statement
 * dbDelta() executed, so a failing "ADD COLUMN" followed by a succeeding "ADD INDEX" leaves
 * last_error empty even though the column never landed. dbDelta($sql, false) — the read-only
 * dry-run — is the second, independent check: its own "Created table / Added column / Added
 * index" descriptions mean the structure is still genuinely absent (M3 in
 * docs/PLAN-SCHEMA-INSTALL-HARDENING.md).
 */
class DbDeltaRunnerTest extends TestCase
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

            public function prepare($query, ...$args)
            {
                return $args ? vsprintf($query, $args) : $query;
            }
        };

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/var/www/html/');
        }

        // Shared shims with ActivateDbDeltaTest/LightningActivateDbDeltaTest/DbInstallerTest —
        // whichever file's setUp() runs first in the process declares them (see T2 in the plan).
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
        $GLOBALS['__options'] = [];
        $GLOBALS['__dbdelta_captured'] = [];
        unset($GLOBALS['__dbdelta_dry_run_result']);
    }

    private function error_option_writes(): array
    {
        return array_values(array_filter(
            $GLOBALS['__update_option_calls'],
            fn(array $call): bool => $call[0] === DbInstaller::ERRORS_OPTION
        ));
    }

    public function test_no_error_when_the_dry_run_list_is_empty()
    {
        $GLOBALS['__dbdelta_dry_run_result'] = [];

        $errors = DbDeltaRunner::run('CREATE TABLE wp_x (id BIGINT(20))', 'wp_x');

        $this->assertSame([], $errors);
        $this->assertSame([], $this->error_option_writes());
    }

    public function test_records_an_error_when_the_dry_run_list_contains_added_column()
    {
        // The exact shape of M2: dbDelta ran, last_error is empty (checked in setUp: '' by
        // default), but the dry run says the column genuinely never landed.
        $GLOBALS['__dbdelta_dry_run_result'] = ['Added column wp_x.amount_expected'];

        $errors = DbDeltaRunner::run('CREATE TABLE wp_x (id BIGINT(20), amount_expected BIGINT(20))', 'wp_x');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('wp_x', $errors[0]);
        $this->assertStringContainsString('Added column wp_x.amount_expected', $errors[0]);
        $this->assertCount(1, $this->error_option_writes());
    }

    public function test_ignores_a_list_containing_only_changed_type_or_default()
    {
        // "Changed type of …" / "Changed default value of …" mean the column DOES exist but is
        // declared differently — normalisation noise across MySQL/MariaDB versions, not a real
        // failure. Treating it as fatal would risk blocking the version option forever on a
        // healthy site.
        $GLOBALS['__dbdelta_dry_run_result'] = [
            'Changed type of wp_x.foo from int(11) to bigint(20) unsigned',
            'Changed default value of wp_x.bar from NULL to 0',
        ];

        $errors = DbDeltaRunner::run('CREATE TABLE wp_x (id BIGINT(20))', 'wp_x');

        $this->assertSame([], $errors);
        $this->assertSame([], $this->error_option_writes());
    }

    public function test_last_error_still_short_circuits_with_todays_message_shape()
    {
        global $wpdb;
        $wpdb->last_error = 'Specified key was too long; max key length is 767 bytes';

        // If the dry-run check ran too, it would see nothing pending (no override set) and this
        // would incorrectly pass even without the last_error short-circuit — so also prove the
        // dry-run dbDelta() was never reached by leaving no override and asserting the error text
        // is the ORIGINAL last_error message, not a "still missing" one.
        $errors = DbDeltaRunner::run('CREATE TABLE wp_x (id BIGINT(20))', 'wp_x');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('too long', $errors[0]);
        $this->assertStringNotContainsString('still missing', $errors[0]);
    }
}
