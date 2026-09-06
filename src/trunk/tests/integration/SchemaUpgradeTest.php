<?php

use PayCryptoMe\WooCommerce\DbInstaller;

/**
 * What the unit suite structurally cannot check (F7): whether dbDelta() actually did what the
 * CREATE TABLE said.
 *
 * The headline test here is the convergence one. It is the test that would have caught the
 * nullability design before it shipped: dbDelta() does not apply NOT NULL -> NULL, silently and
 * with an empty $wpdb->last_error, so an upgraded site would have kept the old columns forever
 * while a fresh install got the new ones.
 */
class SchemaUpgradeTest extends SchemaTestCase
{
    public function test_upgrade_removes_legacy_onchain_columns_and_is_idempotent()
    {
        $prefix = $this->reserve_prefix();
        $this->install_frozen_schema(dirname(__DIR__) . '/schema/v1.sql', $prefix);
        update_option(DbInstaller::VERSION_OPTION, '1');

        $this->assertTrue($this->with_prefix($prefix, fn(): bool => DbInstaller::install()));
        $this->assertSame([], $this->legacy_onchain_columns($prefix));

        $this->assertTrue($this->with_prefix($prefix, fn(): bool => DbInstaller::install()));
        $this->assertSame([], $this->legacy_onchain_columns($prefix));
    }

    /** @return string[] */
    private function legacy_onchain_columns(string $prefix): array
    {
        global $wpdb;

        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$prefix}paycrypto_me_bitcoin_transactions_data`", 0);

        return array_values(array_intersect(
            $columns,
            ['num_confirmations', 'amount_received', 'tx_hash']
        ));
    }

    private function snapshot_version(string $snapshot): string
    {
        preg_match('/v([^\.]+)\.sql$/', $snapshot, $matches);

        return $matches[1] ?? '0';
    }

    public function test_upgrade_from_each_frozen_version_converges_to_a_fresh_install()
    {
        $snapshots = $this->frozen_snapshots();

        $this->assertNotEmpty(
            $snapshots,
            'tests/schema/ is empty. Every DB_VERSION must have a frozen snapshot — see tests/bin/dump-schema.php.'
        );

        $fresh_prefix = $this->fresh_install();
        $fresh = $this->schema_fingerprints($fresh_prefix);

        // Front B1 gate, permanent: right after a fresh install, dbDelta's own dry run must agree
        // nothing is pending for our schema — the assumption DbDeltaRunner's post-condition check
        // is built on. If this ever fails on a different engine (MariaDB, a newer MySQL), Front B
        // must fall back to parsing CREATE TABLE structurally instead of widening this filter.
        $this->assert_nothing_pending($fresh_prefix);

        foreach ($snapshots as $snapshot) {
            $upgraded_prefix = $this->reserve_prefix();

            $this->install_frozen_schema($snapshot, $upgraded_prefix);
            update_option(DbInstaller::VERSION_OPTION, $this->snapshot_version($snapshot));

            $installed = $this->with_prefix($upgraded_prefix, fn(): bool => DbInstaller::install());

            $this->assertTrue($installed, basename($snapshot) . ': the upgrade reported failure');

            foreach (self::tables() as $table) {
                $this->assertSame(
                    $fresh[$table],
                    $this->schema_fingerprint($upgraded_prefix . $table),
                    basename($snapshot) . ": {$table} after upgrading does not match a fresh install. "
                        . 'dbDelta silently ignores some declarations (nullability changes, a second '
                        . 'column declared on the same line, any removal) — this is what that looks like.'
                );
            }

            $this->assert_nothing_pending($upgraded_prefix, basename($snapshot) . ': ');
        }
    }

    /**
     * Front B1 gate, made permanent: re-runs BOTH activators directly (not DbInstaller::install(),
     * which would also touch the lock/version bookkeeping) under $prefix and asserts they report no
     * error. Each activator's dbDelta($sql, false) dry run (via DbDeltaRunner) is exactly the check
     * that would catch a structurally-absent table/column/index that $wpdb->last_error alone missed
     * (F5, M2) — re-running against a schema that should already be complete is what proves it
     * agrees with "nothing pending" for our own 4 tables, on THIS engine. Deliberately re-running
     * the real activators rather than duplicating their CREATE TABLE strings into this test.
     */
    private function assert_nothing_pending(string $prefix, string $message_prefix = ''): void
    {
        $errors = $this->with_prefix($prefix, static fn(): array => array_merge(
            \PayCryptoMe\WooCommerce\PayCryptoMeBitcoinGatewayActivate::activate(),
            \PayCryptoMe\WooCommerce\PayCryptoMeLightningGatewayActivate::activate()
        ));

        $this->assertSame([], $errors, $message_prefix . 'dbDelta reports the schema is still incomplete: ' . implode('; ', $errors));
    }

    public function test_install_is_idempotent()
    {
        $prefix = $this->fresh_install();

        $before = $this->schema_fingerprints($prefix);

        $again = $this->with_prefix($prefix, fn(): bool => DbInstaller::install());

        $this->assertTrue($again);
        $this->assertSame([], get_option(DbInstaller::ERRORS_OPTION, []), 'A second install must record no errors');
        $this->assertSame($before, $this->schema_fingerprints($prefix), 'A second install must not change the schema');
    }

    public function test_version_is_not_recorded_when_a_table_fails()
    {
        global $wpdb;

        $prefix = $this->reserve_prefix();
        $table  = $prefix . 'paycrypto_me_bitcoin_wallet_xpubkeys';

        // A real failure, not a mocked one: the table exists without its UNIQUE KEY and already
        // holds two rows that violate it, so dbDelta's ALTER ... ADD UNIQUE KEY fails with
        // "Duplicate entry". This is the shape of failure that used to be recorded as success.
        $wpdb->query(
            "CREATE TABLE `{$table}` (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                xpub VARCHAR(191) NOT NULL,
                network VARCHAR(50) NOT NULL,
                PRIMARY KEY (id)
            ) " . $wpdb->get_charset_collate()
        );
        $this->assertEmpty($wpdb->last_error, "Test setup failed: {$wpdb->last_error}");

        $wpdb->query("INSERT INTO `{$table}` (xpub, network) VALUES ('zpubduplicate', 'mainnet'), ('zpubduplicate', 'mainnet')");
        $this->assertEmpty($wpdb->last_error, "Test setup failed: {$wpdb->last_error}");

        $installed = $this->with_prefix($prefix, fn(): bool => DbInstaller::install());

        $this->assertFalse($installed);
        $this->assertFalse(
            get_option(DbInstaller::VERSION_OPTION, false),
            'A site whose tables are broken must not claim to be on the current schema — it would never retry'
        );
        $this->assertNotEmpty(get_option(DbInstaller::ERRORS_OPTION, []), 'The failure must reach the admin notice');
        $this->assertSame(1, get_transient(DbInstaller::RETRY_TRANSIENT), 'The retry must be throttled');
    }

    public function test_version_is_never_downgraded()
    {
        $prefix = $this->reserve_prefix();

        update_option(DbInstaller::VERSION_OPTION, '9');

        $this->with_prefix($prefix, static function (): void {
            DbInstaller::maybe_upgrade();
        });

        $this->assertSame('9', get_option(DbInstaller::VERSION_OPTION));

        global $wpdb;
        $this->assertSame(
            [],
            (array) $wpdb->get_col("SHOW TABLES LIKE '{$prefix}%'"),
            'Nothing may be installed when the recorded schema is newer than this code'
        );
    }

    public function test_fresh_install_records_the_current_version()
    {
        $prefix = $this->fresh_install();

        $this->assertSame(DbInstaller::DB_VERSION, get_option(DbInstaller::VERSION_OPTION));
        $this->assertTrue(DbInstaller::is_current());
        $this->assertSame([], get_option(DbInstaller::ERRORS_OPTION, []));
        $this->assertFalse(get_transient(DbInstaller::RETRY_TRANSIENT));

        global $wpdb;

        foreach (self::tables() as $table) {
            $this->assertSame(
                $prefix . $table,
                $wpdb->get_var("SHOW TABLES LIKE '{$prefix}{$table}'")
            );
        }
    }
}
