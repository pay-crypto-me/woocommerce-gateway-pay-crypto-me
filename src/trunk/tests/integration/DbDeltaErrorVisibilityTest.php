<?php

use PayCryptoMe\WooCommerce\DbDeltaRunner;
use PayCryptoMe\WooCommerce\DbInstaller;

/**
 * M2 (docs/PLAN-SCHEMA-INSTALL-HARDENING.md): $wpdb->last_error only reflects the LAST statement
 * dbDelta() executed — wpdb::query() calls flush() (clearing last_error) on every query, and
 * dbDelta() runs every statement it built in one loop, column ALTERs before index ALTERs. A failing
 * "ADD COLUMN" followed by a succeeding "ADD INDEX" therefore leaves last_error empty even though
 * the column never landed. This is what CLAUDE.md's F5 records.
 */
class DbDeltaErrorVisibilityTest extends SchemaTestCase
{
    /** @var string[] "masktest" is not one of the 4 real tables, so SchemaTestCase's own cleanup (keyed to self::tables()) never drops it — tracked and dropped here instead. */
    private array $masktest_tables = [];

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->masktest_tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->masktest_tables = [];

        parent::tearDown();
    }

    /**
     * Canary, pins WordPress/MySQL behaviour rather than our own code: if this ever starts
     * failing, WordPress or MySQL changed how dbDelta reports (or orders) its statements, and
     * DbDeltaRunner's mitigation (a post-condition dry run) can potentially be simplified — this
     * test is what would tell the next person that.
     */
    public function test_a_failing_column_followed_by_a_succeeding_index_leaves_last_error_empty()
    {
        global $wpdb;

        $prefix = $this->reserve_prefix();
        $table  = $prefix . 'masktest';
        $this->masktest_tables[] = $table;
        $charset_collate = $wpdb->get_charset_collate();

        $wpdb->query(
            "CREATE TABLE `{$table}` (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_address VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            ) {$charset_collate}"
        );
        $this->assertEmpty($wpdb->last_error, "Test setup failed: {$wpdb->last_error}");

        $sql = $this->masked_failure_sql($table, $charset_collate);

        dbDelta($sql);

        $this->assertEmpty($wpdb->last_error, 'Canary: dbDelta must leave last_error empty even though the column ALTER failed');

        $columns = array_column((array) $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A), 'Field');
        $this->assertNotContains('amount_expected', $columns, 'Canary: the invalid-default column must NOT have been added');

        $indexes = array_column((array) $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A), 'Key_name');
        $this->assertContains('payment_address', $indexes, 'Canary: the index ALTER after the failing column must still have succeeded');
    }

    public function test_db_delta_runner_reports_the_masked_failure()
    {
        global $wpdb;

        $prefix = $this->reserve_prefix();
        $table  = $prefix . 'masktest';
        $this->masktest_tables[] = $table;
        $charset_collate = $wpdb->get_charset_collate();

        $wpdb->query(
            "CREATE TABLE `{$table}` (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_address VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            ) {$charset_collate}"
        );
        $this->assertEmpty($wpdb->last_error, "Test setup failed: {$wpdb->last_error}");

        $sql = $this->masked_failure_sql($table, $charset_collate);

        $errors = DbDeltaRunner::run($sql, $table);

        $this->assertNotEmpty($errors, 'DbDeltaRunner must catch what last_error alone missed');
        $this->assertStringContainsString('amount_expected', $errors[0]);
        $this->assertStringContainsString(
            $table,
            implode(' ', get_option(DbInstaller::ERRORS_OPTION, [])),
            'The error must also land in the option the admin notice reads'
        );
    }

    private function masked_failure_sql(string $table, string $charset_collate): string
    {
        return "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_address VARCHAR(255) NOT NULL,
            amount_expected BIGINT(20) UNSIGNED NOT NULL DEFAULT 'not-a-number',
            PRIMARY KEY (id),
            KEY payment_address (payment_address)
        ) {$charset_collate};";
    }
}
