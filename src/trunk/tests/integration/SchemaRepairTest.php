<?php

use PayCryptoMe\WooCommerce\DbInstaller;

/**
 * M1 (docs/PLAN-SCHEMA-INSTALL-HARDENING.md): before Front A, a site whose recorded version was
 * current but whose table was missing (a restored migration, a merchant who manually dropped a
 * table uninstall.php deliberately kept) had NO self-repair path — activation itself short-circuited
 * on is_current() and created nothing. These tests pin the fix: activation always repairs
 * regardless of the recorded version, and admin_init repairs it too, throttled.
 */
class SchemaRepairTest extends SchemaTestCase
{
    public function test_activation_recreates_a_missing_table_regardless_of_the_recorded_version()
    {
        $prefix = $this->fresh_install();
        $this->drop_table($prefix, 'paycrypto_me_bitcoin_transactions_data');

        $this->assertMissing($prefix, 'paycrypto_me_bitcoin_transactions_data');

        $recreated = $this->with_prefix($prefix, static function (): bool {
            DbInstaller::activate();

            return DbInstaller::is_current();
        });

        $this->assertTrue($recreated);
        $this->assertPresent($prefix, 'paycrypto_me_bitcoin_transactions_data');
        $this->assertSame([], get_option(DbInstaller::ERRORS_OPTION, []));
        $this->assertSame(DbInstaller::DB_VERSION, get_option(DbInstaller::VERSION_OPTION));
    }

    public function test_admin_init_repairs_a_missing_table_when_the_health_transient_is_clear()
    {
        $prefix = $this->fresh_install();
        $this->drop_table($prefix, 'paycrypto_me_bitcoin_transactions_data');
        delete_transient(DbInstaller::HEALTH_TRANSIENT);

        $this->with_prefix($prefix, static function (): void {
            DbInstaller::maybe_upgrade();
        });

        $this->assertPresent($prefix, 'paycrypto_me_bitcoin_transactions_data');
    }

    public function test_admin_init_does_not_repair_while_the_health_transient_is_set()
    {
        $prefix = $this->fresh_install();
        $this->drop_table($prefix, 'paycrypto_me_bitcoin_transactions_data');
        set_transient(DbInstaller::HEALTH_TRANSIENT, 1, HOUR_IN_SECONDS);

        $this->with_prefix($prefix, static function (): void {
            DbInstaller::maybe_upgrade();
        });

        $this->assertMissing($prefix, 'paycrypto_me_bitcoin_transactions_data');
    }

    private function drop_table(string $prefix, string $table): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS `{$prefix}{$table}`");
    }

    private function assertMissing(string $prefix, string $table): void
    {
        global $wpdb;
        $this->assertNull(
            $wpdb->get_var("SHOW TABLES LIKE '{$prefix}{$table}'"),
            "Expected {$prefix}{$table} to be missing"
        );
    }

    private function assertPresent(string $prefix, string $table): void
    {
        global $wpdb;
        $this->assertSame(
            $prefix . $table,
            $wpdb->get_var("SHOW TABLES LIKE '{$prefix}{$table}'"),
            "Expected {$prefix}{$table} to exist"
        );
    }
}
