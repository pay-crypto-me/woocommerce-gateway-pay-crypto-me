<?php

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\DbInstaller;

/**
 * Base for the schema integration tests: isolation, cleanup, and an order-insensitive way to
 * compare two schemas.
 *
 * Isolation is by table-name prefix rather than by database. Every activator derives its table
 * names from $wpdb->prefix, so swapping that gives each test its own namespace inside the dev
 * database — no second database, no credentials juggling, and the dev site's own tables are never
 * touched. Only $wpdb->prefix is swapped, never $wpdb->set_prefix(): the latter rebuilds WordPress
 * core's table names too, and get_option() would stop working mid-test.
 */
abstract class SchemaTestCase extends TestCase
{
    protected const TABLES = [
        'paycrypto_me_bitcoin_wallet_xpubkeys',
        'paycrypto_me_bitcoin_derivation_indexes',
        'paycrypto_me_bitcoin_transactions_data',
        'paycrypto_me_lightning_invoices',
    ];

    /** Unique across the whole run, so a leaked table from one test cannot be seen by another. */
    private static int $namespace_counter = 0;

    private string $original_prefix;
    private $original_version;
    private $original_errors;
    private $original_retry;

    /** @var string[] Every prefix this test created tables under. */
    private array $created_prefixes = [];

    protected function setUp(): void
    {
        global $wpdb;

        $this->original_prefix  = $wpdb->prefix;
        $this->original_version = get_option(DbInstaller::VERSION_OPTION, null);
        $this->original_errors  = get_option(DbInstaller::ERRORS_OPTION, null);
        $this->original_retry   = get_transient(DbInstaller::RETRY_TRANSIENT);

        // The installer refuses to run while this is set; a previous failing test must not decide
        // whether this one gets to install anything.
        delete_transient(DbInstaller::RETRY_TRANSIENT);
        delete_option(DbInstaller::VERSION_OPTION);
        delete_option(DbInstaller::ERRORS_OPTION);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        $wpdb->prefix = $this->original_prefix;

        foreach ($this->created_prefixes as $prefix) {
            foreach (self::TABLES as $table) {
                $wpdb->query("DROP TABLE IF EXISTS `{$prefix}{$table}`");
            }
        }

        $this->created_prefixes = [];

        $this->restore_option(DbInstaller::VERSION_OPTION, $this->original_version);
        $this->restore_option(DbInstaller::ERRORS_OPTION, $this->original_errors);

        if ($this->original_retry === false) {
            delete_transient(DbInstaller::RETRY_TRANSIENT);
        } else {
            set_transient(DbInstaller::RETRY_TRANSIENT, $this->original_retry, HOUR_IN_SECONDS);
        }
    }

    private function restore_option(string $key, $value): void
    {
        if ($value === null) {
            delete_option($key);

            return;
        }

        update_option($key, $value);
    }

    /**
     * Reserves a fresh table namespace. Registered for cleanup here rather than at creation time,
     * so a test that fails halfway through still drops what it managed to create.
     */
    protected function reserve_prefix(): string
    {
        self::$namespace_counter++;

        $prefix = 'pcmit' . self::$namespace_counter . '_';
        $this->created_prefixes[] = $prefix;

        return $prefix;
    }

    /**
     * Runs $callback with $wpdb->prefix pointed at $prefix, restoring it even if the callback
     * throws — a leaked prefix would send the next test's writes into the wrong tables.
     */
    protected function with_prefix(string $prefix, callable $callback)
    {
        global $wpdb;

        $previous = $wpdb->prefix;
        $wpdb->prefix = $prefix;

        try {
            return $callback();
        } finally {
            $wpdb->prefix = $previous;
        }
    }

    /** Installs the current schema into a brand new namespace and returns its prefix. */
    protected function fresh_install(): string
    {
        $prefix = $this->reserve_prefix();

        $installed = $this->with_prefix($prefix, fn(): bool => DbInstaller::install());

        $this->assertTrue($installed, "A fresh install into {$prefix} was expected to succeed");

        return $prefix;
    }

    /**
     * Creates the tables exactly as a given released version had them, from tests/schema/v<N>.sql.
     */
    protected function install_frozen_schema(string $snapshot_file, string $prefix): void
    {
        global $wpdb;

        $sql = file_get_contents($snapshot_file);
        $this->assertNotFalse($sql, "Could not read {$snapshot_file}");

        // Comments are stripped line-first, before splitting: the header block sits immediately
        // above the first CREATE TABLE, so a per-statement "starts with --" check would silently
        // discard that whole statement along with it.
        $body = implode("\n", array_filter(
            explode("\n", str_replace('{PREFIX}', $prefix, $sql)),
            static fn(string $line): bool => !str_starts_with(ltrim($line), '--')
        ));

        foreach (array_filter(array_map('trim', explode(';', $body))) as $statement) {
            $wpdb->query($statement);

            $this->assertEmpty(
                $wpdb->last_error,
                "Frozen schema {$snapshot_file} failed to apply: {$wpdb->last_error}"
            );
        }
    }

    /**
     * A canonical, order-insensitive description of a table: columns keyed by name, indexes keyed
     * by name, both sorted.
     *
     * Deliberately NOT `SHOW CREATE TABLE`: dbDelta appends a new column at the end of the table
     * while a fresh install creates it in its declared position, so the two are legitimately
     * different texts for the same schema. Comparing the raw DDL would fail on that and hide the
     * differences that matter.
     */
    protected function schema_fingerprint(string $table): array
    {
        global $wpdb;

        $columns = [];

        foreach ((array) $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A) as $column) {
            $columns[$column['Field']] = [
                'type'    => strtolower((string) $column['Type']),
                'null'    => (string) $column['Null'],
                'default' => $column['Default'],
                'extra'   => strtolower((string) $column['Extra']),
            ];
        }

        ksort($columns);

        $indexes = [];

        foreach ((array) $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A) as $row) {
            $name = (string) $row['Key_name'];

            $indexes[$name]['unique'] = (int) $row['Non_unique'] === 0;
            $indexes[$name]['columns'][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
        }

        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }

        unset($index);
        ksort($indexes);

        $this->assertNotEmpty($columns, "Table {$table} does not exist or has no columns");

        return ['columns' => $columns, 'indexes' => $indexes];
    }

    /** @return array<string, array> Fingerprint of all 4 tables under a prefix, keyed by bare name. */
    protected function schema_fingerprints(string $prefix): array
    {
        $fingerprints = [];

        foreach (self::TABLES as $table) {
            $fingerprints[$table] = $this->schema_fingerprint($prefix . $table);
        }

        return $fingerprints;
    }

    /** @return string[] Absolute paths to every frozen snapshot, oldest version first. */
    protected function frozen_snapshots(): array
    {
        $files = glob(dirname(__DIR__) . '/schema/v*.sql') ?: [];

        usort($files, static fn(string $a, string $b): int => version_compare(
            (string) preg_replace('/\D/', '', basename($a)),
            (string) preg_replace('/\D/', '', basename($b))
        ));

        return $files;
    }
}
